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
            <?php
              /* Every link has to carry the current scope, or clicking a source tab
                 silently widens the page back to all projects. */
              $keep = ($q !== '' ? '&q=' . urlencode($q) : '') . (!empty($showAll) ? '&all=1' : '');
            ?>
            <?php foreach ($sources as $key => $label): ?>
                <a href="/workbench/prompts?source=<?= urlencode($key) ?><?= $keep ?>"
                   class="btn <?= $source === $key ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= htmlspecialchars($label) ?>
                    <span class="badge text-bg-light ms-1"><?= (int) ($counts[$key] ?? 0) ?></span>
                </a>
            <?php endforeach; ?>
            <?php if (!empty($queued)): ?>
                <a href="#queued" class="btn btn-outline-warning">
                    Queued <span class="badge text-bg-warning ms-1"><?= count($queued) ?></span>
                </a>
            <?php endif; ?>
        </div>

        <?php /* Which project these prompts belong to, and the way out. The page used to
                 list every project at once, which mattered because the buttons beside a row
                 act on the SELECTED project, not the one the row came from. */ ?>
        <div class="small text-body-secondary d-flex align-items-center gap-2">
            <?php if (!empty($showAll)): ?>
                <span><i class="bi bi-globe2 me-1"></i>All projects</span>
                <a href="/workbench/prompts<?= $source !== '' ? '?source=' . urlencode($source) : '' ?>">show only this project</a>
            <?php elseif (($selectedTag ?? '') !== ''): ?>
                <span><i class="bi bi-folder me-1"></i><?= htmlspecialchars($selectedTag) ?></span>
                <a href="/workbench/prompts?all=1<?= $source !== '' ? '&source=' . urlencode($source) : '' ?>">show all projects</a>
            <?php else: ?>
                <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>No project selected — showing all</span>
            <?php endif; ?>
        </div>
        <div class="ms-auto d-flex gap-2">
            <?php if ($source !== ''): ?><input type="hidden" name="source" value="<?= htmlspecialchars($source) ?>"><?php endif; ?>
            <input type="search" name="q" class="form-control form-control-sm" style="min-width:240px"
                   placeholder="Search your prompts…" value="<?= htmlspecialchars($q) ?>">
            <button class="btn btn-sm btn-outline-primary" type="submit"><i class="bi bi-search"></i></button>
        </div>
    </form>

    <?php /* Goals waiting their turn. One planner runs per project, so firing several
             decomposes queues them instead of losing them — but with nothing showing the
             queue, "it did nothing" was indistinguishable from "it is third in line".
             Only straight-through goals queue: a decompose without it produces a draft
             that waits for approval anyway, so retrying it unattended would be inventing
             an instruction nobody gave. */ ?>
    <?php if (!empty($queued)): ?>
        <div class="card border-warning-subtle mb-3" id="queued">
            <div class="card-header bg-warning-subtle d-flex align-items-center gap-2 py-2">
                <i class="bi bi-hourglass-split"></i>
                <strong><?= count($queued) ?> waiting to decompose</strong>
                <span class="small text-body-secondary ms-auto">oldest first — the order they will run in</span>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($queued as $i => $qd): ?>
                    <li class="list-group-item d-flex align-items-start gap-2">
                        <span class="badge text-bg-secondary mt-1">#<?= $i + 1 ?></span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="text-truncate"><?= htmlspecialchars($qd['title'] ?: mb_substr($qd['body'], 0, 120)) ?></div>
                            <div class="small text-body-secondary">
                                <?= htmlspecialchars($qd['instance_tag']) ?>
                                · queued <?= htmlspecialchars($qd['queued_at']) ?>
                                <?php if ($qd['attempts'] > 0): ?>
                                    · <span class="text-warning"><?= (int) $qd['attempts'] ?> attempt<?= $qd['attempts'] === 1 ? '' : 's' ?></span>
                                <?php endif; ?>
                                <?php if (!empty($qd['auto_build'])): ?>
                                    · <span class="badge text-bg-light border">runs straight through</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" onclick="wbUnqueue(<?= (int) $qd['id'] ?>, this)"
                                title="Stop waiting — leaves the prompt in the log, it just will not retry">Cancel</button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

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
                            <?php elseif ($src === 'decompose' && !empty($r->extKey)): ?>
                                <?php /* Recovered from disk by the backfill: which plan it produced was never
                                         recorded, so it is not a decompose that failed to fire — nothing to retry. */ ?>
                                <span class="small ms-auto text-body-secondary"
                                      title="This goal was recovered from disk. Which plan it produced — if any — was not recorded at the time.">
                                    <i class="bi bi-question-circle"></i> plan unknown
                                </span>
                            <?php elseif ($src === 'decompose'): ?>
                                <?php /* Submitted here but never produced a plan. Almost always the refusal in
                                         PlanRunner::start — a planner was already running for that project.
                                         A straight-through goal is QUEUED and retries itself; anything else
                                         waits for the button, because starting it unattended would be
                                         inventing an instruction nobody gave. */ ?>
                                <span class="small ms-auto d-flex align-items-center gap-2">
                                    <?php if (!empty($r->queuedAt)): ?>
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"
                                              title="Queued <?= htmlspecialchars((string) $r->queuedAt) ?> — retries automatically when this project frees up (attempt <?= (int) $r->queueAttempts ?> of 3)">
                                            <i class="bi bi-hourglass-split"></i> queued, retrying
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                            <i class="bi bi-exclamation-triangle"></i> never ran
                                        </span>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0 prompt-rerun"
                                            data-prompt="<?= (int) $r->id ?>"
                                            title="Decompose this goal again for <?= htmlspecialchars($tag) ?>">
                                        <i class="bi bi-arrow-clockwise"></i> Run decompose
                                    </button>
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
/* Drop a queued decompose. Global because the queue rows are rendered above and use an
   inline onclick, matching how the rest of this page's row actions are wired. */
