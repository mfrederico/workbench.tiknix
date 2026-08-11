<?php
/**
 * WorkbenchDb — point RedBean at an instance's OWN workbench.db (per-instance, fluid).
 * The workbench sidecar owns this data; the file lives with the instance (gitignored) so
 * it travels on eject. No Workbench data-access rewrite — just select, then run the code.
 */
namespace app;

use \Flight as Flight;

class WorkbenchDb {

    /**
     * The instance's app root on disk (…/<slug>.<app>).
     *
     * Core owns this rule — Model_Instance::dirFrom, which also refuses an empty slug
     * rather than naming a directory that is not a project. Five sidecars each carried a
     * copy that had to agree forever with nothing to notice if one stopped.
     */
    public static function instanceDir(array $inst): string {
        return \Model_Instance::dirFrom((string) $inst['slug'], (string) ($inst['app'] ?? ''));
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
            // The build board is written by plan-orchestrate.php, plan-ingest.php and
            // PlanExecutor on the CLI, and read here over HTTP. Task status going quietly
            // stale is the exact confusion that made a board full of "stalled" and
            // "awaiting" impossible to trust, so a 60-second-old answer here is worse than
            // a slower fresh one.
            //
            // Core CAN now invalidate across that boundary: [cache] version_store =
            // valkey puts the per-table generations in a service every process shares, so
            // a CLI write does reach a web reader (measured — with apcu versions the same
            // test served a stale count, with valkey it did not). Two things must both be
            // true before flipping this to cached, and neither is today:
            //   1. this sidecar has NO query cache at all — no [cache] block in its
            //      conf/config.ini, so query_cache is null and nothing is cached anyway;
            //   2. if it gains one, it MUST also set version_store = valkey. Core writing
            //      generations to valkey while this process read them from apcu would put
            //      the writer and the reader in different namespaces — the same split that
            //      the DSN-only cache prefix was just fixed to remove.
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
