<?php
/**
 * The Assistant page: chat list on the left, the conversation on the right.
 *
 * @var bool $enabled
 * @var array $settings Ai::settings()
 * @var array $chats
 * @var ?array $chat the open chat, null for a new one
 * @var array $sources
 * @var string $local
 */

use DeployTool\Ai;
use DeployTool\Ai\ChatStore;
use DeployTool\Http;
use DeployTool\View;

$suggestions = ['What changed since the last deploy?', 'Is staging up to date?', 'Compare staging with its commit', 'Summarise this week\'s history'];
?>
<?php if (!$enabled): ?>
    <div class="card empty-state">
        <p>The assistant is switched off.</p>
        <a class="button" href="index.php?r=settings#ai_base_url">Turn it on in Settings</a>
    </div>
<?php else: ?>
<div class="chat-layout">
    <aside class="chat-side">
        <a class="button primary-link chat-new" href="index.php?r=assistant&amp;local=<?= e(urlencode($local)) ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
            New chat
        </a>
        <section class="card chat-list-card js-chat-list-card">
            <div class="chat-list-head">
                <h2>Chats</h2>
                <button type="button" class="link-button js-chat-select"<?= $chats ? '' : ' hidden' ?>>Select</button>
            </div>
            <div class="chat-select-bar js-chat-select-bar" hidden>
                <label class="checkbox"><input type="checkbox" class="js-chat-select-all"><span>All</span></label>
                <button type="button" class="small danger js-chat-delete-selected" disabled>Delete</button>
                <button type="button" class="small js-chat-select-done">Done</button>
            </div>
            <ul class="chat-list js-chat-list">
                <?= View::partial('assistant_list', ['chats' => $chats, 'current' => $chat['id'] ?? null, 'sources' => $sources]) ?>
            </ul>
            <form method="post" action="index.php?r=assistant-delete" class="js-chat-delete-form" hidden>
                <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
                <input type="hidden" name="back" value="<?= e($chat['id'] ?? '') ?>" class="js-chat-delete-back">
            </form>
        </section>
        <section class="card chat-help">
            <?php $allowedLabels = array_map(static fn (array $p): string => $p[0], array_filter(Ai::PERMISSIONS, static fn (string $key): bool => ($settings['perm_' . $key] ?? '0') !== '0', ARRAY_FILTER_USE_KEY)); ?>
            <p><strong>What it can do now</strong></p>
            <p>Read status, history, compare results and file changes<?= $allowedLabels ? ', and: ' . e(implode(', ', array_map('lcfirst', $allowedLabels))) : '' ?>.</p>
            <p class="chat-lock"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg><span>It acts only when you ask, never approves on production, and you choose the rest in <a href="index.php?r=settings&amp;tab=permissions">AI permissions</a>.</span></p>
        </section>
    </aside>

    <section class="card chat-panel">
        <div class="chat-head">
            <div class="chat-title">
                <h1 class="js-chat-title"><?= e($chat['title'] ?? 'Assistant') ?></h1>
                <input type="text" class="js-chat-title-input" maxlength="80" aria-label="Chat name" hidden>
                <button type="button" class="icon-button js-chat-rename" aria-label="Rename this chat" title="Rename"<?= $chat === null ? ' hidden' : '' ?>>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4z"></path><path d="M14 6l4 4"></path></svg>
                </button>
            </div>
            <?php if ($chat === null): ?>
                <label class="chat-local">Local
                    <select name="local" form="chat-form">
                        <?php foreach ($sources as $key => $source): ?>
                            <option value="<?= e($key) ?>"<?= $key === $local ? ' selected' : '' ?>><?= e($source['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </label>
            <?php else: ?>
                <span class="pill"><?= e($sources[$chat['local']]['name'] ?? $chat['local']) ?></span>
            <?php endif ?>
            <span class="pill" title="Change it in Settings"><?= e(Ai::PRESETS[$settings['provider']][0] ?? 'Custom') ?> · <?= e($settings['model']) ?></span>
            <span class="pill js-chat-usage" title="Used by this chat so far. The price shows when the provider reports it (OpenRouter does)."<?= $chat === null || $chat['messages'] === [] ? ' hidden' : '' ?>><?= $chat !== null ? e(ChatStore::usageText($chat)) : '' ?></span>
            <?php if ($chat !== null): ?>
                <form method="post" action="index.php?r=assistant-delete" class="inline js-confirm" data-confirm="Delete this chat?">
                    <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
                    <input type="hidden" name="chat" value="<?= e($chat['id']) ?>">
                    <button type="submit" class="small danger">Delete chat</button>
                </form>
            <?php endif ?>
        </div>

        <?php
        // Mid-answer (the page was reloaded): show up to the latest message; the script
        // follows the answer live from there.
        $busy = $chat !== null && ChatStore::isBusy($chat['id']);
        $shown = $chat['messages'] ?? [];
        if ($busy) {
            $lastUser = max(array_keys(array_filter($shown, static fn (array $m): bool => $m['role'] === 'user')) ?: [-1]);
            $shown = array_slice($shown, 0, $lastUser + 1);
        }
        ?>
        <div class="chat-log js-chat-log" aria-live="polite"<?= $busy ? ' data-busy="1"' : '' ?>>
            <?php if ($shown === []): ?>
                <div class="chat-empty js-chat-empty">
                    <p><strong>Ask about your deploys in plain words.</strong></p>
                    <p class="meta">Try one of these, or type your own question below.</p>
                    <div class="chat-suggest" role="group" aria-label="Suggestions">
                        <?php foreach ($suggestions as $suggestion): ?>
                            <button type="button" class="chat-chip js-suggest" data-text="<?= e($suggestion) ?>"><?= e($suggestion) ?></button>
                        <?php endforeach ?>
                    </div>
                </div>
            <?php else: ?>
                <?= View::partial('assistant_turns', ['messages' => $shown]) ?>
            <?php endif ?>
        </div>

        <form id="chat-form" class="chat-form js-chat-form" method="post" action="index.php?r=assistant-send">
            <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
            <input type="hidden" name="chat" value="<?= e($chat['id'] ?? '') ?>">
            <div class="chat-composer">
                <label for="chat-message" class="sr-only">Message</label>
                <textarea id="chat-message" name="message" rows="1" placeholder="Ask about deploys, compares, history or a file's changes…" required></textarea>
                <div class="chat-composer-bar">
                    <div class="chat-tips">
                        <button type="button" class="chat-tips-toggle js-tips-toggle" aria-haspopup="menu" aria-expanded="false" aria-controls="chat-tips-menu" title="Suggested questions">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.2 1 2V17h6v-.3c0-.8.4-1.5 1-2A7 7 0 0 0 12 2z"></path></svg>
                            <span>Suggestions</span>
                        </button>
                        <div id="chat-tips-menu" class="chat-tips-menu js-tips-menu" role="menu" aria-label="Suggested questions" hidden>
                            <?php foreach ($suggestions as $suggestion): ?>
                                <button type="button" class="js-suggest" role="menuitem" data-text="<?= e($suggestion) ?>"><?= e($suggestion) ?></button>
                            <?php endforeach ?>
                        </div>
                    </div>
                    <button type="button" class="chat-stop js-chat-stop" hidden>
                        <svg width="12" height="12" viewBox="0 0 12 12" aria-hidden="true"><rect width="12" height="12" rx="2" fill="currentColor"></rect></svg>
                        <span>Stop</span>
                    </button>
                    <button type="submit" class="primary chat-send" aria-label="Send" title="Send (Enter). Shift + Enter adds a line.">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6"></path></svg>
                    </button>
                </div>
            </div>
        </form>
    </section>
</div>
<?php endif ?>
