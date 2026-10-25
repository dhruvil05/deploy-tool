<?php
/**
 * @var array $source
 * @var array $errors
 * @var ?string $originalKey
 */

use DeployTool\Http;
use DeployTool\ProtectedPaths;

$hasError = static fn (string $field): string => isset($errors[$field]) ? ' has-error' : '';
$error = static fn (string $field): string => isset($errors[$field]) ? '<p class="error-text">' . e($errors[$field]) . '</p>' : '';
?>
<h1><?= $originalKey === null ? 'Add local' : 'Edit local ' . e($source['name']) ?></h1>
<p class="lead">A local starts a pipeline: a git repository on this computer and the branch its first site gets. Files are always uploaded from commits, never from the working folder.</p>

<form method="post" action="index.php?r=source-save" class="form card">
    <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
    <input type="hidden" name="original_key" value="<?= e($originalKey ?? '') ?>">

    <div class="field<?= $hasError('name') ?>">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?= e($source['name']) ?>" placeholder="Backoffice" required>
        <?= $error('name') ?>
    </div>

    <div class="field<?= $hasError('key') ?>">
        <label for="key">Key</label>
        <input type="text" id="key" name="key" value="<?= e($source['key']) ?>" <?= $originalKey !== null ? 'readonly' : 'required' ?>>
        <p class="help">Short id, for example backoffice. It can't be changed later.</p>
        <?= $error('key') ?>
    </div>

    <div class="field<?= $hasError('repo_path') ?>">
        <label for="repo_path">Repository folder</label>
        <input type="text" id="repo_path" name="repo_path" value="<?= e($source['repo_path']) ?>" placeholder="C:\laragon\www\backoffice-bkp" required>
        <?= $error('repo_path') ?>
    </div>

    <div class="field-row">
        <div class="field<?= $hasError('branch') ?>">
            <label for="branch">Branch</label>
            <input type="text" id="branch" name="branch" value="<?= e($source['branch']) ?>" placeholder="staging-latest" required>
            <?= $error('branch') ?>
        </div>
        <div class="field<?= $hasError('remote') ?>">
            <label for="remote">GitHub remote</label>
            <input type="text" id="remote" name="remote" value="<?= e($source['remote']) ?>" required>
            <?= $error('remote') ?>
        </div>
    </div>

    <div class="field">
        <p class="label">Always protected</p>
        <p class="code-list"><?php foreach (ProtectedPaths::BUILT_IN as $rule): ?><code><?= e($rule) ?></code> <?php endforeach ?></p>
    </div>

    <div class="field<?= $hasError('extra_protected') ?>">
        <label for="extra_protected">Also protect for this local</label>
        <textarea id="extra_protected" name="extra_protected" rows="4" placeholder="resources/&#10;package.json"><?= e($source['extra_protected']) ?></textarea>
        <p class="help">Never uploaded, overwritten, deleted or read on this pipeline's sites. One path per line from the app folder; end folders with /; <code>*</code> stands for one folder name.</p>
        <?= $error('extra_protected') ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="primary">Save local</button>
        <a class="button" href="index.php">Cancel</a>
    </div>
</form>
