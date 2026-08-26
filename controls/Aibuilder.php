<?php
/**
 * AI Builder — in-app entry to a member's jailed Claude/qwen coding sessions.
 *
 * A member (admin) provisions one or more isolated "<slug>.tiknix" instances —
 * each an independent git clone with its own SQLite DB. Opening an instance mints
 * a short-lived HMAC token and renders a terminal (xterm) that connects, same-origin,
 * to the aibuilder terminal bridge:
 *   - terminal: wss://<host>/aibuilder/ws  -> node bridge (127.0.0.1:3990)
 * It spawns a bubblewrap-jailed agent confined to THAT instance. Checkpoint /
 * Rollback shell out to the capricorn instance scripts so any change is reversible.
 *
 * There was a second, separate chat bridge on 3991 (/aibuilder/chat-ws). Nothing opens
 * it any more — the terminal is the whole interface — so it is gone from here rather
 * than lingering as a socket that looks broken because no service and no proxy block
 * back it.
 *
 * Security: the bubblewrap jail (capricorn/bin/jail-run.sh) is the real boundary.
 * This controller gates access (ADMIN), mints the token, validates instance
 * ownership, and brokers snapshot/rollback. Slugs are strictly validated before
 * any shell use, and the shared token secret must match the bridges' env.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\EngineRegistry;
use app\MemberEnginePrefs;
use app\BrokerService;

class Aibuilder extends BuildControl {

    // Stored slug is the immutable {base}-{hash} identity (e.g. "towels-a1b2c3"):
    // lowercase, starts with a letter, internal single hyphens only — path-safe.
    private const SLUG_RE = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';
    private const APP     = 'tiknix';

    /**
     * The whole AI Builder is control-plane-only: a provisioned sandbox instance
     * is a leaf and must not run the instance tooling (no nested instances until
     * host-aware nesting exists). Gate every route in one place.
     */
    // Instance selection is inherited from BuildControl: the project selected in core,
    // and nothing else. The old ?id / ?plan / ?inst routes are gone — a link carrying an
    // instance id is a second way to say which project, and a stale one moved you
    // silently. A plan belongs to a project; open the project, then the plan.

    private function cfg(): array {
        // Read CORE's aibuilder.ini (token secret + bridge ws paths) via core_root, so the
        // terminal token validates against core's node bridge and the wss path matches.
        $coreRoot = rtrim((string) \Flight::get('sidecar.core_root'), '/') ?: dirname(__DIR__);
        return @parse_ini_file($coreRoot . '/conf/aibuilder.ini', true) ?: [];
    }

    /** wss/ws base for CORE's host (where the PTY node bridge runs), from [sidecar] core_url. */
    private function coreWsBase(): string {
        $u = rtrim((string) (\Flight::get('sidecar.core_url') ?: 'https://tiknix.com'), '/');
        return (strpos($u, 'https://') === 0) ? 'wss://' . substr($u, 8) : 'ws://' . preg_replace('#^ws?://|^http://#', '', $u);
    }

    private function minLevel(): int {
        // Floor to REACH AI Builder. Members (100) may use instances shared with
        // their team; per-instance authorization is enforced by accessibleInstance()
        // / ownedInstance() on each endpoint. Provisioning (create) is ADMIN-gated
        // separately. Configurable via [access] min_level.
        return (int)($this->cfg()['access']['min_level'] ?? LEVELS['MEMBER']);
    }

    /**
     * The namespace new instances are minted under: the running host minus the
     * .com apex. Root tiknix.com -> "tiknix" (== APP, so the control plane is
     * byte-for-byte unchanged); an instance served at instance.tiknix.com ->
     * "instance.tiknix", so its children nest as <slug>.instance.tiknix.com
     * (capricorn builds <sub>.<app> from this and its Lua router auto-routes it).
     * Falls back to APP if the host is missing/unusable.
     *
     * A node only ever manages instances under its own namespace, so this equals
     * each managed instance's stored ->app — safe to use for existing ones too.
     */
    private function appNamespace(): string {
        // Instances live under CORE's app namespace (e.g. "tiknix"), NOT this sidecar's own
        // host. Derive from [sidecar] core_url (https://tiknix.com -> tiknix); app.baseurl here
        // is workbench.tiknix.com, which would wrongly yield "workbench.tiknix" and point
        // instanceDir()/ab_url at <slug>.workbench.tiknix (nonexistent) -> terminal never opens.
        $src  = (string) (Flight::get('sidecar.core_url') ?: Flight::get('app.baseurl'));
        $host = strtolower((string)(parse_url($src, PHP_URL_HOST) ?: ''));
        $ns   = preg_replace('/\.com$/', '', $host);
        return ($ns !== '' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $ns))
            ? $ns : self::APP;
    }

    private function instanceDir(string $sub): string {
        return '/var/www/html/default/' . $sub . '.' . $this->appNamespace();
    }

    /**
     * Has this instance's own web app finished setup? Mirrors the instance's
     * Install::isInstalled() — a level-1 admin whose password is no longer the seed hash.
     * Read-only peek at the instance's own sqlite; on any uncertainty return true so we
     * never nag. (A freshly provisioned instance is NOT installed until the operator sets
     * the admin password at /install.)
     */
    private function instanceInstalled(string $instanceDir): bool {
        $ini   = @parse_ini_file($instanceDir . '/conf/config.ini', true) ?: [];
        $dbRel = (string) ($ini['database']['path'] ?? '');
        if ($dbRel === '') return true;
        $dbAbs = ($dbRel[0] ?? '') === '/' ? $dbRel : $instanceDir . '/' . $dbRel;
        if (!is_file($dbAbs)) return true;
        // The default admin hash is a stable constant in the app's controls/Install.php.
        $seed = '$2y$10$jVz654DI7bX8e1Dh32O9suFcMW4x1V.0SrniJNpDyknwkzc6gM20a';
        try {
            $pdo = new \PDO('sqlite:' . $dbAbs);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
            $st = $pdo->prepare("SELECT COUNT(*) FROM member WHERE level = 1 AND password != ? AND password != ''");
            $st->execute([$seed]);
            return (int) $st->fetchColumn() > 0;
        } catch (\Throwable $e) { return true; }
    }

    /** base64url(payload) + "." + hex(HMAC-SHA256(payload, secret)) — mirrors the bridges. */
    /**
     * @param string $engineWanted  Open the terminal on a SPECIFIC provider, e.g. 'zai'.
     *                              Empty = the project's own engine, which is what every
     *                              caller did before this existed.
     *
     * The engine has to be decided here rather than left to the jail, and it decides two
     * things at once that must agree: which endpoint the CLI talks to (carried to the
     * bridge as a token claim, which it turns into $ENGINE) and which credential store gets
     * bound (AgentState::resolve, keyed per engine). Setting one without the other is the
     * failure worth naming — credentials written to state/zai while the CLI still talks to
     * Anthropic looks like a successful login that never takes effect.
     */
    private function mintToken(string $sub, int $memberId, string $engineWanted = ''): string {
        $cfg    = $this->cfg();
        $secret = (string)($cfg['token']['secret'] ?? '');
        $ttl    = (int)($cfg['token']['ttl'] ?? 120);
        // Where THIS member's agent credentials live. Decided here, in one place
        // (app\AgentState), and carried in the signed payload so the terminal bridge
        // binds the same store the planner and the build agents use. Without it the
        // terminal fell back to the per-project dir and asked you to log in again for a
        // project you had already signed in for elsewhere.
        $dir    = '/var/www/html/default/' . $sub . '.' . $this->appNamespace();
        $engine = trim((string) @file_get_contents($dir . '/.aibuilder/engine')) ?: 'claude';
        // A requested engine wins, but only one the registry knows — this value becomes
        // $ENGINE in a shell, and the bridge allowlists it again on its side.
        $engineWanted = trim($engineWanted);
        if ($engineWanted !== '' && EngineRegistry::isValid($engineWanted)) $engine = $engineWanted;

        $payload = json_encode([
            'app' => $this->appNamespace(), 'sub' => $sub, 'member_id' => $memberId,
            // The bridge reads this and sets ENGINE before spawning the jail. Without it the
            // terminal always came up on the project's engine, so there was no way to sign
            // in to a second provider from the browser at all.
            'engine' => $engine,
            'agent_state' => \app\AgentState::resolve($memberId, $engine, $dir),
            'nonce' => bin2hex(random_bytes(8)),   // single-use: the bridge burns this on connect
            'exp' => time() + $ttl,
        ]);
        $b64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        return $b64 . '.' . hash_hmac('sha256', $b64, $secret);
    }

    // ---- instance access: converged onto WorkbenchAccess (owner/team scoping from CORE,
    //      read-only). Return a read-only instance-meta object (drop-in for the old bean on
    //      READ paths). Registry MUTATIONS (create/fork/delete/share) are the core write-seam.

    /** An instance the current member OWNS and that exists on disk (owner-only actions). */
    private function ownedInstance($id) {
        $id = (int) $id;
        if (!$id || !$this->access->ownsInstance((int) $this->member->id, $id)) return null;
        $inst = $this->access->instanceMeta($id);
        if (!$inst || !is_file($this->instanceDir($inst->slug) . '/public/index.php')) return null;
        return $inst;
    }

    /** An instance the current member may USE: owned OR shared with one of their teams. */
    private function accessibleInstance($id) {
        $id = (int) $id;
        if (!$id) return null;
        $inst = $this->access->instanceMeta($id);   // null unless accessible (owned ∪ team-shared)
        // The "(default)" core instance is the live control plane (core.tiknix symlinks to the
        // running app) — not a buildable instance. It's excluded from the AI Builder entirely.
        if (!$inst || !empty($inst->isDefault)) return null;
        if (!is_file($this->instanceDir($inst->slug) . '/public/index.php')) return null;
        return $inst;
    }

    /** True when the current member owns the instance (for owner-only actions). */
    private function isInstanceOwner($inst): bool {
        return $inst && $this->access->ownsInstance((int) $this->member->id, (int) $inst->id);
    }

    /** Run git inside an instance's directory (read/write its own repo only). */
    private function gitInstance(string $slug, array $args): array {
        if (!preg_match(self::SLUG_RE, $slug)) return ['ok' => false, 'out' => '', 'code' => 1];
        $cmd = 'git -C ' . escapeshellarg($this->instanceDir($slug));
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string)$a); }
        $lines = []; $code = 0;
        exec($cmd . ' 2>&1', $lines, $code);
        return ['ok' => $code === 0, 'out' => implode("\n", $lines), 'code' => $code];
    }

    /** Run a capricorn instance script (args already validated). Returns ok/out/code. */
    private function runScript(string $script, array $args): array {
        $cfg    = $this->cfg();
        $binDir = rtrim((string)($cfg['ops']['bin_dir'] ?? '/home/ubuntu/capricorn/bin'), '/');
        $prefix = trim((string)($cfg['ops']['sudo_prefix'] ?? ''));
        $cmd = ($prefix ? $prefix . ' ' : '') . escapeshellarg($binDir . '/' . $script);
        foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string)$a); }
        $lines = []; $code = 0;
        exec($cmd . ' 2>&1', $lines, $code);
        return ['ok' => $code === 0, 'out' => implode("\n", $lines), 'code' => $code];
    }

    /**
     * Neutralize Claude's browser-open inside the jail. There's no GUI browser in the
     * sandbox, so we point $BROWSER at a tiny no-op script; Claude then falls back to
     * printing its hosted sign-in URL + a "Paste code here" prompt in the terminal,
     * which the sign-in gate reads (see oauthstatus) and drives. The script also
     * records the URL to .aibuilder/oauth-request.json as a debug artifact.
     * Idempotent; safe to call on every open.
     *
     * Two files, both under the bind-mounted instance dir so they resolve at the
     * SAME path inside the jail:
     *   1. .aibuilder/oauth-browser.sh           — the fake browser (from scripts/)
     *   2. .aibuilder/state/claude/settings.json  — env.BROWSER -> that script.
     * Claude applies settings env via Object.assign(process.env, settings.env) at
     * startup, and CLAUDE_CONFIG_DIR (set by jail-run.sh) points at that state dir,
     * so this covers BOTH the interactive terminal and task automation with no jail
     * or bridge changes. Merge-preserving: the operator's creds live in this dir too.
     */
    private function ensureOAuthCapture(string $slug): void {
        if (!preg_match(self::SLUG_RE, $slug)) return;
        $dir = $this->instanceDir($slug);
        if (!is_dir($dir)) return;

        $aib     = $dir . '/.aibuilder';
        $browser = $aib . '/oauth-browser.sh';
        $src     = dirname(__DIR__) . '/scripts/aibuilder-oauth-browser.sh';

        // (1) Install / refresh the fake browser (copy when missing or changed).
        $want = is_file($src) ? @file_get_contents($src) : false;
        if ($want !== false) {
            if (!is_dir($aib)) @mkdir($aib, 0775, true);
            if (@file_get_contents($browser) !== $want) @file_put_contents($browser, $want);
            @chmod($browser, 0755);
        }

        // (2) Point Claude at it via the persisted per-instance settings.json.
        $stateDir = $aib . '/state/claude';
        if (!is_dir($stateDir)) @mkdir($stateDir, 0775, true);
        $file = $stateDir . '/settings.json';
        $settings = [];
        if (is_file($file)) {
            $decoded = json_decode(((string)@file_get_contents($file)) ?? '', true);
            if (is_array($decoded)) $settings = $decoded;
        }
        // NOTE: deliberately NOT pinning forceLoginMethod. The default interactive flow
        // already uses the Claude.ai (subscription) path via a localhost callback, which
        // we've verified works end-to-end. Forcing "claudeai" can switch it onto the
        // HOSTED callback (platform.claude.com/oauth/code/callback), which has an open
        // "Redirect URI is not supported by client" bug (anthropics/claude-code#36215).
        // Don't destabilise a working flow — leave the login method to Claude's default.
        if (!isset($settings['env']) || !is_array($settings['env'])) $settings['env'] = [];
        if (($settings['env']['BROWSER'] ?? null) !== $browser) {
            $settings['env']['BROWSER'] = $browser;
            @file_put_contents($file,
                json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }

    // --- routes ---------------------------------------------------------------

    /** GET /aibuilder — list instances (optionally ?id= to open one inline). */
    public function index($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $this->renderHome();
    }

    /** GET /aibuilder/open/<id> — open a specific instance's terminal + chat. */
    public function open($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        // Kept so existing /aibuilder/open/<id> links do not 404, but the id is ignored:
        // the project you are on decides what opens. Switch project in core to change it.
        $this->renderHome();
    }

    /** Render the selected project's Terminal/Chat. */
    private function renderHome(): void {
        // ONE input: the project selected in core (resolved in BuildControl). No id from
        // the URL — a link carrying one is a second way to say which project, and a stale
        // one moved you without the UI ever showing it.
        if (!$this->requireProject()) return;
        $selId = (int) $this->selected['id'];

        // Accessible instances (owned ∪ team-shared) from CORE via WorkbenchAccess, as
        // read-only meta objects (drop-in for the old instance beans on read paths).
        $mid       = (int) $this->member->id;
        $instances = array_values(array_filter(array_map(
            fn($i) => $this->access->instanceMeta((int) $i['id']),
            $this->access->accessibleInstances())));
        $selected  = $selId ? $this->accessibleInstance($selId) : null;

        // Neutralize Claude's in-jail browser-open before the terminal opens, so a
        // first-run `claude` sign-in surfaces in the gate instead of a dead browser.
        if ($selected) $this->ensureOAuthCapture($selected->slug);

        // Share-management UI (owner-only team sharing) is part of the registry write-seam;
        // the read/terminal/plan path works without it. Selected instance's shares are read-only.
        $shareTeams       = [];   // TODO write-seam: teams the member can share INTO
        $instSharedIds    = [];   // TODO write-seam: which displayed instances have any share

        // Flag a selected instance whose web app hasn't finished its own /install (admin
        // password still the seed) so the view can nudge the operator to complete setup.
        $needsInstall = ($selected && empty($selected->isDefault))
            ? !$this->instanceInstalled($this->instanceDir($selected->slug)) : false;

        $cfg = $this->cfg();
        $this->render('aibuilder/index', [
            'title'            => 'Terminal',
            'instances'        => array_values($instances),
            'shareTeams'       => array_values($shareTeams),
            'ab_memberId'      => $mid,
            // Core's picker: the ONE place a project is chosen. The view links back here
            // instead of offering a local list.
            'ab_projectsUrl'   => \app\Sidecar\Sso::projectPickerUrl(),
            'ab_isOwner'       => $selected ? $this->isInstanceOwner($selected) : false,
            'ab_instSharedIds' => array_values($instSharedIds),
            'selected'       => $selected,
            'ab_needsInstall' => $needsInstall,
            'ab_sub'         => $selected ? $selected->slug : '',
            'ab_token'       => $selected ? $this->mintToken($selected->slug, (int)$this->member->id,
                                                             (string) $this->getParam('engine', '')) : '',
            'ab_wspath'      => (string)($cfg['bridge']['ws_path'] ?? '/aibuilder/ws'),
            // The terminal PTY bridge (node runner) lives on CORE, so the xterm must
            // connect to core's host, not this sidecar's. wss://<core-host>. See coreWsBase().
            'ab_ws_base'     => $this->coreWsBase(),
            'ab_hasInstance' => (bool)$selected,
            'ab_isDefault'   => $selected ? (bool)$selected->isDefault : false,
            'ab_isRoot'      => $this->hasLevel(LEVELS['ROOT']),
            'ab_canCreate'   => $this->hasLevel(LEVELS['ADMIN']),
            'ab_url'         => $selected ? 'https://' . $selected->slug . '.' . $this->appNamespace() . '.com' : '',
        ]);
    }

    /** POST /aibuilder/create — provision a new instance. JSON. Provisioning is
     *  ADMIN-only even though using instances is open to members. */
    public function create($params = []): void {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        if (!$this->validateCSRF()) return;

        // Registry MUTATION: the sidecar is read-only to core, so provisioning goes through
        // the HMAC-authed core /provision endpoint — ProvisionService owns the `instance`
        // write + capricorn shell-out + broker-key mint. Writes/custody stay in core.
        $res = $this->provisionCall('create', [
            'slug'       => strtolower(trim((string) $this->getParam('slug', ''))),
            'name'       => trim((string) $this->getParam('name', '')),
            'engine'     => EngineRegistry::coerce($this->getParam('engine'), EngineRegistry::defaultEngine()),
            'is_default' => filter_var($this->getParam('is_default', false), FILTER_VALIDATE_BOOLEAN),
            'is_root'    => $this->hasLevel(LEVELS['ROOT']),
        ]);
        if (!empty($res['success'])) {
            $d = (array) ($res['data'] ?? []);
            // The new instance's per-instance workbench.db + oauth capture are set up lazily
            // on first open() (co-located, sidecar-writable) — no core write needed here.
            Flight::jsonSuccess(['id' => (int) ($d['id'] ?? 0), 'slug' => (string) ($d['slug'] ?? '')], 'Instance created');
        } else {
            Flight::jsonError((string) ($res['message'] ?? 'Provisioning failed'), (int) ($res['code'] ?? 500));
        }
    }

    /**
     * Perform a registry MUTATION in core. The sidecar can't write core, so it signs
     * {member_id, op, params, exp} with the shared sidecar secret and POSTs to core's
     * HMAC-authed /provision/call, which dispatches to ProvisionService. Returns the
     * decoded core envelope {success, data|message, code}. (No curl_close — it throws in
     * the PHP 8.5 web handler.)
     */
    private function provisionCall(string $op, array $params): array {
        $cfg     = @parse_ini_file(dirname(__DIR__) . '/conf/config.ini', true) ?: [];
        $secret  = (string) ($cfg['sidecar']['sso_secret'] ?? '');
        $coreUrl = rtrim((string) ($cfg['sidecar']['core_url'] ?? 'https://tiknix.com'), '/');
        if ($secret === '') return ['success' => false, 'message' => 'Provisioning not configured (no shared secret).'];
        $payload = json_encode(['member_id' => (int) $this->member->id, 'op' => $op, 'params' => $params, 'exp' => time() + 60]);
        $sig     = hash_hmac('sha256', $payload, $secret);

        $ch = curl_init($coreUrl . '/provision/call');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => http_build_query(['payload' => $payload, 'sig' => $sig]),
            CURLOPT_TIMEOUT        => 180,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        if ($body === false) return ['success' => false, 'message' => 'Could not reach core provisioning: ' . $err];
        $d = json_decode((string) $body, true);
        if (!is_array($d)) return ['success' => false, 'message' => 'Bad response from core provisioning (HTTP ' . $code . ')'];
        if (empty($d['success']) && !isset($d['code'])) $d['code'] = $code;   // carry HTTP status (e.g. 409)
        return $d;
    }

    /** GET /aibuilder/refresh?id= — re-mint a token (AJAX reconnect). JSON. */
    public function refresh($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }
        // Carries the engine too: a reconnect that silently dropped back to the project's
        // provider would move you off z.ai mid-session without saying so.
        Flight::jsonSuccess(['token' => $this->mintToken($inst->slug, (int)$this->member->id,
                                                         (string) $this->getParam('engine', ''))]);
    }

    /** Path to the instance's jailed tmux control socket. */
    private function tmuxSock(string $slug): string {
        return $this->instanceDir($slug) . '/.aibuilder/tmux.sock';
    }

    /** Best-effort snapshot of the jailed agent's current screen ('' if no session). */
    private function paneText(string $slug): string {
        if (!preg_match(self::SLUG_RE, $slug)) return '';
        $sock = $this->tmuxSock($slug);
        if (!file_exists($sock)) return '';
        $out = []; $code = 0;
        exec('tmux -S ' . escapeshellarg($sock) . ' capture-pane -p -t aib 2>/dev/null', $out, $code);
        return $code === 0 ? implode("\n", $out) : '';
    }

    /**
     * Reassemble a Claude sign-in URL from the agent's screen. Claude hard-wraps the
     * URL across several terminal lines (that's why it offers its own "c to copy");
     * URLs contain no spaces, so once we hit the "…/oauth/authorize?" line we glue the
     * contiguous no-space fragments that follow, stopping at the first line with a
     * space (the next prompt, e.g. "Paste code here >").
     */
    private function signinUrlFromPane(string $pane): string {
        if ($pane === '') return '';
        $url = ''; $collecting = false;
        foreach (explode("\n", $pane) as $line) {
            $t = trim($line);
            if (!$collecting) {
                $pos = stripos($t, 'https://');
                if ($pos !== false
                    && preg_match('#^https://[a-z0-9.-]*claude\.(?:com|ai)/[^\s]*oauth/authorize\?#i', substr($t, $pos))) {
                    $url = substr($t, $pos);
                    $collecting = true;
                }
                continue;
            }
            if ($t === '' || preg_match('/\s/', $t)) break;   // continuation ended
            $url .= $t;
        }
        return $url;
    }

    /**
     * GET /aibuilder/oauthstatus?id= — is the jailed Claude sitting at its sign-in
     * screen? Claude prints a hosted sign-in URL (redirect_uri=platform.claude.com/
     * oauth/code/callback) plus a "Paste code here" prompt right in the terminal, so we
     * read the agent's screen and hand that URL to the gate. The operator approves in
     * their own browser, copies the code Anthropic shows, and the gate types it back
     * into that prompt over the PTY websocket (client-side). JSON: {pending:bool, url?}.
     */
    public function oauthstatus($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $url = $this->signinUrlFromPane($this->paneText($inst->slug));
        if ($url !== '' && preg_match('#^https://[a-z0-9.-]*claude\.(?:com|ai)/[^\s]*oauth/authorize\?#i', $url)) {
            Flight::jsonSuccess(['pending' => true, 'url' => $url]);
            return;
        }
        Flight::jsonSuccess(['pending' => false]);
    }

    /** GET /aibuilder/changes?id= — files changed since the last checkpoint. JSON. */
    public function changes($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        // Uncommitted working-tree changes == the delta since the last checkpoint
        // (snapshot-instance.sh commits everything, so this self-resets per checkpoint).
        $out = $this->gitInstance($inst->slug, ['status', '--porcelain']);
        $files = [];
        foreach (explode("\n", $out['out']) as $line) {
            if (trim($line) === '') continue;
            $status = trim(substr($line, 0, 2));
            $path   = substr($line, 3);
            if (($p = strpos($path, ' -> ')) !== false) $path = substr($path, $p + 4); // rename
            $files[] = ['status' => $status, 'path' => trim($path)];
        }
        Flight::jsonSuccess(['files' => $files, 'count' => count($files)]);
    }

    /**
     * GET /aibuilder/reusedigest?id= — the auto-generated reuse inventory the planner
     * is fed for this instance (controllers, models, libs, permissions, seeders). Lets
     * the operator SEE exactly what decomposition is grounded on. JSON.
     */
    public function reusedigest($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $file = dirname(__DIR__) . '/mcptools/Introspector.php';
        if (is_file($file)) require_once $file;
        $cls = 'app\\mcptools\\Introspector';
        if (!class_exists($cls)) { Flight::jsonError('Introspector unavailable', 500); return; }
        try {
            $digest = (new $cls($this->instanceDir($inst->slug)))->digest();
        } catch (\Throwable $e) {
            Flight::jsonError('Digest failed: ' . $e->getMessage(), 500); return;
        }
        Flight::jsonSuccess(['slug' => $inst->slug, 'digest' => $digest]);
    }

    /** POST /aibuilder/checkpoint?id= — checkpoint with an optional description. JSON. */
    public function checkpoint($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $desc = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', (string)$this->getParam('label', ''))), 0, 200);

        // snapshot-instance.sh commits + creates an auto-unique lightweight tag, echoing it.
        $out = $this->runScript('snapshot-instance.sh', [$this->appNamespace(), $inst->slug]);
        if (!$out['ok']) { Flight::jsonError('Checkpoint failed: ' . substr(trim($out['out']), -300), 500); return; }

        $tag = '';
        foreach (array_reverse(array_filter(array_map('trim', explode("\n", $out['out'])))) as $l) {
            if (preg_match('/^checkpoint-[A-Za-z0-9._-]+$/', $l)) { $tag = $l; break; }
        }
        // Re-tag as an ANNOTATED tag carrying the description (git-native; HEAD is the snapshot commit).
        if ($tag !== '' && $desc !== '') {
            $this->gitInstance($inst->slug, ['tag', '-f', '-a', $tag, '-m', $desc]);
        }

        // A checkpoint is a local git tag and nothing more. Auto-publish used to ride
        // here, reading a `connections` bean and calling GitHubPublisher — both of which
        // resolve against THIS SIDECAR's database and class path, not core's, so it had
        // been dead since the extraction. Publishing on a schedule is now a cron on the
        // project's publish pipeline, which is visible, debuggable and owned by the
        // project rather than hidden inside a save.
        Flight::jsonSuccess(['checkpoint' => $tag, 'description' => $desc], 'Checkpoint saved');
    }

    /** GET /aibuilder/checkpoints?id= — list checkpoints with descriptions. JSON. */
    public function checkpoints($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $out = $this->gitInstance($inst->slug, ['for-each-ref', '--sort=-creatordate',
            '--format=%(refname:short)|%(creatordate:short)|%(objectname:short)|%(contents:subject)',
            'refs/tags/checkpoint-*']);
        $items = [];
        foreach (explode("\n", $out['out']) as $line) {
            if ($line === '') continue;
            $p = explode('|', $line, 4);
            $items[] = [
                'name'        => $p[0] ?? '',
                'date'        => $p[1] ?? '',
                'commit'      => $p[2] ?? '',
                'description' => $p[3] ?? '',  // empty for lightweight (undescribed) tags
            ];
        }
        Flight::jsonSuccess(['checkpoints' => $items]);
    }

    /** POST /aibuilder/rollback/<checkpoint>?id= — restore a checkpoint. JSON. */
    public function rollback($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        // Owner-only: rollback resets the whole (possibly team-shared) instance.
        $inst = $this->ownedInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance (owner only)', 404); return; }

        $ckpt = (string)($params['operation']->name ?? $this->getParam('checkpoint', 'checkpoint-baseline'));
        if (!preg_match('/^[a-z0-9-]{3,60}$/i', $ckpt)) {
            Flight::jsonError('Invalid checkpoint', 400); return;
        }
        $out = $this->runScript('rollback-instance.sh', [$this->appNamespace(), $inst->slug, $ckpt]);
        if ($out['ok']) Flight::jsonSuccess(['log' => $out['out']], 'Rolled back to ' . $ckpt);
        else            Flight::jsonError('Rollback failed: ' . substr(trim($out['out']), -300), 500);
    }

    /**
     * POST /aibuilder/share — owner toggles whether an instance is shared with a
     * given team (team_id + shared=1|0). Many-to-many: an instance can be shared
     * with several teams at once ("work between teams"). Team members then get full
     * use of it (terminal, build, checkpoint) and see its tasks in the Workbench.
     * JSON.
     */
    public function share($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        // Registry write (instance_team m2m) → core provision seam.
        $res = $this->provisionCall('share', [
            'id'      => (int) $this->getParam('id', 0),
            'team_id' => (int) $this->getParam('team_id', 0),
            'shared'  => (int) $this->getParam('shared', 0) === 1,
        ]);
        if (!empty($res['success'])) {
            $d = (array) ($res['data'] ?? []);
            $tn = (string) ($d['team_name'] ?? 'team');
            Flight::jsonSuccess($d, !empty($d['shared']) ? ('Shared with ' . $tn) : ('Removed from ' . $tn));
        } else { Flight::jsonError((string) ($res['message'] ?? 'Share failed'), (int) ($res['code'] ?? 500)); }
    }

    // instanceDbRel / registerInstanceBean / archiveInstance moved to core ProvisionService
    // (the write-seam): registry writes + capricorn ops run in core, not the sidecar.

    /**
     * POST /aibuilder/fork — create a NEW instance from a source instance's checkpoint.
     * Carries code + data (the tracked sqlite db) from the checkpoint; connections and
     * secrets reset because the fresh instance keeps its own provisioned config (new
     * subdomain, db path, app_key). The forker becomes the owner. Provisioning is
     * ADMIN-only. JSON.
     */
    public function fork($params = []): void {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        if (!$this->validateCSRF()) return;
        // Registry write + capricorn provision + git overlay → core provision seam
        // (ProvisionService::fork owns the source-checkpoint archive/data-carry + new bean).
        $res = $this->provisionCall('fork', [
            'id'         => (int) $this->getParam('id', 0),
            'checkpoint' => (string) ($params['operation']->name ?? $this->getParam('checkpoint', 'checkpoint-baseline')),
            'slug'       => strtolower(trim((string) $this->getParam('slug', ''))),
            'name'       => trim((string) $this->getParam('name', '')),
        ]);
        if (!empty($res['success'])) {
            $d = (array) ($res['data'] ?? []);
            $carried = !empty($d['data_carried']);
            Flight::jsonSuccess(['id' => (int) ($d['id'] ?? 0), 'slug' => (string) ($d['slug'] ?? ''), 'data_carried' => $carried],
                'New instance created' . ($carried ? '' : ' (code only — data not carried)'));
        } else { Flight::jsonError((string) ($res['message'] ?? 'Fork failed'), (int) ($res['code'] ?? 500)); }
    }

    /** Validate a decomposed-plan array: {title, subtasks:[{title,...}]}. */
    private function validPlan($plan): bool {
        return is_array($plan) && !empty($plan['title']) && !empty($plan['subtasks']) && is_array($plan['subtasks']);
    }

    /** Persist a decomposed plan as a workbench task tree + take a baseline checkpoint. */
    private function savePlanTree($inst, array $plan): array {
        // Baseline checkpoint so the WHOLE plan is reversible to the pre-plan state.
        $snap = $this->runScript('snapshot-instance.sh', [$this->appNamespace(), $inst->slug]);
        $tag = '';
        foreach (array_reverse(array_filter(array_map('trim', explode("\n", $snap['out'])))) as $l) {
            if (preg_match('/^checkpoint-[A-Za-z0-9._-]+$/', $l)) { $tag = $l; break; }
        }
        if ($tag !== '') {
            $this->gitInstance($inst->slug, ['tag', '-f', '-a', $tag, '-m', 'plan: ' . mb_substr((string)$plan['title'], 0, 80)]);
        }

        // Deterministic tree creation is shared with the headless CLI ingester.
        $res = \app\PlanIngestor::ingest($inst, $plan, (int)$this->member->id, $tag, $this->appNamespace());
        $this->logger->info('aibuilder plan saved', ['instance' => $inst->slug, 'parent' => $res['parent']['id'], 'subtasks' => count($res['subtasks'])]);
        return $res;
    }

    /** POST /aibuilder/planingest?id= — ingest the plan the agent wrote to .aibuilder/plan.json. JSON.
     *  Reliable handoff: the jailed planner WRITES a file (a tool it does well) rather than us
     *  scraping JSON out of chat text. */
    public function planingest($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $file  = $this->instanceDir($inst->slug) . '/.aibuilder/plan.json';
        // Atomically claim the file so the server-side (planner-exit) ingester and
        // this browser poll can never double-ingest the same plan.
        $claim = \app\PlanIngestor::claim($file);
        if ($claim === null) { Flight::jsonError('No plan.json to ingest (or it was already ingested).', 404); return; }

        $plan = json_decode(((string)@file_get_contents($claim)) ?? '', true);
        if (!\app\PlanIngestor::isValidPlan($plan)) {
            @unlink($claim);
            Flight::jsonError('plan.json is not a valid plan {title, subtasks:[...]}.', 422);
            return;
        }
        try {
            $res = $this->savePlanTree($inst, $plan);
        } catch (\Throwable $e) {
            @rename($claim, $file);  // release for retry
            Flight::jsonError('Ingest failed: ' . $e->getMessage(), 500);
            return;
        }
        @unlink($claim);
        Flight::jsonSuccess($res, 'Plan saved');
    }

    /** POST /aibuilder/plansave?id= — save a decomposed plan posted as JSON (fallback path). JSON. */
    public function plansave($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $plan = json_decode(((string)$this->getParam('plan', '')) ?? '', true);
        if (!$this->validPlan($plan)) { Flight::jsonError('Invalid plan: need {title, subtasks:[...]}', 400); return; }
        Flight::jsonSuccess($this->savePlanTree($inst, $plan), 'Plan saved');
    }

    /** GET /aibuilder/plan?id= — list saved plans (task trees) for an instance. JSON. */
    public function plan($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $parents = Bean::find('workbenchtask', 'instance_id = ? AND parent_task_id IS NULL ORDER BY created_at DESC', [(int)$inst->id]);
        $plans = [];
        foreach ($parents as $p) {
            $subs = Bean::find('workbenchtask', 'parent_task_id = ? ORDER BY priority ASC, id ASC', [(int)$p->id]);
            $plans[] = [
                'id' => (int)$p->id, 'title' => $p->title, 'summary' => $p->description,
                'checkpoint' => $p->planCheckpoint, 'status' => $p->status,
                'plan_status' => $p->planStatus ?: 'draft',
                'instance_tag' => $p->instanceTag ?: ($inst->slug . '.' . $this->appNamespace()),
                'subtasks' => array_map(fn($s) => [
                    'id' => (int)$s->id, 'ref' => $s->planRef, 'title' => $s->title, 'description' => $s->description,
                    'priority' => (int)$s->priority, 'engine' => $s->engine, 'status' => $s->status,
                    'files' => $s->relatedFiles,
                    'depends_on' => json_decode(($s->dependsOn ?: '[]') ?? '', true) ?: [],
                ], array_values($subs)),
            ];
        }
        Flight::jsonSuccess(['plans' => $plans]);
    }

    /**
     * POST /aibuilder/plangenerate?id= — launch the headless (claude -p) planner
     * for a goal. It grounds itself via the tiknix MCP and calls submit_plan,
     * which writes .aibuilder/plan.json for planingest to pick up. JSON.
     */
    public function plangenerate($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $goal = trim((string)$this->getParam('goal', ''));
        if (mb_strlen($goal) < 10) { Flight::jsonError('Describe the goal in a sentence or two (min 10 chars).', 400); return; }

        $runner = new PlanRunner($inst->slug, $this->instanceDir($inst->slug),
                                 (int)$this->member->id, (int)$this->member->level, (string)$inst->engine);
        try {
            $session = $runner->start($goal);
        } catch (\Throwable $e) {
            Flight::jsonError('Could not start planner: ' . $e->getMessage(), 500);
            return;
        }
        $this->logger->info('aibuilder planner started', ['instance' => $inst->slug, 'session' => $session]);
        Flight::jsonSuccess(['session' => $session, 'running' => true], 'Planner started — decomposing the goal…');
    }

    /** GET /aibuilder/planstatus?id= — poll the headless planner (running / plan_ready / log). JSON. */
    public function planstatus($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $runner = new PlanRunner($inst->slug, $this->instanceDir($inst->slug),
                                 (int)$this->member->id, (int)$this->member->level, (string)$inst->engine);
        Flight::jsonSuccess([
            'running'    => $runner->running(),
            'plan_ready' => $runner->planReady(),
            'log'        => $runner->logTail(40),
        ]);
    }

    /** Resolve a plan (workbenchtask parent) by id and authorize via its instance. */
    private function ownedPlan($planId) {
        $planId = (int)$planId;
        if ($planId <= 0) return null;
        $plan = Bean::load('workbenchtask', $planId);
        if (!$plan->id || $plan->parentTaskId) return null;         // must be a plan parent
        $inst = $this->accessibleInstance((int)$plan->instanceId);
        if (!$inst) return null;
        return [$plan, $inst];
    }

    /** POST /aibuilder/planapprove?plan= — mark a plan approved (ready to build). JSON. */
    public function planapprove($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $pi = $this->ownedPlan($this->getParam('plan', 0));
        if (!$pi) { Flight::jsonError('No such plan', 404); return; }
        [$plan] = $pi;
        $plan->planStatus = 'approved';
        $plan->updatedAt  = date('Y-m-d H:i:s');
        Bean::store($plan);
        Flight::jsonSuccess(['plan_status' => 'approved'], 'Plan approved — ready to build.');
    }

    /**
     * POST /aibuilder/planrun?plan= — launch the detached worktree orchestrator for
     * an approved plan (parallel build agents, capped at PlanExecutor::MAX_CONCURRENT). JSON.
     */
    public function planrun($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $pi = $this->ownedPlan($this->getParam('plan', 0));
        if (!$pi) { Flight::jsonError('No such plan', 404); return; }
        [$plan, $inst] = $pi;

        if (!in_array($plan->planStatus, ['approved', 'stalled'], true)) {
            Flight::jsonError('Approve the plan before running it (or it is already building).', 409);
            return;
        }
        if (\app\PlanOrchestrator::running((int)$plan->id, (string)$inst->slug)) {
            Flight::jsonError('This plan is already running.', 409); return;
        }

        $dir = $this->instanceDir($inst->slug);
        // Worker model for the orchestrator. The executor runs the claude CLI for every
        // task today (native non-claude dispatch is Phase A), so this --model must be a
        // claude-valid model — resolve the member's CLAUDE worker override, default sonnet.
        // Per-task engine selection still happens inside PlanExecutor via the registry.
        $workerModel = MemberEnginePrefs::model((int)$this->member->id, 'claude', 'worker', 'sonnet');
        // The launch block lives in core (app\PlanOrchestrator): it resolves the
        // orchestrator script, exports the per-instance workbench.db so plan state is
        // written where this plan actually lives, and refuses to report success for a
        // command it cannot run.
        if (!\app\PlanOrchestrator::launch(
            (int)$plan->id, (string)$inst->slug, $dir, (int)$this->member->level, $workerModel
        )) {
            Flight::jsonError('Could not start the orchestrator.', 500);
            return;
        }
        $plan->planStatus = 'building';
        $plan->status     = 'running';   // sync the plain status column for the Workbench list
        $plan->updatedAt  = date('Y-m-d H:i:s');
        Bean::store($plan);
        Flight::jsonSuccess(
            ['session' => \app\PlanOrchestrator::sessionName((int)$plan->id, (string)$inst->slug)],
            'Build started — up to ' . PlanExecutor::MAX_CONCURRENT . ' agents running.'
        );
    }

    /** GET /aibuilder/planprogress?plan= — per-task build status for the live board. JSON. */
    public function planprogress($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $pi = $this->ownedPlan($this->getParam('plan', 0));
        if (!$pi) { Flight::jsonError('No such plan', 404); return; }
        [$plan, $inst] = $pi;
        $subs = Bean::find('workbenchtask', 'parent_task_id = ? ORDER BY priority ASC, id ASC', [(int)$plan->id]);
        $tasks = [];
        foreach ($subs as $s) {
            $tasks[] = [
                'id' => (int)$s->id, 'title' => $s->title, 'status' => $s->status,
                'engine' => $s->engine, 'error' => (string)$s->errorMessage,
                'depends_on' => json_decode(((string)$s->dependsOn ?: '[]') ?? '', true) ?: [],
            ];
        }
        Flight::jsonSuccess([
            'plan_status' => $plan->planStatus ?: 'draft',
            'running'     => \app\PlanOrchestrator::running((int)$plan->id, (string)$inst->slug),
            'tasks'       => $tasks,
        ]);
    }

    /**
     * POST /aibuilder/restart — kill the instance's jailed tmux session so a fresh
     * jail (with the current binds/settings) launches when the terminal reconnects.
     * The fpm user owns the socket, so no elevation is needed. JSON.
     */
    public function restart($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }
        $sock = $this->instanceDir($inst->slug) . '/.aibuilder/tmux.sock';
        if (@file_exists($sock)) {
            @exec('tmux -S ' . escapeshellarg($sock) . ' kill-server 2>&1');
        }
        Flight::jsonSuccess([], 'Session restarted — reconnecting');
    }

    /**
     * POST /aibuilder/delete — danger-zone delete. The caller must type the
     * instance's full domain (slug.tiknix.com) to confirm. Kills the jailed
     * session, unlinks any GitHub connector (the remote repo is left intact),
     * archives the folder to a tombstone zip in a fresh public/, wipes everything
     * else, and removes the instance + connector DB records. JSON.
     */
    public function delete($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;

        // Confirm-gated teardown (kill jail, unlink connectors, archive+wipe the dir incl.
        // its workbench.db, trash the instance + core task records) → core provision seam.
        $res = $this->provisionCall('delete', [
            'id'      => (int) $this->getParam('id', 0),
            'confirm' => trim((string) $this->getParam('confirm', '')),
            'is_root' => $this->hasLevel(LEVELS['ROOT']),
        ]);
        if (!empty($res['success'])) {
            $d = (array) ($res['data'] ?? []);
            Flight::jsonSuccess(['slug' => (string) ($d['slug'] ?? ''), 'steps' => (array) ($d['steps'] ?? [])],
                'Deleted ' . (string) ($d['domain'] ?? ($d['slug'] ?? 'instance')));
        } else { Flight::jsonError((string) ($res['message'] ?? 'Delete failed'), (int) ($res['code'] ?? 500)); }
    }

    // --- Uploads: secure (private/gitignored) + public (published) ------------

    private const UPLOAD_MAX = 52428800; // 50 MB per file

    /** Relative dir for an upload bucket. public/uploads is under the docroot (web-served);
     *  secure/uploads is outside it (not web-accessible). BOTH are tracked and published. */
    private function uploadBucketRel(string $bucket): string {
        return ($bucket === 'public' ? 'public' : 'secure') . '/uploads';
    }

    /** Ensure both upload buckets exist. public/uploads is web-served (under the docroot);
     *  secure/uploads sits outside the docroot so it is NOT web-accessible — a place for a
     *  DB or system files. Both are committed + published; the only difference is reachability. */
    private function ensureUploadDirs(string $slug): void {
        $root = $this->instanceDir($slug);
        foreach (['public/uploads', 'secure/uploads'] as $rel) {
            @mkdir($root . '/' . $rel, 0775, true);
            $keep = $root . '/' . $rel . '/.gitkeep';
            if (!is_file($keep)) @file_put_contents($keep, '');
        }
        // public/uploads is web-served: serve assets, but never EXECUTE uploaded code.
        $puh = $root . '/public/uploads/.htaccess';
        if (!is_file($puh)) {
            @file_put_contents($puh,
                "# Uploaded assets are served but never executed.\n"
                . "<FilesMatch \"\\.(php|phtml|phar|php[0-9]|pht)$\">\n    Require all denied\n</FilesMatch>\n");
        }
        // Defense-in-depth: if a web server is ever mis-pointed at the instance root
        // (docroot must be public/), deny web access to secure/ entirely.
        $sh = $root . '/secure/.htaccess';
        if (!is_file($sh)) @file_put_contents($sh, "Require all denied\n");
    }

    /** Reduce an uploaded name to a safe basename (no traversal, no hidden files). */
    private function safeName(string $name): string {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name));
        $name = ltrim($name, '.');
        return substr($name === '' ? 'file' : $name, 0, 120);
    }

    /** POST /aibuilder/upload — store file(s) into the secure|public bucket. JSON. */
    public function upload($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $bucket    = $this->getParam('bucket', 'secure') === 'public' ? 'public' : 'secure';
        $overwrite = filter_var($this->getParam('overwrite', false), FILTER_VALIDATE_BOOLEAN);
        $this->ensureUploadDirs($inst->slug);
        $destDir = $this->instanceDir($inst->slug) . '/' . $this->uploadBucketRel($bucket);

        if (empty($_FILES['files']['name'])) { Flight::jsonError('No files uploaded', 400); return; }
        $names = (array)$_FILES['files']['name'];
        $tmps  = (array)$_FILES['files']['tmp_name'];
        $errs  = (array)$_FILES['files']['error'];
        $sizes = (array)$_FILES['files']['size'];

        $stored = []; $errors = [];
        foreach ($names as $i => $origName) {
            if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $errors[] = $origName . ': upload error'; continue; }
            if (($sizes[$i] ?? 0) > self::UPLOAD_MAX)               { $errors[] = $origName . ': too large (max 50MB)'; continue; }
            if (!is_uploaded_file($tmps[$i]))                        { $errors[] = $origName . ': invalid'; continue; }

            $name = $this->safeName((string)$origName);
            $dest = $destDir . '/' . $name;
            if ($overwrite) {
                // index.php is protected — never overwrite the front controller.
                if (strtolower($name) === 'index.php' && is_file($dest)) {
                    $errors[] = $origName . ': index.php is protected (not overwritten)'; continue;
                }
                // otherwise keep $dest as-is; move_uploaded_file replaces it.
            } else {
                $n = 1;
                while (is_file($dest)) {
                    $ext  = pathinfo($name, PATHINFO_EXTENSION);
                    $dest = $destDir . '/' . pathinfo($name, PATHINFO_FILENAME) . '-' . $n . ($ext ? '.' . $ext : '');
                    $n++;
                }
            }
            if (move_uploaded_file($tmps[$i], $dest)) {
                @chmod($dest, 0664);
                $rel = $this->uploadBucketRel($bucket) . '/' . basename($dest);
                // Track the file so it publishes with the next checkpoint (both buckets publish).
                $this->gitInstance($inst->slug, ['add', $rel]);
                $stored[] = ['name' => basename($dest), 'path' => $rel, 'ref' => '@' . $rel, 'bucket' => $bucket];
            } else {
                $errors[] = $origName . ': write failed';
            }
        }
        Flight::jsonSuccess(['stored' => $stored, 'errors' => $errors], count($stored) . ' file(s) uploaded');
    }

    /** GET /aibuilder/uploads?id= — list uploaded files by bucket. JSON. */
    public function uploads($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }
        $out = ['secure' => [], 'public' => []];
        foreach (['secure', 'public'] as $b) {
            $dir = $this->instanceDir($inst->slug) . '/' . $this->uploadBucketRel($b);
            if (!is_dir($dir)) continue;
            foreach (scandir($dir) as $f) {
                if ($f === '.' || $f === '..' || $f === '.gitkeep' || $f === '.htaccess') continue;
                $full = $dir . '/' . $f;
                if (!is_file($full)) continue;
                $rel = $this->uploadBucketRel($b) . '/' . $f;
                $out[$b][] = ['name' => $f, 'path' => $rel, 'ref' => '@' . $rel, 'size' => filesize($full)];
            }
        }
        Flight::jsonSuccess(['uploads' => $out]);
    }

    /** POST /aibuilder/deleteupload — remove an uploaded file. JSON. */
    public function deleteupload($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }
        $bucket = $this->getParam('bucket', 'secure') === 'public' ? 'public' : 'secure';
        $name   = basename((string)$this->getParam('name', ''));
        if ($name === '' || $name === '.gitkeep') { Flight::jsonError('Invalid file', 400); return; }

        $relDir    = $this->uploadBucketRel($bucket);
        $bucketDir = realpath($this->instanceDir($inst->slug) . '/' . $relDir);
        $real      = realpath($this->instanceDir($inst->slug) . '/' . $relDir . '/' . $name);
        if (!$real || !$bucketDir || strpos($real, $bucketDir) !== 0 || !is_file($real)) {
            Flight::jsonError('Not found', 404); return;
        }
        $this->gitInstance($inst->slug, ['rm', '-f', '--cached', $relDir . '/' . $name]);
        @unlink($real);
        Flight::jsonSuccess([], 'Deleted');
    }
}
