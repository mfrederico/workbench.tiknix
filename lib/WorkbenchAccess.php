<?php
/**
 * WorkbenchAccess — the sidecar's instance-scoped replacement for core's
 * lib/TaskAccessControl. Same method surface the Workbench controller already calls
 * ($this->access->canRun / getVisibleTasks / getInstanceTags / …), but under the
 * per-instance model (decided 2026-07-24):
 *
 *   Access = "can you reach this instance" (Sidecar\Access, computed from core's
 *   team/instance tables, read-only). Anyone with access to an instance sees ALL its
 *   tasks. There is no per-task team ACL — a task belongs to exactly one instance and
 *   lives in that instance's workbench.db.
 *
 * The controller selects ONE instance's workbench.db per request (setCurrent), so the
 * task-side methods here just query the currently-selected DB. The identity/instance
 * side (which instances, ownership, admin level) is answered from core via Sidecar\Access.
 */
namespace app;

use \Flight as Flight;
use \app\Bean;
use \app\Sidecar\Access;

class WorkbenchAccess {

    /**
     * Board tabs that cover more than one stored status.
     *
     * "Running" is what a person calls a task the agent is holding, whether the row says
     * running or queued — which is why views/workbench/index.php already adds both for the
     * badge. Kept here, beside the query that uses it, so the number and the list cannot
     * mean different things again. Anything absent filters on itself.
     */
    public const STATUS_BUCKETS = [
        'running' => ['running', 'queued'],
    ];

    private Access $core;
    private \PDO $pdo;
    private int $memberId;
    /** @var array<int,array> accessible instances, keyed by id */
    private array $instances = [];
    /** @var array|null the instance whose workbench.db is currently selected */
    private ?array $current = null;

    public function __construct(int $memberId, \PDO $coreDb) {
        $this->memberId = $memberId;
        $this->pdo = $coreDb;
        $this->core = new Access($coreDb);
        foreach ($this->core->instances($memberId) as $i) $this->instances[$i['id']] = $i;
        // The "(default)" core instance is the live control plane (core.tiknix symlinks to the
        // running app), not a buildable instance — real core changes go through a normal
        // instance + PR. Exclude it from the ENTIRE sidecar (AI Builder + Task Board) here,
        // at the source, so no picker, board, or open path ever surfaces it.
        try {
            $st = $this->pdo->query('SELECT id FROM instance WHERE is_default = 1');
            foreach ($st->fetchAll(\PDO::FETCH_COLUMN) as $defId) unset($this->instances[(int) $defId]);
        } catch (\Throwable $e) { /* column absent → nothing to exclude */ }
    }

