<?php
/**
 * Changes to one file of a run, with line numbers, as one column or side by side.
 *
 * @var array $run
 * @var array $file the run's entry for this file
 * @var string $path
 * @var string $kind change or server
 * @var array{0: string, 1: string} $labels what the left (−) and right (+) sides are
 * @var bool $beforeMissing
 * @var bool $afterMissing
 * @var bool $eolOnly
 * @var array{rows: list<array>, added: int, removed: int, binary: bool} $diff DiffView::parse()
 * @var array{view: string, ctx: string} $options
 * @var array{0: int, 1: int} $position this file's number and the number of files
 * @var ?string $prevPath
 * @var ?string $nextPath
 */

use DeployTool\DiffView;
use DeployTool\RunView;

$url = static function (array $changes) use ($run, $path, $kind, $options): string {
    $query = $changes + ['path' => $path, 'kind' => $kind] + $options;
    $query = array_filter(['r' => 'run-diff', 'id' => $run['id']] + $query, static fn ($value): bool => $value !== null
        && !($value === 'change' || $value === 'unified' || $value === '3'));
    return 'index.php?' . e(http_build_query($query));
};
$actions = ['upload' => ['Upload', 'info'], 'delete' => ['Delete', 'danger'], 'restore' => ['Restore', 'info']];
$changes = ['new' => 'new file', 'changed' => 'changed', 'deleted' => 'removed in git'];
[$actionLabel, $actionStyle] = $actions[$file['action']] ?? [$file['action'], 'neutral'];
$folder = str_contains($path, '/') ? substr($path, 0, strrpos($path, '/') + 1) : '';
$total = $diff['added'] + $diff['removed'];
$greens = $total > 0 ? (int) round(5 * $diff['added'] / $total) : 0;

$cell = static function (?array $row, string $side): string {
    if ($row === null) {
        return '<td class="ln"></td><td class="code empty"></td>';
    }
    $number = $side === 'old' ? $row['old'] : $row['new'];
    $type = $row['type'] === 'ctx' ? '' : ' ' . $row['type'];
    $eol = !empty($row['eol']) ? ' <span class="eol-tag">line ending</span>' : '';
    return '<td class="ln' . $type . '">' . ($number ?? '') . '</td><td class="code' . $type . '">' . $row['html'] . $eol . '</td>';
};
?>
<div class="diff-nav">
    <a href="index.php?r=run&amp;id=<?= e(urlencode($run['id'])) ?>">← <?= e(RunView::title($run)) ?></a>
    <?php if ($position[1] > 1): ?>
        <div class="actions-row flush">
            <span class="meta">File <?= $position[0] ?> of <?= $position[1] ?></span>
            <?php if ($prevPath !== null): ?><a class="button small" href="<?= $url(['path' => $prevPath, 'kind' => null]) ?>" title="<?= e($prevPath) ?>">‹ Previous</a><?php endif ?>
            <?php if ($nextPath !== null): ?><a class="button small" href="<?= $url(['path' => $nextPath, 'kind' => null]) ?>" title="<?= e($nextPath) ?>">Next ›</a><?php endif ?>
        </div>
    <?php endif ?>
</div>

<header class="card diff-head">
    <div class="diff-title">
        <h1><span class="diff-folder"><?= e($folder) ?></span><?= e(basename($path)) ?></h1>
        <button type="button" class="small js-copy" data-copy="<?= e($path) ?>">Copy path</button>
    </div>
    <p class="diff-badges">
        <span class="badge badge-<?= e($actionStyle) ?>"><?= e($actionLabel) ?></span>
        <span class="meta"><?= e($changes[$file['change']] ?? '') ?></span>
        <?php if ($kind === 'server'): ?><span class="badge badge-warning">Server edits</span><?php endif ?>
        <span class="diff-stat">
            <span class="diff-add">+<?= $diff['added'] ?></span>
            <span class="diff-del">−<?= $diff['removed'] ?></span>
            <?php if ($total > 0): ?>
                <span class="stat-blocks" aria-hidden="true"><?php for ($i = 0; $i < 5; $i++): ?><i class="<?= $i < $greens ? 'is-add' : 'is-del' ?>"></i><?php endfor ?></span>
            <?php endif ?>
        </span>
    </p>
    <p class="diff-legend">
        <span><span class="legend-swatch is-del"></span><?= e(ucfirst($labels[0])) ?><?= $beforeMissing ? ' <em>(no file)</em>' : '' ?></span>
        <span><span class="legend-swatch is-add"></span><?= e(ucfirst($labels[1])) ?><?= $afterMissing ? ' <em>(no file)</em>' : '' ?></span>
    </p>
