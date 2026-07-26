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
$kernel = new app\Sidecar\Kernel(dirname(__DIR__), [
    'index'     => 'Index',
    'sso'       => 'Sso',
    'workbench' => 'Workbench',
    'aibuilder' => 'Aibuilder',
]);

// Core's absolute URL, for views linking to routes CORE owns (Connections, Teams,
// Firehose). Those are not routes of this sidecar, so a leading-slash href resolves
// against this host and 404s — which is exactly how they were broken. Views must build
// core links from this value.
Flight::set('sidecar.core_url', rtrim((string) ($cfg['sidecar']['core_url'] ?? ''), '/'));

$kernel->run();