    /**
     * Instance metadata from CORE as a camelCase object (the sidecar replacement for
     * Bean::load('instance', $id) — the instance table lives in core, not workbench.db).
     * Access-gated: returns null for an instance the member can't reach.
     */
    public function instanceMeta(int $id): ?object {
        if (!isset($this->instances[$id])) return null;
        try {
            $st = $this->pdo->prepare('SELECT * FROM instance WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $r = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { $r = []; }
        // Expose EVERY column camelCased so it's a drop-in for a read-only instance bean
        // (Aibuilder reads $inst->slug / ->app / ->memberId / ->displayName / ->engine / …).
        $o = new \stdClass();
        foreach ($r as $k => $v) {
            $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $k))));
            $o->$camel = $v;
        }
        $o->id       = $id;
        if (!isset($o->slug))     $o->slug     = $this->instances[$id]['slug'];
        if (!isset($o->app))      $o->app      = $this->instances[$id]['app'];
        if (!isset($o->memberId)) $o->memberId = 0;
        return $o;
    }

    /**
     * The same metadata, found by slug (+app) instead of id.
     *
     * Searches only the member's ACCESSIBLE instances, so it inherits instanceMeta's gate
     * rather than adding a second, weaker way in. Needed because a prompt records what it
     * was for as a tag ("<slug>.<app>") — the id would be meaningless if that project were
     * ever rebuilt.
     */
    public function instanceBySlug(string $slug, string $app = 'tiknix'): ?object {
        $slug = strtolower(trim($slug));
        $app  = strtolower(trim($app)) ?: 'tiknix';
        foreach ($this->instances as $id => $i) {
            if (strtolower((string) $i['slug']) !== $slug) continue;
            if (strtolower((string) ($i['app'] ?: 'tiknix')) !== $app) continue;
            return $this->instanceMeta((int) $id);
        }
        return null;
    }

    /** Team ids a given instance is shared with (core instance_team, read-only). */
    public function teamIdsForInstance(int $instanceId): array {
        if ($instanceId <= 0) return [];
        try {
            $st = $this->pdo->prepare('SELECT team_id FROM instance_team WHERE instance_id = ?');
            $st->execute([$instanceId]);
            return array_values(array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN)));
        } catch (\Throwable $e) { return []; }
    }

    /** The accessible instance (row) whose workbench.db holds this task id, or null. */
    public function findTaskInstance(int $taskId): ?array {
        if ($taskId <= 0) return null;
        foreach ($this->instances as $inst) {
            try {
                WorkbenchDb::selectInstance($inst);
                if ((int) Bean::getCell("SELECT id FROM workbenchtask WHERE id = ?", [$taskId]) === $taskId) {
                    return $inst;
                }
            } catch (\Throwable $e) { /* table absent */ }
        }
        return null;
    }

    /** Accessible instances (list), each [{id,slug,app,name,owned}]. */
    /**
     * Accessible instances KEYED BY ID. Not a list: array_values() here is what handed
     * every caller an `[0]` to use as a default, and a default is how a surface ends up
     * addressing an instance the member never chose. Name one, or ask for the project.
     *
     * @return array<int,array>
     */
    public function accessibleInstances(): array { return $this->instances; }

    /** One accessible instance by id, or null. */
    public function instance(int $id): ?array { return $this->instances[$id] ?? null; }

    /** Select an instance as current AND point RedBean at its workbench.db (self-consistent). */
    public function setCurrent(?array $inst): void {
        $this->current = $inst;
        if ($inst) WorkbenchDb::selectInstance($inst);
    }
    public function current(): ?array { return $this->current; }

    /** Re-select the current instance's DB after a helper scanned other instances' DBs. */
    private function restoreCurrent(): void {
        if ($this->current) WorkbenchDb::selectInstance($this->current);
    }

    // ---- identity / instance side (answered from core, never from workbench.db) ----

    public function getAccessibleInstanceIds(int $memberId): array {
        return array_values(array_map('intval', array_keys($this->instances)));
    }

    public function canAccessInstance(int $memberId, int $instanceId): bool {
        return isset($this->instances[$instanceId]);
    }

    /** OWNER-only gate for destructive instance actions. */
    public function ownsInstance(int $memberId, int $instanceId): bool {
        return !empty($this->instances[$instanceId]['owned']);
    }

    /** No team model in the sidecar — instance access IS the ACL. */
    public function getMemberTeams(int $memberId): array { return []; }
    public function isTeamMember(int $teamId, int $memberId): bool { return false; }

    /**
     * Left-nav workbench tabs: one per accessible instance, with a plan count read from
     * that instance's own workbench.db. Scans each DB then restores the current selection.
     */
    public function getInstanceTags(int $memberId): array {
        $out = [];
        foreach ($this->instances as $inst) {
            $tag = $inst['slug'] . '.' . ($inst['app'] !== '' ? $inst['app'] : 'tiknix');
            $n = 0;
            try {
                WorkbenchDb::selectInstance($inst);
                $n = (int) Bean::getCell(
                    "SELECT COUNT(*) FROM workbenchtask WHERE parent_task_id IS NULL");
            } catch (\Throwable $e) { $n = 0; }   // table absent until first task
            $out[] = [
                'tag' => $tag, 'n' => $n, 'id' => (int) $inst['id'], 'slug' => (string) $inst['slug'],
                'name' => (string) ($inst['name'] ?: $inst['slug']), 'engine' => '',
                'is_default' => false, 'owned' => (bool) $inst['owned'],
            ];
        }
        $this->restoreCurrent();
        return $out;
    }

    // ---- task side (answered from the CURRENTLY selected instance's workbench.db) ----

    /**
     * Tasks in the selected instance. Instance access already gates visibility, so this
     * is just filter application — no member/team scoping. Returns [] if no instance is
     * selected (nothing to show).
     */
    public function getVisibleTasks(int $memberId, array $filters = []): array {
        if (!$this->current) return [];
        $conds = []; $params = [];
        foreach ([['status','status'],['task_type','task_type'],['instance_tag','instance_tag']] as [$fk,$col]) {
            if (empty($filters[$fk])) continue;
            /* A board tab is a BUCKET, not always one stored status. The "Running" badge
               counts running + queued, because from the outside both mean the agent has
               the task — but the tab links to ?status=running and this matched that one
               literal string, so the tab read "Running 1" and opened an empty list. The
               count was right; the filter was a different idea of the same word. */
            if ($fk === 'status') {
                $wanted = self::STATUS_BUCKETS[$filters[$fk]] ?? [$filters[$fk]];
                $conds[] = "$col IN (" . implode(',', array_fill(0, count($wanted), '?')) . ')';
                foreach ($wanted as $w) $params[] = $w;
                continue;
            }
            $conds[] = "$col = ?"; $params[] = $filters[$fk];
        }
        if (!empty($filters['priority'])) { $conds[] = "priority = ?"; $params[] = (int) $filters['priority']; }
        $where = $conds ? implode(' AND ', array_map(fn($c) => "($c)", $conds)) : '1';
        $orderBy = $filters['order_by'] ?? 'created_at DESC';
        try {
            return Bean::find('workbenchtask', "$where ORDER BY $orderBy", $params);
        } catch (\Throwable $e) {
            // An empty board is a legitimate state (a project with no tasks yet, before
            // the fluid table exists), so we still return nothing rather than a 500. But
            // it is NOT the same as a failed query, and swallowing the difference made a
            // real fault — ten tasks in the database, "No Tasks Found" on screen while
            // the counters said 10 — look exactly like an empty project.
            Flight::get('log')->error('getVisibleTasks failed', [
                'error' => $e->getMessage(),
                'where' => $where,
                'order' => $orderBy,
            ]);
            return [];
        }
    }

    /** Status counts for the selected instance. */
    public function getTaskCounts(int $memberId): array {
        $counts = ['pending'=>0,'queued'=>0,'running'=>0,'completed'=>0,'failed'=>0,'paused'=>0,'total'=>0];
        if (!$this->current) return $counts;
        try {
            foreach (Bean::getAll("SELECT status, COUNT(*) c FROM workbenchtask GROUP BY status") as $r) {
                $s = (string) $r['status'];
                if (isset($counts[$s])) $counts[$s] = (int) $r['c'];
                $counts['total'] += (int) $r['c'];
            }
        } catch (\Throwable $e) {}
        return $counts;
    }

    /** Kept for the view's team-tab code path; personal == everything visible here. */
    public function getTeamTaskCounts(int $memberId): array {
        $t = $this->getTaskCounts($memberId);
        return ['personal' => $t['total'], 'total' => $t['total']];
    }

    // ---- per-task gates: a task is reachable iff its instance is accessible ----

    private function taskInstanceId($task): int {
        $iid = (int) ($task->instanceId ?? 0);
        if ($iid) return $iid;
        return $this->current ? (int) $this->current['id'] : 0;
    }
    public function canView(int $memberId, $task): bool   { return $this->canAccessInstance($memberId, $this->taskInstanceId($task)); }
    public function canComment(int $memberId, $task): bool { return $this->canView($memberId, $task); }
    public function canRun(int $memberId, $task): bool     { return $this->canView($memberId, $task); }
    public function canEdit(int $memberId, $task): bool    { return $this->canView($memberId, $task); }
    /** Destructive: owner of the task's instance only. */
    public function canDelete(int $memberId, $task): bool  { return $this->ownsInstance($memberId, $this->taskInstanceId($task)); }
}