</header>

<div class="diff-toolbar">
    <div class="segmented" role="group" aria-label="Layout">
        <a href="<?= $url(['view' => 'unified']) ?>"<?= $options['view'] === 'unified' ? ' class="active"' : '' ?>><span>One column</span></a>
        <a href="<?= $url(['view' => 'split']) ?>"<?= $options['view'] === 'split' ? ' class="active"' : '' ?>><span>Side by side</span></a>
    </div>
    <div class="segmented" role="group" aria-label="Unchanged lines shown">
        <?php foreach (['3' => '3 lines around', '10' => '10 lines around', 'all' => 'Whole file'] as $ctx => $label): ?>
            <a href="<?= $url(['ctx' => (string) $ctx]) ?>"<?= $options['ctx'] === (string) $ctx ? ' class="active"' : '' ?>><span><?= e($label) ?></span></a>
        <?php endforeach ?>
    </div>
</div>

<?php if ($beforeMissing && !$afterMissing): ?>
    <div class="notice notice-info">New file: it wasn't there <?= e($labels[0]) ?>, so every line is added.</div>
<?php elseif ($afterMissing && !$beforeMissing): ?>
    <div class="notice notice-warning">This file is removed <?= e($labels[1]) ?>, so every line is gone.</div>
<?php endif ?>
<?php if ($eolOnly): ?>
    <div class="notice notice-info">Only the line endings differ (Windows ↔ Unix). The code is the same.</div>
<?php endif ?>

<?php if ($diff['binary']): ?>
    <div class="card empty-state"><p>This is a binary file (an image, font or archive), so its changes can't be shown as text.</p></div>
<?php elseif ($diff['rows'] === []): ?>
    <div class="card empty-state"><p>No differences.</p></div>
<?php elseif ($options['view'] === 'split'): ?>
    <div class="diff-wrap">
        <table class="diff-table split">
            <colgroup><col class="col-ln"><col><col class="col-ln"><col></colgroup>
            <?php foreach (DiffView::split($diff['rows']) as $pair): ?>
                <?php if (isset($pair['hunk'])): ?>
                    <tr class="hunk"><td colspan="4"><?= $pair['hunk']['html'] !== '' ? $pair['hunk']['html'] : '…' ?></td></tr>
                <?php else: ?>
                    <tr><?= $cell($pair[0], 'old') ?><?= $cell($pair[1], 'new') ?></tr>
                <?php endif ?>
            <?php endforeach ?>
        </table>
    </div>
<?php else: ?>
    <div class="diff-wrap">
        <table class="diff-table">
            <colgroup><col class="col-ln"><col class="col-ln"><col></colgroup>
            <?php foreach ($diff['rows'] as $row): ?>
                <?php if ($row['type'] === 'hunk' || $row['type'] === 'note'): ?>
                    <tr class="hunk"><td colspan="3"><?= $row['html'] !== '' ? $row['html'] : '…' ?></td></tr>
                <?php else: ?>
                    <?php $type = $row['type'] === 'ctx' ? '' : ' ' . $row['type']; ?>
                    <tr>
                        <td class="ln<?= $type ?>"><?= $row['old'] ?? '' ?></td>
                        <td class="ln<?= $type ?>"><?= $row['new'] ?? '' ?></td>
                        <td class="code<?= $type ?>"><span class="sign"><?= ['add' => '+', 'del' => '−'][$row['type']] ?? ' ' ?></span><?= $row['html'] ?><?= !empty($row['eol']) ? ' <span class="eol-tag">line ending</span>' : '' ?></td>
                    </tr>
                <?php endif ?>
            <?php endforeach ?>
        </table>
    </div>
<?php endif ?>
