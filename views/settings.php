<?php
/**
 * @var array $values
 * @var array $errors
 * @var string $gitBinary
 * @var string $phpBinary
 * @var string $sshBinary
 * @var array $storage
 */

use DeployTool\FtpTransport;
use DeployTool\Http;
use DeployTool\ProtectedPaths;
use DeployTool\Settings;

$hasError = static fn (string $field): string => isset($errors[$field]) ? ' has-error' : '';
$error = static fn (string $field): string => isset($errors[$field]) ? '<p class="error-text">' . e($errors[$field]) . '</p>' : '';
$programs = [
    'git_path' => ['Git', $gitBinary, 'Reads commits and files from your locals.'],
    'php_path' => ['PHP', $phpBinary, 'Runs the PHP syntax check before uploads.'],
    'ssh_path' => ['SSH', $sshBinary, "Connects to SSH sites. Blank uses Windows' own OpenSSH."],
];
$removedDefaults = array_diff(ProtectedPaths::DEFAULTS, ProtectedPaths::parse($values['protected_paths']));
$protectedCount = count(ProtectedPaths::parse($values['protected_paths']));
?>
<div class="page-head">
    <h1>Settings</h1>
    <button type="submit" form="settings-form" class="primary">Save settings</button>
</div>
<p class="lead">Settings for this computer. Repositories and branches are set per local on the Sites page.</p>

<form method="post" action="index.php?r=settings-save" id="settings-form" class="settings-grid">
    <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">

    <div class="settings-col">
        <section class="card settings-card">
            <h2>You</h2>
            <div class="field<?= $hasError('deployer_name') ?>">
                <label for="deployer_name">Your name</label>
                <input type="text" id="deployer_name" name="deployer_name" value="<?= e($values['deployer_name']) ?>" required>
                <p class="help">Recorded on the server with every deploy, so everyone can see who deployed what.</p>
                <?= $error('deployer_name') ?>
            </div>
        </section>

        <section class="card settings-card">
            <h2>Runs and deploys</h2>
            <div class="field-row">
                <div class="field<?= $hasError('review_minutes') ?>">
                    <label for="review_minutes">Review time limit</label>
                    <div class="input-unit">
                        <input type="number" id="review_minutes" name="review_minutes" min="5" max="720" value="<?= e($values['review_minutes']) ?>">
                        <span>minutes</span>
                    </div>
                    <p class="help">A prepared deploy older than this must be prepared again, so its backups are fresh.</p>
                    <?= $error('review_minutes') ?>
                </div>
                <div class="field<?= $hasError('ftp_connections') ?>">
                    <label for="ftp_connections">FTP connections for new sites</label>
                    <div class="input-unit">
                        <input type="number" id="ftp_connections" name="ftp_connections" min="1" max="<?= FtpTransport::MAX_CONNECTIONS ?>" value="<?= e($values['ftp_connections']) ?>">
                        <span>at once</span>
                    </div>
                    <p class="help">Filled in when you add an FTP site; each site can change it. 1 to <?= FtpTransport::MAX_CONNECTIONS ?>.</p>
                    <?= $error('ftp_connections') ?>
                </div>
            </div>

            <fieldset class="field<?= $hasError('compare_method') ?>">
                <legend>Default compare for FTP sites</legend>
                <div class="segmented">
                    <?php foreach (Settings::COMPARE_METHODS as $method => $label): ?>
                        <label><input type="radio" name="compare_method" value="<?= e($method) ?>"<?= $values['compare_method'] === $method ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                    <?php endforeach ?>
                </div>
                <p class="help">Picked first on "Compare". Fingerprint is exact and takes seconds; sizes uploads nothing but is slower and rougher. SSH and folder sites always fingerprint.</p>
                <?= $error('compare_method') ?>
            </fieldset>

            <div class="field">
                <input type="hidden" name="background_runs" value="0">
                <label class="checkbox"><input type="checkbox" name="background_runs" value="1"<?= $values['background_runs'] === '1' ? ' checked' : '' ?>>
                    <span><strong>Run in the background</strong></span></label>
                <p class="help">Runs keep going when you close or reload the page. Turn off only if background runs fail to start (see storage\worker.log); the run page then does the work while it stays open.</p>
            </div>
        </section>

        <section class="card settings-card">
            <h2>Programs</h2>
            <p class="meta">Leave blank to find them automatically.</p>
            <?php foreach ($programs as $field => [$label, $using, $help]): ?>
                <div class="field program-field<?= $hasError($field) ?>">
                    <label for="<?= e($field) ?>"><?= e($label) ?></label>
                    <input type="text" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e($values[$field]) ?>" placeholder="Automatic">
                    <p class="help"><?= e($help) ?> Using <code><?= e($using) ?></code>
                        <?php if (is_file($using)): ?><span class="pill pill-success">found</span><?php else: ?><span class="pill">from PATH</span><?php endif ?>
                    </p>
                    <?= $error($field) ?>
                </div>
            <?php endforeach ?>
        </section>
    </div>

    <div class="settings-col">
        <section class="card settings-card">
            <div class="settings-card-head">
                <h2>Protected on every site</h2>
                <span class="pill"><?= $protectedCount ?> path<?= $protectedCount === 1 ? '' : 's' ?></span>
            </div>
            <p class="meta">Never uploaded, overwritten, deleted or read on any server. Each local can add more of its own.</p>
            <div class="field<?= $hasError('protected_paths') ?>">
                <textarea id="protected_paths" name="protected_paths" rows="16" aria-label="Protected on every site"><?= e($values['protected_paths']) ?></textarea>
                <p class="help">One path per line from the app folder. End folders with <code>/</code>; <code>*</code> stands for one folder name.</p>
                <p class="help">Always protected: <?php foreach (ProtectedPaths::LOCKED as $rule): ?><code><?= e($rule) ?></code> <?php endforeach ?>(where the tool records the deployed commit).</p>
                <?= $error('protected_paths') ?>
                <?php if ($removedDefaults): ?>
                    <p class="warning-text">Not protected any more: <?= e(implode(', ', $removedDefaults)) ?>. Files there in git will be uploaded over the server's copies, for example customer uploads or the server's own config.</p>
                <?php endif ?>
                <button type="button" class="small js-fill" data-target="protected_paths" data-fill="<?= e(implode("\n", ProtectedPaths::DEFAULTS)) ?>">Restore the default list</button>
            </div>
        </section>

        <section class="card settings-card">
            <h2>Storage</h2>
            <p class="meta">Everything the tool keeps, including FTP passwords. Not part of any deploy.</p>
            <dl class="stats">
                <div><dt>Folder</dt><dd><code><?= e($storage['dir']) ?></code></dd></div>
                <div><dt>Runs in history</dt><dd><?= $storage['runs'] ?></dd></div>
                <div><dt>Backups</dt><dd><?= $storage['backups'] ?> zip<?= $storage['backups'] === 1 ? '' : 's' ?>, <?= e(number_format($storage['backupBytes'] / 1048576, 1)) ?> MB</dd></div>
            </dl>
        </section>
    </div>

    <div class="settings-actions">
        <button type="submit" class="primary">Save settings</button>
    </div>
</form>
