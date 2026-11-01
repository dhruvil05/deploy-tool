<?php
/**
 * The chat list beside the conversation. Each chat has a delete button (shown on hover)
 * and a tick box for deleting several (shown in Select mode).
 *
 * @var array $chats ChatStore::all()
 * @var ?string $current
 * @var array $sources
 */
?>
<?php if (!$chats): ?>
    <li class="meta chat-list-empty">No chats yet.</li>
<?php endif ?>
<?php foreach (array_slice($chats, 0, 50) as $item): ?>
    <li class="chat-list-item" data-chat="<?= e($item['id']) ?>">
        <input type="checkbox" class="chat-pick js-chat-pick" value="<?= e($item['id']) ?>" aria-label="Select <?= e($item['title']) ?>">
        <a href="index.php?r=assistant&amp;chat=<?= e(urlencode($item['id'])) ?>"<?= $item['id'] === $current ? ' class="active" aria-current="page"' : '' ?>>
            <?= e($item['title']) ?>
            <span><?= e($sources[$item['local']]['name'] ?? $item['local']) ?> · <?= e(ago($item['updated_at'])) ?></span>
        </a>
        <button type="button" class="chat-delete js-chat-delete" data-title="<?= e($item['title']) ?>" aria-label="Delete <?= e($item['title']) ?>" title="Delete">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"></path></svg>
        </button>
    </li>
<?php endforeach ?>
