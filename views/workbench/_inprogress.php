<?php
/**
 * The In progress card: what is live right now. A plan that is building is ONE entry with its
 * tasks under it — what an agent has now, what is in trouble, what comes next — and a task that
 * belongs to no plan stands alone. Drawn on the board and, narrow ($compact), beside the terminal.
 * Needs $liveTree (Workbench::liveTree()). Draws nothing when nothing is live.
 */
$__compact = !empty($compact);
?>
            <?php
            $__tree = $liveTree ?? [];
            $__liveCount = 0;
            foreach ($__tree as $__g) $__liveCount += $__g['alone'] ? 1 : max(1, count($__g['now']));
            $__badge = function ($t): string {
                $st = (string) $t->status;
                if (in_array($st, ['awaiting', 'waiting'], true)) return '<span class="badge text-bg-warning" title="Waiting on you"><i class="bi bi-hourglass-split me-1"></i>awaiting</span>';
                if (in_array($st, ['failed', 'conflict'], true)) return '<span class="badge text-bg-danger"><i class="bi bi-exclamation-triangle me-1"></i>' . htmlspecialchars($st === 'conflict' ? 'merge conflict' : 'failed') . '</span>';
                return '<span class="badge text-bg-primary"><span class="spinner-border spinner-border-sm me-1" style="width:.6rem;height:.6rem" role="status"></span>' . htmlspecialchars($st) . '</span>';
            };
            $__line = function ($t, bool $under) use ($__badge, $__compact): string {
                $note = trim((string) ($t->progressMessage ?: (in_array((string) $t->status, ['failed', 'conflict'], true) ? $t->errorMessage : '')));
                return '<a class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-2' . ($under ? ($__compact ? ' ps-4' : ' ps-5') : '') . '" href="/workbench/view?id=' . (int) $t->id . '"' . ($__compact ? ' title="' . htmlspecialchars($note) . '"' : '') . '>'
                     . ($under ? '<i class="bi bi-arrow-return-right text-body-secondary" aria-hidden="true"></i>' : '') . $__badge($t)
                     . '<span class="text-truncate flex-grow-1"><span class="text-body-secondary">#' . (int) $t->id . '</span> ' . htmlspecialchars((string) $t->title) . '</span>'
                     . ($note !== '' && !$__compact ? '<span class="small text-body-secondary text-truncate d-none d-md-inline" style="max-width:40%">' . htmlspecialchars($note) . '</span>' : '') . '</a>';
            };
            ?>
            <?php if ($__tree): ?>
            <div class="card shadow-sm mb-3 border-primary-subtle">
                <div class="card-header d-flex align-items-center gap-2 py-2">
                    <i class="bi bi-activity text-primary"></i><span class="fw-semibold">In progress</span>
                    <span class="badge bg-primary rounded-pill"><?= (int) $__liveCount ?></span>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($__tree as $__g): ?>
                    <?php if ($__g['alone']): ?>
                        <?= $__line($__g['alone'], false) ?>
                    <?php else: $__pl = $__g['plan']; $__pct = $__g['total'] ? (int) round($__g['built'] * 100 / $__g['total']) : 0;
                        $__ps = (string) ($__pl->planStatus ?? ''); $__held = !$__g['now'] && $__ps !== 'building'; ?>
                        <a class="list-group-item list-group-item-action py-2" href="/workbench/view?id=<?= (int) $__pl->id ?>">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi bi-diagram-3 text-primary" aria-hidden="true"></i>
                                <span class="fw-semibold text-truncate flex-grow-1"><span class="text-body-secondary fw-normal">#<?= (int) $__pl->id ?></span> <?= htmlspecialchars((string) $__pl->title) ?></span>
                                <span class="small text-body-secondary text-nowrap"><?= (int) $__g['built'] ?> of <?= (int) $__g['total'] ?> built<?= $__held ? ' &middot; ' . htmlspecialchars($__ps !== '' ? $__ps : (string) $__pl->status) : '' ?></span>
                            </div>
                            <div class="progress mt-1" style="height:4px" role="progressbar" aria-label="Built" aria-valuenow="<?= $__pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?= $__pct ?>%"></div></div>
                            <?php if (!empty($__pl->progressMessage)): ?><div class="small text-body-secondary mt-1"><?= htmlspecialchars((string) $__pl->progressMessage) ?></div><?php endif; ?>
                        </a>
                        <?php foreach ($__g['now'] as $__k) echo $__line($__k, true); ?>
                        <?php foreach ($__g['trouble'] as $__k) echo $__line($__k, true); ?>
                        <?php if ($__g['next']): $__first = array_slice($__g['next'], 0, 3); ?>
                        <div class="list-group-item <?= $__compact ? 'ps-4' : 'ps-5' ?> py-2 small text-body-secondary d-flex gap-2">
                            <i class="bi bi-hourglass" aria-hidden="true"></i>
                            <span class="text-truncate">Next: <?= implode(' &middot; ', array_map(fn($k) => '#' . (int) $k->id . ' ' . htmlspecialchars(mb_strimwidth((string) $k->title, 0, 60, '…')), $__first)) ?><?= count($__g['next']) > 3 ? ' &middot; and ' . (count($__g['next']) - 3) . ' more' : '' ?></span>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
