<?php
/**
 * One run: a header with its commits and steps, then what it needs from you or what it did.
 *
 * @var array $run
 * @var ?array $fromInfo
 * @var ?array $toInfo
 * @var ?string $rollbackBlocker 'n/a' for runs that can never be rolled back
 */

use DeployTool\Backup;
use DeployTool\Http;
use DeployTool\RunView;
use DeployTool\View;

[$statusLabel, $statusStyle] = RunView::STATUSES[$run['status']] ?? [$run['status'], 'neutral'];
$phases = RunView::phases($run);
$phaseKeys = array_keys($phases);
$currentIndex = array_search($run['phase'], $phaseKeys, true);
$finished = $run['phase'] === 'done';
$post = static function (string $route, string $label, string $class = '', string $confirm = '') use ($run): string {
    return '<form method="post" action="index.php?r=' . e($route) . '" class="inline' . ($confirm !== '' ? ' js-confirm' : '') . '"'
        . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>'
        . '<input type="hidden" name="_csrf" value="' . e(Http::csrfToken()) . '">'
        . '<input type="hidden" name="id" value="' . e($run['id']) . '">'
        . '<button type="submit" class="' . e($class) . '">' . e($label) . '</button></form>';
};
$commitBox = static function (string $label, ?array $info): string {
    if ($info === null) {
        return '<div class="commit-box"><span class="commit-label">' . e($label) . '</span><span class="meta">none recorded</span></div>';
    }
    return '<div class="commit-box"><span class="commit-label">' . e($label) . '</span><code>' . e(short_sha($info['sha'])) . '</code>'
        . ($info['subject'] !== '' ? '<span class="commit-subject">' . e($info['subject']) . '</span>' : '') . '</div>';
};
$kind = $run['type'] === 'sync' ? 'deploy' : $run['type'];
$method = $run['type'] !== 'compare' ? null : match (true) {
    $run['site_type'] === 'folder' => 'folder fingerprints',
    $run['site_type'] === 'ssh' => 'fingerprints over SSH',
    $run['method'] === 'fingerprint' => 'fingerprint file',
    default => 'file sizes over FTP',
};
$took = isset($run['finished_at']) && $run['type'] === 'compare' ? (int) $run['finished_at'] - (int) $run['created_at'] : null;

$backupOf = $run['type'] === 'rollback' ? (string) ($run['rollback_of'] ?? '') : $run['id'];
$backupSize = in_array($run['type'], ['deploy', 'sync', 'rollback'], true) ? Backup::size($backupOf) : null;
$showFiles = $run['files'] && $run['status'] !== 'review';
?>
<header class="card run-hero">
    <div class="run-hero-top">
        <div>
            <p class="run-hero-kind">
                <span class="run-kind kind-<?= e($kind) ?>"><?= e(RunView::TYPES[$run['type']] ?? $run['type']) ?></span>
                <?php if (!empty($run['production'])): ?><span class="badge badge-danger">Production</span><?php endif ?>
            </p>
            <h1><?= e($run['site_name']) ?></h1>
        </div>
        <span class="badge badge-<?= e($statusStyle) ?> run-status"><?= e($statusLabel) ?></span>
    </div>

    <div class="run-commits">
        <?php if ($run['type'] === 'compare'): ?>
            <?= $commitBox('Compared with', $toInfo) ?>
        <?php else: ?>
            <?= $commitBox($run['type'] === 'rollback' ? 'Undoing' : 'From', $fromInfo) ?>
            <span class="commit-arrow" aria-hidden="true">→</span>
            <?= $commitBox($run['type'] === 'rollback' ? 'Back to' : 'To', $toInfo) ?>
        <?php endif ?>
    </div>

    <p class="meta run-facts">
        <span>Started <?= e(date('d M Y, H:i', $run['created_at'])) ?></span>
        <?php if ($took !== null): ?><span>took <?= $took < 60 ? $took . ' s' : round($took / 60) . ' min' ?></span><?php endif ?>
        <?php if (isset($run['finished_at']) && $took === null): ?><span>finished <?= e(date('d M Y, H:i', (int) $run['finished_at'])) ?></span><?php endif ?>
        <span>by <?= e($run['by']) ?></span>
        <?php if ($method !== null): ?><span><?= e($method) ?></span><?php endif ?>
        <span class="run-id" title="Run id"><?= e($run['id']) ?></span>
    </p>

    <ol class="steps" aria-label="Steps">
        <?php foreach ($phaseKeys as $index => $key): ?>
            <?php
            $state = $finished || ($currentIndex !== false && $index < $currentIndex) ? 'done' : ($index === $currentIndex ? 'current' : 'pending');
            if ($state === 'current' && $run['status'] === 'failed') {
                $state = 'failed';
            }
            ?>
            <li class="step-<?= $state ?>" data-phase="<?= e($key) ?>"><?= e($phases[$key]) ?></li>
        <?php endforeach ?>
    </ol>
</header>

<?php if ($run['error']): ?>
    <div class="notice notice-danger"><?= e($run['error']) ?></div>
<?php endif ?>
<?php if (!empty($run['helper']) && empty($run['helper']['deleted']) && $run['status'] !== 'running'): ?>
    <div class="notice notice-warning">The temporary file <code><?= e($run['helper']['name']) ?></code> may still be on the server. It stops working after 15 minutes; delete it over FTP to be sure.</div>
