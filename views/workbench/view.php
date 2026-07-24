<?php
// Extract domain from baseurl for subdomain URLs
$baseUrl = \Flight::get('baseurl') ?? 'https://localhost';
$baseDomain = preg_replace('#^https?://#', '', $baseUrl);
?>
<div class="container-fluid py-4">
    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <div class="mb-3">
                <?php $wbBack = !empty($task->instanceTag)
                    ? '/workbench?instance_tag=' . urlencode($task->instanceTag)
                    : '/workbench'; ?>
                <a href="<?= htmlspecialchars($wbBack) ?>" class="text-decoration-none text-muted">
                    <i class="bi bi-arrow-left"></i>
                    <?= !empty($task->instanceTag)
                        ? 'Back to ' . htmlspecialchars($task->instanceTag)
                        : 'Back to AI Projects' ?>
                </a>
            </div>

            <?php
            $flash = $_SESSION['flash'] ?? [];
            unset($_SESSION['flash']);
            foreach ($flash as $msg):
            ?>
                <div class="alert alert-<?= $msg['type'] === 'error' ? 'danger' : $msg['type'] ?> alert-dismissible fade show">
                    <?= htmlspecialchars(($msg['message']) ?? '') ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>

            <!-- Task Header -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <?php if (!empty($task->instanceTag)): ?>
                                <a href="/workbench?instance_tag=<?= urlencode($task->instanceTag) ?>"
                                   class="badge bg-info-subtle text-info-emphasis border border-info-subtle text-decoration-none mb-2 d-inline-block"
                                   style="font-size:.9rem;" title="Show all tasks for this instance">
                                    <i class="bi bi-hdd-network me-1"></i>Instance: <?= htmlspecialchars(($task->instanceTag) ?? '') ?>
                                </a>
                            <?php endif; ?>
                            <h2 class="mb-1"><?= htmlspecialchars(($task->title) ?? '') ?></h2>
                            <div class="text-muted small">
                                Created by <?= htmlspecialchars($creator->displayName ?? $creator->email) ?>
                                on <?= date('M j, Y', strtotime($task->createdAt)) ?>
                                <?php if ($team): ?>
                                    &bull; <a href="/teams/view?id=<?= $team->id ?>"><?= htmlspecialchars(($team->name) ?? '') ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <?php
                            $typeInfo = $taskTypes[$task->taskType ?? 'feature'] ?? $taskTypes['feature'];
                            $priorityInfo = $priorities[$task->priority ?? 3] ?? $priorities[3];
                            $statusBadge = match($task->status) {
                                'pending' => 'secondary',
                                'queued' => 'info',
                                'running' => 'primary',
                                'awaiting' => 'warning',
                                'completed' => 'success',
                                'merged' => 'success',
                                'failed' => 'danger',
                                'paused' => 'warning',
                                default => 'secondary'
                            };
                            ?>
                            <span class="badge bg-<?= $typeInfo['color'] ?> me-1">
                                <i class="bi bi-<?= $typeInfo['icon'] ?>"></i> <?= $typeInfo['label'] ?>
                            </span>
                            <span class="badge bg-<?= $priorityInfo['color'] ?> me-1">
                                <?= $priorityInfo['label'] ?>
                            </span>
                            <span class="badge bg-<?= $statusBadge ?> fs-6">
                                <?php if ($task->status === 'running'): ?>
                                    <span class="spinner-border spinner-border-sm me-1"></span>
                                <?php elseif ($task->status === 'merged'): ?>
                                    <i class="bi bi-git me-1"></i>
                                <?php endif; ?>
                                <?= ucfirst($task->status) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if ($canRun && in_array($task->status, ['pending', 'failed'])): ?>
                            <button class="btn btn-success" onclick="runTask(<?= $task->id ?>)">
                                <i class="bi bi-play-fill"></i> Run with Claude
                            </button>
                        <?php endif; ?>

                        <?php if ($canRun && $task->status === 'completed'): ?>
                            <button class="btn btn-outline-success" onclick="rerunTask(<?= $task->id ?>)">
                                <i class="bi bi-arrow-repeat"></i> Re-run
                            </button>
                            <?php if (!empty($task->branchName) && !empty($task->projectPath)): ?>
                                <button class="btn btn-outline-warning" onclick="resolveConflict(<?= $task->id ?>)"
                                        title="Update this task's branch from the current base and let the agent resolve any merge conflicts, keeping this task's work">
                                    <i class="bi bi-sign-merge-left"></i> Resolve conflict
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($canRun && in_array($task->status, ['queued', 'running'])): ?>
                            <button class="btn btn-outline-warning" onclick="forceResetTask(<?= $task->id ?>)">
                                <i class="bi bi-arrow-counterclockwise"></i> Force Reset
                            </button>
                        <?php endif; ?>

                        <?php if ($canRun && $task->status === 'running'): ?>
                            <button class="btn btn-warning" onclick="pauseTask(<?= $task->id ?>)">
                                <i class="bi bi-pause-fill"></i> Pause
                            </button>
                            <button class="btn btn-danger" onclick="stopTask(<?= $task->id ?>)">
                                <i class="bi bi-stop-fill"></i> Stop
                            </button>
                        <?php endif; ?>

                        <?php // Always offer a finish path while active — a worker can finish (or
                              // wedge) without the status flipping to 'awaiting', and completing
                              // stops the session first, so this is safe either way. ?>
                        <?php if ($canRun && in_array($task->status, ['running', 'queued'])): ?>
                            <button class="btn btn-outline-success" onclick="markComplete(<?= $task->id ?>)">
                                <i class="bi bi-check-circle"></i> Mark Complete <span class="small">(no merge)</span>
                            </button>
                            <button class="btn btn-success" onclick="markCompleteMerge(<?= $task->id ?>)">
                                <i class="bi bi-check2-circle"></i> Mark Complete <span class="small">(&amp; merge)</span>
                            </button>
                        <?php endif; ?>

                        <?php if ($canRun && $task->status === 'paused'): ?>
                            <button class="btn btn-success" onclick="resumeTask(<?= $task->id ?>)">
                                <i class="bi bi-play-fill"></i> Resume
                            </button>
                        <?php endif; ?>

                        <?php if (!empty($planRollup)): ?>
                            <?php
                            // Plan PARENT: no branch of its own — subtasks already merged into the
                            // instance's live branch individually. Show the rollup, not a dead button.
                            $pr = $planRollup;
                            $allDone = $pr['done'] >= $pr['total'] && $pr['total'] > 0;
                            ?>
                            <span class="badge bg-<?= $allDone ? 'success' : 'secondary' ?> fs-6 align-middle">
                                <i class="bi bi-diagram-3 me-1"></i>Plan · <?= (int)$pr['done'] ?>/<?= (int)$pr['total'] ?> subtasks <?= $allDone ? 'live' : 'done' ?>
                            </span>
                            <div class="form-text mt-1">
                                <?= $allDone
                                    ? 'All subtasks are complete and live on your instance — nothing left to approve here.'
                                    : 'Subtasks go live on your instance as each one finishes — there is nothing to approve on the plan itself.' ?>
                            </div>
                            <?php $auditStatus = (string)($task->auditStatus ?? ''); if ($auditStatus !== ''): $af = (int)($task->auditFailures ?? 0); ?>
                                <div class="mt-2">
                                    <?php if ($auditStatus === 'passed'): ?>
                                        <span class="badge bg-success"><i class="bi bi-shield-check me-1"></i>Audit passed</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><i class="bi bi-shield-exclamation me-1"></i>Audit failed<?= $af > 0 ? ' · ' . $af . ' issue' . ($af === 1 ? '' : 's') : ($af < 0 ? ' · no results' : '') ?></span>
                                    <?php endif; ?>
                                    <span class="text-body-secondary small ms-1">Definition-of-Done QA<?= !empty($task->auditAt) ? ' · ' . htmlspecialchars((string)$task->auditAt) : '' ?> — proof-of-life screenshots below</span>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($canRun && empty($task->planStatus) && in_array($task->status, ['awaiting', 'completed'])): ?>
                            <?php
                            // Check if current user is admin (can approve/decline)
                            $isAdmin = isset($member) && $member->level <= LEVELS['ADMIN'];
                            ?>
                            <?php if ($isAdmin): ?>
                                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal">
                                    <i class="bi bi-check-circle-fill"></i> Approve & Merge
                                </button>
                                <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#declineModal">
                                    <i class="bi bi-x-circle"></i> Decline
                                </button>
                            <?php else: ?>
                                <?php if ($task->status !== 'completed'): ?>
                                <button class="btn btn-success" onclick="markComplete(<?= $task->id ?>)">
                                    <i class="bi bi-check-circle"></i> Mark Complete
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($task->status === 'awaiting'): ?>
                            <button class="btn btn-outline-primary" onclick="document.getElementById('commentContent').focus()">
                                <i class="bi bi-chat-dots"></i> Send Instructions
                            </button>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($canEdit): ?>
                            <a href="/workbench/edit?id=<?= $task->id ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-pencil"></i> Edit
                            </a>
                        <?php endif; ?>

                        <?php if ($task->prUrl): ?>
                            <a href="<?= htmlspecialchars(($task->prUrl) ?? '') ?>" class="btn btn-outline-primary" target="_blank">
                                <i class="bi bi-github"></i> View PR
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Outcome banner — status at a glance, kept at the top for fast scanning -->
            <!-- Results (when completed or merged) -->
            <?php if (in_array($task->status, ['completed', 'merged'])): ?>
                <div class="card mb-4 border-success">
                    <div class="card-header bg-success text-white">
                        <?php if ($task->status === 'merged'): ?>
                            <h5 class="mb-0"><i class="bi bi-git me-1"></i> Merged</h5>
                        <?php else: ?>
                            <h5 class="mb-0"><i class="bi bi-check-circle"></i> Task Completed</h5>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($task->status === 'merged' && $task->branchName): ?>
                            <?php
                            // Extract repo from PR URL if available
                            $mergedTo = 'main';
                            if ($task->prUrl && preg_match('#github\.com/([^/]+/[^/]+)#', $task->prUrl, $matches)) {
                                $mergedTo = $matches[1] . '/main';
                            }
                            ?>
                            <p class="mb-2">
                                <i class="bi bi-check-circle-fill text-success me-1"></i>
                                <strong>Merged to:</strong> <code><?= htmlspecialchars(($mergedTo) ?? '') ?></code>
                            </p>
                            <p><strong>From branch:</strong> <code><?= htmlspecialchars(($task->branchName) ?? '') ?></code></p>
                        <?php elseif ($task->branchName): ?>
                            <p><strong>Branch:</strong> <code><?= htmlspecialchars(($task->branchName) ?? '') ?></code></p>
                        <?php endif; ?>
                        <?php if ($task->prUrl): ?>
                            <p><strong>Pull Request:</strong> <a href="<?= htmlspecialchars(($task->prUrl) ?? '') ?>" target="_blank"><?= htmlspecialchars(($task->prUrl) ?? '') ?></a></p>
                        <?php endif; ?>
                        <?php if ($task->mergedAt): ?>
                            <p><strong>Merged:</strong> <?= date('M j, Y g:i A', strtotime($task->mergedAt)) ?></p>
                        <?php elseif ($task->completedAt): ?>
                            <p><strong>Completed:</strong> <?= date('M j, Y g:i A', strtotime($task->completedAt)) ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Error (when failed) -->
            <?php if ($task->status === 'failed' && $task->errorMessage): ?>
                <div class="card mb-4 border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-x-circle"></i> Task Failed</h5>
                    </div>
                    <div class="card-body">
                        <pre class="<?= !empty($task->parentTaskId) ? 'mb-3' : 'mb-0' ?> text-danger"><?= htmlspecialchars(($task->errorMessage) ?? '') ?></pre>
                        <?php if (!empty($task->parentTaskId)): ?>
                            <button class="btn btn-danger" onclick="taskRetry(<?= (int)$task->id ?>, this)">
                                <i class="bi bi-arrow-clockwise me-1"></i> Fix &amp; retry
                            </button>
                            <div class="form-text mt-1">Clears known blockers (e.g. a dirty working tree), rebuilds this task, and auto-retries toward completion.</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Dependencies — what Claude is waiting on before it can start this task -->
            <?php if (!empty($deps) || !empty($blocks)): ?>
                <?php
                $depBadge = function($s) {
                    $map = ['merged'=>'success','completed'=>'success','done'=>'success',
                            'running'=>'primary','queued'=>'info','failed'=>'danger',
                            'awaiting'=>'warning','waiting'=>'warning','paused'=>'warning'];
                    $cls = $map[$s] ?? 'secondary';
                    return '<span class="badge bg-'.$cls.'">'.ucfirst($s ?: 'pending').'</span>';
                };
                $nPending = count($depsPending);
                $isTerminalDone = in_array($task->status, ['merged','completed','done'], true);
                ?>
                <div class="card mb-4 <?= $nPending ? 'border-warning' : (!empty($deps) ? 'border-success' : '') ?>">
                    <div class="card-header d-flex align-items-center <?= $nPending ? 'bg-warning bg-opacity-10' : '' ?>">
                        <h5 class="mb-0">
                            <i class="bi bi-diagram-3 me-1"></i> Dependencies
                        </h5>
                        <?php if (!empty($deps)): ?>
                            <span class="ms-auto small">
                                <?php if ($isTerminalDone): ?>
                                    <span class="text-success"><i class="bi bi-check2-all"></i> Completed</span>
                                <?php elseif ($nPending === 0): ?>
                                    <span class="text-success"><i class="bi bi-check2-circle"></i> Ready to run — all prerequisites done</span>
                                <?php else: ?>
                                    <span class="text-warning-emphasis"><i class="bi bi-hourglass-split"></i> Waiting on <?= $nPending ?> of <?= count($deps) ?></span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($deps)): ?>
                            <div class="mb-1 text-muted small text-uppercase">Waiting on</div>
                            <ul class="list-group list-group-flush mb-<?= empty($blocks) ? '0' : '3' ?>">
                                <?php foreach ($deps as $d): ?>
                                    <li class="list-group-item d-flex align-items-center px-0">
                                        <i class="bi bi-<?= $d['done'] ? 'check-circle-fill text-success' : 'hourglass text-warning' ?> me-2"></i>
                                        <a href="/workbench/view?id=<?= $d['id'] ?>" class="text-decoration-none flex-grow-1 <?= $d['done'] ? 'text-muted' : 'fw-medium' ?>">
                                            <?= htmlspecialchars(($d['title']) ?? '') ?>
                                        </a>
                                        <?= $depBadge($d['status']) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if (!empty($blocks)): ?>
                            <div class="mb-1 text-muted small text-uppercase">Blocks</div>
                            <ul class="list-group list-group-flush mb-0">
                                <?php foreach ($blocks as $b): ?>
                                    <li class="list-group-item d-flex align-items-center px-0">
                                        <i class="bi bi-arrow-return-right text-muted me-2"></i>
                                        <a href="/workbench/view?id=<?= $b['id'] ?>" class="text-decoration-none flex-grow-1">
                                            <?= htmlspecialchars(($b['title']) ?? '') ?>
                                        </a>
                                        <?= $depBadge($b['status']) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Description -->
            <?php if (!empty($task->description)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Description</h5>
                    </div>
                    <div class="card-body">
                        <div class="prose"><?= \app\MarkdownParser::parseSafe($task->description ?? '') ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Acceptance Criteria -->
            <?php if (!empty($task->acceptanceCriteria)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Acceptance Criteria</h5>
                    </div>
                    <div class="card-body">
                        <div class="prose"><?= \app\MarkdownParser::parseSafe($task->acceptanceCriteria ?? '') ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php
            // Find the last Claude message for display
            $lastClaudeMessage = null;
            if (!empty($comments)) {
                foreach (array_reverse($comments) as $c) {
                    if (!empty($c['is_from_claude'])) {
                        $lastClaudeMessage = $c;
                        break;
                    }
                }
            }
            ?>

            <!-- Last Claude Message (when awaiting) -->
            <?php if ($task->status === 'awaiting' && $lastClaudeMessage): ?>
                <div class="card mb-4 border-primary" id="lastClaudeCard">
                    <div class="card-header bg-primary bg-opacity-10 d-flex align-items-center">
                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                            <i class="bi bi-robot"></i>
                        </div>
                        <h5 class="mb-0">Claude's Last Message</h5>
                        <small class="text-muted ms-auto"><?= date('M j, g:i A', strtotime($lastClaudeMessage['created_at'])) ?></small>
                    </div>
                    <div class="card-body">
                        <?php
                        $content = htmlspecialchars(($lastClaudeMessage['content']) ?? '');
                        $content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $content);
                        $content = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $content);
                        $content = preg_replace('/^- (.+)$/m', '<li>$1</li>', $content);
                        $content = nl2br($content);
                        ?>
                        <div class="comment-content"><?= $content ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Live Console (read-only tmux capture of the worker session) -->
            <?php
            $consoleSession = (string)($task->tmuxSession ?: $task->agentSession ?: '');
            $showConsole = $consoleSession !== '' || in_array($task->status, ['running', 'queued', 'awaiting', 'paused'], true);
            ?>
            <?php if ($showConsole): ?>
                <div class="card mb-4" id="consoleCard">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-terminal me-2"></i>Live Console <span class="badge bg-secondary ms-1">read-only</span></h5>
                        <span class="small text-muted d-flex align-items-center gap-2">
                            <span id="consoleDot" class="d-inline-block rounded-circle" style="width:.6rem;height:.6rem;background:#6c757d;"></span>
                            <span id="consoleState">connecting…</span>
                            <label class="ms-2 mb-0"><input type="checkbox" id="consoleFollow" checked> follow</label>
                        </span>
                    </div>
                    <pre id="wbConsole" class="mb-0 p-3" style="background:#1e1e1e;color:#d4d4d4;max-height:60vh;overflow:auto;font-size:.8rem;line-height:1.35;white-space:pre-wrap;word-break:break-word;border-radius:0 0 .375rem .375rem;">Connecting…</pre>
                </div>
                <script>
                (function(){
                    var pre = document.getElementById('wbConsole'),
                        dot = document.getElementById('consoleDot'),
                        state = document.getElementById('consoleState'),
                        follow = document.getElementById('consoleFollow');
                    if (!pre) return;
                    var taskId = <?= (int)$task->id ?>, timer = null, busy = false;
                    function nearBottom(){ return pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 40; }
                    function paint(text){
                        var stick = follow.checked && nearBottom();
                        // textContent (never innerHTML): any <script>/HTML in the agent
                        // output is shown literally and can never execute.
                        pre.textContent = text && text.length ? text : '(no output captured)';
                        if (stick) pre.scrollTop = pre.scrollHeight;
                    }
                    function tick(){
                        if (busy) return; busy = true;
                        fetch('/workbench/console?id=' + taskId + '&lines=1500', {headers:{'X-Requested-With':'XMLHttpRequest'}})
                            .then(function(r){ return r.json(); })
                            .then(function(j){
                                busy = false;
                                var d = (j && j.data) ? j.data : {};
                                if (d.alive) { dot.style.background = '#28a745'; state.textContent = 'live'; paint(d.content); }
                                else {
                                    dot.style.background = '#6c757d'; state.textContent = 'session ended';
                                    if (d.content) paint(d.content);
                                    // Only stop polling once the task is truly done — otherwise keep
                                    // reconnecting (a run may just be starting up).
                                    if (['running','queued','awaiting','paused'].indexOf(d.status) < 0 && timer) {
                                        clearInterval(timer); timer = null;
                                    }
                                }
                            })
                            .catch(function(){ busy = false; });
                    }
                    tick();
                    timer = setInterval(tick, 1500);
                    document.addEventListener('visibilitychange', function(){
                        if (document.hidden) { if (timer) { clearInterval(timer); timer = null; } }
                        else if (!timer) { tick(); timer = setInterval(tick, 1500); }
                    });
                })();
                </script>
            <?php endif; ?>

            <!-- Your Turn Card (when awaiting) -->
            <?php if ($task->status === 'awaiting'): ?>
                <div class="card mb-4 border-warning" id="progressCard">
                    <div class="card-header d-flex justify-content-between align-items-center bg-warning bg-opacity-25">
                        <h5 class="mb-0">
                            <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>
                            Your Turn
                        </h5>
                        <small class="text-muted" id="lastUpdate"></small>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><strong>Claude paused and handed control back to you.</strong> It has either finished the work and wants your review, or it asked a question. Here's what to do:</p>
                        <ol class="small text-muted mb-3">
                            <li>Read <strong>Claude's message</strong> (the card just above) for what it did or is asking.</li>
                            <?php if (!empty($reviewChanges)): ?>
                                <li>Review the <strong>changes below</strong>, then use <strong>Approve &amp; Merge</strong> (top of the page) to accept them into <code><?= htmlspecialchars(($reviewChanges['base']) ?? '') ?></code>.</li>
                            <?php endif; ?>
                            <li>Or type an answer / follow-up instructions and <strong>Send</strong> to keep Claude going.</li>
                        </ol>

                        <?php if (!empty($reviewChanges)): ?>
                            <div class="border rounded p-2 mb-3 bg-body-tertiary">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong class="small"><i class="bi bi-file-earmark-diff me-1"></i>Changes to review</strong>
                                    <a href="/workbench/diff?id=<?= $task->id ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                                        <i class="bi bi-arrows-fullscreen me-1"></i>View full diff
                                    </a>
                                </div>
                                <div class="small mb-1">
                                    <span class="text-muted"><?= (int)$reviewChanges['total_files'] ?> file<?= $reviewChanges['total_files'] == 1 ? '' : 's' ?> changed ·</span>
                                    <span class="text-success">+<?= (int)$reviewChanges['added'] ?></span>
                                    <span class="text-danger">&minus;<?= (int)$reviewChanges['removed'] ?></span>
                                </div>
                                <ul class="list-unstyled mb-0 small font-monospace">
                                    <?php foreach ($reviewChanges['files'] as $f): ?>
                                        <li class="d-flex justify-content-between border-top py-1">
                                            <span class="text-truncate me-2"><?= htmlspecialchars(($f['path']) ?? '') ?></span>
                                            <span class="text-nowrap">
                                                <?php if ($f['binary']): ?><span class="text-muted">binary</span>
                                                <?php else: ?><span class="text-success">+<?= (int)$f['added'] ?></span> <span class="text-danger">&minus;<?= (int)$f['removed'] ?></span><?php endif; ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <!-- Reply/instruct via the single Conversation pane below (was a
                             duplicate comment box here — removed to avoid two inputs). -->
                        <div class="d-flex gap-2 flex-wrap">
                            <button class="btn btn-primary" onclick="document.getElementById('commentContent').focus()">
                                <i class="bi bi-chat-dots me-1"></i> Reply in Conversation
                            </button>
                            <button class="btn btn-outline-success" onclick="markComplete(<?= $task->id ?>)">
                                <i class="bi bi-check-circle me-1"></i> Mark Complete <span class="small">(no merge)</span>
                            </button>
                            <button class="btn btn-success" onclick="markCompleteMerge(<?= $task->id ?>)">
                                <i class="bi bi-check2-circle me-1"></i> Mark Complete <span class="small">(&amp; merge)</span>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Live Progress (when running) -->
            <?php if (in_array($task->status, ['running', 'queued'])): ?>
                <div class="card mb-4" id="progressCard">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <span class="spinner-border spinner-border-sm me-2" id="progressSpinner"></span>
                            Claude is Working
                        </h5>
                        <small class="text-muted" id="lastUpdate">Updating...</small>
                    </div>
                    <div class="card-body">
                        <div id="progressContent">
                            <p class="text-muted">Connecting to Claude runner...</p>
                        </div>
                        <div id="snapshotContent" class="mt-3" style="display: none;">
                            <strong>Latest Activity:</strong>
                            <pre class="bg-dark text-light p-3 rounded mt-2 small" style="max-height: 300px; overflow-y: auto;" id="snapshotText"></pre>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Comments -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-chat-dots me-2"></i>Conversation</h5>
                </div>
                <div class="card-body">
                    <!-- Add Message Form (at top) -->
                    <form id="commentForm" class="mb-4">
                        <div class="mb-2">
                            <textarea class="form-control" id="commentContent" name="content" rows="2" placeholder="Send instructions or respond to Claude..."></textarea>
                        </div>
                        <!-- Image preview area -->
                        <div id="imagePreviewContainer" class="mb-2" style="display: none;">
                            <div class="position-relative d-inline-block">
                                <img id="imagePreview" src="" class="img-thumbnail" style="max-height: 150px;">
                                <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1" onclick="clearImagePreview()">
                                    <i class="bi bi-x"></i>
                                </button>
                            </div>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send me-1"></i>Send</button>
                            <label class="btn btn-sm btn-outline-secondary mb-0" for="imageUpload" title="Attach image">
                                <i class="bi bi-image"></i>
                                <span class="d-none d-sm-inline ms-1">Image</span>
                            </label>
                            <input type="file" id="imageUpload" accept="image/png,image/jpeg,image/gif,image/webp" class="d-none">
                        </div>
                    </form>

                    <div id="commentsList">
                        <?php if (empty($comments)): ?>
                            <p class="text-muted" id="noComments">No messages yet.</p>
                        <?php else: ?>
                            <?php foreach (array_reverse($comments) as $comment): ?>
                                <?php
                                $isFromClaude = !empty($comment['is_from_claude']);
                                $authorName = $isFromClaude ? 'Claude' : trim(($comment['first_name'] ?? '') . ' ' . ($comment['last_name'] ?? ''));
                                if (empty($authorName)) $authorName = $comment['username'] ?? $comment['email'] ?? 'Unknown';
                                ?>
                                <div class="d-flex mb-3 <?= $isFromClaude ? 'flex-row-reverse' : '' ?>" data-comment-id="<?= $comment['id'] ?>">
                                    <?php if ($isFromClaude): ?>
                                        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center ms-2 flex-shrink-0" style="width: 40px; height: 40px;">
                                            <i class="bi bi-robot"></i>
                                        </div>
                                    <?php elseif (!empty($comment['avatar_url'])): ?>
                                        <img src="<?= htmlspecialchars(($comment['avatar_url']) ?? '') ?>" class="rounded-circle me-2 flex-shrink-0" width="40" height="40">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 flex-shrink-0" style="width: 40px; height: 40px;">
                                            <?= strtoupper(substr($authorName, 0, 1)) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex-grow-1">
                                        <div class="<?= $isFromClaude ? 'bg-primary bg-opacity-10 border border-primary' : 'bg-light text-dark border' ?> rounded p-3 position-relative">
                                            <?php if ($canEdit): ?>
                                                <button type="button" class="btn btn-sm btn-link text-danger position-absolute p-0" style="top: 8px; right: 8px; opacity: 0.5;" onclick="deleteComment(<?= $comment['id'] ?>)" title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                            <div class="d-flex justify-content-between mb-1 pe-4">
                                                <strong><?= $isFromClaude ? '<i class="bi bi-robot me-1"></i>' : '' ?><?= htmlspecialchars(($authorName) ?? '') ?></strong>
                                                <small class="text-muted"><?= date('M j, g:i A', strtotime($comment['created_at'])) ?></small>
                                            </div>
                                            <?php
                                            // Claude messages are markdown → render safely via parseSafe();
                                            // user comments stay plain (escaped) text.
                                            $content = $isFromClaude
                                                ? \app\MarkdownParser::parseSafe($comment['content'] ?? '')
                                                : nl2br(htmlspecialchars($comment['content'] ?? ''));
                                            ?>
                                            <?php if (!empty($content)): ?>
                                                <div class="comment-content"><?= $content ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($comment['image_path'])): ?>
                                                <div class="comment-image mt-2">
                                                    <a href="/<?= htmlspecialchars(($comment['image_path']) ?? '') ?>" target="_blank" class="d-block">
                                                        <img src="/<?= htmlspecialchars(($comment['image_path']) ?? '') ?>"
                                                             class="img-fluid rounded border"
                                                             style="max-height: 300px; cursor: zoom-in;"
                                                             alt="Attached image">
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Task Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0">Details</h6>
                </div>
                <div class="card-body">
                    <dl class="mb-0">
                        <dt>Status</dt>
                        <dd><span class="badge bg-<?= $statusBadge ?>"><?= ucfirst($task->status) ?></span></dd>

                        <dt>Run Count</dt>
                        <dd><?= $task->runCount ?? 0 ?></dd>

                        <?php if ($task->startedAt): ?>
                            <dt>Last Started</dt>
                            <dd><?= date('M j, g:i A', strtotime($task->startedAt)) ?></dd>
                        <?php endif; ?>

                        <?php if ($task->tmuxSession): ?>
                            <dt>Session</dt>
                            <dd><code class="small"><?= htmlspecialchars(($task->tmuxSession) ?? '') ?></code></dd>
                        <?php endif; ?>

                        <?php
                        $relatedFiles = json_decode(((string)$task->relatedFiles) ?? '', true) ?: [];
                        if (!empty($relatedFiles)):
                        ?>
                            <dt>Related Files</dt>
                            <dd>
                                <?php foreach ($relatedFiles as $file): ?>
                                    <code class="d-block small"><?= htmlspecialchars(($file) ?? '') ?></code>
                                <?php endforeach; ?>
                            </dd>
                        <?php endif; ?>

                        <?php
                        $reuses = json_decode(((string)($task->reuses ?? '')), true) ?: [];
                        if (!empty($reuses)):
                        ?>
                            <dt><i class="bi bi-recycle text-success me-1"></i>Reuses</dt>
                            <dd>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($reuses as $ru): ?>
                                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle fw-normal"><?= htmlspecialchars((string)$ru) ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <div class="form-text">Existing primitives this task builds on instead of reinventing.</div>
                            </dd>
                        <?php endif; ?>

                        <?php
                        $tags = json_decode(((string)$task->tags) ?? '', true) ?: [];
                        if (!empty($tags)):
                        ?>
                            <dt>Tags</dt>
                            <dd>
                                <?php foreach ($tags as $tag): ?>
                                    <span class="badge bg-secondary"><?= htmlspecialchars(($tag) ?? '') ?></span>
                                <?php endforeach; ?>
                            </dd>
                        <?php endif; ?>

                        <?php if ($task->branchName): ?>
                            <dt>Branch</dt>
                            <dd><code class="small"><?= htmlspecialchars(($task->branchName) ?? '') ?></code></dd>
                        <?php endif; ?>

                        <?php if ($task->baseBranch): ?>
                            <dt>Base Branch</dt>
                            <dd><code class="small"><?= htmlspecialchars(($task->baseBranch) ?? '') ?></code> <small class="text-muted">(PR target)</small></dd>
                        <?php endif; ?>

                        <?php if ($task->assignedPort): ?>
                            <dt>Test Port</dt>
                            <dd><?= $task->assignedPort ?></dd>
                        <?php endif; ?>

                        <?php if ($task->authcontrolLevel): ?>
                            <dt>Endpoint Level</dt>
                            <dd>
                                <?php
                                $levelName = array_search($task->authcontrolLevel, LEVELS) ?: 'Custom';
                                ?>
                                <?= ucfirst(strtolower($levelName)) ?> (<?= $task->authcontrolLevel ?>)
                            </dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <!-- Test Server Controls -->
            <?php if ($task->branchName && $task->assignedPort && $canRun): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-hdd-stack me-1"></i> Test Server</h6>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-2">
                            Run the branch on port <?= $task->assignedPort ?> for testing.
                        </p>
                        <?php if ($task->testServerSession): ?>
                            <div class="alert alert-success py-2 mb-2">
                                <i class="bi bi-check-circle me-1"></i>
                                <?php if ($task->proxyHash): ?>
                                    Running at <a href="https://<?= htmlspecialchars(($task->proxyHash) ?? '') ?>.<?= $baseDomain ?>" target="_blank" class="fw-bold">
                                        <?= htmlspecialchars(($task->proxyHash) ?? '') ?>.<?= $baseDomain ?>
                                    </a>
                                <?php else: ?>
                                    Running on <a href="http://localhost:<?= $task->assignedPort ?>" target="_blank">localhost:<?= $task->assignedPort ?></a>
                                <?php endif; ?>
                            </div>
                            <div class="d-grid gap-2">
                                <button class="btn btn-danger btn-sm" onclick="stopTestServer(<?= $task->id ?>)">
                                    <i class="bi bi-stop-fill"></i> Stop Server
                                </button>
                                <code class="small d-block text-center mt-1">tmux attach -t <?= htmlspecialchars(($task->testServerSession) ?? '') ?></code>
                            </div>
                        <?php else: ?>
                            <?php if ($task->proxyHash): ?>
                                <p class="small text-muted mb-2">
                                    <i class="bi bi-globe me-1"></i>
                                    Test URL: <code><?= htmlspecialchars(($task->proxyHash) ?? '') ?>.<?= $baseDomain ?></code>
                                </p>
                            <?php endif; ?>
                            <div class="d-grid">
                                <button class="btn btn-outline-primary btn-sm" onclick="startTestServer(<?= $task->id ?>)">
                                    <i class="bi bi-play-fill"></i> Start Test Server
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Recent Logs -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Recent Logs</h6>
                    <a href="/workbench/logs?id=<?= $task->id ?>" class="btn btn-sm btn-link">View All</a>
                </div>
                <div class="list-group list-group-flush" style="max-height: 300px; overflow-y: auto;" id="logsList">
                    <?php if (empty($logs)): ?>
                        <?php
                        // No tasklog rows — e.g. a plan-orchestrated task built before
                        // orchestrator logging existed. Synthesize a minimal timeline from
                        // the task's own lifecycle fields so the panel isn't blank.
                        $synth = [];
                        if (!empty($task->createdAt)) $synth[] = ['info', 'Task created', $task->createdAt];
                        if (!empty($task->startedAt)) $synth[] = ['info', 'Build started', $task->startedAt];
                        $termMap = ['merged' => ['info', 'Merged into base'], 'completed' => ['info', 'Completed'], 'conflict' => ['warning', 'Merge conflict'], 'failed' => ['error', 'Failed']];
                        if (isset($termMap[$task->status]) && !empty($task->completedAt)) {
                            [$lv, $lbl] = $termMap[$task->status];
                            if ($task->status === 'failed' && !empty($task->errorMessage)) $lbl .= ' — ' . $task->errorMessage;
                            $synth[] = [$lv, $lbl, $task->completedAt];
                        }
                        ?>
                        <?php if (empty($synth)): ?>
                            <div class="list-group-item text-muted small">No logs yet</div>
                        <?php else: ?>
                            <?php foreach ($synth as [$lv, $msg, $ts]): ?>
                                <div class="list-group-item py-2">
                                    <div class="d-flex justify-content-between">
                                        <span class="badge bg-<?= $lv === 'error' ? 'danger' : ($lv === 'warning' ? 'warning' : 'info') ?>"><?= $lv ?></span>
                                        <small class="text-muted"><?= date('g:i A', strtotime($ts)) ?></small>
                                    </div>
                                    <div class="small mt-1"><?= htmlspecialchars($msg) ?></div>
                                </div>
                            <?php endforeach; ?>
                            <div class="list-group-item text-muted" style="font-size:.7rem"><i class="bi bi-info-circle me-1"></i>Synthesized from the task's lifecycle — step-by-step logs appear for tasks built from now on.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php foreach (array_slice($logs, 0, 10) as $log): ?>
                            <div class="list-group-item py-2">
                                <div class="d-flex justify-content-between">
                                    <span class="badge bg-<?= match($log->logLevel) {
                                        'error' => 'danger',
                                        'warning' => 'warning',
                                        'info' => 'info',
                                        default => 'secondary'
                                    } ?>"><?= $log->logLevel ?></span>
                                    <small class="text-muted"><?= date('g:i A', strtotime($log->createdAt)) ?></small>
                                </div>
                                <div class="small mt-1"><?= htmlspecialchars(($log->message) ?? '') ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Danger Zone -->
            <?php if ($canDelete): ?>
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0">Danger Zone</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="/workbench/delete" onsubmit="return confirm('Delete this task? This cannot be undone.');">
                            <?php foreach ($csrf as $name => $value): ?>
                                <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                            <?php endforeach; ?>
                            <input type="hidden" name="id" value="<?= $task->id ?>">
                            <button type="submit" class="btn btn-danger btn-sm w-100">
                                <i class="bi bi-trash"></i> Delete Task
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const taskId = <?= $task->id ?>;
const taskStatus = '<?= $task->status ?>';
const csrfToken = '<?= \app\SimpleCsrf::getToken() ?>';
let pollInterval = null;

