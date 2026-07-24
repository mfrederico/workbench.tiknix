<?php
/** AI Projects (Workbench) — front controller on the Sidecar Kit. Task board + AI Builder (terminal, plan pipeline). */
if (php_sapi_name() === 'cli-server') {
    $f = __DIR__ . urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (is_file($f)) return false;
}
$cfg      = @parse_ini_file(dirname(__DIR__) . '/conf/config.ini', true) ?: [];
$coreRoot = rtrim($cfg['sidecar']['core_root'] ?? '/var/www/html/default/tiknix', '/');
require $coreRoot . '/vendor/autoload.php';   // Sidecar Kit (tiknix/sidecar-kit) + core shared classes
app\Sidecar\Kernel::guard(['', 'sso', 'index', 'workbench', 'aibuilder', 'error']);
(new app\Sidecar\Kernel(dirname(__DIR__), [
    'index'     => 'Index',
    'sso'       => 'Sso',
    'workbench' => 'Workbench',
    'aibuilder' => 'Aibuilder',
]))->run();
