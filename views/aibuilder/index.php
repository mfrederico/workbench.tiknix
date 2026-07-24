<?php
/**
 * AI Builder view — instance picker + jailed Terminal + git changes/checkpoint/plan panel.
 *
 * Vars: $instances (Instance beans), $selected (bean|null), $ab_sub, $ab_token,
 *       $ab_wspath, $ab_hasInstance, $csrf
 */
$csrfTok = csrf_token();
$selId   = $selected ? (int)$selected->id : 0;
$ab_isDefault = $ab_isDefault ?? false;
$ab_isRoot    = $ab_isRoot ?? false;
$ab_canCreate = $ab_canCreate ?? false;
$ab_isOwner       = $ab_isOwner ?? false;
$shareTeams       = $shareTeams ?? [];
$ab_sharedTeamIds = array_map('intval', $ab_sharedTeamIds ?? []);
$ab_instSharedIds = array_map('intval', $ab_instSharedIds ?? []);
$hasDefault = false;
foreach ($instances as $__i) { if (!empty($__i->isDefault)) { $hasDefault = true; break; } }
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/css/xterm.min.css">
<style>
  #ab-terminal { height: 70vh; width: 100%; background:#1e1e1e; border-radius:.375rem; padding:8px; }
  /* Loud "which instance am I in" banner */
  .ab-working { border:2px solid var(--bs-primary); }
  .ab-working .lbl { font-size:.6rem; letter-spacing:.06em; }
  .ab-working .ab-open:hover { text-decoration:underline !important; }
  /* Active instance in the left nav */
  .list-group-item.active .ab-caret { display:inline; }
  .ab-caret { display:none; }
  #ab-changes { max-height:22vh; overflow-y:auto; }
  #ab-upload-list { max-height:18vh; overflow-y:auto; }
  #ab-ckpt-list { max-height:22vh; overflow-y:auto; }
  .ab-file { display:flex; gap:.5rem; align-items:center; font-family:ui-monospace,Menlo,monospace; font-size:.78rem; padding:.15rem 0; }
  .ab-file .st { width:1.4rem; text-align:center; border-radius:.2rem; font-weight:700; font-size:.7rem; }
  .ab-file .st.M{background:#3a2f00;color:#e3b341}.ab-file .st.A{background:#0f2e15;color:#3fb950}
  .ab-file .st.D{background:#3a1113;color:#f85149}.ab-file .st.R{background:#0b2b3a;color:#39c5cf}.ab-file .st.U{background:#2d2233;color:#bc8cff}
  .ab-file.fresh { background:rgba(13,202,240,.10); border-radius:.25rem; }
  .ab-ckpt { font-size:.8rem; padding:.4rem 0; border-bottom:1px solid var(--bs-border-color); }
  .ab-ckpt .desc { color:var(--bs-secondary-color); }
  /* Sign-in gate: locks the terminal until this instance is connected to Claude */
  .ab-oauth-gate { position:absolute; inset:0; z-index:20; display:flex; align-items:center; justify-content:center;
    background:rgba(15,17,20,.93); backdrop-filter:blur(3px); border-radius:.375rem; padding:1rem; }
  .ab-oauth-card { max-width:540px; width:100%; background:var(--bs-body-bg); border:1px solid var(--bs-border-color);
    border-radius:.5rem; padding:1.25rem 1.4rem; box-shadow:0 12px 44px rgba(0,0,0,.45); }
  .ab-oauth-head { font-size:1.05rem; font-weight:700; color:var(--bs-primary); margin-bottom:.35rem; }
  .ab-oauth-sub { font-size:.85rem; color:var(--bs-secondary-color); margin-bottom:.9rem; }
  .ab-oauth-steps { padding-left:1.15rem; margin:0; font-size:.86rem; }
  .ab-oauth-steps li { margin-bottom:.9rem; }
  .ab-oauth-msg { font-size:.82rem; min-height:1.2em; margin-top:.4rem; }
</style>

<div class="container-fluid py-4">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
    <div>
      <h1 class="h3 fw-bold mb-0"><i class="bi bi-robot me-2"></i>Advanced Builder</h1>
      <p class="text-body-secondary mb-0">Build software with AI. Every instance is sandboxed — checkpoint and roll back any change.</p>
    </div>
    <?php if ($ab_hasInstance): ?>
      <div class="ab-working d-flex align-items-center gap-2 px-3 py-2 rounded-3 bg-primary-subtle flex-wrap">
        <i class="bi bi-hdd-network-fill text-primary fs-5"></i>
        <div class="lh-sm">
          <div class="lbl text-uppercase text-body-secondary fw-semibold">Working on</div>
          <div class="fw-bold">
            <?php $abName = htmlspecialchars(($selected->slug ?? '') . '.tiknix'); $abUrl = (string)($ab_url ?? ''); ?>
            <?php if ($abUrl !== ''): ?>
              <a href="<?= htmlspecialchars($abUrl) ?>" target="_blank" rel="noopener"
                 class="ab-open link-body-emphasis text-decoration-none"
                 title="Open live preview — <?= htmlspecialchars($abUrl) ?>"><?= $abName ?><i class="bi bi-box-arrow-up-right ms-1 small opacity-75"></i></a>
            <?php else: ?>
              <?= $abName ?>
            <?php endif; ?>
            <?php if ($ab_isDefault): ?><span class="badge text-bg-warning">default · core</span><?php endif; ?>
            <span id="ab-status" class="fw-normal text-body-secondary small">· connecting…</span>
          </div>
        </div>
        <div class="vr d-none d-sm-block mx-1"></div>
        <button id="ab-publish" class="btn btn-dark btn-sm" type="button">
          <i class="bi bi-cloud-upload me-1"></i><?= $ab_isDefault ? 'Publish to main' : 'Publish' ?>
        </button>
        <span id="ab-gh-state" class="small text-body-secondary"></span>
        <span id="ab-publish-msg" class="small"></span>
        <?php if ($ab_isOwner): ?>
          <div class="vr d-none d-sm-block mx-1"></div>
          <a href="/connections?id=<?= (int)$selected->id ?>" target="_blank" rel="noopener"
             class="btn btn-outline-secondary btn-sm" title="Store &amp; service connections for this instance (Shopify, GitHub, …)">
            <i class="bi bi-plug me-1"></i>Connections
          </a>
        <?php endif; ?>
        <?php if ($ab_isOwner && !$ab_isDefault): $sharedCount = count($ab_sharedTeamIds); ?>
          <div class="vr d-none d-sm-block mx-1"></div>
          <div class="dropdown" id="ab-share-wrap">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Share this instance with one or more teams">
              <i class="bi bi-people me-1"></i><span id="ab-share-label"><?= $sharedCount ? ('Shared · ' . $sharedCount) : 'Share' ?></span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm p-2" style="min-width:15rem;">
              <li><h6 class="dropdown-header px-1">Share with teams</h6></li>
              <?php if (empty($shareTeams)): ?>
                <li><span class="dropdown-item-text small text-body-secondary">You're not on any team yet.</span></li>
              <?php else: foreach ($shareTeams as $__t): $on = in_array((int)$__t->id, $ab_sharedTeamIds, true); ?>
                <li>
                  <label class="dropdown-item d-flex align-items-center gap-2 rounded">
                    <input type="checkbox" class="form-check-input mt-0 ab-share-team" value="<?= (int)$__t->id ?>" <?= $on ? 'checked' : '' ?>>
                    <span><?= htmlspecialchars(($__t->name) ?? '') ?></span>
                  </label>
                </li>
              <?php endforeach; endif; ?>
              <li><hr class="dropdown-divider"></li>
              <li><span class="dropdown-item-text small text-body-secondary">Members of any checked team get full use (build, run, checkpoint) and see its AI Projects tasks. Only you can share, unshare, or delete.</span></li>
            </ul>
          </div>
          <span id="ab-share-msg" class="small"></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="row g-3">
    <!-- Instance picker -->
    <div class="col-lg-3">
      <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Your Instances</span>
          <?php if ($ab_canCreate): ?><button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#ab-new-form"><i class="bi bi-plus-lg"></i></button><?php endif; ?>
        </div>
        <?php if ($ab_canCreate): ?>
        <div class="collapse <?= empty($instances) ? 'show' : '' ?>" id="ab-new-form">
          <div class="card-body border-bottom">
            <form id="ab-create-form">
              <div class="mb-2">
                <label class="form-label small mb-1">Name (slug)</label>
                <input name="slug" class="form-control form-control-sm" placeholder="myapp" pattern="[a-z][a-z0-9]{1,49}" required>
                <div class="form-text">Becomes <code>&lt;slug&gt;.tiknix</code>.</div>
              </div>
              <div class="mb-2">
                <label class="form-label small mb-1">Engine</label>
                <select name="engine" class="form-select form-select-sm">
                  <?php foreach (\app\EngineRegistry::menu() as $engName => $engLabel): ?>
                  <option value="<?= htmlspecialchars($engName) ?>"><?= htmlspecialchars($engLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-success btn-sm w-100"><i class="bi bi-hammer me-1"></i>Create instance</button>
              <div id="ab-create-msg" class="form-text"></div>
            </form>
          </div>
        </div>
        <?php endif; ?>
        <div class="list-group list-group-flush">
          <?php if ($ab_isRoot && !$hasDefault): ?>
            <button id="ab-create-core" type="button" class="list-group-item list-group-item-action list-group-item-warning">
              <span class="fw-semibold"><i class="bi bi-star-fill me-1"></i>Set up tiknix core (default)</span>
              <div class="small text-body-secondary">A sandboxed clone of main you publish back via PR.</div>
            </button>
          <?php endif; ?>
          <?php if (empty($instances)): ?>
            <div class="list-group-item text-body-secondary small"><?= $ab_canCreate ? 'No instances yet. Create one above.' : 'No instances shared with you yet. Ask an instance owner to share one with your team.' ?></div>
          <?php else: foreach ($instances as $inst): $isSel = ($selId === (int)$inst->id); ?>
            <a href="/aibuilder/open/<?= (int)$inst->id ?>"
               class="list-group-item list-group-item-action <?= $isSel ? 'active' : '' ?>">
              <div class="d-flex justify-content-between">
                <span class="fw-semibold"><i class="bi bi-caret-right-fill ab-caret me-1"></i><?= htmlspecialchars(($inst->displayName ?: $inst->slug) ?? '') ?></span>
                <span>
                  <?php if (!empty($inst->isDefault)): ?><span class="badge text-bg-warning">default</span> <?php endif; ?>
                  <?php $__mine = (int)$inst->memberId === (int)($ab_memberId ?? 0); $__shared = in_array((int)$inst->id, $ab_instSharedIds, true); ?>
                  <?php if (!$__mine): ?><span class="badge text-bg-info" title="Shared with your team"><i class="bi bi-people-fill"></i></span> <?php endif; ?>
                  <?php if ($__mine && $__shared): ?><span class="badge text-bg-info" title="You shared this with a team"><i class="bi bi-share-fill"></i></span> <?php endif; ?>
                  <span class="badge text-bg-dark"><?= htmlspecialchars(($inst->engine) ?? '') ?></span>
                </span>
              </div>
              <small class="<?= $isSel ? '' : 'text-body-secondary' ?>"><?= htmlspecialchars(($inst->slug) ?? '') ?>.tiknix</small>
            </a>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <?php if (!$ab_hasInstance): ?>
      <div class="col-lg-9">
        <div class="card shadow-sm"><div class="card-body text-center text-body-secondary py-5">
          <i class="bi bi-arrow-left-circle fs-1 d-block mb-3"></i>
          Select an instance to open its sandboxed Terminal, or create a new one.
        </div></div>
      </div>
    <?php else: ?>
      <!-- Builder surface: Terminal -->
      <div class="col-lg-6">
        <div class="card shadow-sm">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-terminal me-1"></i>Terminal</span>
            <span class="d-flex align-items-center gap-2">
              <span class="text-body-secondary small d-none d-md-inline"><i class="bi bi-shield-lock me-1"></i>Sandboxed to <?= htmlspecialchars(($selected->slug) ?? '') ?>.tiknix</span>
              <button id="ab-restart" class="btn btn-outline-secondary btn-sm" type="button" title="Restart the jailed session (applies updated sandbox settings)"><i class="bi bi-arrow-repeat me-1"></i>Restart</button>
              <button id="ab-delete" class="btn btn-outline-danger btn-sm" type="button" title="Delete this instance (danger zone)"><i class="bi bi-trash me-1"></i>Delete</button>
            </span>
          </div>
          <div class="card-body p-2 bg-body-tertiary position-relative">
            <div id="ab-terminal"></div>

            <!-- Sign-in gate — the fake browser ($BROWSER in the jail) captured an OAuth
                 URL; lock the terminal until this instance is connected to Claude. -->
            <div id="ab-oauth-gate" class="ab-oauth-gate d-none">
              <div class="ab-oauth-card">
                <div class="ab-oauth-head"><i class="bi bi-box-arrow-in-right me-1"></i>Connect this instance to Claude</div>
                <p class="ab-oauth-sub">Claude needs to sign in before it can work here. One-time — it stays signed in after.</p>
                <ol class="ab-oauth-steps">
                  <li>Open the sign-in page and approve access:
                    <div class="mt-2"><a id="ab-oauth-open" class="btn btn-primary btn-sm" href="#" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Open Claude sign-in</a></div>
                  </li>
                  <li>Approve — Anthropic then shows you a code. <strong>Copy it</strong> and paste it here:
                    <div class="input-group input-group-sm mt-2">
                      <input id="ab-oauth-code" class="form-control" placeholder="Paste the code Anthropic gave you…" autocomplete="off" spellcheck="false">
                      <button id="ab-oauth-submit" class="btn btn-success" type="button" disabled><i class="bi bi-plug me-1"></i>Connect</button>
                    </div>
                  </li>
                </ol>
                <div id="ab-oauth-msg" class="ab-oauth-msg"></div>
              </div>
            </div>

            <p class="text-body-secondary small mt-2 mb-1">
              Type <code>claude</code> to start the agent. If it needs to sign in, this panel locks until you connect it.
              Hold <kbd>Shift</kbd> and drag to select/copy; right-click to paste.
            </p>
            <button id="ab-test" class="btn btn-outline-secondary btn-sm" type="button" title="Copy a browser-test prompt for the agent (uses the playwright MCP)"><i class="bi bi-bug me-1"></i>Copy browser-test prompt</button>
            <span id="ab-test-msg" class="small text-body-secondary ms-2"></span>
          </div>
        </div>
      </div>

      <!-- Changes + checkpoints + plan -->
      <div class="col-lg-3">
        <!-- Save your work: changes + uploads + checkpoint, in one place -->
        <div class="card shadow-sm">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-save me-1"></i>Save your work</span>
            <button id="ab-changes-refresh" class="btn btn-sm btn-outline-secondary" title="Refresh changes"><i class="bi bi-arrow-clockwise"></i></button>
          </div>
          <div class="card-body">
            <!-- 1) Changes since last checkpoint -->
            <div class="text-uppercase text-body-secondary fw-semibold mb-1" style="font-size:.68rem;letter-spacing:.04em"><i class="bi bi-file-diff me-1"></i>Changes since last checkpoint</div>
            <div id="ab-changes" class="mb-3"><div class="text-body-secondary small">No changes yet.</div></div>

            <!-- 2) Uploads -->
            <div class="text-uppercase text-body-secondary fw-semibold mb-1" style="font-size:.68rem;letter-spacing:.04em"><i class="bi bi-paperclip me-1"></i>Uploads <span class="fw-normal text-lowercase">— @reference in the terminal</span></div>
            <form id="ab-upload-form" class="mb-2">
              <input id="ab-upload-file" type="file" class="form-control form-control-sm mb-2" multiple>
              <div class="d-flex gap-2">
                <select id="ab-upload-bucket" class="form-select form-select-sm">
                  <option value="secure">Secure — not web-accessible (published)</option>
                  <option value="public">Public — web-accessible (published)</option>
                </select>
                <button class="btn btn-primary btn-sm" type="submit" title="Upload"><i class="bi bi-upload"></i></button>
              </div>
              <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" id="ab-upload-overwrite">
                <label class="form-check-label small" for="ab-upload-overwrite">Overwrite existing (<code>index.php</code> protected)</label>
              </div>
              <div id="ab-upload-msg" class="form-text"></div>
            </form>
            <div id="ab-upload-list" class="small mb-3"></div>

            <!-- 3) Checkpoint (commits everything above; auto-publishes if connected) -->
            <hr class="my-2">
            <div class="text-uppercase text-body-secondary fw-semibold mb-1" style="font-size:.68rem;letter-spacing:.04em"><i class="bi bi-bookmark-plus me-1"></i>Checkpoint</div>
            <form id="ab-ckpt-form" class="d-flex gap-2 mb-1">
              <input id="ab-ckpt-desc" class="form-control form-control-sm" placeholder="Describe this checkpoint…" maxlength="200">
              <button class="btn btn-success btn-sm text-nowrap" type="submit" title="Save checkpoint"><i class="bi bi-save me-1"></i>Save</button>
            </form>
            <div class="text-body-secondary mb-2" style="font-size:.72rem">Commits all changes &amp; uploads above as a restore point — and publishes to GitHub if this instance auto-publishes.</div>
            <div id="ab-ckpt-list" class="small"></div>
          </div>
        </div>
        <div class="card shadow-sm mt-3">
          <div class="card-header fw-semibold"><i class="bi bi-diagram-3 me-1"></i>Plan &amp; build</div>
          <div class="card-body">
            <p class="text-body-secondary small mb-2">Decompose a goal into a multi-agent plan for this instance — grounded on its reuse inventory so tasks build on what already exists. Planning, review, approve &amp; build all live in AI Projects.</p>
            <div class="d-flex gap-2">
              <a href="/workbench/create?instance_id=<?= (int)$selId ?>" class="btn btn-info btn-sm flex-fill"><i class="bi bi-diagram-3 me-1"></i>Plan &amp; build in AI Projects</a>
              <button id="ab-reuse-digest" class="btn btn-outline-secondary btn-sm text-nowrap" type="button" title="Preview the reuse inventory the planner is grounded on for this instance"><i class="bi bi-recycle me-1"></i>Reuse digest</button>
            </div>
            <div class="text-body-secondary mt-2" style="font-size:.72rem">Preview this instance's <a href="#" id="ab-reuse-digest-link">reuse inventory</a>, or review generated plans in <a href="/workbench" target="_blank">AI Projects</a>, tagged to this instance.</div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@xterm/xterm@5.5.0/lib/xterm.min.js"></script>
