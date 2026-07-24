<?php
/**
 * WorkspaceDb — point RedBean at an instance's OWN workspace.db (per-instance, fluid).
 * The workspace sidecar owns this data; the file lives with the instance (gitignored) so
 * it travels on eject. No Workbench data-access rewrite — just select, then run the code.
 */
namespace app;
use RedBeanPHP\R;

class WorkspaceDb {
    /** Select this instance's workspace.db (creates dir + DB + tables on first use). */
    public static function select(string $instanceDir, string $slug): void {
        $key = 'ws:' . $slug;
        $dir = rtrim($instanceDir, '/') . '/data';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (!R::hasDatabase($key)) R::addDatabase($key, 'sqlite:' . $dir . '/workspace.db');
        R::selectDatabase($key);
        R::freeze(false);   // fluid: auto-create workbenchtask/taskcomment/… on first store
    }
    /** Back to the sidecar's own default DB (Kit metadata). */
    public static function selectDefault(): void { R::selectDatabase('default'); }
}
