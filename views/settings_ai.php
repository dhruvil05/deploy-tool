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

// The line under each provider's name on its card.
$providerNotes = [
    'openrouter' => 'Many models, one key',
    'openai' => 'GPT models',
    'anthropic' => 'Claude models',
    'groq' => 'Fast open models',
    'ollama' => 'On this computer · free',
    'lmstudio' => 'On this computer · free',
    'custom' => 'Any OpenAI-style API',
];
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

        <input type="hidden" name="ai_enabled" value="0">
        <label class="set-toggle">
            <span class="set-toggle-text">
                <strong>Enable AI assistant</strong>
                <span>Off hides the assistant and blocks it, but keeps the details below. What it may do is set in <a href="index.php?r=settings&amp;tab=permissions" class="js-settings-goto" data-tab="permissions">AI permissions</a>.</span>
            </span>
            <input type="checkbox" class="switch" name="ai_enabled" value="1" role="switch"<?= $ai['enabled'] === '1' ? ' checked' : '' ?>>
        </label>

        <section class="set-section">
            <div class="set-section-head">
                <h3>Provider</h3>
                <p class="meta">A preset fills in the base URL. Custom works with any OpenAI-style API.</p>
            </div>
            <fieldset class="field<?= $hasError('ai_provider') ?>">
                <legend class="sr-only">Provider</legend>
                <div class="provider-grid">
                    <?php foreach (Ai::PRESETS as $key => [$label, $base, , $needsKey]): ?>
                        <label class="provider-card">
                            <input type="radio" name="ai_provider" value="<?= e($key) ?>" class="js-ai-provider" data-base="<?= e($base) ?>" data-key="<?= $needsKey ? '1' : '0' ?>"<?= $ai['provider'] === $key ? ' checked' : '' ?>>
                            <span class="provider-mark" aria-hidden="true"><?= e(mb_substr($label, 0, 1)) ?></span>
                            <span class="provider-text">
                                <strong><?= e($label) ?></strong>
                                <span><?= e($providerNotes[$key] ?? '') ?></span>
                            </span>
                        </label>
                    <?php endforeach ?>
                </div>
                <?= $error('ai_provider') ?>
            </fieldset>
        </section>

        <section class="set-section">
            <div class="set-section-head">
                <h3>Connection</h3>
                <p class="meta">Where the provider answers, and your key for it.</p>
            </div>
            <div class="set-pair">
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
        </section>

        <section class="set-section">
            <div class="set-section-head">
                <h3>Model</h3>
                <p class="meta">It must support tool calling. Test checks that with one small request.</p>
            </div>
            <div class="field<?= $hasError('ai_model') ?>">
                <label for="ai_model" class="sr-only">Model</label>
                <div class="input-button">
                    <input type="text" id="ai_model" name="ai_model" value="<?= e($ai['model']) ?>" list="ai-model-list" class="mono-input" spellcheck="false" placeholder="for example anthropic/claude-sonnet-5-5">
                    <button type="button" class="js-ai-models" data-busy="Loading…">Load models</button>
                    <button type="button" class="js-ai-test" data-busy="Testing…">Test</button>
                </div>
                <datalist id="ai-model-list"></datalist>
                <p class="help js-ai-models-status">Type a model name, or load the provider's list and pick one. Test uses the details on this page, saved or not.</p>
                <?php if (preg_match('#(^openrouter/(auto|free)$|:free$)#i', $ai['model'])): ?>
                    <p class="warning-text">A free or router model changes from message to message and often times out or cuts answers short. For dependable tool use, pick one fixed model, or at least set a backup below.</p>
                <?php endif ?>
                <?= $error('ai_model') ?>
            </div>
            <div class="field<?= $hasError('ai_fallback_model') ?>">
                <label for="ai_fallback_model">Backup model <span class="meta">optional · speed-up</span></label>
                <input type="text" id="ai_fallback_model" name="ai_fallback_model" value="<?= e($ai['fallback_model']) ?>" list="ai-model-list" class="mono-input" spellcheck="false" placeholder="blank: no backup">
                <p class="help">When the model above times out or fails, that step is tried once more with this one, so a chat doesn't stop on a busy provider. Same provider and key. Blank switches it off.</p>
                <?= $error('ai_fallback_model') ?>
            </div>
            <div class="ai-test-result js-ai-test-result" aria-live="polite"></div>
        </section>

        <section class="set-section">
            <div class="set-section-head">
                <h3>Limits</h3>
                <p class="meta">Each message is a few requests to the provider; these cap the cost.</p>
            </div>
            <div class="set-pair ai-limits">
                <div class="field<?= $hasError('ai_max_steps') ?>">
                    <label for="ai_max_steps">Steps per message</label>
                    <input type="number" id="ai_max_steps" name="ai_max_steps" min="1" max="20" value="<?= e($ai['max_steps']) ?>">
                    <p class="help">Most actions it may run for one message (1 to 20).</p>
                    <?= $error('ai_max_steps') ?>
                </div>
                <div class="field<?= $hasError('ai_max_messages') ?>">
                    <label for="ai_max_messages">Messages kept per chat</label>
                    <input type="number" id="ai_max_messages" name="ai_max_messages" min="10" max="200" value="<?= e($ai['max_messages']) ?>">
                    <p class="help">Older ones aren't re-sent, which keeps cost down (10 to 200).</p>
                    <?= $error('ai_max_messages') ?>
                </div>
            </div>
        </section>

        <section class="set-section">
            <div class="set-section-head">
                <h3>Your instructions</h3>
                <p class="meta">House rules in plain words, added to every chat. They can't unlock anything AI permissions don't allow.</p>
            </div>
            <div class="field ai-instructions<?= $hasError('ai_instructions') ?>">
                <label for="ai_instructions" class="sr-only">Your instructions</label>
                <textarea id="ai_instructions" name="ai_instructions" rows="6" placeholder="For example:&#10;- When I say untick, only change the ticks. Never start an upload unless I say upload or deploy.&#10;- Always list the files and ask before preparing anything.&#10;- Answer in short bullet points."><?= e($ai['instructions']) ?></textarea>
                <?= $error('ai_instructions') ?>
            </div>
        </section>

        <p class="meta ai-env-note">To remove the assistant for good, set <code>DEPLOY_TOOL_AI=false</code> in the .env file in the tool's folder (copy .env.example to start one).</p>
    </section>
<?php endif ?>
