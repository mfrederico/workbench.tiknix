<?php
/**
 * migrate-from-core.php — one-time, NON-DESTRUCTIVE copy of workbench task data out of
 * core's db into each instance's per-instance workbench.db (the Task Board sidecar model).
 *
 *   php scripts/migrate-from-core.php            # dry-run: report what WOULD copy
 *   php scripts/migrate-from-core.php --apply     # copy
 *
 * SAFE: reads core read-only (PDO), never writes/deletes core. Idempotent — skips any
 * instance whose workbench.db already holds tasks. Copies workbenchtask (+ its taskcomment
 * / tasklog children) grouped by instance_id, remapping ids so parent/child links stay
 * intact. Tasks with instance_id 0/NULL can't be routed to a workbench.db and are left in
 * core (reported).
 */

$apply = in_array('--apply', $argv, true);
$CORE  = '/var/www/html/default/tiknix';
$SC    = dirname(__DIR__);

require $CORE . '/vendor/autoload.php';
require $SC . '/lib/WorkbenchDb.php';
use RedBeanPHP\R;
use app\Bean;
use app\WorkbenchDb;

\Flight::set('sidecar.core_root', $CORE);

// core db (read-only by convention)
$cfg = @parse_ini_file($CORE . '/conf/config.ini', true) ?: [];
$corePath = $cfg['database']['path'] ?? '';
if ($corePath !== '' && $corePath[0] !== '/') $corePath = $CORE . '/' . $corePath;
$core = new PDO('sqlite:' . $corePath);
$core->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// RedBean must be initialised before addDatabase(); scratch default we never write.
R::setup('sqlite::memory:');

function coreRows(PDO $core, string $sql, array $p = []): array {
    $st = $core->prepare($sql); $st->execute($p); return $st->fetchAll(PDO::FETCH_ASSOC);
}

// instances that own tasks
$byInst = coreRows($core, "SELECT instance_id, COUNT(*) n FROM workbenchtask
    WHERE instance_id IS NOT NULL AND instance_id > 0 GROUP BY instance_id ORDER BY instance_id");
$orphans = (int) ($core->query("SELECT COUNT(*) FROM workbenchtask WHERE instance_id IS NULL OR instance_id=0")->fetchColumn());

echo ($apply ? "MIGRATE (apply)" : "MIGRATE (dry-run)") . "\n";
$grandTasks = 0; $grandChildren = 0;

foreach ($byInst as $row) {
    $iid  = (int) $row['instance_id'];
    $inst = coreRows($core, "SELECT id, slug, app, display_name FROM instance WHERE id=?", [$iid])[0] ?? null;
    if (!$inst) { echo "  instance_id=$iid: NO instance row — skipping {$row['n']} tasks\n"; continue; }
    $meta = ['id' => $iid, 'slug' => $inst['slug'], 'app' => $inst['app'] ?: 'tiknix', 'name' => $inst['display_name']];

    WorkbenchDb::selectInstance($meta);
    $existing = 0;
    try { $existing = (int) R::getCell("SELECT COUNT(*) FROM workbenchtask"); } catch (\Throwable $e) {}
    if ($existing > 0) { echo "  {$inst['slug']} (id=$iid): workbench.db already has $existing tasks — SKIP (idempotent)\n"; continue; }

    // read everything for this instance from core FIRST (we're about to switch RedBean db)
    $tasks    = coreRows($core, "SELECT * FROM workbenchtask WHERE instance_id=? ORDER BY id", [$iid]);
    $taskIds  = array_map(fn($t) => (int) $t['id'], $tasks);
    $inClause = $taskIds ? implode(',', array_fill(0, count($taskIds), '?')) : 'NULL';
    $comments = $taskIds ? coreRows($core, "SELECT * FROM taskcomment WHERE task_id IN ($inClause)", $taskIds) : [];
    $logs     = $taskIds ? coreRows($core, "SELECT * FROM tasklog   WHERE task_id IN ($inClause)", $taskIds) : [];

    echo "  {$inst['slug']} (id=$iid): " . count($tasks) . " tasks, " . count($comments) . " comments, " . count($logs) . " logs"
        . ($apply ? " → copying" : "") . "\n";
    $grandTasks += count($tasks); $grandChildren += count($comments) + count($logs);
    if (!$apply) continue;

    WorkbenchDb::selectInstance($meta);   // ensure this instance's db is selected for writes

    // pass 1: copy tasks (parent_task_id nulled), capture old→new id map
    $map = []; $parentOf = [];
    foreach ($tasks as $t) {
        $old = (int) $t['id']; $parent = $t['parent_task_id'] ?? null;
        unset($t['id']); $t['parent_task_id'] = null;
        $b = Bean::dispense('workbenchtask');
        foreach ($t as $k => $v) $b->$k = $v;
        $new = Bean::store($b);
        $map[$old] = $new;
        if ($parent) $parentOf[$new] = (int) $parent;
    }
    // pass 2: remap parent_task_id via the map
    foreach ($parentOf as $new => $oldParent) {
        if (!isset($map[$oldParent])) continue;
        $b = Bean::load('workbenchtask', $new); $b->parent_task_id = $map[$oldParent]; Bean::store($b);
    }
    // children: copy with remapped task_id
    foreach (['taskcomment' => $comments, 'tasklog' => $logs] as $type => $rows) {
        foreach ($rows as $r) {
            $ot = (int) ($r['task_id'] ?? 0);
            if (!isset($map[$ot])) continue;   // orphan child — skip
            unset($r['id']); $r['task_id'] = $map[$ot];
            $b = Bean::dispense($type);
            foreach ($r as $k => $v) $b->$k = $v;
            Bean::store($b);
        }
    }
}

echo "  " . str_repeat('-', 40) . "\n";
echo "  " . ($apply ? "copied" : "would copy") . ": $grandTasks tasks + $grandChildren child rows\n";
if ($orphans) echo "  LEFT IN CORE: $orphans task(s) with no instance_id (not routable)\n";
if (!$apply) echo "  (dry-run — re-run with --apply to copy)\n";
