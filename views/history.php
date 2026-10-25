<?php
/** @var array $runs */

use DeployTool\Backup;
use DeployTool\RunView;

$backupLink = static function (string $runId, string $label): string {
    $size = Backup::size($runId);
    return $size === null ? '' : '<a href="index.php?r=backup-download&amp;id=' . e(urlencode($runId)) . '" title="' . e(Backup::location($runId)) . '">'
        . e($label) . '</a> <span class="meta">' . e(number_format($size / 1024, 1)) . ' KB</span>';
};
?>
<h1>History</h1>
<?php if (!$runs): ?>
    <p class="lead">No deploys or compares yet.</p>
<?php else: ?>
    <table class="table">
        <thead>
        <tr><th>When</th><th>What</th><th>Site</th><th>Commits</th><th>Files</th><th>Status</th><th>Backup</th><th>By</th></tr>
        </thead>
        <tbody>
        <?php foreach ($runs as $run): ?>
            <?php [$label, $style] = RunView::STATUSES[$run['status']] ?? [$run['status'], 'neutral']; ?>
            <tr>
                <td><a href="index.php?r=run&amp;id=<?= e(urlencode($run['id'])) ?>"><?= e(date('d M H:i', $run['created_at'])) ?></a></td>
                <td><?= e(RunView::TYPES[$run['type']] ?? $run['type']) ?></td>
                <td><?= e($run['site_name']) ?></td>
                <td><code><?= $run['type'] === 'compare' ? '' : e(short_sha($run['from']) ?: '—') . ' → ' ?><?= e(short_sha($run['to'])) ?></code></td>
                <td><?= $run['type'] === 'compare' ? '' : count($run['files']) ?></td>
                <td><span class="badge badge-<?= e($style) ?>"><?= e($label) ?></span></td>
                <td>
                    <?php if ($run['type'] === 'rollback'): ?>
                        <?= $backupLink($run['rollback_of'], 'Deploy\'s backup') ?: '<span class="meta">—</span>' ?>
                    <?php elseif ($run['type'] === 'compare'): ?>
                        <span class="meta">—</span>
                    <?php else: ?>
                        <?= $backupLink($run['id'], 'Download') ?: '<span class="meta" title="None of its files existed on the server before it.">none needed</span>' ?>
                    <?php endif ?>
                </td>
                <td><?= e($run['by']) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
    <p class="meta">Each backup is a zip of the server's files from just before that run, in <code><?= e(str_replace('/', '\\', realpath(STORAGE_DIR . '/backups') ?: STORAGE_DIR . '/backups')) ?></code>. It includes a note listing the files the run added.</p>
<?php endif ?>
