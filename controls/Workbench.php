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
use \app\WorkspaceManager;
use \app\EngineRegistry;
use \app\MemberEnginePrefs;
use \Exception as Exception;
use app\BaseControls\Control;

class Workbench extends Control {

    private TaskAccessControl $access;

    public function __construct() {
        parent::__construct();
        $this->access = new TaskAccessControl();
        $this->requireBuilderTools('The Workbench');
    }

    /**
     * Task dashboard
     */
    public function index($params = []) {
        if (!$this->requireLogin()) return;

        $this->viewData['title'] = 'AI Projects';

        // Freshly-ingested tasks (written by the headless plan-ingest.php CLI, which
        // has its own DB connection) don't invalidate this web process's query cache,
        // so a just-decomposed plan could stay hidden for up to the cache TTL. Bust
        // the task table before reading so newly-landed plans show immediately.
        $ad = Flight::get('cachedDatabaseAdapter');
        if ($ad instanceof \app\CachedDatabaseAdapter) $ad->invalidateTable('workbenchtask');

        // Get filter parameters
        $filters = [
            'status' => $this->getParam('status'),
            'task_type' => $this->getParam('type'),
            'team_id' => $this->getParam('team_id'),
            'priority' => $this->getParam('priority'),
            'instance_tag' => $this->getParam('instance_tag'),
            'order_by' => $this->getParam('order_by', 'updated_at DESC')
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
        $this->viewData['canCreate'] = Flight::hasLevel(LEVELS['ADMIN']);
        $this->viewData['engines']   = \app\EngineRegistry::menu();
        $this->viewData['planMeta'] = $planMeta;
        $this->viewData['parentIdsWithChildren'] = array_keys($childParentIds);
        $this->viewData['decomposingInstance'] = (int)$this->getParam('decomposing', 0);

        // Persistent "decomposing…" indicator: find planner sessions still alive for
        // THIS member so the banner shows even after they navigate away from the
        // create page. Session name mirrors PlanRunner: tiknix-<memberId>-plan-<slug>.
        $decomposing = [];
        $prefix = 'tiknix-' . (int)$this->member->id . '-plan-';
        $active = \app\TmuxManager::list($prefix);
        if ($active) {
            $accIds = $this->access->getAccessibleInstanceIds((int)$this->member->id);
            if ($accIds) {
                $ph = implode(',', array_fill(0, count($accIds), '?'));
                foreach (Bean::find('instance', "id IN ($ph) ORDER BY slug ASC", $accIds) as $inst) {
                    if (in_array($prefix . $inst->slug, $active, true)) {
                        $decomposing[] = ['id' => (int)$inst->id, 'tag' => $inst->slug . '.' . ($inst->app ?: 'tiknix')];
                    }
                }
            }
        }
        $this->viewData['decomposingInstances'] = $decomposing;

        $this->render('workbench/index', $this->viewData);
    }

    /**
     * Create task form
     */
    public function create($params = []) {
        if (!$this->requireLogin()) return;

        $this->viewData['title'] = 'Create Task';

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
        $this->viewData['taskTypes'] = $this->getTaskTypes();
        $this->viewData['priorities'] = $this->getPriorities();
        $this->viewData['authcontrolLevels'] = $this->getAuthcontrolLevels();
        $this->viewData['memberLevel'] = $this->member->level;
        $this->viewData['branches'] = $remoteBranches;
        $this->viewData['currentBranch'] = in_array($currentBranch, $remoteBranches) ? $currentBranch : 'main';

        // AI Builder instances (tenants) a task can target: ones this member OWNS
        // plus ones shared with their teams. A shared workspace is labelled so.
        $instances = [];
        $accessibleIds = $this->access->getAccessibleInstanceIds($this->member->id);
        if (!empty($accessibleIds)) {
            $ph = implode(',', array_fill(0, count($accessibleIds), '?'));
            $rows = Bean::find('instance', "id IN ($ph) AND status = ? ORDER BY slug ASC",
                array_merge($accessibleIds, ['active']));
            foreach ($rows as $inst) {
                $tag      = $inst->slug . '.' . ($inst->app ?: 'tiknix');
                $isShared = (int)$inst->memberId !== (int)$this->member->id;
                $instances[] = [
                    'id'    => (int)$inst->id,
                    'tag'   => $tag,
                    'label' => ($inst->displayName ? $inst->displayName . ' — ' : '') . $tag . ($isShared ? ' (shared)' : ''),
                ];
            }
        }
        $this->viewData['instances'] = $instances;
        // Pre-select an instance: by id (AI Builder "Plan & build in the Workbench") or
        // by tag (New Task from a filtered /workbench?instance_tag=... view).
        $preselectInstanceId = (int)$this->getParam('instance_id', 0);
        if (!$preselectInstanceId) {
            $tag = (string)$this->getParam('instance_tag', '');
            if ($tag !== '') {
                foreach ($instances as $inst) {
                    if (($inst['tag'] ?? '') === $tag) { $preselectInstanceId = (int)$inst['id']; break; }
                }
            }
        }
        $this->viewData['preselectInstanceId'] = $preselectInstanceId;

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

        // Instance (tenant) is required — must be one the member owns OR one shared
        // with their teams (mirrors the create-form picker and getVisibleTasks).
        $instanceId = (int)$this->getParam('instance_id', 0);
        $instance = $instanceId ? Bean::load('instance', $instanceId) : null;
        if (!$instance || !$instance->id || !$this->access->canAccessInstance((int)$this->member->id, (int)$instance->id)) {
            $this->flash('error', 'Please select a valid instance for this task');
            Flight::redirect('/workbench/create');
            return;
        }

        try {
            $task = Bean::dispense('workbenchtask');
            $task->title = $title;
            $task->description = trim($this->getParam('description', ''));
            $task->taskType = $this->getParam('task_type', 'feature');
            $task->priority = (int)$this->getParam('priority', 3);
            $task->status = 'pending';
            $task->memberId = $this->member->id;
            $task->teamId = $teamId;
            $task->authcontrolLevel = $authcontrolLevel;
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

            $this->logger->info('Task created', [
                'task_id' => $task->id,
                'title' => $title,
                'team_id' => $teamId,
                'member_id' => $this->member->id
            ]);

            $this->flash('success', 'Task created successfully');
            Flight::redirect('/workbench/view?id=' . $task->id);

        } catch (Exception $e) {
            $this->logger->error('Failed to create task', ['error' => $e->getMessage()]);
            $this->flash('error', 'Failed to create task');
            Flight::redirect('/workbench/create');
        }
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

        // Instance (tenant) is required — one the member owns OR one shared with
        // their teams (mirrors the create picker and store/getVisibleTasks).
        $instanceId = (int)$this->getParam('instance_id', 0);
        $instance = $instanceId ? Bean::load('instance', $instanceId) : null;
        if (!$instance || !$instance->id || !$this->access->canAccessInstance((int)$this->member->id, (int)$instance->id)) {
            $this->flash('error', 'Please select a valid instance to decompose for');
            Flight::redirect('/workbench/create');
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

        try {
            $runner = new PlanRunner(
                $slug, $instanceDir, (int)$this->member->id,
                (int)$this->member->level, (string)($instance->engine ?: 'claude')
            );
            $runner->start($goal);
        } catch (\Throwable $e) {
            $this->logger->error('Workbench decompose failed', ['error' => $e->getMessage(), 'instance' => $slug]);
            $this->flash('error', 'Could not start the planner: ' . $e->getMessage());
            Flight::redirect('/workbench/create');
            return;
        }

        $this->logger->info('Workbench decompose started', ['instance' => $slug, 'member_id' => $this->member->id]);
        // Stay in the Workbench: the planner ingests itself when it finishes
        // (scripts/plan-ingest.php), so the plan appears here automatically. The
        // decomposing banner polls and refreshes the list when it lands.
        $this->flash('info', 'Decomposing your goal for ' . $slug . '.' . $app . ' — the plan will appear here shortly.');
        Flight::redirect('/workbench?instance_tag=' . urlencode($slug . '.' . $app) . '&decomposing=' . (int)$instance->id);
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

        $inst = $instanceId ? Bean::load('instance', $instanceId) : null;
        if (!$inst || !$inst->id || !$this->access->canAccessInstance((int)$this->member->id, (int)$inst->id)) { Flight::jsonError('No valid instance for these tasks.', 409); return; }

        // Don't consolidate tasks whose plan is actively building.
        foreach (array_keys($parentIds) as $pid) {
            if (TmuxManager::exists('tiknix-plan' . $pid . '-orchestrator')) {
                Flight::jsonError('A plan involved is currently building — stop it before consolidating.', 409);
                return;
            }
        }

        $slug = (string)$inst->slug;
        $app  = $inst->app ?: 'tiknix';
        $instanceDir = '/var/www/html/default/' . $slug . '.' . $app;
        if (!is_file($instanceDir . '/public/index.php')) { Flight::jsonError('That instance is not available on disk.', 409); return; }

        try {
            $runner = new PlanRunner(
                $slug, $instanceDir, (int)$this->member->id,
                (int)$this->member->level, (string)($inst->engine ?: 'claude')
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
        $inst = $plan->instanceId ? Bean::load('instance', (int)$plan->instanceId) : null;
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
        $inst = $plan->instanceId ? Bean::load('instance', (int)$plan->instanceId) : null;
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
        if (TmuxManager::exists('tiknix-plan' . (int)$plan->id . '-orchestrator')) { Flight::jsonError('This plan is already running.', 409); return; }
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

    /** Launch the detached worktree orchestrator for a plan. Returns true on success. */
    private function startOrchestrator($plan, $inst): bool {
        $dir = '/var/www/html/default/' . $inst->slug . '.' . ($inst->app ?: 'tiknix');
        $cmd = 'php ' . escapeshellarg(dirname(__DIR__) . '/scripts/plan-orchestrate.php')
             . ' --plan=' . (int)$plan->id
             . ' --slug=' . escapeshellarg((string)$inst->slug)
             . ' --dir='  . escapeshellarg($dir)
             . ' --model=sonnet'
             . ' --level=' . (int)$this->member->level;
        $ab = $dir . '/.aibuilder';
        @mkdir($ab, 0775, true);
        $scriptFile = $ab . '/run-orchestrator.sh';
        file_put_contents($scriptFile, "#!/bin/bash\n" . $cmd . ' 2>&1 | tee ' . escapeshellarg($ab . '/orchestrator.log') . "\n");
        @chmod($scriptFile, 0755);
        return TmuxManager::create('tiknix-plan' . (int)$plan->id . '-orchestrator', $scriptFile, $dir);
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
        if (!in_array($task->status, ['failed', 'conflict'], true)) { Flight::jsonError('Only a failed task can be retried.', 409); return; }

        $plan = Bean::load('workbenchtask', (int)$task->parentTaskId);
        if (!$plan->id) { Flight::jsonError('Parent plan not found', 404); return; }
        $inst = $plan->instanceId ? Bean::load('instance', (int)$plan->instanceId) : null;
        if (!$inst || !$inst->id) { Flight::jsonError('This plan has no linked instance.', 409); return; }
        if (TmuxManager::exists('tiknix-plan' . (int)$plan->id . '-orchestrator')) {
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
        [$plan] = $pi;
        if ($plan->planStatus === 'building' || TmuxManager::exists('tiknix-plan' . (int)$plan->id . '-orchestrator')) {
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

    /** GET /workbench/decomposestatus — is the planner still decomposing for an instance? JSON. */
    public function decomposestatus($params = []) {
        if (!$this->requireLogin()) return;
        $instanceId = (int)$this->getParam('instance_id', 0);
        $inst = $instanceId ? Bean::load('instance', $instanceId) : null;
        if (!$inst || !$inst->id || !$this->access->canAccessInstance((int)$this->member->id, (int)$inst->id)) {
            Flight::jsonError('No such instance', 404);
            return;
        }
        $session = 'tiknix-' . (int)$this->member->id . '-plan-' . $inst->slug;
        $newest  = (int)Bean::getCell(
            'SELECT MAX(id) FROM workbenchtask WHERE instance_id = ? AND parent_task_id IS NULL',
            [(int)$inst->id]
        );
        Flight::jsonSuccess(['running' => TmuxManager::exists($session), 'newest_plan_id' => $newest]);
    }

    /** Absolute path to a plan subtask's executor agent log (stream-json), or '' if unknown. */
    private function agentLogPath($task): string {
        $inst = $task->instanceId ? Bean::load('instance', (int)$task->instanceId) : null;
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
        $ad = Flight::get('cachedDatabaseAdapter');
        if ($ad instanceof \app\CachedDatabaseAdapter) $ad->invalidateTable('workbenchtask');

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
        // "tiknix-plan<N>-task<M>" session), NOT by ClaudeRunner. Never let this
        // view's poller touch them — its ClaudeRunner session name won't match, so
        // exists() returns false and it would race the executor by force-failing a
        // live subtask.
        $isPlanManaged = !empty($task->planRef)
            || !empty($task->worktreeBranch)
            || strncmp((string)$task->agentSession, 'tiknix-plan', 11) === 0;
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
                // Session ended but status not updated - check if completed or failed
                $task->status = 'failed';
                $task->progressMessage = 'Session ended unexpectedly';
                $task->updatedAt = date('Y-m-d H:i:s');
                Bean::store($task);
            }
        }

        // Get task logs
        $logs = Bean::find('tasklog', 'task_id = ? ORDER BY created_at DESC LIMIT 50', [$taskId]);

        // Get task comments (including image_path for attached images)
        $comments = Bean::getAll(
            "SELECT tc.*, tc.image_path, m.first_name, m.last_name, m.username, m.email, m.avatar_url
             FROM taskcomment tc
             JOIN member m ON tc.member_id = m.id
             WHERE tc.task_id = ?
             ORDER BY tc.created_at ASC",
            [$taskId]
        );

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
            TmuxManager::kill('tiknix-plan' . $taskId . '-orchestrator');
            $subtaskCount = 0;
            foreach (Bean::find('workbenchtask', 'parent_task_id = ?', [$taskId]) as $sub) {
                if (!empty($sub->agentSession)) TmuxManager::kill((string)$sub->agentSession);
                $sub->xownTasklogList;
                $sub->xownTasksnapshotList;
                $sub->xownTaskcommentList;
                Bean::trash($sub);
                $subtaskCount++;
            }

            // Remember the instance so we return to the same filtered workbench view.
            $instanceTag = (string)($task->instanceTag ?? '');

            Bean::trash($task);

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

        // Check if already running
        if ($task->status === 'running') {
            Flight::jsonError('Task is already running', 400);
            return;
        }

        // Assign port for this member
        $portInfo = PortManager::getTaskPortInfo($this->member->id);
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
                $workspacePath = $mainGit->cloneToWorkspace($this->member->id, $task->id, $cloneUrl, $baseBranch);
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
                    $inst = Bean::load('instance', (int)$task->instanceId);
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
            try {
                $apiKey = $this->getOrCreateWorkbenchApiKey($this->member->id);
                $baseUrl = Flight::get('app.baseurl') ?? 'https://dev.tiknix.com';
                $this->generateWorkspaceMcpConfig($workspacePath, $apiKey, $baseUrl);
                $this->logTaskEvent($taskId, 'info', 'system', "Generated .mcp.json with baseurl: {$baseUrl}");
            } catch (Exception $e) {
                $this->logger->warning('Failed to generate workspace MCP config', ['error' => $e->getMessage()]);
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
                $this->logger->warning('Failed to send prompt to Claude session', ['task_id' => $taskId]);
            }

            // Update status to running
            $task->status = 'running';
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'info', 'system', 'Claude session started by ' . ($this->member->displayName ?? $this->member->email));

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
                $apiKey = $this->getOrCreateWorkbenchApiKey($this->member->id);
                $baseUrl = Flight::get('app.baseurl') ?? 'https://dev.tiknix.com';
                $this->generateWorkspaceMcpConfig($workspacePath, $apiKey, $baseUrl);
                $this->logTaskEvent($taskId, 'info', 'system', "Regenerated .mcp.json for re-run");
            } catch (Exception $e) {
                $this->logger->warning('Failed to regenerate workspace MCP config', ['error' => $e->getMessage()]);
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
        $workerModel   = EngineRegistry::model($authorEngine, 'worker', 'sonnet');
        // The member resolving the conflict may override the resolver model in settings;
        // absent that, the engine's resolver tier (defaults to its frontier/planner model).
        $resolverModel = MemberEnginePrefs::model((int)$this->member->id, $authorEngine, 'resolver',
                            EngineRegistry::model($authorEngine, 'planner', 'opus'));

        try {
            $runner = new ClaudeRunner($taskId, $this->member->id, $task->teamId, $ws, $this->member->level);
            if ($resolverModel !== '' && $resolverModel !== $workerModel) {
                $runner->setModelOverride($resolverModel);
            }
            if ($runner->exists()) { $runner->kill(); usleep(400000); }
            try {
                $apiKey  = $this->getOrCreateWorkbenchApiKey($this->member->id);
                $baseUrl = Flight::get('app.baseurl') ?? 'https://dev.tiknix.com';
                $this->generateWorkspaceMcpConfig($ws, $apiKey, $baseUrl);
            } catch (Exception $e) { /* non-fatal */ }

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

        // Must have a branch and port assigned
        if (empty($task->branchName)) {
            Flight::jsonError('No branch assigned to this task', 400);
            return;
        }

        if (empty($task->assignedPort)) {
            Flight::jsonError('No port assigned to this task', 400);
            return;
        }

        // Check if test server session already exists
        if (!empty($task->testServerSession)) {
            if (TmuxManager::exists($task->testServerSession)) {
                Flight::jsonError('Test server is already running', 400);
                return;
            }
            $task->testServerSession = null;
        }

        // Check if port is available
        if (!PortManager::isPortAvailable($task->assignedPort)) {
            Flight::jsonError("Port {$task->assignedPort} is already in use", 400);
            return;
        }

        try {
            $sessionName = TmuxManager::buildServerSessionName($this->member->id, $task->id);
            $initMessages = [];

            // Use workspace path if available, otherwise default to main project
            $projectPath = !empty($task->projectPath) ? $task->projectPath : dirname(__DIR__);

            // Generate proxy hash if not exists
            if (empty($task->proxyHash)) {
                $task->proxyHash = bin2hex(random_bytes(6));
                $initMessages[] = "Generated proxy hash: {$task->proxyHash}";
            }

            // Initialize or refresh workspace environment
            if (!empty($task->projectPath) && is_dir($task->projectPath)) {
                // Pull latest changes from branch
                $pullCmd = sprintf(
                    'cd %s && git pull origin %s 2>&1',
                    escapeshellarg($task->projectPath),
                    escapeshellarg($task->branchName)
                );
                exec($pullCmd, $pullOutput, $pullCode);
                if ($pullCode === 0) {
                    $initMessages[] = "Pulled latest changes from {$task->branchName}";
                } else {
                    // Not fatal - might be a local-only branch
                    $this->logger->info('Git pull skipped (local branch)', ['output' => implode("\n", $pullOutput)]);
                }

                // Initialize workspace with fresh database and config
                $wsManager = new WorkspaceManager();
                $wsInfo = $wsManager->initialize($task->projectPath, $task->proxyHash);
                $initMessages[] = "Initialized workspace: {$wsInfo['baseurl']}";
                $initMessages[] = "Fresh database created with admin/admin1234";

                // Workspace mode - already in isolated clone on correct branch
                $serverCmd = sprintf(
                    'cd %s && php -S 0.0.0.0:%d server.php; echo "Server stopped. Press Enter to close..."; read',
                    escapeshellarg($projectPath),
                    $task->assignedPort
                );
            } else {
                // Main project mode - need to checkout branch
                $serverCmd = sprintf(
                    'cd %s && git checkout %s && php -S 0.0.0.0:%d server.php; echo "Server stopped. Press Enter to close..."; read',
                    escapeshellarg($projectPath),
                    escapeshellarg($task->branchName),
                    $task->assignedPort
                );
            }

            // Use TmuxManager to create the session
            TmuxManager::create($sessionName, $serverCmd, $projectPath);

            $task->testServerSession = $sessionName;
            $task->updatedAt = date('Y-m-d H:i:s');

            // Create .proxy file for nginx subdomain routing (if proxyHash exists)
            // File format: proxyhost=X\nproxyport=Y (lua loadEnvFile expects key=value)
            // Filename: .proxy.{hash}.{domain} (no TLD - nginx lua strips it)
            if (!empty($task->proxyHash)) {
                $baseDomain = preg_replace('#^https?://#', '', Flight::get('baseurl') ?? 'https://localhost');
                // Strip TLD (e.g., .com, .net) - nginx lua expects domain without TLD
                $baseDomain = preg_replace('/\.[a-z]{2,}$/i', '', $baseDomain);
                $proxyFile = "/var/www/html/.proxy.{$task->proxyHash}.{$baseDomain}";
                $proxyContent = "proxyhost=127.0.0.1\nproxyport={$task->assignedPort}";
                if (file_put_contents($proxyFile, $proxyContent) !== false) {
                    $task->proxyFile = $proxyFile;
                    $this->logTaskEvent($taskId, 'info', 'system', "Created proxy file: {$proxyFile}");
                } else {
                    $this->logger->warning("Failed to create proxy file: {$proxyFile}");
                }
            }

            Bean::store($task);

            // Log initialization messages
            foreach ($initMessages as $msg) {
                $this->logTaskEvent($taskId, 'info', 'system', $msg);
            }
            $this->logTaskEvent($taskId, 'info', 'system', "Test server started on port {$task->assignedPort}");

            // Build response with subdomain URL if available
            $baseDomain = preg_replace('#^https?://#', '', Flight::get('baseurl') ?? 'https://localhost');
            $testUrl = "http://localhost:{$task->assignedPort}";
            if (!empty($task->proxyHash)) {
                $testUrl = "https://{$task->proxyHash}.{$baseDomain}";
            }

            $message = "Test server started on port {$task->assignedPort}";
            if (!empty($initMessages)) {
                $message .= " (" . implode(", ", $initMessages) . ")";
            }

            Flight::json([
                'success' => true,
                'message' => $message,
                'session' => $sessionName,
                'port' => $task->assignedPort,
                'url' => $testUrl,
                'subdomain' => !empty($task->proxyHash) ? "{$task->proxyHash}.{$baseDomain}" : null,
                'init_details' => $initMessages
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to start test server', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to start test server: ' . $e->getMessage(), 500);
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

        if (empty($task->testServerSession)) {
            Flight::jsonError('No test server is running', 400);
            return;
        }

        try {
            TmuxManager::kill($task->testServerSession);

            // Delete .proxy file for nginx subdomain routing
            if (!empty($task->proxyFile) && file_exists($task->proxyFile)) {
                if (unlink($task->proxyFile)) {
                    $this->logTaskEvent($taskId, 'info', 'system', "Deleted proxy file: {$task->proxyFile}");
                } else {
                    $this->logger->warning("Failed to delete proxy file: {$task->proxyFile}");
                }
            }

            $task->testServerSession = null;
            $task->proxyFile = null;
            $task->updatedAt = date('Y-m-d H:i:s');
            Bean::store($task);

            $this->logTaskEvent($taskId, 'info', 'system', 'Test server stopped');

            Flight::json([
                'success' => true,
                'message' => 'Test server stopped'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to stop test server', ['error' => $e->getMessage()]);
            Flight::jsonError('Failed to stop test server: ' . $e->getMessage(), 500);
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
            || strncmp((string)$task->agentSession, 'tiknix-plan', 11) === 0;
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

        // Get recent comments for live updates
        $comments = Bean::getAll(
            "SELECT tc.id, tc.content, tc.image_path, tc.is_from_claude, tc.created_at,
                    m.first_name, m.last_name, m.username
             FROM taskcomment tc
             JOIN member m ON tc.member_id = m.id
             WHERE tc.task_id = ?
             ORDER BY tc.created_at ASC",
            [$taskId]
        );
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
        $inst = Bean::load('instance', (int)$task->instanceId);
        if (!$inst->id) return null;
        $dir = '/var/www/html/default/' . $inst->slug . '.' . ($inst->app ?: 'tiknix');
        return is_dir($dir . '/.git') ? $dir : null;
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
            $sessionName = TmuxManager::buildServerSessionName($memberId, $task->id);
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
                $baseDomain = preg_replace('#^https?://#', '', Flight::get('baseurl') ?? 'https://localhost');
                // Strip TLD (e.g., .com, .net) - nginx lua expects domain without TLD
                $baseDomain = preg_replace('/\.[a-z]{2,}$/i', '', $baseDomain);
                $proxyFile = "/var/www/html/.proxy.{$task->proxyHash}.{$baseDomain}";
                $proxyContent = "proxyhost=127.0.0.1\nproxyport={$task->assignedPort}";
                if (file_put_contents($proxyFile, $proxyContent) !== false) {
                    $task->proxyFile = $proxyFile;
                }
            }

            Bean::store($task);

            $baseDomain = $baseDomain ?? preg_replace('#^https?://#', '', Flight::get('baseurl') ?? 'https://localhost');
            $testUrl = !empty($task->proxyHash)
                ? "https://{$task->proxyHash}.{$baseDomain}"
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

        // Check for existing workbench key
        $existingKey = Bean::findOne('apikey',
            'member_id = ? AND name = ? AND is_active = 1 AND (expires_at IS NULL OR expires_at > ?)',
            [$memberId, $keyName, date('Y-m-d H:i:s')]
        );

        if ($existingKey) {
            return $existingKey->token;
        }

        // Create new workbench API key
        try {
            $key = Bean::dispense('apikey');
            $key->memberId = $memberId;
            $key->name = $keyName;
            $key->token = 'tk_' . bin2hex(random_bytes(32));
            $key->scopes = json_encode(['mcp:tools']); // Limited to MCP tools only
            $key->allowedServers = json_encode([]); // All servers
            $key->isActive = 1;
            $key->expiresAt = date('Y-m-d H:i:s', strtotime('+1 year')); // 1 year expiry
            $key->createdAt = date('Y-m-d H:i:s');
            $key->usageCount = 0;
            Bean::store($key);

            $this->logger->info('Created workbench API key', [
                'member_id' => $memberId,
                'key_id' => $key->id
            ]);

            return $key->token;
        } catch (Exception $e) {
            $this->logger->error('Failed to create workbench API key', [
                'member_id' => $memberId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
