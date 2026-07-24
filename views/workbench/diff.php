<div class="container-fluid py-4">
    <div class="mb-4">
        <a href="/workbench/view?id=<?= $task->id ?>" class="text-decoration-none">
            <i class="bi bi-arrow-left"></i> Back to <?= htmlspecialchars(($task->title) ?? '') ?>
        </a>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h2 mb-1">Changes to review</h1>
            <div class="text-muted small">
                <code><?= htmlspecialchars(((string)$task->branchName) ?? '') ?></code>
                vs <code><?= htmlspecialchars($stat['base'] ?? ($task->baseBranch ?: 'main')) ?></code>
                <?php if (!empty($stat)): ?>
                    · <?= (int)$stat['total_files'] ?> file<?= $stat['total_files'] == 1 ? '' : 's' ?>
                    · <span class="text-success">+<?= (int)$stat['added'] ?></span>
                    <span class="text-danger">&minus;<?= (int)$stat['removed'] ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($patch)): ?>
        <button class="btn btn-outline-secondary" onclick="copyDiff()">
            <i class="bi bi-clipboard"></i> Copy patch
        </button>
        <?php endif; ?>
    </div>

    <?php if (!empty($note)): ?>
        <div class="alert alert-info"><?= htmlspecialchars(($note) ?? '') ?></div>
    <?php endif; ?>

    <?php if (!empty($patch)): ?>
    <div class="card">
        <div class="card-body p-0">
            <pre class="mb-0 diff-view" id="diffContent"><?php
                // Lightweight diff colouring — escape first, then class each line.
                foreach (explode("\n", $patch) as $line) {
                    $esc = htmlspecialchars(($line) ?? '');
                    if ($line === '') { echo "\n"; continue; }
                    $c = $line[0];
                    if (strncmp($line, '+++', 3) === 0 || strncmp($line, '---', 3) === 0) $cls = 'd-file';
                    elseif (strncmp($line, 'diff ', 5) === 0 || strncmp($line, 'index ', 6) === 0) $cls = 'd-meta';
                    elseif ($c === '@') $cls = 'd-hunk';
                    elseif ($c === '+') $cls = 'd-add';
                    elseif ($c === '-') $cls = 'd-del';
                    else $cls = '';
                    echo $cls ? '<span class="' . $cls . '">' . $esc . "</span>\n" : $esc . "\n";
                }
            ?></pre>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.diff-view{max-height:80vh;overflow:auto;font-size:.82rem;line-height:1.45;padding:1rem;white-space:pre;tab-size:4}
.diff-view .d-add{background:rgba(46,160,67,.15);display:block}
.diff-view .d-del{background:rgba(248,81,73,.15);display:block}
.diff-view .d-hunk{color:#8250df;display:block}
.diff-view .d-file{color:#0969da;font-weight:600;display:block}
.diff-view .d-meta{color:#6e7781;display:block}
</style>

<script>
function copyDiff() {
    const content = document.getElementById('diffContent')?.textContent || '';
    navigator.clipboard.writeText(content).then(() => {
        const btn = event.target.closest('button');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check"></i> Copied!';
        setTimeout(() => { btn.innerHTML = originalHtml; }, 2000);
    });
}
</script>