// Recover a failed plan subtask: reset + re-launch the orchestrator (which auto-retries).
async function taskRetry(id, btn) {
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Retrying…'; }
    try {
        const fd = new FormData();
        fd.append('task_id', id);
        fd.append('_csrf_token', csrfToken);
        const r = await fetch('/workbench/taskretry', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) { location.reload(); return; }
        alert(j.message || 'Retry failed');
    } catch (e) { alert('Retry failed: ' + e); }
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i> Fix &amp; retry'; }
}

// Task actions
async function runTask(id) {
    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Starting...';

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/run', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-play-fill"></i> Run with Claude';
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-fill"></i> Run with Claude';
    }
}

async function forceResetTask(id) {
    if (!confirm('Force reset this task? This will kill any running session and reset to pending.')) return;

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Resetting...';

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/forcereset', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Force Reset';
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Force Reset';
    }
}

async function resolveConflict(id) {
    if (!confirm("Update this task's branch from the current base and have the agent resolve any merge conflicts? This keeps the task's work.")) return;
    const btn = event.target.closest('button');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Resolving...';
    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf_token', csrfToken);
        const response = await fetch('/workbench/resolveconflict', { method: 'POST', body: formData });
        const data = await response.json();
        if (data.success) {
            alert(data.message || (data.clean ? 'No conflict — ready to Approve & Merge.' : 'Agent is resolving the conflict.'));
            location.reload();
            return;
        }
        alert('Error: ' + (data.message || data.error || 'Could not resolve'));
    } catch (e) {
        alert('Error: ' + e.message);
    }
    btn.disabled = false;
    btn.innerHTML = orig;
}

