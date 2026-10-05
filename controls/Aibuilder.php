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
        return \app\WorkbenchDb::dirOf($sub, $this->appNamespace());
    }


    /**
     * A project in its own container: its terminal is the APP's (runtime bin/terminal-bridge.php on
     * <ct_ip>:3990, reached at wss://<its domain>/aibuilder/ws through the front proxy), and runs the
     * app's own agent with the app's own credential — not core's bridge, jail or engines.
     */
    private function inContainer(object $inst): bool {
        return (string) ($inst->ctKind ?? '') === 'tenant';   // the registry's word, not whether an address happens to be set
    }

    private function mintAppToken(object $inst, int $memberId, bool $resume, string $agent): string {
        // Signed by core's one signer for apps (lib/AppToken.php), with the app's own key. The
        // session is this member's own on $agent ('' = the app's default agent).
        try {
            return \app\AppToken::terminal($inst, $memberId, $resume, $agent, (int) ($this->cfg()['token']['ttl'] ?? 120));
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    private function appWsBase(object $inst): string {
        $d = strtolower(trim((string) ($inst->ctDomain ?? '')));
        if ($d === '') throw new \RuntimeException("{$inst->slug} is in a container but has no domain (instance.ct_domain)");
        return 'wss://' . $d;
    }

    // ---- instance access: converged onto WorkbenchAccess (owner/team scoping from CORE,
    //      read-only). Return a read-only instance-meta object (drop-in for the old bean on
    //      READ paths). Registry MUTATIONS (create/fork/delete/share) are the core write-seam.

    /** An instance the current member OWNS and that exists on disk (owner-only actions). */
    private function ownedInstance($id) {
        $id = (int) $id;
        if (!$id || !$this->access->ownsInstance((int) $this->member->id, $id)) return null;
        $inst = $this->access->instanceMeta($id);
        if (!$inst || !$this->onDisk($inst)) return null;
        return $inst;
    }

    /**
     * The project's code is where this page can reach it: in its container (its workspace here
     * holds only the board, data/workbench.db). Nothing else holds a project's code any more.
     */
    private function onDisk(object $inst): bool {
        return $this->inContainer($inst);
    }

    /** An instance the current member may USE: owned OR shared with one of their teams. */
    private function accessibleInstance($id) {
        $id = (int) $id;
        if (!$id) return null;
        $inst = $this->access->instanceMeta($id);   // null unless accessible (owned ∪ team-shared)
        // The "(default)" core instance is the live control plane (core.tiknix symlinks to the
        // running app) — not a buildable instance. It's excluded from the AI Builder entirely.
        if (!$inst || !empty($inst->isDefault)) return null;
        if (!$this->onDisk($inst)) return null;
        return $inst;
    }

    /** True when the current member owns the instance (for owner-only actions). */
    private function isInstanceOwner($inst): bool {
        return $inst && $this->access->ownsInstance((int) $this->member->id, (int) $inst->id);
    }

    /** Run git inside an instance's directory (read/write its own repo only). */
    private function gitInstance(object $inst, array $args): array {
        $slug = (string) $inst->slug;
        if (!preg_match(self::SLUG_RE, $slug)) return ['ok' => false, 'out' => '', 'code' => 1];
        if ($this->inContainer($inst)) {
            // The project's repository is the app in its container (/srv/app), not its workspace here.
            $cmd = 'git -C /srv/app';
            foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string) $a); }
            [$code, $out] = \app\TenantHost::ssh($inst, 'app', $cmd . ' 2>&1', null, 30);
            return ['ok' => $code === 0, 'out' => rtrim((string) $out, "\n"), 'code' => $code];
        }
        return ['ok' => false, 'out' => "{$slug} is not in a container, so it has no repository this page can reach", 'code' => 1];
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
        // No agent on the project: the layout shows the gate and nothing else, so no terminal
        // token is minted and the container is not asked a second time.
        if (!empty($this->viewData['agentGate'])) { $this->render('aibuilder/index', ['title' => 'Terminal']); return; }
        $selId = (int) $this->selected['id'];

        // Accessible instances (owned ∪ team-shared) from CORE via WorkbenchAccess, as
        // read-only meta objects (drop-in for the old instance beans on read paths).
        $mid       = (int) $this->member->id;
        $instances = array_values(array_filter(array_map(
            fn($i) => $this->access->instanceMeta((int) $i['id']),
            $this->access->accessibleInstances())));
        $selected  = $selId ? $this->accessibleInstance($selId) : null;

        // The terminal is the APP's own (runtime bin/terminal-bridge.php, in its container).
        $termError = '';
        $inCt = $selected && $this->inContainer($selected);
        $ctToken = ''; $ctWs = ''; $ctAgentNote = null;
        $ctAgents = []; $ctAgent = (string) $this->getParam('agent', '');
        if ($inCt) {
            try {
                // The terminal runs one of the APP's agents with its credential, set on the app's own
                // AI agents page — say so up front, with the link, instead of only inside the terminal.
                // The picker lists them; each is the member's own session (aib-<agent>-m<member>).
                try {
                    $ag = \app\TenantBuilder::agents($selected);
                    $problem = (string) ($ag['claude']['problem'] ?? '');
                    // Through core's /projects/open: it signs you in to the app (a direct link to the
                    // app's /agents lands on the app's own login page).
                    $core = rtrim((string) \Flight::get('sidecar.core_url'), '/');
                    if (empty($ag['agents']) && $problem !== '') $ctAgentNote = ['problem' => $problem, 'url' => $core . '/projects/open?to=' . rawurlencode('/agents')];
                    foreach ((array) ($ag['agents'] ?? []) as $a) {
                        $n = (string) ($a['name'] ?? '');
                        if ($n === '' || empty($a['builder'])) continue;   // only a Build agent runs a terminal: the others answer, they do not edit
                        $label = $n . (!empty($a['preset']) ? ' · ' . $a['preset'] : '') . (!empty($a['is_default']) ? ' (builder)' : '');
                        $ctAgents[!empty($a['is_default']) ? '' : $n] = $label;   // '' = the default agent
                    }
                    if ($ctAgent !== '' && !array_key_exists($ctAgent, $ctAgents)) {
                        throw new \RuntimeException("{$selected->slug} has no agent '{$ctAgent}' — pick one of its agents, or add it on the app's AI agents page.");
                    }
                } catch (\RuntimeException $e) {
                    if ($ctAgent !== '') throw $e;
                    $ctAgentNote = ['problem' => $e->getMessage(), 'url' => ''];
                }
                $ctToken = $this->mintAppToken($selected, $mid, $this->getParam('resume', '') === '1', $ctAgent);
                $ctWs = $this->appWsBase($selected);
            } catch (\RuntimeException $e) {
                $termError = $e->getMessage();
                $this->logger->error('Terminal: container project not ready', ['instance' => $selected->slug, 'err' => $termError]);
            }
        } elseif ($selected) {
            // The host's node bridge and jail are retired (2026-10-01): a terminal is an app's own.
            $termError = ($selected->displayName ?: $selected->slug) . " isn't running in its own container, so it has no builder terminal.";
        }

        // Share-management UI (owner-only team sharing) is part of the registry write-seam;
        // the read/terminal/plan path works without it. Selected instance's shares are read-only.
        $shareTeams       = [];   // TODO write-seam: teams the member can share INTO
        $instSharedIds    = [];   // TODO write-seam: which displayed instances have any share

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
            'ab_sub'         => $selected ? $selected->slug : '',
            'ab_termError'   => $termError,
            'ab_token'       => $ctToken,
            // The app's agent runs there: no platform engine picker, no host key notes.
            'ab_agents'      => $ctAgents,
            'ab_agent'       => $ctAgent,
            'ab_keyNeeded'   => null,
            'ab_keyNote'     => null,
            'ab_wspath'      => (string)($cfg['bridge']['ws_path'] ?? '/aibuilder/ws'),
            'ab_ws_base'     => $ctWs,
            'ab_hasInstance' => (bool)$selected,
            'ab_inCt'        => $inCt,
            'ab_agentNote'   => $ctAgentNote,
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
        if (!$this->inContainer($inst)) { Flight::jsonError("{$inst->slug} isn't running in its own container, so it has no builder terminal.", 409); return; }
        try {
            $tok = $this->mintAppToken($inst, (int) $this->member->id, $this->getParam('resume', '') === '1', (string) $this->getParam('agent', ''));
        } catch (\RuntimeException $e) {
            Flight::jsonError($e->getMessage(), 409);
            return;
        }
        Flight::jsonSuccess(['token' => $tok]);
    }

    /** GET /aibuilder/changes?id= — files changed since the last checkpoint. JSON. */
    public function changes($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        // Uncommitted working-tree changes == the delta since the last checkpoint
        // (snapshot-instance.sh commits everything, so this self-resets per checkpoint).
        $out = $this->gitInstance($inst, ['status', '--porcelain']);
        if (!$out['ok']) { Flight::jsonError("git status failed for {$inst->slug}: " . mb_substr($out['out'], 0, 300), 502); return; }
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

        if ($this->inContainer($inst)) {
            // Read in the container, where the code is (the planner's own source, TenantBuilder::digest).
            $digest = \app\TenantBuilder::digest($inst);
            if (str_starts_with($digest, '_(codebase inventory unavailable')) { Flight::jsonError("{$inst->slug}'s container gave no codebase inventory — see core's log", 502); return; }
            Flight::jsonSuccess(['slug' => $inst->slug, 'digest' => $digest]);
            return;
        }
        Flight::jsonError("{$inst->slug} is not in a container, so there is no code to inventory", 409);
    }

    /**
     * POST /aibuilder/checkpoint?id= — checkpoint the app as it is now, with an optional
     * description (label). Taken in the container (TenantHost::checkpoint): what is uncommitted
     * is committed, HEAD tagged, each database copied — all by the member saving it. JSON.
     */
    public function checkpoint($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }
        if (!$this->inContainer($inst)) { Flight::jsonError("{$inst->slug} is not running in its own container, so it cannot be checkpointed here.", 409); return; }
        $label = mb_substr(trim((string) $this->getParam('label', '')), 0, 200);
        try {
            $c = \app\TenantHost::checkpoint($inst, 'checkpoint-' . date('Ymd-His'), $label, \app\TenantHost::author((int) $this->member->id));
        } catch (\RuntimeException $e) {
            $c = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!$c['ok']) {
            $this->logger->error('aibuilder checkpoint failed', ['instance' => $inst->slug, 'error' => $c['error']]);
            Flight::jsonError('Checkpoint failed: ' . $c['error'], 502);
            return;
        }
        $this->logger->info('aibuilder checkpoint', ['instance' => $inst->slug, 'tag' => $c['tag'], 'member' => (int) $this->member->id]);
        Flight::jsonSuccess($c, 'Checkpoint ' . preg_replace('/^checkpoint-/', '', $c['tag']) . ' saved' . ($c['committed'] ? ' (uncommitted changes included)' : ''));
    }

    /** GET /aibuilder/checkpoints?id= — list checkpoints with descriptions. JSON. */
    public function checkpoints($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }

        $out = $this->gitInstance($inst, ['for-each-ref', '--sort=-creatordate',
            '--format=%(refname:short)|%(creatordate:short)|%(objectname:short)|%(contents:subject)',
            'refs/tags/checkpoint-*']);
        if (!$out['ok']) { Flight::jsonError("could not list checkpoints for {$inst->slug}: " . mb_substr($out['out'], 0, 300), 502); return; }
        // Which checkpoints carry a copy of the data (TenantHost::checkpoint) — a rollback to one
        // that does not restores the code only, and the page says so before it is done.
        $withData = [];
        if ($this->inContainer($inst)) {
            [$bc, $bo] = \app\TenantHost::ssh($inst, 'app', 'ls -1 /srv/app/.aibuilder/backups 2>/dev/null; true', null, 20);
            if ($bc !== 0) { Flight::jsonError("could not read {$inst->slug}'s checkpoint data copies: " . mb_substr((string) $bo, 0, 300), 502); return; }
            $withData = array_flip(array_filter(array_map('trim', explode("\n", (string) $bo))));
        }
        $items = [];
        foreach (explode("\n", $out['out']) as $line) {
            if ($line === '') continue;
            $p = explode('|', $line, 4);
            $items[] = [
                'name'        => $p[0] ?? '',
                'date'        => $p[1] ?? '',
                'commit'      => $p[2] ?? '',
                'description' => $p[3] ?? '',  // empty for lightweight (undescribed) tags
                'data'        => isset($withData[$p[0] ?? '']),
            ];
        }
        Flight::jsonSuccess(['checkpoints' => $items]);
    }

    /**
     * POST /aibuilder/rollback/<checkpoint>?id= — roll the app back to a checkpoint
     * (TenantHost::rollback: its state now is checkpointed first, the code restored and committed
     * forward, the data restored when the checkpoint has a copy) — by the member doing it. JSON.
     */
    public function rollback($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }
        if (!$this->inContainer($inst)) { Flight::jsonError("{$inst->slug} is not running in its own container, so it cannot be rolled back here.", 409); return; }
        $tag = (string) ($params['operation']->name ?? $this->getParam('checkpoint', ''));
        try {
            $r = \app\TenantHost::rollback($inst, $tag, \app\TenantHost::author((int) $this->member->id));
        } catch (\RuntimeException $e) {
            $r = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (!$r['ok']) {
            $this->logger->error('aibuilder rollback failed', ['instance' => $inst->slug, 'checkpoint' => $tag, 'error' => $r['error']]);
            Flight::jsonError('Rollback failed: ' . $r['error'], 502);
            return;
        }
        $this->logger->info('aibuilder rollback', ['instance' => $inst->slug, 'checkpoint' => $tag, 'before' => $r['before'], 'member' => (int) $this->member->id]);
        $short = fn(string $t) => preg_replace('/^checkpoint-/', '', $t);
        Flight::jsonSuccess($r, 'Rolled back to ' . $short($tag) . ($r['data'] ? ' — code and data (' . $r['databases'] . ')' : ' — code only: this checkpoint has no copy of the data, which was left as it is')
            . '. The state before is saved as ' . $short($r['before']) . '.');
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
        // Taken in the container by the member saving the plan; if it cannot be, the plan is still
        // saved and PlanExecutor takes its rollback point before the first task (or refuses to run).
        $tag = '';
        try {
            $c = \app\TenantHost::checkpoint($inst, 'checkpoint-plan-' . date('Ymd-His'), 'plan: ' . mb_substr((string) $plan['title'], 0, 80),
                \app\TenantHost::author((int) $this->member->id));
        } catch (\RuntimeException $e) {
            $c = ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($c['ok']) $tag = $c['tag'];
        else $this->logger->error('aibuilder plan baseline checkpoint failed', ['instance' => $inst->slug, 'error' => $c['error']]);

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
        if (!$pi) { $this->noSuchPlan((int) $this->getParam('plan', 0)); return; }
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
        if (!$pi) { $this->noSuchPlan((int) $this->getParam('plan', 0)); return; }
        [$plan, $inst] = $pi;

        if (!in_array($plan->planStatus, ['approved', 'stalled'], true)) {
            Flight::jsonError('Approve the plan before running it (or it is already building).', 409);
            return;
        }
        if (\app\PlanOrchestrator::running((int)$plan->id, (string)$inst->slug)) {
            Flight::jsonError('This plan is already running.', 409); return;
        }

        $dir = $this->instanceDir($inst->slug);
        // No worker model is passed any more. This resolved CLAUDE's worker tier and gave
        // it to every task in the plan, on the premise that the executor ran the claude CLI
        // regardless of engine. It no longer does: PlanExecutor dispatches each task on its
        // own engine, so a plan on another provider was launched asking for sonnet.
        // The launch block lives in core (app\PlanOrchestrator): it resolves the
        // orchestrator script, exports the per-instance workbench.db so plan state is
        // written where this plan actually lives, and refuses to report success for a
        // command it cannot run.
        if (!\app\PlanOrchestrator::launch(
            (int)$plan->id, (string)$inst->slug, $dir, (int)$this->member->level
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
        if (!$pi) { $this->noSuchPlan((int) $this->getParam('plan', 0)); return; }
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
     * POST /aibuilder/restart — end the agent's tmux session in the app's container, so the
     * next connect starts a fresh one. JSON.
     */
    public function restart($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        if (!$this->validateCSRF()) return;
        $inst = $this->accessibleInstance($this->getParam('id', 0));
        if (!$inst) { Flight::jsonError('No such instance', 404); return; }
        if (!$this->inContainer($inst)) { Flight::jsonError("{$inst->slug} isn't running in its own container, so it has no builder terminal.", 409); return; }
        // The member's own session on that agent lives in the app's container; ending it lets the
        // next connect start a fresh one. Nobody else's session is touched.
        $agent = (string) $this->getParam('agent', '');
        if ($agent !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,40}$/', $agent)) { Flight::jsonError("'{$agent}' is not an agent name", 400); return; }
        $session = \app\AppToken::terminalSession((int) $this->member->id, $agent);
        [$c, $o] = \app\TenantHost::ssh($inst, 'app', 'tmux kill-session -t ' . escapeshellarg('=' . $session) . ' 2>&1 || true', null, 20);
        if ($c !== 0) { Flight::jsonError("could not reach {$inst->slug}'s container: " . trim((string) $o), 502); return; }
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

    /** POST /aibuilder/upload — store file(s) into the secure|public bucket. JSON. */
    public function upload($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        Flight::jsonError('Uploading files for the agent is not available for a project in its own container yet.', 409);
    }

    /** GET /aibuilder/uploads?id= — list uploaded files by bucket. JSON. */
    public function uploads($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        Flight::jsonError('Uploading files for the agent is not available for a project in its own container yet.', 409);
    }

    /** POST /aibuilder/deleteupload — remove an uploaded file. JSON. */
    public function deleteupload($params = []): void {
        if (!$this->requireLevel($this->minLevel())) return;
        Flight::jsonError('Uploading files for the agent is not available for a project in its own container yet.', 409);
    }
}
