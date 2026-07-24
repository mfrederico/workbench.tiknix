<?php
/**
 * Sidecar layout — the lean shell the workspace views render inside. No core nav/header;
 * the sidecar renders within core's shell iframe. Bootstrap + icons + jQuery so the copied
 * workbench views' markup and scripts run unchanged. postMessage the height so the parent
 * shell can size the frame (Kit convention).
 */
$title = htmlspecialchars($title ?? 'AI Projects');
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