async function rerunTask(id) {
    if (!confirm('Re-run this task with Claude?')) return;

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Starting...';

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/rerun', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Re-run';
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Re-run';
    }
}

async function pauseTask(id) {
    const formData = new FormData();
    formData.append('id', id);
    formData.append('_csrf_token', csrfToken);
    const response = await fetch('/workbench/pause', { method: 'POST', body: formData });
    const data = await response.json();
    if (data.success) location.reload();
    else alert('Error: ' + data.message);
}

async function resumeTask(id) {
    const formData = new FormData();
    formData.append('id', id);
    formData.append('_csrf_token', csrfToken);
    const response = await fetch('/workbench/resume', { method: 'POST', body: formData });
    const data = await response.json();
    if (data.success) location.reload();
    else alert('Error: ' + data.message);
}

async function stopTask(id) {
    if (!confirm('Stop the Claude runner? Progress may be lost.')) return;
    const formData = new FormData();
    formData.append('id', id);
    formData.append('_csrf_token', csrfToken);
    const response = await fetch('/workbench/stop', { method: 'POST', body: formData });
    const data = await response.json();
    if (data.success) location.reload();
    else alert('Error: ' + data.message);
}

// Progress polling
async function pollProgress() {
    try {
        const response = await fetch('/workbench/progress?id=' + taskId);
        const data = await response.json();

        document.getElementById('lastUpdate').textContent = 'Updated ' + new Date().toLocaleTimeString();

        // Check for status change - reload page to update UI
        if (data.status !== taskStatus) {
            location.reload();
            return;
        }

        if (data.status !== 'running' && data.status !== 'queued' && data.status !== 'awaiting') {
            clearInterval(pollInterval);
            location.reload();
            return;
        }

        let html = '';

        // For running status, show live progress
        if (data.status === 'running' || data.status === 'queued') {
            if (data.live) {
                html += '<div class="mb-2"><strong>Status:</strong> ' + (data.live.status || 'Running') + '</div>';
                if (data.live.current_task) {
                    html += '<div class="mb-2"><strong>Current:</strong> ' + data.live.current_task + '</div>';
                }
                if (data.live.files_changed && data.live.files_changed.length > 0) {
                    html += '<div class="mb-2"><strong>Files Changed:</strong></div>';
                    html += '<ul class="list-unstyled ms-3">';
                    data.live.files_changed.forEach(f => {
                        html += '<li><code>' + f + '</code></li>';
                    });
                    html += '</ul>';
                }
            }

            if (data.recent_logs && data.recent_logs.length > 0) {
                html += '<div class="mt-3"><strong>Recent Activity:</strong></div>';
                html += '<div class="small text-muted mt-2">';
                data.recent_logs.slice(0, 5).forEach(log => {
                    html += '<div>' + log.message + '</div>';
                });
                html += '</div>';
            }

            document.getElementById('progressContent').innerHTML = html || '<p class="text-muted">Waiting for activity...</p>';
        }

        // Show snapshot if available
        if (data.snapshot && data.snapshot.content) {
            const snapshotDiv = document.getElementById('snapshotContent');
            const snapshotText = document.getElementById('snapshotText');
            if (snapshotDiv && snapshotText) {
                snapshotText.textContent = data.snapshot.content;
                snapshotDiv.style.display = 'block';
            }
        }

        // Also refresh comments if there are new ones
        if (data.comments && data.comments.length > 0) {
            updateCommentsList(data.comments);
        }

    } catch (e) {
        console.error('Poll error:', e);
    }
}