<?php endif ?>
<?php if (!empty($run['site_check'])): ?>
    <div class="notice notice-<?= $run['site_check']['ok'] ? ($run['site_check']['skipped'] ? 'info' : 'success') : 'danger' ?>">
        Site check: <?= e($run['site_check']['message']) ?>
        <?php if (!$run['site_check']['ok'] && $rollbackBlocker === null): ?> Consider rolling back.<?php endif ?>
    </div>
<?php endif ?>
<?php if ($run['type'] === 'rollback' && $run['status'] === 'done'): ?>
    <div class="notice notice-info">Files and the recorded version are back. If the deploy ran SQL, undo that in the database yourself.</div>
<?php endif ?>

<?php if ($run['status'] === 'running'): ?>
    <section class="card section" id="run-live" data-run="<?= e($run['id']) ?>">
        <p class="live-head"><strong class="js-phase"><?= e($phases[$run['phase']] ?? $run['phase']) ?></strong> <span class="meta js-count"></span></p>
        <progress class="js-progress" max="1" value="0"></progress>
        <ul class="log js-log"></ul>
        <p class="meta">This runs in the background: you can close or reload this page and come back later.</p>
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
    <?= View::partial('run_review', ['run' => $run]) ?>
<?php endif ?>

<?php if ($run['status'] === 'sql'): ?>
    <section class="card section">
        <h2>SQL</h2>
        <p>The files are deployed and recorded. If this deploy needs database changes, run them now in the database yourself, then confirm here.</p>
        <?= $post('run-sql-done', 'SQL is done (or not needed)', 'primary') ?>
    </section>
<?php endif ?>

<div class="run-toolbar">
    <div class="actions-row flush">
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
    </div>
    <div class="actions-row flush">
        <?php if ($backupSize !== null): ?>
            <a class="button" href="index.php?r=backup-download&amp;id=<?= e(urlencode($backupOf)) ?>">Download backup (<?= e(number_format($backupSize / 1024, 1)) ?> KB)</a>
        <?php endif ?>
        <?php if ($run['files'] || $run['type'] === 'compare'): ?>
            <a class="button" href="index.php?r=run-export&amp;id=<?= e(urlencode($run['id'])) ?>">Export list (CSV)</a>
        <?php endif ?>
    </div>
</div>

<?php if ($run['type'] === 'compare' && $run['status'] === 'done'): ?>
    <?= View::partial('compare_result', ['run' => $run]) ?>
<?php endif ?>

<?php if ($showFiles): ?>
    <?php
    $count = static fn (callable $filter): int => count(array_filter($run['files'], $filter));
    $written = $count(static fn (array $f): bool => $f['action'] !== 'delete');
    $deleted = $count(static fn (array $f): bool => $f['action'] === 'delete');
    $verified = $count(static fn (array $f): bool => !empty($f['verified']));
    $notDone = $count(static fn (array $f): bool => empty($f['done']));
    ?>
    <div class="metrics run-metrics">
        <div class="metric"><p class="metric-label"><?= $run['type'] === 'rollback' ? 'Restored' : 'Uploaded' ?></p><p class="metric-value"><?= $written ?></p></div>
        <div class="metric"><p class="metric-label">Deleted</p><p class="metric-value"><?= $deleted ?></p></div>
        <div class="metric<?= $verified < count($run['files']) && $finished ? ' metric-warning' : '' ?>"><p class="metric-label">Verified</p><p class="metric-value"><?= $verified ?> <span class="metric-of">of <?= count($run['files']) ?></span></p></div>
        <?php if ($notDone > 0): ?>
            <div class="metric metric-warning"><p class="metric-label">Not done</p><p class="metric-value"><?= $notDone ?></p></div>
        <?php endif ?>
        <?php if (!empty($run['skipped'])): ?>
            <div class="metric"><p class="metric-label">Protected, skipped</p><p class="metric-value"><?= count($run['skipped']) ?></p></div>
        <?php endif ?>
    </div>

    <details class="card section result-section" <?= count($run['files']) <= 100 ? 'open' : '' ?>>
        <summary><span class="section-title">Files in this run</span> <span class="pill"><?= count($run['files']) ?></span></summary>
        <?= View::partial('run_files', ['run' => $run, 'filters' => false]) ?>
    </details>
<?php endif ?>

<?php if ($backupSize !== null): ?>
    <section class="card section backup-card">
        <div>
            <h2><?= $run['type'] === 'rollback' ? 'Backup it restored from' : 'Backup' ?></h2>
            <p class="meta"><?= $run['type'] === 'rollback' ? 'The server\'s files from just before the deploy this rollback undid.' : 'The server\'s copies of these files from just before this run, kept on this computer.' ?></p>
            <code class="backup-path"><?= e(Backup::location($backupOf)) ?></code>
        </div>
        <div class="actions-row flush">
            <button type="button" class="small js-copy" data-copy="<?= e(Backup::location($backupOf)) ?>">Copy path</button>
            <a class="button small" href="index.php?r=backup-download&amp;id=<?= e(urlencode($backupOf)) ?>">Download zip · <?= e(number_format($backupSize / 1024, 1)) ?> KB</a>
        </div>
    </section>
<?php endif ?>

<details class="card section result-section">
    <summary><span class="section-title">Log</span> <span class="pill"><?= count($run['log']) ?></span></summary>
    <ul class="log">
        <?php foreach ($run['log'] as [$time, $level, $message]): ?>
            <li class="log-<?= e($level) ?>"><span class="meta"><?= e($time) ?></span> <?= e($message) ?></li>
        <?php endforeach ?>
    </ul>
</details>
