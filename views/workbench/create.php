<style>
/* The straight-through opt-in: the whole panel is the control.
   Colours come from Bootstrap 5.3's subtle/emphasis variables rather than fixed
   hex, so the checked state reads correctly in both the light and dark themes
   instead of being a green that only works in one of them. */
.wb-straight {
    cursor: pointer;
    transition: background-color .15s ease-in-out, border-color .15s ease-in-out;
}
.wb-straight:hover { border-color: var(--bs-secondary-border-subtle); }

/* :has() lets the panel react to its own checkbox with no JS to fall out of sync
   with the input's real state. */
.wb-straight:has(#auto_build:checked) {
    background-color: var(--bs-success-bg-subtle) !important;
    border-color: var(--bs-success-border-subtle) !important;
}
.wb-straight:has(#auto_build:checked) .wb-straight-title { color: var(--bs-success-text-emphasis); }

/* Match the tick to the panel — the Bootstrap default is primary blue, which
   would be the one blue thing inside a green box. */
.wb-straight .form-check-input:checked {
    background-color: var(--bs-success);
    border-color: var(--bs-success);
}
/* Keyboard users get the focus ring on the PANEL, since that is what now reads as
   the control; the input's own ring would be a 16px halo inside a 700px target. */
.wb-straight:has(#auto_build:focus-visible) {
    box-shadow: 0 0 0 .25rem rgba(var(--bs-success-rgb), .25);
    border-color: var(--bs-success-border-subtle);
}
.wb-straight .form-check-input:focus { box-shadow: none; }
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
                    <h4 class="mb-0">Create Task</h4>
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
                                Never ran <span class="badge bg-secondary ms-1"><?= count($unrun) ?></span>
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= $unrun ? '' : 'active' ?>" data-bs-toggle="tab"
                                    data-bs-target="#wbGoalsBuilt" type="button" role="tab">
                                Produced a plan <span class="badge bg-secondary ms-1"><?= count($built) ?></span>
                            </button>
                        </li>
                    </ul>
                    <div class="tab-content border border-top-0 rounded-bottom p-2 mb-4">
                        <?php foreach ([['wbGoalsUnrun', $unrun, (bool)$unrun], ['wbGoalsBuilt', $built, !$unrun]] as [$paneId, $rows, $isActive]): ?>
                        <div class="tab-pane fade <?= $isActive ? 'show active' : '' ?>" id="<?= $paneId ?>" role="tabpanel">
                            <?php if (!$rows): ?>
                                <div class="text-body-secondary small py-2">Nothing here.</div>
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

                    <form method="POST" action="/workbench/store">
                        <?php foreach ($csrf as $name => $value): ?>
                            <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                        <?php endforeach; ?>

                        <?php
                        /* Which project this task is for is NOT a question this form asks.
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
                            <div class="form-text">This task will be built against the project you are working on.</div>
                            <?php /* Rendered whenever ANY offered engine lacks credentials, then shown
                                     or hidden by the picker — the warning is about the engine you
                                     SELECTED, not the project's default. It nagged about claude while
                                     z.ai was picked and working. Server-rendered visible only when the
                                     default itself is unusable, so it is right before any JS runs. */ ?>
                            <?php if (!empty($engineAuth) && in_array(false, $engineAuth, true)): ?>
                                <div id="wb-engine-warning"
                                     class="alert alert-warning mt-2 mb-0 py-2 small<?= !empty($agentSignedIn) ? ' d-none' : '' ?>"
                                     data-auth='<?= htmlspecialchars(json_encode($engineAuth), ENT_QUOTES) ?>'
                                     data-labels='<?= htmlspecialchars(json_encode($engineLabels ?? []), ENT_QUOTES) ?>'>
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    <strong>You have no credentials for
                                    <span id="wb-engine-name"><?= htmlspecialchars($engineLabels[$agentSignedInEngine] ?? ($agentSignedInEngine ?? '')) ?></span>.</strong>
                                    Agents run on YOUR credentials, not the project's, so either pick an
                                    engine above that you are signed in to, open the
                                    <a href="/aibuilder">Terminal</a> and run <code>/login</code> there,
                                    or add that engine's API key in Settings.
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Title -->
                        <div class="mb-3">
                            <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="title" name="title" required
                                   value="<?= htmlspecialchars($prefill['title'] ?? '') ?>"
                                   placeholder="Describe what needs to be done">
                        </div>

                        <!-- Markdown import (drag & drop) -->
                        <div class="mb-3">
                            <label class="form-label">Start from a Markdown file <span class="text-muted">(optional)</span></label>
                            <div id="mdDrop" class="border border-2 rounded p-4 text-center text-muted" style="border-style:dashed !important; cursor:pointer;">
                                <i class="bi bi-filetype-md fs-2 d-block mb-2"></i>
                                <span id="mdDropText">Drag &amp; drop a <code>.md</code> file here, or click to browse</span>
                                <input type="file" id="mdFile" accept=".md,.markdown,text/markdown,text/plain" hidden>
                            </div>
                            <div class="form-text">Loads the file into Title &amp; Description below. Nothing is uploaded until you click Create.</div>
                        </div>

                        <!-- Description -->
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="4"
                                      placeholder="Provide detailed context for the agent..."><?= htmlspecialchars($prefill['body'] ?? '') ?></textarea>
                            <div class="form-text">Be specific about what you want. Include relevant code paths, requirements, and constraints.</div>
                        </div>

                        <div class="row">
                            <?php /* Which agent builds this — ONE choice, not two.
                                     An engine list plus a model list lets someone pick z.ai with
                                     opus: syntactically fine, meaningless to the provider, and it
                                     fails at run time as an unhelpful API error. The pair is the
                                     unit, and EngineRegistry::runMenu() only offers pairs that
                                     exist on an engine that is actually available. */ ?>
                            <?php if (!empty($runChoices)): ?>
                            <div class="col-md-4 mb-3">
                                <label for="run_with" class="form-label">Run with</label>
                                <select class="form-select" id="run_with" name="run_with">
                                    <?php foreach ($runChoices as $c):
                                        /* Marked, not hidden. A member who has no credentials for an
                                           engine still needs to see it exists — hiding it makes the
                                           menu look like the engine was never offered. */
                                        $usable = ($engineAuth[$c['engine']] ?? true); ?>
                                        <option value="<?= htmlspecialchars($c['value']) ?>"
                                            <?= ($c['value'] === ($defaultRunChoice ?? '')) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['label']) ?><?= $usable ? '' : ' — no credentials' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text small">
                                    Defaults to this project's engine. Runs on YOUR credentials for the
                                    engine you pick, so a choice marked <em>no credentials</em> cannot run
                                    until you sign in to it (or add its API key in Settings).
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Task Type -->
                            <div class="col-md-4 mb-3">
                                <label for="task_type" class="form-label">Type</label>
                                <select class="form-select" id="task_type" name="task_type">
                                    <?php foreach ($taskTypes as $type => $info): ?>
                                        <option value="<?= $type ?>">
                                            <?= $info['label'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Priority -->
                            <div class="col-md-4 mb-3">
                                <label for="priority" class="form-label">Priority</label>
                                <select class="form-select" id="priority" name="priority">
                                    <?php foreach ($priorities as $level => $info): ?>
                                        <option value="<?= $level ?>" <?= $level === 3 ? 'selected' : '' ?>>
                                            <?= $info['label'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Team -->
                            <div class="col-md-4 mb-3">
                                <label for="team_id" class="form-label">Team</label>
                                <select class="form-select" id="team_id" name="team_id">
                                    <option value="personal">Personal Task</option>
                                    <?php foreach ($teams as $team): ?>
                                        <option value="<?= $team['id'] ?>" <?= $preselectedTeamId == $team['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(($team['name']) ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <!-- Base Branch -->
                            <div class="col-md-6 mb-3">
                                <label for="base_branch" class="form-label">Base Branch</label>
                                <select class="form-select" id="base_branch" name="base_branch">
                                    <?php foreach ($branches ?? ['main'] as $branch): ?>
                                        <option value="<?= htmlspecialchars(($branch) ?? '') ?>" <?= $branch === ($currentBranch ?? 'main') ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(($branch) ?? '') ?>
                                            <?= $branch === ($currentBranch ?? 'main') ? '(current)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Branch to create this task from. PR will merge back into this branch. Only pushed branches are shown.</div>
                            </div>

                            <!-- Test-DB source -->
                            <div class="col-md-6 mb-3">
                                <label for="db_source" class="form-label">Test data</label>
                                <select class="form-select" id="db_source" name="db_source">
                                    <option value="live" selected>Copy the instance's real data (higher-fidelity testing)</option>
                                    <option value="fresh">Fresh empty database (keeps customer data out of the agent)</option>
                                </select>
                                <div class="form-text">The test workspace's database. "Real data" copies the instance's live DB so the agent sees the actual site; it's a copy and never merges back. Choose "fresh" for privacy-sensitive instances.</div>
                            </div>

                            <!-- Authcontrol Level -->
                            <div class="col-md-6 mb-3">
                                <label for="authcontrol_level" class="form-label">Endpoint Access Level</label>
                                <select class="form-select" id="authcontrol_level" name="authcontrol_level">
                                    <?php foreach ($authcontrolLevels as $level => $info): ?>
                                        <option value="<?= $level ?>" <?= $level == $memberLevel ? 'selected' : '' ?>>
                                            <?= $info['label'] ?> (<?= $level ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Access level for new endpoints created by this task. Lower number = higher privilege.</div>
                            </div>
                        </div>

                        <!-- Acceptance Criteria -->
                        <div class="mb-3">
                            <label for="acceptance_criteria" class="form-label">Acceptance Criteria</label>
                            <textarea class="form-control" id="acceptance_criteria" name="acceptance_criteria" rows="3"
                                      placeholder="What conditions must be met for this task to be complete?"></textarea>
                            <div class="form-text">Claude will use these to verify the work is done correctly.</div>
                        </div>

                        <!-- Related Files -->
                        <div class="mb-3">
                            <label for="related_files" class="form-label">Related Files</label>
                            <textarea class="form-control font-monospace" id="related_files" name="related_files" rows="3"
                                      placeholder="src/controllers/UserController.php&#10;tests/UserTest.php"></textarea>
                            <div class="form-text">One file path per line. These will be prioritized during analysis.</div>
                        </div>

                        <!-- Tags -->
                        <div class="mb-4">
                            <label for="tags" class="form-label">Tags</label>
                            <input type="text" class="form-control" id="tags" name="tags"
                                   placeholder="api, authentication, backend">
                            <div class="form-text">Comma-separated tags for organization.</div>
                        </div>

                        <!-- Straight-through. Deliberately unchecked on every load: it is a
                             per-submission choice, not a preference, because it commits agent
                             work without anyone reading the plan first.

                             The whole panel is the control: a <label> wrapping the input, so
                             every pixel of it toggles. NOTE it is deliberately NOT .form-check
                             — that class pairs padding-left:1.5rem on the container with
                             margin-left:-1.5rem on the input, so adding p-3 (padding:1rem)
                             overrode the padding but not the negative margin and the box
                             floated 7px outside its own left border. Flex does the layout
                             here instead, which cannot drift the same way. -->
                        <label class="wb-straight d-flex gap-3 mb-3 p-3 border rounded bg-body-tertiary">
                            <input class="form-check-input flex-shrink-0 mt-1" type="checkbox" value="1"
                                   id="auto_build" name="auto_build">
                            <span>
                                <span class="wb-straight-title">
                                    <strong>Run it straight through</strong> &mdash; don't stop for my approval
                                </span>
                                <span class="form-text d-block mb-0">
                                    <strong>Create Task</strong>: starts the agent the moment the task is saved.
                                    Its work still waits for you to approve the merge, so this only skips the Run click.
                                    <br>
                                    <strong>Decompose into plan</strong>: approves the plan as soon as it lands and starts
                                    the build. Plan subtasks merge into the project themselves as they pass &mdash;
                                    so ticking this means code lands in
                                    <strong><?= htmlspecialchars((string)($instance['tag'] ?? 'this project')) ?></strong>
                                    without anyone having read the plan first. Reviewing the plan is the only
                                    point at which you can stop it cheaply.
                                </span>
                            </span>
                        </label>

                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> Create Task
                            </button>
                            <button type="submit" class="btn btn-info" formaction="/workbench/decompose"
                                    title="Treat the Description as a goal document and decompose it into a multi-agent plan for the project you are working on">
                                <i class="bi bi-diagram-3"></i> Decompose into plan &rarr;
                            </button>
                            <a href="/workbench" class="btn btn-outline-secondary">Cancel</a>
                        </div>

                        <div class="form-text mt-2">
                            <strong>Create Task</strong> saves a single task. <strong>Decompose into plan</strong>
                            feeds the Description (e.g. your uploaded <code>.md</code> goal document) to the Builder
                            planner, which breaks it into a multi-agent plan for the chosen instance to review, approve, and build.
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
            txt.innerHTML = 'Loaded <strong>' + name + '</strong> (' + content.length + ' chars). Review below, then Create.';
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