// Update comments list without full page reload
function updateCommentsList(comments) {
    const list = document.getElementById('commentsList');
    const noComments = document.getElementById('noComments');
    if (noComments) noComments.remove();

    // Get existing comment IDs
    const existingIds = new Set();
    list.querySelectorAll('[data-comment-id]').forEach(el => {
        existingIds.add(el.getAttribute('data-comment-id'));
    });

    // Add new comments
    comments.forEach(comment => {
        if (!existingIds.has(String(comment.id))) {
            const isFromClaude = comment.is_from_claude;

            // Build comment content with optional image
            let contentHtml = '';
            if (comment.content) {
                contentHtml += `<div class="comment-content">${comment.content.replace(/\n/g, '<br>')}</div>`;
            }
            if (comment.image_path) {
                const imgUrl = '/' + comment.image_path;
                contentHtml += `
                    <div class="comment-image mt-2">
                        <a href="${imgUrl}" target="_blank" class="d-block">
                            <img src="${imgUrl}" class="img-fluid rounded border" style="max-height: 300px; cursor: zoom-in;" alt="Attached image">
                        </a>
                    </div>
                `;
            }

            const html = `
                <div class="d-flex mb-3 ${isFromClaude ? 'flex-row-reverse' : ''}" data-comment-id="${comment.id}">
                    ${isFromClaude ?
                        `<div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center ms-2" style="width: 40px; height: 40px;">
                            <i class="bi bi-robot"></i>
                        </div>` :
                        `<div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2" style="width: 40px; height: 40px;">
                            ${comment.author.charAt(0).toUpperCase()}
                        </div>`
                    }
                    <div class="flex-grow-1">
                        <div class="${isFromClaude ? 'bg-primary bg-opacity-10 border border-primary' : 'bg-light text-dark border'} rounded p-3">
                            <div class="d-flex justify-content-between mb-1">
                                <strong>${isFromClaude ? '<i class="bi bi-robot me-1"></i>' : ''}${comment.author}</strong>
                                <small class="text-muted">${new Date(comment.created_at).toLocaleString()}</small>
                            </div>
                            ${contentHtml}
                        </div>
                    </div>
                </div>
            `;
            // Newest at the top — consistent with the initial render (array_reverse) and
            // so a just-arrived reply surfaces above older messages. Iterating the ASC
            // feed and prepending each leaves the newest of the batch at the very top.
            list.insertAdjacentHTML('afterbegin', html);
        }
    });
}

