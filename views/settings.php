<?php
/**
 * @var array $values
 * @var array $errors
 * @var string $gitBinary
 * @var string $phpBinary
 * @var string $sshBinary
 */

use DeployTool\Http;
use DeployTool\ProtectedPaths;

$hasError = static fn (string $field): string => isset($errors[$field]) ? ' has-error' : '';
$error = static fn (string $field): string => isset($errors[$field]) ? '<p class="error-text">' . e($errors[$field]) . '</p>' : '';
?>
<h1>Settings</h1>
<p class="lead">Repositories and branches are set per local: Sites → Locals, or Edit on a local's card.</p>

<form method="post" action="index.php?r=settings-save" class="form card">
    <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">

    <div class="field<?= $hasError('deployer_name') ?>">
        <label for="deployer_name">Your name</label>
        <input type="text" id="deployer_name" name="deployer_name" value="<?= e($values['deployer_name']) ?>" required>
        <p class="help">Recorded on the server with every deploy, so everyone can see who deployed what.</p>
        <?= $error('deployer_name') ?>
    </div>

    <div class="field<?= $hasError('git_path') ?>">
        <label for="git_path">Git program</label>
        <input type="text" id="git_path" name="git_path" value="<?= e($values['git_path']) ?>" placeholder="Automatic">
        <p class="help">Leave blank to find it automatically. Using: <code><?= e($gitBinary) ?></code></p>
        <?= $error('git_path') ?>
    </div>

    <div class="field<?= $hasError('php_path') ?>">
        <label for="php_path">PHP program</label>
        <input type="text" id="php_path" name="php_path" value="<?= e($values['php_path']) ?>" placeholder="Automatic">
        <p class="help">Used for the PHP syntax check. Leave blank to find it automatically. Using: <code><?= e($phpBinary) ?></code></p>
        <?= $error('php_path') ?>
    </div>

    <div class="field<?= $hasError('ssh_path') ?>">
        <label for="ssh_path">SSH program</label>
        <input type="text" id="ssh_path" name="ssh_path" value="<?= e($values['ssh_path']) ?>" placeholder="Automatic">
        <p class="help">Used for SSH sites. Leave blank to use Windows' own OpenSSH. Using: <code><?= e($sshBinary) ?></code></p>
        <?= $error('ssh_path') ?>
    </div>

    <?php $removedDefaults = array_diff(ProtectedPaths::DEFAULTS, ProtectedPaths::parse($values['protected_paths'])); ?>
    <div class="field<?= $hasError('protected_paths') ?>">
        <label for="protected_paths">Protected on every site</label>
        <textarea id="protected_paths" name="protected_paths" rows="9"><?= e($values['protected_paths']) ?></textarea>
        <p class="help">Never uploaded, overwritten, deleted or read on any server. One path per line from the app folder; end folders with /; <code>*</code> stands for one folder name. Each local can add more paths of its own.</p>
        <p class="help">Always protected, and can't be removed: <?php foreach (ProtectedPaths::LOCKED as $rule): ?><code><?= e($rule) ?></code> <?php endforeach ?>(where the tool records the deployed commit).</p>
        <?= $error('protected_paths') ?>
        <?php if ($removedDefaults): ?>
            <p class="warning-text">Not protected any more: <?= e(implode(', ', $removedDefaults)) ?>. Files there in git will be uploaded over the server's copies, for example customer uploads or the server's own config.</p>
        <?php endif ?>
        <button type="button" class="small js-fill" data-target="protected_paths" data-fill="<?= e(implode("\n", ProtectedPaths::DEFAULTS)) ?>">Restore the default list</button>
    </div>

    <div class="form-actions">
        <button type="submit" class="primary">Save settings</button>
    </div>
</form>
