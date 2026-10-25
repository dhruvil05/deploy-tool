<?php
/**
 * @var array $run
 * @var ?array $fromInfo
 * @var ?array $toInfo
 * @var ?string $rollbackBlocker 'n/a' for runs that can never be rolled back
 */

use DeployTool\Http;
use DeployTool\RunStore;
use DeployTool\RunView;

[$statusLabel, $statusStyle] = RunView::STATUSES[$run['status']] ?? [$run['status'], 'neutral'];
$phases = RunView::phases($run);
$phaseKeys = array_keys($phases);
$currentIndex = array_search($run['phase'], $phaseKeys, true);
$finished = in_array($run['phase'], ['done'], true);
$post = static function (string $route, string $label, string $class = '', string $confirm = '') use ($run): string {
    return '<form method="post" action="index.php?r=' . e($route) . '" class="inline' . ($confirm !== '' ? ' js-confirm' : '') . '"'
        . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . '<input type="hidden" name="_csrf" value="' . e(Http::csrfToken()) . '">'
        . '<input type="hidden" name="id" value="' . e($run['id']) . '">'
        . '<button type="submit" class="' . e($class) . '">' . e($label) . '</button></form>';
};
$commitText = static fn (?array $info): string => $info === null ? 'none' : short_sha($info['sha']) . ($info['subject'] !== '' ? ' · ' . $info['subject'] : '');
?>
<div class="page-head">
    <h1><?= e(RunView::title($run)) ?></h1>
    <span class="badge badge-<?= e($statusStyle) ?>"><?= e($statusLabel) ?></span>
</div>
<p class="lead">
    <?php if ($run['type'] === 'compare'): ?>
        Compared with <code><?= e($commitText($toInfo)) ?></code>
        · <?= $run['site_type'] === 'folder' ? 'folder fingerprints' : ($run['method'] === 'fingerprint' ? 'fingerprint file' : 'file sizes over FTP') ?>
    <?php else: ?>
        From <code><?= e($commitText($fromInfo)) ?></code> to <code><?= e($commitText($toInfo)) ?></code>
    <?php endif ?>
    <br><span class="meta">Started <?= e(date('d M Y H:i', $run['created_at'])) ?> by <?= e($run['by']) ?> · <?= e($run['id']) ?></span>
</p>

<?php if ($run['error']): ?>
    <div class="notice notice-danger"><?= e($run['error']) ?></div>
<?php endif ?>
<?php if (!empty($run['helper']) && empty($run['helper']['deleted']) && $run['status'] !== 'running'): ?>
    <div class="notice notice-warning">The temporary file <code><?= e($run['helper']['name']) ?></code> may still be on the server. It stops working after 15 minutes; delete it over FTP to be sure.</div>
<?php endif ?>

<ol class="phases">
    <?php foreach ($phaseKeys as $index => $key): ?>
        <?php $state = $finished || ($currentIndex !== false && $index < $currentIndex) ? 'done' : ($index === $currentIndex ? 'current' : 'pending'); ?>
        <li class="phase-<?= $state ?>"><?= e($phases[$key]) ?></li>
    <?php endforeach ?>
</ol>

<?php if ($run['status'] === 'running'): ?>
    <section class="card" id="run-live" data-run="<?= e($run['id']) ?>">
        <p class="live-head"><strong class="js-phase"><?= e($phases[$run['phase']] ?? $run['phase']) ?></strong> <span class="meta js-count"></span></p>
        <progress class="js-progress" max="1" value="0"></progress>
        <ul class="log js-log"></ul>
        <p class="meta">Keep this page open. If you close it, the run waits and carries on when you open it again.</p>
        <?= $post('run-stop', 'Stop', 'danger') ?>
    </section>
<?php endif ?>

<?php if (!empty($run['checks']) && ($run['status'] === 'failed' || $run['status'] === 'review')): ?>
    <section class="card section">
        <h2>Safety checks</h2>
        <ul class="checks">
            <?php foreach ($run['checks'] as [$ok, $text]): ?>
                <li class="<?= $ok ? 'check-ok' : 'check-fail' ?>"><?= $ok ? '✓' : '✗' ?> <?= e($text) ?></li>
            <?php endforeach ?>
        </ul>
    </section>
<?php endif ?>

<?php if (!empty($run['lint_errors'])): ?>
    <section class="card section">
        <h2>PHP syntax errors</h2>
        <ul class="checks">
            <?php foreach ($run['lint_errors'] as $lint): ?>
                <li class="check-fail"><code><?= e($lint['path']) ?></code>: <?= e($lint['error']) ?></li>
            <?php endforeach ?>
        </ul>
    </section>
