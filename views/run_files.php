<?php
/**
 * File list of a deploy, match or rollback.
 *
 * @var array $run
 * @var bool $filters show the filter buttons (review screen)
 * @var array $stats path => [lines added, removed] (speed-up "Review insights"), optional
 * @var array $risky path => why it deserves a look (same), optional
 * @var bool $preview show each file's changes in place (same), optional
 */

use DeployTool\DeployRun;
use DeployTool\RunView;

$stats ??= [];
$risky ??= [];
$preview ??= false;

$warningPaths = $run['type'] === 'rollback' ? [] : array_column(DeployRun::warnings($run), 'path');
$actionLabels = ['upload' => ['Upload', 'info'], 'delete' => ['Delete', 'danger'], 'restore' => ['Restore', 'info']];
$changeLabels = ['new' => 'new', 'changed' => 'changed', 'deleted' => $run['type'] === 'sync' ? 'extra on server' : 'removed in git'];
?>
<?php if ($filters): ?>
    <div class="filters" role="group" aria-label="Filter files">
        <button type="button" class="js-filter active" data-filter="all">All (<?= count($run['files']) ?>)</button>
        <button type="button" class="js-filter" data-filter="upload">Uploads</button>
        <button type="button" class="js-filter" data-filter="delete">Deletes</button>
        <?php if ($warningPaths): ?>
            <button type="button" class="js-filter" data-filter="warning">Warnings (<?= count($warningPaths) ?>)</button>
        <?php endif ?>
    </div>
<?php endif ?>
<table class="table files-table">
    <thead>
    <tr><th>Action</th><th>File</th><?php if ($stats): ?><th class="num">Lines</th><?php endif ?><th>Server copy</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($run['files'] as $file): ?>
        <?php
        [$actionLabel, $actionStyle] = $actionLabels[$file['action']];
        $isWarning = in_array($file['path'], $warningPaths, true);
        $diffUrl = 'index.php?r=run-diff&id=' . urlencode($run['id']) . '&path=' . urlencode($file['path']);
        ?>
        <tr data-action="<?= $file['action'] === 'delete' ? 'delete' : 'upload' ?>" data-warning="<?= $isWarning ? '1' : '0' ?>">
            <td>
                <span class="badge badge-<?= $actionStyle ?>"><?= $actionLabel ?></span>
                <span class="meta"><?= e($changeLabels[$file['change']] ?? '') ?></span>
            </td>
            <td class="path"><code><?= e($file['path']) ?></code><?php if (isset($risky[$file['path']])): ?> <span class="badge badge-warning"><?= e($risky[$file['path']]) ?></span><?php endif ?></td>
            <?php if ($stats): ?>
                <?php [$added, $removed] = $stats[$file['path']] ?? [null, null]; ?>
                <td class="num lines"><?= $added === null && $removed === null ? '<span class="meta">' . (isset($stats[$file['path']]) ? 'binary' : '') . '</span>' : '<span class="diff-add">+' . (int) $added . '</span> <span class="diff-del">−' . (int) $removed . '</span>' ?></td>
            <?php endif ?>
            <td>
                <?php if (isset($file['server'])): ?>
                    <?php [$label, $style] = RunView::serverBadge($file); ?>
                    <span class="badge badge-<?= e($style) ?>"><?= e($label) ?></span>
                <?php endif ?>
                <?php if (!empty($file['verified'])): ?>
                    <span class="badge badge-success">Verified</span>
                <?php elseif (!empty($file['done'])): ?>
                    <span class="badge badge-neutral">Done</span>
                <?php endif ?>
            </td>
            <td class="links">
                <div class="row-actions">
                <?php if ($preview): ?>
                    <button type="button" class="row-action js-diff-preview" data-url="<?= e($diffUrl . '&embed=1') ?>" aria-expanded="false">Preview</button>
                <?php endif ?>
                <a class="row-action" href="<?= e($diffUrl) ?>" target="_blank" rel="noopener">Changes</a>
                <?php if (($file['server'] ?? '') === 'edited'): ?>
                    <a class="row-action" href="<?= e($diffUrl . '&kind=server') ?>" target="_blank" rel="noopener">Server edits</a>
                    <?php if (\DeployTool\RunController::hasThreeWay($run, $file)): ?>
                        <a class="row-action row-action-primary" href="<?= e($diffUrl . '&kind=three') ?>" target="_blank" rel="noopener">Three-way</a>
                    <?php endif ?>
                <?php endif ?>
                </div>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>

<?php if (!empty($run['skipped'])): ?>
    <details class="skipped">
        <summary><?= count($run['skipped']) ?> protected file(s) changed in git but are never touched</summary>
        <ul>
            <?php foreach ($run['skipped'] as $file): ?>
                <li><code><?= e($file['path']) ?></code> <span class="meta"><?= e($changeLabels[$file['change']] ?? '') ?></span></li>
            <?php endforeach ?>
        </ul>
    </details>
<?php endif ?>
