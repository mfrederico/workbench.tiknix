<?php
/**
 * Import from monday.com — pick a board, tick the items to build.
 *
 * Only reachable when the selected project has an active monday connection; the
 * controller redirects otherwise, so nothing here has to consider the empty case.
 *
 * Two flags do the work. `imported` marks an item that is already a task here,
 * so ticking a whole board twice is visibly pointless rather than silently
 * ignored. `done` marks a CLOSED item — done or cancelled (MondayImport::isClosed
 * decides, so this page never has to know which word a board uses). On a real
 * board that is a good share of it, and importing finished or abandoned work to
 * build it again is the mistake this page exists to make hard.
 *
 * Closed items are skipped by the bulk select and still tickable one at a time.
 * Only `imported` disables the box outright: re-importing is the one thing that
 * cannot be what somebody meant.
 *
 * Vars: $boards, $items, $boardId, $cursor, $account, $error, $csrf, $selected
 */
?>
<div class="container-fluid py-3">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h1 class="h4 mb-1">Import from monday.com</h1>
      <div class="text-body-secondary small">
        <?= htmlspecialchars($account ?: 'monday.com') ?>
        <?php if (!empty($selected['slug'])): ?>
          &middot; into <span class="font-monospace"><?= htmlspecialchars($selected['slug']) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <a href="/workbench" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left"></i> Task board
    </a>
  </div>

  <?php if (!empty($error)): ?>
    <?php /* monday's own wording — "Complexity budget exhausted, reset in 45
             seconds" and "Not authenticated" want different reactions, and a
             friendlier generic message would lose that. */ ?>
    <div class="alert alert-danger">
      <i class="bi bi-exclamation-triangle me-1"></i>
      <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <!-- board picker -->
  <form method="GET" action="/workbench/monday" class="row g-2 align-items-end mb-4">
    <div class="col-md-7">
      <label for="board" class="form-label small text-body-secondary mb-1">Board</label>
      <select name="board" id="board" class="form-select" onchange="this.form.submit()">
        <option value="">Choose a board…</option>
        <?php foreach (($boards ?? []) as $b): ?>
          <option value="<?= htmlspecialchars($b['id']) ?>"
                  <?= (string) $b['id'] === (string) $boardId ? 'selected' : '' ?>>
            <?= htmlspecialchars($b['name']) ?>
            (<?= (int) $b['items_count'] ?> items<?= $b['workspace'] !== '' ? ' · ' . htmlspecialchars($b['workspace']) : '' ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <noscript><button class="btn btn-outline-primary">Load items</button></noscript>
    </div>
  </form>

  <?php if ($boardId === ''): ?>
    <p class="text-body-secondary">Pick a board to see what is on it.</p>

  <?php elseif (empty($items)): ?>
    <p class="text-body-secondary">
      <?= empty($error) ? 'That board has no items.' : 'Nothing could be loaded.' ?>
    </p>

  <?php else: ?>
    <form method="POST" action="/workbench/mondayimport">
      <?php foreach ($csrf as $name => $value): ?>
        <input type="hidden" name="<?= $name ?>" value="<?= $value ?>">
      <?php endforeach; ?>
      <input type="hidden" name="board" value="<?= htmlspecialchars($boardId) ?>">

      <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
          <span><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></span>
          <button type="button" class="btn btn-link btn-sm p-0" id="tickBuildable">
            Select everything still open
          </button>
        </div>

        <?php
          /* Grouped, because on these boards a group is one site's work — Murray
             Website, Parts Website, Massport Website — and "import that site" is the
             thing somebody actually wants. Ungrouped items keep their own bucket
             rather than being folded into the first group. */
          $byGroup = [];
          foreach ($items as $it) { $byGroup[(string) ($it['group'] ?? '')][] = $it; }
        ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($byGroup as $groupName => $groupItems): ?>
            <?php
              $openInGroup = 0;
              foreach ($groupItems as $gi) if (empty($gi['done']) && empty($gi['imported'])) $openInGroup++;
            ?>
            <li class="list-group-item bg-body-secondary py-1 d-flex align-items-center justify-content-between">
              <span class="small fw-semibold">
                <?= $groupName !== '' ? htmlspecialchars($groupName) : '<span class="text-body-secondary">No group</span>' ?>
                <span class="text-body-secondary fw-normal">
                  · <?= count($groupItems) ?> item<?= count($groupItems) === 1 ? '' : 's' ?><?php
                    if ($openInGroup !== count($groupItems)) echo ', ' . $openInGroup . ' open'; ?>
                </span>
              </span>
              <?php if ($openInGroup > 0): ?>
                <button type="button" class="btn btn-link btn-sm p-0 wb-group-tick"
                        data-group="<?= htmlspecialchars($groupName) ?>">
                  Select the <?= $openInGroup ?> open here
                </button>
              <?php endif; ?>
            </li>
          <?php foreach ($groupItems as $it): ?>
            <?php $blocked = !empty($it['imported']); ?>
            <li class="list-group-item">
              <div class="form-check d-flex align-items-start gap-2">
                <input class="form-check-input mt-1 wb-mi"
                       type="checkbox"
                       name="items[]"
                       value="<?= htmlspecialchars($it['id']) ?>"
                       id="mi<?= htmlspecialchars($it['id']) ?>"
                       data-done="<?= !empty($it['done']) ? '1' : '0' ?>"
                       data-group="<?= htmlspecialchars((string) ($it['group'] ?? '')) ?>"
                       data-imported="<?= $blocked ? '1' : '0' ?>"
                       <?= $blocked ? 'disabled' : '' ?>>
                <label class="form-check-label flex-grow-1" for="mi<?= htmlspecialchars($it['id']) ?>">
                  <span class="<?= $blocked ? 'text-body-secondary' : '' ?>">
                    <?= htmlspecialchars($it['name']) ?>
                  </span>

                  <?php if (!empty($it['group'])): ?>
                    <span class="badge text-bg-light border ms-1"><?= htmlspecialchars($it['group']) ?></span>
                  <?php endif; ?>

                  <?php if (!empty($it['done'])): ?>
                    <?php /* Says WHICH closed status, because "Done in monday" on a
                             cancelled item is a wrong answer that looks like a right
                             one — and done and cancelled deserve different reactions. */ ?>
                    <?php
                      $isCancelled = stripos((string) ($it['status'] ?? ''), 'cancel') !== false;
                      // Archived outranks the status text: an archived item with a
                      // blank Status would otherwise fall back to reading "Done".
                      $closedLabel = !empty($it['archived'])
                          ? 'Archived'
                          : ((($it['status'] ?? '') !== '') ? $it['status'] : 'Done');
                    ?>
                    <span class="badge ms-1 border <?= $isCancelled
                          ? 'text-bg-warning-subtle text-warning-emphasis border-warning-subtle'
                          : 'text-bg-success-subtle text-success-emphasis border-success-subtle' ?>">
                      <?= htmlspecialchars($closedLabel) ?> in monday
                    </span>
                  <?php endif; ?>

                  <?php if ($blocked): ?>
                    <span class="badge text-bg-secondary ms-1">Already imported</span>
                    <?php if (!empty($it['task_id'])): ?>
                      <a class="small ms-1" href="/workbench/view?id=<?= (int) $it['task_id'] ?>">view task</a>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php
                    // Subitems are the breakdown monday already has. Counted here
                    // (open ones only) so the choice is informed: ticking this item
                    // brings its open subitems in as child tasks, and the planner is
                    // not asked to invent a decomposition alongside them.
                    $openSubs = 0;
                    foreach (($it['subitems'] ?? []) as $sub) {
                        if (!empty($sub['closed'])) continue;
                        $openSubs++;
                    }
                  ?>
                  <?php if ($openSubs > 0): ?>
                    <span class="badge text-bg-info-subtle text-info-emphasis border border-info-subtle ms-1"
                          title="Imported as child tasks. Closed subitems are skipped.">
                      <i class="bi bi-diagram-3"></i> <?= $openSubs ?> subitem<?= $openSubs === 1 ? '' : 's' ?>
                    </span>
                  <?php endif; ?>

                  <?php if (!empty($it['fields'])): ?>
                    <div class="small text-body-secondary mt-1">
                      <?php
                        // Only the few that say something about the work. The rest is
                        // board bookkeeping and would bury these.
                        $show = [];
                        foreach (['Status', 'Priority', 'Due Date', 'Owner', 'Estimated Hours'] as $k) {
                            if (!empty($it['fields'][$k])) $show[] = $k . ': ' . $it['fields'][$k];
                        }
                        echo htmlspecialchars(implode('  ·  ', $show));
                      ?>
                    </div>
                  <?php endif; ?>
                </label>
              </div>
            </li>
          <?php endforeach; ?>
          <?php endforeach; ?>
        </ul>

        <div class="card-footer d-flex align-items-center justify-content-between">
          <div class="small text-body-secondary">
            Each becomes one task. Decompose it from the board when you are ready to build.
          </div>
          <div class="d-flex gap-2">
            <?php if (!empty($cursor)): ?>
              <a class="btn btn-outline-secondary btn-sm"
                 href="/workbench/monday?board=<?= urlencode($boardId) ?>&cursor=<?= urlencode($cursor) ?>">
                Next page
              </a>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" id="importBtn" disabled>
              Import selected
            </button>
          </div>
        </div>
      </div>
    </form>

    <script>
    (function () {
        var boxes  = Array.prototype.slice.call(document.querySelectorAll('.wb-mi'));
        var btn    = document.getElementById('importBtn');
        var tickAll= document.getElementById('tickBuildable');

        // Disabled until something is ticked: a button that submits nothing and
        // returns "Nothing selected" is a round trip to say what the page knew.
        function sync() {
            var n = boxes.filter(function (b) { return b.checked && !b.disabled; }).length;
            btn.disabled = n === 0;
            btn.textContent = n === 0 ? 'Import selected'
                            : 'Import ' + n + ' item' + (n === 1 ? '' : 's');
        }

        boxes.forEach(function (b) { b.addEventListener('change', sync); });

        // Per-group select. Same rule as the global one: open work only, so
        // ticking a group never quietly re-imports its finished half.
        Array.prototype.slice.call(document.querySelectorAll('.wb-group-tick'))
            .forEach(function (link) {
                link.addEventListener('click', function () {
                    var g = link.dataset.group;
                    boxes.forEach(function (b) {
                        if (b.disabled || b.dataset.group !== g) return;
                        if (b.dataset.done !== '1') b.checked = true;
                    });
                    sync();
                });
            });

        // Skips done and already-imported ones — the whole point of the flags.
        tickAll.addEventListener('click', function () {
            boxes.forEach(function (b) {
                if (b.disabled) return;
                b.checked = b.dataset.done !== '1';
            });
            sync();
        });

        sync();
    })();
    </script>
  <?php endif; ?>
</div>
