<?php
/**
 * WorkbenchDb — point RedBean at an instance's OWN workbench.db (per-instance, fluid).
 * The workbench sidecar owns this data; the file lives with the instance (gitignored) so
 * it travels on eject. No Workbench data-access rewrite — just select, then run the code.
 */
namespace app;

use \Flight as Flight;

class WorkbenchDb {

    /** The instance's app root on disk (…/<slug>.<app>), mirroring PipeFiles::instanceDir. */
    public static function instanceDir(array $inst): string {
        $parent = dirname(rtrim((string) Flight::get('sidecar.core_root'), '/'));  // /var/www/html/default
        $app = ($inst['app'] ?? '') !== '' ? $inst['app'] : 'tiknix';
        return $parent . '/' . $inst['slug'] . '.' . $app;
    }

    /** The connection key for an instance's workbench.db. One namer, so callers agree. */
    public static function key(string $slug): string { return 'ws:' . $slug; }

    /** Select this instance's workbench.db (creates dir + DB + tables on first use). */
    public static function select(string $instanceDir, string $slug): void {
        $key = self::key($slug);
        $dir = rtrim($instanceDir, '/') . '/data';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (!Bean::hasDatabase($key)) {
            // DELIBERATELY UNCACHED — the sixth argument is not an oversight.
            //
            // Bean::addDatabase now gives secondary connections the query cache, which is
            // right for a database only web requests write. This one is the opposite: the
            // build board is written by plan-orchestrate.php, plan-ingest.php and
            // PlanExecutor on the CLI, and read here over HTTP. APCu memory is per-SAPI,
            // so those writers cannot stamp a version this process will ever see — a
            // cached read would sit stale until the TTL lapsed while a build moved on
            // underneath it. Task status going quietly stale is the exact confusion that
            // made a board full of "stalled" and "awaiting" impossible to trust, and a
            // 60-second-old answer here is worse than a slower fresh one.
            //
            // Redis would remove this restriction (one namespace across every process);
            // until then, correctness wins.
            Bean::addDatabase($key, 'sqlite:' . $dir . '/workbench.db', null, null, false, false);
        }
        Bean::selectDatabase($key);
        Bean::freeze(false);   // fluid: auto-create workbenchtask/taskcomment/… on first store
    }

    /** The instance's workbench.db file path (for the TIKNIX_WORKBENCH_DB env of children). */
    public static function path(array $inst): string {
        return rtrim(self::instanceDir($inst), '/') . '/data/workbench.db';
    }

    /** Convenience: resolve dir from an instance row and select it. Returns the dir. */
    public static function selectInstance(array $inst): string {
        $dir = self::instanceDir($inst);
        self::select($dir, (string) $inst['slug']);
        return $dir;
    }

    /** Back to the sidecar's own default DB (Kit metadata). */
    public static function selectDefault(): void { Bean::selectDatabase('default'); }
}
