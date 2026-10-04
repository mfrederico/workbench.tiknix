<?php include __DIR__ . '/_form_css.php'; ?>
<style>
/* What the form says follows the two cards, with no script: planning or one task, and
   whether it runs straight through. */
#wbForm:has(#plan:checked) .wb-if-task, #wbForm:not(:has(#plan:checked)) .wb-if-plan { display: none !important; }
#wbForm:has(#auto_build:checked) .wb-if-ask, #wbForm:not(:has(#auto_build:checked)) .wb-if-straight { display: none !important; }
</style>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="mb-4">
                <a href="/workbench" class="text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Back to Task Board
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
                    <h4 class="mb-0">What are we building?</h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($recentPrompts)):
                        /* Earlier goals first, because the common reason for opening this page
                           is picking up something you already asked for. Split by the only fact
                           that distinguishes them: a plan_uid means a plan was produced; its
                           absence means the goal never became one, whatever the reason. */
                        $built = $unrun = [];
                        foreach ($recentPrompts as $pr) {
                            if (!empty($pr['plan_uid'])) { $built[] = $pr; } else { $unrun[] = $pr; }
                        }
                    ?>
                    <ul class="nav nav-tabs small mb-0" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $unrun ? 'active' : '' ?>" data-bs-toggle="tab"
                                    data-bs-target="#wbGoalsUnrun" type="button" role="tab">
                                Not built yet <span class="badge bg-secondary ms-1"><?= count($unrun) ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $unrun ? '' : 'active' ?>" data-bs-toggle="tab"
                                    data-bs-target="#wbGoalsBuilt" type="button" role="tab">
                                Already planned <span class="badge bg-secondary ms-1"><?= count($built) ?></span>
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content border border-top-0 rounded-bottom p-2 mb-4">
                        <?php foreach ([['wbGoalsUnrun', $unrun, (bool)$unrun], ['wbGoalsBuilt', $built, !$unrun]] as [$paneId, $rows, $isActive]): ?>
                        <div class="tab-pane fade <?= $isActive ? 'show active' : '' ?>" id="<?= $paneId ?>" role="tabpanel">
                            <?php if (!$rows): ?>
                                <div class="text-body-secondary small py-2">Nothing here yet.</div>
                            <?php else: ?>
                            <div class="list-group list-group-flush small">
                                <?php foreach ($rows as $pr): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-start gap-3 px-0 py-2">
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold"><?= htmlspecialchars((string)($pr['title'] ?? '(untitled)')) ?></div>
                                        <div class="text-body-secondary">
                                            <?= htmlspecialchars(substr((string)($pr['created_at'] ?? ''), 0, 16)) ?>
                                            <?php if (!empty($pr['last_error'])): ?>
                                                — <span class="text-danger"><?= htmlspecialchars(substr((string)$pr['last_error'], 0, 90)) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <a class="btn btn-outline-secondary btn-sm text-nowrap"
                                       href="/workbench/create?prompt=<?= (int)$pr['id'] ?>">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reuse
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="/workbench/store" id="wbForm">
                        <?php foreach ($csrf as $name => $value): ?>
                            <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                        <?php endforeach; ?>
                        <?php /* Noted with the submit (app\ToolUse): which parts of this form get used. */ ?>
                        <input type="hidden" name="from_prompt" value="<?= (int) $prefill['prompt_id'] ?>">
                        <input type="hidden" name="md_file" id="md_file" value="">
                        <input type="hidden" name="more_opened" id="more_opened" value="">
                        <!-- Base Branch — always main for now; the picker is hidden. -->
                        <input type="hidden" id="base_branch" name="base_branch" value="main">

                        <?php
                        /* Which project this is for is NOT a question this form asks.
                           It is the project you chose in Projects and that the shell has
                           been naming all along; a chooser here could disagree with it,
                           and the one that disagreed would silently win. Shown, not
                           offered — with the way to change it being the way you set it. */
                        ?>
                        <div class="mb-3">
                            <label class="form-label">Project</label>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <span class="badge text-bg-primary-subtle text-primary-emphasis border border-primary-subtle py-2 px-3">
                                    <i class="bi bi-hdd-network-fill me-1"></i>
                                    <?php if (!empty($instance['name'])): ?>
                                        <?= htmlspecialchars($instance['name']) ?>
                                        <span class="text-body-secondary">— <?= htmlspecialchars($instance['tag']) ?></span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($instance['tag']) ?>
                                    <?php endif; ?>
                                </span>
                                <a href="<?= htmlspecialchars($projectPickerUrl) ?>" target="_top" class="small">Work on a different project</a>
                            </div>
                            <div class="form-text">Everything below gets built into this project.</div>
                            <?php /* Rendered whenever ANY offered engine lacks credentials, then shown
                                     or hidden by the picker — the warning is about the engine you
                                     SELECTED, not the project's default. It nagged about claude while
                                     z.ai was picked and working. Server-rendered visible only when the
                                     default itself is unusable, so it is right before any JS runs. */ ?>
                            <?php if (!isset($appAgents) && !isset($appAgentsError) && !empty($engineAuth) && in_array(false, $engineAuth, true)): ?>
                                <div id="wb-engine-warning"
                                     class="alert alert-warning mt-2 mb-0 py-2 small<?= !empty($agentSignedIn) ? ' d-none' : '' ?>"
                                     data-auth='<?= htmlspecialchars(json_encode($engineAuth), ENT_QUOTES) ?>'
                                     data-labels='<?= htmlspecialchars(json_encode($engineLabels ?? []), ENT_QUOTES) ?>'>
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    <strong>You have no credentials for
                                    <span id="wb-engine-name"><?= htmlspecialchars($engineLabels[$agentSignedInEngine] ?? ($agentSignedInEngine ?? '')) ?></span>.</strong>
                                    Agents run on YOUR credentials, not the project's, so either pick an
                                    engine below that you are signed in to, open the
                                    <a href="/aibuilder">Terminal</a> and run <code>/login</code> there,
                                    or add that engine's API key in Settings.
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Title -->
                        <div class="mb-3">
                            <label for="title" class="form-label">Give it a name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="title" name="title" required
                                   value="<?= htmlspecialchars($prefill['title'] ?? '') ?>"
                                   placeholder="e.g. Loyalty cards for regulars">
                        </div>

                        <!-- Description -->
                        <div class="mb-2">
                            <label for="description" class="form-label">What do you want built?</label>
                            <textarea class="form-control" id="description" name="description" rows="5"
                                      placeholder="Say it the way you'd explain it to a teammate: what it does, who uses it, anything it must not break."><?= htmlspecialchars($prefill['body'] ?? '') ?></textarea>
                            <div class="form-text">Plain words are fine. The more you say here, the less the agent has to guess.</div>
                        </div>

                        <!-- Markdown import (drag & drop) -->
                        <div class="mb-4">
                            <div id="mdDrop" class="border rounded px-3 py-2 text-body-secondary small" style="border-style:dashed !important; cursor:pointer;">
                                <i class="bi bi-filetype-md me-1"></i>
                                <span id="mdDropText">Already wrote it up? Drop a <code>.md</code> file here (or click) and we'll fill in the name and description.</span>
                                <input type="file" id="mdFile" accept=".md,.markdown,text/markdown,text/plain" hidden>
                            </div>
                        </div>

                        <?php
                        $briefAudience   = (string) ($prefill['audience'] ?? '');
                        $briefAcceptance = (string) ($prefill['acceptance'] ?? '');
                        $briefNoneLabel  = ['No new pages', "it's a fix or a change to something that already exists."];
                        include __DIR__ . '/_brief_fields.php'; ?>

                        <?php
                        $builderAgent = '';
                        $builderRun   = (string) ($defaultRunChoice ?? '');
                        include __DIR__ . '/_who_builds.php'; ?>

                        <?php /* HOW TO BUILD IT — two cards of the same kind.

                                 Plan it first is ON by default: thinking it through is the normal
                                 way to build, and one loose task is the exception you choose.
                                 Straight-through is deliberately OFF on every load: it is a
                                 per-submission choice, not a preference, because it commits agent
                                 work without anyone reading the plan first. */
                        $__proj = htmlspecialchars((string) ($instance['name'] ?: $instance['tag'])); ?>
                        <div class="form-label">How should we go about it?</div>
                        <label class="wb-card d-flex gap-3 mb-2 p-3 border rounded bg-body-tertiary">
                            <input class="form-check-input flex-shrink-0 mt-1" type="checkbox" value="1"
                                   id="plan" name="plan" <?= !empty($prefill['plan']) ? 'checked' : '' ?>>
                            <span>
                                <span class="wb-card-title"><i class="bi bi-diagram-3 me-1"></i>Plan it first</span>
                                <span class="text-body-secondary">&mdash; recommended</span>
                                <span class="form-text d-block mb-0 wb-if-plan">
                                    A planner reads your project first, then breaks this into small, named tasks:
                                    what it will reuse, what it will add, and in what order. You get to read that
                                    plan <strong>before a line of code is written</strong> &mdash; it's the cheapest
                                    moment to say "no, not like that". Small tasks also build side by side and are
                                    each checked on their own, so one wrong turn doesn't sink the whole thing.
                                </span>
                                <span class="d-block mt-2 wb-if-plan">
                                    <label for="planning_depth" class="form-label small mb-1">How deep should the planning go?</label>
                                    <select class="form-select form-select-sm" id="planning_depth" name="planning_depth" style="max-width:34rem">
                                        <option value="flagged" selected>Deepen what needs it — tasks the planner finds are really several get split, then the order is re-checked (recommended)</option>
                                        <option value="always">Always go deeper — every task is re-examined and the order re-checked (about three times the planning time)</option>
                                        <option value="off">One pass — the first plan, as written (fastest)</option>
                                    </select>
                                </span>
                                <span class="form-text d-block mb-0 wb-if-task">
                                    Off: this goes to one agent as a single task, no plan. Good for a quick,
                                    obvious change (fix a typo, change a colour). For anything with more than
                                    one moving part, tick this &mdash; you'll thank yourself.
                                </span>
                            </span>
                        </label>

                        <label class="wb-card wb-go d-flex gap-3 mb-3 p-3 border rounded bg-body-tertiary">
                            <input class="form-check-input flex-shrink-0 mt-1" type="checkbox" value="1"
                                   id="auto_build" name="auto_build">
                            <span>
                                <span class="wb-card-title"><i class="bi bi-fast-forward me-1"></i>Run it straight through</span>
                                <span class="text-body-secondary">&mdash; don't stop to ask me</span>
                                <span class="form-text d-block mb-0 wb-if-plan">
                                    The plan approves itself the moment it's ready and the build starts. Each task
                                    merges into <strong><?= $__proj ?></strong> as it passes &mdash; so code lands
                                    without anyone having read the plan. Handy when you trust the goal; leave it
                                    off when you'd like a look first.
                                </span>
                                <span class="form-text d-block mb-0 wb-if-task">
                                    The agent starts the moment the task is saved, instead of waiting for you to
                                    press Run. Its work still waits for you to approve the merge.
                                </span>
                            </span>
                        </label>

                        <?php /* Extras that only a single task reads — a plan's tasks get theirs from the
                                 planner. Folded away, and their use is counted (app\ToolUse) so the ones
                                 nobody opens can be removed. */ ?>
                        <details class="wb-if-task mb-3" id="wbMore">
                            <summary class="small text-body-secondary">More options for a single task</summary>
                            <div class="border rounded p-3 mt-2">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="task_type" class="form-label">Kind of work</label>
                                        <select class="form-select" id="task_type" name="task_type">
                                            <?php foreach ($taskTypes as $type => $info): ?>
                                                <option value="<?= $type ?>"><?= $info['label'] ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="priority" class="form-label">Priority</label>
                                        <select class="form-select" id="priority" name="priority">
                                            <?php foreach ($priorities as $level => $info): ?>
                                                <option value="<?= $level ?>" <?= $level === 3 ? 'selected' : '' ?>><?= $info['label'] ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="db_source" class="form-label">Data to test with</label>
                                        <select class="form-select" id="db_source" name="db_source">
                                            <option value="live" selected>A copy of the real data</option>
                                            <option value="fresh">An empty database</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-text mt-0 mb-3">A copy of the real data lets the agent test against the site as it is; it never merges back. Choose the empty database if the data is private.</div>
                                <div class="mb-3">
                                    <label for="related_files" class="form-label">Files to start from</label>
                                    <textarea class="form-control font-monospace" id="related_files" name="related_files" rows="2"
                                              placeholder="controls/Cafe.php&#10;views/cafe/menu.php"></textarea>
                                    <div class="form-text">If you already know where the change goes, one path per line.</div>
                                </div>
                                <div>
                                    <label for="tags" class="form-label">Tags</label>
                                    <input type="text" class="form-control" id="tags" name="tags" placeholder="menu, checkout">
                                    <div class="form-text">Comma-separated, for finding it on the board later.</div>
                                </div>
                            </div>
                        </details>

                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <button type="submit" class="btn btn-primary btn-lg px-4" id="wbGo">
                                <span class="wb-if-plan"><i class="bi bi-diagram-3 me-1"></i><span class="wb-if-ask">Plan it</span><span class="wb-if-straight">Plan it and build it</span></span>
                                <span class="wb-if-task"><i class="bi bi-play-fill me-1"></i><span class="wb-if-ask">Create the task</span><span class="wb-if-straight">Create the task and start it</span></span>
                            </button>
                            <a href="/workbench" class="btn btn-outline-secondary">Cancel</a>
                            <span class="form-text mb-0 mt-0">
                                <span class="wb-if-plan"><span class="wb-if-ask">Next: a plan to read. Nothing is built until you approve it.</span><span class="wb-if-straight">Next: the plan, then the build, without stopping.</span></span>
                                <span class="wb-if-task"><span class="wb-if-ask">Next: one task on the board, waiting for you to press Run.</span><span class="wb-if-straight">Next: one agent, starting right away.</span></span>
                            </span>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var drop  = document.getElementById('mdDrop'),
        input = document.getElementById('mdFile'),
        txt   = document.getElementById('mdDropText');
    if (!drop || !input) return;
    function ingest(file){
        if (!file) return;
        if (!/\.(md|markdown|txt)$/i.test(file.name)) { txt.textContent = 'Please choose a .md file.'; return; }
        var reader = new FileReader();
        reader.onload = function(e){
            var content = String(e.target.result || '');
            var desc  = document.getElementById('description');
            var title = document.getElementById('title');
            if (desc) desc.value = content;
            if (title && !title.value.trim()){
                var m = content.match(/^\s*#\s+(.+?)\s*$/m);   // first "# Heading" -> title
                title.value = (m ? m[1] : file.name.replace(/\.(md|markdown|txt)$/i, '')).slice(0, 255);
            }
            var name = file.name.replace(/[<>&"]/g, '');
            txt.innerHTML = 'Loaded <strong>' + name + '</strong> (' + content.length + ' characters). Have a read through above, then go.';
            var used = document.getElementById('md_file'); if (used) used.value = '1';
        };
        reader.readAsText(file);
    }
    drop.addEventListener('click', function(){ input.click(); });
    input.addEventListener('change', function(){ ingest(input.files[0]); });
    ['dragenter','dragover'].forEach(function(ev){
        drop.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); drop.classList.add('border-primary','text-primary'); });
    });
    ['dragleave','drop'].forEach(function(ev){
        drop.addEventListener(ev, function(e){ e.preventDefault(); e.stopPropagation(); drop.classList.remove('border-primary','text-primary'); });
    });
    drop.addEventListener('drop', function(e){
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) ingest(e.dataTransfer.files[0]);
    });
})();
</script>
<script>
/* Keep the credentials warning pointed at the engine actually selected.
   The picker is client-side, so a server-rendered notice froze on the project default and
   kept warning about an engine the member had already switched away from. */
(function () {
  const box  = document.getElementById('wb-engine-warning');
  const pick = document.getElementById('run_with');
  if (!box || !pick) return;
  const auth   = JSON.parse(box.dataset.auth   || '{}');
  const labels = JSON.parse(box.dataset.labels || '{}');
  const name   = document.getElementById('wb-engine-name');
  const sync = () => {
    // run_with is "engine:model"; the engine is what credentials attach to.
    const engine = String(pick.value || '').split(':')[0];
    const ok = auth[engine] !== false;          // unknown engine: do not invent a problem
    box.classList.toggle('d-none', ok);
    if (!ok && name) name.textContent = labels[engine] || engine;
  };
  pick.addEventListener('change', sync);
  sync();
})();
</script>
<script>
/* Counted with the submit (app\ToolUse): were the single-task extras even opened? */
(function () {
  var more = document.getElementById('wbMore'), flag = document.getElementById('more_opened');
  if (more && flag) more.addEventListener('toggle', function () { if (more.open) flag.value = '1'; });
})();
</script>
