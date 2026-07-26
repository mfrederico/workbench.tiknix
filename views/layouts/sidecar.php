<?php
/**
 * Sidecar layout — the lean shell the workbench views render inside. No core nav/header;
 * the sidecar renders within core's shell iframe. Bootstrap + icons + jQuery so the copied
 * workbench views' markup and scripts run unchanged. postMessage the height so the parent
 * shell can size the frame (Kit convention).
 */
$title = htmlspecialchars($title ?? 'Task Board');
// Which of the sidecar's two facets is active (board vs AI Builder), for the tab bar.
$__p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$__onBuilder = strpos($__p, '/aibuilder') === 0;
?><!doctype html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars(function_exists('csrf_token') ? csrf_token() : '') ?>">
<title><?= $title ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand bg-body-tertiary border-bottom px-3 py-1">
  <span class="navbar-brand fw-semibold d-flex align-items-center gap-1" style="font-size:.95rem"><i class="bi bi-hammer"></i> Build</span>
  <ul class="nav nav-pills ms-2 gap-1">
    <li class="nav-item"><a class="nav-link py-1 px-2 <?= $__onBuilder ? '' : 'active' ?>" href="/workbench"><i class="bi bi-kanban me-1"></i>Task Board</a></li>
    <li class="nav-item"><a class="nav-link py-1 px-2 <?= $__onBuilder ? 'active' : '' ?>" href="/aibuilder"><i class="bi bi-robot me-1"></i>AI Builder</a></li>
  </ul>
</nav>
<?= $ws_body ?? '' ?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Size the parent shell iframe (Kit convention).
(function () {
  function report() {
    try { parent.postMessage({ tiknixHeight: document.body.scrollHeight }, '*'); } catch (e) {}
  }
  window.addEventListener('load', report);
  new ResizeObserver(report).observe(document.body);
})();
</script>
</body>
</html>
