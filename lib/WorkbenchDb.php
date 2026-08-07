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

    /** Select this instance's workbench.db (creates dir + DB + tables on first use). */
    public static function select(string $instanceDir, string $slug): void {
        $key = 'ws:' . $slug;
        $dir = rtrim($instanceDir, '/') . '/data';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (!Bean::hasDatabase($key)) Bean::addDatabase($key, 'sqlite:' . $dir . '/workbench.db');
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
