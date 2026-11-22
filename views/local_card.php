<?php
/**
 * One local on the dashboard: the start of a pipeline.
 *
 * @var array $local LocalStatus::collect() result
 */

use DeployTool\Http;

$source = $local['source'];
?>
<section class="card local-card js-local-card" data-ref="<?= e('local:' . $source['key']) ?>" data-commit="<?= e($local['commit']['sha'] ?? '') ?>"<?php if (\DeployTool\Settings::on('feature_auto_fetch') && empty($local['error'])): ?> data-auto-fetch="<?= e($source['key']) ?>" data-last-fetch="<?= (int) ($local['last_fetch'] ?? 0) ?>"<?php endif ?>>
    <p class="card-label">
        Local · <strong><?= e($source['name']) ?></strong> · <?= e($source['branch']) ?>
        <a class="card-edit" href="index.php?r=source-edit&amp;key=<?= e(urlencode($source['key'])) ?>">Edit</a>
    </p>
    <p class="meta local-path"><?= \DeployTool\Sources::isManaged($source) ? 'Copy of ' . e((string) preg_replace('#^(https://|git@|ssh://)#', '', $source['repo_url'])) . ' · kept by the tool' : e($source['repo_path']) ?></p>

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

        <?php if ($local['malware']): ?>
            <div class="alert malware-alert">
                <strong>Malware in your <?= e($source['branch']) ?> (<?= e(short_sha($local['commit']['sha'])) ?>). Don't deploy, build or run npm in this folder.</strong>
                <ul><?php foreach ($local['malware'] as $finding): ?><li><?= e($finding) ?></li><?php endforeach ?></ul>
                <span>Deploys of this commit are blocked. Go back to a clean commit (git log shows the last one without these files changed).</span>
            </div>
        <?php endif ?>
        <?php if ($local['remote_malware']): ?>
            <div class="alert malware-alert">
                <strong>Malware on GitHub's <?= e($source['branch']) ?> (<?= e(short_sha($local['remote_commit'])) ?>). Don't pull, merge or build from it.</strong>
                <ul><?php foreach ($local['remote_malware'] as $finding): ?><li><?= e($finding) ?></li><?php endforeach ?></ul>
                <span>Someone pushed this to GitHub. <?= $local['malware'] ? '' : 'Your own copy is clean. ' ?>Check who pushed it (GitHub → the repository's activity), change the passwords and tokens with access, then push your clean copy back. Deploys stay blocked until GitHub and your copy agree.</span>
            </div>
        <?php endif ?>

        <?php if ($local['warnings']): ?>
            <ul class="warnings">
                <?php foreach ($local['warnings'] as $warning): ?>
                    <li><?= e($warning) ?></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>

        <?php $canPull = \DeployTool\Settings::on('feature_git_pull') && !\DeployTool\Sources::isManaged($source) && $local['remote_commit'] !== null && $local['behind'] > 0; ?>
        <div class="fetch-row">
            <form method="post" action="index.php?r=fetch" class="fetch-form js-busy">
                <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
                <input type="hidden" name="source" value="<?= e($source['key']) ?>">
                <button type="submit" data-busy="Fetching…">Fetch from GitHub</button>
            </form>
            <?php // Speed-up "Pull from GitHub": fast-forward only, stopped by malware on GitHub (CommitFiles::pull). ?>
            <?php if ($canPull && $local['remote_malware']): ?>
                <button type="button" disabled title="GitHub's commit has malware markers">Pull blocked: malware on GitHub</button>
            <?php elseif ($canPull): ?>
                <form method="post" action="index.php?r=pull" class="fetch-form js-busy">
                    <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
                    <input type="hidden" name="source" value="<?= e($source['key']) ?>">
                    <button type="submit" class="primary" data-busy="Pulling…" title="Fetches, checks GitHub's commit for malware, then fast-forwards your <?= e($source['branch']) ?>. Never merges.">Pull <?= $local['behind'] ?> commit<?= $local['behind'] === 1 ? '' : 's' ?></button>
                </form>
            <?php endif ?>
            <span class="meta">Last fetched <?= e(ago($local['last_fetch'])) ?></span>
        </div>

        <?php // Speed-up "Files of each commit": what this commit changed, and what a pull would bring. ?>
        <?php if (\DeployTool\Settings::on('feature_commit_files')): ?>
            <div class="local-commit-files">
                <?= \DeployTool\View::partial('commit_files_box', ['source' => $source['key'], 'from' => null, 'to' => $local['commit']['sha'],
                    'title' => 'Files in this commit', 'help' => short_sha($local['commit']['sha']) . ' as folders', 'card' => false]) ?>
                <?php if ($local['remote_commit'] !== null && $local['behind'] > 0 && $local['ahead'] === 0 && !$local['remote_malware']): ?>
                    <?= \DeployTool\View::partial('commit_files_box', ['source' => $source['key'], 'from' => $local['commit']['sha'], 'to' => $local['remote_commit'],
                        'title' => 'What a pull brings', 'help' => $local['behind'] . ' commit(s) from GitHub, with their files', 'card' => false]) ?>
                <?php endif ?>
            </div>
        <?php endif ?>
    <?php endif ?>
</section>
