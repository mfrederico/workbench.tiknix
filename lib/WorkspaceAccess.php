<?php
/**
 * WorkspaceAccess — the sidecar's instance-scoped replacement for core's
 * lib/TaskAccessControl. Same method surface the Workbench controller already calls
 * ($this->access->canRun / getVisibleTasks / getInstanceTags / …), but under the
 * per-instance model (decided 2026-07-24):
 *
 *   Access = "can you reach this instance" (Sidecar\Access, computed from core's
 *   team/instance tables, read-only). Anyone with access to an instance sees ALL its
 *   tasks. There is no per-task team ACL — a task belongs to exactly one instance and
 *   lives in that instance's workspace.db.
 *
 * The controller selects ONE instance's workspace.db per request (setCurrent), so the
 * task-side methods here just query the currently-selected DB. The identity/instance
 * side (which instances, ownership, admin level) is answered from core via Sidecar\Access.
 */
namespace app;

use \app\Bean;
use \app\Sidecar\Access;
use RedBeanPHP\R;

class WorkspaceAccess {

    private Access $core;
    private \PDO $pdo;
    private int $memberId;
    /** @var array<int,array> accessible instances, keyed by id */
    private array $instances = [];
    /** @var array|null the instance whose workspace.db is currently selected */
    private ?array $current = null;

    public function __construct(int $memberId, \PDO $coreDb) {
        $this->memberId = $memberId;
        $this->pdo = $coreDb;
        $this->core = new Access($coreDb);
        foreach ($this->core->instances($memberId) as $i) $this->instances[$i['id']] = $i;
    }

    /**
     * Instance metadata from CORE as a camelCase object (the sidecar replacement for
     * Bean::load('instance', $id) — the instance table lives in core, not workspace.db).
     * Access-gated: returns null for an instance the member can't reach.
     */
    public function instanceMeta(int $id): ?object {
        if (!isset($this->instances[$id])) return null;
        try {
            $st = $this->pdo->prepare('SELECT * FROM instance WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $r = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { $r = []; }
        return (object) [
            'id'          => $id,
            'slug'        => (string) ($r['slug'] ?? $this->instances[$id]['slug']),
            'app'         => (string) ($r['app'] ?? $this->instances[$id]['app']),
            'displayName' => (string) ($r['display_name'] ?? $this->instances[$id]['name']),
            'engine'      => (string) ($r['engine'] ?? ''),
            'memberId'    => (int) ($r['member_id'] ?? 0),
        ];
    }

    /** The accessible instance (row) whose workspace.db holds this task id, or null. */
    public function findTaskInstance(int $taskId): ?array {
        if ($taskId <= 0) return null;
        foreach ($this->instances as $inst) {
            try {
                WorkspaceDb::selectInstance($inst);
                if ((int) R::getCell("SELECT id FROM workbenchtask WHERE id = ?", [$taskId]) === $taskId) {
                    return $inst;
                }
            } catch (\Throwable $e) { /* table absent */ }
        }
        return null;
    }

    /** Accessible instances (list), each [{id,slug,app,name,owned}]. */
    public function accessibleInstances(): array { return array_values($this->instances); }

    /** Select an instance as current AND point RedBean at its workspace.db (self-consistent). */
    public function setCurrent(?array $inst): void {
        $this->current = $inst;
        if ($inst) WorkspaceDb::selectInstance($inst);
    }
    public function current(): ?array { return $this->current; }

    /** Re-select the current instance's DB after a helper scanned other instances' DBs. */
    private function restoreCurrent(): void {
        if ($this->current) WorkspaceDb::selectInstance($this->current);
    }

    // ---- identity / instance side (answered from core, never from workspace.db) ----

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
     * Left-nav workspace tabs: one per accessible instance, with a plan count read from
     * that instance's own workspace.db. Scans each DB then restores the current selection.
     */
    public function getInstanceTags(int $memberId): array {
        $out = [];
        foreach ($this->instances as $inst) {
            $tag = $inst['slug'] . '.' . ($inst['app'] !== '' ? $inst['app'] : 'tiknix');
            $n = 0;
            try {
                WorkspaceDb::selectInstance($inst);
                $n = (int) R::getCell(
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

    // ---- task side (answered from the CURRENTLY selected instance's workspace.db) ----

    /**
     * Tasks in the selected instance. Instance access already gates visibility, so this
     * is just filter application — no member/team scoping. Returns [] if no instance is
     * selected (nothing to show).
     */
    public function getVisibleTasks(int $memberId, array $filters = []): array {
        if (!$this->current) return [];
        $conds = []; $params = [];
        foreach ([['status','status'],['task_type','task_type'],['instance_tag','instance_tag']] as [$fk,$col]) {
            if (!empty($filters[$fk])) { $conds[] = "$col = ?"; $params[] = $filters[$fk]; }
        }
        if (!empty($filters['priority'])) { $conds[] = "priority = ?"; $params[] = (int) $filters['priority']; }
        $where = $conds ? implode(' AND ', array_map(fn($c) => "($c)", $conds)) : '1';
        $orderBy = $filters['order_by'] ?? 'created_at DESC';
        try {
            return Bean::find('workbenchtask', "$where ORDER BY $orderBy", $params);
        } catch (\Throwable $e) { return []; }   // fluid table not created yet
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
