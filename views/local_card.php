<?php
/**
 * One local on the dashboard: the start of a pipeline.
 *
 * @var array $local LocalStatus::collect() result
 */

use DeployTool\Http;

$source = $local['source'];
?>
<section class="card local-card" data-ref="<?= e('local:' . $source['key']) ?>" data-commit="<?= e($local['commit']['sha'] ?? '') ?>">
    <p class="card-label">
        Local · <strong><?= e($source['name']) ?></strong> · <?= e($source['branch']) ?>
        <a class="card-edit" href="index.php?r=source-edit&amp;key=<?= e(urlencode($source['key'])) ?>">Edit</a>
    </p>
    <p class="meta"><?= e($source['repo_path']) ?></p>

    <?php if (!empty($local['error'])): ?>
        <p class="error-text"><?= e($local['error']) ?></p>
    <?php else: ?>
        <p class="sha"><?= e(short_sha($local['commit']['sha'])) ?> <span class="subject"><?= e($local['commit']['subject']) ?></span></p>
        <p class="meta"><?= e($local['commit']['date']) ?> · <?= e($local['commit']['author']) ?></p>

        <?php if ($local['remote_commit'] !== null): ?>
            <?php if ($local['ahead'] === 0 && $local['behind'] === 0): ?>
                <span class="badge badge-success">Same as GitHub</span>
            <?php endif ?>
            <?php if ($local['ahead'] > 0): ?>
                <span class="badge badge-warning"><?= $local['ahead'] ?> commit(s) not on GitHub yet</span>
            <?php endif ?>
            <?php if ($local['behind'] > 0): ?>
                <span class="badge badge-warning">GitHub has <?= $local['behind'] ?> commit(s) you don't have</span>
            <?php endif ?>
        <?php endif ?>

        <?php foreach ($local['malware'] as $finding): ?>
            <div class="alert">Malware marker in your <?= e($source['branch']) ?>: <?= e($finding) ?>. Don't deploy.</div>
        <?php endforeach ?>
        <?php foreach ($local['remote_malware'] as $finding): ?>
            <div class="alert">Malware marker on GitHub's <?= e($source['branch']) ?>: <?= e($finding) ?>. Don't pull it.</div>
        <?php endforeach ?>

        <?php if ($local['warnings']): ?>
            <ul class="warnings">
                <?php foreach ($local['warnings'] as $warning): ?>
                    <li><?= e($warning) ?></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>

        <form method="post" action="index.php?r=fetch" class="fetch-form js-busy">
            <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
            <input type="hidden" name="source" value="<?= e($source['key']) ?>">
            <button type="submit" data-busy="Fetching…">Fetch from GitHub</button>
            <span class="meta">Last fetched <?= e(ago($local['last_fetch'])) ?></span>
        </form>
    <?php endif ?>
</section>
