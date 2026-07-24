<div class="container-fluid py-4">
    <div class="mb-4">
        <a href="/workbench/view?id=<?= $task->id ?>" class="text-decoration-none">
            <i class="bi bi-arrow-left"></i> Back to <?= htmlspecialchars(($task->title) ?? '') ?>
        </a>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Full Output</h1>
        <button class="btn btn-outline-secondary" onclick="copyOutput()">
            <i class="bi bi-clipboard"></i> Copy
        </button>
    </div>

    <div class="card">
        <div class="card-body">
            <?php if (empty($output)): ?>
                <p class="text-muted">No output available yet.</p>
            <?php else: ?>
                <pre class="mb-0" id="outputContent" style="max-height: 80vh; overflow-y: auto; white-space: pre-wrap; word-break: break-word;"><?= htmlspecialchars(($output) ?? '') ?></pre>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function copyOutput() {
    const content = document.getElementById('outputContent')?.textContent || '';
    navigator.clipboard.writeText(content).then(() => {
        const btn = event.target.closest('button');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check"></i> Copied!';
        setTimeout(() => {
            btn.innerHTML = originalHtml;
        }, 2000);
    });
}
</script>
