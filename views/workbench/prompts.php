<div class="container-fluid py-3">
    <div class="mb-3">
        <h4 class="mb-1"><i class="bi bi-chat-left-quote me-1"></i> Prompts</h4>
        <div class="text-body-secondary small">
            Everything you have asked this system to build &mdash; goals you decomposed, tasks you
            wrote, and what you typed in the Terminal. Kept when you write it, so it survives a
            planner that fails and the next thing you start.
            <strong>All your projects</strong>, not just this one.
        </div>
    </div>

    <?php if (!empty($harvestError)): ?>
        <div class="alert alert-warning py-2">
            <i class="bi bi-exclamation-triangle"></i>
            <strong>Some prompts could not be saved</strong>, so this list may be incomplete:
            <code><?= htmlspecialchars((string) $harvestError) ?></code>
        </div>
    <?php endif; ?>

    <?php /* Private, and said out loud: people type credentials into prompts. */ ?>
    <div class="alert alert-secondary d-flex align-items-start gap-2 py-2 small">
        <i class="bi bi-lock-fill mt-1"></i>
        <div>
            <strong>Only you can see this.</strong> Prompts often contain things you would not put in
            a shared log &mdash; passwords, keys, customer names &mdash; so there is no cross-member
            view of this page, including for admins.
        </div>
    </div>

    <form method="GET" action="/workbench/prompts" class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <div class="btn-group btn-group-sm">
            <a href="/workbench/prompts<?= $q !== '' ? '?q=' . urlencode($q) : '' ?>"
               class="btn <?= $source === '' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                All <span class="badge text-bg-light ms-1"><?= (int) ($counts[''] ?? 0) ?></span>
            </a>
            <?php foreach ($sources as $key => $label): ?>
                <a href="/workbench/prompts?source=<?= urlencode($key) ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>"
                   class="btn <?= $source === $key ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= htmlspecialchars($label) ?>
                    <span class="badge text-bg-light ms-1"><?= (int) ($counts[$key] ?? 0) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="ms-auto d-flex gap-2">
            <?php if ($source !== ''): ?><input type="hidden" name="source" value="<?= htmlspecialchars($source) ?>"><?php endif; ?>
            <input type="search" name="q" class="form-control form-control-sm" style="min-width:240px"
                   placeholder="Search your prompts…" value="<?= htmlspecialchars($q) ?>">
            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
        </div>
    </form>

    <?php if (empty($rows)): ?>
        <div class="alert alert-info py-2">
            <i class="bi bi-info-circle"></i>
            <?php if ($q !== '' || $source !== ''): ?>
                Nothing matches that filter. <a href="/workbench/prompts">Show everything</a>.
            <?php else: ?>
                No prompts recorded yet. They appear here as you decompose a goal, create a task,
                or type in the Terminal.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="list-group list-group-flush">
                <?php foreach ($rows as $r): ?>
                    <?php
                        $src   = (string) $r->source;
                        $badge = ['decompose' => 'info', 'task' => 'secondary', 'terminal' => 'dark'][$src] ?? 'secondary';
                        $icon  = ['decompose' => 'diagram-3', 'task' => 'card-checklist', 'terminal' => 'terminal'][$src] ?? 'chat-left-text';
                        $body  = (string) $r->body;
                        $long  = mb_strlen($body) > 400;
                        $tag   = (string) $r->instanceTag;
                        // A plan can only be OPENED from the project it lives in — its id is a key
                        // in that instance's own workbench.db. So the link appears only when this
                        // prompt belongs to the project you are on; otherwise the plan is named
                        // but not linked, and the tooltip says which project to switch to.
                        $here  = $tag !== '' && $tag === (string) ($selectedTag ?? '');
                    ?>
                    <div class="list-group-item">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="badge bg-<?= $badge ?>">
                                <i class="bi bi-<?= $icon ?> me-1"></i><?= htmlspecialchars($sources[$src] ?? $src) ?>
                            </span>
                            <?php if ($tag !== ''): ?>
                                <span class="badge text-bg-light border<?= $here ? '' : ' opacity-75' ?>"
                                      title="<?= $here ? 'The project you are working on' : 'Another of your projects' ?>">
                                    <?= htmlspecialchars($tag) ?>
                                </span>
                            <?php endif; ?>
                            <span class="text-body-secondary small"><?= htmlspecialchars((string) $r->createdAt) ?></span>

                            <?php if (!empty($r->planUid)): ?>
                                <span class="small ms-auto text-body-secondary">
                                    became
                                    <?php if ($here && (int) $r->planRef > 0): ?>
                                        <a href="/workbench/view?id=<?= (int) $r->planRef ?>"
                                           title="Plan <?= htmlspecialchars((string) $r->planUid) ?>">
                                            <?= htmlspecialchars((string) ($r->planTitle ?: 'a plan')) ?>
                                        </a>
                                    <?php else: ?>
                                        <span title="Plan <?= htmlspecialchars((string) $r->planUid) ?> &mdash; switch to <?= htmlspecialchars($tag) ?> to open it">
                                            <?= htmlspecialchars((string) ($r->planTitle ?: 'a plan')) ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            <?php elseif ($src === 'decompose'): ?>
                                <span class="small ms-auto text-body-secondary"
                                      title="This goal was recovered from disk. Which plan it produced — if any — was not recorded at the time.">
                                    <i class="bi bi-question-circle"></i> plan unknown
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php /* Escaped, never parsed: a prompt is the literal text you wrote, and
                                 rendering it as markdown would reflow the very thing you came to read. */ ?>
                        <pre class="mb-1 p-2 bg-body-tertiary border rounded<?= $long ? ' prompt-clipped' : '' ?>"
                             id="pb<?= (int) $r->id ?>"
                             style="white-space:pre-wrap;word-break:break-word;font-size:.85rem"><?= htmlspecialchars($body) ?></pre>

                        <div class="d-flex gap-3">
                            <?php if ($long): ?>
                                <button type="button" class="btn btn-link btn-sm p-0 prompt-more" data-target="pb<?= (int) $r->id ?>">Show all</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-link btn-sm p-0 prompt-copy" data-target="pb<?= (int) $r->id ?>">Copy</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-text mt-2">Showing <?= count($rows) ?> prompt<?= count($rows) === 1 ? '' : 's' ?>, newest first.</div>
    <?php endif; ?>
</div>

<style>.prompt-clipped { max-height: 9rem; overflow: hidden; }</style>

<script>
(function () {
    document.querySelectorAll('.prompt-more').forEach(function (b) {
        b.addEventListener('click', function () {
            var el = document.getElementById(b.dataset.target);
            var clipped = el.classList.toggle('prompt-clipped');
            b.textContent = clipped ? 'Show all' : 'Show less';
        });
    });
    document.querySelectorAll('.prompt-copy').forEach(function (b) {
        b.addEventListener('click', function () {
            var el = document.getElementById(b.dataset.target);
            // Say plainly when the copy did not happen — clipboard access is refused often
            // enough (insecure context, permissions) that a silent no-op is misleading.
            navigator.clipboard.writeText(el.textContent).then(function () {
                var was = b.textContent; b.textContent = 'Copied';
                setTimeout(function () { b.textContent = was; }, 1200);
            }, function (err) {
                b.textContent = 'Copy failed';
                console.error('Clipboard write refused:', err);
            });
        });
    });
})();
</script>
