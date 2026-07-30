<?php
/**
 * Sidecar layout — the lean shell the workbench views render inside. No core nav/header;
 * the sidecar renders within core's shell iframe. Bootstrap + icons + jQuery so the copied
 * workbench views' markup and scripts run unchanged. postMessage the height so the parent
 * shell can size the frame (Kit convention).
 */
$title = htmlspecialchars($title ?? 'Task Board');
// Which of the sidecar's facets is active, for the tab bar. The route stays /aibuilder —
// the "Terminal" rename is a LABEL only, because the plugin itself is now called "Builder"
// in the nav and a tab inside it called "AI Builder" read as though it were a different
// thing again.
$__p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$__onBuilder = strpos($__p, '/aibuilder') === 0;
// Prompts must be matched BEFORE the board: it lives under /workbench/… too, so a plain
// prefix test would light up the Task Board tab while you are looking at prompts.
$__facet = $__onBuilder ? 'builder' : (strpos($__p, '/workbench/prompts') === 0 ? 'prompts' : 'board');
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
    <li class="nav-item"><a class="nav-link py-1 px-2 <?= $__facet === 'board' ? 'active' : '' ?>" href="/workbench"><i class="bi bi-kanban me-1"></i>Task Board</a></li>
    <li class="nav-item"><a class="nav-link py-1 px-2 <?= $__onBuilder ? 'active' : '' ?>" href="/aibuilder"><i class="bi bi-robot me-1"></i>Terminal</a></li>
    <?php /* The prompt log belongs beside the two surfaces that produce it — the board's
             forms and the Terminal — rather than in core's nav, which is where you pick a
             project rather than work on one. */ ?>
    <li class="nav-item"><a class="nav-link py-1 px-2 <?= (($__facet ?? '') === 'prompts') ? 'active' : '' ?>" href="/workbench/prompts"><i class="bi bi-chat-left-quote me-1"></i>Prompts</a></li>
  </ul>
</nav>
<?= $ws_body ?? '' ?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// The task board is a FULL-HEIGHT app: the shell already sizes the frame to
// calc(100vh - topbar) and the board scrolls inside it. Reporting a content height on
// top of that made the parent resize the frame, which changed this document's viewport,
// which changed body.scrollHeight, which reported again — the frame flickered several
// times a second with the scrollbar appearing and disappearing.
//
// So this deliberately does NOT report. The postMessage channel remains for short plugin
// pages that genuinely want to grow to their content; a full-height app is not one.
</script>
</body>
</html>