<?php endif ?>

<?php if ($run['status'] === 'review'): ?>
    <?= \DeployTool\View::partial('run_review', ['run' => $run]) ?>
<?php endif ?>

<?php if ($run['type'] === 'compare' && $run['status'] === 'done'): ?>
    <?= \DeployTool\View::partial('compare_result', ['run' => $run]) ?>
<?php endif ?>

<?php if (!empty($run['site_check'])): ?>
    <div class="notice notice-<?= $run['site_check']['ok'] ? ($run['site_check']['skipped'] ? 'info' : 'success') : 'danger' ?>">
        Site check: <?= e($run['site_check']['message']) ?>
        <?php if (!$run['site_check']['ok'] && $rollbackBlocker === null): ?> Consider rolling back.<?php endif ?>
    </div>
<?php endif ?>

<?php if ($run['status'] === 'sql'): ?>
    <section class="card section">
        <h2>SQL</h2>
        <p>The files are deployed and recorded. If this deploy needs database changes, run them now in the database yourself, then confirm here.</p>
        <?= $post('run-sql-done', 'SQL is done (or not needed)', 'primary') ?>
    </section>
<?php endif ?>

<?php if ($run['type'] === 'rollback' && $run['status'] === 'done'): ?>
    <div class="notice notice-info">Files and the recorded version are back. If the deploy ran SQL, undo that in the database yourself.</div>
<?php endif ?>

<div class="actions-row">
    <?php if ($run['status'] === 'failed'): ?>
        <?= $post('run-retry', 'Retry', 'primary') ?>
        <?php if (empty($run['touched'])): ?>
            <?= $post('run-cancel', 'Cancel') ?>
        <?php else: ?>
            <?= $post('run-abandon', 'Abandon', 'danger', 'Abandon this run? Some files on the server may be new and some old. Rolling back is usually better.') ?>
        <?php endif ?>
    <?php endif ?>
    <?php if ($rollbackBlocker === null): ?>
        <?= $post('run-rollback', 'Roll back this deploy…', 'danger') ?>
    <?php endif ?>
    <?php if (!empty($run['rolled_back_by'])): ?>
        <a class="button" href="index.php?r=run&amp;id=<?= e(urlencode($run['rolled_back_by'])) ?>">Open the rollback</a>
    <?php endif ?>
    <?php if (!empty($run['rollback_of'])): ?>
        <a class="button" href="index.php?r=run&amp;id=<?= e(urlencode($run['rollback_of'])) ?>">Open the deploy it undid</a>
    <?php endif ?>
    <?php if (!empty($run['compare_id'])): ?>
        <a class="button" href="index.php?r=run&amp;id=<?= e(urlencode($run['compare_id'])) ?>">Open the compare</a>
    <?php endif ?>
    <?php if ($run['files'] || $run['type'] === 'compare'): ?>
        <a class="button" href="index.php?r=run-export&amp;id=<?= e(urlencode($run['id'])) ?>">Export list (CSV)</a>
    <?php endif ?>
</div>

<?php if ($run['files'] && $run['status'] !== 'review'): ?>
    <details class="card section">
        <summary><?= count($run['files']) ?> file(s) in this run</summary>
        <?= \DeployTool\View::partial('run_files', ['run' => $run, 'filters' => false]) ?>
    </details>
<?php endif ?>

<?php $backupOf = $run['type'] === 'rollback' ? $run['rollback_of'] : $run['id']; ?>
<?php if (in_array($run['type'], ['deploy', 'sync', 'rollback'], true) && \DeployTool\Backup::size($backupOf) !== null): ?>
    <p class="meta backup-line">
        <?= $run['type'] === 'rollback' ? 'Backup this rollback restored from:' : 'Backup of the server\'s files before this run:' ?>
        <a class="button small" href="index.php?r=backup-download&amp;id=<?= e(urlencode($backupOf)) ?>">Download zip (<?= e(number_format(\DeployTool\Backup::size($backupOf) / 1024, 1)) ?> KB)</a>
        <code><?= e(\DeployTool\Backup::location($backupOf)) ?></code>
    </p>
<?php endif ?>

<details class="card section">
    <summary>Log (<?= count($run['log']) ?>)</summary>
    <ul class="log">
        <?php foreach ($run['log'] as [$time, $level, $message]): ?>
            <li class="log-<?= e($level) ?>"><span class="meta"><?= e($time) ?></span> <?= e($message) ?></li>
        <?php endforeach ?>
    </ul>
</details>
