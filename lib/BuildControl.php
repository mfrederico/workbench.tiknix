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
            'title'      => 'Task Board',
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

    /**
     * The instance this request works on.
     *
     * PROJECT AFFINITY: core owns the choice. This sidecar must never pick one on the
     * member's behalf — the old fallback to $insts[0] is exactly what made the surface
     * flip: open AI Builder after choosing a project elsewhere and you silently landed
     * on whichever instance happened to sort first, editing something you had not asked
     * for.
     *
     * Order: an explicit URL hint (deep links must keep working), then the project core
     * says you are working on. Never a guess. If the chosen project is not accessible
     * here we return null rather than falling through to another instance, because
     * quietly substituting a different project is the failure this exists to prevent.
     */
    protected function resolveSelected(): ?array {
        $insts = $this->access->accessibleInstances();
        if (!$insts) return null;

        if ($hit = $this->resolveByInstanceHint($insts)) return $hit;

        $project = \app\Sidecar\Sso::project();
        if (!$project) return null;                       // nothing chosen → core's picker
        foreach ($insts as $i) if ((int) $i['id'] === $project['id']) return $i;
        return null;                                      // chosen project not available here
    }

    /**
     * Send a member with no usable project back to core to choose one, rather than
     * offering a second picker here. Controllers call this before rendering anything
     * that needs an instance.
     */
    protected function requireProject(): bool {
        if ($this->selected) return true;
        Flight::redirect(\app\Sidecar\Sso::projectPickerUrl());
        return false;
    }

    /**
     * The accessible instance matching the project chosen in CORE, or null.
     *
     * Every resolveSelected() override ends here instead of defaulting to the first
     * accessible instance. That default was the bug: with no explicit id in the URL the
     * sidecar silently picked for you, so arriving from another surface could land you
     * in a different project than the one you had selected. If the chosen project is not
     * accessible here, return null rather than substituting another — quietly swapping
     * projects is exactly what this prevents.
     */
    protected function projectInstance(array $insts): ?array {
        $project = \app\Sidecar\Sso::project();
        if (!$project) return null;
        foreach ($insts as $i) if ((int) $i['id'] === $project['id']) return $i;
        return null;
    }

    /** Level check against the SSO'd member (NOT Flight::hasLevel, which reads core's session). */
    protected function hasLevel($level): bool {
        return $this->authed && (int) $this->member->level <= (int) $level;
    }

    /** Gate an action to a level; 403/redirect otherwise. Mirrors core Control::requireLevel. */
    protected function requireLevel($level) {
        if (!$this->requireLogin()) return false;
        if (!$this->hasLevel($level)) {
            if (Flight::request()->ajax) { Flight::jsonError('Access denied', 403); }
            else { Flight::redirect('/'); }
            return false;
        }
        return true;
    }

    /** SSO-session login gate (replaces core's session/redirect-to-/auth/login). */
    protected function requireLogin() {
        if ($this->authed) return true;
        if (Flight::request()->ajax) { Flight::jsonError('Login required', 401); return false; }
        // Recover an expired session by bouncing to CORE's launch, which re-mints the SSO
        // handoff and drops the member back here — same as the pipelines sidecar. (The old
        // /sso/logout just clears + lands on '/', dead-ending anyone whose session lapsed.)
        $coreUrl = rtrim((string) (Flight::get('sidecar.core_url') ?? ''), '/');
        $name    = \app\Sidecar\Kernel::name();
        if ($coreUrl !== '' && $name !== '') { Flight::redirect($coreUrl . '/sidecar/launch/' . $name); }
        else { Flight::redirect('/sso/logout'); }
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
