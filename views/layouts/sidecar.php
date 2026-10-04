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
  <?php /* No agent on the project: there is nothing to build with, so the three views are not
           offered at all — the gate below is the page (BuildControl::agentGate). */ ?>
  <ul class="nav nav-pills gap-1 flex-nowrap"<?= !empty($agentGate) ? ' style="display:none"' : '' ?>>
    <li class="nav-item"><a class="nav-link py-1 px-2 <?= $__facet === 'board' ? 'active' : '' ?>" href="/workbench" title="Task Board"><i class="bi bi-kanban me-sm-1"></i><span class="d-none d-sm-inline">Task Board</span><span class="visually-hidden d-sm-none">Task Board</span></a></li>
    <li class="nav-item"><a class="nav-link py-1 px-2 <?= $__onBuilder ? 'active' : '' ?>" href="/aibuilder" title="Terminal"><i class="bi bi-terminal me-sm-1"></i><span class="d-none d-sm-inline">Terminal</span><span class="visually-hidden d-sm-none">Terminal</span></a></li>
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
<?php if (!empty($tiknixMcpOff)): ?>
  <?php /* The project's own MCP server is switched off (the app's AI agents page → MCP servers → danger zone). Not a gate:
           its owner chose it. Said on every page, with the way back. */ ?>
  <div class="alert alert-danger rounded-0 mb-0 py-2 d-flex flex-wrap align-items-center gap-2" role="alert" id="wbTiknixOff">
    <i class="bi bi-exclamation-octagon-fill"></i>
    <span class="me-auto"><strong>This project's own MCP server is removed from its agents.</strong> Plans cannot be made, and build agents work without the project's tools.</span>
    <a class="btn btn-sm btn-danger" target="_top" href="<?= htmlspecialchars(rtrim((string) Flight::get('sidecar.core_url'), '/')) ?>/projects/open?to=<?= rawurlencode('/agents?tab=mcp') ?>" rel="noopener">Restore it on the app's AI agents page</a>
  </div>
<?php endif; ?>
<?php if (!empty($agentGate)): $__g = $agentGate; ?>
<?php /* THE GATE. The project has no agent, so nothing on any Builder page could run: no board,
         no terminal, no prompts — this card instead, on every surface, until the app's AI agents
         page has an agent. The page body is NOT rendered (a form you cannot submit is a trap). */ ?>
<div class="container-fluid py-4" id="wbAgentGate">
  <?php /* A refused write (requireAgent) flashes its reason; the page body that normally shows
           flashes is not rendered here, so they are shown on the gate itself. */
  $__fl = $_SESSION['flash'] ?? []; unset($_SESSION['flash']);
  foreach ($__fl as $__m): ?>
  <div class="alert alert-<?= ($__m['type'] ?? '') === 'error' ? 'danger' : htmlspecialchars((string) ($__m['type'] ?? 'info')) ?> alert-dismissible fade show"><?= htmlspecialchars((string) ($__m['message'] ?? '')) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
  <?php endforeach; ?>
  <div class="alert alert-warning border-warning border-3 d-flex align-items-start gap-3 py-3 mb-3" role="alert">
    <i class="bi bi-cpu fs-3 lh-1"></i>
    <div>
      <div class="fw-bold fs-5"><?= htmlspecialchars($__g['name']) ?> needs an AI model before it can build anything.</div>
      <div class="mt-2">The Builder works by running an AI agent inside your project. That agent needs a model to think with, and you choose which one on the project's <strong>AI agents</strong> page: connect the provider you use &mdash; DeepSeek, GLM (z.ai), Kimi, OpenRouter, Ollama Cloud, your own endpoint, or Anthropic &mdash; by pasting its API key (Anthropic can also sign in). One is enough.</div>
      <div>Until then the task board, the terminal and the prompt log stay closed &mdash; a task typed now could not run.</div>
      <div class="mt-3 d-flex flex-wrap gap-2">
        <a class="btn btn-warning" href="<?= htmlspecialchars($__g['url']) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Connect a provider on <?= htmlspecialchars($__g['name']) ?>'s AI agents page</a>
        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/workbench') ?>"><i class="bi bi-arrow-clockwise me-1"></i>Done &mdash; open the Builder</a>
      </div>
      <details class="mt-3 small text-body-secondary"><summary>What the project reported</summary><code><?= htmlspecialchars($__g['problem']) ?></code></details>
    </div>
  </div>
</div>
<?php else: ?>
<?= $ws_body ?? '' ?>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script><?php /* modal alert/confirm/prompt: core owns it; this host cannot serve core's /js. A core on the
         tiknix runtime package keeps it in the package's public/. */
    $__core = (string) Flight::get('sidecar.core_root');
    $__dialogs = is_file($__core . '/public/js/dialogs.js') ? $__core . '/public/js/dialogs.js' : $__core . '/vendor/tiknix/runtime/public/js/dialogs.js';
    readfile($__dialogs); ?></script>
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
