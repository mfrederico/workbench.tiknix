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
  <?php /* Below sm (phones) every item is its icon alone — labels stay for screen readers
           and as a tooltip — so the bar keeps one row instead of spilling off the side. */ ?>
  <span class="navbar-brand fw-semibold d-flex align-items-center gap-1 me-2" style="font-size:.95rem"><i class="bi bi-hammer"></i><span class="d-none d-sm-inline"> Build</span></span>
  <ul class="nav nav-pills gap-1 flex-nowrap">
    <li class="nav-item"><a class="nav-link py-1 px-2 <?= $__facet === 'board' ? 'active' : '' ?>" href="/workbench" title="Task Board"><i class="bi bi-kanban me-sm-1"></i><span class="d-none d-sm-inline">Task Board</span><span class="visually-hidden d-sm-none">Task Board</span></a></li>
    <li class="nav-item"><a class="nav-link py-1 px-2 <?= $__onBuilder ? 'active' : '' ?>" href="/aibuilder" title="Terminal"><i class="bi bi-robot me-sm-1"></i><span class="d-none d-sm-inline">Terminal</span><span class="visually-hidden d-sm-none">Terminal</span></a></li>
    <?php /* The prompt log belongs beside the two surfaces that produce it — the board's
             forms and the Terminal — rather than in core's nav, which is where you pick a
             project rather than work on one. */ ?>
    <li class="nav-item"><a class="nav-link py-1 px-2 <?= (($__facet ?? '') === 'prompts') ? 'active' : '' ?>" href="/workbench/prompts" title="Prompts"><i class="bi bi-chat-left-quote me-sm-1"></i><span class="d-none d-sm-inline">Prompts</span><span class="visually-hidden d-sm-none">Prompts</span></a></li>
  </ul>
  <?php /* Stuck on something that looks like the platform rather than your app? Tiknix
           support, about THIS project: core's Support page with it filled in (Contact::index
           takes ?project= only for a project the member can reach). _top: it is core's page,
           not something to open inside this frame. $selected is an array on the board and a
           bean on the Terminal. */
  $__sel  = $selected ?? null;
  $__slug = is_array($__sel) ? (string) ($__sel['slug'] ?? '') : (string) ($__sel->slug ?? '');
  $__support = rtrim((string) Flight::get('sidecar.core_url'), '/') . '/contact' . ($__slug !== '' ? '?project=' . rawurlencode($__slug) : ''); ?>
  <a class="ms-auto small text-decoration-none d-flex align-items-center gap-1" target="_top"
     href="<?= htmlspecialchars($__support) ?>" title="Ask Tiknix support about this project">
    <i class="bi bi-life-preserver"></i><span class="d-none d-sm-inline">Tiknix support</span><span class="visually-hidden d-sm-none">Tiknix support</span></a>
</nav>
<?php
/* THE BIG ONE. A session/usage limit blocks every decompose, build and terminal for this
   member until it resets, and it is the one failure a retry cannot fix — so it belongs
   above everything, on every surface, not buried in one task's error field. It shows the
   ENGINE'S OWN WORDS, which already carry the reset time and its timezone; reformatting
   that into server time is how you end up telling someone "7pm" when their clock says 3. */
/* $member comes from BuildControl's viewData. NOT Flight::getMember(): that helper is
   mapped in core and does not exist in this sidecar, so calling it threw — and the
   try/catch below turned that into a silently missing banner, which is precisely the
   failure this banner exists to prevent. Hence the explicit log on the way past. */
$__limit  = null;
$__mid    = (int) ($member->id ?? 0);
if ($__mid > 0 && class_exists('\app\AgentLimit')) {
    try {
        $__limit = \app\AgentLimit::active($__mid);
    } catch (\Throwable $e) {
        $__limit = null;
        error_log('[sidecar] agent-limit banner could not be resolved: ' . $e->getMessage());
    }
}
?>
<?php if ($__limit): ?>
  <div class="alert alert-danger border-danger border-3 rounded-0 mb-0 py-3" role="alert">
    <div class="container-fluid d-flex align-items-start gap-3">
      <i class="bi bi-exclamation-octagon-fill fs-3 lh-1"></i>
      <div>
        <div class="fw-bold fs-5">Your agent account has hit its limit — nothing will build until it resets.</div>
        <div class="mt-1"><code><?= htmlspecialchars((string) $__limit['message']) ?></code></div>
        <div class="small mt-2">
          Decomposes, builds and terminal sessions will all fail until then, and retrying
          sooner only spends attempts. Roughly <strong><?= (int) $__limit['minutes'] ?> minute(s)</strong> left by this server's clock.
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
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
