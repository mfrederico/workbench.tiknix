<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Task Board</h1>
        <div>
            <a href="<?= htmlspecialchars((string)Flight::get('sidecar.core_url')) ?>/firehose" target="_top" class="btn btn-outline-danger me-2" title="Errors captured live from your instances">
                <i class="bi bi-fire"></i> Firehose
            </a>
            <a href="<?= htmlspecialchars((string)Flight::get('sidecar.core_url')) ?>/teams" target="_top" class="btn btn-outline-secondary me-2">
                <i class="bi bi-people"></i> Teams
            </a>
            <?php
            $createParams = [];
            if (!empty($filters['team_id']) && $filters['team_id'] !== 'personal' && is_numeric($filters['team_id'])) {
                $createParams['team_id'] = (int)$filters['team_id'];
            }
            // Carry the current instance filter so the create page pre-selects it.
            if (!empty($filters['instance_tag'])) {
                $createParams['instance_tag'] = $filters['instance_tag'];
            }
            $createUrl = '/workbench/create' . (!empty($createParams) ? '?' . http_build_query($createParams) : '');
            ?>
            <?php /* Only when the SELECTED project actually has a live monday
                     connection. An import button on a project with nowhere to
                     import from is an offer that fails after the click, and it
                     invites people to go looking for a setting that is not
                     missing — the connection belongs to a different project. */ ?>
            <?php if (!empty($hasMonday)): ?>
                <a href="/workbench/monday" class="btn btn-outline-primary me-2">
                    <i class="bi bi-box-arrow-in-down"></i> Import from monday.com
                </a>
                <?php /* The import gate only stops work that was ALREADY closed when
                         you looked. Boards move on afterwards, which is the common
                         case — this re-checks what is here and flags what has since
                         been finished, cancelled or deleted. It never removes a task:
                         a board changing is not permission to delete work somebody
                         may have started. */ ?>
                <form method="POST" action="/workbench/mondayrefresh" class="d-inline">
                    <?php foreach (($csrf ?? []) as $cn => $cv): ?>
                        <input type="hidden" name="<?= htmlspecialchars($cn) ?>" value="<?= htmlspecialchars($cv) ?>">
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-outline-secondary me-2"
                            title="Re-read imported items from monday: flags any that were finished, cancelled or deleted, and pulls in changed titles and briefs. Never deletes a task, and never touches its status, comments or branch.">
                        <i class="bi bi-arrow-repeat"></i> Sync with monday
                    </button>
                </form>
            <?php endif; ?>
            <a href="<?= $createUrl ?>" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> New Task
            </a>
        </div>
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

    <?php
    // Preserve the "other" active filter when building links, so switching instance
    // keeps the status filter and switching status keeps the instance.
    $statusQ = !empty($filters['status']) ? (string)$filters['status'] : '';
    $tagQ    = !empty($filters['instance_tag']) ? (string)$filters['instance_tag'] : '';
    $tagLink = function (string $tag) use ($statusQ) {
        $qs = [];
        if ($tag !== '')     $qs['instance_tag'] = $tag;
        if ($statusQ !== '') $qs['status'] = $statusQ;
        return '/workbench' . ($qs ? '?' . http_build_query($qs) : '');
    };
    $statusLink = function (string $status) use ($tagQ) {
        $qs = [];
        if ($status !== '') $qs['status'] = $status;
        if ($tagQ !== '')   $qs['instance_tag'] = $tagQ;
        return '/workbench' . ($qs ? '?' . http_build_query($qs) : '');
    };
    $statusTabs = [
        ''          => ['All',       'grid-1x2',     'secondary', (int)($counts['total'] ?? 0)],
        'pending'   => ['Pending',   'circle',       'secondary', (int)($counts['pending'] ?? 0)],
        'running'   => ['Running',   'play-circle',  'primary',   (int)(($counts['running'] ?? 0) + ($counts['queued'] ?? 0))],
        'completed' => ['Completed', 'check-circle', 'success',   (int)($counts['completed'] ?? 0)],
        'failed'    => ['Failed',    'x-circle',     'danger',    (int)($counts['failed'] ?? 0)],
    ];
    ?>
    <div class="row">
        <!--
          NO INSTANCE PICKER. The project is chosen once, in core, and this board shows
          that project's tasks. A second picker here is how the board could show one
          project's work while you believed you were in another.
        -->
        <div class="col-lg-3 col-md-4 mb-4">
            <div class="card shadow-sm">
                <div class="card-header"><span class="fw-semibold">Project</span></div>
                <div class="card-body">
                    <?php if (!empty($selected)): ?>
                        <div class="text-body-secondary small text-uppercase" style="letter-spacing:.06em">Working on</div>
                        <div class="fw-semibold fs-6 mt-1"><?= htmlspecialchars(($selected['name'] ?? $selected['slug']) ?? '') ?></div>
                        <div class="text-body-secondary small"><code><?= htmlspecialchars(($selected['slug']) ?? '') ?></code></div>
                    <?php elseif (!empty($projectUnavailable)): ?>
                        <div class="text-body-secondary small">
                            Your selected project can't be built here — the control plane
                            isn't a buildable instance. Pick another.
                        </div>
                    <?php else: ?>
                        <div class="text-body-secondary small">No project selected.</div>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars(\app\Sidecar\Sso::projectPickerUrl()) ?>" target="_top"
                       class="btn btn-outline-secondary btn-sm w-100 mt-3">
                        <i class="bi bi-grid-3x3-gap me-1"></i>Change project
                    </a>
                </div>
            </div>
        </div>

        <!-- Task List -->
        <div class="col-lg-9 col-md-8">
            <!-- Status filter — moved above the task list -->
            <ul class="nav nav-pills mb-3 gap-1 flex-wrap">
                <?php foreach ($statusTabs as $sKey => $sInfo): [$sLabel, $sIcon, $sColor, $sCount] = $sInfo; $sActive = ((string)($filters['status'] ?? '')) === $sKey; ?>
                <li class="nav-item">
                    <a class="nav-link <?= $sActive ? 'active' : '' ?>" href="<?= htmlspecialchars($statusLink($sKey)) ?>">
                        <i class="bi bi-<?= $sIcon ?>"></i> <?= $sLabel ?>
                        <span class="badge bg-<?= $sActive ? 'light text-dark' : $sColor ?> rounded-pill ms-1"><?= $sCount ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>

            <script>window.WB_CSRF = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;</script>
            <script>
            // New-instance provisioning (mirrors /aibuilder): create, then jump into
            // the new instance's builder to start working.
            (function(){
                var form = document.getElementById('wb-create-form');
                if (!form) return;
                form.addEventListener('submit', function(e){
                    e.preventDefault();
                    var btn = form.querySelector('button[type=submit]'), msg = document.getElementById('wb-create-msg');
                    btn.disabled = true; msg.textContent = 'Provisioning… this can take a minute.';
                    fetch('/aibuilder/create', {
                        method: 'POST',
                        headers: {'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':window.WB_CSRF||'','X-Requested-With':'XMLHttpRequest'},
                        body: new URLSearchParams({slug: form.slug.value.trim(), engine: form.engine.value, csrf_token: window.WB_CSRF||''}).toString()
                    }).then(function(r){ return r.json(); }).then(function(j){
                        if (j && j.success && j.data && j.data.id) { window.location = '/aibuilder/open/' + j.data.id; }
                        else { msg.textContent = (j && j.message) || 'Failed.'; btn.disabled = false; }
                    }).catch(function(){ msg.textContent = 'Network error.'; btn.disabled = false; });
                });
            })();
            </script>

            <?php /* Decomposing banner for THE SELECTED PROJECT — detected server-side
                     from a live planner session, so it survives navigating away, and
                     armed by ?decomposing=1 for the moment right after submitting, before
                     tmux has the session. */ ?>
            <?php if (!empty($decomposing)): ?>
                <div id="wbDecomposeBanner" class="alert alert-info d-flex align-items-center">
                    <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                    <div>
                        <strong>Decomposing your goal into a plan…</strong>
                        <?php if (!empty($decomposingTag)): ?>
                            <span class="ui-mono small">(<?= htmlspecialchars($decomposingTag) ?>)</span>
                        <?php endif; ?>
                        <div class="small text-muted">The planner is grounding itself in the codebase and drafting tasks. This page refreshes automatically when the plan is ready — you can browse away and it'll keep working.</div>
                    </div>
                    <?php /* Cancelling used to mean killing a tmux session from a shell. The
                             moment you need it is the moment you realise it is planning the
                             wrong thing, so it belongs right here on the banner. */ ?>
                    <button id="wbDecomposeStop" class="btn btn-outline-secondary btn-sm ms-auto" type="button">
                        <i class="bi bi-stop-circle me-1"></i>Stop
                    </button>
                </div>
                <script>
                (function(){
                    var el = document.getElementById('wbDecomposeBanner');
                    if (!el) return;
                    var baseline = null, tries = 0, stopPolling = false;
                    // Reload ONLY when there is something new to show. The banner is armed
                    // server-side by a live planner session, so an unconditional reload here
                    // re-armed it, polled again, reloaded again — a refresh loop that ran for
                    // as long as the session existed (and a finished session lingers in tmux
                    // for a while after the planner exits). If the planner has simply stopped
                    // with nothing new, clear the banner in place and stop.
                    function done(landed){
                        stopPolling = true;
                        if (!landed) { el.remove(); return; }
                        var u = new URL(window.location.href);
                        u.searchParams.delete('decomposing');   // drop so the banner does not re-arm
                        window.location.href = u.toString();
                    }
                    function poll(){
                        if (stopPolling) return;
                        // No instance id: the endpoint answers for the project you are on,
                        // which is the only one this banner speaks for.
                        fetch('/workbench/decomposestatus', {headers:{'X-Requested-With':'XMLHttpRequest'}})
                            .then(function(r){ return r.json(); })
                            .then(function(j){
                                var d = (j && j.data) ? j.data : j;
                                if (!d) { if (++tries < 240) setTimeout(poll, 3000); return; }
                                var newest = d.newest_plan_id || 0;
                                if (baseline === null) baseline = newest;
                                if (newest > baseline) return done(true);    // a plan landed: show it
                                if (d.running === false) return done(false); // stopped, nothing new
                                if (++tries < 240) setTimeout(poll, 3000);   // ~12 min cap
                            })
                            .catch(function(){ if (++tries < 240) setTimeout(poll, 3000); });
                    }
                    setTimeout(poll, 3000);

                    var stopBtn = document.getElementById('wbDecomposeStop');
                    if (stopBtn) stopBtn.addEventListener('click', function () {
                        if (!window.confirm('Stop decomposing? The planner run so far is discarded.')) return;
                        stopBtn.disabled = true;
                        stopBtn.textContent = 'Stopping…';
                        fetch('/workbench/decomposestop', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded',
                                      'X-CSRF-TOKEN': window.WB_CSRF || '',
                                      'X-Requested-With': 'XMLHttpRequest'},
                            body: '_csrf_token=' + encodeURIComponent(window.WB_CSRF || '')
                        }).then(function (r) { return r.json(); }).then(function (j) {
                            window.location = '/workbench';   // banner clears with the session
                        }).catch(function () {
                            stopBtn.disabled = false; stopBtn.textContent = 'Stop';
                        });
                    });
                })();
                </script>
            <?php endif; ?>

            <?php if (empty($tasks)): ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 3rem; color: #6c757d;"></i>
                        <h4 class="mt-3">No Tasks Found</h4>
                        <p class="text-muted">Create a new task to get started with AI-assisted development.</p>
                        <a href="<?= $createUrl ?>" class="btn btn-primary">
                            <i class="bi bi-plus-lg"></i> Create Task
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <?php
                // Grouping: subtasks nest under their plan parent (a rendered group header).
                // A plan parent that HAS visible children becomes the header, so it is
                // skipped as a body row; everything else (subtasks, standalone tasks) is a leaf.
                $parentSet = array_flip($parentIdsWithChildren ?? []);
                // Pending (non-approved) task ids per group — powers the plan-level
                // "select the whole plan" checkbox in each group header.
                $groupPendingIds = [];
                foreach ($tasks as $t) {
                    if (isset($parentSet[(int)$t->id])) continue;
                    if (($t->status ?? '') !== 'pending') continue;
                    $gk = !empty($t->parentTaskId) ? ('plan:' . (int)$t->parentTaskId) : 'solo';
                    $groupPendingIds[$gk][] = (int)$t->id;
                }
                $planMetaJs = [];
                foreach (($planMeta ?? []) as $pid => $m) {
                    $planMetaJs['plan:' . $pid] = [
                        'id'          => (int)$pid,
                        'title'       => $m['title'],
                        'tag'         => $m['instanceTag'],
                        'status'      => $m['planStatus'] ?: $m['status'],
                        'plan_status' => $m['planStatus'] ?: '',
                        'auto_build'  => !empty($m['autoBuild']),
                        'url'         => '/workbench/view?id=' . $pid,
                        'taskIds'     => $groupPendingIds['plan:' . $pid] ?? [],
                    ];
                }
                $planMetaJs['solo'] = ['id' => 0, 'title' => 'Standalone tasks', 'tag' => null, 'status' => null, 'plan_status' => '', 'url' => null, 'taskIds' => $groupPendingIds['solo'] ?? []];

                // Group ordering key: the most recent createdAt in each group, so the
                // whole table defaults to newest-first BY GROUP (not by plan-id string).
                // Must be one constant per group (incl. the parent header row) or the
                // RowGroup rows won't stay contiguous.
                $groupOrder = [];
                foreach ($tasks as $t) {
                    $gk = !empty($t->parentTaskId) ? ('plan:' . (int)$t->parentTaskId)
                        : (isset($parentSet[(int)$t->id]) ? ('plan:' . (int)$t->id) : 'solo');
                    $ts = $t->createdAt ? strtotime((string)$t->createdAt) : 0;
                    if (!isset($groupOrder[$gk]) || $ts > $groupOrder[$gk]) $groupOrder[$gk] = $ts;
                }
                ?>
                <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
                <link rel="stylesheet" href="https://cdn.datatables.net/rowgroup/1.4.1/css/rowGroup.bootstrap5.min.css">
                <style>
                    #wbTasks .wb-grp{display:none}
                    #wbTasks .wb-consol,#wbTasks .wb-consol-group{width:1.15em;height:1.15em;cursor:pointer;border:1px solid #6c757d;opacity:1;vertical-align:-.15em}
                    #wbTasks .wb-consol:checked,#wbTasks .wb-consol-group:checked{background-color:#ffc107;border-color:#ffc107}
                    #wbTasks .wb-consol-group{width:1.25em;height:1.25em}
                </style>

                <!-- Consolidate action bar: appears when 2+ pending tasks are checked -->
                <div id="wbConsolBar" class="bg-dark text-white shadow rounded-pill px-3 py-2" style="display:none; position:fixed; left:50%; transform:translateX(-50%); bottom:1.25rem; z-index:1050; align-items:center; gap:.75rem;">
                    <span><i class="bi bi-check2-square me-1"></i><span id="wbConsolCount">0</span> selected</span>
                    <button id="wbConsolBtn" class="btn btn-warning btn-sm" type="button" disabled><i class="bi bi-union me-1"></i>Consolidate into one</button>
                    <button id="wbConsolCancel" class="btn btn-outline-light btn-sm" type="button">Clear</button>
                </div>
                <div class="card">
                    <div class="card-body">
                        <table id="wbTasks" class="table table-hover align-middle mb-0" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="wb-grp">Group</th>
                                    <th>Task</th>
                                    <th>Instance</th>
                                    <th>Type</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tasks as $task): ?>
                                    <?php
                                    if (isset($parentSet[(int)$task->id])) continue; // header row, not a leaf
                                    $typeInfo = $taskTypes[$task->taskType ?? 'feature'] ?? $taskTypes['feature'];
                                    $priorityInfo = $priorities[$task->priority ?? 3] ?? $priorities[3];
                                    $isSub = !empty($task->parentTaskId);
                                    $groupKey = $isSub ? ('plan:' . (int)$task->parentTaskId) : 'solo';
                                    ?>
                                    <tr>
                                        <?php // Prefix an inverted-timestamp so string-sorting column 0 puts newest groups first, while the value still groups by key (parsed back out in startRender). ?>
                                        <td class="wb-grp"><?= htmlspecialchars(sprintf('%010d', 9999999999 - (int)($groupOrder[$groupKey] ?? 0)) . '~' . $groupKey) ?></td>
                                        <td>
                                            <?php if ($task->status === 'pending'): ?>
                                                <input type="checkbox" class="form-check-input wb-consol me-1 align-middle" value="<?= (int)$task->id ?>" title="Select to consolidate with other pending tasks">
                                            <?php endif; ?>
                                            <?php if ($isSub): ?><i class="bi bi-arrow-return-right text-muted me-1"></i><?php endif; ?>
                                            <?php /* The id, so a task can be referred to. Everything else on this
                                                     row is prose — quoting a title in a message is ambiguous the
                                                     moment two tasks start with "Fix:". Monospaced and muted so it
                                                     reads as a handle rather than competing with the title. */ ?>
                                            <code class="text-body-secondary small me-1">#<?= (int) $task->id ?></code>
                                            <a href="/workbench/view?id=<?= $task->id ?>" class="text-decoration-none fw-medium">
                                                <?= htmlspecialchars(($task->title) ?? '') ?>
                                            </a>
                                            <?php if (($task->source ?? '') === 'detected_error'): ?>
                                                <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle ms-1" title="Auto-created from a detected runtime error"><i class="bi bi-fire"></i> detected</span>
                                            <?php endif; ?>
                                            <?php /* Set by "Re-check monday". The task is left exactly as it
                                                     is — this only says the source item stopped being open, so
                                                     whoever owns the work decides what that means. Says WHICH
                                                     status, because cancelled and done are different news. */ ?>
                                            <?php if (!empty($task->mondayClosed) || !empty($task->mondayMissing)): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1"
                                                      title="Checked against monday<?= !empty($task->mondayCheckedAt) ? ' on ' . htmlspecialchars((string) $task->mondayCheckedAt) : '' ?>. Nothing was changed here.">
                                                    <i class="bi bi-exclamation-triangle"></i>
                                                    <?= !empty($task->mondayMissing)
                                                        ? 'gone from monday'
                                                        : htmlspecialchars(($task->mondayStatus ?: 'closed') . ' in monday') ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($task->teamId): ?>
                                                <br><small class="text-muted"><i class="bi bi-people"></i> Team task</small>
                                            <?php endif; ?>
                                            <?php
                                            $reuses = json_decode((string)($task->reuses ?? ''), true);
                                            if (is_array($reuses) && $reuses):
                                            ?>
                                                <div class="mt-1 d-flex flex-wrap gap-1 align-items-center">
                                                    <span class="text-muted" style="font-size:.62rem" title="Existing primitives this task reuses"><i class="bi bi-recycle"></i></span>
                                                    <?php foreach (array_slice($reuses, 0, 6) as $ru): ?>
                                                        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle fw-normal" style="font-size:.62rem"><?= htmlspecialchars((string)$ru) ?></span>
                                                    <?php endforeach; ?>
                                                    <?php if (count($reuses) > 6): ?><span class="text-muted" style="font-size:.62rem">+<?= count($reuses) - 6 ?></span><?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($task->instanceTag)): ?>
                                                <a href="/workbench?instance_tag=<?= urlencode($task->instanceTag) ?>" class="badge bg-info-subtle text-info-emphasis border border-info-subtle text-decoration-none" title="Filter to this instance">
                                                    <i class="bi bi-hdd-network me-1"></i><?= htmlspecialchars(($task->instanceTag) ?? '') ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $typeInfo['color'] ?>-subtle text-<?= $typeInfo['color'] ?>">
                                                <i class="bi bi-<?= $typeInfo['icon'] ?>"></i> <?= $typeInfo['label'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $priorityInfo['color'] ?>-subtle text-<?= $priorityInfo['color'] ?>">
                                                <?= $priorityInfo['label'] ?>
                                            </span>
                                        </td>
                                        <td data-order="<?= htmlspecialchars(((string)$task->status) ?? '') ?>">
                                            <?php
                                            $statusBadge = match($task->status) {
                                                'pending' => 'secondary',
                                                'queued' => 'info',
                                                'running' => 'primary',
                                                'completed' => 'success',
                                                'merged' => 'success',
                                                // Terminal and fine, but not the same as
                                                // merged: nothing changed because nothing
                                                // needed to. Distinct colour so a reader
                                                // can tell "done" from "already done".
                                                'resolved' => 'info',
                                                'failed' => 'danger',
                                                'paused' => 'warning',
                                                'awaiting', 'waiting' => 'warning',
                                                default => 'secondary'
                                            };
                                            ?>
                                            <span class="badge bg-<?= $statusBadge ?>">
                                                <?php if ($task->status === 'running'): ?>
                                                    <span class="spinner-border spinner-border-sm me-1" role="status"></span>
                                                <?php elseif ($task->status === 'merged'): ?>
                                                    <i class="bi bi-git me-1"></i>
                                                <?php endif; ?>
                                                <?= ucfirst($task->status) ?>
                                            </span>
                                        </td>
                                        <td data-order="<?= htmlspecialchars(((string)$task->createdAt) ?? '') ?>">
                                            <small class="text-muted"><?= date('M j', strtotime($task->createdAt)) ?></small>
                                        </td>
                                        <td>
                                            <a href="/workbench/view?id=<?= $task->id ?>" class="btn btn-sm btn-outline-primary">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <script>
                (function(){
                    var $;   // bound once jQuery is available (this layout loads jQuery after the view)
                    var PLAN_META = <?= json_encode($planMetaJs, JSON_UNESCAPED_SLASHES) ?>;
                    var DT_ASSETS = [
                        'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',
                        'https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js',
                        'https://cdn.datatables.net/rowgroup/1.4.1/js/dataTables.rowGroup.min.js'
                    ];
                    function esc(t){ return $('<div>').text(t == null ? '' : t).html(); }
                    // Lifecycle action buttons for a plan group header, keyed on plan_status.
                    function planActions(m){
                        if (!m.id) return '';   // solo group is not a plan
                        var ps = String(m.plan_status || '').toLowerCase();
                        var btn = function(cls, extra, icon, label){
                            return '<button type="button" class="btn btn-sm '+cls+' '+extra+' ms-1" data-plan-id="'+m.id+'">'
                                 + '<i class="bi bi-'+icon+'"></i>' + (label ? ' '+label : '') + '</button>';
                        };
                        var out = '';
                        // Straight-through plans skipped the Approve/Build clicks, so mark
                        // them: "building" alone reads as something you started.
                        if (m.auto_build) out += '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle ms-1"'
                                              + ' title="Approved and started automatically — &quot;Run it straight through&quot; was ticked when this was decomposed">'
                                              + '<i class="bi bi-lightning-charge-fill"></i> Auto</span>';
                        if (ps === 'draft')                             out += btn('btn-outline-info',   'wb-plan-approve', 'check2-circle', 'Approve');
                        else if (ps === 'approved' || ps === 'stalled') out += btn('btn-info',           'wb-plan-build',   'play-fill',     'Build');
                        else if (ps === 'building')                     out += '<span class="badge bg-primary ms-1"><span class="spinner-border spinner-border-sm me-1" role="status"></span>Building</span>';
                        if (ps !== 'building')                          out += btn('btn-outline-danger',  'wb-plan-delete',  'trash',         '');
                        return '<span class="float-end">'+out+'</span>';
                    }
                    function statusBadge(s){
                        if (!s) return '';
                        var map = {done:'success', completed:'success', merged:'success',
                            building:'primary', running:'primary', approved:'info',
                            draft:'secondary', pending:'secondary', stalled:'danger', failed:'danger'};
                        var cls = map[String(s).toLowerCase()] || 'secondary';
                        return ' <span class="badge bg-'+cls+' ms-1">'+esc(String(s).charAt(0).toUpperCase()+String(s).slice(1))+'</span>';
                    }
                    function loadSeq(list, done){
                        if (!list.length) return done();
                        var s = document.createElement('script');
                        s.src = list[0];
                        s.onload = function(){ loadSeq(list.slice(1), done); };
                        s.onerror = function(){ console.error('DataTables asset failed to load:', list[0]); };
                        document.head.appendChild(s);
                    }
                    function initTable(){
                        if (!$.fn || !$.fn.DataTable) return;   // graceful no-op if a CDN asset is unreachable
                        $('#wbTasks').DataTable({
                            pageLength: 25,
                            lengthMenu: [[10,25,50,-1],[10,25,50,'All']],
                            orderFixed: { pre: [[0,'asc']] },    // group key carries an inverted-ts prefix, so asc = newest group first
                            order: [[6,'desc']],                  // and rows newest-first within each group
                            columnDefs: [
                                { targets: 0, visible: false, searchable: false },
                                { targets: -1, orderable: false, searchable: false }
                            ],
                            rowGroup: {
                                dataSrc: 0,
                                startRender: function(rows, group){
                                    var key = String(group).split('~').pop();   // strip the inverted-ts sort prefix
                                    var m = PLAN_META[key] || { title: key };
                                    var n = rows.count();
                                    var icon = m.url ? '<i class="bi bi-diagram-3 me-2 text-primary"></i>'
                                                     : '<i class="bi bi-collection me-2 text-muted"></i>';
                                    var title = m.url
                                        ? '<a href="'+m.url+'" class="text-decoration-none fw-semibold">'+esc(m.title)+'</a>'
                                        : '<span class="fw-semibold">'+esc(m.title)+'</span>';
                                    var tag = m.tag ? ' <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-2"><i class="bi bi-hdd-network me-1"></i>'+esc(m.tag)+'</span>' : '';
                                    var count = ' <span class="text-muted small ms-2">'+n+' task'+(n===1?'':'s')+'</span>';
                                    // Plan-level consolidate checkbox — selects every pending task in the group at once.
                                    var groupCb = (m.taskIds && m.taskIds.length)
                                        ? '<input type="checkbox" class="form-check-input wb-consol-group me-2 align-middle" data-ids="'+m.taskIds.join(',')+'" title="Select all '+m.taskIds.length+' pending task(s) in this plan to consolidate">'
                                        : '';
                                    return $('<tr class="table-active">').append(
                                        '<td colspan="7">'+groupCb+planActions(m)+icon+title+tag+statusBadge(m.status)+count+'</td>'
                                    );
                                }
                            },
                            language: { search: 'Filter:', searchPlaceholder: 'title, instance, status…' }
                        });
                        bindPlanActions();
                    }
                    // POST a plan lifecycle action, then refresh so the new state shows.
                    function planAction(url, btn, confirmMsg){
                        var id = btn.getAttribute('data-plan-id');
                        if (!id) return;
                        if (confirmMsg && !window.confirm(confirmMsg)) return;
                        btn.disabled = true;
                        fetch(url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-CSRF-TOKEN': window.WB_CSRF || '',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: 'plan_id=' + encodeURIComponent(id) + '&_csrf_token=' + encodeURIComponent(window.WB_CSRF || '')
                        }).then(function(r){ return r.json(); })
                          .then(function(j){
                              if (j && j.success === false) { window.alert(j.message || 'Action failed'); btn.disabled = false; return; }
                              window.location.reload();
                          })
                          .catch(function(){ window.alert('Network error'); btn.disabled = false; });
                    }
                    // Delegated so the buttons survive DataTables redraws (sort/search/paginate).
                    function bindPlanActions(){
                        $('#wbTasks').off('click.wbplan')
                            .on('click.wbplan', '.wb-plan-approve', function(){ planAction('/workbench/planapprove', this); })
                            .on('click.wbplan', '.wb-plan-build',   function(){ planAction('/workbench/planbuild', this); })
                            .on('click.wbplan', '.wb-plan-delete',  function(){ planAction('/workbench/plandelete', this, 'Delete this entire plan and all of its tasks? This cannot be undone.'); });
                    }
                    // jQuery is loaded near the end of <body> (after this view), so wait for it,
                    // then load DataTables + RowGroup in order and initialise.
                    (function waitJQ(n){
                        if (window.jQuery){ $ = window.jQuery; loadSeq(DT_ASSETS, initTable); }
                        else if (n < 200){ setTimeout(function(){ waitJQ(n + 1); }, 50); }   // ~10s cap
                    })(0);
                })();
                </script>

                <!-- Consolidate: multi-select pending tasks -> merged draft plan -->
                <script>
                (function(){
                    var selected = new Set();
                    var bar, countEl, btn, cancel;
                    var BTN_HTML = '<i class="bi bi-union me-1"></i>Consolidate into one';
                    function refresh(){
                        if (!bar) return;
                        countEl.textContent = selected.size;
                        bar.style.display = selected.size > 0 ? 'flex' : 'none';
                        btn.disabled = selected.size < 2;
                    }
                    // Re-apply checkbox state after DataTables recreates rows on sort/search/paginate.
                    // Plan-header checkboxes reflect "all my pending tasks are selected".
                    function applyChecks(){
                        document.querySelectorAll('#wbTasks .wb-consol').forEach(function(cb){
                            cb.checked = selected.has(cb.value);
                        });
                        document.querySelectorAll('#wbTasks .wb-consol-group').forEach(function(g){
                            var ids = (g.getAttribute('data-ids') || '').split(',').filter(Boolean);
                            g.checked = ids.length > 0 && ids.every(function(id){ return selected.has(id); });
                        });
                    }
                    document.addEventListener('change', function(e){
                        var t = e.target;
                        if (!t || !t.closest) return;
                        var grp = t.closest('.wb-consol-group');
                        if (grp) {
                            (grp.getAttribute('data-ids') || '').split(',').filter(Boolean).forEach(function(id){
                                if (grp.checked) selected.add(id); else selected.delete(id);
                            });
                            applyChecks(); refresh(); return;
                        }
                        var cb = t.closest('.wb-consol');
                        if (!cb) return;
                        if (cb.checked) selected.add(cb.value); else selected.delete(cb.value);
                        applyChecks(); refresh();   // keep the plan-header checkbox in sync
                    });
                    var tbl = document.getElementById('wbTasks');
                    if (tbl && window.MutationObserver) {
                        new MutationObserver(function(){ applyChecks(); }).observe(tbl, { childList: true, subtree: true });
                    }
                    function doConsolidate(){
                        if (selected.size < 2) return;
                        if (!window.confirm('Consolidate ' + selected.size + ' tasks into one deduplicated plan? The originals are replaced once the merged plan is ready.')) return;
                        btn.disabled = true;
                        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Consolidating…';
                        fetch('/workbench/consolidate', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-CSRF-TOKEN': window.WB_CSRF || '',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: 'task_ids=' + encodeURIComponent(Array.from(selected).join(',')) + '&_csrf_token=' + encodeURIComponent(window.WB_CSRF || '')
                        }).then(function(r){ return r.json(); }).then(function(j){
                            if (j && j.success) {
                                // The board is the selected project's board; no need to
                                // tell it which instance it is already on.
                                window.location = '/workbench?decomposing=1';
                            } else {
                                window.alert((j && j.message) || 'Consolidation failed');
                                btn.disabled = false; btn.innerHTML = BTN_HTML;
                            }
                        }).catch(function(){ window.alert('Network error'); btn.disabled = false; btn.innerHTML = BTN_HTML; });
                    }
                    document.addEventListener('DOMContentLoaded', function(){
                        bar = document.getElementById('wbConsolBar');
                        countEl = document.getElementById('wbConsolCount');
                        btn = document.getElementById('wbConsolBtn');
                        cancel = document.getElementById('wbConsolCancel');
                        if (cancel) cancel.addEventListener('click', function(){ selected.clear(); applyChecks(); refresh(); });
                        if (btn) btn.addEventListener('click', doConsolidate);
                        refresh();
                    });
                })();
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>
