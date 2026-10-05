<?php
/** The project's notebook: three Markdown documents its builders read before every task and add
    to after it. @var ?array $docs  @var array $titles  @var string $doc  @var string $notebookError  @var string $projectName */
$__blank = fn(string $d) => '# ' . ($titles[$d][0] ?? ucfirst($d)) . "\n\n" . ($titles[$d][1] ?? '') . "\n\n- ";
?>
<div class="container-fluid py-3" style="max-width:1100px">
    <div class="d-flex align-items-center gap-2 mb-1">
        <i class="bi bi-journal-text fs-4 text-primary"></i>
        <h1 class="h4 mb-0">Notebook<?= $projectName !== '' ? ' <span class="text-body-secondary fw-normal">— ' . htmlspecialchars($projectName) . '</span>' : '' ?></h1>
    </div>
    <p class="text-body-secondary small mb-3">What this project's builders know, written down. Every task and every plan is handed these three documents before it starts, and a task adds
        a line when it learns something the next one would need. It is documentation, not memory: you can read it, correct it and delete from it &mdash; and you should, because
        whatever is written here is believed by every agent that comes after.</p>

    <?php foreach ((array) ($_SESSION['flash'] ?? []) as $__m): ?>
        <div class="alert alert-<?= ($__m['type'] ?? '') === 'error' ? 'danger' : htmlspecialchars($__m['type'] ?? 'info') ?> py-2"><?= htmlspecialchars($__m['message'] ?? '') ?></div>
    <?php endforeach; unset($_SESSION['flash']); ?>

    <?php if ($notebookError !== ''): ?>
        <div class="alert alert-warning"><?= htmlspecialchars($notebookError) ?></div>
    <?php else: ?>
        <ul class="nav nav-tabs mb-3" id="nbTabs">
            <?php foreach ($docs as $__d => $__text): $__n = preg_match_all('/^\s*[-*] \S/m', (string) $__text); ?>
            <li class="nav-item"><a class="nav-link <?= $__d === $doc ? 'active' : '' ?>" href="/workbench/notebook?doc=<?= htmlspecialchars($__d) ?>"><?= htmlspecialchars($titles[$__d][0] ?? ucfirst($__d)) ?>
                <span class="badge text-bg-<?= $__n ? 'secondary' : 'light border' ?>"><?= (int) $__n ?></span></a></li>
            <?php endforeach; ?>
        </ul>
        <p class="small mb-2"><strong><?= htmlspecialchars($titles[$doc][0] ?? '') ?>:</strong> <?= htmlspecialchars($titles[$doc][1] ?? '') ?>
            <span class="text-body-secondary"><?= trim((string) $docs[$doc]) === '' ? 'Nothing recorded yet — write the first entries yourself, or let the tasks do it.' : 'One entry per line; the note in brackets is where it came from.' ?></span></p>
        <form method="post" action="/workbench/notebooksave" id="nbForm">
            <?php foreach (($csrf ?? []) as $__cn => $__cv): ?><input type="hidden" name="<?= htmlspecialchars($__cn) ?>" value="<?= htmlspecialchars($__cv) ?>"><?php endforeach; ?>
            <input type="hidden" name="doc" value="<?= htmlspecialchars($doc) ?>">
            <textarea class="form-control font-monospace" id="nbText" name="text" rows="18"><?= htmlspecialchars(trim((string) $docs[$doc]) === '' ? $__blank($doc) : (string) $docs[$doc]) ?></textarea>
            <div class="d-flex align-items-center gap-3 mt-3">
                <button type="submit" class="btn btn-primary" id="nbSave"><i class="bi bi-save me-1"></i>Save <?= htmlspecialchars(strtolower($titles[$doc][0] ?? 'document')) ?></button>
                <span class="small text-body-secondary">Saved into the project's repository (<code>agent/notebook/<?= htmlspecialchars($doc) ?>.md</code>) as you. Emptying it removes the document.</span>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php if ($notebookError === ''): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.20.0/dist/easymde.min.css">
<script src="https://cdn.jsdelivr.net/npm/easymde@2.20.0/dist/easymde.min.js"></script>
<script>
/* The same Markdown editor a task's description uses (EasyMDE over the real textarea, which is what posts). */
(function () {
  var ta = document.getElementById('nbText');
  if (!ta || typeof EasyMDE === 'undefined') return;   // the CDN unreachable: the plain textarea still works
  var mde = new EasyMDE({
    element: ta, spellChecker: false, autosave: {enabled: false}, status: ['lines', 'words'],
    minHeight: '380px', lineWrapping: true, forceSync: true,
    toolbar: ['bold', 'italic', 'heading-2', 'heading-3', '|', 'unordered-list', 'ordered-list', 'code', 'quote', '|', 'link', '|', 'preview', 'side-by-side', 'fullscreen', '|', 'guide'],
    renderingConfig: {singleLineBreaks: false, codeSyntaxHighlighting: false},
  });
  var dirty = false;
  mde.codemirror.on('change', function () { dirty = true; });
  ta.form.addEventListener('submit', function () { ta.value = mde.value(); dirty = false; });
  // Leaving with an unsaved edit (another tab of the notebook included) asks first.
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
<?php endif; ?>
