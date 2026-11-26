<?php
/**
 * Start a compare: pick a commit and how to read the server.
 *
 * @var array $site
 * @var ?array $default the commit this site gets
 * @var ?string $sourceError
 * @var array $recent recent commits from $default
 * @var ?array $lastCompare
 * @var ?array $lastDeploy
 * @var ?array $commitChoices speed-up "Compare only some commits": CommitFiles commits + site_has, or null when off
 */

use DeployTool\Http;
use DeployTool\Settings;
use DeployTool\Sites;

$typeLabel = ['ssh' => 'SSH', 'folder' => 'Test folder'][$site['type']] ?? 'FTP';
$where = match ($site['type']) {
    'folder' => $site['path'],
    'ssh' => $site['ssh_host'] . ':' . $site['ssh_root'],
    default => $site['url'] !== '' ? $site['url'] : $site['host'],
};
$picks = [];
if ($default !== null) {
    $picks[] = ['What this site gets', $default['sha']];
}
if ($lastDeploy !== null && $lastDeploy['to'] !== ($default['sha'] ?? null)) {
    $picks[] = ['Last deployed', $lastDeploy['to']];
}
if ($lastCompare !== null && !in_array($lastCompare['to'], array_column($picks, 1), true)) {
    $picks[] = ['Last compared', $lastCompare['to']];
}
$lastResult = $lastCompare['result'] ?? null;
$sizesFirst = Settings::get('compare_method') === 'sizes';
?>
<header class="card run-hero">
    <p class="run-hero-kind"><span class="run-kind kind-compare">Compare</span><?php if ($site['production']): ?><span class="badge badge-danger">Production</span><?php endif ?></p>
    <h1><?= e($site['name']) ?></h1>
    <p class="meta run-facts">
        <span><?= e($typeLabel) ?> · <?= e($where) ?></span>
        <span>gets code from <?= e(Sites::sourceLabel($site)) ?></span>
    </p>
    <p class="meta">Finds out what is really on the server: which files match a commit, which differ, and which are missing or extra. Nothing on the server is changed.</p>
</header>