// Start polling if task is running or awaiting
if (taskStatus === 'running' || taskStatus === 'queued' || taskStatus === 'awaiting') {
    pollProgress();
    pollInterval = setInterval(pollProgress, 3000);
}

// Mark task as complete
async function markComplete(id) {
    if (!confirm('Mark this task as complete?')) return;

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Completing...';

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/complete', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle"></i> Mark Complete';
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-circle"></i> Mark Complete';
    }
}

// Mark complete AND merge the task branch back into its base (instance/<slug> for
// instance tasks) — the same gh-free local merge as Approve & Merge.
async function markCompleteMerge(id) {
    if (!confirm('Merge this task\'s changes into the instance and mark it complete?')) return;

    const btn = event.target.closest('button');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Merging...';

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('merge', '1');
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/complete', { method: 'POST', body: formData });
        const data = await response.json();

        if (data.success) {
            if (data.merged === false && data.merge_reason) {
                alert('Marked complete, but NOT merged:\n\n' + data.merge_reason);
            }
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

// Comment form - Ctrl+Enter to submit
document.getElementById('commentContent').addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        document.getElementById('commentForm').dispatchEvent(new Event('submit'));
    }
});

// Inline comment - Ctrl+Enter to submit (if exists)
const inlineComment = document.getElementById('inlineCommentContent');
if (inlineComment) {
    inlineComment.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            sendInlineComment();
        }
    });
}

