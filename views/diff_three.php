<?php
/**
 * Speed-up "Three-way view for server edits": a file someone edited on the server. First the
 * server lines this upload would lose, then the edit itself, then what the upload does to it.
 *
 * @var array $run
 * @var string $path
 * @var array{lost: array<int, string>, added: int} $lost
 * @var string $serverPart the diff view (embedded): expected → on the server
 * @var string $changePart the diff view (embedded): on the server → after this run
 */

use DeployTool\RunView;

$folder = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/') + 1) : '';
$base = 'index.php?r=run-diff&id=' . urlencode($run['id']) . '&path=' . urlencode($path);
?>
<div class="diff-nav">
    <a href="index.php?r=run&amp;id=<?= e(urlencode($run['id'])) ?>">← <?= e(RunView::title($run)) ?></a>
    <div class="actions-row flush">
        <a class="button small" href="<?= e($base) ?>">Changes only</a>
        <a class="button small" href="<?= e($base . '&kind=server') ?>">Server edits only</a>
    </div>
</div>

<header class="card diff-head">
    <div class="diff-title">
        <h1><span class="diff-folder"><?= e($folder) ?></span><?= e(basename($path)) ?></h1>
        <span class="badge badge-warning">Edited on server</span>
    </div>
    <p class="meta">Someone changed this file directly on the server after commit <code><?= e(short_sha($run['from'])) ?></code>. Uploading replaces the server copy with commit <code><?= e(short_sha($run['to'])) ?></code>.</p>
</header>

<?php if ($lost['lost'] === []): ?>
    <div class="notice notice-success three-verdict">
        <strong><?= $lost['added'] === 0 ? 'The server edit only removed or emptied lines.' : 'Nothing from the server edit is lost.' ?></strong>
        <?= $lost['added'] > 0 ? 'Every line added on the server is also in the upload, so it seems the edit was already committed.' : 'Check the edit below to be sure it should go.' ?>
    </div>
<?php else: ?>
    <div class="notice notice-danger three-verdict">
        <strong><?= count($lost['lost']) ?> of <?= $lost['added'] ?> line<?= $lost['added'] === 1 ? '' : 's' ?> added on the server will be lost.</strong>
        If they're a hotfix, commit them first, then prepare the deploy again.
        <ol class="three-lost">
            <?php foreach (array_slice($lost['lost'], 0, 40, true) as $number => $text): ?>
                <li value="<?= (int) $number ?>"><code><?= e($text) ?></code></li>
            <?php endforeach ?>
        </ol>
        <?php if (count($lost['lost']) > 40): ?><p class="meta">And <?= count($lost['lost']) - 40 ?> more; see the edit below.</p><?php endif ?>
    </div>
<?php endif ?>

<section class="three-part">
    <h2><span class="set-step">1</span>What was edited on the server</h2>
    <p class="meta">Commit <code><?= e(short_sha($run['from'])) ?></code> (what should be there) → what is actually on the server.</p>
    <?= $serverPart ?>
</section>

<section class="three-part">
    <h2><span class="set-step">2</span>What this upload does to it</h2>
    <p class="meta">What is on the server now → commit <code><?= e(short_sha($run['to'])) ?></code>.</p>
    <?= $changePart ?>
</section>