<div class="start-layout">
    <form method="post" action="index.php?r=compare-start" class="card start-form">
        <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
        <input type="hidden" name="site" value="<?= e($site['key']) ?>">

        <?php // Speed-up "Compare only some commits": the whole site, or just the files of the ticked commits. ?>
        <?php if ($commitChoices !== null): ?>
            <h2><span class="step-no">0</span> What to compare</h2>
            <div class="repo-kind-cards scope-cards js-compare-scope">
                <label class="radio-card repo-kind-card">
                    <input type="radio" name="scope" value="site" checked>
                    <span class="repo-kind-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 9h18"></path></svg></span>
                    <span><strong>Whole site</strong><span class="meta">Every file on the server against one commit.</span></span>
                </label>
                <label class="radio-card repo-kind-card">
                    <input type="radio" name="scope" value="commits">
                    <span class="repo-kind-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M12 3v6M12 15v6"></path></svg></span>
                    <span><strong>Only some commits</strong><span class="meta">Just the files the commits you tick changed. Seconds; e.g. one hotfix to prod.</span></span>
                </label>
            </div>
            <div class="js-scope-group" data-scope="commits" hidden>
                <h2 class="section"><span class="step-no">1</span> Which commits?</h2>
                <p class="meta">
                    <?php if ($commitChoices['site_has'] !== null): ?>
                        The commits after <code><?= e(short_sha($commitChoices['site_has'])) ?></code>, the version this site was last on, newest first.
                    <?php else: ?>
                        This site's version isn't known, so these are the latest <?= count($commitChoices['commits']) ?> commits.
                    <?php endif ?>
                    Only their files are read and compared, with the newest ticked commit.
                </p>
                <?php if ($commitChoices['commits'] === []): ?>
                    <p class="meta">The site already has every commit of <?= e(Sites::sourceLabel($site)) ?>.</p>
                <?php else: ?>
                    <ul class="pick-commits">
                        <?php foreach ($commitChoices['commits'] as $commit): ?>
                            <?php $count = count($commit['files']); ?>
                            <li>
                                <label class="pick-commit">
                                    <input type="checkbox" name="commits[]" value="<?= e($commit['sha']) ?>">
                                    <span class="pick-commit-text">
                                        <span><code class="commit-sha"><?= e(short_sha($commit['sha'])) ?></code> <strong><?= e($commit['subject']) ?></strong></span>
                                        <span class="meta"><?= e($commit['author']) ?> · <?= e(substr($commit['date'], 0, 16)) ?> · <?= $count ?> file<?= $count === 1 ? '' : 's' ?><?= $commitChoices['site_has'] !== null ? ' · not on the site yet' : '' ?></span>
                                    </span>
                                </label>
                                <?php if ($count > 0): ?>
                                    <details class="pick-commit-files">
                                        <summary>Files</summary>
                                        <ul><?php foreach (array_slice($commit['files'], 0, 40) as [$letter, $path]): ?><li><code><?= e($path) ?></code><?= $letter === 'A' ? ' <span class="badge badge-success">new</span>' : ($letter === 'D' ? ' <span class="badge badge-danger">removed</span>' : '') ?></li><?php endforeach ?></ul>
                                        <?php if ($count > 40): ?><p class="meta">And <?= $count - 40 ?> more.</p><?php endif ?>
                                    </details>
                                <?php endif ?>
                            </li>
                        <?php endforeach ?>
                    </ul>
                <?php endif ?>
            </div>
        <?php endif ?>

        <div class="js-scope-group" data-scope="site">
        <h2<?= $commitChoices !== null ? ' class="section"' : '' ?>><span class="step-no">1</span> Which commit?</h2>
        <div class="field">
            <label for="commit">Commit, branch or tag</label>
            <input type="text" id="commit" name="commit" value="<?= e($default['sha'] ?? '') ?>" required spellcheck="false">
            <?php if ($default === null): ?>
                <p class="error-text"><?= e((string) $sourceError) ?></p>
            <?php endif ?>
        </div>
        <?php if ($picks): ?>
            <div class="quick-picks">
                <?php foreach ($picks as [$label, $sha]): ?>
                    <button type="button" class="small js-fill-input" data-target="commit" data-value="<?= e($sha) ?>"><?= e($label) ?> · <code><?= e(short_sha($sha)) ?></code></button>
                <?php endforeach ?>
            </div>
        <?php endif ?>
        <?php if ($recent): ?>
            <div class="field">
                <label for="recent">Or a recent commit</label>
                <select id="recent" class="js-pick-commit" data-target="commit">
                    <option value="">Choose…</option>
                    <?php foreach ($recent as $commit): ?>
                        <option value="<?= e($commit['sha']) ?>"><?= e(short_sha($commit['sha']) . ' · ' . substr($commit['date'], 0, 10) . ' · ' . $commit['subject']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        <?php endif ?>

        <h2 class="section"><span class="step-no">2</span> How to read the server</h2>
        <?php if ($site['type'] === 'folder'): ?>
            <div class="choice-tile is-static"><strong>Folder fingerprints</strong><span>Every file in the folder is fingerprinted directly. Exact and quick.</span></div>
        <?php elseif ($site['type'] === 'ssh'): ?>
            <div class="choice-tile is-static"><strong>Fingerprints over SSH</strong><span>The server fingerprints every file itself in one call, without putting anything in the web folder. Exact, takes seconds, and never reads protected folders like uploads/.</span></div>
        <?php else: ?>
            <div class="choice-tiles" role="radiogroup" aria-label="How to read the server">
                <label class="choice-tile">
                    <input type="radio" name="method" value="fingerprint"<?= $sizesFirst ? '' : ' checked' ?>>
                    <strong>Fingerprint file <span class="pill pill-success">exact · seconds</span></strong>
                    <span>Uploads a temporary <code>dt-fingerprint-….php</code>, calls it once over HTTPS with a one-time key, then deletes it. Tells line-ending-only differences apart and never reads protected folders.</span>
                </label>
                <label class="choice-tile">
                    <input type="radio" name="method" value="sizes"<?= $sizesFirst ? ' checked' : '' ?>>
                    <strong>File sizes over FTP <span class="pill">rough · minutes</span></strong>
                    <span>Uploads nothing; for when the fingerprint file can't run. Lists <?= (int) $site['connections'] ?> folders at a time, can't spot an edit that keeps the size, and Windows line endings show as different.</span>
                </label>
            </div>
        <?php endif ?>
        </div>

        <div class="form-actions start-actions">
            <button type="submit" class="primary">Start compare</button>
            <a class="button" href="index.php">Back</a>
        </div>
    </form>

    <aside class="start-side">
        <section class="card">
            <h2>Last compare</h2>
            <?php if ($lastCompare === null || !is_array($lastResult)): ?>
                <p class="meta">This site hasn't been compared yet.</p>
            <?php else: ?>
                <?php $issues = count($lastResult['differs']) + count($lastResult['missing']); ?>
                <p class="<?= $issues === 0 ? 'ok-text' : 'warn-text' ?>"><?= $issues === 0 ? 'Matched' : $issues . ' file' . ($issues === 1 ? '' : 's') . ' didn\'t match' ?></p>
                <p class="meta"><code><?= e(short_sha($lastCompare['to'])) ?></code> · <?= e(ago($lastCompare['created_at'])) ?> · <?= e($lastCompare['by']) ?></p>
                <ul class="mini-stats">
                    <li><span>Different</span><strong><?= count($lastResult['differs']) ?></strong></li>
                    <li><span>Missing</span><strong><?= count($lastResult['missing']) ?></strong></li>
                    <li><span>Extra on server</span><strong><?= count($lastResult['extra']) ?></strong></li>
                </ul>
                <a class="button small" href="index.php?r=run&amp;id=<?= e(urlencode($lastCompare['id'])) ?>">Open it</a>
            <?php endif ?>
        </section>
        <section class="card">
            <h2>What you'll see</h2>
            <ul class="legend-list">
                <li><span class="dot dot-warning"></span><strong>Different</strong> content isn't the commit's</li>
                <li><span class="dot dot-warning"></span><strong>Missing</strong> in the commit, not on the server</li>
                <li><span class="dot dot-neutral"></span><strong>Extra</strong> on the server only, never deleted</li>
                <li><span class="dot dot-neutral"></span><strong>Line endings</strong> same code, harmless</li>
            </ul>
            <p class="meta">Then upload just the files you tick, or record the commit as the server's version.</p>
        </section>
    </aside>
</div>