// Image upload handling
let selectedImageFile = null;

document.getElementById('imageUpload').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (!file) return;

    // Validate file type
    const validTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
    if (!validTypes.includes(file.type)) {
        alert('Invalid image type. Please use PNG, JPEG, GIF, or WEBP.');
        e.target.value = '';
        return;
    }

    // Validate file size (max 10MB)
    if (file.size > 10 * 1024 * 1024) {
        alert('Image too large. Max size: 10MB');
        e.target.value = '';
        return;
    }

    selectedImageFile = file;

    // Show preview
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('imagePreview').src = e.target.result;
        document.getElementById('imagePreviewContainer').style.display = 'block';
    };
    reader.readAsDataURL(file);
});

function clearImagePreview() {
    selectedImageFile = null;
    document.getElementById('imageUpload').value = '';
    document.getElementById('imagePreviewContainer').style.display = 'none';
    document.getElementById('imagePreview').src = '';
}

document.getElementById('commentForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const content = document.getElementById('commentContent').value.trim();

    // If no content and no image, do nothing
    if (!content && !selectedImageFile) return;

    const formData = new FormData();
    formData.append('id', taskId);
    formData.append('_csrf_token', csrfToken);

    // Determine which endpoint to use
    let endpoint = '/workbench/comment';
    if (selectedImageFile) {
        endpoint = '/workbench/uploadimage';
        formData.append('image', selectedImageFile);
        if (content) {
            formData.append('content', content);
        }
    } else {
        formData.append('content', content);
    }

    try {
        const response = await fetch(endpoint, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            const noComments = document.getElementById('noComments');
            if (noComments) noComments.remove();

            let commentHtml = '';
            if (data.comment.content) {
                commentHtml += `<div class="comment-content">${data.comment.content.replace(/\n/g, '<br>')}</div>`;
            }
            if (data.comment.image_url || data.comment.image_path) {
                const imgUrl = data.comment.image_url || '/' + data.comment.image_path;
                commentHtml += `
                    <div class="comment-image mt-2">
                        <a href="${imgUrl}" target="_blank" class="d-block">
                            <img src="${imgUrl}" class="img-fluid rounded border" style="max-height: 300px; cursor: zoom-in;" alt="Attached image">
                        </a>
                    </div>
                `;
            }

            const html = `
                <div class="d-flex mb-3" data-comment-id="${data.comment.id}">
                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 flex-shrink-0" style="width: 40px; height: 40px;">
                        ${data.comment.author.charAt(0).toUpperCase()}
                    </div>
                    <div class="flex-grow-1">
                        <div class="bg-light text-dark border rounded p-3 position-relative">
                            <div class="d-flex justify-content-between mb-1 pe-4">
                                <strong>${data.comment.author}</strong>
                                <small class="text-muted">Just now</small>
                            </div>
                            ${commentHtml}
                        </div>
                    </div>
                </div>
            `;
            document.getElementById('commentsList').insertAdjacentHTML('afterbegin', html);
            document.getElementById('commentContent').value = '';
            clearImagePreview();
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error posting comment');
    }
});

