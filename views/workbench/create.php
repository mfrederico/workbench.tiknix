<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="mb-4">
                <a href="/workbench" class="text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Back to AI Projects
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
                    <form method="POST" action="/workbench/store">
                        <?php foreach ($csrf as $name => $value): ?>
                            <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
                        <?php endforeach; ?>

                        <!-- Instance (tenant) — required -->
                        <div class="mb-3">
                            <label for="instance_id" class="form-label">Instance <span class="text-danger">*</span></label>
                            <select class="form-select" id="instance_id" name="instance_id" required>
                                <option value="" <?= (($preselectInstanceId ?? 0) === 0) ? 'selected' : '' ?> disabled>— Select an instance —</option>
                                <?php foreach (($instances ?? []) as $inst): ?>
                                    <option value="<?= (int)$inst['id'] ?>" <?= (($preselectInstanceId ?? 0) === (int)$inst['id']) ? 'selected' : '' ?>><?= htmlspecialchars(($inst['label']) ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($instances)): ?>
                                <div class="form-text text-warning"><i class="bi bi-exclamation-triangle"></i> You have no instances yet — provision one in the <a href="/aibuilder">Advanced Builder</a> first.</div>
                            <?php else: ?>
                                <div class="form-text">Which Advanced Builder instance this task targets.</div>
                            <?php endif; ?>
                        </div>

                        <!-- Title -->
                        <div class="mb-3">
                            <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="title" name="title" required
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
                                      placeholder="Provide detailed context for Claude..."></textarea>
                            <div class="form-text">Be specific about what you want. Include relevant code paths, requirements, and constraints.</div>
                        </div>

                        <div class="row">
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

                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> Create Task
                            </button>
                            <button type="submit" class="btn btn-info" formaction="/workbench/decompose"
                                    title="Treat the Description as a goal document and decompose it into a multi-agent plan for the selected instance">
                                <i class="bi bi-diagram-3"></i> Decompose into plan &rarr;
                            </button>
                            <a href="/workbench" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                        <div class="form-text mt-2">
                            <strong>Create Task</strong> saves a single task. <strong>Decompose into plan</strong>
                            feeds the Description (e.g. your uploaded <code>.md</code> goal document) to the Advanced Builder
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
