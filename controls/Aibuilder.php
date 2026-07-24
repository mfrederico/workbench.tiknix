<?php
/**
 * AI Builder — in-app entry to a member's jailed Claude/qwen coding sessions.
 *
 * A member (admin) provisions one or more isolated "<slug>.tiknix" instances —
 * each an independent git clone with its own SQLite DB. Opening an instance mints
 * a short-lived HMAC token and renders a terminal (xterm) + chat UI that connect,
 * same-origin, to the aibuilder bridges:
 *   - terminal: wss://<host>/aibuilder/ws       -> node bridge (127.0.0.1:3990)
 *   - chat:     wss://<host>/aibuilder/chat-ws    -> php bridge  (127.0.0.1:3991)
 * Both spawn a bubblewrap-jailed agent confined to THAT instance. Checkpoint /
 * Rollback shell out to the capricorn instance scripts so any change is reversible.
 *
 * Security: the bubblewrap jail (capricorn/bin/jail-run.sh) is the real boundary.
 * This controller gates access (ADMIN), mints the token, validates instance
 * ownership, and brokers snapshot/rollback. Slugs are strictly validated before
 * any shell use, and the shared token secret must match the bridges' env.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use RedBeanPHP\R;
use app\EngineRegistry;
use app\MemberEnginePrefs;
use app\BrokerService;

class Aibuilder extends Control {

    private const SLUG_RE = '/^[a-z][a-z0-9]{1,49}$/';
    private const APP     = 'tiknix';

    /**
     * The whole AI Builder is control-plane-only: a provisioned sandbox instance
     * is a leaf and must not run the instance tooling (no nested instances until
     * host-aware nesting exists). Gate every route in one place.
     */
    public function __construct() {
        parent::__construct();
        $this->requireBuilderTools('The AI Builder');
    }

    private function cfg(): array {
        return @parse_ini_file(dirname(__DIR__) . '/conf/aibuilder.ini', true) ?: [];
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
        $host = strtolower((string)(parse_url((string)Flight::get('app.baseurl'), PHP_URL_HOST) ?: ''));
        $ns   = preg_replace('/\.com$/', '', $host);
        return ($ns !== '' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $ns))
            ? $ns : self::APP;
    }

    private function instanceDir(string $sub): string {
        return '/var/www/html/default/' . $sub . '.' . $this->appNamespace();
    }

    /** base64url(payload) + "." + hex(HMAC-SHA256(payload, secret)) — mirrors the bridges. */
    private function mintToken(string $sub, int $memberId): string {
        $cfg    = $this->cfg();
        $secret = (string)($cfg['token']['secret'] ?? '');
        $ttl    = (int)($cfg['token']['ttl'] ?? 120);
        $payload = json_encode([
            'app' => $this->appNamespace(), 'sub' => $sub, 'member_id' => $memberId,
            'exp' => time() + $ttl,
        ]);
        $b64 = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        return $b64 . '.' . hash_hmac('sha256', $b64, $secret);
    }

    /** Load an instance the current member owns and that exists on disk. */
    private function ownedInstance($id) {
        $id = (int)$id;
        if (!$id) return null;
        $inst = R::load('instance', $id);
        if (!$inst->id) return null;
        if ((int)$inst->memberId !== (int)$this->member->id) return null;
        if (!is_file($this->instanceDir($inst->slug) . '/public/index.php')) return null;
        return $inst;
    }

    /**
     * An instance the current member may USE: their own, OR one shared with a team
     * they belong to. Owner-only actions (share/unshare, delete, rollback) keep
     * using ownedInstance() instead.
     */
    private function accessibleInstance($id) {
        $id = (int)$id;
        if (!$id) return null;
        $inst = R::load('instance', $id);
        if (!$inst->id) return null;
        $mid  = (int)$this->member->id;
        $mine = (int)$inst->memberId === $mid;
        if (!$mine) {
            // Shared with one of the member's teams (many-to-many via instance_team)?
            if (!$this->hasShareTable()) return null;
            $shared = (int)R::getCell(
                'SELECT COUNT(*) FROM instance_team it
                   JOIN teammember tm ON tm.team_id = it.team_id
                 WHERE it.instance_id = ? AND tm.member_id = ?', [$id, $mid]) > 0;
            if (!$shared) return null;
        }
        if (!is_file($this->instanceDir($inst->slug) . '/public/index.php')) return null;
        return $inst;
    }

    /** True when the current member owns the instance (for owner-only actions). */
    private function isInstanceOwner($inst): bool {
        return $inst && (int)$inst->memberId === (int)$this->member->id;
    }

    /** The instance<->team link table appears only after the first share. */
    private function hasShareTable(): bool {
        return in_array('instance_team', R::inspect(), true);
    }

    /** Instance ids shared with ANY of the given teams (empty if unshared / no table). */
    private function instanceIdsSharedWithTeams(array $teamIds): array {
        $teamIds = array_values(array_map('intval', $teamIds));
        if (!$teamIds || !$this->hasShareTable()) return [];
        $ph = implode(',', array_fill(0, count($teamIds), '?'));
        return array_map('intval', R::getCol(
            "SELECT DISTINCT instance_id FROM instance_team WHERE team_id IN ($ph)", $teamIds));
    }

    /** Team ids a given instance is currently shared with. */
    private function teamIdsForInstance(int $instanceId): array {
        if (!$instanceId || !$this->hasShareTable()) return [];
        return array_map('intval', R::getCol(
            'SELECT team_id FROM instance_team WHERE instance_id = ?', [$instanceId]));
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
        $this->renderHome((int)$this->getParam('id', 0));
    }

    /** GET /aibuilder/open/<id> — open a specific instance's terminal + chat. */
    public function open($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $this->renderHome((int)($params['operation']->name ?? $this->getParam('id', 0)));
    }

    /** Render the instance picker plus, if one is selected, its Terminal/Chat. */
    private function renderHome(int $selId): void {
        // Owned instances + any shared with a team the member belongs to (many-to-many).
        $mid     = (int)$this->member->id;
        $teamIds = array_values(array_map('intval', R::getCol('SELECT team_id FROM teammember WHERE member_id = ?', [$mid])));
        $sharedInstanceIds = $this->instanceIdsSharedWithTeams($teamIds);
        $where = 'member_id = ?';
        $args  = [$mid];
        if ($sharedInstanceIds) {
            $where .= ' OR id IN (' . implode(',', array_fill(0, count($sharedInstanceIds), '?')) . ')';
            $args   = array_merge($args, $sharedInstanceIds);
        }
        $instances = R::find('instance', $where . ' ORDER BY created_at DESC', $args);
        $selected  = $selId ? $this->accessibleInstance($selId) : null;

        // Neutralize Claude's in-jail browser-open before the terminal opens, so a
        // first-run `claude` sign-in surfaces in the gate instead of a dead browser.
        if ($selected) $this->ensureOAuthCapture($selected->slug);

        // Teams the member can share an owned instance INTO (for the share control).
        $shareTeams = $teamIds
            ? R::find('team', 'id IN (' . implode(',', array_fill(0, count($teamIds), '?')) . ') ORDER BY name', $teamIds)
            : [];

        // Which of the displayed instances have ANY share (for the picker badges),
        // and which teams the SELECTED instance is shared with (for checkbox state).
        // array_values(): R::find keys results by bean id; those keys must not leak
        // into the IN() binding below (RedBean maps int keys to param positions).
        $displayIds = array_values(array_map(fn($i) => (int)$i->id, $instances));
        $instSharedIds = [];
        if ($displayIds && $this->hasShareTable()) {
            $ph = implode(',', array_fill(0, count($displayIds), '?'));
            $instSharedIds = array_map('intval', R::getCol(
                "SELECT DISTINCT instance_id FROM instance_team WHERE instance_id IN ($ph)", $displayIds));
        }
        $selSharedTeamIds = $selected ? $this->teamIdsForInstance((int)$selected->id) : [];

        $cfg = $this->cfg();
        $this->render('aibuilder/index', [
            'title'            => 'Advanced Builder',
            'instances'        => array_values($instances),
            'shareTeams'       => array_values($shareTeams),
            'ab_memberId'      => $mid,
            'ab_isOwner'       => $selected ? $this->isInstanceOwner($selected) : false,
            'ab_sharedTeamIds' => array_values($selSharedTeamIds),
            'ab_instSharedIds' => array_values($instSharedIds),
            'selected'       => $selected,
            'ab_sub'         => $selected ? $selected->slug : '',
            'ab_token'       => $selected ? $this->mintToken($selected->slug, (int)$this->member->id) : '',
            'ab_wspath'      => (string)($cfg['bridge']['ws_path'] ?? '/aibuilder/ws'),
            'ab_chat_wspath' => (string)($cfg['bridge']['chat_ws_path'] ?? '/aibuilder/chat-ws'),
            'ab_hasInstance' => (bool)$selected,
            'ab_isDefault'   => $selected ? (bool)$selected->isDefault : false,
            'ab_isRoot'      => Flight::hasLevel(LEVELS['ROOT']),
            'ab_canCreate'   => Flight::hasLevel(LEVELS['ADMIN']),
            'ab_mainRepo'    => GitHubPublisher::mainGithubRepo(),
            'ab_url'         => $selected ? 'https://' . $selected->slug . '.' . $this->appNamespace() . '.com' : '',
        ]);
    }

    /** POST /aibuilder/create — provision a new instance. JSON. Provisioning is
     *  ADMIN-only even though using instances is open to members. */
    public function create($params = []): void {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;
        if (!$this->validateCSRF()) return;

        $slug   = strtolower(trim((string)$this->getParam('slug', '')));
        $name   = trim((string)$this->getParam('name', '')) ?: ucfirst($slug);
        $engine = EngineRegistry::coerce($this->getParam('engine'), EngineRegistry::defaultEngine());

        if (!preg_match(self::SLUG_RE, $slug)) {
            Flight::jsonError('Invalid name: use 2-50 lowercase letters/numbers, starting with a letter.', 400);
            return;
        }
        if (R::count('instance', 'slug = ?', [$slug]) > 0 || is_dir($this->instanceDir($slug))) {
            Flight::jsonError('That name is already taken.', 409);
            return;
        }

        // Provision: capricorn clones the app, seeds an isolated sqlite db + guardrails.
        $out = $this->runScript('provision-instance.sh',
            [$this->appNamespace(), $slug, '--admin', (string)$this->member->email, '--name', $name]);

        if (!is_file($this->instanceDir($slug) . '/public/index.php')) {
            $this->logger->error('aibuilder provision failed', ['slug' => $slug, 'out' => $out['out']]);
            Flight::jsonError('Provisioning failed. ' . substr(trim($out['out']), -300), 500);
            return;
        }

        // Honor the chosen engine (provision wrote the conf default; override if needed).
        @file_put_contents($this->instanceDir($slug) . '/.aibuilder/engine', $engine . "\n");

        // Neutralize Claude's browser-open in the jail (no GUI there) so its first
        // sign-in falls to the in-terminal URL + paste prompt the gate drives.
        $this->ensureOAuthCapture($slug);

        // Record ownership via the association (sets member_id automatically).
        $member = R::load('member', (int)$this->member->id);
        $inst = R::dispense('instance');
        $inst->slug        = $slug;
        $inst->app         = $this->appNamespace();
        $inst->displayName = $name;
        $inst->engine      = $engine;
        $inst->status      = 'active';
        // Root may flag one instance as the "(default)" tiknix-core sandbox that
        // publishes back to main. Only root; other members' instances are never default.
        $inst->isDefault   = (Flight::hasLevel(LEVELS['ROOT'])
                              && filter_var($this->getParam('is_default', false), FILTER_VALIDATE_BOOLEAN)) ? 1 : 0;
        $inst->createdAt   = date('Y-m-d H:i:s');
        $member->ownInstanceList[] = $inst;
        R::store($member);

        // Give the instance its OWN broker-key identity, bound to THIS instance's id,
        // so it can reach its (future) connections and drive the connect flow from its
        // own /connections page. Safe — its own key, written to its own broker.ini
        // (provisioning already reset any inherited one to the empty template).
        try {
            BrokerService::ensureInstanceConfig((int)$inst->id, (int)$this->member->id, $this->instanceDir($slug));
        } catch (\Throwable $e) {
            $this->logger->warning('aibuilder broker-key mint failed', ['slug' => $slug, 'err' => $e->getMessage()]);
        }

        $this->logger->info('aibuilder instance created', ['slug' => $slug, 'by' => $this->member->id]);
        Flight::jsonSuccess(['id' => $inst->id, 'slug' => $slug], 'Instance created');
    }

    /** GET /aibuilder/refresh?id= — re-mint a token (AJAX reconnect). JSON. */
    public function refresh($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }
        Flight::jsonSuccess(['token' => $this->mintToken($inst->slug, (int)$this->member->id)]);
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

        // Auto-publish: if this instance has a GitHub connection with auto-publish on,
        // push HEAD + open/refresh a PR right after the checkpoint lands.
        $publish = null;
        $conn = Bean::findOne('connections',
            'member_id = ? AND instance_id = ? AND connector_type = ? AND enabled = 1',
            [(int)$this->member->id, (int)$inst->id, 'github']);
        if ($conn && $conn->id) {
            $meta = json_decode(($conn->metadataJson ?: '{}') ?? '', true) ?: [];
            if (!empty($meta['autoPublish'])) {
                $res = GitHubPublisher::publish($inst, $conn);
                $conn->lastUsedAt = date('Y-m-d H:i:s');
                $conn->lastError  = $res['ok'] ? ($res['note'] ?? null) : ($res['error'] ?? 'publish failed');
                Bean::store($conn);
                $publish = $res['ok']
                    ? ['ok' => true, 'pr' => $res['pr'], 'message' => $res['message'], 'note' => $res['note'] ?? null]
                    : ['ok' => false, 'error' => $res['error'] ?? 'publish failed'];
            }
        }

        Flight::jsonSuccess(['checkpoint' => $tag, 'description' => $desc, 'publish' => $publish], 'Checkpoint saved');
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
        $inst = $this->ownedInstance($this->getParam('id', 0));   // owner only
        if (!$inst) { Flight::jsonError('No such instance (owner only)', 404); return; }

        $teamId = (int)$this->getParam('team_id', 0);
        $shared = (int)$this->getParam('shared', 0) === 1;
        if ($teamId <= 0) { Flight::jsonError('Pick a team', 400); return; }

        // Owner must belong to the team they share into.
        $isMember = (int)R::getCell('SELECT COUNT(*) FROM teammember WHERE team_id = ? AND member_id = ?',
                [$teamId, (int)$this->member->id]) > 0;
        if (!$isMember) { Flight::jsonError('You are not a member of that team', 403); return; }
        $team = R::load('team', $teamId);
        if (!$team->id) { Flight::jsonError('No such team', 404); return; }

        // Add/remove this team in the instance's shared-team set (keyed by bean id,
        // so the toggle is idempotent). RedBean reconciles the instance_team link
        // table on store.
        $teams = $inst->sharedTeamList;
        if ($shared) $teams[$team->id] = $team;
        else         unset($teams[$team->id]);
        $inst->sharedTeamList = $teams;
        R::store($inst);

        $ids = array_map('intval', array_keys($inst->sharedTeamList));
        Flight::jsonSuccess(
            ['team_id' => $teamId, 'team_name' => $team->name, 'shared' => $shared, 'shared_team_ids' => array_values($ids)],
            $shared ? ('Shared with ' . $team->name) : ('Removed from ' . $team->name)
        );
    }

    /** The configured sqlite db path (relative) for an instance, e.g. "database/foo.db". */
    private function instanceDbRel(string $slug): string {
        $ini = @parse_ini_file($this->instanceDir($slug) . '/conf/config.ini', true) ?: [];
        $p   = (string)($ini['database']['path'] ?? '');
        return preg_match('#^database/[A-Za-z0-9._-]+\.db$#', $p) ? $p : 'database/' . $slug . '.db';
    }

    /** Register an instance bean owned by the current member (shared by create/fork). */
    private function registerInstanceBean(string $slug, string $name, string $engine): object {
        $member = R::load('member', (int)$this->member->id);
        $inst = R::dispense('instance');
        $inst->slug        = $slug;
        $inst->app         = $this->appNamespace();
        $inst->displayName = $name;
        $inst->engine      = $engine;
        $inst->status      = 'active';
        $inst->isDefault   = 0;   // forks/creates by non-root are never the core default
        $inst->createdAt   = date('Y-m-d H:i:s');
        $member->ownInstanceList[] = $inst;
        R::store($member);
        return $inst;
    }

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

        $src = $this->accessibleInstance($this->getParam('id', 0));
        if (!$src) { Flight::jsonError('No such source instance', 404); return; }

        $ckpt = (string)($params['operation']->name ?? $this->getParam('checkpoint', 'checkpoint-baseline'));
        if (!preg_match('/^checkpoint-[A-Za-z0-9._-]+$/', $ckpt)) { Flight::jsonError('Invalid checkpoint name', 400); return; }
        // The tag must exist in the source repo.
        $tagCheck = $this->gitInstance($src->slug, ['tag', '-l', $ckpt]);
        if (trim($tagCheck['out']) !== $ckpt) { Flight::jsonError('Checkpoint not found in source instance', 404); return; }

        $slug   = strtolower(trim((string)$this->getParam('slug', '')));
        $name   = trim((string)$this->getParam('name', '')) ?: ucfirst($slug);
        $engine = (string)($src->engine ?: 'claude');
        if (!preg_match(self::SLUG_RE, $slug)) {
            Flight::jsonError('Invalid name: use 2-50 lowercase letters/numbers, starting with a letter.', 400);
            return;
        }
        if (R::count('instance', 'slug = ?', [$slug]) > 0 || is_dir($this->instanceDir($slug))) {
            Flight::jsonError('That name is already taken.', 409);
            return;
        }

        $srcDir = $this->instanceDir($src->slug);
        $newDir = $this->instanceDir($slug);

        // 1) Provision a fresh skeleton (own db + config + jail + fresh secrets).
        $out = $this->runScript('provision-instance.sh',
            [$this->appNamespace(), $slug, '--admin', (string)$this->member->email, '--name', $name]);
        if (!is_file($newDir . '/public/index.php')) {
            $this->logger->error('aibuilder fork: provision failed', ['slug' => $slug, 'out' => $out['out']]);
            Flight::jsonError('Provisioning failed. ' . substr(trim($out['out']), -300), 500);
            return;
        }

        // 2) Overlay the source checkpoint CODE (tracked tree minus database/, which is
        //    instance-specific) onto the fresh instance. Config is gitignored, so the
        //    new instance keeps its own — that's the secret/connection reset.
        $tar = $newDir . '/.aibuilder/fork-src.tar';
        @mkdir(dirname($tar), 0775, true);
        $a = []; $ac = 0;
        exec('git -C ' . escapeshellarg($srcDir) . ' archive ' . escapeshellarg($ckpt)
             . ' -o ' . escapeshellarg($tar) . ' 2>&1', $a, $ac);
        if ($ac !== 0) {
            $this->logger->error('aibuilder fork: archive failed', ['slug' => $slug, 'out' => implode("\n", $a)]);
            Flight::jsonError('Could not read the checkpoint tree.', 500);
            return;
        }
        $e = []; $ec = 0;
        exec('tar -xf ' . escapeshellarg($tar) . ' -C ' . escapeshellarg($newDir)
             . ' --exclude=' . escapeshellarg('database') . ' --exclude=' . escapeshellarg('database/*')
             . ' 2>&1', $e, $ec);
        @unlink($tar);
        if ($ec !== 0) {
            $this->logger->error('aibuilder fork: extract failed', ['slug' => $slug, 'out' => implode("\n", $e)]);
            Flight::jsonError('Could not apply the checkpoint code (permissions?).', 500);
            return;
        }

        // 3) Carry DATA: stream the source checkpoint's tracked sqlite db into the new
        //    instance's configured db path (overwriting the freshly-seeded db).
        $srcDbRel = $this->instanceDbRel($src->slug);   // e.g. database/bidsurge.db
        $newDb    = $newDir . '/' . $this->instanceDbRel($slug);
        $carried  = false;
        // Confirm the db blob exists in the tag before copying.
        $lt = $this->gitInstance($src->slug, ['cat-file', '-e', $ckpt . ':' . $srcDbRel]);
        if ($lt['ok']) {
            $d = []; $dc = 0;
            exec('git -C ' . escapeshellarg($srcDir) . ' show ' . escapeshellarg($ckpt . ':' . $srcDbRel)
                 . ' > ' . escapeshellarg($newDb) . ' 2>&1', $d, $dc);
            $carried = ($dc === 0 && is_file($newDb) && filesize($newDb) > 0);
            if (!$carried) {
                $this->logger->warning('aibuilder fork: db copy failed', ['slug' => $slug, 'out' => implode("\n", $d)]);
            }
        }

        // 4) Commit the overlaid tree as the new instance's baseline.
        $this->gitInstance($slug, ['add', '-A']);
        $this->gitInstance($slug, ['commit', '--no-verify', '-m',
            'Fork from ' . $src->slug . '@' . $ckpt . ($carried ? ' (code+data)' : ' (code only)')]);

        // 5) Neutralize the in-jail browser + register ownership (forker owns it).
        $this->ensureOAuthCapture($slug);
        $inst = $this->registerInstanceBean($slug, $name, $engine);

        $this->logger->info('aibuilder instance forked', [
            'from' => $src->slug, 'checkpoint' => $ckpt, 'slug' => $slug, 'by' => $this->member->id, 'data' => $carried,
        ]);
        Flight::jsonSuccess(
            ['id' => $inst->id, 'slug' => $slug, 'data_carried' => $carried],
            'New instance created from ' . $ckpt . ($carried ? '' : ' (code only — data not carried)')
        );
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

        $parents = R::find('workbenchtask', 'instance_id = ? AND parent_task_id IS NULL ORDER BY created_at DESC', [(int)$inst->id]);
        $plans = [];
        foreach ($parents as $p) {
            $subs = R::find('workbenchtask', 'parent_task_id = ? ORDER BY priority ASC, id ASC', [(int)$p->id]);
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
        $plan = R::load('workbenchtask', $planId);
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
        R::store($plan);
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
        $session = 'tiknix-plan' . (int)$plan->id . '-orchestrator';
        if (TmuxManager::exists($session)) { Flight::jsonError('This plan is already running.', 409); return; }

        $dir = $this->instanceDir($inst->slug);
        // Worker model for the orchestrator. The executor runs the claude CLI for every
        // task today (native non-claude dispatch is Phase A), so this --model must be a
        // claude-valid model — resolve the member's CLAUDE worker override, default sonnet.
        // Per-task engine selection still happens inside PlanExecutor via the registry.
        $workerModel = MemberEnginePrefs::model((int)$this->member->id, 'claude', 'worker', 'sonnet');
        $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/scripts/plan-orchestrate.php')
             . ' --plan=' . (int)$plan->id
             . ' --slug=' . escapeshellarg((string)$inst->slug)
             . ' --dir='  . escapeshellarg($dir)
             . ' --model=' . escapeshellarg($workerModel)
             . ' --level=' . (int)$this->member->level;
        $ab = $dir . '/.aibuilder';
        @mkdir($ab, 0775, true);
        $scriptFile = $ab . '/run-orchestrator.sh';
        file_put_contents($scriptFile, "#!/bin/bash\n" . $cmd . ' 2>&1 | tee ' . escapeshellarg($ab . '/orchestrator.log') . "\n");
        @chmod($scriptFile, 0755);

        if (!TmuxManager::create($session, $scriptFile, $dir)) {
            Flight::jsonError('Could not start the orchestrator.', 500);
            return;
        }
        $plan->planStatus = 'building';
        $plan->status     = 'running';   // sync the plain status column for the Workbench list
        $plan->updatedAt  = date('Y-m-d H:i:s');
        R::store($plan);
        Flight::jsonSuccess(['session' => $session], 'Build started — up to ' . PlanExecutor::MAX_CONCURRENT . ' agents running.');
    }

    /** GET /aibuilder/planprogress?plan= — per-task build status for the live board. JSON. */
    public function planprogress($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $pi = $this->ownedPlan($this->getParam('plan', 0));
        if (!$pi) { Flight::jsonError('No such plan', 404); return; }
        [$plan] = $pi;
        $subs = R::find('workbenchtask', 'parent_task_id = ? ORDER BY priority ASC, id ASC', [(int)$plan->id]);
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
            'running'     => TmuxManager::exists('tiknix-plan' . (int)$plan->id . '-orchestrator'),
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

        $inst = R::load('instance', (int)$this->getParam('id', 0));
        if (!$inst->id) { Flight::jsonError('No such instance', 404); return; }
        if ((int)$inst->memberId !== (int)$this->member->id && !Flight::hasLevel(LEVELS['ROOT'])) {
            Flight::jsonError('Not your instance', 403); return;
        }
        if (!empty($inst->isDefault)) { Flight::jsonError('The (default) core instance cannot be deleted here.', 403); return; }

        $slug = (string)$inst->slug;
        if (!preg_match(self::SLUG_RE, $slug)) { Flight::jsonError('Invalid instance slug', 400); return; }
        $domain = $slug . '.' . $this->appNamespace() . '.com';
        if (!hash_equals($domain, trim((string)$this->getParam('confirm', '')))) {
            Flight::jsonError('Confirmation does not match — type "' . $domain . '" exactly.', 400); return;
        }

        $dir = $this->instanceDir($slug);
        // Hard safety before any destructive fs op: canonical path + a dot in the
        // basename (every instance dir is "slug.app"; the source app dir is not).
        if ($dir !== '/var/www/html/default/' . $slug . '.' . $this->appNamespace() || strpos(basename($dir), '.') === false) {
            Flight::jsonError('Refusing to delete: path failed validation', 400); return;
        }

        $steps = [];

        // 1) Kill the jailed session (same mechanism as restart).
        $sock = $dir . '/.aibuilder/tmux.sock';
        if (@file_exists($sock)) { @exec('tmux -S ' . escapeshellarg($sock) . ' kill-server 2>&1'); $steps[] = 'killed jailed session'; }

        // 2) Unlink GitHub connector(s). The remote repo itself is left untouched.
        $conns = R::find('connections', 'instance_id = ?', [(int)$inst->id]);
        if ($conns) { R::trashAll($conns); $steps[] = 'removed ' . count($conns) . ' connector(s)'; }

        // 3) Archive + wipe, leaving a tombstone zip in a fresh public/.
        if (is_dir($dir)) {
            $res = $this->archiveInstance($dir, $slug);
            if (!$res['ok']) { Flight::jsonError('Archive failed: ' . $res['error'], 500); return; }
            $steps[] = $res['message'];
        } else {
            $steps[] = 'folder already absent';
        }

        // 4) Delete every workbench task tagged to this instance (plan parents +
        // subtasks, standalone tasks) and their child rows, so the workbench isn't
        // left showing orphaned tasks for a gone instance. Also stop any of their
        // still-live sessions and remove per-task workspace clones under /projects/.
        $tasks = R::find('workbenchtask', 'instance_id = ?', [(int)$inst->id]);
        if ($tasks) {
            $killed = 0; $wiped = 0;
            foreach ($tasks as $t) {
                // Stop live agent/task sessions + any plan orchestrator (parents).
                $sessions = [(string)$t->agentSession, (string)$t->tmuxSession];
                if (empty($t->parentTaskId)) $sessions[] = 'tiknix-plan' . (int)$t->id . '-orchestrator';
                foreach (array_unique(array_filter($sessions)) as $s) {
                    if (TmuxManager::exists($s)) { TmuxManager::kill($s); $killed++; }
                }
                // Remove the per-task workspace clone (guard to a /projects/ path).
                $ws = (string)$t->projectPath;
                if ($ws !== '' && strpos($ws, '/projects/') !== false && is_dir($ws)) {
                    @exec('rm -rf ' . escapeshellarg($ws) . ' 2>&1'); $wiped++;
                }
                // Child rows keyed by task_id.
                foreach (['tasklog', 'taskcomment', 'tasksnapshot'] as $child) {
                    $rows = R::find($child, 'task_id = ?', [(int)$t->id]);
                    if ($rows) R::trashAll($rows);
                }
            }
            R::trashAll($tasks);
            $steps[] = 'deleted ' . count($tasks) . ' workbench task(s)'
                     . ($killed ? ", stopped {$killed} session(s)" : '')
                     . ($wiped ? ", removed {$wiped} workspace(s)" : '');
        }

        // 5) Drop the instance record.
        R::trash($inst);
        $steps[] = 'removed instance record';

        $this->logger->warning('aibuilder instance deleted', ['slug' => $slug, 'by' => (int)$this->member->id, 'steps' => $steps]);
        Flight::jsonSuccess(['slug' => $slug, 'steps' => $steps], 'Deleted ' . $domain);
    }

    /**
     * Archive an instance folder to public/slug.zip, then wipe. Excludes the big
     * regenerable dirs (vendor, node_modules, .git); swaps real conf/*.ini for the
     * matching .example.ini (or drops a secret-bearing .ini with no example) so the
     * web-served archive never carries live credentials.
     */
    private function archiveInstance(string $dir, string $slug): array {
        // (a) Neutralize secrets: real conf/*.ini -> its .example.ini.
        foreach (glob($dir . '/conf/*.ini') ?: [] as $ini) {
            if (substr($ini, -12) === '.example.ini') continue;
            $example = substr($ini, 0, -4) . '.example.ini';
            if (is_file($example)) @copy($example, $ini);
            else                   @unlink($ini);
        }

        // (b) Zip everything except the heavy regenerable dirs.
        $tmpZip = sys_get_temp_dir() . '/' . $slug . '-' . date('Ymd-His') . '.zip';
        @unlink($tmpZip);
        $cmd = 'cd ' . escapeshellarg($dir) . ' && zip -r -q ' . escapeshellarg($tmpZip)
             . " . -x 'vendor/*' 'node_modules/*' '.git/*'";
        $out = []; $code = 0; @exec($cmd . ' 2>&1', $out, $code);
        if (!is_file($tmpZip)) {
            return ['ok' => false, 'error' => 'zip produced no archive: ' . implode(' ', array_slice($out, -2))];
        }

        // (c) Wipe the folder, then recreate an empty public/.
        @exec('rm -rf ' . escapeshellarg($dir) . ' 2>&1');
        if (!@mkdir($dir . '/public', 0775, true) && !is_dir($dir . '/public')) {
            return ['ok' => false, 'error' => 'could not recreate public/ (archive kept at ' . $tmpZip . ')'];
        }

        // (d) Drop the tombstone zip into public/.
        $dest = $dir . '/public/' . $slug . '.zip';
        if (!@rename($tmpZip, $dest)) { @copy($tmpZip, $dest); @unlink($tmpZip); }
        @chmod($dest, 0644);

        $kb = (int)round((@filesize($dest) ?: 0) / 1024);
        return ['ok' => true, 'message' => 'archived to public/' . $slug . '.zip (' . $kb . ' KB)'];
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
