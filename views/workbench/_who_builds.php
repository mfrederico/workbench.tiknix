<?php
/* "Who does the building?" — the app's own agents for a project in its own container, else an
   engine+model pair on the member's credentials. Shared by the create and edit forms.
   Needs: $builderAgent (the agent picked, '' = the app's default), $builderRun (the
   engine:model value selected), and the controller's $appAgents / $appAgentsError /
   $tenantAgentsUrl or $runChoices / $engineAuth / $defaultRunChoice. */
?>
                        <?php /* Which agent builds this — ONE choice, not two.
                                 An engine list plus a model list lets someone pick z.ai with
                                 opus: syntactically fine, meaningless to the provider, and it
                                 fails at run time as an unhelpful API error. The pair is the
                                 unit, and EngineRegistry::runMenu() only offers pairs that
                                 exist on an engine that is actually available. */ ?>
                        <?php if (isset($appAgents) || isset($appAgentsError)): ?>
                        <?php /* A project in its own container builds on ITS agents — the ones on
                                 its AI agents page — not on an engine and your credentials. */ ?>
                        <div class="mb-4">
                            <label for="agent" class="form-label">Who does the building?</label>
                            <?php if (isset($appAgentsError)): ?>
                                <div class="alert alert-danger py-2 small mb-0">We couldn't reach the app to ask about its agents: <?= htmlspecialchars($appAgentsError) ?></div>
                            <?php else:
                                $__claude = (string) ($appAgents['claude']['in_use'] ?? '');
                                $__def = null;
                                foreach ($appAgents['agents'] as $__a) if (!empty($__a['is_default'])) $__def = $__a;
                                $__why = function (array $a) use ($__claude): string {
                                    if (empty($a['builder'])) return 'text only — cannot build';
                                    if (!empty($a['problems'])) return implode('; ', $a['problems']);
                                    if (($a['endpoint'] ?? '') === '' && ($a['key_status'] ?? '') !== 'set' && $__claude === '') return 'default model not set up';
                                    return '';
                                };
                                $__where = fn(array $a) => ($a['endpoint'] ?? '') !== '' ? (parse_url($a['endpoint'], PHP_URL_HOST) ?: $a['endpoint']) : 'default model';
                            ?>
                            <select class="form-select" id="agent" name="agent">
                                <?php $__dw = $__def ? $__why($__def) : ($__claude === '' ? 'default model not set up' : ''); ?>
                                <option value="" <?= $__dw !== '' ? 'disabled' : ($builderAgent === '' ? 'selected' : '') ?>>
                                    App default — <?= htmlspecialchars($__def ? (($__def['display_name'] ?? '') !== '' ? $__def['display_name'] : $__def['name']) . ' (' . $__where($__def) . ')' : 'default model' . ($__claude !== '' ? ' (' . $__claude . ')' : '')) ?><?= $__dw !== '' ? ' — ' . htmlspecialchars($__dw) : '' ?>
                                </option>
                                <?php foreach ($appAgents['agents'] as $__a): $__w = $__why($__a); ?>
                                    <option value="<?= htmlspecialchars($__a['name']) ?>" <?= $__w !== '' ? 'disabled' : '' ?> <?= $builderAgent === $__a['name'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((($__a['display_name'] ?? '') !== '' ? $__a['display_name'] . ' (' . $__a['name'] . ')' : $__a['name']) . (($__a['description'] ?? '') !== '' ? ': ' . $__a['description'] : '') . ' — ' . $__where($__a) . (($__a['model'] ?? '') !== '' ? ' / ' . $__a['model'] : '')) ?><?= $__w !== '' ? ' — ' . htmlspecialchars($__w) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php endif; ?>
                            <div class="form-text">
                                The agent (and model) that plans and builds this, on the app's own keys. Leave it on the default unless you have a reason.
                                <a href="<?= htmlspecialchars($tenantAgentsUrl) ?>" target="_blank" rel="noopener">Manage agents</a>
                            </div>
                        </div>
                        <?php elseif (!empty($runChoices)): ?>
                        <div class="mb-4">
                            <label for="run_with" class="form-label">Who does the building?</label>
                            <input type="hidden" name="run_with_default" value="<?= htmlspecialchars($defaultRunChoice ?? '') ?>">
                            <select class="form-select" id="run_with" name="run_with">
                                <?php foreach ($runChoices as $c):
                                    /* Marked, not hidden. A member who has no credentials for an
                                       engine still needs to see it exists — hiding it makes the
                                       menu look like the engine was never offered. */
                                    $usable = ($engineAuth[$c['engine']] ?? true); ?>
                                    <option value="<?= htmlspecialchars($c['value']) ?>"
                                        <?= ($c['value'] === $builderRun) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['label']) ?><?= $usable ? '' : ' — no credentials' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">
                                Starts on this project's usual engine. It runs on YOUR sign-in for whichever
                                you pick, so one marked <em>no credentials</em> can't run until you sign in
                                to it (or add its API key in Settings).
                            </div>
                        </div>
                        <?php endif; ?>