<!-- Danger-zone: delete instance -->
<div class="modal fade" id="ab-delete-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Delete instance</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">This <strong>permanently deletes</strong> <code id="ab-del-domain"></code>. It:</p>
        <ul class="small mb-3">
          <li>kills the jailed session and removes the GitHub connector (the repo itself is kept)</li>
          <li>archives the folder to <code>public/&lt;slug&gt;.zip</code> (config secrets stripped; vendor/.git excluded)</li>
          <li>wipes everything else and removes it from the builder</li>
        </ul>
        <label class="form-label small mb-1">Type <code id="ab-del-domain2"></code> to confirm:</label>
        <input id="ab-del-input" class="form-control" autocomplete="off" spellcheck="false">
        <div id="ab-del-msg" class="small text-danger mt-2"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button id="ab-del-confirm" class="btn btn-danger" type="button" disabled><i class="bi bi-trash me-1"></i>Delete permanently</button>
      </div>
    </div>
  </div>
</div>

<!-- Checkpoint: roll back OR fork a new instance -->
<div class="modal fade" id="ab-ckpt-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Checkpoint <code id="ab-ck-name" class="ms-1"></code></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <!-- Roll back (owner only) -->
        <?php if ($ab_isOwner): ?>
        <div class="border rounded p-3 mb-3">
          <div class="fw-semibold mb-1"><i class="bi bi-arrow-counterclockwise me-1"></i>Roll back this instance</div>
          <p class="small text-body-secondary mb-2">Restores <strong>code and data</strong> of this instance to this checkpoint. Anything since is lost.</p>
          <button id="ab-ck-rollback" class="btn btn-outline-danger btn-sm" type="button"><i class="bi bi-arrow-counterclockwise me-1"></i>Roll back to here</button>
        </div>
        <?php endif; ?>
        <!-- Fork (admin only) -->
        <?php if ($ab_canCreate): ?>
        <div class="border rounded p-3">
          <div class="fw-semibold mb-1"><i class="bi bi-diagram-2 me-1"></i>Create a new instance from this checkpoint</div>
          <p class="small text-body-secondary mb-2">Spins up a brand-new instance with this checkpoint's <strong>code and data</strong>. Connections and secrets reset — it gets its own subdomain, database, and sign-in.</p>
          <div class="mb-2">
            <label class="form-label small mb-1">Name (slug)</label>
            <input id="ab-fork-slug" class="form-control form-control-sm" placeholder="myapp2" pattern="[a-z][a-z0-9]{1,49}" autocomplete="off" spellcheck="false">
            <div class="form-text">Becomes <code>&lt;slug&gt;.tiknix</code>.</div>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">Display name</label>
            <input id="ab-fork-name" class="form-control form-control-sm" placeholder="My App (fork)" autocomplete="off">
          </div>
          <button id="ab-ck-fork" class="btn btn-primary btn-sm" type="button"><i class="bi bi-diagram-2 me-1"></i>Create instance</button>
          <div id="ab-fork-msg" class="form-text mt-2"></div>
        </div>
        <?php endif; ?>
        <?php if (!$ab_isOwner && !$ab_canCreate): ?>
        <p class="small text-body-secondary mb-0">Rolling back and forking are limited to the instance owner. You can still create checkpoints from the panel.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Reuse digest: what the planner is grounded on for this instance -->