// Send inline comment (from Your Turn card)
async function sendInlineComment() {
    const textarea = document.getElementById('inlineCommentContent');
    const content = textarea.value.trim();
    if (!content) {
        textarea.focus();
        return;
    }

    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';

    try {
        const formData = new FormData();
        formData.append('id', taskId);
        formData.append('content', content);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/comment', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            // Clear the inline textarea
            textarea.value = '';

            // Add to comments list
            const noComments = document.getElementById('noComments');
            if (noComments) noComments.remove();

            const html = `
                <div class="d-flex mb-3" data-comment-id="${data.comment.id}">
                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 flex-shrink-0" style="width: 40px; height: 40px;">
                        ${data.comment.author.charAt(0).toUpperCase()}
                    </div>
                    <div class="flex-grow-1">
                        <div class="bg-light text-dark border rounded p-3 position-relative">
                            <div class="d-flex justify-content-between mb-1 pe-4">
                                <strong>${data.comment.author}</strong>
                                <small class="text-muted">Just now</small>
                            </div>
                            <div>${data.comment.content.replace(/\n/g, '<br>')}</div>
                        </div>
                    </div>
                </div>
            `;
            document.getElementById('commentsList').insertAdjacentHTML('afterbegin', html);

            // If sent to session, status might change - reload after short delay
            if (data.sent_to_session) {
                setTimeout(() => location.reload(), 1500);
            }
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error sending instructions');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

// Delete comment
async function deleteComment(commentId) {
    if (!confirm('Delete this message?')) return;

    try {
        const formData = new FormData();
        formData.append('id', taskId);
        formData.append('comment_id', commentId);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/deletecomment', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            // Remove the comment from DOM
            const commentEl = document.querySelector(`[data-comment-id="${commentId}"]`);
            if (commentEl) {
                commentEl.remove();
            }
        } else {
            alert('Error: ' + data.message);
        }
    } catch (e) {
        alert('Error deleting comment');
    }
}

// Start test server
async function startTestServer(id) {
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Starting...';

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/startserver', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

// Stop test server
async function stopTestServer(id) {
    const btn = event.target.closest('button');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Stopping...';

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/stopserver', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

// Approve task (admin only) - with options from modal
async function approveTask(id) {
    const btn = document.getElementById('approveSubmitBtn');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Approving...';

    // Get checkbox values (only if not disabled)
    const createPrCheckbox = document.getElementById('approveCreatePr');
    const mergePrCheckbox = document.getElementById('approveMergePr');
    const stopSessionCheckbox = document.getElementById('approveStopSession');
    const stopServerCheckbox = document.getElementById('approveStopServer');
    const deleteWorkspaceCheckbox = document.getElementById('approveDeleteWorkspace');
    const notesField = document.getElementById('approveNotes');

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('_csrf_token', csrfToken);

        // Add options
        formData.append('create_pr', createPrCheckbox && !createPrCheckbox.disabled && createPrCheckbox.checked ? '1' : '0');
        formData.append('merge_pr', mergePrCheckbox && !mergePrCheckbox.disabled && mergePrCheckbox.checked ? '1' : '0');
        formData.append('stop_session', stopSessionCheckbox && !stopSessionCheckbox.disabled && stopSessionCheckbox.checked ? '1' : '0');
        formData.append('stop_server', stopServerCheckbox && !stopServerCheckbox.disabled && stopServerCheckbox.checked ? '1' : '0');
        formData.append('delete_workspace', deleteWorkspaceCheckbox && !deleteWorkspaceCheckbox.disabled && deleteWorkspaceCheckbox.checked ? '1' : '0');
        formData.append('notes', notesField ? notesField.value : '');

        const response = await fetch('/workbench/approve', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('approveModal')).hide();

            let message = 'Task approved!';
            if (data.pr_created) message += ' PR created.';
            if (data.merged) {
                message += data.pr_merged ? ' PR merged.' : ' Merged.';
            } else if (data.merge_requested && data.merge_reason) {
                message += '\n\n⚠ Not merged — ' + data.merge_reason + '\nStatus left as "completed".';
            }
            if (data.merge_error) message += ' (PR merge failed: ' + data.merge_error + ')';
            if (data.workspace_deleted) message += ' Workspace deleted.';

            alert(message);
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}

// Decline task (admin only) - close PR and send back for revision
async function declineTask(id) {
    const reason = document.getElementById('declineReason').value.trim();

    const btn = document.getElementById('declineSubmitBtn');
    const originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Declining...';

    try {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('reason', reason);
        formData.append('_csrf_token', csrfToken);

        const response = await fetch('/workbench/decline', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();

        if (data.success) {
            bootstrap.Modal.getInstance(document.getElementById('declineModal')).hide();
            alert('Task declined and sent back for revision.');
            location.reload();
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = originalHtml;
        }
    } catch (e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}
</script>

<!-- Decline Modal -->
<div class="modal fade" id="declineModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Decline Task</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>This will close the PR and send the task back for revision.</p>
                <div class="mb-3">
                    <label for="declineReason" class="form-label">Reason for declining (optional)</label>
                    <textarea class="form-control" id="declineReason" rows="4"
                              placeholder="Explain what needs to be changed..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="declineSubmitBtn"
                        onclick="declineTask(<?= $task->id ?>)">
                    <i class="bi bi-x-circle"></i> Decline Task
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check-circle-fill me-2"></i>Approve Task</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Select the actions to perform when approving this task:</p>

                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="approveCreatePr" checked
                           <?= !empty($task->prUrl) ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="approveCreatePr">
                        <i class="bi bi-git me-1"></i>
                        <?php if (!empty($task->prUrl)): ?>
                            PR already exists
                        <?php else: ?>
                            Create Pull Request
                        <?php endif; ?>
                    </label>
                </div>

                <?php if (!empty($task->prUrl)): ?>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="approveMergePr" checked>
                    <label class="form-check-label" for="approveMergePr">
                        <i class="bi bi-arrow-down-circle me-1"></i>
                        Merge Pull Request (squash)
                    </label>
                </div>
                <?php endif; ?>

                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="approveStopSession" checked
                           <?= empty($task->tmuxSession) ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="approveStopSession">
                        <i class="bi bi-terminal me-1"></i>
                        Stop Claude session
                        <?= empty($task->tmuxSession) ? '<span class="text-muted">(not running)</span>' : '' ?>
                    </label>
                </div>

                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="approveStopServer" checked
                           <?= empty($task->testServerSession) ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="approveStopServer">
                        <i class="bi bi-hdd-stack me-1"></i>
                        Stop test server
                        <?= empty($task->testServerSession) ? '<span class="text-muted">(not running)</span>' : '' ?>
                    </label>
                </div>

                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="approveDeleteWorkspace"
                           <?= empty($task->projectPath) ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="approveDeleteWorkspace">
                        <i class="bi bi-folder-x me-1 text-danger"></i>
                        Delete workspace files
                        <?= empty($task->projectPath) ? '<span class="text-muted">(no workspace)</span>' : '' ?>
                    </label>
                    <?php if (!empty($task->projectPath)): ?>
                    <small class="d-block text-muted ms-4"><?= htmlspecialchars(($task->projectPath) ?? '') ?></small>
                    <?php endif; ?>
                </div>

                <hr>
                <div class="mb-3">
                    <label for="approveNotes" class="form-label">Notes (optional)</label>
                    <textarea class="form-control" id="approveNotes" rows="2"
                              placeholder="Add any notes about this approval..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="approveSubmitBtn"
                        onclick="approveTask(<?= $task->id ?>)">
                    <i class="bi bi-check-circle-fill"></i> Approve Task
                </button>
            </div>
        </div>
    </div>
</div>
