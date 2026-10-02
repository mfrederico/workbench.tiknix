<?php
/* "Who is it for?" and "How will you know it worked?" — the two answers that are written INTO
   the goal the agent reads (app\GoalBrief). Shared by the create and edit forms.
   Needs: $briefAudience ('' = not answered yet), $briefAcceptance, and $briefNoneLabel
   [name, explanation] for the option that writes nothing about access. */
?>
                        <?php /* WHO IS IT FOR — asked in people, not in numbers, and never pre-answered:
                                 the old select defaulted to the builder's own level, so an admin's
                                 new pages were quietly admin-only (or root-only). The answer is
                                 written into the goal (app\GoalBrief), where the planner reads it. */
                        $__who = [
                            'anyone'  => ['bi-globe2',      'Anyone',          'Visitors can see it without signing in — a landing page, a booking form, a menu.'],
                            'members' => ['bi-person-check', 'Signed-in members', 'People with an account, once they are logged in — their orders, their profile.'],
                            'admins'  => ['bi-shield-lock',  'Admins only',     'You and your staff. Customers never see it — reports, settings, the back office.'],
                        ]; ?>
                        <fieldset class="mb-4">
                            <legend class="form-label fs-6 mb-1">Who is it for? <span class="text-danger">*</span></legend>
                            <div class="form-text mt-0 mb-2">This decides who can open the new pages. If it's a mix (a public form with an admin list behind it), pick the main one and say the rest in your description.</div>
                            <div class="row g-2">
                                <?php foreach ($__who as $__k => [$__icon, $__name, $__blurb]): ?>
                                <div class="col-md-4">
                                    <label class="wb-card d-flex gap-2 p-3 border rounded bg-body-tertiary h-100">
                                        <input class="form-check-input flex-shrink-0 mt-1" type="radio" name="audience" value="<?= $__k ?>" required <?= $briefAudience === $__k ? 'checked' : '' ?>>
                                        <span>
                                            <span class="wb-card-title d-block"><i class="bi <?= $__icon ?> me-1"></i><?= $__name ?></span>
                                            <span class="form-text d-block mb-0"><?= $__blurb ?></span>
                                        </span>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <label class="wb-card d-flex gap-2 px-3 py-2 mt-2 border rounded bg-body-tertiary">
                                <input class="form-check-input flex-shrink-0 mt-1" type="radio" name="audience" value="none" required <?= $briefAudience === 'none' ? 'checked' : '' ?>>
                                <span class="form-text mb-0 mt-0"><span class="wb-card-title text-body"><?= htmlspecialchars($briefNoneLabel[0]) ?></span> — <?= htmlspecialchars($briefNoneLabel[1]) ?></span>
                            </label>
                        </fieldset>

                        <!-- Acceptance Criteria -->
                        <div class="mb-4">
                            <label for="acceptance_criteria" class="form-label">How will you know it worked? <span class="text-body-secondary fw-normal">(optional, but worth a line)</span></label>
                            <textarea class="form-control" id="acceptance_criteria" name="acceptance_criteria" rows="3"
                                      placeholder="A member only sees their own invoices.&#10;The booking form won't take a date in the past."><?= htmlspecialchars($briefAcceptance) ?></textarea>
                            <div class="form-text">The things you'd check yourself. The work has to cover every one of them.</div>
                        </div>

