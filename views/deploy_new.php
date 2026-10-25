<?php
/**
 * @var array $site
 * @var ?array $open
 * @var ?array $source
 * @var ?string $sourceError
 * @var ?array $state
 * @var ?string $stateError
 * @var array $commits
 * @var ?array $changes
 */

use DeployTool\Http;
use DeployTool\Sites;

$blocked = $open !== null || $source === null || $stateError !== null;
?>
<h1>Deploy to <?= e($site['name']) ?></h1>

<?php if (Sites::isInsideOwnLocal($site)): ?>
    <div class="notice notice-danger">This folder is inside the repository this site gets its code from. Deploying here would overwrite your working files, so it's blocked. Point the site at a separate copy.</div>
<?php elseif (($warning = Sites::checkoutWarning($site)) !== null): ?>
    <div class="notice notice-warning"><?= e($warning) ?></div>
<?php endif ?>

<?php if ($open !== null): ?>
    <div class="notice notice-warning">This site has an open run. <a href="index.php?r=run&amp;id=<?= e(urlencode($open['id'])) ?>">Open it</a> to finish or cancel it first.</div>
<?php endif ?>

<section class="card section">
    <dl class="facts">
        <dt>Gets code from</dt>
        <dd><?= e(Sites::sourceLabel($site)) ?></dd>
        <dt>Commit to deploy</dt>
        <dd>
            <?php if ($source !== null): ?>
                <code><?= e(short_sha($source['sha'])) ?></code> <?= e($source['subject']) ?>
                <span class="meta">· <?= e($source['date']) ?> · <?= e($source['author']) ?></span>
            <?php else: ?>
                <span class="error-text"><?= e($sourceError) ?></span>
            <?php endif ?>
        </dd>
        <dt>Server now</dt>
        <dd>
            <?php if ($stateError !== null): ?>
                <span class="error-text"><?= e($stateError) ?></span>
            <?php elseif ($state !== null): ?>
                <code><?= e(short_sha($state['commit'])) ?></code>
                <span class="meta">deployed <?= e($state['deployed_at'] ?? '?') ?> by <?= e($state['deployed_by'] ?? '?') ?></span>
            <?php else: ?>
                No recorded version yet
            <?php endif ?>
        </dd>
        <?php if ($changes !== null): ?>
            <dt>Changes</dt>
            <dd><?= $changes['commits'] ?> commit(s), <?= $changes['files'] ?> file(s) changed in git (protected paths are left out later)</dd>
        <?php endif ?>
        <?php if ($site['production']): ?>
            <dt>Production</dt>
            <dd>Yes: you'll type <code><?= e($site['key']) ?></code> to confirm, and no file is ever deleted automatically.</dd>
        <?php endif ?>
    </dl>
</section>

<form method="post" action="index.php?r=deploy-start" class="form">
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

    <p class="meta">Preparing checks everything and backs up the server's files. Nothing on the server changes until you approve the review.</p>
    <div class="form-actions">
        <?php if (!$blocked): ?>
            <button type="submit" class="primary">Prepare deploy</button>
        <?php endif ?>
        <a class="button" href="index.php">Back</a>
    </div>
</form>
