<?php
/**
 * Status of one site, loaded by the page's script (dashboard card and "Test connection").
 *
 * @var array $site
 * @var array $status
 */
?>
<?php if (!$status['ok']): ?>
    <span class="badge badge-danger">Can't connect</span>
    <p class="error-text"><?= e($status['error']) ?></p>
    <p class="meta"><a href="index.php?r=site-edit&amp;key=<?= e(urlencode($site['key'])) ?>">Edit site</a></p>
<?php else: ?>
    <span class="badge badge-success">Connected</span>
    <?php if (!$status['looks_like_app']): ?>
        <span class="badge badge-warning">No index.php or application/ here</span>
        <p class="meta">For the backoffice this means the <?= $site['type'] === 'folder' ? 'folder' : 'FTP folder' ?> setting is probably wrong. Other kinds of projects can ignore this.</p>
    <?php endif ?>

    <?php if ($status['state'] === null): ?>
        <span class="badge badge-neutral">No recorded version yet</span>
        <p class="meta">The server doesn't say which commit it runs. The first compare or deploy will record it.</p>
    <?php else: ?>
        <p class="sha"><?= e(short_sha($status['commit']['sha'])) ?></p>
        <?php if ($status['commit_known']): ?>
            <p class="subject"><?= e($status['commit']['subject']) ?></p>
            <?php if ($status['behind'] === 0): ?>
                <span class="badge badge-success">Up to date</span>
            <?php elseif ($status['behind'] !== null): ?>
                <span class="badge badge-warning"><?= $status['behind'] ?> commit(s) behind</span>
            <?php elseif ($status['diverged']): ?>
                <span class="badge badge-warning">Not on the same line as its source</span>
            <?php endif ?>
        <?php else: ?>
            <span class="badge badge-warning">Commit not in your local repo</span>
        <?php endif ?>
        <p class="meta">
            Deployed <?= e($status['state']['deployed_at'] ?? '?') ?> by <?= e($status['state']['deployed_by'] ?? '?') ?>
        </p>
    <?php endif ?>
<?php endif ?>
