<?php include __DIR__ . '/_form_css.php'; ?>
<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="mb-4">
                <a href="/workbench/view?id=<?= $task->id ?>" class="text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Back to the task
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
                    <h4 class="mb-0">Change this task</h4>
                </div>
                <div class="card-body">
                    <?php /* A task that has already run keeps what it built: an edit changes what the
                             agent is told NEXT time, which is worth saying before someone rewrites
                             a description expecting the code to follow. */ ?>
                    <?php if (!empty($task->branchName) || (int) ($task->runCount ?? 0) > 0): ?>
                        <div class="alert alert-secondary py-2 small">
                            This task has already run. What you change here is what the agent is told the <strong>next</strong> time it runs — it doesn't undo what's built.
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="/workbench/update" id="wbForm">
                        <?php foreach ($csrf as $name => $value): ?>
                            <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                        <?php endforeach; ?>
                        <input type="hidden" name="id" value="<?= $task->id ?>">
                        <input type="hidden" name="more_opened" id="more_opened" value="">

                        <!-- Title -->
                        <div class="mb-3">
                            <label for="title" class="form-label">Its name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="title" name="title" required
                                   value="<?= htmlspecialchars(($task->title) ?? '') ?>">
                        </div>

                        <!-- Description -->
                        <div class="mb-4">
                            <label for="description" class="form-label">What should it do?</label>
                            <textarea class="form-control" id="description" name="description" rows="8"><?= htmlspecialchars($brief['goal']) ?></textarea>
                            <div class="form-text">This is what the agent reads. Plain words are fine.</div>
                        </div>

                        <?php
                        /* The same two questions as the create form, read back out of the description
                           they were written into (app\GoalBrief). A task the planner wrote says who
                           each page is for in its own words, so nothing is pre-answered for it. */
                        $briefAudience   = $brief['audience'];
                        $briefAcceptance = $brief['acceptance'];
                        $briefNoneLabel  = ['Leave it to the description', 'it already says, or this adds no new pages.'];
                        include __DIR__ . '/_brief_fields.php';

                        $builderAgent = (string) ($task->agent ?? '');
                        $builderRun   = (string) ($currentRunChoice ?? '');
                        include __DIR__ . '/_who_builds.php';
                        ?>

                        <?php /* The extras nobody needs for most tasks, folded away as on the create form;
                                 whether they get opened is counted (app\ToolUse). Open already when one
                                 of them is in use, so nothing set is hidden. */
                        $relatedFiles = json_decode(($task->relatedFiles) ?? '', true) ?: [];
                        $tags = json_decode(($task->tags) ?? '', true) ?: [];
                        ?>
                        <details class="mb-3" id="wbMore" <?= ($relatedFiles || $tags) ? 'open' : '' ?>>
                            <summary class="small text-body-secondary">More options</summary>
                            <div class="border rounded p-3 mt-2">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="task_type" class="form-label">Kind of work</label>
                                        <select class="form-select" id="task_type" name="task_type">
                                            <?php foreach ($taskTypes as $type => $info): ?>
                                                <option value="<?= $type ?>" <?= $task->taskType === $type ? 'selected' : '' ?>><?= $info['label'] ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="priority" class="form-label">Priority</label>
                                        <select class="form-select" id="priority" name="priority">
                                            <?php foreach ($priorities as $level => $info): ?>
                                                <option value="<?= $level ?>" <?= (int)$task->priority === $level ? 'selected' : '' ?>><?= $info['label'] ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="related_files" class="form-label">Files to start from</label>
                                    <textarea class="form-control font-monospace" id="related_files" name="related_files" rows="3"><?= htmlspecialchars(implode("\n", $relatedFiles)) ?></textarea>
                                    <div class="form-text">Where the change likely goes, one path per line. The agent is pointed at these first.</div>
                                </div>
                                <div>
                                    <label for="tags" class="form-label">Tags</label>
                                    <input type="text" class="form-control" id="tags" name="tags" value="<?= htmlspecialchars(implode(', ', $tags)) ?>">
                                    <div class="form-text">Comma-separated, for finding it on the board later.</div>
                                </div>
                            </div>
                        </details>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> Save changes
                            </button>
                            <a href="/workbench/view?id=<?= $task->id ?>" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
/* Counted with the save (app\ToolUse): were the extras even opened? */
(function () {
  var more = document.getElementById('wbMore'), flag = document.getElementById('more_opened');
  // The summary's click, not 'toggle': the section is rendered open when it is already in use,
  // and that is not somebody opening it.
  if (more && flag) more.querySelector('summary').addEventListener('click', function () { if (!more.open) flag.value = '1'; });
})();
</script>
