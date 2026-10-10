<?php
/**
 * Workbench Controller
 *
 * Manages workbench tasks - a micro-Jira for AI-assisted development.
 * Tasks can be personal or team-based with access controls.
 */

namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\TaskAccessControl;
use \app\SimpleCsrf;
use \app\PromptBuilder;
use \app\PortManager;
use \app\TmuxManager;
use \app\PlanRunner;
use \app\PlanExecutor;
use \app\PlanOrchestrator;
use \app\EngineRegistry;
use \app\MemberEnginePrefs;
use \Exception as Exception;
use app\BaseControls\Control;

class Workbench extends BuildControl {

    /**
     * Workbench routes address instances BY TASK: extend the shared hint resolver with
     * self-location — a ?id/task_id → the accessible instance whose workbench.db holds it
     * (so every existing task link works WITHOUT threading ?inst everywhere). Falls back to
     * ?instance_id (store/create) via the base hint, then the first accessible (board).
     */
    // Instance selection is inherited from BuildControl: the selected project, only.
    // ?inst / ?instance_id / ?task_id are gone — the board shows the project you are on,
    // so a task link that implies a different one would contradict the shell's chip.

    /**
     * Base URL for the test-server proxy domain. The capricorn proxy router that serves the
     * `.proxy.<hash>.<domain>` files lives on the CONTROL PLANE — so a test server must be
     * reachable at <hash>.tiknix.com, NOT the sidecar host or localhost. In the sidecar
     * Flight has no 'baseurl' (only 'app.baseurl'=workbench.tiknix.com), so fall back to the
     * core url; that null-baseurl→localhost gap is why an in-sidecar test server was unreachable.
     */
    /**
     * The hostname label a test server is published under.
     *
     * ONE definition, because this string is used twice — as the subdomain in the
     * URL, and as the suffix of the /var/www/html/.proxy.<label>.<domain> file
     * nginx reads. Those two must agree exactly or the link 404s, and they were
     * previously written out by hand in both places.
     *
     * The `preview-` prefix says what the host IS. A bare 12-hex label shares a
     * namespace with real instances (<slug>.tiknix.com), so nothing distinguished a
     * throwaway preview from a customer's site, and nothing stopped a hash
     * colliding with a slug.
     *
     * EXISTING previews are unaffected: stop and cleanup use $task->proxyFile, the
     * path recorded when the file was written, so anything already running is still
     * removed correctly. A restart republishes it under the new label.
     */
    public static function previewLabel(string $proxyHash, string $instanceTag = ''): string {
        // The project the preview belongs to, so the host says whose it is:
        //   preview-floorplan-dd2e9b-cfa3ac1deeca.tiknix.com
        // instance_tag arrives as "<slug>.tiknix"; the app suffix is dropped because
        // the domain already supplies it.
        $slug = preg_replace('/\.[a-z0-9]+$/i', '', trim($instanceTag));

        // DNS labels allow letters, digits and hyphens only, and cannot start or end
        // with one. A slug that fails this would produce a host that simply does not
        // resolve — silently, which is the failure mode this whole area keeps having.
        $slug = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $slug));
        $slug = trim($slug, '-');

        $label = $slug === '' ? 'preview-' . $proxyHash : 'preview-' . $slug . '-' . $proxyHash;

        // 63 octets is the hard limit for one DNS label. Only the slug is trimmed —
        // the hash is what makes the name unique and the prefix is what makes it
        // recognisable, so neither may be sacrificed.
        if (strlen($label) > 63) {
            $keep  = 63 - strlen('preview-') - 1 - strlen($proxyHash);
            $slug  = rtrim(substr($slug, 0, max(0, $keep)), '-');
            $label = $slug === '' ? 'preview-' . $proxyHash : 'preview-' . $slug . '-' . $proxyHash;
        }
        return $label;
    }

    /**
     * The control plane's host (tiknix.com), for a workspace's [app] control_plane_host —
     * without it the project's code in a task workspace believes it IS the control plane.
     */
    private function controlPlaneHost(): string {
        $host = (string) parse_url((string) Flight::get('sidecar.core_url'), PHP_URL_HOST);
        if ($host === '') {
            throw new \RuntimeException('Workspaces cannot be told the control plane: set [sidecar] core_url in conf/config.ini.');
        }
        return $host;
    }

    protected function serverBaseurl(): string {
        // NO localhost fallback. It used to end `?: 'https://localhost'`, and that
        // single default is the whole bug: a preview genuinely live at
        // <hash>.tiknix.com was advertised as <hash>.localhost, which reads as a
        // broken feature rather than a missing setting. A wrong answer that looks
        // right costs more than no answer.
        //
        // An empty return means "this install has not been told its public domain",
        // and every caller says so plainly instead of printing a link that cannot work.
        $url = (string) (Flight::get('baseurl') ?: Flight::get('sidecar.core_url') ?: '');
        if ($url === '') {
            Flight::get('log')?->error('serverBaseurl: no baseurl and no sidecar.core_url — '
                . 'test-server preview URLs cannot be built. Set [sidecar] core_url in conf/config.ini.');
        }
        return rtrim($url, '/');
    }

    /**
     * Task dashboard
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        $this->viewData['title'] = 'Task Board';

        // Freshly-ingested tasks are written by the headless plan-ingest.php CLI, whose
        // APCu segment this process cannot see, so it can never invalidate what we cached.
        // workbenchtask lives in the instance's workbench.db, which WorkbenchDb therefore
        // opens uncached — so today this is already fresh and the call below does nothing.
        //
        // It stays, addressed to the RIGHT connection. It used to ask
        // Flight::get('cachedDatabaseAdapter'), which is always the DEFAULT connection:
        // that stamped a version for 'workbenchtask' in CORE's namespace, for a table in
        // a different database. It invalidated nothing while reading exactly like a
        // guard that worked. If workbench.db is ever cached (Redis), this starts working
        // instead of quietly continuing not to.
        $this->bustTaskCache();

        // Get filter parameters
        // Finished work is demoted: no status chosen = "active" (everything not finished);
        // ?status=all is the whole board, ?status=finished just the done ones.
        $statusParam = (string) ($this->getParam('status') ?? '');
        $filters = [
            'status' => $statusParam === '' ? 'active' : ($statusParam === 'all' ? '' : $statusParam),
            'task_type' => $this->getParam('type'),
            'team_id' => $this->getParam('team_id'),
            'priority' => $this->getParam('priority'),
            'instance_tag' => $this->getParam('instance_tag'),
            // EMPTY, not a default. Passing 'updated_at DESC' here overrode the board's
            // own ordering (in-flight work first), so a running task stayed buried among its
            // finished siblings no matter what that default said. An explicit ?order_by=
            // still wins; absent one, the access layer decides.
            'order_by' => $this->getParam('order_by', '')
        ];

        // Get visible tasks
        $tasks = $this->access->getVisibleTasks($this->member->id, $filters);

        // Get task counts
        $counts = $this->access->getTaskCounts($this->member->id);

        // What is live right now — awaiting its person, or held by an agent — for the card
        // at the top, whichever tab is showing. Awaiting first: that is the one waiting on YOU.
        $live = $this->access->getVisibleTasks($this->member->id, ['status' => 'live']);
        usort($live, fn($a, $b) => [(string) $a->status !== 'awaiting', -(int) $a->id] <=> [(string) $b->status !== 'awaiting', -(int) $b->id]);
        $this->viewData['liveTasks'] = $live;
        $this->viewData['statusTab'] = $statusParam === '' ? 'active' : $statusParam;

        // A project made from a Get-started plan says, on its board, where that stands — setting
        // up, PLAN.md in, waiting for the app's agent, being planned — instead of an empty board
        // that looks like nothing is happening (core lib/PlanHandoff.php; read from core's db).
        $this->viewData['handoff'] = $this->handoffState();

        // Get user's teams for filter dropdown
        $teams = $this->access->getMemberTeams($this->member->id);

        // Get task counts per team for tab badges
        $teamCounts = $this->access->getTeamTaskCounts($this->member->id);

        // Grouping for the list: subtasks nest under their plan parent. Collect the
        // parent ids referenced by visible subtasks, then load those parents' header
        // metadata directly — a status filter can hide the parent (e.g. a "completed"
        // plan whose children are "merged"), but we still want its group header.
        $childParentIds = [];
        foreach ($tasks as $t) {
            if (!empty($t->parentTaskId)) { $childParentIds[(int)$t->parentTaskId] = true; }
        }
        $planMeta = [];
        if ($childParentIds) {
            $ids = array_keys($childParentIds);
            $ph  = implode(',', array_fill(0, count($ids), '?'));
            foreach (Bean::find('workbenchtask', "id IN ($ph)", $ids) as $p) {
                $planMeta[(int)$p->id] = [
                    'id'          => (int)$p->id,
                    'title'       => $p->title,
                    'instanceTag' => $p->instanceTag,
                    'status'      => $p->status,
                    'planStatus'  => $p->planStatus,
                    // A plan that approved itself and started building without anyone
                    // clicking Build should say so on the board — otherwise the first
                    // sign of it is code already landing in the project.
                    'autoBuild'   => !empty($p->autoBuild),
                ];
            }
        }

        // Phase PROGRESS: how many of each plan's subtasks are built (merged/completed) vs
        // total — one grouped query, so the board can show "Phase N — X/Y built" without
        // loading every child. array_keys() is 0-indexed, so it is safe in the IN() binding.
        if ($planMeta) {
            $pids = array_keys($planMeta);
            $ph2  = implode(',', array_fill(0, count($pids), '?'));
            foreach (Bean::getAll(
                "SELECT parent_task_id pid, COUNT(*) total,
                        SUM(CASE WHEN status IN ('merged','completed') THEN 1 ELSE 0 END) built
                 FROM workbenchtask WHERE parent_task_id IN ($ph2) GROUP BY parent_task_id", $pids) as $row) {
                $pid = (int) $row['pid'];
                if (isset($planMeta[$pid])) {
                    $planMeta[$pid]['total'] = (int) $row['total'];
                    $planMeta[$pid]['built'] = (int) $row['built'];
                }
            }
        }

        // The saved goal (business plan / spec) this project's phases descend from — the
        // provenance root, and what the "Continue to next phase" button re-decomposes.
        $goalDoc = ''; $goalComplete = '';
        if ($this->selected) {
            $ab = \app\WorkbenchDb::dirOf((string) $this->selected['slug'], (string) ($this->selected['app'] ?? '')) . '/.aibuilder';
            if (is_file($ab . '/plan-goal.md'))     $goalDoc      = (string) file_get_contents($ab . '/plan-goal.md');
            // The planner writes this when it judges the goal already built (see PlanRunner's
            // brief). Its presence = "no next phase"; cleared on the next decompose.
            if (is_file($ab . '/plan-complete.md')) $goalComplete = (string) file_get_contents($ab . '/plan-complete.md');
        }
        // The phases of this project, whatever the status filter above is showing.
        $phases = $this->phaseList();
        $this->viewData['phases']       = $phases;
        $this->viewData['nextPhase']    = \app\PlanPhases::next($phases);
        $this->viewData['planGoal']     = $goalDoc;
        $this->viewData['hasSavedGoal']  = $goalDoc !== '';
        $this->viewData['goalComplete']  = $goalComplete;

        $this->viewData['tasks'] = $tasks;
        $this->viewData['counts'] = $counts;
        $this->viewData['teams'] = $teams;
        $this->viewData['teamCounts'] = $teamCounts;
        $this->viewData['filters'] = $filters;
        $this->viewData['taskTypes'] = $this->getTaskTypes();
        $this->viewData['priorities'] = $this->getPriorities();
        $this->viewData['instanceTags'] = $this->access->getInstanceTags($this->member->id);
        // Provisioning a new instance is ADMIN-only (mirrors Aibuilder::create); the
        // left-nav shows the inline create form only to those who can use it.
        $this->viewData['canCreate'] = (int)$this->member->level <= LEVELS['ADMIN'];
        $this->viewData['engines']   = \app\EngineRegistry::menu();
        $this->viewData['planMeta'] = $planMeta;
        $this->viewData['parentIdsWithChildren'] = array_keys($childParentIds);
        // Persistent "decomposing…" indicator, for THE SELECTED PROJECT only.
        //
        // It used to scan every accessible instance for a live planner session, which
        // meant the board reported on work in projects you were not on — the same
        // "other projects are in play here" implication a second picker makes. The board
        // shows the project you are on; if you want to watch another one decompose, that
        // is what selecting it is for.
        //
        // Armed by ?decomposing=1 as well as by a live session, because the redirect
        // straight after kicking a planner off can beat tmux to the punch.
        $decomposing = false;
        if ($this->selected) {
            $session = 'tiknix-' . (int)$this->member->id . '-plan-' . $this->selected['slug'];
            $decomposing = \app\TmuxManager::exists($session);
        }
        $this->viewData['decomposing'] = $decomposing || $this->getParam('decomposing', '') !== '';
        $this->viewData['decomposingTag'] = $this->selected
            ? $this->selected['slug'] . '.' . ($this->selected['app'] ?: 'tiknix') : '';
        // WHAT is being planned — so "Stop" is a decision about a named thing, not about "your goal".
        $this->viewData['decomposingGoal'] = $this->viewData['decomposing'] ? $this->decomposingGoal() : null;

        // A project whose container is still being set up (a tenant row not yet published at a
        // domain — it may already have a container and an address, with app.sh still running): the
        // board says so and refreshes itself until the container answers — nothing on it could run.
        // Model_Instance::setupReport reads the workspace's provision.log (+ .pid): state '' (no
        // record), 'pending', 'failed' (an ERROR line, or no progress for SETUP_STALL_SECONDS —
        // with the reason in 'error'), 'active' (container + domain). Core's class, through core's
        // lib. A stalled setup is removed by core's provision sweep within the hour, with a Note
        // to the owner; until then the board shows where it stopped.
        $this->viewData['containerSetup'] = null;
        $this->viewData['projectStatus'] = null;
        if ($this->selected) {
            $meta = $this->access->instanceMeta((int) $this->selected['id']);
            // The app's own last report (core's Projectreport), for the project card.
            if ($meta && !empty($meta->lastReportedAt) && ($h = json_decode((string) ($meta->reportJson ?? ''), true)) && is_array($h)) {
                $age = time() - strtotime((string) $meta->lastReportedAt);
                $this->viewData['projectStatus'] = $h + ['at' => (string) $meta->lastReportedAt, 'age' => $age,
                    'ago' => $age < 90 ? 'just now' : ($age < 5400 ? round($age / 60) . ' min ago' : ($age < 172800 ? round($age / 3600) . ' h ago' : round($age / 86400) . ' d ago')),
                    'core' => rtrim((string) Flight::get('sidecar.core_url'), '/'), 'id' => (int) $meta->id,
                    // its domains and the TLS they are served with (core's DomainCerts, probed hourly)
                    'domains' => !empty($meta->ctDomain) ? \app\DomainCerts::summary($meta, \app\Sidecar\Kernel::coreDb()) : null];
            }
            if ($meta && (string) ($meta->ctKind ?? '') === 'tenant' && trim((string) ($meta->ctDomain ?? '')) === '') {
                $rep = \Model_Instance::setupReport($meta);
                $this->viewData['containerSetup'] = ['state' => $rep['state'], 'last' => $rep['last'], 'error' => $rep['error'],
                    'core' => rtrim((string) Flight::get('sidecar.core_url'), '/')];
            }
        }

        $this->render('workbench/index', $this->viewData);
    }

    /**
     * Create task form
     */
    public function create($params = []) {
        if (!$this->requireLogin()) return;
        // No project selected → back to core's picker, not a second one here.
        if (!$this->requireProject()) return;

        $this->viewData['title'] = 'Create Task';

        /* PAST GOALS, AND A WAY BACK TO THEM.
         *
         * A decompose that fails to launch leaves nothing on the board — no row, no button —
         * because a task only exists once a plan has been produced and ingested. The goal IS
         * recorded (promptlog, and .aibuilder/plan-goal.md), but nothing in the interface
         * showed it, so a failed decompose looked like work that had vanished and the only
         * way back was retyping it.
         *
         * Read through CoreDb: promptlog lives in core, while this sidecar's default
         * connection is the instance's own database. */
        $this->viewData['recentPrompts'] = [];
        $this->viewData['prefill'] = ['title' => '', 'body' => '', 'audience' => '', 'acceptance' => '', 'prompt_id' => 0, 'plan' => true];
        if ($this->selected) {
            $tag = (string) ($this->selected['slug'] ?? '') . '.' . ($this->selected['app'] ?: 'tiknix');
            $rows = (array) \app\CoreDb::with(
                fn() => \app\PromptLog::forMember((int) $this->member->id, '', 8, $tag),
                []
            );
            $this->viewData['recentPrompts'] = $rows;

            // ?prompt=<id> puts a previous goal back in the form. Nothing is re-run behind
            // your back — you still press Decompose, so every gate applies as normal.
            $wantId = (int) $this->getParam('prompt', 0);
            if ($wantId > 0) {
                $one = \app\CoreDb::with(
                    fn() => \app\PromptLog::find($wantId, (int) $this->member->id),
                    null
                );
                if ($one) {
                    // The answers were written into the goal (app\GoalBrief): take them back
                    // out, so they return to their own questions instead of being appended twice.
                    $was = \app\GoalBrief::split((string) ($one['body'] ?? ''));
                    $this->viewData['prefill'] = [
                        'title' => (string) ($one['title'] ?? ''),
                        'body'  => $was['goal'],
                        'audience'   => $was['audience'],
                        'acceptance' => $was['acceptance'],
                        'prompt_id'  => $wantId,
                        // What it was last time: a goal that became a single task comes back as one.
                        'plan'       => (string) ($one['source'] ?? '') !== \app\PromptLog::SOURCE_TASK,
                    ];
                }
            }
        }

        // A task builds on the app's main branch, in the project's container — there is no other base.
        $remoteBranches = ['main'];
        $currentBranch = 'main';

        /* Engine+model pairs, and which one is preselected. The default is the PROJECT's
           engine at its worker tier — the thing that would have run anyway — so the picker
           changes what you can choose without changing what happens if you ignore it. */
        $this->viewData['runChoices'] = \app\EngineRegistry::runMenu();
        $projectEngine = \app\EngineRegistry::defaultEngine();
        if ($this->selected) {
            $dir = \app\WorkbenchDb::dirOf((string) $this->selected['slug'], (string) ($this->selected['app'] ?? ''));
            $f   = rtrim($dir, '/') . '/.aibuilder/engine';
            if (is_file($f)) {
                $fromFile = trim((string) @file_get_contents($f));
                if (\app\EngineRegistry::isValid($fromFile)) $projectEngine = $fromFile;
            }
        }
        $this->viewData['defaultRunChoice'] =
            $projectEngine . ':' . \app\EngineRegistry::model($projectEngine, 'worker');
        $this->viewData['taskTypes'] = $this->getTaskTypes();
        $this->viewData['priorities'] = $this->getPriorities();
        $this->viewData['branches'] = $remoteBranches;
        $this->viewData['currentBranch'] = in_array($currentBranch, $remoteBranches) ? $currentBranch : 'main';

        // The task targets THE SELECTED PROJECT — the one chosen in core's picker and
        // named by the chip in the shell. There is no chooser here: a second place to
        // say which project is a second thing that can disagree with the first, which is
        // the flip/flop this sidecar was untangled to stop. The form shows what it will
        // build against; changing it means going back to Projects.
        $this->viewData['instance'] = [
            'id'  => (int) $this->selected['id'],
            'tag' => $this->selected['slug'] . '.' . ($this->selected['app'] ?: 'tiknix'),
            'name' => (string) ($this->selected['name'] ?? ''),
        ];
        $this->viewData['projectPickerUrl'] = \app\Sidecar\Sso::projectPickerUrl();
        // A project in its own container builds on ITS agents (its AI agents page), not on
        // an engine and the member's credentials: the form offers those instead.
        $this->offerAppAgents((string) $this->selected['slug']);
        // A project nobody has signed in for cannot build. Say so on the form, where the
        // decision to write a spec is being made, rather than after it is submitted.
        $projDir = \app\WorkbenchDb::dirOf((string) $this->selected['slug'], (string) ($this->selected['app'] ?? ''));
        /* PER ENGINE, because the member picks one. Computing a single flag from the
           project's engine told a member signed in to one provider that they were not
           signed in at all, and named the wrong provider while doing it. The picker uses
           this map to mark the choices that cannot run for THIS member. */
        $engineAuth = [];
        foreach (\app\EngineRegistry::runMenu() as $choice) {
            $eng = (string) ($choice['engine'] ?? '');
            if ($eng === '' || isset($engineAuth[$eng])) continue;
            $engineAuth[$eng] = $this->agentSignedIn($projDir, $eng);
        }
        $this->viewData['engineAuth'] = $engineAuth;
        // Kept for the form-level notice, but about the engine the form DEFAULTS to.
        /* $projectEngine, resolved above from .aibuilder/engine — NOT
           $this->selected['engine'], which is not a key this array carries. That read
           silently produced 'claude' for every project, so a project running z.ai was told
           it had no credentials for claude, naming an engine it does not use. My own
           fallback, added hours before I removed the same pattern elsewhere. */
        $this->viewData['agentSignedIn']       = $engineAuth[$projectEngine] ?? false;
        $this->viewData['agentSignedInEngine'] = $projectEngine;
        // The picker changes client-side, so the notice needs the whole map to follow it.
        $this->viewData['engineLabels'] = array_combine(
            array_keys($engineAuth),
            array_map(fn($e) => \app\EngineRegistry::label($e), array_keys($engineAuth))
        );

        $this->render('workbench/create', $this->viewData);
    }

    /**
     * POST /workbench/store — the create form's one button.
     *
     * "Plan it first" ticked (the default) hands the goal to the planner (decompose()); unticked,
     * it is one task for one agent, saved here. Either way the form's two answers — who it is
     * for, how we will know it worked — travel INSIDE the goal (app\GoalBrief).
     */
    public function store($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }
        if ($this->wantsPlan()) { $this->decompose($params); return; }

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/workbench/create');
            return;
        }
        if (!$this->requireAgent()) return;

        // Validate required fields
        $title = trim($this->getParam('title', ''));
        if (empty($title)) {
            $this->flash('error', 'Task title is required');
            Flight::redirect('/workbench/create');
            return;
        }

        // No team is asked for: who may see a task follows who the PROJECT is shared with
        // (WorkbenchAccess), and a task that named a team of its own could disagree with that.
        $teamId = null;

        $brief = $this->brief();
        if ($brief === null) return;

        // The instance comes from the SELECTED PROJECT, never from the request. Taking it
        // from a posted field would leave the create form's chooser alive in everything
        // but appearance — a form could still be aimed at a project you are not on, and
        // the task would land somewhere the shell never said you were.
        $instance = $this->selected ? $this->access->instanceMeta((int) $this->selected['id']) : null;
        if (!$instance || !$instance->id || !$this->access->canAccessInstance((int)$this->member->id, (int)$instance->id)) {
            $this->flash('error', 'Choose a project to work on before creating a task.');
            Flight::redirect(\app\Sidecar\Sso::projectPickerUrl());
            return;
        }

        try {
            $task = Bean::dispense('workbenchtask');
            $task->title = $title;
            // The goal WITH its answers: the description is what the agent is handed.
            $task->description = $brief['text'];
            $task->taskType = $this->getParam('task_type', 'feature');
            $task->priority = (int)$this->getParam('priority', 3);
            /* Engine+model as ONE pick (app\EngineRegistry::parseRunChoice), never two
               fields: engine=zai with model=opus is syntactically fine, means nothing to
               the provider, and fails at run time as an unhelpful API error. An empty
               submission leaves the task where it is rather than clearing it. */
            $pick = \app\EngineRegistry::parseRunChoice($this->getParam('run_with', ''));
            if ($pick) {
                $task->engine = $pick['engine'];
                $task->model  = $pick['model'];
            }
            $task->status = 'pending';
            $task->memberId = $this->member->id;
            $task->teamId = $teamId;
            $task->authcontrolLevel = $brief['level'];
            // A project in its own container builds on ITS agents: the one picked, '' = its default.
            if (\app\TenantBuilder::bySlug((string) $instance->slug)) {
                $task->agent = \app\PlanIngestor::agentName($this->getParam('agent', ''));
            }
            /* Engine + model as ONE choice, validated against what the registry actually
               offers rather than parsed from the form. Both values leave PHP: the engine
               becomes a shell assignment in jail-run.sh and the model a --model flag, so an
               unrecognised pair is dropped here instead of failing inside the jail.
               Unset leaves both null, which is the previous behaviour — jail-run.sh falls
               back to the project's .aibuilder/engine and then the conf default. */
            $pick = \app\EngineRegistry::parseRunChoice($this->getParam('run_with', ''));
            if ($pick) {
                $task->engine = $pick['engine'];
                $task->model  = $pick['model'];
            }
            $task->relatedFiles = json_encode(array_filter(explode("\n", $this->getParam('related_files', ''))));
            $task->tags = json_encode(array_filter(array_map('trim', explode(',', $this->getParam('tags', '')))));
            $task->baseBranch = trim($this->getParam('base_branch', 'main'));
            $task->instanceId = (int)$instance->id;
            // Test-DB source: 'live' (default) copies the instance's real data into the
            // workspace for fidelity; 'fresh' starts from an empty schema (privacy).
            $task->dbSource = ($this->getParam('db_source', 'live') === 'fresh') ? 'fresh' : 'live';
            $task->instanceTag = $instance->slug . '.' . ($instance->app ?: 'tiknix');
            $task->runCount = 0;
            $task->createdAt = date('Y-m-d H:i:s');
            Bean::store($task);

            // Log task creation
            $this->logTaskEvent($task->id, 'info', 'user', 'Task created');

            // A task description is a prompt too — it is what the agent is handed. Kept in
            // the member's prompt log so it is still findable after you have moved on to
            // the next task, which is the point at which it used to disappear from view.
            $body = $brief['text'];
            $this->recordFormUse('task', $brief, (int) $instance->id);
            if ($body !== '') {
                \app\PromptLog::record([
                    'member_id'    => (int) $this->member->id,
                    'source'       => \app\PromptLog::SOURCE_TASK,
                    'title'        => $title,
                    'body'         => $body,
                    'instance_id'  => (int) $instance->id,
                    'instance_tag' => (string) $task->instanceTag,
                    'task_id'      => (int) $task->id,
                ]);
            }

            $this->logger->info('Task created', [
                'task_id' => $task->id,
                'title' => $title,
                'team_id' => $teamId,
                'member_id' => $this->member->id
            ]);

            // Straight-through on a single task means "don't make me open it and press
            // Run". The run itself is the existing /workbench/run path, fired by the task
            // page on arrival — deliberately, so a single task starts through exactly the
            // code the Run button uses, with the same guards, and nothing here has to
            // duplicate workspace creation. (A plan differs: it is started server-side by
            // the ingest step, because a decompose finishes minutes after you close the tab.)
            if ($this->wantsAutoBuild()) {
                $this->logTaskEvent($task->id, 'info', 'user', 'Auto-run requested at creation.');
                $this->flash('success', 'Task created — starting the agent now.');
                Flight::redirect('/workbench/view?id=' . $task->id . '&autorun=1');
                return;
            }

            $this->flash('success', 'Task created successfully');
            Flight::redirect('/workbench/view?id=' . $task->id);

        } catch (Exception $e) {
            $this->logger->error('Failed to create task', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to create task');
            Flight::redirect('/workbench/create');
        }
    }

    /**
     * Did the member tick "approve and run straight through" on the create form?
     *
     * One checkbox serves both submit buttons — Create Task auto-runs the agent, Decompose
     * auto-approves and builds the plan — because from where the member sits it is the same
     * request: don't stop and ask me again. It waives the gate BEFORE work starts; it never
     * waives the one after, so a finished task still waits for a human to approve the merge.
     */
    private function wantsAutoBuild(): bool {
        $v = $this->getParam('auto_build', '');
        return in_array((string)$v, ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * For a project in its own container, put ITS agents (its AI agents page) in front of the
     * form — or the reason they could not be listed, which the form shows in their place.
     */
    private function offerAppAgents(string $slug): void {
        $tenant = \app\TenantBuilder::bySlug($slug);
        if (!$tenant) return;
        $this->viewData['tenantAgentsUrl'] = 'https://' . $tenant->ctDomain . '/agents';
        try { $this->viewData['appAgents'] = \app\TenantBuilder::agents($tenant); }
        catch (\Throwable $e) {
            $this->logger->error('Workbench: could not list the app\'s agents', ['instance' => $tenant->slug, 'err' => $e->getMessage()]);
            $this->viewData['appAgentsError'] = $e->getMessage();
        }
    }

    /**
     * The planning depth chosen on the create form (PlanRunner::DEEPEN_MODES). Absent — a
     * continue-phase, a re-run — is the default, "flagged": deepen only what the planner marks
     * complex. Anything else posted is refused by PlanRunner::deepen, not coerced.
     */
    private function planningDepth(): string {
        $d = trim((string) $this->getParam('planning_depth', ''));
        return $d === '' ? 'flagged' : $d;
    }

    /** Did the member leave "Plan it first" ticked on the create form? */
    private function wantsPlan(): bool {
        return in_array((string) $this->getParam('plan', ''), ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * The create form's goal and its two answers, composed into the text the planner or the
     * agent reads (app\GoalBrief). "Who is it for" has no default: an unanswered form is sent
     * back with the question, having answered the request — callers return on null.
     *
     * @return array{goal:string,audience:string,acceptance:string,level:?int,text:string}|null
     */
    private function brief(): ?array {
        $audience = (string) $this->getParam('audience', '');
        if (!\app\GoalBrief::isAudience($audience)) {
            $this->flash('error', 'One more thing before we start: who is this for? Pick one of the cards under the description.');
            Flight::redirect('/workbench/create');
            return null;
        }
        $goal = trim((string) $this->getParam('description', ''));
        if ($goal === '') $goal = trim((string) $this->getParam('title', ''));
        $acceptance = trim((string) $this->getParam('acceptance_criteria', ''));
        return [
            'goal' => $goal, 'audience' => $audience, 'acceptance' => $acceptance,
            'level' => \app\GoalBrief::level($audience),
            'text' => \app\GoalBrief::compose($goal, $audience, $acceptance),
        ];
    }

    /**
     * Note which parts of the create form this submit used (app\ToolUse, tool 'builder.create')
     * — choices only, never what was typed — so the parts nobody uses can be taken out. The
     * single-task extras are noted only for a single task: a plan does not read them.
     */
    private function recordFormUse(string $path, array $brief, int $instanceId): void {
        $said = fn(string $k) => trim((string) $this->getParam($k, '')) !== '';
        $use = [
            'path'        => $path,                       // plan | task
            'straight'    => $this->wantsAutoBuild(),
            'audience'    => $brief['audience'],
            'acceptance'  => $brief['acceptance'] !== '',
            'md_file'     => $this->getParam('md_file', '') === '1',
            'reused_goal' => (int) $this->getParam('from_prompt', 0) > 0,
            'picked_agent' => $said('agent') || ($said('run_with') && $this->getParam('run_with') !== $this->getParam('run_with_default', '')),
        ];
        if ($path === 'task') {
            $use += [
                'more_opened'   => $this->getParam('more_opened', '') === '1',
                'type'          => preg_match('/^[a-z]{1,20}$/', (string) $this->getParam('task_type', '')) ? (string) $this->getParam('task_type') : 'other',
                'priority'      => (int) $this->getParam('priority', 3),
                'fresh_data'    => $this->getParam('db_source', 'live') === 'fresh',
                'related_files' => $said('related_files'),
                'tags'          => $said('tags'),
            ];
        }
        \app\ToolUse::record('builder.create', (int) $this->member->id, $use, $instanceId);
    }

    /**
     * POST /workbench/continuephase — decompose the NEXT phase of the current project's goal.
     *
     * The planner grounds on what is already built (its brief reuses the codebase), so
     * re-running the SAME saved goal yields the next logical phase rather than repeating —
     * that is how "phase 2" arrived on top of a merged "phase 1". This is the deliberate
     * "continue": the goal is read from disk (.aibuilder/plan-goal.md, the last decompose's
     * goal) instead of re-posting it. Draft by default; "run it straight through" is honored
     * when ticked (auto_build). Mirrors decompose(); the only difference is WHERE the goal
     * comes from.
     */
    public function continuephase($params = []) {
        if (!$this->requireLogin()) return;
        $request = Flight::request();
        if ($request->method !== 'POST') { Flight::redirect('/workbench'); return; }
        if (!Flight::csrf()->validateRequest()) { $this->flash('error', 'Invalid CSRF token'); Flight::redirect('/workbench'); return; }
        if (!$this->requireAgent()) return;

        $instance = $this->selected ? $this->access->instanceMeta((int) $this->selected['id']) : null;
        if (!$instance || !$instance->id || !$this->access->canAccessInstance((int)$this->member->id, (int)$instance->id)) {
            $this->flash('error', 'Choose a project to work on before planning against it.');
            Flight::redirect(\app\Sidecar\Sso::projectPickerUrl());
            return;
        }

        $slug = (string) $instance->slug;
        $app  = $instance->app ?: 'tiknix';
        $instanceDir = \app\WorkbenchDb::dirOf($slug, $app);
        $tenant = \app\TenantBuilder::bySlug($slug);
        // The next phase runs on the agent picked on the card; none picked = the one the last plan
        // ran on; none recorded = the app's builder.
        $agent = trim((string) $this->getParam('agent', ''));
        if ($tenant) {
            if ($agent === '') {
                $last = \app\Bean::findOne('workbenchtask', "(parent_task_id IS NULL OR parent_task_id = 0) AND plan_uid IS NOT NULL AND plan_uid != '' ORDER BY id DESC");
                $agent = (string) ($last->agent ?? '');
            }
            if ($why = $this->tenantAgentProblem($tenant, $agent)) {
                $can = array_diff((array) (json_decode((string) ($tenant->reportJson ?? ''), true)['build_agents'] ?? []), [$agent]);
                $this->flash('error', 'The planner cannot run: ' . $why . '.' . ($can ? ' This project has ' . (count($can) === 1 ? 'an agent that can: ' : 'agents that can: ') . implode(', ', $can) . ' — pick it beside “Plan the next phase”.' : ''));
                Flight::redirect('/workbench'); return;
            }
        } elseif (!is_file($instanceDir . '/public/index.php')) { $this->flash('error', 'That instance is not available on disk.'); Flight::redirect('/workbench'); return; }

        // The SAVED goal — the same document the earlier phase(s) came from.
        $goal = trim((string) @file_get_contents($instanceDir . '/.aibuilder/plan-goal.md'));
        if (mb_strlen($goal) < 20) {
            $this->flash('error', 'No saved goal to continue from — decompose a goal first, then Continue picks up the next phase.');
            Flight::redirect('/workbench');
            return;
        }

        $runEngine = (string) ($instance->engine ?: \app\EngineRegistry::defaultEngine());
        if (!$tenant && !$this->agentSignedIn($instanceDir, $runEngine)) {
            $label = \app\EngineRegistry::label($runEngine);
            $this->flash('error', \app\EngineRegistry::authTokenEnv($runEngine) !== ''
                ? "You have no API key set for {$label}, so the planner cannot run. Add one in Settings, or pick a different engine."
                : "You have not signed in to {$label} yet — open the Terminal with this project selected and run /login, then try again.");
            Flight::redirect('/aibuilder');
            return;
        }

        // Draft by default; "run it straight through" (auto_build) is honored when ticked.
        $autoBuild = $this->wantsAutoBuild();

        $promptId = \app\PromptLog::record([
            'member_id'    => (int) $this->member->id,
            'source'       => \app\PromptLog::SOURCE_DECOMPOSE,
            'title'        => 'Continue: next phase',
            'body'         => $goal,
            'instance_id'  => (int) $instance->id,
            'instance_tag' => $slug . '.' . $app,
            'auto_build'   => $autoBuild,
        ]);

        try {
            $runner = new PlanRunner($slug, $instanceDir, (int) $this->member->id, (int) $this->member->level, $runEngine);
            if ($tenant) $runner->useAgent($agent);
            $runner->deepen($this->planningDepth());
            $this->notePlannerAgent($instanceDir, $tenant ? $agent : '');
            $runner->start($goal, [], $autoBuild, $promptId);
        } catch (\Throwable $e) {
            $this->flash('error', 'Could not start the planner: ' . $e->getMessage());
            Flight::redirect('/workbench');
            return;
        }

        $this->flash('success', $autoBuild
            ? 'Planning the next phase — it will build straight through once ingested.'
            : 'Planning the next phase — it will land as a draft to review.');
        Flight::redirect('/workbench?decomposing=1');
    }

    /**
     * Can the app's agent a plan names build there? A project in its own container plans and
     * builds on its own agents (the picker on the plan form): the named one must exist, be one
     * that can do builder work, and have what it needs; none named = the app's default agent,
     * else its Claude account, which must be set up. Asked of the app itself, before a planner
     * starts. Returns the reason it cannot, or ''.
     */
    private function tenantAgentProblem(object $tenant, string $agent): string {
        try { $d = \app\TenantBuilder::agents($tenant); }
        catch (\Throwable $e) { return 'the app did not answer about its agents: ' . $e->getMessage(); }
        $manage = 'https://' . $tenant->ctDomain . '/agents';
        $pick = null;
        foreach ($d['agents'] as $a) {
            // No agent named = the BUILDER: the default among the agents that can build. Each type
            // has its own default since agents got types, and "the first default" was whichever
            // sorted first — on holistica the default Chat agent, nvidia, which cannot build.
            if ($agent !== '' ? $a['name'] === $agent : (!empty($a['is_default']) && !empty($a['builder']))) { $pick = $a; break; }
        }
        if ($agent !== '' && !$pick) return "the app has no agent named '{$agent}' ({$manage})";
        if ($pick) {
            if (empty($pick['builder'])) return "agent '{$pick['name']}' is not a Build agent — it answers, it does not edit files — so it cannot plan or build; pick a Build agent ({$manage})";
            if (!empty($pick['problems'])) return "agent '{$pick['name']}': " . implode('; ', $pick['problems']) . " ({$manage})";
            if (($pick['endpoint'] ?? '') === '' && ($pick['key_status'] ?? '') !== 'set' && (string) ($d['claude']['in_use'] ?? '') === '') {
                return "agent '{$pick['name']}' runs on the app's Claude account, which is not set up: " . ($d['claude']['problem'] ?? '') . " ({$manage})";
            }
            return '';
        }
        if ((string) ($d['claude']['in_use'] ?? '') === '') return "the app has no default agent and its Claude account is not set up — sign in on its AI agents page ({$manage})";
        return '';
    }

    /**
     * Store new task by DECOMPOSING a goal document into a multi-agent plan.
     *
     * The submitted Description (typically an uploaded Markdown "goal" document)
     * is fed to the AI Builder planner for the chosen instance; it decomposes the
     * goal into a plan tree (parent + subtasks with a dependency DAG) via a headless
     * claude -p pass. We then hand off to the AI Builder for that instance to watch
     * the decomposition -> ingest -> approve -> build. The resulting plan lands in
     * the Workbench, tagged to the instance.
     */
    public function decompose($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') { Flight::redirect('/workbench/create'); return; }
        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/workbench/create');
            return;
        }
        if (!$this->requireAgent()) return;

        // Same rule as store(): the plan is decomposed for the SELECTED project, not for
        // whatever a posted field names. Both submit paths hang off the one form, so
        // fixing only one would leave the chooser alive on the other.
        $instance = $this->selected ? $this->access->instanceMeta((int) $this->selected['id']) : null;
        if (!$instance || !$instance->id || !$this->access->canAccessInstance((int)$this->member->id, (int)$instance->id)) {
            $this->flash('error', 'Choose a project to work on before planning against it.');
            Flight::redirect(\app\Sidecar\Sso::projectPickerUrl());
            return;
        }

        // The goal is the Markdown/description body (an uploaded .md fills this).
        $brief = $this->brief();
        if ($brief === null) return;
        if (mb_strlen($brief['goal']) < 20) {
            $this->flash('error', 'A plan needs a little more to go on — describe what you want in a sentence or two (or drop in a .md file).');
            Flight::redirect('/workbench/create');
            return;
        }
        // What the planner reads: the goal, then who it is for and how we will know it worked.
        $goal = $brief['text'];

        $slug = (string)$instance->slug;
        $app  = $instance->app ?: 'tiknix';
        $instanceDir = \app\WorkbenchDb::dirOf($slug, $app);
        $tenant = \app\TenantBuilder::bySlug($slug);
        $agent  = trim((string) $this->getParam('agent', ''));
        if ($tenant) {
            // In its own container: its agent, checked with the app before anything starts.
            if ($why = $this->tenantAgentProblem($tenant, $agent)) {
                $this->flash('error', 'The planner cannot run: ' . $why . '.');
                Flight::redirect('/workbench/create');
                return;
            }
        } elseif (!is_file($instanceDir . '/public/index.php')) {
            $this->flash('error', 'That instance is not available on disk.');
            Flight::redirect('/workbench/create');
            return;
        }

        /* THE MEMBER'S CHOICE DECIDES, not the project's default.
         *
         * The run_with picker is on this form; a member selects the engine they have
         * credentials for. This used to gate on $instance->engine and ignore the pick
         * entirely, so a project set to one provider refused a member who had chosen the
         * other and was correctly signed in to it — with a message naming Claude while it
         * was actually checking z.ai. The project engine stays the DEFAULT for anyone who
         * expresses no preference; it is not a rule about whose credentials get used. */
        $runPick    = \app\EngineRegistry::parseRunChoice($this->getParam('run_with', ''));
        $runEngine  = $runPick['engine'] ?? (string) ($instance->engine ?: \app\EngineRegistry::defaultEngine());
        $runModel   = $runPick['model']  ?? '';

        // Say it BEFORE spending five minutes failing at it. (A container project was checked
        // above, against the app's own agents; the member's engine credentials do not apply.)
        if (!$tenant && !$this->agentSignedIn($instanceDir, $runEngine)) {
            $label = \app\EngineRegistry::label($runEngine);
            // Name the engine actually checked. "Not signed in to Claude" while testing a
            // different provider sends people to /login for an account that was never the
            // problem, and key-authenticated engines have no /login at all.
            $this->flash('error', \app\EngineRegistry::authTokenEnv($runEngine) !== ''
                ? "You have no API key set for {$label}, so the planner cannot run. Add one in Settings, or pick a different engine."
                : "You have not signed in to {$label} yet, so the planner cannot run. "
                  . 'Open the Terminal tab with this project selected and run /login there, then try again.');
            Flight::redirect('/aibuilder');
            return;
        }

        // Straight-through: skip the Approve + Build clicks and let the plan start itself
        // the moment it is ingested. Opt-in per submission and deliberately not sticky —
        // it lands agent-written code in the instance with nobody having read the plan.
        $autoBuild = $this->wantsAutoBuild();
        $this->recordFormUse('plan', $brief, (int) $instance->id);

        // Keep the ask BEFORE running the planner, not after it succeeds. The goal file is
        // overwritten by the next decompose and the copy on the plan only exists if the
        // planner survived to be ingested — so a planner that dies used to take the thing
        // you wrote with it. This is the record that survives regardless.
        $promptId = \app\PromptLog::record([
            'member_id'    => (int) $this->member->id,
            'source'       => \app\PromptLog::SOURCE_DECOMPOSE,
            'title'        => trim($this->getParam('title', '')) ?: 'Decompose',
            'body'         => $goal,
            'instance_id'  => (int) $instance->id,
            'instance_tag' => $slug . '.' . $app,
            // Remembered so a later re-run reproduces what you asked for, rather than
            // quietly downgrading a straight-through decompose into a draft.
            'auto_build'   => $autoBuild,
        ]);

        try {
            // Same engine the gate just approved — checking one and running another is how
            // a build ends up on a provider the member never chose.
            $runner = new PlanRunner(
                $slug, $instanceDir, (int)$this->member->id,
                (int)$this->member->level, $runEngine
            );
            if ($tenant) $runner->useAgent($agent);
            $runner->deepen($this->planningDepth());
            $this->notePlannerAgent($instanceDir, $tenant ? $agent : '');
            // $promptId travels with it so ingest can link the plan back to this goal.
            $runner->start($goal, [], $autoBuild, $promptId);
        } catch (\Throwable $e) {
            // Busy project + straight-through = queue it. "Don't stop and ask me" is an
            // instruction that outlives the moment the project happened to be occupied,
            // so the retry finishes what was asked rather than inventing anything. A
            // decompose without it produces a draft that waits for approval anyway, so
            // starting one unattended would be inventing an instruction — those get the
            // manual button on the Prompts page instead.
            if ($promptId > 0 && \app\PromptQueue::enqueue($promptId, $autoBuild)) {
                $this->logger->info('Decompose queued for retry', [
                    'prompt' => $promptId, 'instance' => $slug, 'why' => $e->getMessage(),
                ]);
                $this->flash('info', 'That project is busy right now — this goal is queued and will '
                    . 'decompose itself as soon as it frees up. You can also run it from Prompts.');
                Flight::redirect('/workbench/prompts?source=decompose');
                return;
            }
            $this->logger->error('Workbench decompose failed', ['error' => $e->getMessage(), 'instance' => $slug]);
            $this->flash('error', 'Could not start the planner: ' . $e->getMessage());
            Flight::redirect('/workbench/create');
            return;
        }

        $this->logger->info('Workbench decompose started', [
            'instance' => $slug, 'member_id' => $this->member->id, 'auto_build' => $autoBuild,
        ]);
        // Stay in the Workbench: the planner ingests itself when it finishes
        // (scripts/plan-ingest.php), so the plan appears here automatically. The
        // decomposing banner polls and refreshes the list when it lands.
        $this->flash('info', $autoBuild
            ? 'Decomposing your goal for ' . $slug . '.' . $app . ' — it will approve itself and start building as soon as the plan lands.'
            : 'Decomposing your goal for ' . $slug . '.' . $app . ' — the plan will appear here shortly.');
        // Just ?decomposing=1: the board is already the selected project's board, so
        // naming an instance here would be repeating the selection back at it.
        Flight::redirect('/workbench?decomposing=1');
    }

    /**
     * POST /workbench/consolidate — merge 2+ non-approved tasks into one deduplicated
     * draft plan. Runs a headless planner over the selected tasks (+ reuse digest) to
     * remove overlap, then supersedes (deletes) the originals once the merged plan is
     * ingested. Tasks must be the member's own, still 'pending', same instance. JSON.
     */
    public function consolidate($params = []) {
        if (!$this->planActionGuard()) return;   // login + POST + CSRF

        $raw = $this->getParam('task_ids', '');
        $ids = is_array($raw) ? $raw : explode(',', (string)$raw);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (count($ids) < 2) { Flight::jsonError('Select at least two tasks to consolidate.', 400); return; }

        $tasks = [];
        $instanceId = null;
        $parentIds = [];
        foreach ($ids as $id) {
            $t = Bean::load('workbenchtask', $id);
            if (!$t->id || !$this->access->canEdit((int)$this->member->id, $t)) { Flight::jsonError("Task {$id} not found or not yours.", 404); return; }
            if ($t->status !== 'pending') { Flight::jsonError('Only non-approved (pending) tasks can be consolidated.', 409); return; }
            if ($instanceId === null) { $instanceId = (int)$t->instanceId; }
            elseif ((int)$t->instanceId !== $instanceId) { Flight::jsonError('All selected tasks must belong to the same instance.', 409); return; }
            if ($t->parentTaskId) { $parentIds[(int)$t->parentTaskId] = true; }
            $tasks[] = $t;
        }

        $inst = $instanceId ? $this->access->instanceMeta((int)$instanceId) : null;
        if (!$inst || !$inst->id || !$this->access->canAccessInstance((int)$this->member->id, (int)$inst->id)) { Flight::jsonError('No valid instance for these tasks.', 409); return; }

        $slug = (string)$inst->slug;
        $app  = $inst->app ?: 'tiknix';

        // Don't consolidate tasks whose plan is actively building. Asked through
        // PlanOrchestrator so the project is part of the question: the bare name
        // matched plan 26 in EVERY project, so a build on one instance refused a
        // consolidation on another.
        foreach (array_keys($parentIds) as $pid) {
            if (PlanOrchestrator::running((int)$pid, $slug)) {
                Flight::jsonError('A plan involved is currently building — stop it before consolidating.', 409);
                return;
            }
        }

        $instanceDir = \app\WorkbenchDb::dirOf($slug, $app);
        // A project in its own container has no directory here: the consolidation planner runs on
        // its app's agent, checked with the app first — the same as decompose.
        $tenant = \app\TenantBuilder::bySlug($slug);
        if ($tenant) {
            if ($why = $this->tenantAgentProblem($tenant, '')) { Flight::jsonError('The planner cannot run: ' . $why . '.', 409); return; }
        } elseif (!is_file($instanceDir . '/public/index.php')) { Flight::jsonError('That instance is not available on disk.', 409); return; }

        try {
            $runner = new PlanRunner(
                $slug, $instanceDir, (int)$this->member->id,
                (int)$this->member->level, (string)($inst->engine ?? '')
            );
            if ($tenant) $runner->useAgent('');
            $runner->start($this->buildConsolidationGoal($tasks), $ids);
        } catch (\Throwable $e) {
            $this->logger->error('Consolidate failed to start', ['error' => $e->getMessage(), 'instance' => $slug]);
            Flight::jsonError('Could not start consolidation: ' . $e->getMessage(), 500);
            return;
        }

        $this->logger->info('Consolidation started', ['instance' => $slug, 'tasks' => $ids, 'member_id' => $this->member->id]);
        Flight::jsonSuccess(
            ['instance_tag' => $slug . '.' . $app, 'instance_id' => (int)$inst->id],
            'Consolidating ' . count($tasks) . ' tasks — a single merged draft plan will appear shortly; the originals are replaced once it lands.'
        );
    }

    /** Build the goal document fed to the consolidation planner from the selected tasks. */
    private function buildConsolidationGoal(array $tasks): string {
        $out  = "# Consolidate overlapping tasks into ONE minimal plan\n\n";
        $out .= "The tasks below were planned independently and OVERLAP — they build duplicate "
              . "models, seeds, endpoints, and UI. Merge them into a SINGLE, minimal, "
              . "non-overlapping plan that delivers everything they collectively intend, with NO "
              . "duplicated work. When two tasks describe the same model / seed / route / view, "
              . "emit exactly ONE task for it. Preserve every distinct capability; drop only the "
              . "redundancy. Keep tasks that must run in sequence chained via depends_on.\n\n";
        $out .= "## Tasks to merge\n\n";
        $i = 0;
        foreach ($tasks as $t) {
            $i++;
            $files  = json_decode((string)$t->relatedFiles, true);
            $reuses = json_decode((string)$t->reuses, true);
            $out .= "### {$i}. " . trim((string)$t->title) . "\n\n";
            $out .= trim((string)$t->description) . "\n\n";
            if (is_array($files) && $files)   { $out .= "Files: "  . implode(', ', $files)  . "\n"; }
            if (is_array($reuses) && $reuses) { $out .= "Reuses: " . implode(', ', $reuses) . "\n"; }
            $out .= "\n";
        }
        return $out;
    }

    /** Load a plan (parent task) the member OWNS; returns [plan, instance|null] or null.
     *  Owner-only gate (canDelete policy) — use for destructive actions like delete. */
    private function ownedPlan($planId): ?array {
        $planId = (int)$planId;
        if (!$planId) return null;
        $plan = Bean::load('workbenchtask', $planId);
        if (!$plan->id || !empty($plan->parentTaskId)) return null;      // must be a plan parent
        if (!$this->access->canDelete((int)$this->member->id, $plan)) return null;
        $inst = $plan->instanceId ? $this->access->instanceMeta((int)$plan->instanceId) : null;
        return [$plan, ($inst && $inst->id) ? $inst : null];
    }

    /** Load a plan (parent task) the member can ACT ON — owns it, or it lives in an
     *  instance shared with their team (canRun policy). Returns [plan, inst|null] or
     *  null. Use for approve/build/retry/progress; keep ownedPlan() for delete. */
    private function accessiblePlan($planId): ?array {
        $planId = (int)$planId;
        if (!$planId) return null;
        $plan = Bean::load('workbenchtask', $planId);
        if (!$plan->id || !empty($plan->parentTaskId)) return null;      // must be a plan parent
        if (!$this->access->canRun((int)$this->member->id, $plan)) return null;
        $inst = $plan->instanceId ? $this->access->instanceMeta((int)$plan->instanceId) : null;
        return [$plan, ($inst && $inst->id) ? $inst : null];
    }

    private function planActionGuard(): bool {
        if (!$this->requireLogin()) return false;
        if (Flight::request()->method !== 'POST') { Flight::jsonError('POST required', 405); return false; }
        if (!Flight::csrf()->validateRequest()) { Flight::jsonError('Invalid CSRF token', 403); return false; }
        return true;
    }

    /**
     * Every plan of the selected project, oldest first, as the board's "Goal → phases" card shows
     * them: {id, title, plan_status, total, built, replan_of, superseded, phase}.
     *
     * A plan is a PHASE unless it is an automatic re-plan (replan_of — PlanRemediator writes one
     * when a build stalls, re-planning what was left). A re-plan stands in for the rest of its
     * original, so it is that phase's way forward while the original is stuck — and SUPERSEDED the
     * moment the original finishes without it: its tasks are then work already done. On holistica
     * two such re-plans sat as "Phase 2" and "Phase 3" after phase 1 completed; building either
     * would have built phase 1's second half again.
     */
    private function phaseList(): array {
        $plans = Bean::find('workbenchtask', "(parent_task_id IS NULL OR parent_task_id = 0) AND plan_status IS NOT NULL AND plan_status != '' ORDER BY id ASC");
        if (!$plans) return [];
        $byId = [];
        foreach ($plans as $p) $byId[(int) $p->id] = $p;
        $counts = [];
        $ids = array_keys($byId);
        foreach (Bean::getAll('SELECT parent_task_id pid, COUNT(*) total, SUM(CASE WHEN status IN (\'merged\', \'completed\', \'resolved\') THEN 1 ELSE 0 END) built
                                 FROM workbenchtask WHERE parent_task_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY parent_task_id', $ids) as $r) {
            $counts[(int) $r['pid']] = [(int) $r['total'], (int) $r['built']];
        }
        $out = []; $n = 0;
        // A plan a re-plan took over: an unfinished plan whose re-plan has STARTED building. While the
        // re-plan is still only a draft, the original is what gets resumed (PlanPhases) — two half-built
        // versions of one plan is the thing to avoid, not re-plans as such.
        $replacedBy = [];
        foreach ($byId as $id => $p) {
            $of = (int) ($p->replanOf ?? 0);
            if ($of > 0 && isset($byId[$of]) && (string) $byId[$of]->planStatus !== 'done' && self::replanStarted($p, $counts[$id][1] ?? 0)) $replacedBy[$of] = $id;
        }
        foreach ($byId as $id => $p) {
            $of = (int) ($p->replanOf ?? 0);
            $origin = $of > 0 ? ($byId[$of] ?? null) : null;
            $superseded = $origin !== null && (string) $origin->planStatus === 'done';
            $out[] = [
                'id' => $id, 'title' => (string) $p->title, 'plan_status' => (string) $p->planStatus,
                'total' => $counts[$id][0] ?? 0, 'built' => $counts[$id][1] ?? 0,
                'replan_of' => $of, 'superseded' => $superseded, 'replaced_by' => $replacedBy[$id] ?? 0,
                'phase' => $of > 0 ? 0 : ++$n,   // a re-plan has no number of its own
            ];
        }
        return $out;
    }

    /** Has this re-plan begun building (so it, not its original, is the one to continue)? */
    private static function replanStarted($replan, int $built): bool {
        return $built > 0 || in_array((string) $replan->planStatus, ['building', 'stalled', 'done'], true);
    }

    /**
     * Why a plan must not be approved or built: it is an automatic re-plan whose original then
     * finished without it, so its tasks are work already merged. '' = it may be.
     */
    private function supersededWhy($plan): string {
        $of = (int) ($plan->replanOf ?? 0);
        if ($of <= 0) return '';
        $origin = Bean::load('workbenchtask', $of);
        if (!$origin->id || (string) $origin->planStatus !== 'done') return '';
        return "This plan is superseded: it was an automatic re-plan of plan #{$of}, which then finished on its own, so these tasks are already built. Delete it — and use “Plan the next phase” for what the goal still needs.";
    }

    /**
     * POST /workbench/buildnextphase — approve (when it is still a draft) and build the next
     * phase that is already planned. What "continue" means to someone looking at a list of
     * phases with one waiting: run it. Planning a NEW phase is continuephase.
     */
    public function buildnextphase($params = []) {
        if (!$this->requireLogin()) return;
        if (Flight::request()->method !== 'POST') { Flight::redirect('/workbench'); return; }
        if (!Flight::csrf()->validateRequest()) { $this->flash('error', 'Invalid CSRF token'); Flight::redirect('/workbench'); return; }
        if (!$this->requireAgent()) return;
        $back = function (string $type, string $msg): void { $this->flash($type, $msg); Flight::redirect('/workbench'); };

        $phases = $this->phaseList();
        foreach ($phases as $ph) if ($ph['plan_status'] === 'building') { $back('info', "“{$ph['title']}” is already building — the next phase can start when it finishes."); return; }
        $next = \app\PlanPhases::next($phases);
        if (!$next) { $back('info', 'No phase is planned and waiting to be built. Use “Plan the next phase” to have one planned from the goal.'); return; }
        // The one the page offered: a plan ingested since the page loaded must not be built unseen.
        if ((int) $this->getParam('plan_id', 0) !== (int) $next['id']) { $back('error', 'The phases changed since this page loaded — check which one is next, then build it.'); return; }

        $pi = $this->accessiblePlan((int) $next['id']);
        if (!$pi) { $back('error', 'That phase is not one you can build.'); return; }
        [$plan, $inst] = $pi;
        if (!$inst) { $back('error', 'This phase has no linked project to build in.'); return; }
        if (PlanOrchestrator::running((int) $plan->id, (string) $inst->slug)) { $back('info', 'That phase is already running.'); return; }

        $dir   = \app\WorkbenchDb::dirOf((string) $inst->slug, (string) ($inst->app ?? ''));
        $check = (new PlanExecutor((int) $plan->id, (string) $inst->slug, $dir, (int) $this->member->level))->progressCheck();
        if ($check['ready'] === 0 && $check['running'] === 0) {
            $back('error', 'This phase cannot start: ' . ($check['roots'] ? implode('; ', $check['roots']) : 'no task in it is ready') . '. Open it to retry or fix those tasks.');
            return;
        }
        $wasDraft = (string) $plan->planStatus === 'draft';
        if ($wasDraft) { $plan->planStatus = 'approved'; $plan->updatedAt = date('Y-m-d H:i:s'); Bean::store($plan); }
        if (!$this->startOrchestrator($plan, $inst)) { $back('error', 'Could not start the build.'); return; }
        $plan->planStatus = 'building';
        $plan->status     = 'running';
        $plan->updatedAt  = date('Y-m-d H:i:s');
        Bean::store($plan);
        $this->bustTaskCache();
        $back('success', ($wasDraft ? 'Approved and building' : 'Building') . " “{$plan->title}” — {$next['total']} task(s), in dependency order.");
    }

    /** POST /workbench/planapprove — approve a plan (task-chain) so it can be built. JSON. */
    public function planapprove($params = []) {
        if (!$this->planActionGuard()) return;
        $pi = $this->accessiblePlan($this->getParam('plan_id', 0));
        if (!$pi) { $this->noSuchPlan((int) $this->getParam('plan_id', 0)); return; }
        [$plan] = $pi;
        if ($why = $this->supersededWhy($plan)) { Flight::jsonError($why, 409); return; }
        if ($plan->planStatus === 'building') { Flight::jsonError('This plan is already building.', 409); return; }
        $plan->planStatus = 'approved';
        $plan->updatedAt  = date('Y-m-d H:i:s');
        Bean::store($plan);
        Flight::jsonSuccess(['plan_status' => 'approved'], 'Plan approved — ready to build.');
    }

    /** POST /workbench/planbuild — launch the worktree orchestrator for an approved plan. JSON. */
    public function planbuild($params = []) {
        if (!$this->planActionGuard()) return;
        if (!$this->requireAgent(true)) return;
        $pi = $this->accessiblePlan($this->getParam('plan_id', 0));
        if (!$pi) { $this->noSuchPlan((int) $this->getParam('plan_id', 0)); return; }
        [$plan, $inst] = $pi;
        if (!$inst) { Flight::jsonError('This plan has no linked instance to build in.', 409); return; }
        if ($why = $this->supersededWhy($plan)) { Flight::jsonError($why, 409); return; }
        if (!in_array($plan->planStatus, ['approved', 'stalled'], true)) {
            Flight::jsonError('Approve the plan before building it (or it is already building).', 409);
            return;
        }
        if (PlanOrchestrator::running((int)$plan->id, (string)$inst->slug)) { Flight::jsonError('This plan is already running.', 409); return; }

        // SAY WHY IT CANNOT BUILD, instead of starting an orchestrator that stalls.
        //
        // Pressing Build on a plan whose remaining subtasks are all blocked used to spawn
        // an orchestrator that ticked once, found nothing launchable, wrote "stalled" and
        // exited. The page refreshed to the same stalled plan with no error — identical
        // to the button being broken. Plan 32 on floorplan sat like that: two subtasks
        // were left in `awaiting` (a status the executor never launches and nothing ever
        // moves), so the three that depended on them could never start.
        $dir   = \app\WorkbenchDb::dirOf((string) $inst->slug, (string) ($inst->app ?? ''));
        $check = (new PlanExecutor((int) $plan->id, (string) $inst->slug, $dir, (int) $this->member->level))
                    ->progressCheck();
        if ($check['ready'] === 0 && $check['running'] === 0) {
            $why = $check['roots']
                ? implode('; ', $check['roots'])
                : 'no subtask is ready and none is running';
            // Say what to do about the state the blockers are actually in.
            $all  = implode(' ', $check['roots']);
            $todo = [];
            if (preg_match('/^#\d+ (failed|conflict)\b/m', implode("\n", $check['roots']))) $todo[] = 'open each failed task and press Retry (the build restarts with it; one that ran out of time gets 60 minutes)';
            if (str_contains($all, ' awaiting')) $todo[] = 'a task left in "awaiting" is never picked up by a build — answer it in its console, or stop its session and retry it';
            Flight::jsonError('This plan cannot start: ' . $why . '.'
                . ($todo ? ' To move it: ' . implode('; ', $todo) . '.' : ''), 409);
            return;
        }

        if (!$this->startOrchestrator($plan, $inst)) {
            Flight::jsonError('Could not start the orchestrator.', 500);
            return;
        }
        $plan->planStatus = 'building';
        $plan->status     = 'running';
        $plan->updatedAt  = date('Y-m-d H:i:s');
        Bean::store($plan);
        Flight::jsonSuccess(['plan_status' => 'building'], 'Build started — up to ' . PlanExecutor::MAX_CONCURRENT . ' agents running.');
    }

    /**
     * Launch the detached worktree orchestrator for a plan. Returns true on success.
     *
     * The launch itself lives in core (app\PlanOrchestrator) because four copies of it
     * existed and had drifted — see that class. This wrapper is just "which instance,
     * which member level".
     */
    private function startOrchestrator($plan, $inst): bool {
        $dir = \app\WorkbenchDb::dirOf((string) $inst->slug, (string) ($inst->app ?? ''));
        return PlanOrchestrator::launch(
            (int) $plan->id, (string) $inst->slug, $dir, (int) $this->member->level
        );
    }

    /** The states in which a task has not started (or has stopped), so the agent it runs on can still be changed. */
    private const AGENT_CHANGEABLE = ['pending', 'failed', 'conflict'];

    /**
     * POST /workbench/taskagent — which of the app's Build agents a task runs on, set from the
     * board: one task (task_id), or every task of a plan that has not started (plan_id). agent ''
     * = the app's builder. Tasks on different agents run side by side (PlanExecutor caps each
     * agent by its own "Tasks at once"), which is what a second Build agent is for. JSON.
     */
    public function taskagent($params = []) {
        if (!$this->planActionGuard()) return;
        try { $agent = \app\PlanIngestor::agentName($this->getParam('agent', '')); }
        catch (\RuntimeException $e) { Flight::jsonError($e->getMessage(), 422); return; }

        $planId = (int) $this->getParam('plan_id', 0);
        $first  = Bean::load('workbenchtask', $planId ?: (int) $this->getParam('task_id', 0));
        if (!$first->id || !$this->access->canRun((int) $this->member->id, $first)) { Flight::jsonError('No such task', 404); return; }
        $tasks = $planId
            ? array_values(Bean::find('workbenchtask', 'parent_task_id = ? AND status IN (' . implode(',', array_fill(0, count(self::AGENT_CHANGEABLE), '?')) . ') ORDER BY id', array_merge([$planId], self::AGENT_CHANGEABLE)))
            : [$first];
        if (!$planId && !in_array((string) $first->status, self::AGENT_CHANGEABLE, true)) {
            Flight::jsonError("Task #{$first->id} is {$first->status}: the agent can be changed only before a task starts, or after it failed.", 409); return;
        }
        if (!$tasks) { Flight::jsonError('No task of this plan is waiting to start, so there is nothing to move to another agent.', 409); return; }

        $tenant = \app\TenantBuilder::bySlug(explode('.', (string) $first->instanceTag, 2)[0]);
        if (!$tenant) { Flight::jsonError('This project does not run its own agents, so there is no agent to pick.', 409); return; }
        if ($why = $this->tenantAgentProblem($tenant, $agent)) { Flight::jsonError('That agent cannot build: ' . $why . '.', 409); return; }

        foreach ($tasks as $t) { $t->agent = $agent; Bean::store($t); }
        $n = count($tasks);
        Flight::jsonSuccess(['agent' => $agent, 'task_ids' => array_map(fn($t) => (int) $t->id, $tasks)],
            ($planId ? "{$n} task" . ($n === 1 ? '' : 's') . ' of this plan' : "Task #{$first->id}") . ' will run on ' . ($agent !== '' ? $agent : 'the builder') . '.');
    }

    /**
     * POST /workbench/taskretry — recover a failed plan subtask: reset it to pending
     * (fresh auto-retry budget), re-open the plan, and re-launch the orchestrator,
     * which rebuilds the task and auto-corrects known blockers toward completion. JSON.
     */
    public function taskretry($params = []) {
        if (!$this->planActionGuard()) return;
        if (!$this->requireAgent(true)) return;

        $task = Bean::load('workbenchtask', (int)$this->getParam('task_id', 0));
        if (!$task->id || !$this->access->canRun((int)$this->member->id, $task)) { Flight::jsonError('No such task', 404); return; }
        if (empty($task->parentTaskId)) { Flight::jsonError('Only a plan subtask can be retried this way.', 409); return; }
        // `awaiting` is retryable ONLY once its session is gone.
        //
        // While the session is alive, awaiting means the agent asked something and is
        // holding at its prompt — the session IS the question, and resetting the task
        // would throw away work mid-flight. Once the session has ended there is no
        // question left to answer and nothing will ever move the task again: the
        // executor launches `pending` only, and treats `awaiting` as neither done nor
        // startable. That is a dead end with no way out of the UI, and it is how plan 32
        // on floorplan stalled permanently behind two subtasks.
        $retryable = ['failed', 'conflict'];
        $session   = trim((string) ($task->agentSession ?: $task->tmuxSession ?: ''));
        if ($task->status === 'awaiting' && ($session === '' || !TmuxManager::exists($session))) {
            $retryable[] = 'awaiting';
        }
        if (!in_array($task->status, $retryable, true)) {
            Flight::jsonError($task->status === 'awaiting'
                ? 'This task is waiting on you and its console is still live — answer it there, or stop the session first.'
                : 'Only a failed task can be retried.', 409);
            return;
        }

        $plan = Bean::load('workbenchtask', (int)$task->parentTaskId);
        if (!$plan->id) { Flight::jsonError('Parent plan not found', 404); return; }
        // A plan a re-plan took over: its tasks were planned again there. Building one here too
        // builds the same thing twice (two seeds for one column, two versions of one page).
        $replan = (string) $plan->planStatus !== 'done' ? Bean::findOne('workbenchtask', 'replan_of = ? ORDER BY id DESC', [(int) $plan->id]) : null;
        if ($replan && $replan->id && self::replanStarted($replan, (int) Bean::count('workbenchtask', "parent_task_id = ? AND status IN ('merged', 'completed', 'resolved')", [(int) $replan->id]))) {
            Flight::jsonError("Plan #{$plan->id} was re-planned as #{$replan->id} when it stalled, and that re-plan is what continues it — this task was planned again there. Open plan #{$replan->id} and retry or resume it; this one can be deleted.", 409);
            return;
        }
        $inst = $plan->instanceId ? $this->access->instanceMeta((int)$plan->instanceId) : null;
        if (!$inst || !$inst->id) { Flight::jsonError('This plan has no linked instance.', 409); return; }
        $building = PlanOrchestrator::running((int)$plan->id, (string)$inst->slug);

        // Ran out of time last attempt: the retry gets the longer limit — the same 30 minutes
        // would end the same way.
        if (\app\PlanExecutor::ranOutOfTime((string) $task->errorMessage)) $task->timeLimit = \app\PlanExecutor::TIME_LIMIT_MAX;
        // Reset the task for a fresh attempt (fresh auto-retry budget).
        $task->status       = 'pending';
        $task->errorMessage = '';
        $task->retryCount   = 0;
        $task->updatedAt    = date('Y-m-d H:i:s');
        Bean::store($task);

        // The plan is still building: the orchestrator reads its tasks afresh every tick and
        // launches this one as soon as it has a slot. (This used to be refused with "will be
        // picked up in that run" — but a FAILED task never was; only a pending one is.)
        if ($building) {
            $this->bustTaskCache();
            Flight::jsonSuccess(['plan_status' => 'building'], 'Retrying — the build already running picks it up in a few seconds.');
            return;
        }
        if (!$this->startOrchestrator($plan, $inst)) {
            Flight::jsonError('Could not start the orchestrator.', 500);
            return;
        }
        // Reflect that the orchestrator is now running (matches planbuild).
        $plan->planStatus = 'building';
        $plan->status     = 'running';
        $plan->updatedAt  = date('Y-m-d H:i:s');
        Bean::store($plan);
        Flight::jsonSuccess(['plan_status' => 'building'], 'Retrying — the orchestrator will rebuild this task and auto-correct known blockers.');
    }

    /** POST /workbench/plandelete — delete a whole plan (task-chain): parent + all subtasks. JSON. */
    public function plandelete($params = []) {
        if (!$this->planActionGuard()) return;
        $pi = $this->ownedPlan($this->getParam('plan_id', 0));
        if (!$pi) { $this->noSuchPlan((int) $this->getParam('plan_id', 0)); return; }
        [$plan, $inst] = $pi;
        if ($plan->planStatus === 'building'
            || PlanOrchestrator::running((int)$plan->id, (string)($inst->slug ?? ''))) {
            Flight::jsonError('This plan is building — stop the build before deleting it.', 409);
            return;
        }
        $n = 0;
        foreach (Bean::find('workbenchtask', 'parent_task_id = ?', [(int)$plan->id]) as $s) { Bean::trash($s); $n++; }
        Bean::trash($plan);
        Flight::jsonSuccess(['deleted' => $n + 1], 'Deleted the plan and ' . $n . ' task(s).');
    }

    /** GET /workbench/planprogress — per-task status for a plan (for the build poller). JSON. */
    public function planprogress($params = []) {
        if (!$this->requireLogin()) return;
        $pi = $this->accessiblePlan($this->getParam('plan_id', 0));
        if (!$pi) { $this->noSuchPlan((int) $this->getParam('plan_id', 0)); return; }
        [$plan] = $pi;
        $tasks = [];
        foreach (Bean::find('workbenchtask', 'parent_task_id = ? ORDER BY priority ASC, id ASC', [(int)$plan->id]) as $s) {
            $tasks[] = ['id' => (int)$s->id, 'title' => $s->title, 'status' => $s->status];
        }
        Flight::jsonSuccess(['plan_status' => $plan->planStatus ?: 'draft', 'status' => $plan->status, 'tasks' => $tasks]);
    }

    /**
     * Has anyone signed the agent in for this project yet?
     *
     * Claude credentials are stored PER PROJECT — jail-run.sh binds
     * <instance>/.aibuilder/state/<engine> as the agent's ~/.claude — so a freshly
     * created project cannot plan or build until someone opens ITS terminal and logs in.
     * Without this check the planner starts, dies in about a second with "Not logged in",
     * and the board sits on "Decomposing…" until the poll gives up: a five-minute wait
     * for a failure that was knowable before the click.
     */
    private function agentSignedIn(string $dir, string $engine): bool {
        // One rule, in core: the member's own store first, the project's as the legacy
        // fallback. Asking here in a different way than the runners answer it is how a
        // form ends up promising a build that cannot start.
        return \app\AgentState::signedIn((int) $this->member->id, $engine, $dir);
    }

    /**
     * POST /workbench/decomposestop — cancel the planner running for this project.
     *
     * A decompose is a five-minute frontier model run that, once started, could only be
     * stopped by killing its tmux session from a shell. That is not an operation a user
     * can be expected to have — and the case that needs it is not rare: you realise it is
     * grounded on the wrong project, or you spot a mistake in the goal the moment after
     * you click.
     *
     * Stops only THIS project's planner: the session name is derived from the selected
     * instance, never from the request, so this cannot cancel someone else's run.
     */
    public function decomposestop($params = []) {
        if (!$this->requireLogin()) return;
        if (Flight::request()->method !== 'POST') { Flight::jsonError('POST required', 405); return; }
        if (!Flight::csrf()->validateRequest()) { Flight::jsonError('Invalid CSRF token', 403); return; }

        $instance = $this->selected ? $this->access->instanceMeta((int) $this->selected['id']) : null;
        if (!$instance || !$instance->id || !$this->access->canAccessInstance((int)$this->member->id, (int)$instance->id)) {
            Flight::jsonError('No project selected.', 409);
            return;
        }

        $dir = \app\WorkbenchDb::dirOf((string) $instance->slug, (string) ($instance->app ?? ''));
        $runner = new PlanRunner((string) $instance->slug, $dir, (int) $this->member->id,
                                 // '' not 'claude': an instance row with no engine should fall
                                 // through to the PROJECT's own .aibuilder/engine, which
                                 // AgentContext consults. Substituting claude here overruled it.
                                 (int) $this->member->level, (string) ($instance->engine ?? ''));
        if (!$runner->running()) {
            Flight::jsonSuccess(['stopped' => false], 'No planner is running for this project.');
            return;
        }

        $ok = $runner->stop();
        // A half-written plan.json would be ingested as if it were finished. The planner
        // writes it only on success, but a cancel is exactly when "only on success" is
        // worth not betting on.
        @unlink($dir . '/.aibuilder/plan.json');

        $this->logger->info('decompose stopped', ['instance' => $instance->slug, 'member' => $this->member->id]);
        Flight::jsonSuccess(['stopped' => $ok],
            $ok ? 'Stopped decomposing. Nothing was ingested.' : 'Could not stop the planner.');
    }

    /**
     * The goal the selected project's planner is working on: {title, excerpt}, or null when it
     * cannot be told.
     *
     * The goal itself is the workspace's .aibuilder/plan-goal.md, which PlanRunner writes when
     * a planner starts — whoever started it (the form, a re-run, "continue", a queued retry).
     * Its NAME is the title typed on the form, kept in the member's prompt log with the goal as
     * the body: the row whose body is this goal names it. A goal nobody named (or named only
     * "Decompose", the form's old default) is called by its own first line.
     */
    private function decomposingGoal(): ?array {
        if (!$this->selected) return null;
        $slug = (string) $this->selected['slug'];
        $app  = (string) ($this->selected['app'] ?? '');
        $file = rtrim(\app\WorkbenchDb::dirOf($slug, $app), '/') . '/.aibuilder/plan-goal.md';
        if (!is_file($file)) return null;
        $goal = trim((string) file_get_contents($file));
        if ($goal === '') return null;

        $title = '';
        $rows = (array) \app\CoreDb::with(
            fn() => \app\PromptLog::forMember((int) $this->member->id, \app\PromptLog::SOURCE_DECOMPOSE, 30, $slug . '.' . ($app ?: 'tiknix')),
            []
        );
        foreach ($rows as $r) {
            if (trim((string) $r->body) !== $goal) continue;
            $t = trim((string) $r->title);
            if ($t !== '' && $t !== 'Decompose') $title = $t;
            break;   // newest first: the run in progress
        }
        // What was asked, without the form's answers written under it (app\GoalBrief).
        $asked = \app\GoalBrief::split($goal)['goal'];
        $lines = array_values(array_filter(array_map(fn($l) => trim(ltrim(trim($l), '#')), explode("\n", $asked)), fn($l) => $l !== ''));
        if ($title === '') $title = (string) ($lines[0] ?? '');
        if ($title === '') return null;
        $excerpt = implode(' ', array_filter($lines, fn($l) => $l !== $title));
        return [
            'title'   => mb_strlen($title) > 90 ? mb_substr($title, 0, 89) . '…' : $title,
            'excerpt' => mb_strlen($excerpt) > 180 ? mb_substr($excerpt, 0, 179) . '…' : $excerpt,
        ];
    }

    /**
     * GET /workbench/decomposestatus — is the planner still decomposing? JSON.
     *
     * Answers for THE SELECTED PROJECT. It used to take an ?instance_id, which was
     * access-checked and so never unsafe, but it did let the board ask about a project
     * the member was not on — and an endpoint that will answer for any project is how a
     * caller ends up quietly reporting on one.
     */
    public function decomposestatus($params = []) {
        if (!$this->requireLogin()) return;
        $inst = $this->selected ? $this->access->instanceMeta((int) $this->selected['id']) : null;
        if (!$inst || !$inst->id || !$this->access->canAccessInstance((int)$this->member->id, (int)$inst->id)) {
            Flight::jsonError('No project selected', 409);
            return;
        }
        $session = 'tiknix-' . (int)$this->member->id . '-plan-' . $inst->slug;
        $newest  = (int)Bean::getCell(
            'SELECT MAX(id) FROM workbenchtask WHERE instance_id = ? AND parent_task_id IS NULL',
            [(int)$inst->id]
        );
        /* Liveness, not just presence. The planner runs plain `claude -p`, so planner.log
           stays empty for the whole run and the process sits at 0% CPU between API turns —
           a working decompose is indistinguishable from a wedged one, and a 17-minute run
           was reported as hung on exactly that. The CLI's transcript grows every turn, so
           its size and age are the progress signal. Null means "cannot tell", which the UI
           must not render as either working or stuck. */
        $running  = TmuxManager::exists($session);
        $dir      = \app\WorkbenchDb::dirOf((string) $inst->slug, (string) ($inst->app ?? ''));   // the workspace (planner.log)
        $activity = null;
        if ($running) {
            $runner   = new PlanRunner((string) $inst->slug, $dir, (int) $this->member->id,
                                       (int) $this->member->level, (string) ($inst->engine ?? ''));
            $activity = $runner->activity();
        }
        // WHO is planning and WHAT it is doing right now, for the banner: the planner runs headless
        // in the project's container, and its live transcript is the only thing that moves while it
        // works (TenantRun::activity — what it said, each tool it called).
        $who = ''; $doing = ''; $recent = []; $quiet = null;
        if ($running && ($ct = $this->tenantInst())) {
            $rj = json_decode((string) ($ct->reportJson ?? ''), true) ?: [];
            $name = trim((string) @file_get_contents($dir . '/.aibuilder/planner-agent')) ?: (string) ($rj['default_agent'] ?? '');
            $who = $name === '' || $name === 'anthropic' ? 'the Anthropic account' : (string) (($rj['agent_names'][$name] ?? '') ?: $name);
            try {
                $a = \app\TenantRun::activity($ct, ['planner-m' . (int) $this->member->id], 6)['planner-m' . (int) $this->member->id] ?? null;
                if ($a && $a['found']) {
                    $clean = fn(string $l) => trim(preg_replace('/^\[[0-9:]*\]\s*(→\s*)?/u', '', $l));
                    $recent = array_values(array_filter(array_map($clean, $a['lines']), fn($l) => $l !== ''));
                    $doing  = $recent ? (string) end($recent) : 'Started — nothing said yet';
                    $quiet  = $a['at'] !== '' ? max(0, time() - (int) strtotime($a['at'])) : null;
                } else {
                    $doing = 'Starting up…';
                }
            } catch (\RuntimeException $e) {
                $doing = '';   // the container did not answer this time: say nothing rather than guess
            }
        }
        Flight::jsonSuccess([
            'running'        => $running,
            'newest_plan_id' => $newest,
            'activity'       => $activity,
            'agent'          => $who,
            'doing'          => mb_substr($doing, 0, 220),
            'recent'         => array_map(fn($l) => mb_substr($l, 0, 220), array_slice($recent, -5, 4)),
            'quiet_seconds'  => $quiet,
        ]);
    }

    /** Which of the project's agents the planner about to start runs on ('' = its builder) — the planning banner names it. */
    private function notePlannerAgent(string $instanceDir, string $agent): void {
        $ab = rtrim($instanceDir, '/') . '/.aibuilder';
        if (!is_dir($ab)) @mkdir($ab, 0775, true);
        if (@file_put_contents($ab . '/planner-agent', $agent) === false) error_log("ERROR Workbench: could not record the planner's agent in {$ab}/planner-agent");
    }

    /**
     * Parse the executor agent's stream-json log into "what is it doing now".
     * Returns {current: {verb,target}|null, recent: [...], files: [...],
     * running: bool, finished: bool}. Pure read — never mutates anything.
     */
    private function planAgentActivity($task): array {
        // A subtask runs headless in the project's container (TenantRun): whether it is still
        // running is known there; its output is recorded on the task when it finishes.
        $out = ['current' => null, 'recent' => [], 'files' => [], 'finished' => false, 'running' => false];
        $ct = $this->tenantInst();
        if ($ct && (string) $task->agentSession !== '') {
            try { $out['running'] = \app\TenantRun::alive($ct, (string) $task->agentSession); } catch (\RuntimeException $e) { $out['running'] = true; }
        }
        return $out;
    }

    /** Map a Claude tool_use event to a human {verb, target} for the activity feed. */
    private function describeToolUse(string $name, array $in): array {
        switch ($name) {
            case 'Read':         return ['verb' => 'Reading',       'target' => basename((string)($in['file_path'] ?? ''))];
            case 'Edit':         return ['verb' => 'Editing',       'target' => basename((string)($in['file_path'] ?? ''))];
            case 'Write':        return ['verb' => 'Writing',       'target' => basename((string)($in['file_path'] ?? ''))];
            case 'NotebookEdit': return ['verb' => 'Editing',       'target' => basename((string)($in['notebook_path'] ?? ''))];
            case 'Bash':         return ['verb' => 'Running',       'target' => $this->firstLine((string)($in['description'] ?? $in['command'] ?? ''))];
            case 'Grep':         return ['verb' => 'Searching for', 'target' => $this->firstLine((string)($in['pattern'] ?? ''))];
            case 'Glob':         return ['verb' => 'Finding files', 'target' => $this->firstLine((string)($in['pattern'] ?? ''))];
            case 'Task':         return ['verb' => 'Delegating',    'target' => $this->firstLine((string)($in['description'] ?? ''))];
            case 'TodoWrite':    return ['verb' => 'Planning next steps', 'target' => ''];
            default:             return ['verb' => 'Using ' . ($name ?: 'a tool'), 'target' => ''];
        }
    }

    /** First line of a string, trimmed and length-capped for display. */
    private function firstLine(string $s, int $max = 80): string {
        $s = trim($s);
        $nl = strpos($s, "\n");
        if ($nl !== false) $s = rtrim(substr($s, 0, $nl));
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
    }

    /**
     * View task details
     */
    public function view($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        if (!$taskId) {
            Flight::redirect('/workbench');
            return;
        }

        // Subtasks are written by the headless plan-ingest.php CLI, whose separate DB
        // connection can't invalidate this web process's query cache. Without busting
        // it here, a plan parent viewed soon after decompose can read a stale-EMPTY
        // subtask list — dropping $planRollup and rendering the task-level
        // "Approve & Merge" button on a plan parent, which merges the (branchless)
        // parent and corrupts its status. Bust before we load/find anything.
        $this->bustTaskCache();

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            $this->flash('error', 'Task not found');
            Flight::redirect('/workbench');
            return;
        }

        // Check access
        if (!$this->access->canView($this->member->id, $task)) {
            $this->flash('error', 'Access denied');
            Flight::redirect('/workbench');
            return;
        }

        // Sync tmux status to database for running tasks.
        // Plan-decompose subtasks are owned by PlanExecutor (separate worktree +
        // a tiknix-<slug>-plan<N>-task<M> session in its container). Never let this
        // view's poller touch them, so
        // exists() returns false and it would race the executor by force-failing a
        // live subtask.
        /* A plan PARENT is plan-managed too, and every marker below belongs to a SUBTASK:
           a parent has no plan_ref, no worktree branch and no agent session of its own,
           because its work happens in its subtasks' sessions under an orchestrator. So the
           most plan-managed row in the system read as unmanaged, and opening the plan page
           while it built marked it `failed` with "Session ended unexpectedly" — against a
           session it never had. Plan #142 showed status=failed beside plan_status=building
           with two subtasks still running.

           isPlan() is the authoritative test and exists so the board, the reaper and the
           task view cannot answer this differently. The reaper simply never asked it. */
        if ($ct = $this->tenantInst()) $this->syncContainerTask($task, $ct);   // a finished container run's result
        $isPlanManaged = $task->isPlan()
            || !empty($task->planRef)
            || !empty($task->worktreeBranch)
            || TmuxManager::isPlanSession((string)$task->agentSession);

        // Get task logs
        $logs = Bean::find('tasklog', 'task_id = ? ORDER BY created_at DESC LIMIT 50', [$taskId]);

        // Task comments.
        //
        // This used to JOIN member and name tc.image_path explicitly, and returned
        // NOTHING on every instance. `member` does not live in workbench.db — it is
        // core's table — and image_path only exists once somebody has attached an
        // image, because the schema is fluid. RedBean answers a query naming an
        // absent table or column with an empty result rather than an error, so the
        // conversation looked deleted on every reload while the rows sat there
        // untouched. Comments posted by fetch appeared because the JAVASCRIPT added
        // them to the page; they vanished the moment the server rendered it.
        //
        // So: read the comments from the database they are actually in, with no
        // column named that fluid mode may not have created yet.
        $comments = Bean::getAll(
            "SELECT * FROM taskcomment WHERE task_id = ? ORDER BY created_at ASC",
            [$taskId]
        );
        $comments = $this->withCommentAuthors($comments);

        // Get latest snapshot
        $latestSnapshot = Bean::findOne('tasksnapshot', 'task_id = ? ORDER BY created_at DESC', [$taskId]);

        // Get team info if team task
        $team = null;
        if ($task->teamId) {
            $team = Bean::load('team', $task->teamId);
        }

        // Get creator info
        $creator = Bean::load('member', $task->memberId);

        // Dependency status — for a plan subtask, "what is this task waiting on
        // before Claude can start it?" (upstream prerequisites) and "what is
        // waiting on it?" (downstream). Done = merged/completed; anything else
        // still blocks. Ordering the executor uses is the same depends_on DAG.
        $doneStates = ['merged', 'completed', 'done'];
        $deps = $blocks = [];
        foreach ((array)json_decode(((string)($task->dependsOn ?: '[]')) ?? '', true) as $did) {
            $d = Bean::load('workbenchtask', (int)$did);
            if ($d->id) {
                $deps[] = ['id' => (int)$d->id, 'title' => $d->title, 'status' => $d->status,
                           'done' => in_array($d->status, $doneStates, true)];
            }
        }
        if (!empty($task->parentTaskId)) {
            foreach (Bean::find('workbenchtask', 'parent_task_id = ? AND id != ?',
                     [(int)$task->parentTaskId, (int)$task->id]) as $sib) {
                $sd = array_map('intval', (array)json_decode(((string)($sib->dependsOn ?: '[]')) ?? '', true));
                if (in_array((int)$task->id, $sd, true)) {
                    $blocks[] = ['id' => (int)$sib->id, 'title' => $sib->title, 'status' => $sib->status];
                }
            }
        }
        $this->viewData['deps']        = $deps;
        $this->viewData['depsPending'] = array_values(array_filter($deps, fn($d) => !$d['done']));
        $this->viewData['blocks']      = $blocks;

        // Changes to review — when a task is paused for the operator (awaiting /
        // completed / paused) and still has its workspace branch, summarise what
        // changed so "Your Turn" actually shows what there is to review.
        $reviewChanges = null;
        if (in_array($task->status, ['awaiting', 'completed', 'paused'], true)) {
            $reviewChanges = $this->taskDiffStat($task);
        }
        $this->viewData['reviewChanges'] = $reviewChanges;

        // Plan rollup — a plan PARENT has no branch of its own; its subtasks each
        // merge into the instance's live branch as they finish. So "Approve & Merge"
        // is a no-op on the parent. Detect it and hand the view a status rollup to
        // show instead of a dead merge button.
        $planRollup = null;
        if (empty($task->parentTaskId)) {
            $subs = Bean::find('workbenchtask', 'parent_task_id = ?', [(int)$task->id]);
            if ($subs) {
                $doneStates = ['merged', 'completed', 'done'];
                $done = 0; $counts = [];
                foreach ($subs as $s) {
                    $st = (string)$s->status;
                    $counts[$st] = ($counts[$st] ?? 0) + 1;
                    if (in_array($st, $doneStates, true)) $done++;
                }
                // The subtasks that stopped and need a person: named, with why, so the plan
                // page can ask for the retry instead of leaving a count to be decoded.
                $stopped = [];
                foreach ($subs as $s) {
                    if (!in_array((string) $s->status, ['failed', 'conflict'], true)) continue;
                    $stopped[] = ['id' => (int) $s->id, 'title' => (string) $s->title, 'status' => (string) $s->status,
                                  'timed_out' => \app\PlanExecutor::ranOutOfTime((string) $s->errorMessage),
                                  'error' => mb_substr((string) $s->errorMessage, 0, 300)];
                }
                $planRollup = ['total' => count($subs), 'done' => $done, 'counts' => $counts, 'stopped' => $stopped];
                // What the plan's agents have cost so far: every subtask's runs, summed (app\RunStats).
                $planStats = \app\RunStats::sum(array_map(fn($s) => json_decode((string) ($s->statsJson ?? ''), true), array_values($subs)));
                if (!empty($planStats['tasks'])) $this->viewData['runStatsLine'] = \app\RunStats::line($planStats) . ' — across ' . (int) $planStats['tasks'] . ' task' . ((int) $planStats['tasks'] === 1 ? '' : 's') . ' that ' . ((int) $planStats['tasks'] === 1 ? 'has' : 'have') . ' run';
            }
        }
        // A task's own runs so far.
        if (!isset($this->viewData['runStatsLine'])) $this->viewData['runStatsLine'] = \app\RunStats::line(json_decode((string) ($task->statsJson ?? ''), true) ?: []);
        $this->viewData['planRollup'] = $planRollup;

        $this->viewData['title'] = $task->title;
        $this->viewData['task'] = $task;
        $this->viewData['logs'] = $logs;
        $this->viewData['comments'] = $comments;
        $this->viewData['latestSnapshot'] = $latestSnapshot;
        $this->viewData['team'] = $team;
        $this->viewData['creator'] = $creator;
        $this->viewData['canEdit'] = $this->access->canEdit($this->member->id, $task);
        // What the Run button names: the task's own agent, else the app's default as it last
        // reported it (StatusReport → instance.report_json); nothing invented when unknown.
        $runAgent = trim((string) ($task->agent ?? ''));
        $rj = ($this->selected && ($im = $this->access->instanceMeta((int) $this->selected['id']))) ? json_decode((string) ($im->reportJson ?? ''), true) : null;
        if ($runAgent === '' && is_array($rj)) $runAgent = (string) ($rj['default_agent'] ?? '');
        // The name its owner gave it ("QA Quinn"), where it has one; the handle otherwise.
        if ($runAgent !== '' && is_array($rj) && !empty($rj['agent_names'][$runAgent])) $runAgent = (string) $rj['agent_names'][$runAgent];
        $this->viewData['runAgentLabel'] = $runAgent !== '' ? 'Run with ' . $runAgent : 'Run';
        $this->viewData['runAgentName'] = $runAgent;   // '' = unknown: the page says "Agent", never a vendor
        $this->viewData['canRun'] = $this->access->canRun($this->member->id, $task);
        $this->viewData['canDelete'] = $this->access->canDelete($this->member->id, $task);
        $this->viewData['taskTypes'] = $this->getTaskTypes();
        $this->viewData['priorities'] = $this->getPriorities();

        $this->render('workbench/view', $this->viewData);
    }

    /**
     * Edit task form
     */
    public function edit($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        if (!$taskId) {
            Flight::redirect('/workbench');
            return;
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            $this->flash('error', 'Task not found');
            Flight::redirect('/workbench');
            return;
        }

        if (!$this->access->canEdit($this->member->id, $task)) {
            $this->flash('error', 'Access denied');
            Flight::redirect('/workbench/view?id=' . $taskId);
            return;
        }

        // Get available branches from git (only if task hasn't been run yet)
        // Only show remote branches - local-only branches can't be used as base for new workspaces
        $branches = [];
        $currentBranch = 'main';
        // A task builds on the app's main branch, in the project's container — there is no other base.
        if (empty($task->branchName)) $branches = ['main'];

        $this->viewData['title'] = 'Edit Task';
        $this->viewData['task'] = $task;
        /* The engine is chosen when a task is CREATED and was then unchangeable — a task
           assigned to a provider that later ran out of quota, or that turned out to be the
           wrong fit, could only be moved by editing the database row. Same picker and same
           values as the create form, so there is one way to express this choice. */
        $choices = \app\EngineRegistry::runMenu();
        $this->viewData['runChoices'] = $choices;
        /* Match an OFFERED option, never a constructed string. A task carries engine and
           model separately and the model is often null, so "zai:" matched nothing and the
           browser silently selected the first option in the list — opening this page on a
           z.ai task showed "Claude Code" and saving would have moved it. Prefer the exact
           pair, fall back to the engine's first offered model, and select nothing when the
           engine is unknown rather than pointing at someone else's. */
        $curEngine = trim((string) ($task->engine ?? ''));
        $curModel  = trim((string) ($task->model ?? ''));
        $current   = '';
        foreach ($choices as $c) {
            if ($c['engine'] !== $curEngine) continue;
            if ($curModel !== '' && $c['model'] === $curModel) { $current = $c['value']; break; }
            if ($current === '') $current = $c['value'];      // first for this engine
        }
        $this->viewData['currentRunChoice'] = $current;
        $this->viewData['defaultRunChoice'] = $current;
        $this->viewData['taskTypes'] = $this->getTaskTypes();
        $this->viewData['priorities'] = $this->getPriorities();
        // A project in its own container builds on ITS agents: offer those, as the create form does.
        $this->offerAppAgents(explode('.', (string) $task->instanceTag, 2)[0]);
        /* "Who is it for" and "how will you know it worked" live IN the description
           (app\GoalBrief): taken back out here so they are edited as answers, and written back
           by update(). A task from before that kept its criteria in a column of their own; they
           are shown in the same box and move into the description on the next save. A task with
           no answer written (the planner's own tasks say it in their words) gets 'none', which
           writes nothing. */
        $was = \app\GoalBrief::split((string) $task->description);
        $this->viewData['brief'] = [
            'goal'       => $was['goal'],
            'audience'   => $was['audience'] !== '' ? $was['audience'] : 'none',
            'acceptance' => $was['acceptance'] !== '' ? $was['acceptance'] : trim((string) ($task->acceptanceCriteria ?? '')),
        ];
        $this->viewData['branches'] = $branches;
        $this->viewData['currentBranch'] = $currentBranch;

        $this->render('workbench/edit', $this->viewData);
    }

    /**
     * Update task
     */
    public function update($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        $taskId = (int)$this->getParam('id');
        if (!$taskId) {
            Flight::redirect('/workbench');
            return;
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            $this->flash('error', 'Task not found');
            Flight::redirect('/workbench');
            return;
        }

        if (!$this->access->canEdit($this->member->id, $task)) {
            $this->flash('error', 'Access denied');
            Flight::redirect('/workbench/view?id=' . $taskId);
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/workbench/edit?id=' . $taskId);
            return;
        }

        $title = trim($this->getParam('title', ''));
        if (empty($title)) {
            $this->flash('error', 'Task title is required');
            Flight::redirect('/workbench/edit?id=' . $taskId);
            return;
        }

        // The two answers go back INTO the description, where the agent reads them (app\GoalBrief).
        $audience = (string) $this->getParam('audience', '');
        if (!\app\GoalBrief::isAudience($audience)) {
            $this->flash('error', 'Pick who this is for — or "Leave it to the description".');
            Flight::redirect('/workbench/edit?id=' . $taskId);
            return;
        }
        $acceptance  = trim((string) $this->getParam('acceptance_criteria', ''));
        $description = ltrim(\app\GoalBrief::compose(trim((string) $this->getParam('description', '')), $audience, $acceptance));

        // Who builds it: the app's agent for a project in its own container, else an engine+model
        // pair. (The picker used to be shown here and never saved.)
        $tenant = \app\TenantBuilder::bySlug(explode('.', (string) $task->instanceTag, 2)[0]);
        $agent  = null;
        if ($tenant) {
            try { $agent = \app\PlanIngestor::agentName($this->getParam('agent', '')); }
            catch (\RuntimeException $e) {
                $this->flash('error', $e->getMessage());
                Flight::redirect('/workbench/edit?id=' . $taskId);
                return;
            }
        }

        try {
            $relatedFiles = json_encode(array_values(array_filter(array_map('trim', explode("\n", (string) $this->getParam('related_files', ''))))));
            $tags         = json_encode(array_values(array_filter(array_map('trim', explode(',', (string) $this->getParam('tags', ''))))));
            // What this save changed — choices only (app\ToolUse), so the parts nobody edits can go.
            $changed = [
                'title'         => $title !== (string) $task->title,
                'description'   => $description !== trim((string) $task->description),
                'audience'      => $audience,
                'acceptance'    => $acceptance !== '',
                'type'          => (string) $this->getParam('task_type', 'feature') !== (string) $task->taskType,
                'priority'      => (int) $this->getParam('priority', 3) !== (int) $task->priority,
                'related_files' => $relatedFiles !== json_encode(array_values(json_decode((string) $task->relatedFiles, true) ?: [])),
                'tags'          => $tags !== json_encode(array_values(json_decode((string) $task->tags, true) ?: [])),
                'agent'         => $tenant ? $agent !== (string) ($task->agent ?? '') : false,
                'more_opened'   => $this->getParam('more_opened', '') === '1',
                'has_run'       => !empty($task->branchName) || (int) ($task->runCount ?? 0) > 0,
            ];

            $task->title = $title;
            $task->description = $description;
            $task->taskType = $this->getParam('task_type', 'feature');
            $task->priority = (int)$this->getParam('priority', 3);
            $task->authcontrolLevel = \app\GoalBrief::level($audience);
            // The criteria are in the description now; a column of their own would show them twice.
            $task->acceptanceCriteria = '';
            $task->relatedFiles = $relatedFiles;
            $task->tags = $tags;
            if ($tenant) {
                $task->agent = $agent;
            } elseif ($pick = \app\EngineRegistry::parseRunChoice($this->getParam('run_with', ''))) {
                $changed['agent'] = $pick['engine'] !== (string) $task->engine || $pick['model'] !== (string) $task->model;
                $task->engine = $pick['engine'];
                $task->model  = $pick['model'];
            }

            // Only allow changing base branch if task hasn't been run yet
            if (empty($task->branchName)) {
                $task->baseBranch = trim($this->getParam('base_branch', $task->baseBranch ?? 'main'));
            }

            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'info', 'user', 'Task updated');
            \app\ToolUse::record('builder.edit', (int) $this->member->id, $changed, (int) $task->instanceId);

            $this->flash('success', 'Task updated');
            Flight::redirect('/workbench/view?id=' . $taskId);

        } catch (Exception $e) {
            $this->logger->error('Failed to update task', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to update task');
            Flight::redirect('/workbench/edit?id=' . $taskId);
        }
    }

    /**
     * Delete task
     */
    /**
     * Delete many tasks at once, enforcing the SAME permission check as single delete.
     *
     * An imported backlog leaves rows nobody will ever run, and removing them one page at a
     * time is why they linger. Each task is checked individually — a bulk action
     * is not a permission shortcut — and the response says exactly which ids were refused
     * rather than reporting a count that quietly hides them.
     *
     * POST /workbench/bulkdelete   ids[]=1&ids[]=2
     */
    public function bulkdelete($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::csrf()->validateRequest()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $ids = (array) ($this->getParam('ids', []));
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) { Flight::jsonError('No tasks selected', 400); return; }

        // A cap, because this destroys workspaces on disk and a runaway selection should not
        // become a filesystem operation nobody can stop.
        if (count($ids) > 100) { Flight::jsonError('Select 100 tasks or fewer at a time', 400); return; }

        $deleted = []; $refused = []; $missing = []; $subtasks = 0;
        foreach ($ids as $id) {
            $task = Bean::load('workbenchtask', $id);
            if (!$task->id)                                        { $missing[] = $id; continue; }
            if (!$this->access->canDelete($this->member->id, $task)) { $refused[] = $id; continue; }
            try {
                $subtasks += $this->purgeTask($task);
                $deleted[] = $id;
            } catch (\Throwable $e) {
                // Name it rather than folding it into a silent count.
                $this->logger->error('Bulk delete failed for a task', ['task_id' => $id, 'error' => $e->getMessage()]);
                $refused[] = $id;
            }
        }

        $this->logger->info('Bulk delete', ['member_id' => $this->member->id, 'deleted' => $deleted, 'refused' => $refused]);
        Flight::jsonSuccess([
            'deleted'  => $deleted,
            'refused'  => $refused,
            'missing'  => $missing,
            'subtasks' => $subtasks,
        ], sprintf('Deleted %d task(s)%s%s',
            count($deleted),
            $subtasks ? " and {$subtasks} subtask(s)" : '',
            $refused ? ' — ' . count($refused) . ' refused' : ''
        ));
    }

    /**
     * Everything deleting a task actually entails, in one place.
     *
     * Not just a row: a task owns a running agent, an nginx proxy file, a workspace clone
     * of ~144MB, its logs/snapshots/comments, and — if it is a plan parent — an
     * orchestrator and a whole subtask chain. Bulk delete must do all of it too, and a
     * second copy of this list would drift the moment either changed.
     *
     * @return int subtasks removed alongside it
     */
    /** A board task's run in the container: its id (AgentTask) and its tmux session there. */
    private function boardRunId($task): string { return 'board-' . (int) $task->id; }

    /** A task's run id in its container: its worktree branch without "task/" (plan-1-task-2, board-7); '' = never launched. */
    private function runIdOf($task): string {
        $b = (string) ($task->worktreeBranch ?? '');
        return str_starts_with($b, 'task/') ? substr($b, 5) : '';
    }
    private function boardSession(object $ct, $task): string { return 'tiknix-' . $ct->slug . '-board' . (int) $task->id; }

    /**
     * Run a board task IN THE PROJECT'S CONTAINER — the same path as a plan's subtasks: the app's
     * own agent and credential, a worktree on task/board-<id> there (clitool --agent-task, in a
     * TenantRun session). Nothing merges until it is approved.
     */
    private function runInContainer($task, object $ct): void {
        $id = $this->boardRunId($task);
        $brief = "# Task #{$task->id}: " . trim((string) $task->title) . "\n\n" . trim((string) $task->description) . "\n";
        foreach (Bean::find('taskcomment', 'task_id = ? ORDER BY created_at ASC', [(int) $task->id]) as $c) {
            $brief .= "\n## Comment\n" . trim((string) $c->content) . "\n";
        }
        $brief .= "\nMake the change in this app, with its conventions (CLAUDE.md). Commit nothing yourself — your edits are committed on the task branch for review.\n";
        try {
            // A previous attempt's branch goes first: a rerun starts from the app as it is now.
            \app\TenantHost::discardTask($ct, $id);
            // On the agent picked for the task ('' = the app's default), in a sandbox holding a copy
            // of the app's data — or none of it, when the task was created with "An empty database".
            $how = \app\TenantHost::agentArg(\app\PlanIngestor::agentName($task->agent ?? ''))
                 . ' --timeout=1800' . ((string) $task->dbSource === 'fresh' ? ' --sandbox=fresh' : '');
            \app\TenantRun::start($ct, $this->boardSession($ct, $task), $id, '--agent-task=' . escapeshellarg($id) . $how, $brief,
                \app\TenantHost::author((int) $this->member->id));   // its commits are the member's who ran it
        } catch (\Throwable $e) {
            Flight::jsonError("Could not start the task in {$ct->slug}'s container: " . $e->getMessage(), 502);
            return;
        }
        $task->status = 'running';
        $task->agentSession = $this->boardSession($ct, $task);
        $task->tmuxSession = null;
        $task->worktreeBranch = 'task/' . $id;
        $task->branchName = 'task/' . $id;
        $task->runCount = (int) $task->runCount + 1;
        $task->startedAt = date('Y-m-d H:i:s');
        $task->errorMessage = null;
        $task->progressMessage = "Running in {$ct->slug}'s container";
        $task->updatedAt = date('Y-m-d H:i:s');
        Bean::store($task);
        $this->logTaskEvent((int) $task->id, 'info', 'system', "Started in {$ct->slug}'s container on task/{$id} (the app's own agent)");
        Flight::json(['success' => true, 'message' => "Started in {$ct->slug}'s container", 'status' => 'running']);
    }

    /**
     * A running container board task whose session has ended: read its result. Changes wait for
     * approval (awaiting, with the diffstat); no changes resolve it; anything else fails it.
     */
    private function syncContainerTask($task, object $ct): void {
        if ($task->status !== 'running' || (string) $task->agentSession === '' || !empty($task->parentTaskId)) return;
        try {
            if (\app\TenantRun::alive($ct, (string) $task->agentSession)) return;
            $run = \app\TenantRun::result($ct, $this->boardRunId($task));
        } catch (\RuntimeException $e) { return; }   // unreachable: ask again on the next poll
        $r = $run['result'] ?? null;
        $task->agentSession = null;
        $task->completedAt = date('Y-m-d H:i:s');
        $task->updatedAt = date('Y-m-d H:i:s');
        // What the agent had to check its work with — a sandbox, or none and why (AgentTask::sandbox).
        if ($r !== null && !empty($r['sandbox'])) $this->logTaskEvent((int) $task->id, str_starts_with((string) $r['sandbox'], 'none') ? 'warning' : 'info', 'agent', 'Sandbox: ' . $r['sandbox']);
        if ($r !== null && !empty($r['output'])) $this->logTaskEvent((int) $task->id, 'info', 'agent', 'Agent output (tail): ' . mb_substr((string) $r['output'], -1500));
        // What the agent proposed for the project's notebook (its `## Notebook` section): kept with
        // the task and added when the work is approved and merged — not before, since work that is
        // declined should leave no lesson behind.
        if ($r !== null) $task->notebookJson = json_encode(\app\Notebook::parse((string) ($r['output'] ?? '')));
        // What the run cost, kept on the task (app\RunStats) — a board task is measured like a plan's.
        if ($r !== null && is_array($r['stats'] ?? null)) {
            $total = \app\RunStats::add(json_decode((string) ($task->statsJson ?? ''), true) ?: [], $r['stats']);
            $task->statsJson = json_encode($total);
            $this->logTaskEvent((int) $task->id, 'info', 'agent', 'This run: ' . \app\RunStats::line(\app\RunStats::add([], $r['stats'])));
        }
        $status = (string) ($r['status'] ?? '');
        if ($status === 'changed') {
            $task->status = 'awaiting';
            $task->progressMessage = 'Changed on task/' . $this->boardRunId($task) . ' in the container — approve to merge.';
            $this->logTaskEvent((int) $task->id, 'success', 'system', "Changed ({$r['commit']}):\n" . ($r['diffstat'] ?? ''));
        } elseif ($status === 'no-change') {
            $task->status = 'resolved';
            $task->progressMessage = 'Nothing to change — the agent made no edits.';
            \app\TenantHost::discardTask($ct, $this->boardRunId($task));
        } else {
            $task->status = 'failed';
            $why = $r === null ? ($run === null ? 'its session ended before it finished' : "it exited {$run['exit']} without an answer: " . mb_substr((string) $run['log'], -500))
                               : (string) ($r['error'] ?? "ended '{$status}'");
            $task->errorMessage = $why;
            $task->progressMessage = 'Failed: ' . $why;
            \app\TenantHost::discardTask($ct, $this->boardRunId($task));
            $this->logTaskEvent((int) $task->id, 'error', 'system', 'Container task failed: ' . $why);
        }
        Bean::store($task);
    }

    /** The selected project when it runs in its own container (its tasks run there — TenantRun), else null. */
    private function tenantInst(): ?object {
        $id = (int) ($this->selected['id'] ?? 0);
        $inst = $id ? $this->access->instanceMeta($id) : null;
        return ($inst && trim((string) ($inst->ctIp ?? '')) !== '') ? $inst : null;
    }

    private function purgeTask($task): int {
        $taskId = (int) $task->id;
            if ($task->testServerSession) {
                TmuxManager::kill($task->testServerSession);
            }

            // Delete proxy file for nginx subdomain routing
            if (!empty($task->proxyFile) && file_exists($task->proxyFile)) {
                unlink($task->proxyFile);
            }


            // Delete related records with cascade
            $logs = $task->xownTasklogList;
            $snapshots = $task->xownTasksnapshotList;
            $comments = $task->xownTaskcommentList;

            // If this task is a plan parent, deleting it removes the WHOLE chain:
            // stop its orchestrator, then cascade-delete every subtask (and each
            // subtask's own logs/snapshots/comments + any running agent session).
            //
            // Scoped by the task's own project. The bare name killed plan <id>'s
            // orchestrator in EVERY project, so deleting a finished plan 26 here
            // stopped a live plan 26 building somewhere else. PlanOrchestrator::stop
            // also covers a session still running under the pre-rename name.
            PlanOrchestrator::stop($taskId, (string) (strstr((string)($task->instanceTag ?? ''), '.', true) ?: ''));
            $subtaskCount = 0;
            foreach (Bean::find('workbenchtask', 'parent_task_id = ?', [$taskId]) as $sub) {
                if (!empty($sub->agentSession)) {
                    if ($ct = $this->tenantInst()) {
                        // In the container: end the agent there and drop its branch, or it keeps
                        // working on a plan that no longer exists.
                        try {
                            \app\TenantRun::kill($ct, (string) $sub->agentSession);
                            $branch = (string) $sub->worktreeBranch;
                            if (str_starts_with($branch, 'task/')) {
                                $d = \app\TenantHost::discardTask($ct, substr($branch, 5));
                                if (empty($d['ok'])) $this->logger->error('Purge: could not discard the container branch', ['branch' => $branch, 'error' => $d['error'] ?? '']);
                            }
                        } catch (\RuntimeException $e) {
                            $this->logger->error('Purge: could not stop the container task', ['session' => (string) $sub->agentSession, 'error' => $e->getMessage()]);
                        }
                    } else {
                        TmuxManager::kill((string) $sub->agentSession);
                    }
                }
                $sub->xownTasklogList;
                $sub->xownTasksnapshotList;
                $sub->xownTaskcommentList;
                Bean::trash($sub);
                $subtaskCount++;
            }

            Bean::trash($task);

        // No flash and no redirect here: this is the WORK, and its two callers report it
        // differently — one redirects a browser, the other answers JSON for a bulk action.
        return $subtaskCount;
    }

    public function delete($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        $taskId = (int)$this->getParam('id');
        if (!$taskId) {
            Flight::redirect('/workbench');
            return;
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            $this->flash('error', 'Task not found');
            Flight::redirect('/workbench');
            return;
        }

        if (!$this->access->canDelete($this->member->id, $task)) {
            $this->flash('error', 'Access denied');
            Flight::redirect('/workbench/view?id=' . $taskId);
            return;
        }

        try {
            // Kill any running sessions
            // Everything this entails lives in purgeTask(), shared with bulk delete.
            $instanceTag  = (string) ($task->instanceTag ?? '');
            $subtaskCount = $this->purgeTask($task);

            $this->logger->info('Task deleted', ['task_id' => $taskId, 'member_id' => $this->member->id, 'subtasks' => $subtaskCount]);

            $this->flash('success', $subtaskCount > 0 ? "Deleted the plan and {$subtaskCount} subtask(s)" : 'Task deleted');
            Flight::redirect('/workbench' . ($instanceTag !== '' ? '?instance_tag=' . urlencode($instanceTag) : ''));

        } catch (Exception $e) {
            $this->logger->error('Failed to delete task', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to delete task');
            Flight::redirect('/workbench/view?id=' . $taskId);
        }
    }

    /**
     * Run task - start Claude runner
     */
    public function run($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        // Validate CSRF for AJAX requests
        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }
        if (!$this->requireAgent(true)) return;

        $taskId = (int)$this->getParam('id');
        if (!$taskId) {
            Flight::jsonError('Task ID required', 400);
            return;
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            Flight::jsonError('Task not found', 404);
            return;
        }

        if (!$this->access->canRun($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        // A PLAN PARENT HAS NO WORK OF ITS OWN. Its subtasks carry the work and merge
        // individually; the parent is a header. Running it starts an agent with nothing
        // to do, which then sits at its prompt while the parent reads `running` for ever
        // — and the plan looks unfinished even though every subtask already merged.
        //
        // pd plan 4 went that way: built and fully merged on 17 Aug, then Run seventeen
        // hours later left it `running` with `plan_status` still `done`. The approve path
        // already refuses a plan for the same reason; this one did not.
        if (empty($task->parentTaskId) && !empty($task->planStatus)) {
            Flight::jsonError('This is a plan, not a task — build it from the plan view. '
                . 'Its subtasks do the work; the parent has none to run.', 409);
            return;
        }

        if ($this->refusePlanSubtask($task)) return;

        // A project in its own container builds there (runInContainer), never on core.
        if ($ct = $this->tenantInst()) { $this->runInContainer($task, $ct); return; }
        Flight::jsonError('This project is not running in its own container — tasks build only in a project\'s container.', 409);
    }

    /**
     * A PLAN SUBTASK IS RUN BY ITS PLAN'S BUILD, and by nothing else. The build launches it in
     * dependency order on its own run (plan-<p>-task-<n>), collects the result and merges it. Run
     * here started a SECOND, board run (board-<n>) of the same task: on holistica the board run
     * finished and committed 1,400 lines that nothing collected, while the build looked for its
     * own run's result, found none and marked the task failed. True = refused (answered).
     */
    private function refusePlanSubtask($task): bool {
        if (empty($task->parentTaskId)) return false;
        Flight::jsonError('This task is part of a plan, and the plan\'s build runs it — in order, and merging it when it is done. '
            . ($task->status === 'pending' ? 'Press Build on the plan (if it is already building, this task starts when its turn comes).'
                                            : 'Use Retry on this task: it puts the task back in the plan\'s build.'), 409);
        return true;
    }

    /**
     * Re-run a completed or failed task
     */
    public function rerun($params = []) {
        if (!$this->requireLogin()) return;
        if (Flight::request()->method !== 'POST' || !SimpleCsrf::validate()) { Flight::jsonError('CSRF validation failed', 403); return; }
        if (!$this->requireAgent(true)) return;
        $task = Bean::load('workbenchtask', (int) $this->getParam('id'));
        if (!$task->id) { Flight::jsonError('Task not found', 404); return; }
        if (!$this->access->canRun($this->member->id, $task)) { Flight::jsonError('Access denied', 403); return; }
        if (empty($task->parentTaskId) && !empty($task->planStatus)) { Flight::jsonError('This is a plan, not a task — build it from the plan view.', 409); return; }
        if (in_array($task->status, ['running', 'queued'], true)) { Flight::jsonError('The task is already running.', 409); return; }
        if ($this->refusePlanSubtask($task)) return;
        if (!($ct = $this->tenantInst())) { Flight::jsonError('This project is not running in its own container — tasks build only in a project\'s container.', 409); return; }
        // Again, from the app as it is now: runInContainer discards the previous attempt's branch.
        $this->runInContainer($task, $ct);
    }

    /**
     * Approve task - merge PR and mark complete
     * Only admins can approve non-admin member tasks
     */
    public function approve($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id) {
            Flight::jsonError('Task not found', 404);
            return;
        }

        // A plan PARENT has no branch of its own — its subtasks merge into the
        // instance's live branch individually. Merging the parent as a task is a
        // no-op that corrupts its status (status='merged' while plan_status stays
        // 'draft'). Route plan parents through planapprove/planbuild instead.
        if (empty($task->parentTaskId) && !empty($task->planStatus)) {
            Flight::jsonError('This is a plan — approve or build it from the workbench list, not the task merge flow.', 409);
            return;
        }

        // Only admins can approve
        if ($this->member->level > LEVELS['ADMIN']) {
            Flight::jsonError('Only admins can approve tasks', 403);
            return;
        }

        // Task must be in awaiting or completed status
        if (!in_array($task->status, ['awaiting', 'completed'])) {
            Flight::jsonError('Task is not ready for approval', 400);
            return;
        }

        if ($ct = $this->tenantInst()) {
            // Merging IS publishing: the task branch into the app in its container (seeds run).
            $branch = (string) $task->worktreeBranch;
            if (!str_starts_with($branch, 'task/')) { Flight::jsonError('This task has no branch in the container to merge.', 409); return; }
            try {
                $m = \app\TenantHost::mergeTask($ct, substr($branch, 5), \app\TenantHost::author((int) $this->member->id));   // the approver's merge
            } catch (\RuntimeException $e) {
                $m = ['ok' => false, 'error' => $e->getMessage()];
            }
            // The work went in but something after it did not (a seed): the task IS merged — its
            // branch is gone, and "awaiting" with a Merge failed line left the owner pressing
            // Approve on something that could not be merged twice. Said loudly, once, below.
            $after = (empty($m['ok']) && (string) ($m['merged'] ?? '') !== '') ? (string) ($m['error'] ?? 'a step after the merge failed') : '';
            if (empty($m['ok']) && $after === '') {
                $err = (string) ($m['error'] ?? 'the merge failed');
                $this->logTaskEvent((int) $task->id, 'error', 'system', 'Merge failed: ' . $err);
                Flight::jsonError('Merge failed: ' . $err, 409);
                return;
            }
            if ($after !== '') $this->logTaskEvent((int) $task->id, 'error', 'system', 'Merged into the app as ' . $m['merged'] . ', but a step after the merge failed — the code is live, and this needs looking at: ' . $after);
            $task->status = 'merged';
            $task->completedAt = date('Y-m-d H:i:s');
            $task->updatedAt = date('Y-m-d H:i:s');
            $task->progressMessage = 'Merged into the app as ' . ($m['merged'] ?? '?');
            Bean::store($task);
            // The task's notebook entries go in with its work, as the member approving it.
            $entries = json_decode((string) ($task->notebookJson ?? ''), true);
            if (is_array($entries) && $entries) {
                try { $nb = \app\TenantHost::notebookAdd($ct, $entries, 'task #' . (int) $task->id, \app\TenantHost::author((int) $this->member->id)); }
                catch (\RuntimeException $e) { $nb = ['ok' => false, 'error' => $e->getMessage()]; }
                $this->logTaskEvent((int) $task->id, empty($nb['ok']) ? 'warning' : 'info', 'system', empty($nb['ok'])
                    ? 'Notebook: ' . count($entries) . ' entr' . (count($entries) === 1 ? 'y' : 'ies') . ' not added — ' . (string) ($nb['error'] ?? 'the app did not answer')
                    : 'Notebook: ' . (int) ($nb['added'] ?? 0) . ' added' . (!empty($nb['skipped']) ? ', ' . (int) $nb['skipped'] . ' already there' : '') . ":\n" . implode("\n", array_map(fn($e) => "- {$e['kind']}: {$e['text']}", $entries)));
            }
            $this->logTaskEvent((int) $task->id, 'success', 'system', 'Merged into the app in its container as ' . ($m['merged'] ?? '?'));
            Flight::json(['success' => true, 'message' => $after === '' ? 'Merged into the app' : 'Merged into the app — but a step after the merge failed: ' . $after]);
            return;
        }
        Flight::jsonError('This project is not running in its own container — tasks build only in a project\'s container.', 409);
    }

    /**
     * Decline task - close PR and send back for revision
     */
    public function decline($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id) {
            Flight::jsonError('Task not found', 404);
            return;
        }

        // Only admins can decline
        if ($this->member->level > LEVELS['ADMIN']) {
            Flight::jsonError('Only admins can decline tasks', 403);
            return;
        }

        // Task must be in awaiting or completed status
        if (!in_array($task->status, ['awaiting', 'completed'])) {
            Flight::jsonError('Task is not ready for review', 400);
            return;
        }

        $reason = trim($this->getParam('reason', ''));

        try {
            // Close PR if exists
            if (!empty($task->prUrl) && !empty($task->prNumber)) {
                try {
                    $github = $this->getGitHubService($task);
                    if ($github) {
                        // Add decline comment
                        if ($reason) {
                            $github->addComment(
                                (int)$task->prNumber,
                                "**Changes requested**\n\n{$reason}\n\n_Declined via Tiknix Workbench_"
                            );
                        }
                        // Close the PR
                        $github->closePullRequest((int)$task->prNumber);
                    }
                } catch (Exception $e) {
                    $this->logger->warning('Failed to close PR', [
                        'task_id' => $taskId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Reset task to pending for revision
            $task->status = 'pending';
            $task->prUrl = null;
            $task->prNumber = null;
            $task->reviewedBy = $this->member->id;
            $task->reviewedAt = date('Y-m-d H:i:s');
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            // Add decline reason as comment
            if ($reason) {
                $comment = Bean::dispense('taskcomment');
                $comment->taskId = $taskId;
                $comment->memberId = $this->member->id;
                $comment->content = "**Changes Requested:**\n\n{$reason}";
                $comment->createdAt = date('Y-m-d H:i:s');
                Bean::store($comment);
            }

            $this->logTaskEvent($taskId, 'warning', 'review',
                'Task declined by ' . ($this->member->displayName ?? $this->member->email) .
                ($reason ? ": {$reason}" : '')
            );

            Flight::json([
                'success' => true,
                'message' => 'Task declined and sent back for revision'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to decline task', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to decline: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get GitHub service for a task
     *
     * @param object $task Task bean
     * @return GitHubService|null
     */
    private function getGitHubService(object $task): ?GitHubService {
        if ($task->teamId) {
            $team = Bean::load('team', $task->teamId);
            $github = GitHubService::fromTeam($team);
            if ($github) {
                return $github;
            }
        }

        return GitHubService::fromConfig();
    }

    /**
     * Pause running task
     */
    public function pause($params = []) {
        if (!$this->requireLogin()) return;

        // Validate CSRF for AJAX requests
        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canRun($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        if ($task->status !== 'running') {
            Flight::jsonError('Task is not running', 400);
            return;
        }

        $task->status = 'paused';
        $task->updatedAt = date('Y-m-d H:i:s');
        Bean::store($task);

        $this->logTaskEvent($taskId, 'info', 'system', 'Task paused');

        Flight::json(['success' => true, 'message' => 'Task paused']);
    }

    /**
     * Resume paused task
     */
    public function resume($params = []) {
        if (!$this->requireLogin()) return;

        // Validate CSRF for AJAX requests
        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canRun($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        if ($task->status !== 'paused') {
            Flight::jsonError('Task is not paused', 400);
            return;
        }

        $task->status = 'running';
        $task->updatedAt = date('Y-m-d H:i:s');
        Bean::store($task);

        $this->logTaskEvent($taskId, 'info', 'system', 'Task resumed');

        Flight::json(['success' => true, 'message' => 'Task resumed']);
    }

    /**
     * Stop running task
     */
    public function stop($params = []) {
        if (!$this->requireLogin()) return;

        // Validate CSRF for AJAX requests
        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canRun($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        if (!in_array($task->status, ['running', 'queued', 'paused'])) {
            Flight::jsonError('Task is not active', 400);
            return;
        }

        if ($ct = $this->tenantInst()) {
            try {
                if ((string) $task->agentSession !== '') \app\TenantRun::kill($ct, (string) $task->agentSession);
                \app\TenantHost::discardTask($ct, $this->boardRunId($task));
            } catch (\RuntimeException $e) {
                Flight::jsonError("Could not stop the task in {$ct->slug}'s container: " . $e->getMessage(), 502);
                return;
            }
            $task->status = 'pending';
            $task->agentSession = null;
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);
            $this->logTaskEvent($taskId, 'warning', 'system', "Task stopped by user (ended in {$ct->slug}'s container, its branch discarded)");
            Flight::json(['success' => true, 'message' => 'Task stopped']);
            return;
        }
        Flight::jsonError('This project is not running in its own container — tasks build only in a project\'s container.', 409);
    }

    /**
     * Start test server for a task's branch
     * Creates a tmux session running server.php on the assigned port
     * Initializes workspace environment with fresh database for testing
     */
    public function startserver($params = []) {
        if (!$this->requireLogin()) return;
        Flight::jsonError('The local preview server is not available — projects build in their own containers: run, review (Diff) and approve the task instead.', 409);
    }

    /**
     * Stop test server for a task
     *
     */
    public function stopserver($params = []) {
        if (!$this->requireLogin()) return;
        Flight::jsonError('The local preview server is not available — projects build in their own containers: run, review (Diff) and approve the task instead.', 409);
    }

    /**
     * GET /workbench/console?id= — read-only tmux pane capture of the task's live
     * worker session. Returned as raw text; the view paints it into a <pre> via
     * textContent, so any <script>/HTML in the agent output is inert. Polled while
     * the task is active. Manual runs use tmux_session; plan subtasks use
     * agent_session — both live on the default tmux socket.
     */
    public function console($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id || !$this->access->canView($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        $session = (string)($task->tmuxSession ?: $task->agentSession ?: '');
        $lines   = max(50, min(4000, (int)$this->getParam('lines', 1500)));
        if ($ct = $this->tenantInst()) {
            // The task runs in the project's container, headless — no screen. What there IS, live,
            // is the agent's transcript: TenantRun::activity reads its tail (what it said, each
            // tool it called). A plan shows every subtask that is running; a task shows its own.
            $isPlan = empty($task->parentTaskId) && !empty($task->planStatus);
            $runs = [];   // run id => heading
            if ($isPlan) {
                foreach (Bean::find('workbenchtask', "parent_task_id = ? AND status IN ('running', 'queued') ORDER BY id", [(int) $task->id]) as $sub) {
                    if ($rid = $this->runIdOf($sub)) $runs[$rid] = '#' . (int) $sub->id . ' ' . (string) $sub->title;
                }
            } elseif ($rid = $this->runIdOf($task)) {
                $runs[$rid] = '';
            }
            try { $act = $runs ? \app\TenantRun::activity($ct, array_keys($runs), $isPlan ? 14 : 80) : []; }
            catch (\RuntimeException $e) { Flight::jsonError($e->getMessage(), 502); return; }
            $content = ''; $newest = 0;
            foreach ($runs as $rid => $heading) {
                $a = $act[$rid] ?? ['lines' => [], 'at' => '', 'found' => false];
                if ($heading !== '') $content .= "━━ {$heading} ━━\n";
                $content .= $a['found'] ? ($a['lines'] ? implode("\n", $a['lines']) : '(the agent has started; nothing said yet)') : '(no transcript yet — the agent is starting)';
                $content .= "\n\n";
                if ($a['at'] !== '') $newest = max($newest, (int) strtotime($a['at']));
            }
            if (!$runs) $content = $isPlan ? "No subtask is running right now.\n" : '';
            $running = in_array((string) $task->status, ['running', 'queued'], true);
            Flight::jsonSuccess([
                'session' => $session,
                // "live" = the task is running and its transcript moved in the last two minutes
                'alive'   => $running && $runs && $newest > 0 && time() - $newest < 120,
                'content' => rtrim($content) . "\n",
                'status'  => $task->status,
                'quiet'   => $running && $newest > 0 ? time() - $newest : null,
            ]);
            return;
        }
        $alive   = $session !== '' && TmuxManager::exists($session);

        Flight::jsonSuccess([
            'session' => $session,
            'alive'   => $alive,
            'content' => $alive ? TmuxManager::capture($session, $lines) : '',
            'status'  => $task->status,
        ]);
    }

    /**
     * Get task progress (AJAX polling)
     */
    public function progress($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canView($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        if ($ct = $this->tenantInst()) $this->syncContainerTask($task, $ct);

        $progress = [
            'status' => $task->status,
            'run_count' => $task->runCount,
            'started_at' => $task->startedAt,
            'completed_at' => $task->completedAt,
            'branch_name' => $task->branchName,
            'pr_url' => $task->prUrl,
            'error_message' => $task->errorMessage
        ];

        // If running, get live progress from tmux

        // Get latest snapshot
        $snapshot = Bean::findOne('tasksnapshot', 'task_id = ? ORDER BY created_at DESC', [$taskId]);
        if ($snapshot) {
            $progress['snapshot'] = [
                'type' => $snapshot->snapshotType,
                'content' => $snapshot->content,
                'timestamp' => $snapshot->createdAt
            ];
        }

        // Get recent logs
        $logs = Bean::find('tasklog', 'task_id = ? ORDER BY created_at DESC LIMIT 10', [$taskId]);
        $progress['recent_logs'] = array_map(function($log) {
            return [
                'level' => $log->logLevel,
                'type' => $log->logType,
                'message' => $log->message,
                'timestamp' => $log->createdAt
            ];
        }, $logs);

        // Plan-managed subtasks run under PlanExecutor (a jailed `claude -p` agent in
        // a worktree), not ClaudeRunner — so $task->tmuxSession is empty and the block
        // above yields no 'live'. Read that agent's streaming log to show what it is
        // CURRENTLY doing. Read-only: it never writes the bean, so it can't race the
        // executor that owns this task's status.
        $isPlanManaged = !empty($task->planRef) || !empty($task->worktreeBranch)
            || TmuxManager::isPlanSession((string)$task->agentSession);
        // In a container the agent's "what now" is its live transcript (TenantRun::activity, the
        // same source the Live Console reads): planAgentActivity reads a host log that no longer
        // exists, so the card said "Starting up…" for the whole run.
        if (in_array($task->status, ['running', 'queued'], true) && empty($progress['live']) && ($ct = $this->tenantInst()) && ($rid = $this->runIdOf($task))) {
            try {
                $a = \app\TenantRun::activity($ct, [$rid], 9)[$rid] ?? null;
                if ($a && $a['found']) {
                    $clean = fn(string $l) => trim(preg_replace('/^\[[0-9:]*\]\s*(→\s*)?/u', '', $l));
                    $quiet = $a['at'] !== '' ? time() - strtotime($a['at']) : null;
                    $progress['live'] = [
                        'status'       => $quiet !== null && $quiet > 120 ? 'Quiet for ' . round($quiet / 60) . ' min' : 'Working',
                        'current_task' => $a['lines'] ? $clean((string) end($a['lines'])) : 'Started — nothing said yet',
                    ];
                    $progress['recent_logs'] = array_map(fn($l) => ['level' => str_contains($l, '✗') ? 'error' : 'info', 'type' => 'activity', 'message' => $clean($l), 'timestamp' => ''],
                        array_reverse(array_slice($a['lines'], 0, -1)));
                }
            } catch (\RuntimeException $e) {
                $this->logger->error('Workbench: could not read the agent\'s activity', ['task' => (int) $task->id, 'err' => $e->getMessage()]);
                $progress['live'] = ['status' => 'Unknown', 'current_task' => 'The container did not answer: ' . $e->getMessage()];
            }
        }
        if (in_array($task->status, ['running', 'queued'], true) && $isPlanManaged && empty($progress['live'])) {
            $act = $this->planAgentActivity($task);
            if ($act['current'] !== null || $act['running']) {
                $cur = $act['current'];
                $progress['live'] = [
                    'status'        => $act['running'] ? 'Working' : 'Finishing up…',
                    'current_task'  => $cur ? trim($cur['verb'] . ' ' . $cur['target']) : 'Starting up…',
                    'files_changed' => $act['files'],
                ];
                if (!empty($act['recent'])) {
                    // newest-first, to match the DB recent_logs the UI expects
                    $progress['recent_logs'] = array_map(fn($a) => [
                        'level' => 'info', 'type' => 'activity',
                        'message' => trim($a['verb'] . ' ' . $a['target']), 'timestamp' => '',
                    ], array_reverse($act['recent']));
                }
            }
        }

        // Recent comments for live updates. Same fault as view() had and fixed the
        // same way: no JOIN to member (it is core's table, not workbench.db) and no
        // column named that fluid mode may not have created. Both silently returned
        // an empty set, so the live panel agreed with the page — wrongly.
        $comments = $this->withCommentAuthors(Bean::getAll(
            "SELECT * FROM taskcomment WHERE task_id = ? ORDER BY created_at ASC",
            [$taskId]
        ));
        $progress['comments'] = array_map(function($c) {
            $author = $c['is_from_claude'] ? 'Claude' :
                      (trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')) ?:
                      ($c['username'] ?? 'Unknown'));
            return [
                'id' => $c['id'],
                'author' => $author,
                'is_from_claude' => (bool)$c['is_from_claude'],
                'content' => $c['content'],
                'image_path' => $c['image_path'] ?? null,
                'created_at' => $c['created_at']
            ];
        }, $comments);

        Flight::json($progress);
    }

    /**
     * View full task output
     */
    public function output($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canView($this->member->id, $task)) {
            $this->flash('error', 'Access denied');
            Flight::redirect('/workbench');
            return;
        }

        $this->viewData['title'] = 'Task Output - ' . $task->title;
        $this->viewData['task'] = $task;
        $this->viewData['output'] = $task->lastOutput;

        $this->render('workbench/output', $this->viewData);
    }

    /**
     * Add comment to task
     */
    public function comment($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        // Validate CSRF for AJAX requests
        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canComment($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        $content = trim($this->getParam('content', ''));
        if (empty($content)) {
            Flight::jsonError('Comment content required', 400);
            return;
        }

        try {
            $comment = Bean::dispense('taskcomment');
            $comment->taskId = $taskId;
            $comment->memberId = $this->member->id;
            $comment->content = $content;
            $comment->isInternal = (int)$this->getParam('is_internal', 0);
            $comment->createdAt = date('Y-m-d H:i:s');
            Bean::store($comment);

            $sentToSession = false;

            // A comment becomes part of the task's brief on its next Run (runInContainer).

            Flight::json([
                'success' => true,
                'sent_to_session' => $sentToSession,
                'comment' => [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'author' => $this->member->displayName ?? $this->member->email,
                    'avatar_url' => $this->member->avatarUrl,
                    'created_at' => $comment->createdAt
                ]
            ]);

        } catch (\Throwable $e) {
            // Throwable, not Exception: a missing class is an Error, and it escaped this
            // catch as a bare 500 with nothing in this sidecar's log (2026-09-23).
            $this->logger->error('Workbench: comment failed', ['task_id' => $taskId, 'err' => get_class($e) . ': ' . $e->getMessage()]);
            Flight::jsonError('Failed to add comment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Upload an image to a task comment
     * Supports both standalone image uploads and image+text comments
     */
    public function uploadimage($params = []) {
        if (!$this->requireLogin()) return;
        Flight::jsonError('Attaching an image to the agent is not available — projects build in their own containers: run, review (Diff) and approve the task instead.', 409);
    }

    /**
     * Delete a comment from a task
     */
    public function deletecomment($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::jsonError('POST required', 405);
            return;
        }

        if (!SimpleCsrf::validate()) {
            Flight::jsonError('CSRF validation failed', 403);
            return;
        }

        $taskId = (int)$this->getParam('id');
        $commentId = (int)$this->getParam('comment_id');

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id || !$this->access->canEdit($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        $comment = Bean::load('taskcomment', $commentId);
        if (!$comment->id || $comment->taskId != $taskId) {
            Flight::jsonError('Comment not found', 404);
            return;
        }

        try {
            Bean::trash($comment);
            $this->logTaskEvent($taskId, 'info', 'user', 'Comment deleted');

            Flight::json([
                'success' => true,
                'message' => 'Comment deleted'
            ]);
        } catch (Exception $e) {
            Flight::jsonError('Failed to delete comment', 500);
        }
    }

    /**
     * View task logs
     */
    /**
     * GET /workbench/notebook — the selected project's notebook (the app's app\Notebook): what its
     * builders are handed before every task and add to after it. Read from the project's own
     * repository in its container; ?doc= picks which document is open in the editor.
     */
    public function notebook($params = []) {
        if (!$this->requireLogin()) return;
        $ct = $this->tenantInst();
        $this->viewData['title'] = 'Notebook';
        $this->viewData['docs'] = null; $this->viewData['titles'] = []; $this->viewData['notebookError'] = '';
        $this->viewData['doc'] = (string) $this->getParam('doc', 'lessons');
        if (!$ct) {
            $this->viewData['notebookError'] = $this->selected ? 'This project is not running in its own container, so it has no notebook to show.' : 'Choose a project first — a notebook belongs to one project.';
        } else {
            try { $r = \app\TenantHost::notebook($ct); } catch (\RuntimeException $e) { $r = ['ok' => false, 'error' => $e->getMessage()]; }
            if (empty($r['ok'])) {
                // An app on a runtime older than the notebook answers with no JSON: say that, not "empty".
                $this->viewData['notebookError'] = 'The project did not answer about its notebook: ' . (string) ($r['error'] ?? 'no answer') . ' (it needs runtime alpha.135 or newer — it updates itself when no build is running).';
            } else {
                $this->viewData['docs'] = (array) $r['docs'];
                $this->viewData['titles'] = (array) $r['titles'];
                if (!isset($this->viewData['docs'][$this->viewData['doc']])) $this->viewData['doc'] = (string) array_key_first($this->viewData['docs']);
            }
        }
        $this->viewData['projectName'] = (string) ($this->selected['name'] ?? $this->selected['slug'] ?? '');
        $this->render('workbench/notebook', $this->viewData);
    }

    /** POST /workbench/notebooksave — save one notebook document as edited; committed in the project as you. */
    public function notebooksave($params = []) {
        if (!$this->requireLogin()) return;
        if (Flight::request()->method !== 'POST') { Flight::redirect('/workbench/notebook'); return; }
        $doc = (string) $this->getParam('doc', '');
        $back = '/workbench/notebook?doc=' . rawurlencode($doc);
        if (!Flight::csrf()->validateRequest()) { $this->flash('error', 'Invalid CSRF token'); Flight::redirect($back); return; }
        $ct = $this->tenantInst();
        if (!$ct || !$this->access->canAccessInstance((int) $this->member->id, (int) $ct->id)) { $this->flash('error', 'Choose a project you can work on first.'); Flight::redirect('/workbench/notebook'); return; }
        try { $r = \app\TenantHost::notebookSet($ct, $doc, (string) $this->getParam('text', ''), \app\TenantHost::author((int) $this->member->id)); }
        catch (\RuntimeException $e) { $r = ['ok' => false, 'error' => $e->getMessage()]; }
        if (empty($r['ok'])) { $this->flash('error', 'Not saved: ' . (string) ($r['error'] ?? 'the project did not answer') . '.'); Flight::redirect($back); return; }
        $this->logger->info('Notebook edited', ['slug' => (string) $ct->slug, 'doc' => $doc, 'member_id' => (int) $this->member->id]);
        $this->flash('success', 'Saved. Every task and plan from now on is handed this version.');
        Flight::redirect($back);
    }

    /**
     * GET /workbench/prompts — everything you have asked this system to build.
     *
     * Lives HERE rather than in core because all three things it records are build
     * surfaces: the goal you decompose and the task you write are this sidecar's own
     * forms, and the Terminal is its other tab. Core's nav is where you pick a project;
     * this is where you work on one.
     *
     * It spans EVERY project, though, not just the selected one — a member's prompt
     * history is theirs, and the moment you scope it to the current project it stops
     * being the record of how the whole system got built. See app\PromptLog, which keeps
     * the rows in core's db for exactly that reason.
     */
    public function prompts($params = []) {
        if (!$this->requireLogin()) return;

        $memberId = (int) $this->member->id;

        // Pull in anything typed at the Terminal since the last look. Harvesting on view
        // keeps it current with no cron, and it is idempotent (each turn carries a uuid).
        try {
            $h = \app\PromptLog::harvestTerminal($memberId);
            // A write that FAILED is the case that matters: without saying so, the page
            // shows a short list and reads as "you have not written many prompts".
            if (!empty($h['failed'])) {
                $this->logger->error('Terminal prompt harvest could not write', [
                    'failed' => $h['failed'], 'error' => $h['error'], 'member_id' => $memberId,
                ]);
                $this->viewData['harvestError'] = $h['failed'] . ' terminal prompt(s) could not be saved: ' . $h['error'];
            }
        } catch (\Throwable $e) {
            $this->logger->error('Terminal prompt harvest failed', ['error' => $e->getMessage(), 'member_id' => $memberId]);
            $this->viewData['harvestError'] = $e->getMessage();
        }

        $source = (string) $this->getParam('source', '');
        $q      = trim((string) $this->getParam('q', ''));

        /* Scope to the project you are working on. This listed every project you own, so a
           partsdna goal sat next to a collectiq one with nothing but a small tag to tell
           them apart — and the buttons beside them act on whichever project is selected,
           not the one the row came from. ?all=1 is the deliberate way to see everything. */
        $inst = $this->selected ? $this->access->instanceMeta((int) $this->selected['id']) : null;
        $selectedTag = ($inst && $inst->id) ? $inst->slug . '.' . ($inst->app ?: 'tiknix') : '';
        $showAll     = (string) $this->getParam('all', '') === '1';
        $scopeTag    = $showAll ? '' : $selectedTag;

        $rows = \app\PromptLog::forMember($memberId, $source, 500, $scopeTag);
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                return mb_strpos(mb_strtolower((string) $r->body), $needle) !== false
                    || mb_strpos(mb_strtolower((string) $r->title), $needle) !== false;
            }));
        }

        $this->viewData['rows']    = $rows;
        $this->viewData['counts']  = \app\PromptLog::countsForMember($memberId, $scopeTag);
        $this->viewData['sources'] = \app\PromptLog::sources();
        $this->viewData['source']  = $source;
        $this->viewData['q']       = $q;
        // Which project you are on, so a prompt from THIS project can link straight to the
        // plan it became — a plan id only resolves inside its own instance's db.
        $this->viewData['selectedTag'] = $selectedTag;
        $this->viewData['showAll']     = $showAll;
        /* Goals waiting their turn. One planner runs per project, so firing several
           decomposes queues them rather than losing them — but nothing showed the queue,
           so "it did nothing" was indistinguishable from "it is third in line". */
        $this->viewData['queued'] = \app\PromptQueue::queued($memberId, $scopeTag);

        $this->render('workbench/prompts', ['title' => 'Prompts']);
    }

    /**
     * POST /workbench/promptunqueue — stop retrying a queued decompose. JSON.
     *
     * Leaves the prompt in the log; it simply stops waiting for its turn. Ownership is
     * re-checked through PromptLog::find, which takes the member id and has no "load any
     * prompt" mode — dequeue() alone takes only an id, and nobody else's queue is yours
     * to empty.
     */
    public function promptunqueue($params = []) {
        if (!$this->planActionGuard()) return;   // login + POST + CSRF

        $promptId = (int) $this->getParam('prompt_id', 0);
        $p = $promptId > 0 ? \app\PromptLog::find($promptId, (int) $this->member->id) : null;
        if (!$p) { Flight::jsonError('No such prompt.', 404); return; }

        \app\PromptQueue::dequeue($promptId);
        Flight::jsonSuccess(['id' => $promptId], 'Removed from the queue.');
    }

    /**
     * POST /workbench/promptrerun — decompose a goal that never produced a plan. JSON.
     *
     * decompose() records the prompt BEFORE starting the planner, deliberately, so the
     * ask survives a planner that never runs. The commonest way it never runs is the
     * refusal in PlanRunner::start — "a planner is already running for this instance" —
     * which happens exactly when you fire a decompose while an ad-hoc task is mid-flight.
     * Nothing retried it afterwards, so the goal sat in the log while unrelated branches
     * kept building, and the only recovery was to find the text and paste it again.
     *
     * This is that retry, from the stored goal, reproducing the original straight-through
     * choice rather than quietly downgrading it to a draft.
     */
    public function promptrerun($params = []) {
        if (!$this->planActionGuard()) return;   // login + POST + CSRF
        if (!$this->requireAgent(true)) return;

        $promptId = (int) $this->getParam('prompt_id', 0);
        $p = $promptId > 0 ? \app\PromptLog::find($promptId, (int) $this->member->id) : null;
        if (!$p) { Flight::jsonError('No such prompt.', 404); return; }
        if ((string) $p['source'] !== \app\PromptLog::SOURCE_DECOMPOSE) {
            Flight::jsonError('Only a decompose goal can be re-run.', 409); return;
        }
        if (!empty($p['plan_uid'])) {
            Flight::jsonError('This goal already produced a plan — open it from the board instead.', 409); return;
        }

        // Resolve the project from the tag recorded WITH the prompt, not from whatever is
        // selected now: you may well be looking at a different project by the time you
        // notice the decompose never fired.
        $tag  = (string) $p['instance_tag'];
        $slug = (string) strstr($tag, '.', true) ?: $tag;
        $app  = ltrim((string) strstr($tag, '.'), '.') ?: 'tiknix';
        $inst = $this->access->instanceBySlug($slug, $app);
        if (!$inst || !$inst->id || !$this->access->canAccessInstance((int)$this->member->id, (int)$inst->id)) {
            Flight::jsonError('That project is no longer available to you (' . $tag . ').', 409); return;
        }

        $dir = \app\WorkbenchDb::dirOf($slug, $app);
        // A project in its own container: its app's agent, checked with the app (as decompose does).
        $tenant = \app\TenantBuilder::bySlug($slug);
        if ($tenant) {
            if ($why = $this->tenantAgentProblem($tenant, '')) { Flight::jsonError('The planner cannot run: ' . $why . '.', 409); return; }
        } else {
            if (!is_file($dir . '/public/index.php')) {
                Flight::jsonError('That project is not on disk any more.', 409); return;
            }
            if (!$this->agentSignedIn($dir, (string) ($inst->engine ?? ''))) {
                Flight::jsonError('This project has not signed in to Claude yet, so the planner cannot run.', 409); return;
            }
        }

        try {
            $runner = new PlanRunner($slug, $dir, (int)$this->member->id,
                (int)$this->member->level, (string)($inst->engine ?? ''));
            if ($tenant) $runner->useAgent('');
            // The same refusal that stranded it in the first place. Say so plainly —
            // "try again when that finishes" is actionable; a generic failure is not.
            if ($runner->running()) {
                Flight::jsonError('A planner is already running for ' . $tag . ' — try again when it finishes.', 409);
                return;
            }
            $runner->start((string) $p['body'], [], !empty($p['auto_build']), $promptId);
        } catch (\Throwable $e) {
            $this->logger->error('Prompt re-run failed', ['prompt' => $promptId, 'error' => $e->getMessage()]);
            Flight::jsonError('Could not start the planner: ' . $e->getMessage(), 500);
            return;
        }

        $this->logger->info('Prompt re-run started', [
            'prompt' => $promptId, 'instance' => $tag, 'auto_build' => !empty($p['auto_build']),
        ]);
        Flight::jsonSuccess(
            ['instance' => $tag, 'auto_build' => !empty($p['auto_build'])],
            'Decomposing again for ' . $tag . (!empty($p['auto_build'])
                ? ' — it will approve itself and build when the plan lands.'
                : ' — the plan will appear on the board shortly.')
        );
    }

    public function logs($params = []) {
        if (!$this->requireLogin()) return;

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canView($this->member->id, $task)) {
            $this->flash('error', 'Access denied');
            Flight::redirect('/workbench');
            return;
        }

        $level = $this->getParam('level');
        $type = $this->getParam('type');

        $sql = 'task_id = ?';
        $params = [$taskId];

        if ($level) {
            $sql .= ' AND log_level = ?';
            $params[] = $level;
        }

        if ($type) {
            $sql .= ' AND log_type = ?';
            $params[] = $type;
        }

        $sql .= ' ORDER BY created_at DESC';

        $logs = Bean::find('tasklog', $sql, $params);

        $this->viewData['title'] = 'Task Logs - ' . $task->title;
        $this->viewData['task'] = $task;
        $this->viewData['logs'] = $logs;
        $this->viewData['filterLevel'] = $level;
        $this->viewData['filterType'] = $type;

        $this->render('workbench/logs', $this->viewData);
    }

    /**
     * Log a task event
     */
    private function logTaskEvent(int $taskId, string $level, string $type, string $message, array $context = []): void {
        try {
            $log = Bean::dispense('tasklog');
            $log->taskId = $taskId;
            $log->memberId = $this->member->id ?? null;
            $log->logLevel = $level;
            $log->logType = $type;
            $log->message = $message;
            $log->contextJson = !empty($context) ? json_encode($context) : null;
            $log->createdAt = date('Y-m-d H:i:s');
            Bean::store($log);
        } catch (Exception $e) {
            $this->logger->error('Failed to log task event', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Diff summary of a task's branch vs its base (numstat), for the review UI.
     * Read-only. Returns null when there's no workspace/branch/changes, else
     * ['files'=>[['path','added','removed','binary'],...],'total_files','added','removed','base'].
     */
    private function taskDiffStat($task): ?array {
        // The task's branch is in the project's container: numstat it there (main...task/<id>).
        $ct = $this->tenantInst();
        $br = (string) $task->worktreeBranch;
        if (!$ct || !str_starts_with($br, 'task/')) return null;
        try {
            [$c, $o] = \app\TenantHost::ssh($ct, 'app', 'cd /srv/app && git rev-parse --verify -q ' . escapeshellarg($br) . ' >/dev/null && git diff --numstat main...' . escapeshellarg($br), null, 30);
        } catch (\RuntimeException $e) { return null; }
        if ($c !== 0) return null;
        $files = []; $addT = 0; $remT = 0;
        foreach (explode("\n", trim((string) $o)) as $line) {
            $p = explode("\t", $line);
            if (count($p) < 3) continue;
            $binary  = ($p[0] === '-');
            $added   = $binary ? 0 : (int) $p[0];
            $removed = ($p[1] === '-') ? 0 : (int) $p[1];
            $files[] = ['path' => $p[2], 'added' => $added, 'removed' => $removed, 'binary' => $binary];
            $addT += $added; $remT += $removed;
        }
        if (!$files) return null;
        return ['files' => $files, 'total_files' => count($files), 'added' => $addT, 'removed' => $remT, 'base' => 'main'];
    }

    /** GET /workbench/diff?id= — full patch of a task's branch vs base, for review. */
    public function diff($params = []) {
        if (!$this->requireLogin()) return;
        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id || !$this->access->canView($this->member->id, $task)) {
            $this->flash('error', 'Access denied');
            Flight::redirect('/workbench');
            return;
        }
        if ($ct = $this->tenantInst()) {
            // The task's branch is in the app's container: diff it there (main...task/<id>).
            $br = (string) $task->worktreeBranch;
            $patch = ''; $note = '';
            if (!str_starts_with($br, 'task/')) {
                $note = 'This task has no branch in the container (not run yet, or merged / discarded).';
            } else {
                [$c, $o] = \app\TenantHost::ssh($ct, 'app', 'cd /srv/app && git rev-parse --verify -q ' . escapeshellarg($br) . ' >/dev/null && { echo ---PATCH---; git diff main...' . escapeshellarg($br) . ' | head -c 500001; } || echo NOBRANCH', null, 60);
                if ($c !== 0) $note = "Could not read the diff from {$ct->slug}'s container: " . trim((string) $o);
                elseif (trim((string) $o) === 'NOBRANCH') $note = "No branch {$br} in the container — it may have been merged or discarded.";
                else {
                    $patch = (string) substr((string) $o, (int) strpos((string) $o, "---PATCH---\n") + 12);
                    if (strlen($patch) > 500000) { $patch = substr($patch, 0, 500000); $note = 'Diff truncated (very large).'; }
                    elseif (trim($patch) === '') $note = 'No changes on this branch.';
                }
            }
            $this->viewData['title'] = 'Diff — ' . $task->title;
            $this->viewData['task']  = $task;
            $this->viewData['patch'] = $patch;
            $this->viewData['note']  = $note;
            $this->viewData['stat']  = $this->taskDiffStat($task);
            $this->render('workbench/diff', $this->viewData);
            return;
        }
        $this->viewData['title'] = 'Diff — ' . $task->title;
        $this->viewData['task']  = $task;
        $this->viewData['patch'] = '';
        $this->viewData['note']  = 'This project is not running in its own container — its tasks have no branch to diff.';
        $this->viewData['stat']  = null;
        $this->render('workbench/diff', $this->viewData);
    }

    /**
     * Get task types
     */
    private function getTaskTypes(): array {
        return [
            'feature' => ['label' => 'Feature', 'icon' => 'plus-lg', 'color' => 'primary'],
            'bugfix' => ['label' => 'Bug Fix', 'icon' => 'bug', 'color' => 'danger'],
            'refactor' => ['label' => 'Refactor', 'icon' => 'arrow-repeat', 'color' => 'info'],
            'security' => ['label' => 'Security', 'icon' => 'shield-lock', 'color' => 'warning'],
            'docs' => ['label' => 'Documentation', 'icon' => 'file-text', 'color' => 'secondary'],
            'test' => ['label' => 'Test', 'icon' => 'check2-square', 'color' => 'success']
        ];
    }

    /**
     * Get priority levels
     */
    private function getPriorities(): array {
        return [
            1 => ['label' => 'Critical', 'color' => 'danger'],
            2 => ['label' => 'High', 'color' => 'warning'],
            3 => ['label' => 'Medium', 'color' => 'info'],
            4 => ['label' => 'Low', 'color' => 'secondary']
        ];
    }

    /**
     * Get or create a workbench API key for the member
     *
     * Creates an API key specifically for Claude workspace workers to access
     * tiknix MCP tools (check_flightphp, check_redbean, etc.)
     *
     * @param int $memberId Member ID
     * @return string|null API key token or null if creation failed
     */
    private function getOrCreateWorkbenchApiKey(int $memberId): ?string {
        $keyName = 'Workbench Auto-Key';

        // IN CORE'S DATABASE, not this sidecar's ambient one.
        //
        // This ran on the default connection, which in the sidecar is the INSTANCE's
        // data/workbench.db — but /mcp/message lives in core and validates the bearer
        // against CORE's apikey table. So every task agent was handed a token that existed
        // nowhere the endpoint could see it: the key "created" fine, the config looked
        // right, and every mcp__tiknix__* call failed with "Authentication required".
        // Agents noticed and said so ("complete_task wasn't available in this session"),
        // which is why finished tasks sat at 'running' forever.
        return \app\CoreDb::with(function () use ($memberId, $keyName) {
            $existing = Bean::findOne('apikey',
                'member_id = ? AND name = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > ?)',
                [$memberId, $keyName, date('Y-m-d H:i:s')]
            );
            if ($existing && $existing->id) return (string) $existing->token;

            $key = Bean::dispense('apikey');
            $key->memberId       = $memberId;
            $key->name           = $keyName;
            $key->token          = 'tk_' . bin2hex(random_bytes(32));
            $key->scopes         = json_encode(['mcp:tools']);   // MCP tools only
            $key->allowedServers = json_encode([]);              // all servers
            $key->isActive       = 1;
            $key->expiresAt      = date('Y-m-d H:i:s', strtotime('+1 year'));
            $key->createdAt      = date('Y-m-d H:i:s');
            $key->usageCount     = 0;
            Bean::store($key);

            $this->logger->info('Created workbench API key in core db', [
                'member_id' => $memberId, 'key_id' => $key->id,
            ]);
            return (string) $key->token;
        }, null) ?: (function () use ($memberId) {
            // Loud: without a key every agent tool call in the workspace will 401.
            $this->logger->error('Could not mint a workbench API key', [
                'member_id' => $memberId, 'error' => \app\CoreDb::lastError(),
            ]);
            return null;
        })();
    }

    /**
     * Attach author details to comments, read from CORE.
     *
     * The comments live in the project's workbench.db and the people live in
     * core's database, so this cannot be a JOIN — that is exactly what was
     * silently returning nothing. Two queries against two databases, which is what
     * this sidecar already does everywhere else.
     *
     * An author who cannot be resolved keeps their comment: losing somebody's
     * message because their row is gone would be a worse answer than showing it
     * unattributed, and the view already falls back to "Unknown".
     */
    private function withCommentAuthors(array $comments): array {
        if (!$comments) return [];

        $ids = array_values(array_unique(array_filter(array_map(
            fn($c) => (int) ($c['member_id'] ?? 0), $comments))));

        $people = [];
        if ($ids) {
            try {
                $pdo = \app\Sidecar\Kernel::coreDb();
                if ($pdo) {
                    $st = $pdo->prepare('SELECT id, first_name, last_name, username, email, avatar_url
                                           FROM member WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
                    $st->execute($ids);
                    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $m) $people[(int) $m['id']] = $m;
                }
            } catch (\Throwable $e) {
                // Said out loud: nameless comments are a symptom somebody would
                // otherwise report as "the conversation looks broken".
                Flight::get('log')?->error('workbench: could not read comment authors from core',
                    ['err' => $e->getMessage()]);
            }
        }

        foreach ($comments as &$c) {
            $m = $people[(int) ($c['member_id'] ?? 0)] ?? [];
            $c['first_name'] = $m['first_name'] ?? '';
            $c['last_name']  = $m['last_name']  ?? '';
            $c['username']   = $m['username']   ?? '';
            $c['email']      = $m['email']      ?? '';
            $c['avatar_url'] = $m['avatar_url'] ?? '';
            // Fluid schema: neither column exists until something first writes one
            // — image_path until an image is attached, is_from_claude until Claude
            // replies — so both are absent on a young instance and both are read
            // unguarded by the view and the live panel.
            $c['image_path']     = $c['image_path'] ?? '';
            $c['is_from_claude'] = (int) ($c['is_from_claude'] ?? 0);
        }
        unset($c);

        return $comments;
    }

    /** The selected project's install directory, which owns its connections. */
    /** The selected project's Get-started hand-off, while it still has something to say; else null. */
    private function handoffState(): ?array {
        if (!$this->selected) return null;
        $iid = (int) $this->selected['id']; $mid = (int) $this->member->id;
        $st = \app\CoreDb::with(function () use ($iid, $mid) {
            $h = \app\Bean::findOne('planhandoff', 'instance_ref = ? AND member_ref = ? ORDER BY id DESC', [$iid, $mid]);
            if (!$h || !$h->id) return null;
            // Waiting for the project's agent: ask the project now (the board refreshes every few
            // seconds), so Phase 1 starts right after the sign-in — not up to a minute later when
            // the cron backstop (tenant.php --handoff-pending) gets to it.
            if ((string) $h->progress === 'waiting-agent' && !empty($h->decompose)) \app\PlanHandoff::startPhaseOne($h);
            return \app\PlanHandoff::state($h) + ['token' => (string) $h->token];
        });
        if (!$st || !in_array($st['progress'], ['setting-up', 'plan-committed', 'waiting-agent', 'planning', 'failed'], true)) return null;
        // Once Phase 1's tasks are on the board, the board says the rest itself.
        if ($st['progress'] === 'planning' && Bean::count('workbenchtask') > 0) return null;
        return $st + ['core' => rtrim((string) Flight::get('sidecar.core_url'), '/')];
    }

}
