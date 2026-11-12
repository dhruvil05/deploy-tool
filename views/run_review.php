<?php
/**
 * Review screen: everything that will happen, before anything happens.
 *
 * @var array $run
 */

use DeployTool\DeployRun;
use DeployTool\Http;
use DeployTool\ReviewInsights;
use DeployTool\RunStore;

$isRollback = $run['type'] === 'rollback';
$uploads = array_filter($run['files'], static fn (array $f): bool => $f['action'] !== 'delete');
$deletes = array_filter($run['files'], static fn (array $f): bool => $f['action'] === 'delete');
$warnings = $isRollback ? [] : DeployRun::warnings($run);
$new = count(array_filter($uploads, static fn (array $f): bool => $f['change'] === 'new'));
$backedUp = count(array_filter($run['files'], static fn (array $f): bool => !empty($f['existed'])));
$expires = (int) $run['reviewed_at'] + \DeployTool\Settings::reviewMinutes() * 60;
$backupOf = $isRollback ? $run['rollback_of'] : $run['id'];
$backupSize = \DeployTool\Backup::size($backupOf);
$total = count($run['files']);
// Speed-ups "Review insights" and "Spot database changes" (Settings → Speed-ups).
$insights = !$isRollback && $total > 0 && \DeployTool\Settings::on('feature_review_insights');
$stats = $insights ? ReviewInsights::lineStats($run) : [];
$risky = $insights ? ReviewInsights::riskyFiles(array_column($uploads, 'path')) : [];
$folders = $insights ? ReviewInsights::folders(array_column($run['files'], 'path'), $stats) : [];
$databaseChanges = !$isRollback && \DeployTool\Settings::on('feature_detect_sql') ? ReviewInsights::databaseChangesOf($run) : [];
$debugCode = $isRollback ? [] : ReviewInsights::debugCode($run);
$diffUrl = static fn (string $path, string $extra = ''): string => 'index.php?r=run-diff&id=' . urlencode($run['id']) . '&path=' . urlencode($path) . $extra;
$buttonLabel = match (true) {
    $total === 0 => 'Record the version only',
    $isRollback => "Roll back $total file(s)",
    default => 'Upload ' . count($uploads) . ' file(s)' . ($deletes && !$run['production'] ? ' and handle ' . count($deletes) . ' delete(s)' : ''),
};
?>
<section class="section">
    <h2>Review</h2>
    <div class="metrics">
        <div class="metric"><p class="metric-label"><?= $isRollback ? 'Put back' : 'Upload' ?></p><p class="metric-value"><?= count($uploads) ?></p><p class="metric-label"><?= $isRollback ? 'from backup' : "$new new · " . (count($uploads) - $new) . ' changed' ?></p></div>
        <div class="metric"><p class="metric-label">Delete</p><p class="metric-value"><?= count($deletes) ?></p><p class="metric-label"><?= $isRollback ? 'added by the deploy' : ($run['production'] ? 'listed only on prod' : 'removed in git') ?></p></div>
        <?php if (!$isRollback): ?>
            <div class="metric"><p class="metric-label">Backed up</p><p class="metric-value"><?= $backedUp ?></p><p class="metric-label">server copies saved</p></div>
            <div class="metric"><p class="metric-label">Skipped</p><p class="metric-value"><?= count($run['skipped']) ?></p><p class="metric-label">protected paths</p></div>
            <div class="metric<?= $warnings ? ' metric-warning' : '' ?>"><p class="metric-label">Warnings</p><p class="metric-value"><?= count($warnings) ?></p><p class="metric-label">need a look</p></div>
        <?php endif ?>
    </div>

    <?php if (!empty($run['checkout_warning'])): ?>
        <div class="notice notice-warning"><?= e($run['checkout_warning']) ?></div>
    <?php endif ?>

    <?php if (!empty($run['excluded'])): ?>
        <div class="notice notice-info">
            <?= count($run['excluded']) ?> file(s) left out of this run, so they stay as they are on the server:
            <?php foreach (array_slice($run['excluded'], 0, 20) as $file): ?><code><?= e($file['path']) ?></code> <?php endforeach ?>
            <?= count($run['excluded']) > 20 ? '…' : '' ?>
        </div>
    <?php endif ?>

    <?php if ($warnings): ?>
        <div class="notice notice-warning">
            <?= count($warnings) ?> file(s) are not what we expected on the server: edited directly on the server, already there, or missing.
            Use the Warnings filter and open <strong>Server edits</strong> to see what's different. Their current copies are in the backup either way.
        </div>
    <?php endif ?>

    <?php if ($backupSize !== null): ?>
        <p class="meta backup-line">
            <?= $isRollback ? 'Files are put back from this backup:' : 'Backup saved. You can check it before approving:' ?>
            <a class="button small" href="index.php?r=backup-download&amp;id=<?= e(urlencode($backupOf)) ?>">Download zip (<?= e(number_format($backupSize / 1024, 1)) ?> KB)</a>
            <code><?= e(\DeployTool\Backup::location($backupOf)) ?></code>
        </p>
    <?php elseif (!$isRollback): ?>
        <p class="meta">No backup needed: none of these files exist on the server yet.</p>
    <?php endif ?>

    <?php if ($databaseChanges): ?>
        <div class="notice notice-warning sql-files">
            <strong>Database changes in this deploy: <?= count($databaseChanges) ?> file<?= count($databaseChanges) === 1 ? '' : 's' ?>.</strong>
            After the upload the deploy waits at the SQL step; have the SQL ready.
            <ul><?php foreach (array_slice($databaseChanges, 0, 12, true) as $path => $why): ?><li><code><?= e($path) ?></code> · <?= e($why) ?> · <a href="<?= e($diffUrl($path)) ?>" target="_blank" rel="noopener">Changes</a></li><?php endforeach ?></ul>
        </div>
    <?php endif ?>

    <?php if ($debugCode): ?>
        <div class="notice notice-warning sql-files">
            <strong>Leftover debug code in <?= count($debugCode) ?> file<?= count($debugCode) === 1 ? '' : 's' ?>:</strong> lines this deploy adds that print or stop the page.
            <ul>
                <?php foreach (array_slice($debugCode, 0, 10, true) as $path => $hits): ?>
                    <?php foreach ($hits as [$line, $what, $code]): ?>
                        <li><code><?= e($path) ?>:<?= (int) $line ?></code> · <?= e($what) ?> · <code class="debug-line"><?= e($code) ?></code></li>
                    <?php endforeach ?>
                <?php endforeach ?>
            </ul>
        </div>
    <?php endif ?>

    <?php if (!$isRollback && $total > 0 && \DeployTool\Ai\Explain::available()): ?>
        <?= \DeployTool\View::partial('run_explain', ['run' => $run]) ?>
    <?php endif ?>

    <?php if ($insights && ($folders || $risky)): ?>
        <div class="review-insights">
            <section class="card">
                <h3>Where the changes are</h3>
                <table class="insight-table">
                    <?php foreach (array_slice($folders, 0, 8, true) as $folder => $f): ?>
                        <tr>
                            <td><code><?= e($folder) ?></code></td>
                            <td class="num"><?= $f['files'] ?> file<?= $f['files'] === 1 ? '' : 's' ?></td>
                            <td class="num"><?php if ($stats): ?><span class="diff-add">+<?= $f['added'] ?></span> <span class="diff-del">−<?= $f['removed'] ?></span><?php endif ?></td>
                        </tr>
                    <?php endforeach ?>
                </table>
                <?php if (count($folders) > 8): ?><p class="meta">And <?= count($folders) - 8 ?> more folders.</p><?php endif ?>
                <?php if (!$stats): ?><p class="meta">Line counts are shown for deploys from a recorded version; this run compares with the server's own files.</p><?php endif ?>
            </section>
            <section class="card">
                <h3>Look at these first <span class="pill<?= $risky ? ' pill-warning' : '' ?>"><?= count($risky) ?></span></h3>
                <?php if ($risky): ?>
                    <ul class="insight-risky">
                        <?php foreach (array_slice($risky, 0, 10, true) as $path => $why): ?>
                            <li><code><?= e($path) ?></code> <span class="badge badge-warning"><?= e($why) ?></span> <a href="<?= e($diffUrl($path)) ?>" target="_blank" rel="noopener">Changes</a></li>
                        <?php endforeach ?>
                    </ul>
                <?php else: ?>
                    <p class="meta">No config, server rules, dependencies, routes or entry points change.</p>
                <?php endif ?>
            </section>
        </div>
    <?php endif ?>

    <?php if ($total > 0): ?>
        <?= \DeployTool\View::partial('run_files', ['run' => $run, 'filters' => true, 'stats' => $stats, 'risky' => $risky, 'preview' => $insights]) ?>
    <?php else: ?>
        <p>No files to change. Approving only records commit <code><?= e(short_sha($run['to'])) ?></code> as the server's version.</p>
    <?php endif ?>

    <form method="post" action="index.php?r=run-approve" class="card approve">
        <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= e($run['id']) ?>">

        <?php if ($warnings): ?>
            <label class="checkbox"><input type="checkbox" name="ack_warnings" value="1" required>
                <span>I checked the <?= count($warnings) ?> warning(s). Replace those files anyway.</span></label>
        <?php endif ?>

        <?php if ($deletes && !$isRollback): ?>
            <?php if ($run['production']): ?>
                <p class="meta"><?= count($deletes) ?> file(s) were removed in git. Prod never deletes automatically; they're in the export list for you to decide.</p>
            <?php else: ?>
                <label class="checkbox"><input type="checkbox" name="approve_deletes" value="1" checked>
                    <span>Also delete the <?= count($deletes) ?> file(s) removed in git (their copies are in the backup)</span></label>
            <?php endif ?>
        <?php endif ?>

        <?php if ($run['production']): ?>
            <div class="field">
                <label for="confirm">Type <code><?= e($run['site']) ?></code> to confirm</label>
                <input type="text" id="confirm" name="confirm" required autocomplete="off">
            </div>
        <?php endif ?>

        <p class="meta">This review expires at <?= e(date('H:i', $expires)) ?>. After that, prepare again so the backups are fresh.</p>
        <button type="submit" class="primary"><?= e($buttonLabel) ?></button>
    </form>
    <form method="post" action="index.php?r=run-cancel" class="inline">
        <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= e($run['id']) ?>">
        <button type="submit">Cancel</button>
    </form>
</section>
