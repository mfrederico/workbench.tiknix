<div class="container py-4">
    <div class="mb-4">
        <a href="/workbench/view?id=<?= $task->id ?>" class="text-decoration-none">
            <i class="bi bi-arrow-left"></i> Back to <?= htmlspecialchars(($task->title) ?? '') ?>
        </a>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Task Logs</h1>
        <div class="btn-group">
            <a href="/workbench/logs?id=<?= $task->id ?>" class="btn btn-outline-secondary <?= empty($filterLevel) && empty($filterType) ? 'active' : '' ?>">All</a>
            <a href="/workbench/logs?id=<?= $task->id ?>&level=error" class="btn btn-outline-danger <?= $filterLevel === 'error' ? 'active' : '' ?>">Errors</a>
            <a href="/workbench/logs?id=<?= $task->id ?>&level=warning" class="btn btn-outline-warning <?= $filterLevel === 'warning' ? 'active' : '' ?>">Warnings</a>
            <a href="/workbench/logs?id=<?= $task->id ?>&type=claude" class="btn btn-outline-primary <?= $filterType === 'claude' ? 'active' : '' ?>">Claude</a>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($logs)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-journal" style="font-size: 3rem;"></i>
                    <p class="mt-3">No logs found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 150px;">Time</th>
                                <th style="width: 80px;">Level</th>
                                <th style="width: 80px;">Type</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="small text-muted"><?= date('M j, g:i:s A', strtotime($log->createdAt)) ?></td>
                                    <td>
                                        <span class="badge bg-<?= match($log->logLevel) {
                                            'error' => 'danger',
                                            'warning' => 'warning',
                                            'info' => 'info',
                                            'debug' => 'secondary',
                                            default => 'secondary'
                                        } ?>"><?= $log->logLevel ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= match($log->logType) {
                                            'claude' => 'primary',
                                            'user' => 'success',
                                            'validation' => 'warning',
                                            default => 'secondary'
                                        } ?>"><?= $log->logType ?></span>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars(($log->message) ?? '') ?></div>
                                        <?php if ($log->contextJson): ?>
                                            <small class="text-muted font-monospace">
                                                <?= htmlspecialchars((substr($log->contextJson, 0, 200)) ?? '') ?>
                                                <?= strlen($log->contextJson) > 200 ? '...' : '' ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