async function wbUnqueue(promptId, btn) {
    var meta = document.querySelector('meta[name="csrf-token"]');
    var was = btn.innerHTML;
    btn.disabled = true;
    try {
        var fd = new FormData();
        fd.append('prompt_id', promptId);
        if (meta) fd.append('_csrf_token', meta.content);
        var res = await fetch('/workbench/promptunqueue', { method: 'POST', body: fd });
        var j = await res.json();
        if (!j.success) throw new Error(j.message || 'Could not remove it.');
        // Drop the row, and the whole card once it is the last one — an empty
        // "0 waiting to decompose" panel reads as a queue that is stuck.
        var li = btn.closest('li'), card = btn.closest('.card');
        if (li) li.remove();
        if (card && !card.querySelector('li')) card.remove();
    } catch (e) {
        btn.disabled = false;
        btn.innerHTML = was;
        alert(e.message);
    }
}

(function () {
    document.querySelectorAll('.prompt-more').forEach(function (b) {
        b.addEventListener('click', function () {
            var el = document.getElementById(b.dataset.target);
            var clipped = el.classList.toggle('prompt-clipped');
            b.textContent = clipped ? 'Show all' : 'Show less';
        });
    });
    var csrf = document.querySelector('meta[name="csrf-token"]');
    document.querySelectorAll('.prompt-rerun').forEach(function (b) {
        b.addEventListener('click', async function () {
            var was = b.innerHTML;
            b.disabled = true;
            b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> starting…';
            try {
                var fd = new FormData();
                fd.append('prompt_id', b.dataset.prompt);
                if (csrf) fd.append('_csrf_token', csrf.content);
                var res = await fetch('/workbench/promptrerun', { method: 'POST', body: fd });
                var j = await res.json();
                if (j.success) {
                    // The planner takes minutes; reloading would just show the same row.
                    // Say what is happening and leave the page where it is.
                    b.outerHTML = '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">'
                                + '<i class="bi bi-hourglass-split"></i> decomposing…</span>';
                    return;
                }
                // Refusals here are actionable ("a planner is already running") — show the
                // server's own words rather than a generic failure.
                alert(j.message || 'Could not start the decompose.');
            } catch (e) {
                alert('Could not start the decompose: ' + e);
            }
            b.disabled = false;
            b.innerHTML = was;
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
