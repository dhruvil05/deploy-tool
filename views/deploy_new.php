<?php
/**
 * Start a deploy: what the server has, what will go up, and what happens next.
 *
 * @var array $site
 * @var ?array $open
 * @var ?array $source the commit to deploy
 * @var ?string $sourceError
 * @var ?array $state the server's recorded version
 * @var ?string $stateError
 * @var array $commits recent commits, when the server has no recorded version
 * @var ?array{commits: int, list: array, files: array, protected: array} $changes
 */

use DeployTool\Http;
use DeployTool\ProtectedPaths;
use DeployTool\Sites;

$blocked = $open !== null || $source === null || $stateError !== null;
$typeLabel = ['ssh' => 'SSH', 'folder' => 'Test folder'][$site['type']] ?? 'FTP';
$where = match ($site['type']) {
    'folder' => $site['path'],
    'ssh' => $site['ssh_host'] . ':' . $site['ssh_root'],
    default => $site['url'] !== '' ? $site['url'] : $site['host'],
};
$statusLabels = ['A' => ['new', 'success'], 'M' => ['changed', 'info'], 'D' => ['removed', 'danger'], 'T' => ['type changed', 'info']];
$counts = ['A' => 0, 'M' => 0, 'D' => 0, 'protected' => 0];
foreach ($changes['files'] ?? [] as [$status, $path]) {
    $counts[$status] = ($counts[$status] ?? 0) + 1;
    $counts['protected'] += ProtectedPaths::matches($path, $changes['protected']) ? 1 : 0;
}
$upToDate = $changes !== null && $changes['commits'] === 0 && $changes['files'] === [];
?>
<header class="card run-hero">
    <p class="run-hero-kind"><span class="run-kind kind-deploy">Deploy</span><?php if ($site['production']): ?><span class="badge badge-danger">Production</span><?php endif ?></p>
    <h1><?= e($site['name']) ?></h1>
    <p class="meta run-facts">
        <span><?= e($typeLabel) ?> · <?= e($where) ?></span>
        <span>gets code from <?= e(Sites::sourceLabel($site)) ?></span>
    </p>

    <div class="run-commits">
        <div class="commit-box">
            <span class="commit-label">Server now</span>
            <?php if ($stateError !== null): ?>
                <span class="error-text"><?= e($stateError) ?></span>
            <?php elseif ($state !== null): ?>
                <code><?= e(short_sha($state['commit'])) ?></code>
                <span class="commit-subject meta">deployed <?= e($state['deployed_at'] ?? '?') ?> by <?= e($state['deployed_by'] ?? '?') ?></span>
            <?php else: ?>
                <span class="meta">no recorded version yet</span>
            <?php endif ?>
        </div>
        <span class="commit-arrow" aria-hidden="true">→</span>
        <div class="commit-box">
            <span class="commit-label">Will deploy</span>
            <?php if ($source !== null): ?>
                <code><?= e(short_sha($source['sha'])) ?></code>
                <span class="commit-subject"><?= e($source['subject']) ?></span>
                <span class="meta"><?= e(substr($source['date'], 0, 16)) ?> · <?= e($source['author']) ?></span>
            <?php else: ?>
                <span class="error-text"><?= e((string) $sourceError) ?></span>
            <?php endif ?>
        </div>
    </div>
</header>

<?php if (Sites::isInsideOwnLocal($site)): ?>
    <div class="notice notice-danger">This folder is inside the repository this site gets its code from. Deploying here would overwrite your working files, so it's blocked. Point the site at a separate copy.</div>
<?php elseif (($warning = Sites::checkoutWarning($site)) !== null): ?>
    <div class="notice notice-warning"><?= e($warning) ?></div>
<?php endif ?>
<?php if ($open !== null): ?>
    <div class="notice notice-warning">This site has an open run. <a href="index.php?r=run&amp;id=<?= e(urlencode($open['id'])) ?>">Open it</a> to finish or cancel it first.</div>
<?php endif ?>
<?php if ($upToDate): ?>
    <div class="notice notice-success">Already up to date: the server is on this commit, so a deploy would change nothing. To check the files themselves, <a href="index.php?r=compare-new&amp;site=<?= e(urlencode($site['key'])) ?>">compare the server</a>.</div>
<?php endif ?>

