<?php
/**
 * Chat messages as turns: each user message, then one assistant turn holding every action
 * it ran and its answer. Action results (tool messages) stay hidden.
 *
 * @var array $messages in Agent's format
 */

use DeployTool\Ai\Markdown;
use DeployTool\DeployRun;
use DeployTool\RunStore;
use DeployTool\RunView;

$turns = [];
foreach ($messages as $message) {
    if ($message['role'] === 'user') {
        $turns[] = ['user', $message['content']];
        continue;
    }
    if ($message['role'] !== 'assistant') {
        continue;
    }
    $last = array_key_last($turns);
    if ($last === null || $turns[$last][0] !== 'bot') {
        $turns[] = ['bot', ['actions' => [], 'notes' => [], 'text' => '', 'error' => false, 'stopped' => false, 'asking' => [], 'approvals' => [], 'models' => []]];
        $last = array_key_last($turns);
    }
    if (!empty($message['model'])) {
        $turns[$last][1]['models'][] = $message['model'];
    }
    if (!empty($message['calls'])) {
        if (($message['content'] ?? '') !== '') {
            $turns[$last][1]['notes'][] = $message['content'];
        }
        array_push($turns[$last][1]['actions'], ...($message['actions'] ?? []));
        $turns[$last][1]['asking'] = !empty($message['pending']) ? (array) ($message['asking'] ?? []) : [];
        $turns[$last][1]['approvals'] = !empty($message['pending']) ? (array) ($message['approvals'] ?? []) : [];
    } else {
        $turns[$last][1]['text'] = $message['content'];
        $turns[$last][1]['error'] = !empty($message['error']);
        $turns[$last][1]['stopped'] = !empty($message['stopped']);
    }
}
// Runs the assistant touched that now wait for the user's review, as they are right now.
$reviewCard = static function (array $actions): string {
    $html = '';
    foreach (array_unique(array_filter(array_column($actions, 'run'))) as $id) {
        try {
            $run = RunStore::load((string) $id);
        } catch (\RuntimeException) {
            continue;
        }
        if ($run['status'] !== 'review') {
            continue;
        }
        $uploads = count(array_filter($run['files'], static fn (array $f): bool => $f['action'] !== 'delete'));
        $deletes = count($run['files']) - $uploads;
        $html .= '<div class="chat-review"><div><strong>Ready for your review</strong><span>' . e(RunView::title($run))
            . ' · ' . $uploads . ' file' . ($uploads === 1 ? '' : 's') . ' to upload' . ($deletes ? ' · ' . $deletes . ' to delete' : '') . (!empty($run['production']) ? ' · production' : '') . '</span></div>'
            . '<a class="button primary-link" href="index.php?r=run&amp;id=' . e(urlencode($run['id'])) . '">Open review</a></div>';
    }
    return $html;
};
$icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="8" width="16" height="12" rx="3"></rect><path d="M12 8V4M9 14h.01M15 14h.01"></path></svg>';
?>
<?php foreach ($turns as [$kind, $turn]): ?>
    <?php if ($kind === 'user'): ?>
        <div class="chat-user"><?= nl2br(e($turn)) ?></div>
    <?php else: ?>
        <div class="chat-bot<?= $turn['error'] ? ' is-error' : '' ?>">
            <span class="chat-avatar"><?= $icon ?></span>
            <div class="chat-bot-body">
                <?php foreach ($turn['notes'] as $note): ?>
                    <p class="chat-note"><?= e($note) ?></p>
                <?php endforeach ?>
                <?php if ($turn['actions']): ?>
                    <ul class="chat-actions">
                        <?php foreach ($turn['actions'] as $action): ?>
                            <li class="<?= $action['ok'] ? 'is-ok' : 'is-failed' ?>">
                                <span class="chat-action-mark" aria-hidden="true"><?= $action['ok'] ? '✓' : '✗' ?></span>
                                <?= e($action['label']) ?>
                                <?php if (!$action['ok']): ?> · failed<?php endif ?>
                                <?php if ($action['link']): ?> · <a href="<?= e($action['link']) ?>">Open</a><?php endif ?>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
                <?php if ($turn['text'] !== ''): ?>
                    <div class="chat-text<?= $turn['stopped'] ? ' is-stopped' : '' ?>"><?= $turn['error'] || $turn['stopped'] ? '<p>' . e($turn['text']) . '</p>' : Markdown::html($turn['text']) ?></div>
                <?php endif ?>
                <?= $reviewCard($turn['actions']) ?>
                <?php if ($turn['approvals']): ?>
                    <?php // Approving production: the user types the site key here; it goes to the approval, never to the model. ?>
                    <div class="chat-ask chat-approval js-chat-ask">
                        <?php foreach ($turn['approvals'] as $runId): ?>
                            <?php
                            try {
                                $run = RunStore::load((string) $runId);
                            } catch (\RuntimeException) {
                                continue;
                            }
                            $warnings = DeployRun::warnings($run);
                            $uploads = count(array_filter($run['files'], static fn (array $f): bool => $f['action'] !== 'delete'));
                            $deletes = count($run['files']) - $uploads;
                            ?>
                            <div class="chat-approval-run js-approval" data-run="<?= e($run['id']) ?>">
                                <p class="chat-approval-title"><span class="badge badge-danger">Production</span> <strong>Approve <?= e(RunView::title($run)) ?>?</strong></p>
                                <p class="meta"><?= $uploads ?> file<?= $uploads === 1 ? '' : 's' ?> to upload<?= $deletes ? " · $deletes removed in git stay on the server (production never deletes)" : '' ?> · <a href="index.php?r=run&amp;id=<?= e(urlencode($run['id'])) ?>" target="_blank" rel="noopener">See the files</a></p>
                                <?php if ($warnings): ?>
                                    <div class="chat-approval-warnings">
                                        <strong><?= count($warnings) ?> warning<?= count($warnings) === 1 ? '' : 's' ?></strong>
                                        <ul>
                                            <?php foreach (array_slice($warnings, 0, 8) as $file): ?>
                                                <li><code><?= e($file['path']) ?></code> · <?= e(RunView::serverBadge($file)[0]) ?></li>
                                            <?php endforeach ?>
                                            <?php if (count($warnings) > 8): ?><li class="meta">and <?= count($warnings) - 8 ?> more on the run's page</li><?php endif ?>
                                        </ul>
                                        <label class="checkbox"><input type="checkbox" class="js-approval-ack"> <span>I checked the warning<?= count($warnings) === 1 ? '' : 's' ?></span></label>
                                    </div>
                                <?php endif ?>
                                <label class="chat-approval-key">
                                    <span>Type <code><?= e($run['site']) ?></code> to approve</span>
                                    <input type="text" class="js-approval-key" data-site="<?= e($run['site']) ?>" autocomplete="off" spellcheck="false" autocapitalize="off" aria-label="Site key">
                                </label>
                            </div>
                        <?php endforeach ?>
                        <?php $others = array_slice($turn['asking'], count($turn['approvals'])); ?>
                        <?php if ($others): ?>
                            <p class="meta">With Approve it also does: <?= e(implode('; ', $others)) ?>.</p>
                        <?php endif ?>
                        <p class="error-text js-approval-error" hidden></p>
                        <div class="chat-ask-buttons">
                            <button type="button" class="primary js-chat-decide" data-decision="allow">Approve</button>
                            <button type="button" class="js-chat-decide" data-decision="deny">Cancel</button>
                            <span class="meta">The key goes straight to the approval; the assistant never sees or types it.</span>
                        </div>
                    </div>
                <?php elseif ($turn['asking']): ?>
                    <div class="chat-ask js-chat-ask">
                        <p><strong>The assistant wants to:</strong></p>
                        <ul>
                            <?php foreach ($turn['asking'] as $what): ?>
                                <li><?= e($what) ?></li>
                            <?php endforeach ?>
                        </ul>
                        <div class="chat-ask-buttons">
                            <button type="button" class="primary js-chat-decide" data-decision="allow">Allow</button>
                            <button type="button" class="js-chat-decide" data-decision="deny">Deny</button>
                            <span class="meta">Set to "Ask me first" in <a href="index.php?r=settings&amp;tab=permissions">AI permissions</a>.</span>
                        </div>
                    </div>
                <?php endif ?>
                <?php if ($turn['models']): ?>
                    <p class="chat-model">Answered by <?= implode(', ', array_map(static fn (string $m): string => '<code>' . e($m) . '</code>', array_values(array_unique($turn['models'])))) ?></p>
                <?php endif ?>
            </div>
        </div>
    <?php endif ?>
<?php endforeach ?>
