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
use \app\ClaudeRunner;
use \app\PromptBuilder;
use \app\GitService;
use \app\PortManager;
use \app\TmuxManager;
use \app\PlanRunner;
use \app\PlanExecutor;
use \app\PlanOrchestrator;
use \app\WorkspaceManager;
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
        $filters = [
            'status' => $this->getParam('status'),
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

        // Drives the 'Import from monday.com' button: shown only when the
        // selected project has a live connection.
        $this->viewData['hasMonday'] = $this->hasMonday();

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
        $this->viewData['prefill'] = ['title' => '', 'body' => ''];
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
                    $this->viewData['prefill'] = [
                        'title' => (string) ($one['title'] ?? ''),
                        'body'  => (string) ($one['body'] ?? ''),
                    ];
                }
            }
        }

        // Pre-select team if specified
        $preselectedTeamId = $this->getParam('team_id');

        // Get user's teams
        $teams = $this->access->getMemberTeams($this->member->id);

        // Get available branches from git (only remote branches - local-only won't work for cloning)
        $gitService = new GitService();
        $branchData = $gitService->getBranches();
        $currentBranch = $gitService->getCurrentBranch();

        // Use remote branches only - local-only branches can't be used as base for new workspaces
        $remoteBranches = $branchData['remote'];
        if (empty($remoteBranches)) {
            $remoteBranches = ['main']; // Fallback
        }

        $this->viewData['teams'] = $teams;
        $this->viewData['preselectedTeamId'] = $preselectedTeamId;
        /* Engine+model pairs, and which one is preselected. The default is the PROJECT's
           engine at its worker tier — the thing that would have run anyway — so the picker
           changes what you can choose without changing what happens if you ignore it. */
        $this->viewData['runChoices'] = \app\EngineRegistry::runMenu();
        $projectEngine = \app\EngineRegistry::defaultEngine();
        if ($this->selected) {
            $dir = \Model_Instance::dirFrom((string) $this->selected['slug'], (string) ($this->selected['app'] ?? ''));
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
        $this->viewData['authcontrolLevels'] = $this->getAuthcontrolLevels();
        $this->viewData['memberLevel'] = $this->member->level;
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
        // A project nobody has signed in for cannot build. Say so on the form, where the
        // decision to write a spec is being made, rather than after it is submitted.
        $projDir = '/var/www/html/default/' . $this->selected['slug'] . '.' . ($this->selected['app'] ?: 'tiknix');
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
     * Store new task
     */
    public function store($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') {
            Flight::redirect('/workbench');
            return;
        }

        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Invalid CSRF token');
            Flight::redirect('/workbench/create');
            return;
        }

        // Validate required fields
        $title = trim($this->getParam('title', ''));
        if (empty($title)) {
            $this->flash('error', 'Task title is required');
            Flight::redirect('/workbench/create');
            return;
        }

        // Get team ID (null = personal task)
        $teamId = $this->getParam('team_id');
        if ($teamId === '' || $teamId === 'personal') {
            $teamId = null;
        } else {
            $teamId = (int)$teamId;
            // Verify membership
            if (!$this->access->isTeamMember($teamId, $this->member->id)) {
                $this->flash('error', 'You are not a member of this team');
                Flight::redirect('/workbench/create');
                return;
            }
        }

        // Validate authcontrol level (must be >= member's level)
        $authcontrolLevel = (int)$this->getParam('authcontrol_level', $this->member->level);
        if ($authcontrolLevel < $this->member->level) {
            $authcontrolLevel = $this->member->level; // Can't assign higher privilege than you have
        }

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
            $task->description = trim($this->getParam('description', ''));
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
            $task->authcontrolLevel = $authcontrolLevel;
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
            $task->acceptanceCriteria = trim($this->getParam('acceptance_criteria', ''));
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
            $body = trim($this->getParam('description', ''));
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
        $goal = trim($this->getParam('description', ''));
        if ($goal === '') { $goal = trim($this->getParam('title', '')); }
        if (mb_strlen($goal) < 20) {
            $this->flash('error', 'Add a goal document (drop a .md or write a spec — at least 20 characters) to decompose.');
            Flight::redirect('/workbench/create');
            return;
        }

        $slug = (string)$instance->slug;
        $app  = $instance->app ?: 'tiknix';
        $instanceDir = '/var/www/html/default/' . $slug . '.' . $app;
        if (!is_file($instanceDir . '/public/index.php')) {
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

        // Say it BEFORE spending five minutes failing at it.
        if (!$this->agentSignedIn($instanceDir, $runEngine)) {
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

        $instanceDir = '/var/www/html/default/' . $slug . '.' . $app;
        if (!is_file($instanceDir . '/public/index.php')) { Flight::jsonError('That instance is not available on disk.', 409); return; }

        try {
            $runner = new PlanRunner(
                $slug, $instanceDir, (int)$this->member->id,
                (int)$this->member->level, (string)($inst->engine ?? '')
            );
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

    /** POST /workbench/planapprove — approve a plan (task-chain) so it can be built. JSON. */
    public function planapprove($params = []) {
        if (!$this->planActionGuard()) return;
        $pi = $this->accessiblePlan($this->getParam('plan_id', 0));
        if (!$pi) { Flight::jsonError('No such plan', 404); return; }
        [$plan] = $pi;
        if ($plan->planStatus === 'building') { Flight::jsonError('This plan is already building.', 409); return; }
        $plan->planStatus = 'approved';
        $plan->updatedAt  = date('Y-m-d H:i:s');
        Bean::store($plan);
        Flight::jsonSuccess(['plan_status' => 'approved'], 'Plan approved — ready to build.');
    }

    /** POST /workbench/planbuild — launch the worktree orchestrator for an approved plan. JSON. */
    public function planbuild($params = []) {
        if (!$this->planActionGuard()) return;
        $pi = $this->accessiblePlan($this->getParam('plan_id', 0));
        if (!$pi) { Flight::jsonError('No such plan', 404); return; }
        [$plan, $inst] = $pi;
        if (!$inst) { Flight::jsonError('This plan has no linked instance to build in.', 409); return; }
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
        $dir   = '/var/www/html/default/' . $inst->slug . '.' . ($inst->app ?: 'tiknix');
        $check = (new PlanExecutor((int) $plan->id, (string) $inst->slug, $dir, (int) $this->member->level))
                    ->progressCheck();
        if ($check['ready'] === 0 && $check['running'] === 0) {
            $why = $check['roots']
                ? implode('; ', $check['roots'])
                : 'no subtask is ready and none is running';
            Flight::jsonError('This plan cannot start: ' . $why
                . '. Reset or re-run those subtasks first — a subtask left in "awaiting" '
                . 'is never picked up by a build.', 409);
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
        $dir = '/var/www/html/default/' . $inst->slug . '.' . ($inst->app ?: 'tiknix');
        return PlanOrchestrator::launch(
            (int) $plan->id, (string) $inst->slug, $dir, (int) $this->member->level
        );
    }

    /**
     * POST /workbench/taskretry — recover a failed plan subtask: reset it to pending
     * (fresh auto-retry budget), re-open the plan, and re-launch the orchestrator,
     * which rebuilds the task and auto-corrects known blockers toward completion. JSON.
     */
    public function taskretry($params = []) {
        if (!$this->planActionGuard()) return;

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
        $inst = $plan->instanceId ? $this->access->instanceMeta((int)$plan->instanceId) : null;
        if (!$inst || !$inst->id) { Flight::jsonError('This plan has no linked instance.', 409); return; }
        if (PlanOrchestrator::running((int)$plan->id, (string)$inst->slug)) {
            Flight::jsonError('This plan is already building — the task will be picked up in that run.', 409);
            return;
        }

        // Reset the task for a fresh attempt (fresh auto-retry budget).
        $task->status       = 'pending';
        $task->errorMessage = '';
        $task->retryCount   = 0;
        $task->updatedAt    = date('Y-m-d H:i:s');
        Bean::store($task);

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
        if (!$pi) { Flight::jsonError('No such plan', 404); return; }
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
        if (!$pi) { Flight::jsonError('No such plan', 404); return; }
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

        $dir = '/var/www/html/default/' . $instance->slug . '.' . ($instance->app ?: 'tiknix');
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
        $dir      = \Model_Instance::dirFrom((string) $inst->slug, (string) ($inst->app ?? ''));
        $activity = null;
        if ($running) {
            $runner   = new PlanRunner((string) $inst->slug, $dir, (int) $this->member->id,
                                       (int) $this->member->level, (string) ($inst->engine ?? ''));
            $activity = $runner->activity();
        }
        Flight::jsonSuccess([
            'running'        => $running,
            'newest_plan_id' => $newest,
            'activity'       => $activity,
        ]);
    }

    /** Absolute path to a plan subtask's executor agent log (stream-json), or '' if unknown. */
    private function agentLogPath($task): string {
        $inst = $task->instanceId ? $this->access->instanceMeta((int)$task->instanceId) : null;
        if (!$inst || !$inst->id) return '';
        $dir = '/var/www/html/default/' . $inst->slug . '.' . ($inst->app ?: 'tiknix');
        return $dir . '/.aibuilder/wt/task-' . (int)$task->id . '/.aibuilder/agent.log';
    }

    /**
     * Parse the executor agent's stream-json log into "what is it doing now".
     * Returns {current: {verb,target}|null, recent: [...], files: [...],
     * running: bool, finished: bool}. Pure read — never mutates anything.
     */
    private function planAgentActivity($task): array {
        $out = ['current' => null, 'recent' => [], 'files' => [], 'finished' => false,
                'running' => ($task->agentSession && TmuxManager::exists((string)$task->agentSession))];
        $log = $this->agentLogPath($task);
        if ($log === '' || !is_file($log)) return $out;
        // Bound the read: only the tail matters, and logs can get large.
        $lines = @file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (count($lines) > 400) $lines = array_slice($lines, -400);
        $acts = $files = [];
        foreach ($lines as $ln) {
            $ln = ltrim($ln);
            if ($ln === '' || $ln[0] !== '{') continue;   // skip the [agent] header lines
            $ev = json_decode(($ln) ?? '', true);
            if (!is_array($ev)) continue;
            $type = $ev['type'] ?? '';
            if ($type === 'result') { $out['finished'] = true; continue; }
            if ($type !== 'assistant') continue;
            foreach (($ev['message']['content'] ?? []) as $c) {
                $ct = $c['type'] ?? '';
                if ($ct === 'tool_use') {
                    $d = $this->describeToolUse((string)($c['name'] ?? ''), (array)($c['input'] ?? []));
                    $acts[] = $d;
                    if (in_array($d['verb'], ['Editing', 'Writing'], true) && $d['target'] !== '') {
                        $files[$d['target']] = true;
                    }
                } elseif ($ct === 'text' && trim((string)($c['text'] ?? '')) !== '') {
                    $acts[] = ['verb' => 'Thinking', 'target' => $this->firstLine((string)$c['text'])];
                }
            }
        }
        if ($acts) {
            $out['current'] = end($acts);
            $out['recent']  = array_slice($acts, -8);
        }
        $out['files'] = array_slice(array_keys($files), -8);
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
        // a tiknix-<slug>-plan<N>-task<M> session), NOT by ClaudeRunner. Never let this
        // view's poller touch them — its ClaudeRunner session name won't match, so
        // exists() returns false and it would race the executor by force-failing a
        // live subtask.
        $isPlanManaged = !empty($task->planRef)
            || !empty($task->worktreeBranch)
            || TmuxManager::isPlanSession((string)$task->agentSession);
        if (!$isPlanManaged && $task->status === 'running') {
            $workspacePath = $task->projectPath ?: Flight::get('project_root');
            $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);

            if ($runner->exists()) {
                $tmuxStatus = $runner->detectStatus();

                // Map detected status to display message
                $statusMessages = [
                    'determining' => 'Determining next action...',
                    'thinking' => 'Thinking...',
                    'processing' => 'Processing...',
                    'analyzing' => 'Analyzing code...',
                    'exploring' => 'Exploring codebase...',
                    'searching' => 'Searching...',
                    'reading' => 'Reading files...',
                    'writing' => 'Writing code...',
                    'executing' => 'Executing tools...',
                    'working' => 'Working...',
                    'waiting' => 'Waiting for user input',
                ];

                if ($tmuxStatus === 'waiting') {
                    // User's turn
                    $task->status = 'awaiting';
                    $task->progressMessage = 'Waiting for user input';
                    $task->updatedAt = date('Y-m-d H:i:s');
                    Bean::store($task);
                } elseif (isset($statusMessages[$tmuxStatus])) {
                    // Update progress message but keep status as running
                    $task->progressMessage = $statusMessages[$tmuxStatus];
                    $task->updatedAt = date('Y-m-d H:i:s');
                    Bean::store($task);
                }
            } else {
                /* The agent's tmux session is gone while the task still read `running`.
                   Recorded in errorMessage as well as progressMessage: the task view renders
                   the error, so writing only a progress line left a task marked failed with
                   nothing on screen saying why — indistinguishable from a task that failed
                   inside the agent. Says what to do about it, because this one is almost
                   always recoverable: nothing was lost but the session. */
                $task->status = 'failed';
                $task->progressMessage = 'Session ended unexpectedly';
                $task->errorMessage = 'The agent session ended before this task reported a result — '
                    . 'usually the terminal bridge restarting, the jail being killed, or the session '
                    . 'being reaped. Any work the agent committed is still in its branch. Press Run to '
                    . 'start it again.';
                $task->updatedAt = date('Y-m-d H:i:s');
                Bean::store($task);
            }
        }

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
                $planRollup = ['total' => count($subs), 'done' => $done, 'counts' => $counts];
            }
        }
        $this->viewData['planRollup'] = $planRollup;

        $this->viewData['title'] = $task->title;
        $this->viewData['task'] = $task;
        $this->viewData['logs'] = $logs;
        $this->viewData['comments'] = $comments;
        $this->viewData['latestSnapshot'] = $latestSnapshot;
        $this->viewData['team'] = $team;
        $this->viewData['creator'] = $creator;
        $this->viewData['canEdit'] = $this->access->canEdit($this->member->id, $task);
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

        // Get user's teams
        $teams = $this->access->getMemberTeams($this->member->id);

        // Get available branches from git (only if task hasn't been run yet)
        // Only show remote branches - local-only branches can't be used as base for new workspaces
        $branches = [];
        $currentBranch = 'main';
        if (empty($task->branchName)) {
            $gitService = new GitService();
            $branchData = $gitService->getBranches();
            $branches = $branchData['remote'];
            if (empty($branches)) {
                $branches = ['main']; // Fallback
            }
            $currentBranch = $gitService->getCurrentBranch();
            if (!in_array($currentBranch, $branches)) {
                $currentBranch = 'main';
            }
        }

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
        $this->viewData['teams'] = $teams;
        $this->viewData['taskTypes'] = $this->getTaskTypes();
        $this->viewData['priorities'] = $this->getPriorities();
        $this->viewData['authcontrolLevels'] = $this->getAuthcontrolLevels();
        $this->viewData['memberLevel'] = $this->member->level;
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

        // Validate authcontrol level (must be >= member's level)
        $authcontrolLevel = (int)$this->getParam('authcontrol_level', $task->authcontrolLevel ?? $this->member->level);
        if ($authcontrolLevel < $this->member->level) {
            $authcontrolLevel = $this->member->level;
        }

        try {
            $task->title = $title;
            $task->description = trim($this->getParam('description', ''));
            $task->taskType = $this->getParam('task_type', 'feature');
            $task->priority = (int)$this->getParam('priority', 3);
            $task->authcontrolLevel = $authcontrolLevel;
            $task->acceptanceCriteria = trim($this->getParam('acceptance_criteria', ''));
            $task->relatedFiles = json_encode(array_filter(explode("\n", $this->getParam('related_files', ''))));
            $task->tags = json_encode(array_filter(array_map('trim', explode(',', $this->getParam('tags', '')))));

            // Only allow changing base branch if task hasn't been run yet
            if (empty($task->branchName)) {
                $task->baseBranch = trim($this->getParam('base_branch', $task->baseBranch ?? 'main'));
            }

            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'info', 'user', 'Task updated');

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
     * Imported backlogs (monday.com) leave rows nobody will ever run, and removing them one
     * page at a time is why they linger. Each task is checked individually — a bulk action
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
    private function purgeTask($task): int {
        $taskId = (int) $task->id;
            // Kill any running sessions
            if ($task->tmuxSession) {
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                if ($runner->exists()) {
                    $runner->kill();
                }
            }
            if ($task->testServerSession) {
                TmuxManager::kill($task->testServerSession);
            }

            // Delete proxy file for nginx subdomain routing
            if (!empty($task->proxyFile) && file_exists($task->proxyFile)) {
                unlink($task->proxyFile);
            }

            // Clean up workspace directory
            if (!empty($task->projectPath) && is_dir($task->projectPath)) {
                try {
                    $wsManager = new WorkspaceManager();
                    $wsManager->destroy($task->projectPath);
                    $this->logger->info('Workspace deleted', ['path' => $task->projectPath]);
                } catch (Exception $e) {
                    $this->logger->warning('Failed to delete workspace', ['error' => $e->getMessage()]);
                }
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
                if (!empty($sub->agentSession)) TmuxManager::kill((string)$sub->agentSession);
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

        // Check if already running
        if ($task->status === 'running') {
            Flight::jsonError('Task is already running', 400);
            return;
        }

        // Assign port for this member
        // Per TASK, not per member: one number for every task meant two concurrent
        // runs were both told 8002 and the second server could not bind.
        $portInfo = PortManager::getTaskPortInfo(
            $this->member->id, (string) $task->instanceTag, (int) $task->id);
        $assignedPort = $portInfo['port'];
        if (!$portInfo['available'] && $portInfo['fallback']) {
            $assignedPort = $portInfo['fallback'];
        }
        $task->assignedPort = $assignedPort;

        // Always create isolated workspace for tasks (safer for testing)
        $workspacePath = null;

        // Self-heal: if the task has a branch but its workspace was deleted (e.g. cleaned
        // up on a prior approve), the stored branch is stale and there is nothing to run
        // against — a null path would fall through to the main app and be rejected. Drop
        // the stale branch so a fresh workspace + branch get rebuilt from the instance.
        if (!empty($task->branchName) && (empty($task->projectPath) || !is_dir($task->projectPath . '/.git'))) {
            $this->logTaskEvent($taskId, 'info', 'system', 'Workspace was gone — rebuilding a fresh one from the instance.');
            $task->branchName = null;
        }

        if (empty($task->branchName)) {
            try {
                // Determine clone source + base branch. An instance-tagged task
                // builds against ITS instance repo (local), on the instance's own
                // branch, so changes land on that instance — not the main tiknix
                // repo. Non-instance tasks clone from main (GitHub) as before.
                $cloneUrl = null;   // null => main repo origin
                $baseBranch = $task->baseBranch ?: 'main';
                if (empty($task->baseBranch) && $task->teamId) {
                    $team = Bean::load('team', $task->teamId);
                    if ($team->defaultBranch) {
                        $baseBranch = $team->defaultBranch;
                    }
                }
                $instDir = $this->instanceDirForTask($task);
                if ($instDir !== null) {
                    // Instance task: build on TOP of the instance's live app, which lives
                    // on the instance repo's checked-out branch (e.g. instance/<slug>).
                    // That repo's 'main' is only the empty starter skeleton, and any
                    // base_branch picked on the create form came from the CONTROL-PLANE
                    // repo — meaningless here. So always base off the instance repo's
                    // current HEAD unless an explicit non-'main' branch that actually
                    // exists in the instance repo was chosen.
                    $cloneUrl = $instDir;
                    $head = trim((string)@shell_exec('git -C ' . escapeshellarg($instDir) . ' rev-parse --abbrev-ref HEAD 2>/dev/null'));
                    $useHead = $head !== '' && $head !== 'HEAD';
                    $stored = trim((string)$task->baseBranch);
                    if ($stored !== '' && strtolower($stored) !== 'main') {
                        $exists = trim((string)@shell_exec('git -C ' . escapeshellarg($instDir)
                            . ' rev-parse --verify --quiet ' . escapeshellarg($stored) . ' 2>/dev/null'));
                        if ($exists !== '') $useHead = false;   // honor a real, deliberate choice
                    }
                    if ($useHead) $baseBranch = $head;
                }

                // Clone repository into isolated workspace (clones the base branch)
                $mainGit = new GitService();
                // instanceTag scopes the workspace. Without it three projects' task
                // 26 share one directory and clone over each other — see
                // GitService::getWorkspacePath.
                $workspacePath = $mainGit->cloneToWorkspace(
                    $this->member->id, $task->id, $cloneUrl, $baseBranch, (string) $task->instanceTag);
                $task->projectPath = $workspacePath;

                $srcLabel = $instDir !== null ? ($task->instanceTag ?: 'instance') : 'main';
                $this->logTaskEvent($taskId, 'info', 'system', "Created workspace: {$workspacePath} (from {$srcLabel}:{$baseBranch})");

                // Create GitService for the workspace
                $gitService = new GitService($workspacePath);

                $branchName = GitService::generateBranchName(
                    $this->member->username ?? $this->member->email,
                    $task->id,
                    $task->title
                );

                // Create new branch from the cloned base branch
                $gitService->createBranch($branchName, $baseBranch);
                $task->branchName = $branchName;
                $task->baseBranch = $baseBranch; // Store the actual base branch used

                $this->logTaskEvent($taskId, 'info', 'system', "Created branch: {$branchName} from {$baseBranch}");

            } catch (Exception $e) {
                $this->logger->error('Failed to create workspace/branch', ['error' => $e->getMessage()]);
                Flight::jsonError('Failed to create workspace: ' . $e->getMessage(), 500);
                return;
            }
        } else if (!empty($task->projectPath)) {
            // Re-running a task - use existing workspace
            $workspacePath = $task->projectPath;
        }

        // Generate proxy hash if not exists (for subdomain routing)
        if (empty($task->proxyHash)) {
            $task->proxyHash = bin2hex(random_bytes(6)); // 12-char hex
            Bean::store($task);
            $this->logTaskEvent($taskId, 'info', 'system', "Generated proxy hash: {$task->proxyHash}");
        }

        // Initialize workspace with isolated database, config, and vendor
        if ($workspacePath && is_dir($workspacePath)) {
            try {
                // For an instance task set to use real data, seed the workspace DB from the
                // INSTANCE's OWN live DB (<instanceDir>/database/<slug>.db) — NOT the
                // control-plane tiknix.com DB — for higher-fidelity testing. The workspace
                // DB is gitignored, so this copy never merges back.
                $liveDbPath = null;
                $instDir = $this->instanceDirForTask($task);
                if ($instDir !== null && (string)($task->dbSource ?? 'live') !== 'fresh') {
                    $inst = $this->access->instanceMeta((int)$task->instanceId);
                    $cand = $instDir . '/database/' . $inst->slug . '.db';
                    if (is_file($cand)) $liveDbPath = $cand;
                }
                $wsManager = new WorkspaceManager();
                $wsInfo = $wsManager->initialize($workspacePath, $task->proxyHash, false, $liveDbPath);
                $this->logTaskEvent($taskId, 'info', 'system', "Initialized workspace: {$wsInfo['baseurl']}"
                    . ($liveDbPath ? ' (seeded from the instance\'s live data)' : ' (fresh database)'));
            } catch (Exception $e) {
                $this->logger->warning('Workspace initialization warning', ['error' => $e->getMessage()]);
                // Continue - workspace may still work without full initialization
            }

        }

        // Always regenerate .mcp.json at run time with current config
        // This ensures correct baseurl from config.ini and fresh API key
        if ($workspacePath && is_dir($workspacePath)) {
            /* Engine config, every run — same reason the MCP config is rewritten here rather
               than at clone time. The clone happens once, on a task's FIRST run; after that
               the task has a branch and every retry reuses the workspace. conf/*.ini is
               gitignored, so a clone never carries it and jail-run finds no [engine.<name>]:
               task #110 failed five times on 'no anthropic_base_url in [engine.zai]'. Doing
               it at clone time alone fixed new tasks and left 47 of 48 existing workspaces
               broken. */
            \app\GitService::ensureEngineConfig($workspacePath, (string) $task->instanceTag);

            // REFUSE, do not degrade. A worktree without its project's own MCP target is
            // the exact condition this change exists to prevent, so it stops the run.
            try {
                $this->writeProjectMcpConfig($workspacePath, $task, $taskId, 'Generated');
            } catch (\Throwable $e) {
                Flight::jsonError($e->getMessage(), 409);
                return;
            }
        }

        try {
            // Create Claude runner with workspace path (null = use main project)
            // Pass member level for security sandbox hook
            $runner = new ClaudeRunner($taskId, $this->member->id, $task->teamId, $workspacePath, $this->member->level);

            // Check if session already exists
            if ($runner->exists()) {
                Flight::jsonError('A session for this task is already active', 400);
                return;
            }

            // Spawn Claude interactively in tmux
            $success = $runner->spawn();

            if (!$success) {
                Flight::jsonError('Failed to start Claude session', 500);
                return;
            }

            // Update task status to queued
            $task->status = 'queued';
            $task->tmuxSession = $runner->getSessionName();
            $task->currentRunId = bin2hex(random_bytes(16));
            $task->runCount = ($task->runCount ?? 0) + 1;
            $task->lastRunnerMemberId = $this->member->id;
            $task->startedAt = date('Y-m-d H:i:s');
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            // Wait for Claude to initialize (give it time to start up)
            usleep(2000000); // 2 seconds

            // Build the prompt using PromptBuilder
            $prompt = PromptBuilder::build([
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'task_type' => $task->taskType,
                'acceptance_criteria' => $task->acceptanceCriteria,
                'related_files' => json_decode(((string)$task->relatedFiles) ?? '', true) ?: [],
                'tags' => json_decode(((string)$task->tags) ?? '', true) ?: [],
                'authcontrol_level' => $task->authcontrolLevel,
                'branch_name' => $task->branchName,
                'assigned_port' => $task->assignedPort,
                'project_path' => $task->projectPath,
                'proxy_hash' => $task->proxyHash,
            ]);

            // Send the prompt to Claude
            $promptSent = $runner->sendPrompt($prompt);

            if (!$promptSent) {
                /* STOP. sendPrompt() already checked the two things that matter — the text
                   landed, and the agent then started — and answered no. Logging a warning
                   and marking the task `running` anyway is how a session sat at an empty
                   prompt while the board said it was working: the runner was right and the
                   caller overruled it.

                   Left at `queued`, which is exactly what happened: the session exists, the
                   brief did not reach it. The session stays alive on purpose — you can open
                   the Terminal and see the idle prompt for yourself — and Run tries again. */
                $this->logger->error('Prompt did not reach the agent; leaving the task queued', ['task_id' => $taskId]);
                $task->status          = 'queued';
                $task->progressMessage = 'The brief did not reach the agent — its session is open but idle. Press Run to try again.';
                $task->updatedAt       = date('Y-m-d H:i:s');
                Bean::store($task);
                $this->logTaskEvent($taskId, 'error', 'system', 'Prompt did not reach the agent; task left queued');
                Flight::jsonError('The agent session started but the brief did not reach it. Press Run to try again.', 409);
                return;
            }

            // Update status to running
            $task->status = 'running';
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            // Name the engine that actually started, not the one this code was written for.
            // A task on another provider still logged "Claude session started", and this is
            // the first line someone reads when working out what ran.
            $startedOn = trim((string) ($task->engine ?? '')) !== ''
                ? \app\EngineRegistry::label((string) $task->engine)
                : 'Agent';
            $this->logTaskEvent($taskId, 'info', 'system', $startedOn . ' session started by ' . ($this->member->displayName ?? $this->member->email));

            $this->logger->info('Claude session started', [
                'task_id' => $taskId,
                'session' => $runner->getSessionName(),
                'member_id' => $this->member->id,
                'prompt_sent' => $promptSent
            ]);

            // Auto-start test server if task has branch and port
            $serverInfo = $this->autoStartTestServer($task, $this->member->id);

            $response = [
                'success' => true,
                'message' => 'Claude session started',
                'session' => $runner->getSessionName()
            ];

            if ($serverInfo) {
                $response['test_server'] = $serverInfo;
                $response['message'] .= " (test server on port {$serverInfo['port']})";
            }

            Flight::json($response);

        } catch (Exception $e) {
            $this->logger->error('Failed to start Claude session', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to start session: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Re-run a completed or failed task
     */
    public function rerun($params = []) {
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

        // Only allow re-run on completed or failed tasks
        if (!in_array($task->status, ['completed', 'failed'])) {
            Flight::jsonError('Can only re-run completed or failed tasks', 400);
            return;
        }

        // Use existing workspace path if available
        $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;

        // If the workspace is gone (deleted on a prior approve/cleanup), we can't re-run
        // against it — that path falls through to the main app and the agent guard rejects
        // it. Rebuild a fresh instance workspace via run(), which self-heals the stale
        // branch, re-clones from the instance, and spawns. Keeps the same task + history.
        if (!$workspacePath || !is_dir($workspacePath . '/.git')) {
            $task->status = 'pending';
            $task->errorMessage = null;
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);
            $this->logTaskEvent($taskId, 'info', 'system', 'Workspace was gone — re-running via a rebuilt instance workspace.');
            return $this->run($params);
        }

        // Kill any existing session for this task
        // Pass member level for security sandbox hook
        $runner = new ClaudeRunner($taskId, $this->member->id, $task->teamId, $workspacePath, $this->member->level);
        if ($runner->exists()) {
            $runner->kill();
            usleep(500000); // Wait 500ms
        }

        // Reset task to pending
        $task->status = 'pending';
        $task->errorMessage = null;
        $task->updatedAt = date('Y-m-d H:i:s');
        Bean::store($task);

        $this->logTaskEvent($taskId, 'info', 'system', 'Task reset for re-run by ' . ($this->member->displayName ?? $this->member->email));

        // Regenerate .mcp.json with current config before running
        if ($workspacePath && is_dir($workspacePath)) {
            try {
                $this->writeProjectMcpConfig($workspacePath, $task, $taskId, 'Regenerated');
            } catch (\Throwable $e) {
                Flight::jsonError($e->getMessage(), 409);
                return;
            }
        }

        // Now run the task (reuse run logic)
        try {
            // Spawn Claude interactively in tmux (runner already has workspace path)
            $success = $runner->spawn();

            if (!$success) {
                Flight::jsonError('Failed to start Claude session', 500);
                return;
            }

            // Update task status to queued
            $task->status = 'queued';
            $task->tmuxSession = $runner->getSessionName();
            $task->currentRunId = bin2hex(random_bytes(16));
            $task->runCount = ($task->runCount ?? 0) + 1;
            $task->lastRunnerMemberId = $this->member->id;
            $task->startedAt = date('Y-m-d H:i:s');
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            // Wait for Claude to initialize
            usleep(2000000); // 2 seconds

            // Build the prompt
            $prompt = PromptBuilder::build([
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'task_type' => $task->taskType,
                'acceptance_criteria' => $task->acceptanceCriteria,
                'related_files' => json_decode(((string)$task->relatedFiles) ?? '', true) ?: [],
                'tags' => json_decode(((string)$task->tags) ?? '', true) ?: [],
                'authcontrol_level' => $task->authcontrolLevel,
                'branch_name' => $task->branchName,
                'assigned_port' => $task->assignedPort,
                'project_path' => $task->projectPath,
                'proxy_hash' => $task->proxyHash,
            ]);

            // Send the prompt
            $runner->sendPrompt($prompt);

            // Update status to running
            $task->status = 'running';
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'info', 'system', 'Task re-run started');

            // Auto-start test server if task has branch and port
            $serverInfo = $this->autoStartTestServer($task, $this->member->id);

            $response = [
                'success' => true,
                'message' => 'Task re-run started',
                'session' => $runner->getSessionName()
            ];

            if ($serverInfo) {
                $response['test_server'] = $serverInfo;
                $response['message'] .= " (test server on port {$serverInfo['port']})";
            }

            Flight::json($response);

        } catch (Exception $e) {
            $this->logger->error('Failed to re-run task', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to re-run: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Resolve a merge conflict WITH the agent. Brings the instance's current base onto
     * the task branch (in its existing workspace), leaving conflict markers, then spawns
     * the agent with a resolution prompt so it fixes + commits. The task's own work is
     * preserved; once the agent commits, Approve & Merge lands cleanly.
     */
    public function resolveconflict($params = []) {
        if (!$this->requireLogin()) return;

        $request = Flight::request();
        if ($request->method !== 'POST') { Flight::redirect('/workbench'); return; }
        if (!SimpleCsrf::validate()) { Flight::jsonError('CSRF validation failed', 403); return; }

        $taskId = (int)$this->getParam('id');
        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) { Flight::jsonError('Task not found', 404); return; }
        if (!$this->access->canRun($this->member->id, $task)) { Flight::jsonError('Access denied', 403); return; }

        $ws = (string)($task->projectPath ?? '');
        $br = (string)($task->branchName ?? '');
        if ($ws === '' || !is_dir($ws . '/.git') || $br === '') {
            Flight::jsonError('This task has no workspace/branch to resolve — use Re-run to rebuild it.', 409);
            return;
        }
        $instDir = $this->instanceDirForTask($task);
        if ($instDir === null) {
            Flight::jsonError('Conflict resolution is only available for instance tasks.', 409);
            return;
        }

        $git = function (string $dir, array $args): array {
            $cmd = 'git -C ' . escapeshellarg($dir);
            foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
            $out = []; $code = 0; exec($cmd . ' 2>&1', $out, $code);
            return ['ok' => $code === 0, 'out' => trim(implode("\n", $out))];
        };

        // Bring the instance's CURRENT base onto the task branch, inside the workspace.
        $instBase = trim((string)@shell_exec('git -C ' . escapeshellarg($instDir) . ' rev-parse --abbrev-ref HEAD 2>/dev/null'))
                    ?: ($task->baseBranch ?: 'main');
        $git($ws, ['checkout', $br]);          // ensure the task branch is checked out
        $git($ws, ['merge', '--abort']);       // clear any half-finished prior merge (no-op if none)
        $fetch = $git($ws, ['fetch', $instDir, $instBase]);
        if (!$fetch['ok']) {
            Flight::jsonError('Could not fetch the instance base into the workspace: ' . $fetch['out'], 500);
            return;
        }
        $restoreWsDb = $this->shieldRuntimeDb($ws);   // don't let a tracked runtime db block the merge
        $merge = $git($ws, ['merge', '--no-ff', '-m', 'Merge ' . $instBase . ' into ' . $br . ' (resolve for task #' . $taskId . ')', 'FETCH_HEAD']);
        if ($merge['ok']) {
            $restoreWsDb();
            // No real conflict — the branch is up to date with the base. LAND it now so the
            // change actually deploys (this is what "resolve" should visibly do), rather
            // than leaving a separate Approve & Merge step the user might not realise is needed.
            $lm = $this->localMergeBack($task);
            $landed = $lm['merged'] && $lm['pushed'];
            if ($landed) {
                $task->status    = 'merged';
                $task->mergedAt  = date('Y-m-d H:i:s');
                $task->updatedAt = date('Y-m-d H:i:s');
                Bean::store($task);
                $this->resolveDetectedError($task);
            }
            $this->logTaskEvent($taskId, $landed ? 'info' : 'warning', 'system',
                'Updated branch from base with no conflict — ' . $lm['reason']);
            Flight::json(['success' => true, 'clean' => true, 'merged' => $landed,
                'message' => $landed
                    ? ('Resolved and merged — ' . $lm['reason'] . '. The change is live.')
                    : ('Branch updated from base, but the merge did not land: ' . $lm['reason'])]);
            return;
        }
        $restoreWsDb();

        // Real conflict: leave the markers in place, collect the file list, hand to the agent.
        $conf  = $git($ws, ['diff', '--name-only', '--diff-filter=U']);
        $files = $conf['ok'] ? array_values(array_filter(explode("\n", trim($conf['out'])))) : [];
        $this->logTaskEvent($taskId, 'warning', 'system',
            'Merge conflict — handing ' . count($files) . ' file(s) to the agent: ' . implode(', ', $files));

        // §5 decorrelation: the resolver must differ from the agent that authored the
        // branch. A conflict is merge-reasoning that benefits from a higher tier anyway,
        // so run the resolver on the author engine's RESOLVER tier (defaults to the
        // frontier/planner model) — a genuinely different model from the worker that
        // built the branch. (A different engine awaits non-claude interactive dispatch;
        // until then the model tier is the honest decorrelation lever.)
        $authorEngine  = EngineRegistry::isValid((string)$task->engine) ? (string)$task->engine : EngineRegistry::defaultEngine();
        $workerModel   = EngineRegistry::model($authorEngine, 'worker');
        // The member resolving the conflict may override the resolver model in settings;
        // absent that, the engine's resolver tier (defaults to its frontier/planner model).
        $resolverModel = MemberEnginePrefs::model((int)$this->member->id, $authorEngine, 'resolver');

        try {
            $runner = new ClaudeRunner($taskId, $this->member->id, $task->teamId, $ws, $this->member->level);
            if ($resolverModel !== '' && $resolverModel !== $workerModel) {
                $runner->setModelOverride($resolverModel);
            }
            if ($runner->exists()) { $runner->kill(); usleep(400000); }
            try {
                $this->writeProjectMcpConfig($ws, $task, $taskId, 'Regenerated');
            } catch (\Throwable $e) {
                Flight::jsonError($e->getMessage(), 409);
                return;
            }

            if (!$runner->spawn()) { Flight::jsonError('Failed to start the agent session', 500); return; }
            $task->status = 'running';
            $task->tmuxSession = $runner->getSessionName();
            $task->currentRunId = bin2hex(random_bytes(16));
            $task->lastRunnerMemberId = $this->member->id;
            $task->startedAt = date('Y-m-d H:i:s');
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            usleep(2000000);   // let the agent initialise
            $fileList = $files ? ('- ' . implode("\n- ", $files)) : '(run `git status` to see them)';
            $prompt = "A git merge is IN PROGRESS on branch `{$br}` and has CONFLICTS. The instance's base branch "
                . "(`{$instBase}`) was merged in and clashed with this task's changes.\n\n"
                . "Resolve the conflict markers (<<<<<<< / ======= / >>>>>>>) in these files, keeping BOTH this task's "
                . "intent AND the base's changes wherever both belong:\n{$fileList}\n\n"
                . "Then stage everything and commit to complete the merge: `git add -A && git commit --no-edit`. "
                . "Do NOT change unrelated code and do NOT push. When finished, say the conflict is resolved so it can be approved & merged.";
            $runner->sendPrompt($prompt);

            $resolverNote = ($resolverModel !== '' && $resolverModel !== $workerModel)
                ? (' — resolver on ' . $resolverModel . ' (decorrelated from worker ' . $workerModel . ')')
                : '';
            $this->logTaskEvent($taskId, 'info', 'review',
                'Conflict resolution started by ' . ($this->member->displayName ?? $this->member->email) . $resolverNote);
            Flight::json(['success' => true, 'clean' => false, 'files' => $files, 'session' => $runner->getSessionName(),
                'message' => 'Agent is resolving ' . count($files) . ' conflicting file(s). Watch the conversation, then Approve & Merge.']);
        } catch (Exception $e) {
            $this->logger->error('Failed to start conflict resolution', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to start conflict resolution: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Force reset a stuck queued/running task
     */
    public function forcereset($params = []) {
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

        // Only allow force reset on queued/running tasks
        if (!in_array($task->status, ['queued', 'running'])) {
            Flight::jsonError('Can only force reset queued or running tasks', 400);
            return;
        }

        try {
            // Kill any existing tmux session
            if ($task->tmuxSession) {
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                if ($runner->exists()) {
                    $runner->kill();
                    usleep(500000); // Wait 500ms for cleanup
                }
            }

            // Reset task to pending
            $task->status = 'pending';
            $task->tmuxSession = null;
            $task->errorMessage = null;
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'warning', 'system', 'Task force reset by ' . ($this->member->displayName ?? $this->member->email));

            $this->logger->info('Task force reset', [
                'task_id' => $taskId,
                'member_id' => $this->member->id
            ]);

            Flight::json([
                'success' => true,
                'message' => 'Task has been reset to pending'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to force reset task', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to reset: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Mark task as complete (user action)
     */
    public function complete($params = []) {
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

        try {
            // Opt-in merge: "Mark Complete & merge" merges the task branch into its
            // base (instance/<slug> for instance tasks) using the same gh-free path
            // as Approve & Merge. Must run BEFORE we tear down the workspace/session.
            $doMerge     = $this->getParam('merge') === '1';
            $merged      = false;
            $mergeReason = null;
            if ($doMerge) {
                $lm = $this->localMergeBack($task);
                $merged = $lm['merged'] && $lm['pushed'];
                $mergeReason = $lm['reason'];
                $this->logTaskEvent($taskId, $merged ? 'info' : 'warning', 'system', 'Local merge: ' . $lm['reason']);
            }

            // Kill any existing tmux session
            if ($task->tmuxSession) {
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                if ($runner->exists()) {
                    $runner->kill();
                }
            }

            // Kill test server if running
            if ($task->testServerSession) {
                TmuxManager::kill($task->testServerSession);
            }

            // Delete proxy file for nginx subdomain routing
            if (!empty($task->proxyFile) && file_exists($task->proxyFile)) {
                unlink($task->proxyFile);
                $this->logTaskEvent($taskId, 'info', 'system', "Deleted proxy file: {$task->proxyFile}");
            }

            // Create PR if task has a branch, workspace, and no PR yet — but never for
            // instance tasks (local sandboxes: no gh CLI / remote, changes stay local)
            // and not when we just merged locally.
            $prUrl = null;
            $prError = null;
            if (!$doMerge && !empty($task->branchName) && !empty($task->projectPath) && empty($task->prUrl)
                && $this->instanceDirForTask($task) === null) {
                $prResult = $this->createPRViaCli($task);
                $prUrl = $prResult['url'] ?? null;
                $prError = $prResult['error'] ?? null;

                if ($prUrl) {
                    $task->prUrl = $prUrl;
                }
            }

            // Mark as completed (or merged when a local merge actually landed) and
            // clear session fields.
            $task->status = $merged ? 'merged' : 'completed';
            if ($merged) $task->mergedAt = date('Y-m-d H:i:s');
            $task->completedAt = date('Y-m-d H:i:s');
            $task->tmuxSession = null;
            $task->testServerSession = null;
            $task->proxyFile = null;
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'info', 'user', 'Task marked complete by ' . ($this->member->displayName ?? $this->member->email));

            // Close the firehose loop: a detected-error task that merged is now fixed.
            if ($merged) $this->resolveDetectedError($task);

            $response = [
                'success' => true,
                'message' => $merged ? 'Task completed and merged' : 'Task completed',
                'merged'  => $merged,
            ];
            if ($doMerge && !$merged) {
                $response['message'] = 'Marked complete, but NOT merged';
                $response['merge_reason'] = $mergeReason;
            }
            if ($prUrl) {
                $response['pr_url'] = $prUrl;
                $response['message'] = 'Task completed - PR created';
            } elseif ($prError) {
                $response['pr_error'] = $prError;
            }

            Flight::json($response);

        } catch (Exception $e) {
            $this->logger->error('Failed to complete task', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to complete: ' . $e->getMessage(), 500);
        }
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

        // Get options from request
        $createPr = $this->getParam('create_pr') === '1';
        $mergePr = $this->getParam('merge_pr') === '1';
        $stopSession = $this->getParam('stop_session') === '1';
        $stopServer = $this->getParam('stop_server') === '1';
        $deleteWorkspace = $this->getParam('delete_workspace') === '1';
        $notes = $this->sanitize($this->getParam('notes', ''));

        try {
            $prCreated = false;
            $prMerged = false;
            $mergeError = null;
            $workspaceDeleted = false;
            $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;

            // Instance tasks are local sandboxes: their changes merge into the live
            // instance repo locally (localMergeBack below), so we never open a GitHub PR
            // for them — no `gh` CLI or remote required. PRs are only for clone-based
            // tasks that target a real GitHub remote.
            $isInstanceTask = $this->instanceDirForTask($task) !== null;

            // Create PR if requested and doesn't exist (clone-based, GitHub-backed tasks only)
            if ($createPr && !$isInstanceTask && empty($task->prUrl) && !empty($task->branchName) && $workspacePath) {
                $prResult = $this->createPRViaCli($task);
                if (!empty($prResult['url'])) {
                    $task->prUrl = $prResult['url'];
                    $prCreated = true;
                    $this->logTaskEvent($taskId, 'info', 'system', "Created PR: {$prResult['url']}");
                } elseif (!empty($prResult['error'])) {
                    $this->logTaskEvent($taskId, 'warning', 'system', "PR creation failed: {$prResult['error']}");
                }
            }

            // Merge PR if requested and exists
            if ($mergePr && !empty($task->prUrl) && !empty($task->prNumber)) {
                try {
                    $github = $this->getGitHubService($task);
                    if ($github) {
                        $mergeResult = $github->mergePullRequest(
                            (int)$task->prNumber,
                            "Merge: {$task->title}",
                            "Approved via Tiknix Workbench\n\nTask #{$task->id}",
                            'squash'
                        );
                        $prMerged = !empty($mergeResult['merged']);
                    }
                } catch (Exception $e) {
                    $mergeError = $e->getMessage();
                    $this->logger->error('Failed to merge PR', [
                        'task_id' => $taskId,
                        'pr_number' => $task->prNumber,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Local merge fallback: when a merge was requested but no GitHub PR was
            // merged (no gh / local-remote instance / no PR), merge the task branch
            // into its base inside the workspace and push — the gh-free equivalent.
            // Must run BEFORE any teardown below (it needs the workspace + branch).
            $localMerged = false;
            $mergeReason = null;
            if ($mergePr && !$prMerged) {
                $lm = $this->localMergeBack($task);
                $localMerged = $lm['merged'] && $lm['pushed'];
                $mergeReason = $lm['reason'];
                $this->logTaskEvent($taskId, $localMerged ? 'info' : 'warning', 'system', 'Local merge: ' . $lm['reason']);
            }
            $merged = $prMerged || $localMerged;

            // SAFETY: if a merge was REQUESTED but did not land, do NOT tear anything
            // down. Deleting the workspace here destroys the task branch + the agent's
            // commits, making a failed merge unrecoverable (this is exactly how task 164
            // lost its work). Keep the workspace + session so the merge can be fixed and
            // retried — teardown only happens once the change is safely merged.
            $mergeFailedButRequested = $mergePr && !$merged;
            if ($mergeFailedButRequested) {
                $stopSession = false;
                $stopServer = false;
                $deleteWorkspace = false;
                $this->logTaskEvent($taskId, 'warning', 'system',
                    'Merge did not complete — workspace kept for retry. Reason: ' . ($mergeReason ?: $mergeError ?: 'unknown'));
            }

            // Stop tmux session if requested
            if ($stopSession && $task->tmuxSession) {
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                if ($runner->exists()) {
                    $runner->kill();
                    $this->logTaskEvent($taskId, 'info', 'system', 'Stopped Claude session');
                }
                $task->tmuxSession = null;
            }

            // Stop test server if requested
            if ($stopServer && $task->testServerSession) {
                TmuxManager::kill($task->testServerSession);
                $this->logTaskEvent($taskId, 'info', 'system', 'Stopped test server');
                $task->testServerSession = null;
            }

            // Delete proxy file
            if (!empty($task->proxyFile) && file_exists($task->proxyFile)) {
                unlink($task->proxyFile);
                $task->proxyFile = null;
            }

            // Delete workspace if requested
            if ($deleteWorkspace && $workspacePath && is_dir($workspacePath)) {
                $this->recursiveDelete($workspacePath);
                $this->logTaskEvent($taskId, 'info', 'system', "Deleted workspace: {$workspacePath}");
                $task->projectPath = null;
                $workspaceDeleted = true;
            }

            // Mark task as merged or completed — only 'merged' when a real merge
            // (GitHub PR or local) actually landed, never silently on failure.
            $task->status = $merged ? 'merged' : 'completed';
            $task->completedAt = date('Y-m-d H:i:s');
            $task->reviewedBy = $this->member->id;
            $task->reviewedAt = date('Y-m-d H:i:s');
            if ($merged) {
                $task->mergedAt = date('Y-m-d H:i:s');
            }
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            // Close the firehose loop: a detected-error task that merged is now fixed.
            if ($merged) $this->resolveDetectedError($task);

            // Build log message
            $message = 'Task approved by ' . ($this->member->displayName ?? $this->member->email);
            $actions = [];
            if ($prCreated) $actions[] = 'PR created';
            if ($prMerged) $actions[] = 'PR merged';
            if ($localMerged) $actions[] = 'merged locally';
            if ($mergePr && !$merged && $mergeReason) $actions[] = "not merged: {$mergeReason}";
            if ($mergeError) $actions[] = "PR merge failed: {$mergeError}";
            if ($stopSession) $actions[] = 'session stopped';
            if ($stopServer) $actions[] = 'server stopped';
            if ($workspaceDeleted) $actions[] = 'workspace deleted';
            if (!empty($actions)) {
                $message .= ' (' . implode(', ', $actions) . ')';
            }
            if (!empty($notes)) {
                $message .= "\nNotes: {$notes}";
            }

            $this->logTaskEvent($taskId, 'info', 'review', $message);

            Flight::json([
                'success' => true,
                'message' => 'Task approved',
                'pr_created' => $prCreated,
                'pr_merged' => $prMerged,
                'merged' => $merged,
                'merge_requested' => $mergePr,
                'merge_reason' => $mergeReason,
                'merge_error' => $mergeError,
                'workspace_deleted' => $workspaceDeleted
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to approve task', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to approve: ' . $e->getMessage(), 500);
        }
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

        try {
            // Kill tmux session if exists
            if ($task->tmuxSession) {
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                $runner->kill();
            }

            $task->status = 'pending';
            $task->tmuxSession = null;
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'warning', 'system', 'Task stopped by user');

            Flight::json(['success' => true, 'message' => 'Task stopped']);

        } catch (Exception $e) {
            Flight::jsonError('Failed to stop task: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Start test server for a task's branch
     * Creates a tmux session running server.php on the assigned port
     * Initializes workspace environment with fresh database for testing
     */
    public function startserver($params = []) {
        if (!$this->requireLogin()) return;

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

        if (empty($task->branchName)) {
            Flight::jsonError('No branch assigned to this task', 400);
            return;
        }
        if (empty($task->projectPath) || !is_dir($task->projectPath)) {
            Flight::jsonError('No workspace to preview yet — run the task first.', 400);
            return;
        }

        try {
            $initMessages = [];
            if (empty($task->proxyHash)) $task->proxyHash = bin2hex(random_bytes(6));

            // Pull the branch work + set up a fresh isolated db/config for the preview.
            exec(sprintf('cd %s && git pull origin %s 2>&1',
                escapeshellarg($task->projectPath), escapeshellarg($task->branchName)), $pullOutput, $pullCode);
            if ($pullCode === 0) $initMessages[] = "Pulled latest changes from {$task->branchName}";

            $wsManager = new WorkspaceManager();
            $wsManager->initialize($task->projectPath, $task->proxyHash);
            $initMessages[] = "Fresh database created with admin/admin1234";

            // The PREVIEW is just a symlink at the capricorn-auto-routed path pointing at the
            // workspace clone — capricorn serves {host}.com → {host}/public/index.php directly.
            // No php -S, no proxy file, NO PORT. Temporary; removed on stop / cleanup.
            $slug = preg_replace('/[^a-z0-9]/', '', strtolower(explode('.', (string) $task->instanceTag)[0])) ?: 'ws';
            $host = "{$slug}-{$task->proxyHash}.tiknix";        // e.g. bidsurge-4b1234ba0a55.tiknix
            $link = '/var/www/html/default/' . $host;
            $target = rtrim($task->projectPath, '/');
            // Safety: only ever link to a workspace clone under the default docroot.
            if (strpos($target, '/var/www/html/default/') !== 0) {
                Flight::jsonError('Refusing to preview: workspace path failed validation', 400); return;
            }
            @unlink($link);
            if (!@symlink($target, $link)) {
                Flight::jsonError('Could not create the preview link.', 500); return;
            }

            $task->testServerSession = $host;   // "preview live" marker + host for the UI
            $task->proxyFile = $link;           // the symlink to remove on stop
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $testUrl = "https://{$host}.com";
            foreach ($initMessages as $msg) $this->logTaskEvent($taskId, 'info', 'system', $msg);
            $this->logTaskEvent($taskId, 'info', 'system', "Preview live at {$testUrl}");

            Flight::json([
                'success'      => true,
                'message'      => "Preview live at {$testUrl}",
                'session'      => $host,
                'url'          => $testUrl,
                'subdomain'    => "{$host}.com",
                'init_details' => $initMessages,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to start preview', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to start preview: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Stop test server for a task
     *
     */
    public function stopserver($params = []) {
        if (!$this->requireLogin()) return;

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

        if (empty($task->testServerSession) && empty($task->proxyFile)) {
            Flight::jsonError('No preview is running', 400);
            return;
        }

        try {
            // New model: remove the symlink. Back-compat: also drop a legacy .proxy file and
            // kill a legacy `php -S` tmux session if this task still has them.
            if (!empty($task->proxyFile)) {
                if (is_link($task->proxyFile)) @unlink($task->proxyFile);
                elseif (file_exists($task->proxyFile)) @unlink($task->proxyFile);
            }
            if (!empty($task->testServerSession) && TmuxManager::exists($task->testServerSession)) {
                TmuxManager::kill($task->testServerSession);
            }

            $task->testServerSession = null;
            $task->proxyFile = null;
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'info', 'system', 'Preview stopped');
            Flight::json(['success' => true, 'message' => 'Preview stopped']);
        } catch (Exception $e) {
            $this->logger->error('Failed to stop preview', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to stop preview: ' . $e->getMessage(), 500);
        }
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
        if (in_array($task->status, ['running', 'queued']) && $task->tmuxSession) {
            try {
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                if ($runner->isRunning()) {
                    $progress['live'] = $runner->getProgress();
                } else {
                    // Session ended - check if completed or failed
                    $progress['session_ended'] = true;
                }
            } catch (Exception $e) {
                $progress['runner_error'] = $e->getMessage();
            }
        }

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

            // If not an internal comment, try to send to Claude session if it exists
            if (!$comment->isInternal) {
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                if ($runner->exists()) {
                    // Append reminder about tiknix MCP tools
                    $messageWithReminder = $content . "\n\n[REMINDER: Use the tiknix MCP tools to update the project status]";
                    $sentToSession = $runner->sendPrompt($messageWithReminder);
                    if ($sentToSession) {
                        $this->logTaskEvent($taskId, 'info', 'user', 'Message sent to Claude: ' . substr($content, 0, 100) . (strlen($content) > 100 ? '...' : ''));

                        // If task was awaiting/completed/failed but session is still active, mark as running
                        if (in_array($task->status, ['awaiting', 'completed', 'failed'])) {
                            $task->status = 'running';
                            $task->updatedAt = date('Y-m-d H:i:s');
                            Bean::store($task);
                        }
                    }
                }
            }

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

        } catch (Exception $e) {
            Flight::jsonError('Failed to add comment', 500);
        }
    }

    /**
     * Upload an image to a task comment
     * Supports both standalone image uploads and image+text comments
     */
    public function uploadimage($params = []) {
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
        $task = Bean::load('workbenchtask', $taskId);

        if (!$task->id || !$this->access->canComment($this->member->id, $task)) {
            Flight::jsonError('Access denied', 403);
            return;
        }

        // Check for uploaded file
        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds max upload size',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form max size',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by extension'
            ];
            $error = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
            Flight::jsonError($errorMessages[$error] ?? 'File upload failed', 400);
            return;
        }

        $file = $_FILES['image'];

        // Validate file type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        $allowedTypes = [
            'image/png' => 'png',
            'image/jpeg' => 'jpeg',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];

        if (!isset($allowedTypes[$mimeType])) {
            Flight::jsonError('Invalid image type. Allowed: PNG, JPEG, GIF, WEBP', 400);
            return;
        }

        // Validate file size (max 10MB)
        $maxSize = 10 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            Flight::jsonError('Image too large. Max size: 10MB', 400);
            return;
        }

        try {
            // Public path is where index.php lives (DOCUMENT_ROOT from nginx)
            $publicRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__) . '/public', '/');

            // Create upload directory
            $uploadsDir = $publicRoot . '/uploads/workbench/' . $taskId;
            if (!is_dir($uploadsDir)) {
                if (!mkdir($uploadsDir, 0755, true)) {
                    throw new Exception("Failed to create uploads directory");
                }
            }

            // Generate unique filename
            $extension = $allowedTypes[$mimeType];
            $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            $savePath = $uploadsDir . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $savePath)) {
                throw new Exception("Failed to save uploaded file");
            }

            // Relative path for database
            $relativePath = 'uploads/workbench/' . $taskId . '/' . $filename;

            // Get optional caption/content
            $content = trim($this->getParam('content', ''));

            // Create comment with image
            $comment = Bean::dispense('taskcomment');
            $comment->taskId = $taskId;
            $comment->memberId = $this->member->id;
            $comment->content = $content ?: null;
            $comment->imagePath = $relativePath;
            $comment->isFromClaude = 0;
            $comment->isInternal = 0;
            $comment->createdAt = date('Y-m-d H:i:s');
            Bean::store($comment);

            $this->logTaskEvent($taskId, 'info', 'user', 'Image uploaded' . ($content ? " with caption" : ''));

            // Try to send notification to Claude session if running
            $sentToSession = false;
            if (!empty($task->tmuxSession)) {
                $workspacePath = !empty($task->projectPath) ? $task->projectPath : null;
                $runner = new ClaudeRunner($taskId, $task->memberId, $task->teamId, $workspacePath);
                if ($runner->exists()) {
                    $message = "[User uploaded an image";
                    if ($content) {
                        $message .= " with message: {$content}";
                    }
                    $message .= ". View it in the task UI.]\n\n[REMINDER: Use the tiknix MCP tools to update the project status]";
                    $sentToSession = $runner->sendPrompt($message);

                    // If task was awaiting, mark as running
                    if ($sentToSession && $task->status === 'awaiting') {
                        $task->status = 'running';
                        $task->updatedAt = date('Y-m-d H:i:s');
                        Bean::store($task);
                    }
                }
            }

            Flight::json([
                'success' => true,
                'sent_to_session' => $sentToSession,
                'comment' => [
                    'id' => $comment->id,
                    'content' => $comment->content,
                    'image_path' => $relativePath,
                    'image_url' => '/' . $relativePath,
                    'author' => $this->member->displayName ?? $this->member->email,
                    'created_at' => $comment->createdAt
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to upload image', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to upload image: ' . $e->getMessage(), 500);
        }
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

        $dir = '/var/www/html/default/' . $slug . '.' . $app;
        if (!is_file($dir . '/public/index.php')) {
            Flight::jsonError('That project is not on disk any more.', 409); return;
        }
        if (!$this->agentSignedIn($dir, (string) ($inst->engine ?? ''))) {
            Flight::jsonError('This project has not signed in to Claude yet, so the planner cannot run.', 409); return;
        }

        try {
            $runner = new PlanRunner($slug, $dir, (int)$this->member->id,
                (int)$this->member->level, (string)($inst->engine ?? ''));
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

    /** Absolute git repo dir for a task's instance, or null if not instance-tagged / missing. */
    private function instanceDirForTask($task): ?string {
        if (empty($task->instanceId)) return null;
        $inst = $this->access->instanceMeta((int)$task->instanceId);
        if (!$inst->id) return null;
        $dir = '/var/www/html/default/' . $inst->slug . '.' . ($inst->app ?: 'tiknix');
        return is_dir($dir . '/.git') ? $dir : null;
    }

    /**
     * The MCP endpoint + key an agent worktree for $task must use: the PROJECT's OWN.
     *
     * Not core's, and not this sidecar's. A worktree pointed at core runs every
     * mcp__tiknix__* call inside CORE's process — core's database and core's source tree
     * — while the task id it passes came from the PROJECT's data/workbench.db. Task ids
     * are per-project autoincrements (the same reason session names carry the slug, see
     * TmuxManager::buildTaskSessionName), so "task 1" names a different row in each. It
     * resolves to somebody else's task and denies, or to a same-named row and silently
     * writes the WRONG one. mtmoses's first build died on the first of those.
     *
     * The project already knows the answer: provisioning writes it a .mcp.json addressed
     * to its own domain with its own agent key, and PlanExecutor already copies that file
     * into plan-subtask worktrees. This is the same source of truth for solo tasks.
     *
     * NO FALLBACK to core. Writing core's url here is precisely the bug; a project whose
     * config cannot be read must fail loudly and leave the worktree's config alone.
     *
     * @return array{baseUrl:string, apiKey:string}|null
     */
    private function projectMcpTarget($task): ?array {
        $dir = $this->instanceDirForTask($task);
        if ($dir === null) return null;

        $file = $dir . '/.mcp.json';
        if (!is_file($file)) return null;

        $json = json_decode((string) @file_get_contents($file), true);
        $srv  = $json['mcpServers']['tiknix'] ?? null;
        if (!is_array($srv)) return null;

        $url  = trim((string) ($srv['url'] ?? ''));
        $auth = trim((string) ($srv['headers']['Authorization'] ?? ''));
        if ($url === '' || stripos($auth, 'Bearer ') !== 0) return null;

        // ensureMcpConfig() appends /mcp/message itself, so hand it the base.
        return [
            'baseUrl' => (string) preg_replace('#/mcp/message/?$#', '', rtrim($url, '/')),
            'apiKey'  => trim(substr($auth, 7)),
        ];
    }

    /**
     * Write the worktree's .mcp.json from the PROJECT's target.
     *
     * THROWS rather than returning quietly. An unresolvable target is not a degraded run
     * that happens to lack a few tools — it is a run whose worktree keeps whatever
     * .mcp.json it had, which for an existing workspace is the CORE-addressed one this
     * change exists to remove. Logging a warning and starting the agent anyway is how the
     * original bug survived two weeks of daily builds: every symptom was inside an agent
     * transcript nobody reads, and the board showed a task that merely never finished.
     *
     * The caller must refuse the run. See projectMcpTarget() for why there is no fallback.
     *
     * @throws \RuntimeException when the project's own MCP target cannot be resolved
     */
    private function writeProjectMcpConfig(string $workspacePath, $task, int $taskId, string $when): void {
        $target = $this->projectMcpTarget($task);
        if ($target === null) {
            $msg = 'This project has no usable .mcp.json, so an agent started here would either '
                 . 'have no tiknix tools or keep addressing core — reading and writing another '
                 . 'project\'s tasks. Re-provision the project, or give it a .mcp.json pointing '
                 . 'at its own /mcp/message.';
            $this->logger->error('Project MCP config unresolvable — refusing to start the agent',
                ['task' => $taskId, 'workspace' => $workspacePath]);
            $this->logTaskEvent($taskId, 'error', 'system', $msg);
            throw new \RuntimeException($msg);
        }
        $this->generateWorkspaceMcpConfig($workspacePath, $target['apiKey'], $target['baseUrl']);
        $this->logTaskEvent($taskId, 'info', 'system', $when . " .mcp.json → {$target['baseUrl']}");
    }

    /**
     * Non-DB uncommitted changes in a repo (a short path list), or '' if clean.
     * Live instances force-track their sqlite DB which churns every request, so
     * *.db/*.sqlite noise is ignored — mirrors the plan executor's significantDirt.
     */
    private function instanceDirtyFiles(string $dir): string {
        $out = [];
        exec('git -C ' . escapeshellarg($dir) . ' status --porcelain 2>/dev/null', $out);
        $paths = [];
        foreach ($out as $line) {
            if ($line === '') continue;
            $code = substr($line, 0, 2);   // XY porcelain status
            $p    = trim(substr($line, 3));
            if ($p === '') continue;
            // Untracked files (incl. runtime user uploads on older instances whose
            // .gitignore predates public/uploads/) are NEVER clobbered by a merge — git
            // aborts the merge itself if an incoming file would overwrite one. Only
            // *tracked* uncommitted edits can be silently lost, so only those should
            // block. This makes the merge far less "issue prone".
            if ($code === '??') continue;
            // Runtime data, not code: databases and user uploads.
            if (preg_match('#\.(db|sqlite)$|(^|/)public/uploads/#i', $p)) continue;
            $paths[] = $p;
        }
        return implode(', ', array_slice($paths, 0, 5)) . (count($paths) > 5 ? ' …' : '');
    }

    /**
     * Local merge-back for tasks with no GitHub PR. Instance-tagged tasks land the
     * change directly in their live instance repo (fetch the task branch from the
     * clone, merge into the instance's checked-out branch — like the plan executor);
     * other tasks merge into base in the clone and push to origin (gh-free PR merge).
     * Best-effort and non-destructive: never force-pushes; aborts on conflict; and
     * refuses to merge into an instance that has its own uncommitted code changes.
     *
     * @return array ['merged' => bool, 'pushed' => bool, 'reason' => string]
     */
    /**
     * Close the firehose loop: when a detected-error task (or the plan it became)
     * merges, flip its linked detectederror to 'resolved'. Idempotent; a no-op for
     * ordinary tasks. Covers both the standalone-task and orchestrator-plan paths
     * via the detectererror_id link, with a task_id fallback.
     */
    private function resolveDetectedError($task): void {
        $eid = (int)($task->detectederrorId ?? 0);
        $e = $eid ? Bean::load('detectederror', $eid) : Bean::findOne('detectederror', 'task_id = ?', [(int)$task->id]);
        if ($e && $e->id && !in_array($e->status, ['resolved', 'ignored'], true)) {
            $e->status    = 'resolved';
            $e->updatedAt = date('Y-m-d H:i:s');
            Bean::store($e);
            $this->logTaskEvent((int)$task->id, 'info', 'system', 'Firehose: detected error #' . (int)$e->id . ' resolved on merge');
        }
    }

    /**
     * Neutralize tracked runtime databases before a merge into an instance. A tracked
     * database/*.db is written at runtime, so git refuses to merge over its uncommitted
     * changes ("commit your changes or stash them"). We back up the LIVE file, discard
     * its working-tree delta so the merge can run, and return a closure that restores the
     * live file afterwards (so runtime data is never lost). New instances gitignore the
     * db and won't hit this; it's a safety net for legacy instances that still track it.
     */
    private function shieldRuntimeDb(string $instDir): callable {
        $rels = [];
        exec('git -C ' . escapeshellarg($instDir) . ' ls-files -- ' . escapeshellarg('database/*.db') . ' 2>/dev/null', $rels);
        $saved = [];
        foreach ($rels as $rel) {
            $abs = $instDir . '/' . $rel;
            if (!is_file($abs)) continue;
            $tmp = $abs . '.live.' . getmypid();
            if (@copy($abs, $tmp)) {
                @exec('git -C ' . escapeshellarg($instDir) . ' checkout -- ' . escapeshellarg($rel) . ' 2>/dev/null');
                $saved[$abs] = $tmp;
            }
        }
        return function () use ($saved) {
            foreach ($saved as $abs => $tmp) {
                if (is_file($tmp)) { @copy($tmp, $abs); @unlink($tmp); }
            }
        };
    }

    private function localMergeBack($task): array {
        $ws   = (string)($task->projectPath ?? '');
        $br   = (string)($task->branchName ?? '');
        $base = (string)($task->baseBranch ?: 'main');
        if ($ws === '' || !is_dir($ws . '/.git')) {
            return ['merged' => false, 'pushed' => false, 'reason' => 'workspace no longer available to merge from'];
        }
        if ($br === '') {
            return ['merged' => false, 'pushed' => false, 'reason' => 'task has no branch'];
        }
        $git = function (string $dir, array $args): array {
            $cmd = 'git -C ' . escapeshellarg($dir);
            foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
            $out = []; $code = 0;
            exec($cmd . ' 2>&1', $out, $code);
            return ['ok' => $code === 0, 'out' => trim(implode("\n", $out))];
        };
        // Are there commits on the branch not already on base?
        $ahead = $git($ws, ['rev-list', '--count', $base . '..' . $br]);
        if (!$ahead['ok']) {
            return ['merged' => false, 'pushed' => false, 'reason' => 'could not compare branch to base (' . $ahead['out'] . ')'];
        }
        if ((int)$ahead['out'] === 0) {
            return ['merged' => false, 'pushed' => false, 'reason' => 'the agent made no committed changes on the branch'];
        }

        $instDir = $this->instanceDirForTask($task);
        if ($instDir !== null) {
            // Instance task: merge into the live instance repo's checked-out branch.
            $dirty = $this->instanceDirtyFiles($instDir);
            if ($dirty !== '') {
                return ['merged' => false, 'pushed' => false,
                        'reason' => 'the instance has uncommitted code changes (' . $dirty . ') — commit or discard them first'];
            }
            $fetch = $git($instDir, ['fetch', $ws, $br]);
            if (!$fetch['ok']) {
                return ['merged' => false, 'pushed' => false, 'reason' => 'could not fetch the task branch into the instance (' . $fetch['out'] . ')'];
            }
            // Shield the tracked runtime DB (its uncommitted writes would block the merge);
            // restore the live DB regardless of outcome.
            $restoreDb = $this->shieldRuntimeDb($instDir);
            $merge = $git($instDir, ['merge', '--no-ff', '-m', 'Merge ' . $br . ' (task #' . (int)$task->id . ')', 'FETCH_HEAD']);
            if (!$merge['ok']) {
                // Capture the conflicting files BEFORE aborting, so the log says exactly what clashed.
                $conf = $git($instDir, ['diff', '--name-only', '--diff-filter=U']);
                $files = $conf['ok'] ? str_replace("\n", ', ', trim($conf['out'])) : '';
                $git($instDir, ['merge', '--abort']);
                $restoreDb();
                return ['merged' => false, 'pushed' => false,
                        'reason' => 'merge conflict on the instance — needs manual resolution'
                                  . ($files !== '' ? ' — conflicting files: ' . $files : '')];
            }
            $restoreDb();
            return ['merged' => true, 'pushed' => true,
                    'reason' => 'merged into ' . $base . ' on ' . ($task->instanceTag ?: 'the instance')];
        }

        // Non-instance task: merge into base in the clone and push to origin.
        if (!$git($ws, ['checkout', $base])['ok']) {
            return ['merged' => false, 'pushed' => false, 'reason' => 'could not check out base branch ' . $base];
        }
        $merge = $git($ws, ['merge', '--no-ff', '-m', 'Merge ' . $br . ' (task #' . (int)$task->id . ')', $br]);
        if (!$merge['ok']) {
            $conf = $git($ws, ['diff', '--name-only', '--diff-filter=U']);
            $files = $conf['ok'] ? str_replace("\n", ', ', trim($conf['out'])) : '';
            $git($ws, ['merge', '--abort']);
            return ['merged' => false, 'pushed' => false,
                    'reason' => 'merge conflict on ' . $base . ' — needs manual resolution'
                              . ($files !== '' ? ' — conflicting files: ' . $files : '')];
        }
        // Push the merged base to origin (gh-free). If this fails, the merge lives
        // only in the soon-to-be-deleted clone, so it does NOT count as merged.
        $push = $git($ws, ['push', 'origin', $base]);
        return [
            'merged' => true,
            'pushed' => $push['ok'],
            'reason' => $push['ok']
                ? 'merged into ' . $base . ' and pushed to origin'
                : 'merged locally but push to origin failed: ' . $push['out'],
        ];
    }

    /**
     * Diff summary of a task's branch vs its base (numstat), for the review UI.
     * Read-only. Returns null when there's no workspace/branch/changes, else
     * ['files'=>[['path','added','removed','binary'],...],'total_files','added','removed','base'].
     */
    private function taskDiffStat($task): ?array {
        $ws   = (string)($task->projectPath ?? '');
        $br   = (string)($task->branchName ?? '');
        $base = (string)($task->baseBranch ?: 'main');
        if ($ws === '' || !is_dir($ws . '/.git') || $br === '') return null;
        $out = []; $code = 0;
        exec('git -C ' . escapeshellarg($ws) . ' diff --numstat ' . escapeshellarg($base . '...HEAD') . ' 2>/dev/null', $out, $code);
        if ($code !== 0) return null;
        $files = []; $addT = 0; $remT = 0;
        foreach ($out as $line) {
            $p = explode("\t", $line);
            if (count($p) < 3) continue;
            $binary  = ($p[0] === '-');
            $added   = $binary ? 0 : (int)$p[0];
            $removed = ($p[1] === '-') ? 0 : (int)$p[1];
            $files[] = ['path' => $p[2], 'added' => $added, 'removed' => $removed, 'binary' => $binary];
            $addT += $added; $remT += $removed;
        }
        if (!$files) return null;
        return ['files' => $files, 'total_files' => count($files), 'added' => $addT, 'removed' => $remT, 'base' => $base];
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
        $ws   = (string)($task->projectPath ?? '');
        $br   = (string)($task->branchName ?? '');
        $base = (string)($task->baseBranch ?: 'main');
        $patch = ''; $note = '';
        if ($ws === '' || !is_dir($ws . '/.git') || $br === '') {
            $note = 'No workspace branch is available for this task — it may have been merged or cleaned up.';
        } else {
            $out = [];
            exec('git -C ' . escapeshellarg($ws) . ' diff ' . escapeshellarg($base . '...HEAD') . ' 2>&1', $out);
            $patch = implode("\n", $out);
            if (strlen($patch) > 500000) { $patch = substr($patch, 0, 500000); $note = 'Diff truncated (very large).'; }
            elseif (trim($patch) === '') { $note = 'No changes on this branch.'; }
        }
        $this->viewData['title'] = 'Diff — ' . $task->title;
        $this->viewData['task']  = $task;
        $this->viewData['patch'] = $patch;
        $this->viewData['note']  = $note;
        $this->viewData['stat']  = $this->taskDiffStat($task);
        $this->render('workbench/diff', $this->viewData);
    }

    /**
     * Create a PR using the gh CLI
     *
     * @param object $task The task bean
     * @return array ['url' => string|null, 'error' => string|null]
     */
    private function createPRViaCli(object $task): array {
        $workspacePath = $task->projectPath;

        if (!is_dir($workspacePath)) {
            return ['url' => null, 'error' => 'Workspace not found'];
        }

        // Build PR title based on task type
        $typePrefix = match($task->taskType) {
            'bugfix' => 'fix',
            'feature' => 'feat',
            'refactor' => 'refactor',
            'security' => 'security',
            'docs' => 'docs',
            'test' => 'test',
            default => 'task'
        };
        $title = "{$typePrefix}: {$task->title}";

        // Build PR body
        $body = "## Task #{$task->id}\n\n";
        if (!empty($task->description)) {
            $body .= "{$task->description}\n\n";
        }
        if (!empty($task->acceptanceCriteria)) {
            $body .= "## Acceptance Criteria\n{$task->acceptanceCriteria}\n\n";
        }
        $body .= "---\n*Created via Tiknix Workbench*";

        // Escape for shell
        $escapedTitle = escapeshellarg($title);
        $escapedBody = escapeshellarg($body);

        // Target base branch (for PR to merge into)
        $baseBranch = $task->baseBranch ?: 'main';
        $escapedBase = escapeshellarg($baseBranch);

        // Run gh pr create with base branch
        $cmd = "cd " . escapeshellarg($workspacePath) . " && gh pr create --title {$escapedTitle} --body {$escapedBody} --base {$escapedBase} 2>&1";

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        $outputStr = implode("\n", $output);

        if ($returnCode === 0) {
            // gh pr create outputs the PR URL on success
            $prUrl = trim($outputStr);
            if (filter_var($prUrl, FILTER_VALIDATE_URL)) {
                $this->logTaskEvent($task->id, 'info', 'github', "PR created: {$prUrl}");
                return ['url' => $prUrl, 'error' => null];
            }
        }

        // Check for common errors
        if (strpos($outputStr, 'already exists') !== false) {
            // PR already exists - try to get its URL
            $prUrl = $this->getExistingPrUrl($workspacePath, $task->branchName);
            if ($prUrl) {
                $this->logTaskEvent($task->id, 'info', 'github', "PR already exists: {$prUrl}");
                return ['url' => $prUrl, 'error' => null];
            }
            return ['url' => null, 'error' => 'PR already exists'];
        }

        $this->logger->warning('gh pr create failed', [
            'task_id' => $task->id,
            'output' => $outputStr,
            'return_code' => $returnCode
        ]);

        return ['url' => null, 'error' => $outputStr ?: 'Failed to create PR'];
    }

    /**
     * Get existing PR URL for a branch
     */
    private function getExistingPrUrl(string $workspacePath, string $branchName): ?string {
        $cmd = "cd " . escapeshellarg($workspacePath) . " && gh pr view " . escapeshellarg($branchName) . " --json url -q .url 2>&1";
        $output = trim(shell_exec($cmd) ?? '');

        if (filter_var($output, FILTER_VALIDATE_URL)) {
            return $output;
        }

        return null;
    }

    /**
     * Auto-start test server for a task (non-blocking)
     * Called automatically when a task starts running
     *
     * @param object $task The task bean
     * @param int $memberId The member starting the task
     * @return array|null Server info if started, null if skipped/failed
     */
    private function autoStartTestServer($task, int $memberId): ?array {
        // Skip if no branch or port assigned
        if (empty($task->branchName) || empty($task->assignedPort)) {
            return null;
        }

        // Skip if test server already running
        if (!empty($task->testServerSession) && TmuxManager::exists($task->testServerSession)) {
            return null;
        }

        // Skip if port is not available
        if (!PortManager::isPortAvailable($task->assignedPort)) {
            $this->logger->warning('Auto-start skipped: port in use', [
                'task_id' => $task->id,
                'port' => $task->assignedPort
            ]);
            return null;
        }

        try {
            $sessionName = TmuxManager::buildServerSessionName($memberId, $task->id, preg_replace('/\.[a-z0-9]+$/i', '', (string) $task->instanceTag));
            $projectPath = !empty($task->projectPath) ? $task->projectPath : dirname(__DIR__);

            // Build the server command
            if (!empty($task->projectPath)) {
                // Workspace mode - already on correct branch
                $serverCmd = sprintf(
                    'cd %s && php -S 0.0.0.0:%d server.php; echo "Server stopped. Press Enter to close..."; read',
                    escapeshellarg($projectPath),
                    $task->assignedPort
                );
            } else {
                // Main project mode - checkout branch first
                $serverCmd = sprintf(
                    'cd %s && git checkout %s && php -S 0.0.0.0:%d server.php; echo "Server stopped. Press Enter to close..."; read',
                    escapeshellarg($projectPath),
                    escapeshellarg($task->branchName),
                    $task->assignedPort
                );
            }

            TmuxManager::create($sessionName, $serverCmd, $projectPath);

            $task->testServerSession = $sessionName;

            // Create .proxy file for subdomain routing
            // File format: proxyhost=X\nproxyport=Y (lua loadEnvFile expects key=value)
            // Filename: .proxy.{hash}.{domain} (no TLD - nginx lua strips it)
            if (!empty($task->proxyHash)) {
                $baseDomain = preg_replace('#^https?://#', '', $this->serverBaseurl());
                // Strip TLD (e.g., .com, .net) - nginx lua expects domain without TLD
                $baseDomain = preg_replace('/\.[a-z]{2,}$/i', '', $baseDomain);
                $proxyFile = "/var/www/html/.proxy." . self::previewLabel($task->proxyHash, (string) $task->instanceTag) . ".{$baseDomain}";
                $proxyContent = "proxyhost=127.0.0.1\nproxyport={$task->assignedPort}";
                if (file_put_contents($proxyFile, $proxyContent) !== false) {
                    $task->proxyFile = $proxyFile;
                }
            }

            Bean::store($task);

            $baseDomain = $baseDomain ?? preg_replace('#^https?://#', '', $this->serverBaseurl());
            // Empty $baseDomain means this install has not been told its public
            // domain (see serverBaseurl) — say the port rather than invent a host.
            $testUrl = (!empty($task->proxyHash) && $baseDomain !== '')
                ? 'https://' . self::previewLabel($task->proxyHash, (string) $task->instanceTag) . '.' . $baseDomain
                : "http://localhost:{$task->assignedPort}";

            $this->logTaskEvent($task->id, 'info', 'system', "Test server auto-started on port {$task->assignedPort}");

            return [
                'session' => $sessionName,
                'port' => $task->assignedPort,
                'url' => $testUrl
            ];

        } catch (Exception $e) {
            $this->logger->warning('Auto-start test server failed', [
                'task_id' => $task->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
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
     * Get authcontrol levels available to current member
     * Members can only assign levels >= their own level (lower privilege or equal)
     *
     * @return array Levels the member can assign
     */
    private function getAuthcontrolLevels(): array {
        $memberLevel = $this->member->level ?? LEVELS['PUBLIC'];

        $availableLevels = [];
        foreach (LEVELS as $name => $value) {
            if ($value >= $memberLevel) {
                $availableLevels[$value] = [
                    'label' => ucfirst(strtolower($name)),
                    'value' => $value
                ];
            }
        }

        ksort($availableLevels);
        return $availableLevels;
    }

    /**
     * Recursively delete a directory
     *
     * @param string $dir Directory path to delete
     */
    private function recursiveDelete(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Generate .mcp.json for a workspace at run time
     *
     * Called every time a task is run to ensure fresh config with
     * correct baseurl from config.ini and valid API key.
     *
     * Delegates to Mcp::ensureMcpConfig() for centralized config management.
     *
     * @param string $workspacePath Path to the workspace
     * @param string|null $apiKey API key for tiknix MCP auth
     * @param string $baseUrl Base URL from config.ini
     */
    private function generateWorkspaceMcpConfig(string $workspacePath, ?string $apiKey, string $baseUrl): void {
        Mcp::ensureMcpConfig($workspacePath, $apiKey, $baseUrl);
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

    // ---- monday.com import -------------------------------------------------------
    //
    // Reached only when the selected project actually has an active monday
    // connection — see mondayConnection(). Everything here is scoped to the
    // SELECTED project: the instance comes from $this->selected, never from the
    // request, so a crafted id cannot import somebody else's board.

    /**
     * The selected project's monday connection, or null.
     *
     * Answered from CORE, because that is where the Connections hub stores it, and
     * this sidecar reaches core through its own PDO rather than through app\CoreDb.
     * Scoped to the instance for the reason ConnectionStore::forInstance exists:
     * core's table holds every project's rows, and an unscoped read here would show
     * one customer the boards of another.
     *
     * Cached per request — the nav asks, then the action asks again.
     */
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
    private function selectedInstanceDir(): string {
        if (!$this->selected) return '';
        return '/var/www/html/default/' . $this->selected['slug']
             . '.' . ($this->selected['app'] ?: 'tiknix');
    }

    private function mondayConnection(): ?\RedBeanPHP\OODBBean {
        static $cached = false, $conn = null;
        if ($cached) return $conn;
        $cached = true;

        if (!$this->selected) return $conn = null;

        // Read from the PROJECT's own store, not core's table. Connections moved to
        // <install>/data/connections.db (CONNECTIONS_PER_INSTANCE.md) and core's table
        // was emptied, so this query returned nothing and the monday nav simply
        // vanished -- indistinguishable from "this project never connected monday".
        //
        // Reading the project's file is also a NARROWER grant than what this replaced.
        // Before, the sidecar could see every customer's ciphertext in core's table and
        // asked core to decrypt one. Now it opens one project's database with one
        // project's key, and can decrypt nothing else.
        $dir = $this->selectedInstanceDir();
        $db  = $dir . '/data/connections.db';
        if ($dir === '' || !is_file($db)) return $conn = null;   // genuinely nothing connected

        try {
            $pdo = new \PDO('sqlite:' . $db, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            // Only columns guaranteed to exist: naming an absent one makes SQLite throw
            // here, but made RedBean answer with NOTHING, which reads as "not connected".
            $st = $pdo->prepare(
                "SELECT id, connector_type, access_token, external_name, environment, revoked_at
                   FROM connections
                  WHERE connector_type = 'monday' AND enabled = 1
                  ORDER BY CASE WHEN environment = 'production' THEN 0 ELSE 1 END, id DESC");
            $st->execute();

            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (!empty($row['revoked_at'])) continue;
                // Dispensed, never stored — a carrier for the fields MondayImport reads.
                $b = Bean::dispense('connections');
                $b->id            = (int) $row['id'];
                $b->connectorType = (string) $row['connector_type'];
                $b->accessToken   = (string) $row['access_token'];
                $b->externalName  = (string) $row['external_name'];
                $b->instanceId    = (int) $this->selected['id'];
                return $conn = $b;
            }
            return $conn = null;
        } catch (\Throwable $e) {
            // LOUD: this is "I could not tell you", which is not the same as "no monday
            // connection" and must not quietly render as a missing button.
            Flight::get('log')?->error('workbench: could not read the project\'s connections store', [
                'project' => $this->selected['slug'] ?? '', 'db' => $db, 'err' => $e->getMessage(),
            ]);
            return $conn = null;
        }
    }

    /** True when the selected project can import from monday — drives the nav. */
    public function hasMonday(): bool {
        return $this->mondayConnection() !== null;
    }

    /**
     * The selected project's own connection key, and the token it opens.
     *
     * No round-trip to core. The key lives at <install>/secure/connections.key and
     * belongs to that project alone, so this sidecar decrypts one project's
     * credential and has no means to touch another's — a smaller grant than the
     * arrangement it replaces, where core held one key for everybody's ciphertext.
     *
     * Throws rather than returning ''. The version this replaced returned an empty
     * string on every failure and the caller read it as "not connected", so an
     * unreadable key, an unreachable core and a genuinely absent connector were one
     * indistinguishable outcome.
     */
    private function mondayPlainToken(\RedBeanPHP\OODBBean $conn): string {
        $keyFile = $this->selectedInstanceDir() . '/secure/connections.key';
        if (!is_file($keyFile)) {
            throw new \RuntimeException('This project has no connections key at ' . $keyFile
                . ' — reconnect monday.com from its Connections page.');
        }

        $hex = trim((string) file_get_contents($keyFile));
        if (strlen($hex) !== 64 || !ctype_xdigit($hex)) {
            throw new \RuntimeException('The project\'s connections key is malformed.');
        }

        $raw = (string) ($conn->accessToken ?? '');
        $dec = base64_decode($raw, true);
        if ($dec === false || strlen($dec) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES + 1) {
            throw new \RuntimeException('The stored monday token is not a readable envelope.');
        }

        // Same envelope EncryptionService::encryptWith writes: base64(nonce . box).
        $key   = hex2bin($hex);
        $nonce = substr($dec, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($dec, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        sodium_memzero($key);

        if ($plain === false) {
            throw new \RuntimeException('The monday token would not decrypt with this project\'s key.');
        }
        return $plain;
    }

    /** Point MondayImport at this project: its connection, and its already-open db. */
    private function mondayReady(): bool {
        $conn = $this->mondayConnection();
        if (!$conn) return false;

        try {
            $plain = $this->mondayPlainToken($conn);
        } catch (\Throwable $e) {
            // Said out loud, not swallowed: the connection exists, so "not ready" here
            // is a fault worth a log line naming the reason.
            Flight::get('log')?->error('workbench: the project\'s monday token could not be opened',
                ['project' => $this->selected['slug'] ?? '', 'err' => $e->getMessage()]);
            return false;
        }

        \app\MondayImport::setToken($plain);
        \app\MondayImport::setConnection($conn);
        // BuildControl::selectInstance already pointed RedBean at this project's
        // workbench.db, so MondayImport must not go looking for one of its own.
        \app\MondayImport::useCurrentDatabase(true);
        // Where attachments may be written: this project's own install, under
        // secure/. Naming it is what permits the download at all — unset, monday
        // files stay referenced in the brief and nothing is fetched.
        \app\MondayImport::setInstallDir($this->selectedInstanceDir());
        return true;
    }

    /**
     * GET /workbench/monday — pick a board, then tick the items to build.
     */
    public function monday($params = []) {
        if (!$this->requireLogin()) return;

        if (!$this->mondayReady()) {
            $this->flash('error', 'This project has no active monday.com connection.');
            Flight::redirect('/workbench');
            return;
        }

        $instanceId = (int) $this->selected['id'];
        $boardId    = trim((string) $this->getParam('board', ''));
        $cursor     = trim((string) $this->getParam('cursor', ''));

        $this->viewData['title']   = 'Import from monday.com';
        $this->viewData['account'] = (string) $this->mondayConnection()->externalName;
        $this->viewData['boardId'] = $boardId;
        $this->viewData['items']   = [];
        $this->viewData['cursor']  = '';
        $this->viewData['error']   = '';

        try {
            $this->viewData['boards'] = \app\MondayImport::boards($instanceId);

            if ($boardId !== '') {
                // 100, not 50: a board's items are grouped in the picker, and a page
                // that stops halfway through "Murray Website" shows a group with a
                // count that is a page artefact rather than the size of the work.
                $page = \app\MondayImport::items($boardId, 100, $cursor, $instanceId);
                $this->viewData['items']  = $page['items'];
                $this->viewData['cursor'] = $page['cursor'];

                // The groups present on this page, in the order monday returned them,
                // with how many of each are still open. A board here is one client's
                // work split by site — Murray Website, Parts Website, Massport
                // Website — so the group IS the unit somebody wants to import.
                $groups = [];
                foreach ($page['items'] as $it) {
                    $g = (string) ($it['group'] ?? '');
                    if ($g === '') continue;
                    if (!isset($groups[$g])) $groups[$g] = ['name' => $g, 'total' => 0, 'open' => 0];
                    $groups[$g]['total']++;
                    if (empty($it['done']) && empty($it['imported'])) $groups[$g]['open']++;
                }
                $this->viewData['groups'] = array_values($groups);
            }
        } catch (\Throwable $e) {
            // monday's own words. "Complexity budget exhausted, reset in 45 seconds"
            // and "Not authenticated" need different reactions from whoever reads it.
            $this->viewData['boards'] = $this->viewData['boards'] ?? [];
            $this->viewData['error']  = $e->getMessage();
        }

        $this->render('workbench/monday', $this->viewData);
    }

    /**
     * POST /workbench/mondayimport — bring the ticked items in as tasks.
     *
     * Each becomes ONE parent task. Decomposition is the existing pipeline, run
     * afterwards from the board, so imported work goes through the same steps as
     * anything typed in by hand.
     */
    /**
     * POST /workbench/mondayrefresh — sync imported tasks with monday.
     *
     * One pass doing both halves: flags work monday has closed, and re-pulls the
     * title, brief and priority for items whose content changed. These were two
     * buttons making the identical call over the identical rows, which asked a
     * person to guess which they wanted when the answer is always both.
     *
     * It never DELETES. A board moving on is not permission to remove work
     * somebody may have started; the flag is what a person acts on. Task status,
     * comments and branches are never touched either — those are the work, and
     * monday knows nothing about them.
     *
     * What happened is stated in full rather than summarised to a count, because
     * "3 tasks flagged" sends you hunting for which three.
     */
    public function mondayrefresh($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Session expired, please try again.');
            Flight::redirect('/workbench');
            return;
        }
        if (!$this->mondayReady()) {
            $this->flash('error', 'This project has no active monday.com connection.');
            Flight::redirect('/workbench');
            return;
        }

        try {
            $r = \app\MondayImport::refresh((int) $this->selected['id']);
        } catch (\Throwable $e) {
            // monday's own wording — a complexity budget and a dead token want
            // different things done about them.
            Flight::get('log')?->error('workbench: monday refresh failed',
                ['project' => $this->selected['slug'] ?? '', 'err' => $e->getMessage()]);
            $this->flash('error', 'Could not re-check monday: ' . $e->getMessage());
            Flight::redirect('/workbench');
            return;
        }

        if (!$r['checked']) {
            $this->flash('info', 'No imported monday items to check.');
            Flight::redirect('/workbench');
            return;
        }

        // Written as sentences a person can act on. The version this replaces read
        // "updated 1 from monday — Product Images (brief). 1 no longer open — Parts
        // Catalog (deleted in monday). Nothing was deleted." — which put a raw field
        // name in brackets, and then followed "deleted in monday" with "Nothing was
        // deleted", so the reassurance looked like a contradiction of the sentence
        // before it.
        $label = ['title' => 'title', 'brief' => 'description', 'priority' => 'priority'];

        $sentences = ['Checked ' . $r['checked'] . ' monday item' . ($r['checked'] === 1 ? '' : 's') . '.'];

        if ($r['updated']) {
            $c = [];
            foreach ($r['changes'] as $ch) {
                $fields = array_map(fn($f) => $label[$f] ?? $f, $ch['fields']);
                $c[] = $ch['title'] . ' (' . implode(' and ', $fields) . ')';
            }
            $sentences[] = 'Brought ' . (count($c) === 1 ? 'one change' : count($c) . ' changes')
                         . ' across from monday: ' . implode('; ', $c) . '.';
        }

        if ($r['flagged']) {
            $f = [];
            foreach ($r['flagged'] as $fl) $f[] = $fl['title'] . ' — ' . $fl['status'];
            // The reassurance goes HERE, attached to the flag it is about, rather
            // than trailing the whole message where it reads as a denial of it.
            $sentences[] = (count($f) === 1 ? 'One item is' : count($f) . ' items are')
                         . ' no longer open in monday: ' . implode('; ', $f) . '. '
                         . (count($f) === 1 ? 'Its task is' : 'Those tasks are')
                         . ' still here and unchanged — flagged only, so you can decide.';
        }

        if (count($sentences) === 1) {
            $this->flash('success', 'Checked ' . $r['checked'] . ' monday item'
                . ($r['checked'] === 1 ? '' : 's') . ' — all still open, nothing changed.');
        } else {
            $this->flash($r['flagged'] ? 'warning' : 'success', implode(' ', $sentences));
        }

        Flight::redirect('/workbench');
    }

    public function mondayimport($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Session expired, please try again.');
            Flight::redirect('/workbench/monday');
            return;
        }
        if (!$this->mondayReady()) {
            $this->flash('error', 'This project has no active monday.com connection.');
            Flight::redirect('/workbench');
            return;
        }

        $boardId = trim((string) $this->getParam('board', ''));
        $ticked  = (array) ($this->getParam('items', []) ?: []);
        if (!$ticked) {
            $this->flash('error', 'Nothing selected.');
            Flight::redirect('/workbench/monday?board=' . urlencode($boardId));
            return;
        }

        $instanceId = (int) $this->selected['id'];
        $tag        = $this->selected['slug'] . '.' . ($this->selected['app'] ?: 'tiknix');

        try {
            // Re-read from monday rather than trusting posted names: the form carries
            // ids, and the title and columns that become the brief should be what the
            // board says now, not what a page rendered some minutes ago.
            $page  = \app\MondayImport::items($boardId, 100, '', $instanceId);
            $byId  = [];
            foreach ($page['items'] as $i) $byId[(string) $i['id']] = $i + ['board_id' => $boardId];

            $chosen = [];
            foreach ($ticked as $id) {
                $id = (string) $id;
                if (isset($byId[$id])) $chosen[] = $byId[$id];
            }

            $res = \app\MondayImport::import($chosen, $instanceId, $tag, (int) $this->member->id);

            $msg = $res['created'] . ' task' . ($res['created'] === 1 ? '' : 's') . ' imported';
            if ($res['skipped']) $msg .= ', ' . $res['skipped'] . ' already here';
            $this->flash($res['created'] ? 'success' : 'info', $msg . '.');
        } catch (\Throwable $e) {
            $this->flash('error', 'monday.com: ' . $e->getMessage());
            Flight::redirect('/workbench/monday?board=' . urlencode($boardId));
            return;
        }

        Flight::redirect('/workbench');
    }

    /**
     * POST /workbench/mondaypost — send one task's finished children back.
     *
     * Manual and per task, so nothing reaches a client's board unprompted. Creating
     * subitems adds a subitems column to a board that has never had one, which is a
     * change to their board and another reason this is not automatic.
     */
    public function mondaypost($params = []) {
        if (!$this->requireLogin()) return;
        if (!Flight::csrf()->validateRequest()) {
            $this->flash('error', 'Session expired, please try again.');
            Flight::redirect('/workbench');
            return;
        }
        if (!$this->mondayReady()) {
            $this->flash('error', 'This project has no active monday.com connection.');
            Flight::redirect('/workbench');
            return;
        }

        $taskId = (int) $this->getParam('task_id', 0);

        // The task must live in the SELECTED project's workbench.db, which
        // BuildControl has already opened. Deliberately not findTaskInstance():
        // task ids are per-database, so id 1 exists in most of them, and that
        // helper returns the first instance it finds one in — which is how a task
        // in this project was reported as belonging to another. It also leaves
        // RedBean pointed at whichever database it stopped scanning on.
        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id || empty($task->mondayEid)) {
            $this->flash('error', 'That task did not come from monday.com.');
            Flight::redirect('/workbench');
            return;
        }

        $res = \app\MondayImport::postBack($taskId, (int) $this->selected['id']);

        if (!$res['posted']) {
            $this->flash('error', $res['reason']);
        } else {
            $msg = $res['subitems'] . ' subitem' . ($res['subitems'] === 1 ? '' : 's') . ' posted to monday.com';
            $this->flash($res['failed'] ? 'error' : 'success',
                $res['failed'] ? $msg . ', ' . $res['reason'] : $msg . '.');
        }

        Flight::redirect('/workbench/view?id=' . $taskId);
    }
}