<div class="modal fade" id="ab-reuse-modal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-recycle me-2"></i>Reuse inventory — <code id="ab-reuse-slug"></code></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="text-body-secondary small mb-2">This is the exact "what already exists, reuse it" inventory injected into this instance's planner brief (and available in-instance via the <code>reuse_digest</code> MCP tool). Decomposition is told to REUSE/EXTEND these before creating anything new.</p>
        <pre id="ab-reuse-body" class="small mb-0" style="max-height:60vh;overflow:auto;background:#1e1e1e;color:#ddd;border-radius:.375rem;padding:.75rem;font-size:.72rem;white-space:pre-wrap">Loading…</pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="ab-reuse-copy"><i class="bi bi-clipboard me-1"></i>Copy</button>
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/@xterm/addon-fit@0.10.0/lib/addon-fit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@xterm/addon-web-links@0.11.0/lib/addon-web-links.min.js"></script>
<script>
const AB = {
  id: <?= $selId ?>,
  token: <?= json_encode($ab_token) ?>,
  wsPath: <?= json_encode($ab_wspath) ?>,
  csrf: <?= json_encode($csrfTok) ?>,
  has: <?= $ab_hasInstance ? 'true' : 'false' ?>,
  url: <?= json_encode($ab_url ?? '') ?>,
};
const esc = s => (s||'').replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));

