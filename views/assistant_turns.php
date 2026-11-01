<?php
/**
 * Chat messages as turns: each user message, then one assistant turn holding every action
 * it ran and its answer. Action results (tool messages) stay hidden.
 *
 * @var array $messages in Agent's format
 */

use DeployTool\Ai\Markdown;
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
        $turns[] = ['bot', ['actions' => [], 'notes' => [], 'text' => '', 'error' => false, 'stopped' => false]];
        $last = array_key_last($turns);
    }
    if (!empty($message['calls'])) {
        if (($message['content'] ?? '') !== '') {
            $turns[$last][1]['notes'][] = $message['content'];
        }
        array_push($turns[$last][1]['actions'], ...($message['actions'] ?? []));
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
            </div>
        </div>
    <?php endif ?>
<?php endforeach ?>
