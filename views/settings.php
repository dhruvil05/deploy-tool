<?php
/**
 * @var array $values
 * @var array $errors
 * @var string $gitBinary
 * @var string $phpBinary
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

    <div class="field">
        <p class="label">Always protected, on every site</p>
        <p class="help">Never uploaded, overwritten, deleted or read on a server. Each local can add more paths.</p>
        <p class="code-list"><?php foreach (ProtectedPaths::BUILT_IN as $rule): ?><code><?= e($rule) ?></code> <?php endforeach ?></p>
    </div>

    <div class="form-actions">
        <button type="submit" class="primary">Save settings</button>
    </div>
</form>
