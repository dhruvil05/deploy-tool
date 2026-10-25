<?php
/**
 * File list of a deploy, match or rollback.
 *
 * @var array $run
 * @var bool $filters show the filter buttons (review screen)
 */

use DeployTool\DeployRun;
use DeployTool\RunView;

$warningPaths = $run['type'] === 'rollback' ? [] : array_column(DeployRun::warnings($run), 'path');
$actionLabels = ['upload' => ['Upload', 'info'], 'delete' => ['Delete', 'danger'], 'restore' => ['Restore', 'info']];
$changeLabels = ['new' => 'new', 'changed' => 'changed', 'deleted' => 'removed in git'];
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
    <tr><th>Action</th><th>File</th><th>Server copy</th><th></th></tr>
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
            <td class="path"><code><?= e($file['path']) ?></code></td>
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
                <a href="<?= e($diffUrl) ?>" target="_blank" rel="noopener">Changes</a>
                <?php if (($file['server'] ?? '') === 'edited'): ?>
                    <a href="<?= e($diffUrl . '&kind=server') ?>" target="_blank" rel="noopener">Server edits</a>
                <?php endif ?>
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
