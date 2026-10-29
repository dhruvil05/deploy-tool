<?php
/**
 * @var array $site
 * @var ?array $default
 * @var ?string $sourceError
 */

use DeployTool\Http;
?>
<h1>Compare <?= e($site['name']) ?> with a commit</h1>
<p class="lead">Finds out what is really on the server: which files match a commit, which differ, and which are missing or extra.</p>

<form method="post" action="index.php?r=compare-start" class="form card">
    <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
    <input type="hidden" name="site" value="<?= e($site['key']) ?>">

    <div class="field">
        <label for="commit">Commit, branch or tag</label>
        <input type="text" id="commit" name="commit" value="<?= e($default['sha'] ?? '') ?>" required>
        <p class="help">
            <?php if ($default !== null): ?>
                Filled in with what this site gets: <?= e(short_sha($default['sha'])) ?> · <?= e($default['subject']) ?>
            <?php else: ?>
                <?= e((string) $sourceError) ?>
            <?php endif ?>
        </p>
    </div>

    <?php if ($site['type'] === 'folder'): ?>
        <p class="meta">This is a folder site, so every file is fingerprinted directly. Exact and quick.</p>
    <?php elseif ($site['type'] === 'ssh'): ?>
        <p class="meta">This is an SSH site: the server fingerprints every file itself in one call, without putting anything in the web folder. Exact, takes seconds. It never reads protected folders like uploads/.</p>
    <?php else: ?>
        <fieldset class="field">
            <legend>How to read the server</legend>
            <label class="checkbox"><input type="radio" name="method" value="fingerprint" checked>
                <span><strong>Fingerprint file (recommended).</strong> Uploads a temporary <code>dt-fingerprint-….php</code> to the site folder, calls it once over HTTPS with a one-time key, then deletes it. Exact, takes seconds, and tells line-ending-only differences apart. It never reads protected folders like uploads/.</span></label>
            <label class="checkbox"><input type="radio" name="method" value="sizes">
                <span><strong>File sizes over FTP.</strong> Uploads nothing; use it when the fingerprint file can't run (for example the site address doesn't reach this folder). Lists <?= (int) $site['connections'] ?> folders at a time, but still takes minutes on big sites, can't spot an edit that keeps the size, and Windows line endings show as different.</span></label>
        </fieldset>
    <?php endif ?>

    <div class="form-actions">
        <button type="submit" class="primary">Start compare</button>
        <a class="button" href="index.php">Back</a>
    </div>
</form>
