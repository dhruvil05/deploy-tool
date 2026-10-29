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

<div class="local-layout">
<form method="post" action="index.php?r=source-save" class="form card js-local-form" id="local-form">
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

    <p class="meta">This local's protected paths are set in the Protected paths panel and saved with this form.</p>

    <div class="form-actions">
        <button type="submit" class="primary">Save local</button>
        <a class="button" href="index.php">Cancel</a>
    </div>
</form>

<?php $global = ProtectedPaths::global(); ?>
<aside class="card protect-panel js-protect" aria-labelledby="protect-title">
    <h2 id="protect-title">Protected paths</h2>
    <p class="meta">Never uploaded, overwritten, deleted or read on this local's sites.</p>

    <h3 class="protect-heading">This local <span class="pill js-rule-count"><?= count(ProtectedPaths::parse($source['extra_protected'])) ?></span></h3>
    <ul class="rule-rows js-rule-list" aria-live="polite"></ul>

    <details class="protect-text<?= $hasError('extra_protected') ?>" <?= isset($errors['extra_protected']) ? 'open' : '' ?>>
        <summary>Edit as text</summary>
        <textarea id="extra_protected" name="extra_protected" form="local-form" rows="6" aria-label="This local's protected paths, one per line" placeholder="resources/&#10;package.json"><?= e($source['extra_protected']) ?></textarea>
        <p class="help">One path per line from the app folder. End folders with <code>/</code>; <code>*</code> stands for one folder name.</p>
        <?= $error('extra_protected') ?>
    </details>

    <details class="protect-text">
        <summary>Every site <span class="pill"><?= count($global) ?></span> <a class="meta" href="index.php?r=settings">change in Settings</a></summary>
        <p class="code-list"><?php foreach ($global as $rule): ?><code><?= e($rule) ?></code> <?php endforeach ?></p>
    </details>

    <h3 class="protect-heading">Add more</h3>
    <div class="tabs" role="tablist">
        <button type="button" role="tab" class="js-tab" data-tab="server" aria-selected="false">On the server <span class="pill js-tab-count"></span></button>
        <button type="button" role="tab" class="js-tab" data-tab="suggest" aria-selected="false">Suggested <span class="pill js-tab-count"></span></button>
        <button type="button" role="tab" class="js-tab" data-tab="browse" aria-selected="false">All folders</button>
    </div>
    <p class="meta js-protect-status">Loading the repository's folders…</p>
    <div class="tab-panel js-panel" data-tab="server" hidden>
        <p class="meta">Found by compares: on a server but not in git, so usually server data.</p>
        <ul class="pick-rows"></ul>
    </div>
    <div class="tab-panel js-panel" data-tab="suggest" hidden>
        <p class="meta">Folders that are usually generated or server data.</p>
        <ul class="pick-rows"></ul>
    </div>
    <div class="tab-panel js-panel" data-tab="browse" hidden>
        <input type="search" class="pick-search js-picker-search" placeholder="Find a folder, for example vendor" aria-label="Find a folder">
        <ul class="pick-rows pick-tree"></ul>
        <p class="meta js-picker-ref"></p>
    </div>

    <div class="protect-save">
        <button type="submit" form="local-form" class="primary">Save local</button>
        <span class="meta js-unsaved" hidden>Unsaved changes</span>
    </div>
</aside>
</div>
