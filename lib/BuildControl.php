<?php
/**
 * BuildControl — the shared foundation for the workbench sidecar's build controllers
 * (the task board `Workbench` and the AI Builder `Aibuilder`). Both are facets of ONE
 * build sidecar, so the plumbing is here once:
 *
 *   - identity from the SSO session (NOT core's web session)
 *   - instance access via WorkbenchAccess (owner/team scoping from core, read-only)
 *   - per-request instance resolution → select its workbench.db + export
 *     TIKNIX_WORKBENCH_DB so spawned children (PlanRunner/plan-ingest/task-complete)
 *     write task state to the SAME per-instance workbench.db
 *   - a lean sidecar render (no core nav chrome)
 *
 * Subclasses override resolveSelected() to pick the instance their routes address
 * (Workbench: by task id; Aibuilder: by instance id / plan).
 */
namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

abstract class BuildControl extends Control {

    /** @var WorkbenchAccess instance-scoped access (owner/team scoping from core) */
    protected $access;
    /** @var array|null the instance whose workbench.db is selected for this request */
    protected $selected = null;
    /** @var bool did SSO establish a session? */
    protected $authed = false;

    public function __construct() {
        // NOT parent::__construct(): that pulls the member from CORE's web session and
        // loads core's nav. Here identity is the SSO session and data is per-instance.
        $this->logger = Flight::get('log');

        $s    = \app\Sidecar\Sso::session();
        $core = \app\Sidecar\Kernel::coreDb();
        if ($s && $core) {
            $this->authed = true;
            $mid = (int) $s['member_id'];
            $this->member = $this->loadMember($core, $mid, $s);
            $this->access = new WorkbenchAccess($mid, $core);
            $this->selected = $this->resolveSelected();
            if ($this->selected) $this->selectInstance($this->selected);
        } else {
            $this->member = (object) ['id' => 0, 'level' => LEVELS['PUBLIC'], 'email' => '',
                'displayName' => '', 'username' => '', 'avatarUrl' => '', 'locale' => 'en'];
        }

        $this->viewData = [
            'member'     => $this->member,
            'isLoggedIn' => $this->authed,
            'menu'       => [],
            'title'      => 'AI Projects',
            'csrf'       => SimpleCsrf::getTokenArray(),
            'selected'   => $this->selected,
        ];
    }

    /** Point RedBean + child processes at this instance's per-instance workbench.db. */
    protected function selectInstance(array $inst): void {
        $this->selected = $inst;
        $this->access->setCurrent($inst);                                   // this process
        putenv('TIKNIX_WORKBENCH_DB=' . WorkbenchDb::path($inst));          // spawned children (bootstrap hook)
    }

    /** Member value object from core's member table (tolerant of fluid columns). */
    protected function loadMember(\PDO $core, int $mid, array $s): object {
        $row = [];
        try {
            $st = $core->prepare('SELECT * FROM member WHERE id = ? LIMIT 1');
            $st->execute([$mid]);
            $row = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {}
        return (object) [
            'id'          => $mid,
            'level'       => (int) ($row['level'] ?? $s['level'] ?? LEVELS['MEMBER']),
            'email'       => (string) ($row['email'] ?? $s['email'] ?? ''),
            'displayName' => (string) ($row['display_name'] ?? $row['username'] ?? ''),
            'username'    => (string) ($row['username'] ?? ''),
            'avatarUrl'   => (string) ($row['avatar_url'] ?? ''),
            'locale'      => (string) ($row['locale'] ?? 'en'),
        ];
    }

    /** Shared hint resolution: ?inst=<slug> / ?instance_tag=<slug.app> / ?instance_id → instance|null. */
    protected function resolveByInstanceHint(array $insts): ?array {
        $hint = (string) ($this->getParam('inst') ?? '');
        if ($hint === '') {
            $tag = (string) ($this->getParam('instance_tag') ?? '');
            if ($tag !== '') $hint = explode('.', $tag)[0];   // "slug.app" → "slug"
        }
        if ($hint !== '') {
            foreach ($insts as $i) if ($i['slug'] === $hint) return $i;
        }
        $iid = (int) ($this->getParam('instance_id') ?? 0);
        if ($iid > 0) {
            foreach ($insts as $i) if ((int) $i['id'] === $iid) return $i;
        }
        return null;
    }

    /** Pick the instance this request targets. Default = hint or first accessible. */
    protected function resolveSelected(): ?array {
        $insts = $this->access->accessibleInstances();
        if (!$insts) return null;
        return $this->resolveByInstanceHint($insts) ?? $insts[0];
    }

    /** SSO-session login gate (replaces core's session/redirect-to-/auth/login). */
    protected function requireLogin() {
        if ($this->authed) return true;
        if (Flight::request()->ajax) { Flight::jsonError('Login required', 401); }
        else { Flight::redirect('/sso/logout'); }   // Kit clears + bounces to launch
        return false;
    }

    /** Render inside the sidecar's own lean layout (no core header/footer/nav chrome). */
    protected function render($template, $data = [], $layout = true) {
        $data = array_merge($this->viewData, $data);
        if ($layout) {
            Flight::render($template, $data, 'ws_body');
            Flight::render('layouts/sidecar', $data);
        } else {
            Flight::render($template, $data);
        }
    }
}