// --- create instance --------------------------------------------------------
const createForm = document.getElementById('ab-create-form');
if (createForm) createForm.addEventListener('submit', function (e) {
  e.preventDefault();
  const btn = createForm.querySelector('button[type=submit]'), msg = document.getElementById('ab-create-msg');
  btn.disabled = true; msg.textContent = 'Provisioning… this can take a minute.';
  fetch('/aibuilder/create', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':AB.csrf,'X-Requested-With':'XMLHttpRequest'},
    body:new URLSearchParams({slug:createForm.slug.value.trim(), engine:createForm.engine.value, csrf_token:AB.csrf}).toString()
  }).then(r=>r.json()).then(j=>{
    if (j.success && j.data && j.data.id) window.location = '/aibuilder/open/' + j.data.id;
    else { msg.textContent = j.message || 'Failed.'; btn.disabled = false; }
  }).catch(()=>{ msg.textContent='Network error.'; btn.disabled=false; });
});

// --- root: provision the "(default)" tiknix-core instance (a clone of main) ---
const coreBtn = document.getElementById('ab-create-core');
if (coreBtn) coreBtn.addEventListener('click', function () {
  if (!confirm('Provision a sandboxed clone of tiknix main as your (default) core instance? This can take a minute.')) return;
  this.disabled = true;
  this.insertAdjacentHTML('beforeend', '<div class="small text-body-secondary">Provisioning…</div>');
  fetch('/aibuilder/create', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':AB.csrf,'X-Requested-With':'XMLHttpRequest'},
    body:new URLSearchParams({slug:'core', name:'(default)', engine:'claude', is_default:'1', csrf_token:AB.csrf}).toString()
  }).then(r=>r.json()).then(j=>{
    if (j.success && j.data && j.data.id) window.location = '/aibuilder/open/' + j.data.id;
    else { alert(j.message || 'Failed to provision core.'); this.disabled = false; }
  }).catch(()=>{ alert('Network error.'); this.disabled = false; });
});

