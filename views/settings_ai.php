<?php
/**
 * Settings → AI assistant card (part of the settings form). When .env turns the
 * assistant off, only a note says so.
 *
 * @var bool $allowed
 * @var array $ai
 * @var bool $keySaved
 * @var array $errors
 */

use DeployTool\Ai;

$hasError = static fn (string $field): string => isset($errors[$field]) ? ' has-error' : '';
$error = static fn (string $field): string => isset($errors[$field]) ? '<p class="error-text">' . e($errors[$field]) . '</p>' : '';
?>
<?php if (!$allowed): ?>
    <section class="card settings-card ai-card">
        <div class="settings-card-head">
            <h2>AI assistant</h2>
            <span class="pill">Turned off in .env</span>
        </div>
        <div class="ai-off">
            <p>The assistant is switched off for this computer by <code>DEPLOY_TOOL_AI=false</code>. There's no Assistant page, and the tool never contacts an AI provider. Saved AI details stay untouched.</p>
            <p class="meta">To use it again, set <code>DEPLOY_TOOL_AI=true</code> in the .env file in the tool's folder, then reload this page.</p>
        </div>
    </section>
<?php else: ?>
    <section class="card settings-card ai-card js-ai-card">
        <div class="settings-card-head">
            <h2>AI assistant</h2>
            <span class="pill<?= $ai['enabled'] === '1' ? ' pill-success' : '' ?>"><?= $ai['enabled'] === '1' ? 'On' : 'Off' ?></span>
        </div>
        <p class="meta">A chat bot that compares, prepares deploys and explains changes for you. Approving deploys and rollbacks always stays with you.</p>

        <div class="ai-grid">
            <div>
                <input type="hidden" name="ai_enabled" value="0">
                <label class="choice-tile ai-toggle">
                    <input type="checkbox" name="ai_enabled" value="1"<?= $ai['enabled'] === '1' ? ' checked' : '' ?>>
                    <strong>Enable AI assistant</strong>
                    <span>Off hides the assistant and blocks it, but keeps the details here.</span>
                </label>

                <p class="help ai-perm-link">What it may do is set in <a href="index.php?r=settings&amp;tab=permissions" class="js-settings-goto" data-tab="permissions">AI permissions</a>.</p>

                <fieldset class="field<?= $hasError('ai_provider') ?>">
                    <legend>Provider</legend>
                    <div class="segmented">
                        <?php foreach (Ai::PRESETS as $key => [$label, $base, , $needsKey]): ?>
                            <label><input type="radio" name="ai_provider" value="<?= e($key) ?>" class="js-ai-provider" data-base="<?= e($base) ?>" data-key="<?= $needsKey ? '1' : '0' ?>"<?= $ai['provider'] === $key ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                        <?php endforeach ?>
                    </div>
                    <p class="help">A preset fills in the base URL. Custom works with any OpenAI-style API.</p>
                    <?= $error('ai_provider') ?>
                </fieldset>

                <div class="field<?= $hasError('ai_base_url') ?>">
                    <label for="ai_base_url">Base URL</label>
                    <input type="text" id="ai_base_url" name="ai_base_url" value="<?= e($ai['base_url']) ?>" class="mono-input" spellcheck="false" placeholder="https://…/v1">
                    <?= $error('ai_base_url') ?>
                </div>

                <div class="field<?= $hasError('ai_api_key') ?>">
                    <label for="ai_api_key">API key</label>
                    <input type="password" id="ai_api_key" name="ai_api_key" value="" autocomplete="new-password" placeholder="<?= $keySaved ? 'Saved. Blank keeps it.' : '' ?>">
                    <p class="help js-ai-key-help">Kept only on this computer in storage/ai.json.<span class="js-ai-nokey"> Not needed for this provider.</span></p>
                    <?= $error('ai_api_key') ?>
                </div>
            </div>

            <div>
                <div class="field<?= $hasError('ai_model') ?>">
                    <label for="ai_model">Model</label>
                    <div class="input-button">
                        <input type="text" id="ai_model" name="ai_model" value="<?= e($ai['model']) ?>" list="ai-model-list" class="mono-input" spellcheck="false" placeholder="for example anthropic/claude-sonnet-5-5">
                        <button type="button" class="js-ai-models" data-busy="Loading…">Load models</button>
                    </div>
                    <datalist id="ai-model-list"></datalist>
                    <p class="help js-ai-models-status">Type a model name, or load the provider's list and pick one. It must support tool calling.</p>
                    <?= $error('ai_model') ?>
                </div>

                <div class="field">
                    <div class="ai-test">
                        <button type="button" class="js-ai-test" data-busy="Testing…">Test</button>
                        <div class="ai-test-result js-ai-test-result" aria-live="polite">
                            <span class="meta">Tests the details above, saved or not, with one small request.</span>
                        </div>
                    </div>
                </div>

                <div class="field-row ai-limits">
                    <div class="field<?= $hasError('ai_max_steps') ?>">
                        <label for="ai_max_steps">Steps per message</label>
                        <input type="number" id="ai_max_steps" name="ai_max_steps" min="1" max="20" value="<?= e($ai['max_steps']) ?>">
                        <p class="help">Most actions it may run for one message.</p>
                        <?= $error('ai_max_steps') ?>
                    </div>
                    <div class="field<?= $hasError('ai_max_messages') ?>">
                        <label for="ai_max_messages">Messages kept per chat</label>
                        <input type="number" id="ai_max_messages" name="ai_max_messages" min="10" max="200" value="<?= e($ai['max_messages']) ?>">
                        <p class="help">Older ones aren't re-sent, which keeps cost down.</p>
                        <?= $error('ai_max_messages') ?>
                    </div>
                </div>
            </div>
        </div>

        <p class="meta ai-env-note">To remove the assistant for good, set <code>DEPLOY_TOOL_AI=false</code> in the .env file in the tool's folder (copy .env.example to start one).</p>
    </section>
<?php endif ?>
