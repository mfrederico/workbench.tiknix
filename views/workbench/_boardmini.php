<?php
/**
 * The task board, narrow: beside the terminal. What is building (the In progress card), then
 * every plan that is not finished with its tasks folded under it, then tasks made on their own.
 * Needs $liveTree, $miniPlans (Workbench::boardmini()) and $miniSolo. Links open the board's pages.
 */
$compact = true;
include __DIR__ . '/_inprogress.php';
$h = fn($s) => htmlspecialchars((string) $s);
$tone = ['draft' => ['secondary', 'Draft'], 'approved' => ['info', 'Ready'], 'queued' => ['info', 'Queued'], 'stalled' => ['danger', 'Stopped'], 'attention' => ['danger', 'Needs a look'], 'building' => ['primary', 'Building']];
$dot = function (string $st): string {
    if (in_array($st, ['merged', 'resolved', 'completed'], true)) return '<i class="bi bi-check-circle-fill text-success" title="Built"></i>';
    if (in_array($st, ['failed', 'conflict'], true)) return '<i class="bi bi-exclamation-triangle-fill text-danger" title="Stopped"></i>';
    if (in_array($st, ['awaiting', 'waiting'], true)) return '<i class="bi bi-chat-dots-fill text-warning" title="Waiting for your answer"></i>';
    if ($st === 'running') return '<span class="spinner-border spinner-border-sm text-primary" style="width:.7rem;height:.7rem" role="status" title="Being built now"></span>';
    return '<i class="bi bi-circle text-body-tertiary" title="Not started yet"></i>';
};
?>
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex align-items-center gap-2 py-2">
        <i class="bi bi-kanban"></i><span class="fw-semibold flex-grow-1">Task Board</span>
        <a class="small text-decoration-none" href="/workbench?view=board">Open it <i class="bi bi-arrow-right-short"></i></a>
    </div>
    <?php if (!$miniPlans && !$miniSolo): ?>
    <div class="card-body small text-body-secondary py-3">Nothing is waiting. Ask for something on the <a href="/workbench/ask">Ask</a> page, or tell the agent in the terminal.</div>
    <?php else: ?>
    <div class="list-group list-group-flush" style="max-height:46vh;overflow-y:auto">
        <?php foreach ($miniPlans as $p): [$cls, $say] = $tone[$p['state']] ?? ['secondary', ucfirst($p['state'])]; ?>
        <details class="list-group-item py-2"<?= $p['state'] === 'building' ? ' open' : '' ?>>
            <summary class="d-flex align-items-center gap-2" style="cursor:pointer;list-style:none">
                <i class="bi bi-chevron-right small text-body-secondary" aria-hidden="true"></i>
                <span class="text-truncate flex-grow-1 small"><span class="text-body-secondary">#<?= (int) $p['id'] ?></span> <?= $h($p['title']) ?></span>
                <span class="small text-body-secondary text-nowrap"><?= (int) $p['built'] ?>/<?= (int) $p['total'] ?></span>
                <span class="badge text-bg-<?= $cls ?>"><?= $h($say) ?></span>
            </summary>
            <ul class="list-unstyled small mb-0 mt-2 ps-3">
                <?php foreach ($p['tasks'] as $t): ?>
                <li class="d-flex gap-2 align-items-baseline py-1"><?= $dot($t['status']) ?><a class="text-reset text-decoration-none text-truncate" href="/workbench/view?id=<?= (int) $t['id'] ?>"><?= $h($t['title']) ?></a></li>
                <?php endforeach; ?>
                <li class="pt-1"><a class="text-body-secondary" href="/workbench/view?id=<?= (int) $p['id'] ?>">Open this plan</a></li>
            </ul>
        </details>
        <?php endforeach; ?>
        <?php foreach ($miniSolo as $t): ?>
        <a class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2 small" href="/workbench/view?id=<?= (int) $t['id'] ?>">
            <?= $dot($t['status']) ?><span class="text-truncate flex-grow-1"><span class="text-body-secondary">#<?= (int) $t['id'] ?></span> <?= $h($t['title']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