<?php if ($changes !== null && !$upToDate): ?>
    <div class="metrics">
        <div class="metric"><p class="metric-label">Commits</p><p class="metric-value"><?= $changes['commits'] ?></p></div>
        <div class="metric"><p class="metric-label">Files changed in git</p><p class="metric-value"><?= count($changes['files']) ?></p>
            <p class="metric-sub"><?= $counts['A'] ?> new · <?= $counts['M'] ?> changed · <?= $counts['D'] ?> removed</p></div>
        <div class="metric"><p class="metric-label">Protected, left out</p><p class="metric-value"><?= $counts['protected'] ?></p></div>
    </div>

    <div class="start-columns">
        <section class="card">
            <h2>Commits in this deploy</h2>
            <ul class="commit-list">
                <?php foreach ($changes['list'] as $commit): ?>
                    <li><code><?= e(short_sha($commit['sha'])) ?></code> <span class="commit-subject"><?= e($commit['subject']) ?></span>
                        <span class="meta"><?= e($commit['author']) ?> · <?= e(substr($commit['date'], 0, 10)) ?></span></li>
                <?php endforeach ?>
            </ul>
            <?php if ($changes['commits'] > count($changes['list'])): ?>
                <p class="meta">And <?= $changes['commits'] - count($changes['list']) ?> older.</p>
            <?php endif ?>
        </section>
        <section class="card">
            <h2>Files that change</h2>
            <ul class="change-list">
                <?php foreach (array_slice($changes['files'], 0, 300) as [$status, $path]): ?>
                    <?php [$label, $style] = $statusLabels[$status] ?? [$status, 'neutral']; ?>
                    <li>
                        <span class="badge badge-<?= e($style) ?>"><?= e($label) ?></span>
                        <code><?= e($path) ?></code>
                        <?php if (ProtectedPaths::matches($path, $changes['protected'])): ?><span class="pill">protected</span><?php endif ?>
                    </li>
                <?php endforeach ?>
            </ul>
            <?php if (count($changes['files']) > 300): ?>
                <p class="meta">Showing 300 of <?= count($changes['files']) ?>. The review lists every one.</p>
            <?php endif ?>
        </section>
    </div>
<?php endif ?>

<form method="post" action="index.php?r=deploy-start" class="section">
    <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
    <input type="hidden" name="site" value="<?= e($site['key']) ?>">

    <?php if (!$blocked && $state === null): ?>
        <section class="card section">
            <h2>Which commit is the server on now?</h2>
            <p class="meta">The server doesn't record its version yet. Pick the commit you last deployed by hand. Every file is checked against the server before anything uploads, so a wrong guess shows up as "Edited on server" warnings in the review.</p>
            <div class="field">
                <label for="baseline">Server is on</label>
                <select id="baseline" name="baseline" required>
                    <option value="">Choose a commit…</option>
                    <?php foreach ($commits as $commit): ?>
                        <option value="<?= e($commit['sha']) ?>"><?= e(short_sha($commit['sha']) . ' · ' . substr($commit['date'], 0, 16) . ' · ' . $commit['subject']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <p class="meta">Not sure? <a href="index.php?r=compare-new&amp;site=<?= e(urlencode($site['key'])) ?>">Compare the server with a commit</a> instead.</p>
        </section>
    <?php endif ?>

    <section class="card section next-steps">
        <h2>What happens next</h2>
        <ol class="step-cards">
            <li><strong>Checks</strong><span>Safety checks and a PHP syntax check of every changed file.</span></li>
            <li><strong>Backup</strong><span>The server's current copies are saved on this computer.</span></li>
            <li><strong>Your review</strong><span>You see every file before anything changes on the server.</span></li>
            <li><strong>Upload</strong><span>Files go up, are verified, and the version is recorded.</span></li>
        </ol>
        <?php if ($site['production']): ?>
            <p class="meta">Production: you'll type <code><?= e($site['key']) ?></code> to confirm, and no file is ever deleted automatically.</p>
        <?php endif ?>
        <div class="form-actions start-actions">
            <?php if (!$blocked): ?>
                <button type="submit" class="primary">Prepare deploy</button>
            <?php endif ?>
            <a class="button" href="index.php?r=compare-new&amp;site=<?= e(urlencode($site['key'])) ?>">Compare first</a>
            <a class="button" href="index.php">Back</a>
        </div>
    </section>
</form>