if (AB.has) {
  const statusEl = document.getElementById('ab-status');
  const setStatus = t => { statusEl.textContent = '· ' + t; };
  const freshToken = () => fetch('/aibuilder/refresh?id='+AB.id, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(j=>(j.success&&j.data&&j.data.token)?j.data.token:AB.token).catch(()=>AB.token);
  const wsBase = (location.protocol==='https:'?'wss':'ws') + '://' + location.host;

  // --- Terminal ---
  let term, termWs;
  function initTerminal(){
    term=new Terminal({cursorBlink:true,fontSize:13,scrollback:50000,fontFamily:'ui-monospace,Menlo,monospace',theme:{background:'#1e1e1e'}});
    const fit=new FitAddon.FitAddon(); term.loadAddon(fit);
    // Clickable URLs — makes the `claude setup-token` OAuth link openable without selecting it.
    try { term.loadAddon(new WebLinksAddon.WebLinksAddon((e,uri)=>window.open(uri,'_blank','noopener'))); } catch(e){}
    const el=document.getElementById('ab-terminal');
    term.open(el); fit.fit();

    // Copy-on-select: releasing the mouse over a selection copies it to the clipboard.
    el.addEventListener('mouseup', ()=>{
      const sel=term.getSelection();
      if(sel && navigator.clipboard) navigator.clipboard.writeText(sel).catch(()=>{});
    });
    // Right-click pastes from the clipboard into the PTY.
    el.addEventListener('contextmenu', ev=>{
      ev.preventDefault();
      if(navigator.clipboard) navigator.clipboard.readText().then(t=>{ if(t) term.paste(t); }).catch(()=>{});
    });

    freshToken().then(tok=>{
      termWs=new WebSocket(wsBase+AB.wsPath+'?token='+encodeURIComponent(tok));
      termWs.onopen=()=>{ setStatus('terminal connected'); termWs.send(JSON.stringify({type:'resize',cols:term.cols,rows:term.rows})); };
      termWs.onmessage=e=>term.write(typeof e.data==='string'?e.data:new Uint8Array(e.data));
      termWs.onclose=()=>setStatus('terminal disconnected');
      term.onData(d=>{ if(termWs.readyState===WebSocket.OPEN) termWs.send(JSON.stringify({type:'input',data:d})); });
      window.addEventListener('resize',()=>{ fit.fit(); if(termWs.readyState===WebSocket.OPEN) termWs.send(JSON.stringify({type:'resize',cols:term.cols,rows:term.rows})); });
    });
  }

  // --- Changes panel (polls so terminal edits show up live) ---
  let lastChangePaths=[];
  function refreshChanges(){
    fetch('/aibuilder/changes?id='+AB.id,{headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(r=>r.json()).then(j=>{
        const box=document.getElementById('ab-changes'); const files=(j.data&&j.data.files)||[];
        const prev=new Set(lastChangePaths);
        if(!files.length){ box.innerHTML='<div class="text-body-secondary small">No changes since last checkpoint.</div>'; lastChangePaths=[]; return; }
        box.innerHTML=files.map(f=>{
          const code=(f.status||'?').replace(/[^MADRU?]/g,'').charAt(0)||'M';
          const fresh=!prev.has(f.path)?' fresh':'';
          return '<div class="ab-file'+fresh+'"><span class="st '+code+'">'+esc(code)+'</span><span class="path">'+esc(f.path)+'</span></div>';
        }).join('');
        lastChangePaths=files.map(f=>f.path);
      }).catch(()=>{});
  }
  document.getElementById('ab-changes-refresh').addEventListener('click',()=>{ lastChangePaths=[]; refreshChanges(); });

  // --- Checkpoints ---
  function loadCheckpoints(){
    fetch('/aibuilder/checkpoints?id='+AB.id,{headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(r=>r.json()).then(j=>{
        const box=document.getElementById('ab-ckpt-list'); const cps=(j.data&&j.data.checkpoints)||[];
        if(!cps.length){ box.innerHTML='<div class="text-body-secondary">No checkpoints yet.</div>'; return; }
        box.innerHTML=cps.map(c=>'<div class="ab-ckpt"><div class="d-flex justify-content-between"><span class="fw-semibold">'+esc(c.name.replace(/^checkpoint-/,''))+'</span>'
          +'<button class="btn btn-link btn-sm p-0 ab-rb" data-ckpt="'+esc(c.name)+'" title="Roll back or fork from here"><i class="bi bi-three-dots"></i></button></div>'
          +(c.description?'<div class="desc">'+esc(c.description)+'</div>':'')
          +'<div class="text-body-secondary" style="font-size:.72rem">'+esc(c.date)+' · '+esc(c.commit)+'</div></div>').join('');
        box.querySelectorAll('.ab-rb').forEach(b=>b.addEventListener('click',()=>openCkptModal(b.dataset.ckpt)));
      }).catch(()=>{});
  }
  const post=(url,extra)=>fetch(url,{method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':AB.csrf,'X-Requested-With':'XMLHttpRequest'},
    body:new URLSearchParams(Object.assign({csrf_token:AB.csrf,id:AB.id},extra||{})).toString()}).then(r=>r.json());

  // --- Share instance with teams (owner only, many-to-many) ------------------
  document.querySelectorAll('.ab-share-team').forEach(cb=>cb.addEventListener('change',function(){
    const teamId=this.value, shared=this.checked?1:0;
    const msg=document.getElementById('ab-share-msg'), lbl=document.getElementById('ab-share-label');
    if(msg) msg.textContent='saving…';
    this.disabled=true;
    post('/aibuilder/share',{team_id:teamId,shared:shared}).then(j=>{
      this.disabled=false;
      if(j&&j.success){
        const n=(j.data&&j.data.shared_team_ids?j.data.shared_team_ids.length:0);
        if(lbl) lbl.textContent = n ? ('Shared · '+n) : 'Share';
        if(msg){ msg.textContent=j.message||''; setTimeout(()=>{ if(msg) msg.textContent=''; },2500); }
      } else {
        this.checked=!this.checked; // revert
        if(msg) msg.textContent=(j&&j.message)||'Share failed';
      }
    }).catch(()=>{ this.disabled=false; this.checked=!this.checked; if(msg) msg.textContent='Share failed'; });
  }));

  document.getElementById('ab-ckpt-form').addEventListener('submit',function(e){
    e.preventDefault(); const inp=document.getElementById('ab-ckpt-desc'); const btn=this.querySelector('button'); btn.disabled=true;
    post('/aibuilder/checkpoint',{label:inp.value.trim()}).then(j=>{
      inp.value=''; loadCheckpoints(); refreshChanges();
      const p=j.data&&j.data.publish; if(p){ const m=ghMsg();
        if(p.ok&&p.pr&&p.pr.url){ m.className='small mt-2 text-success'; m.innerHTML='<i class="bi bi-check-circle me-1"></i>Auto-published — <a href="'+esc(p.pr.url)+'" target="_blank" rel="noopener">PR #'+esc(String(p.pr.number||''))+'</a>'; }
        else if(p.ok){ m.className='small mt-2 text-success'; m.innerHTML='<i class="bi bi-check-circle me-1"></i>'+esc(p.message||'Auto-pushed'); }
        else { m.className='small mt-2 text-danger'; m.textContent='Auto-publish failed: '+(p.error||''); } }
    }).finally(()=>btn.disabled=false);
  });
  let _ckpt=null;
  function openCkptModal(ckpt){
    _ckpt=ckpt;
    const nm=document.getElementById('ab-ck-name'); if(nm) nm.textContent=ckpt.replace(/^checkpoint-/,'');
    const fs=document.getElementById('ab-fork-slug'), fn=document.getElementById('ab-fork-name'), fm=document.getElementById('ab-fork-msg');
    if(fs) fs.value=''; if(fn) fn.value=''; if(fm) fm.textContent='';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('ab-ckpt-modal')).show();
  }
  function doRollback(ckpt){
    if(!confirm('Roll back to '+ckpt+'? This restores code AND data to that checkpoint.')) return;
    post('/aibuilder/rollback/'+encodeURIComponent(ckpt),{}).then(j=>{ loadCheckpoints(); refreshChanges(); });
  }
  document.getElementById('ab-ck-rollback')?.addEventListener('click',function(){
    if(!_ckpt) return;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('ab-ckpt-modal')).hide();
    doRollback(_ckpt);
  });
  document.getElementById('ab-ck-fork')?.addEventListener('click',function(){
    if(!_ckpt) return;
    const slug=(document.getElementById('ab-fork-slug').value||'').trim();
    const name=(document.getElementById('ab-fork-name').value||'').trim();
    const msg=document.getElementById('ab-fork-msg');
    if(!/^[a-z][a-z0-9]{1,49}$/.test(slug)){ msg.className='form-text mt-2 text-danger'; msg.textContent='Enter a valid slug (lowercase, starts with a letter).'; return; }
    this.disabled=true; msg.className='form-text mt-2 text-body-secondary';
    msg.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>Provisioning &amp; copying data… this can take a minute.';
    post('/aibuilder/fork',{id:AB.id,checkpoint:_ckpt,slug:slug,name:name}).then(j=>{
      this.disabled=false;
      if(j&&j.success){
        const url='/aibuilder/open/'+(j.data&&j.data.id);
        msg.className='form-text mt-2 text-success';
        msg.innerHTML='<i class="bi bi-check-circle me-1"></i>'+esc(j.message||'Created')+' — <a href="'+url+'">open '+esc(slug)+'.tiknix</a>';
      } else { msg.className='form-text mt-2 text-danger'; msg.textContent=(j&&j.message)||'Fork failed'; }
    }).catch(()=>{ this.disabled=false; msg.className='form-text mt-2 text-danger'; msg.textContent='Fork failed'; });
  });

  // --- Publish to GitHub (push + PR; first-time opens setup in a new tab) ---
  let ghConnected=false, ghRepo='';
  function loadGhStatus(){
    fetch('/connections/status?id='+AB.id,{headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(r=>r.json()).then(j=>{
        const st=document.getElementById('ab-gh-state');
        ghConnected=!!(j.data&&j.data.connected);
        if(ghConnected&&j.data.connection){ const c=j.data.connection; ghRepo=c.repo||'';
          st.innerHTML='<i class="bi bi-check-circle text-success me-1"></i>Connected: <strong>'+esc(ghRepo)+'</strong>'
            +(c.autoPublish?' <span class="badge text-bg-info">auto-publish</span>':''); }
        else st.innerHTML='<i class="bi bi-plug me-1"></i>Not connected. Publish will open GitHub setup.';
      }).catch(()=>{});
  }
  const ghMsg=()=>document.getElementById('ab-publish-msg');
  document.getElementById('ab-publish').addEventListener('click',function(){
    if(!ghConnected){
      window.open('/connections/setup?id='+AB.id,'_blank');
      ghMsg().className='small mt-2 text-body-secondary';
      ghMsg().innerHTML='Complete GitHub setup in the new tab, then click <strong>Publish</strong> again.';
      return;
    }
    const btn=this; btn.disabled=true;
    ghMsg().className='small mt-2 text-body-secondary'; ghMsg().textContent='Pushing & opening PR…';
    post('/connections/publish',{}).then(j=>{
      const m=ghMsg(); const pr=j.data&&j.data.pr;
      if(j.success&&pr&&pr.url){ m.className='small mt-2 text-success';
        m.innerHTML='<i class="bi bi-check-circle me-1"></i>'+esc(j.message||'Published')+' — <a href="'+esc(pr.url)+'" target="_blank" rel="noopener">PR #'+esc(String(pr.number||''))+'</a>'; }
      else if(j.success){ m.className='small mt-2 text-success';
        m.innerHTML='<i class="bi bi-check-circle me-1"></i>'+esc(j.message||'Pushed')+(j.data&&j.data.note?' — '+esc(j.data.note):''); }
      else { m.className='small mt-2 text-danger'; m.textContent=j.message||'Publish failed.'; }
    }).catch(()=>{ const m=ghMsg(); m.className='small mt-2 text-danger'; m.textContent='Network error.'; })
      .finally(()=>btn.disabled=false);
  });
  window.addEventListener('message',function(ev){
    if(ev.origin===location.origin&&ev.data&&ev.data.type==='gh-connected'){
      loadGhStatus(); const m=ghMsg(); m.className='small mt-2 text-success';
      m.innerHTML='<i class="bi bi-check-circle me-1"></i>GitHub connected. Click <strong>Publish</strong>.'; }
  });

  // --- Uploads (both published; secure = outside docroot/not web-served, public = web-served) ---
  function humanSize(n){ n=+n||0; return n>1048576?(n/1048576).toFixed(1)+'MB':(n>1024?(n/1024).toFixed(0)+'KB':n+'B'); }
  function loadUploads(){
    fetch('/aibuilder/uploads?id='+AB.id,{headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json()).then(j=>{
      const box=document.getElementById('ab-upload-list'); const u=(j.data&&j.data.uploads)||{secure:[],public:[]};
      const row=(f,bucket)=>'<div class="ab-file"><span class="st '+(bucket==='public'?'A':'U')+'">'+(bucket==='public'?'P':'S')+'</span>'
        +'<span class="path flex-grow-1">'+esc(f.name)+' <span class="text-body-secondary">'+humanSize(f.size)+'</span></span>'
        +'<button class="btn btn-link btn-sm p-0 ab-cp" title="Copy @reference" data-ref="'+esc(f.ref)+'"><i class="bi bi-clipboard"></i></button>'
        +'<button class="btn btn-link btn-sm p-0 text-danger ab-del" title="Delete" data-bucket="'+bucket+'" data-name="'+esc(f.name)+'"><i class="bi bi-x-lg"></i></button></div>';
      const sec=(u.secure||[]).map(f=>row(f,'secure')).join(''), pub=(u.public||[]).map(f=>row(f,'public')).join('');
      box.innerHTML=(sec||pub)?((sec?'<div class="text-body-secondary mt-1 mb-1">Secure</div>'+sec:'')+(pub?'<div class="text-body-secondary mt-2 mb-1">Public</div>'+pub:'')):'<div class="text-body-secondary">No uploads yet.</div>';
      box.querySelectorAll('.ab-cp').forEach(b=>b.addEventListener('click',()=>{ if(navigator.clipboard) navigator.clipboard.writeText(b.dataset.ref); b.innerHTML='<i class="bi bi-check2"></i>'; setTimeout(()=>b.innerHTML='<i class="bi bi-clipboard"></i>',1200); }));
      box.querySelectorAll('.ab-del').forEach(b=>b.addEventListener('click',()=>{ if(!confirm('Delete '+b.dataset.name+'?')) return; post('/aibuilder/deleteupload',{bucket:b.dataset.bucket,name:b.dataset.name}).then(()=>{ loadUploads(); refreshChanges(); }); }));
    }).catch(()=>{});
  }
  document.getElementById('ab-upload-form').addEventListener('submit',function(e){
    e.preventDefault();
    const inp=document.getElementById('ab-upload-file'); if(!inp.files.length) return;
    const fd=new FormData();
    fd.append('id',AB.id); fd.append('csrf_token',AB.csrf);
    fd.append('bucket',document.getElementById('ab-upload-bucket').value);
    fd.append('overwrite',document.getElementById('ab-upload-overwrite').checked?'1':'0');
    for(const f of inp.files) fd.append('files[]',f);
    const btn=this.querySelector('button[type=submit]'); btn.disabled=true;
    const msg=document.getElementById('ab-upload-msg'); msg.className='form-text text-body-secondary'; msg.textContent='Uploading…';
    fetch('/aibuilder/upload',{method:'POST',headers:{'X-CSRF-TOKEN':AB.csrf,'X-Requested-With':'XMLHttpRequest'},body:fd}).then(r=>r.json()).then(j=>{
      if(j.success){ const errs=(j.data&&j.data.errors)||[]; msg.className='form-text '+(errs.length?'text-warning':'text-success');
        msg.textContent=(j.message||'Uploaded')+(errs.length?(' · '+errs.join('; ')):''); inp.value=''; loadUploads(); refreshChanges(); }
      else { msg.className='form-text text-danger'; msg.textContent=j.message||'Upload failed.'; }
    }).catch(()=>{ msg.className='form-text text-danger'; msg.textContent='Network error.'; }).finally(()=>btn.disabled=false);
  });

  // --- Restart session (kills the jailed tmux server, then reloads for a fresh jail) ---
  const restartBtn=document.getElementById('ab-restart');
  if(restartBtn) restartBtn.addEventListener('click',function(){
    if(!confirm('Restart this instance’s session? Anything running will stop and a fresh sandbox starts.')) return;
    this.disabled=true; setStatus('restarting…');
    post('/aibuilder/restart',{}).then(()=>{ setTimeout(()=>location.reload(), 700); })
      .catch(()=>{ this.disabled=false; setStatus('restart failed'); alert('Restart failed.'); });
  });

  // --- Browser-test prompt (agent uses the playwright MCP to verify its layout) ---
  const testBtn=document.getElementById('ab-test');
  if(testBtn) testBtn.addEventListener('click',function(){
    const url=AB.url||('https://'+location.host);
    const prompt='Use the playwright MCP to open '+url+' — take a screenshot and a page snapshot, then verify the layout renders correctly '
      +'(no overflow, elements aligned, works at mobile ~375px and desktop widths). List any issues you find, fix them, and re-test until the page is clean.';
    const m=document.getElementById('ab-test-msg');
    if(navigator.clipboard) navigator.clipboard.writeText(prompt).then(()=>{ m.textContent='Copied — paste into the terminal agent.'; setTimeout(()=>m.textContent='',3500); })
      .catch(()=>{ m.textContent='Copy failed.'; });
    else m.textContent='Clipboard unavailable.';
  });

  // --- Danger-zone: delete instance -----------------------------------------
  (function(){
    const btn = document.getElementById('ab-delete');
    const modalEl = document.getElementById('ab-delete-modal');
    if (!btn || !modalEl) return;
    const domain = (AB.url||'').replace(/^https?:\/\//,'').replace(/\/$/,'');
    const input = document.getElementById('ab-del-input');
    const confirmBtn = document.getElementById('ab-del-confirm');
    const msg = document.getElementById('ab-del-msg');
    document.getElementById('ab-del-domain').textContent = domain;
    document.getElementById('ab-del-domain2').textContent = domain;
    input.placeholder = domain;
    btn.addEventListener('click', function(){
      input.value=''; msg.textContent=''; confirmBtn.disabled=true;
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });
    input.addEventListener('input', function(){ confirmBtn.disabled = (input.value.trim() !== domain); });
    confirmBtn.addEventListener('click', function(){
      confirmBtn.disabled = true; msg.textContent = 'Deleting…';
      fetch('/aibuilder/delete', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded','X-CSRF-TOKEN':AB.csrf,'X-Requested-With':'XMLHttpRequest'},
        body:new URLSearchParams({id:AB.id, confirm:input.value.trim(), csrf_token:AB.csrf}).toString()
      }).then(r=>r.json()).then(j=>{
        if (j.success) { window.location = '/aibuilder'; }
        else { msg.textContent = j.message || 'Delete failed.'; confirmBtn.disabled = false; }
      }).catch(()=>{ msg.textContent = 'Network error.'; confirmBtn.disabled = false; });
    });
  })();

  // --- Claude sign-in gate: lock the terminal until this instance is connected -----
  // In the jail, `claude` prints its hosted sign-in URL + a "Paste code here" prompt
  // right in the terminal. We poll oauthstatus (which reads that URL off the agent's
  // screen), lock the terminal behind a gate, and when the operator approves + brings
  // back the code Anthropic shows them, we type it straight into that prompt over the
  // PTY websocket. No localhost, no server forward — Claude completes it itself.
  (function(){
    const gate=document.getElementById('ab-oauth-gate'); if(!gate) return;
    const openA=document.getElementById('ab-oauth-open');
    const codeI=document.getElementById('ab-oauth-code');
    const subBtn=document.getElementById('ab-oauth-submit');
    const msg=document.getElementById('ab-oauth-msg');
    let curUrl='', busy=false;
    const show=()=>gate.classList.remove('d-none');
    const hide=()=>gate.classList.add('d-none');
    const setMsg=(cls,txt)=>{ msg.className='ab-oauth-msg '+cls; msg.textContent=txt; };
    codeI.addEventListener('input',()=>{ subBtn.disabled = codeI.value.trim()===''; });
    codeI.addEventListener('keydown',e=>{ if(e.key==='Enter' && codeI.value.trim()) connect(); });

    function present(url){
      if(url!==curUrl){ curUrl=url; openA.href=url; codeI.value=''; subBtn.disabled=true; setMsg('',''); }
      show();
    }
    function connect(){
      const code=codeI.value.trim(); if(!code || busy) return;
      if(!(termWs && termWs.readyState===WebSocket.OPEN)){ setMsg('text-danger','Terminal not connected — reload the page and try again.'); return; }
      busy=true; subBtn.disabled=true; setMsg('text-body-secondary','Submitting to Claude…');
      // Claude is sitting at its "Paste code here" prompt — type the code in, then Enter.
      termWs.send(JSON.stringify({type:'input',data:code+'\r'}));
      // Let Claude exchange it; the poll drops the gate once the sign-in screen is gone.
      setTimeout(()=>{
        busy=false;
        if(!gate.classList.contains('d-none'))
          setMsg('text-body-secondary','Waiting for Claude to finish… if it didn’t take, re-copy the code and Connect again.');
      }, 3000);
    }
    subBtn.addEventListener('click',connect);

    setInterval(()=>{
      if(busy) return;
      fetch('/aibuilder/oauthstatus?id='+AB.id,{headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json()).then(j=>{
          const d=(j&&j.data)||{};
          if(d.pending && d.url) present(d.url);
          else { curUrl=''; if(!gate.classList.contains('d-none')) hide(); }
        }).catch(()=>{});
    }, 2500);
  })();

  // --- Reuse digest preview (what the planner is grounded on) ---
  function openReuseDigest(){
    if(!AB.id) return;
    const modalEl=document.getElementById('ab-reuse-modal');
    const body=document.getElementById('ab-reuse-body');
    const slug=document.getElementById('ab-reuse-slug');
    if(!modalEl||!body) return;
    body.textContent='Loading…'; if(slug) slug.textContent='';
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    fetch('/aibuilder/reusedigest?id='+AB.id,{headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(r=>r.json()).then(j=>{
        const d=(j&&j.data)||{};
        if(j&&j.success&&d.digest){ body.textContent=d.digest; if(slug) slug.textContent=d.slug||''; }
        else { body.textContent='Could not load digest: '+((j&&j.message)||'unknown error'); }
      }).catch(e=>{ body.textContent='Request failed: '+e; });
  }
  const rdBtn=document.getElementById('ab-reuse-digest');
  if(rdBtn) rdBtn.addEventListener('click', openReuseDigest);
  const rdLink=document.getElementById('ab-reuse-digest-link');
  if(rdLink) rdLink.addEventListener('click', e=>{ e.preventDefault(); openReuseDigest(); });
  const rdCopy=document.getElementById('ab-reuse-copy');
  if(rdCopy) rdCopy.addEventListener('click', ()=>{
    const t=document.getElementById('ab-reuse-body'); if(!t) return;
    navigator.clipboard.writeText(t.textContent||'').then(()=>{ rdCopy.innerHTML='<i class="bi bi-check2 me-1"></i>Copied'; setTimeout(()=>{ rdCopy.innerHTML='<i class="bi bi-clipboard me-1"></i>Copy'; },1500); });
  });

  // init
  setStatus('connecting…'); initTerminal(); refreshChanges(); loadCheckpoints(); loadGhStatus(); loadUploads();
  setInterval(refreshChanges, 4000);

}
</script>
