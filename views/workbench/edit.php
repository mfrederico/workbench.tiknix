<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="mb-4">
                <a href="/workbench/view?id=<?= $task->id ?>" class="text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Back to Task
                </a>
            </div>

            <?php
            $flash = $_SESSION['flash'] ?? [];
            unset($_SESSION['flash']);
            foreach ($flash as $msg):
            ?>
                <div class="alert alert-<?= $msg['type'] === 'error' ? 'danger' : $msg['type'] ?>">
                    <?= htmlspecialchars(($msg['message']) ?? '') ?>
                </div>
            <?php endforeach; ?>

            <div class="card">
                <div class="card-header">
                    <h4 class="mb-0">Edit Task</h4>
                </div>
                <div class="card-body">
                    <form method="POST" action="/workbench/update">
                        <?php foreach ($csrf as $name => $value): ?>
                            <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                        <?php endforeach; ?>
                        <input type="hidden" name="id" value="<?= $task->id ?>">

                        <!-- Title -->
                        <div class="mb-3">
                            <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="title" name="title" required
                                   value="<?= htmlspecialchars(($task->title) ?? '') ?>">
                        </div>

                        <!-- Description -->
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="4"><?= htmlspecialchars($task->description ?? '') ?></textarea>
                        </div>

                        <div class="row">
                            <!-- Task Type -->
                            <div class="col-md-6 mb-3">
                                <label for="task_type" class="form-label">Type</label>
                                <select class="form-select" id="task_type" name="task_type">
                                    <?php foreach ($taskTypes as $type => $info): ?>
                                        <option value="<?= $type ?>" <?= $task->taskType === $type ? 'selected' : '' ?>>
                                            <?= $info['label'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Priority -->
                            <div class="col-md-6 mb-3">
                                <label for="priority" class="form-label">Priority</label>
                                <select class="form-select" id="priority" name="priority">
                                    <?php foreach ($priorities as $level => $info): ?>
                                        <option value="<?= $level ?>" <?= (int)$task->priority === $level ? 'selected' : '' ?>>
                                            <?= $info['label'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <!-- Base Branch -->
                            <div class="col-md-6 mb-3">
                                <label for="base_branch" class="form-label">Base Branch</label>
                                <?php if (empty($task->branchName)): ?>
                                    <select class="form-select" id="base_branch" name="base_branch">
                                        <?php foreach ($branches ?? ['main'] as $branch): ?>
                                            <option value="<?= htmlspecialchars(($branch) ?? '') ?>" <?= ($task->baseBranch ?? 'main') === $branch ? 'selected' : '' ?>>
                                                <?= htmlspecialchars(($branch) ?? '') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Branch to create this task from. PR will merge back into this branch. Only pushed branches are shown.</div>
                                <?php else: ?>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($task->baseBranch ?? 'main') ?>" disabled>
                                    <div class="form-text text-muted">Cannot change base branch after task has been run.</div>
                                <?php endif; ?>
                            </div>

                            <!-- Authcontrol Level -->
                            <div class="col-md-6 mb-3">
                                <label for="authcontrol_level" class="form-label">Endpoint Access Level</label>
                                <select class="form-select" id="authcontrol_level" name="authcontrol_level">
                                    <?php foreach ($authcontrolLevels as $level => $info): ?>
                                        <option value="<?= $level ?>" <?= (int)($task->authcontrolLevel ?? $memberLevel) == $level ? 'selected' : '' ?>>
                                            <?= $info['label'] ?> (<?= $level ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Access level for new endpoints created by this task.</div>
                            </div>
                        </div>

                        <!-- Acceptance Criteria -->
                        <div class="mb-3">
                            <label for="acceptance_criteria" class="form-label">Acceptance Criteria</label>
                            <textarea class="form-control" id="acceptance_criteria" name="acceptance_criteria" rows="3"><?= htmlspecialchars($task->acceptanceCriteria ?? '') ?></textarea>
                        </div>

                        <!-- Related Files -->
                        <div class="mb-3">
                            <label for="related_files" class="form-label">Related Files</label>
                            <?php
                            $relatedFiles = json_decode(($task->relatedFiles) ?? '', true) ?: [];
                            ?>
                            <textarea class="form-control font-monospace" id="related_files" name="related_files" rows="3"><?= htmlspecialchars((implode("\n", $relatedFiles)) ?? '') ?></textarea>
                            <div class="form-text">One file path per line.</div>
                        </div>

                        <!-- Tags -->
                        <div class="mb-4">
                            <label for="tags" class="form-label">Tags</label>
                            <?php
                            $tags = json_decode(($task->tags) ?? '', true) ?: [];
                            ?>
                            <input type="text" class="form-control" id="tags" name="tags"
                                   value="<?= htmlspecialchars((implode(', ', $tags)) ?? '') ?>">
                            <div class="form-text">Comma-separated tags.</div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> Save Changes
                            </button>
                            <a href="/workbench/view?id=<?= $task->id ?>" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
