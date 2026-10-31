<?php
/**
 * Add or edit a local: its repository and branch, and the paths protected on its sites.
 *
 * @var array $source
 * @var array $errors
 * @var ?string $originalKey
 */

use DeployTool\Http;
use DeployTool\ProtectedPaths;

$hasError = static fn (string $field): string => isset($errors[$field]) ? ' has-error' : '';
$error = static fn (string $field): string => isset($errors[$field]) ? '<p class="error-text">' . e($errors[$field]) . '</p>' : '';
$global = ProtectedPaths::global();
?>
<header class="card run-hero">
    <p class="run-hero-kind"><span class="run-kind kind-compare">Local</span></p>
    <h1><?= $originalKey === null ? 'Add a local' : e($source['name']) ?></h1>
    <p class="meta">A local starts a pipeline: a git repository on this computer and the branch its first site gets. Files are always uploaded from commits, never from the working folder.</p>
</header>

<?php if ($errors): ?>
    <div class="notice notice-danger">Some fields need a fix: they're marked below.</div>
<?php endif ?>

<form method="post" action="index.php?r=source-save" class="card settings-card js-local-form" id="local-form">
    <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
    <input type="hidden" name="original_key" value="<?= e($originalKey ?? '') ?>">
    <h2>Repository</h2>
    <div class="repo-grid">
        <div class="field<?= $hasError('name') ?>">
            <label for="name">Name</label>
            <input type="text" id="name" name="name" value="<?= e($source['name']) ?>" placeholder="Backoffice" required>
            <?= $error('name') ?>
        </div>
        <div class="field<?= $hasError('key') ?>">
            <label for="key">Key <span class="meta">can't change later</span></label>
            <input type="text" id="key" name="key" value="<?= e($source['key']) ?>" placeholder="backoffice" <?= $originalKey !== null ? 'readonly' : 'required' ?>>
            <?= $error('key') ?>
        </div>
        <div class="field repo-path<?= $hasError('repo_path') ?>">
            <label for="repo_path">Repository folder</label>
            <input type="text" id="repo_path" name="repo_path" value="<?= e($source['repo_path']) ?>" placeholder="C:\laragon\www\backoffice-bkp" required>
            <?= $error('repo_path') ?>
        </div>
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
</form>

<section class="card settings-card section protect-card js-protect" aria-labelledby="protect-title">
    <div class="settings-card-head">
        <h2 id="protect-title">Protected paths</h2>
        <span class="pill"><span class="js-rule-count"><?= count(ProtectedPaths::parse($source['extra_protected'])) ?></span> for this local</span>
    </div>
    <p class="meta">Files the tool never uploads, overwrites, deletes or reads on this local's sites: server data like uploads, generated files, vendor folders.</p>

    <div class="protect-grid">
        <div>
            <h3 class="protect-heading">Protected now <span class="meta js-rule-summary"></span></h3>
            <ul class="rule-rows js-rule-list" aria-live="polite"></ul>

            <details class="protect-text<?= $hasError('extra_protected') ?>" <?= isset($errors['extra_protected']) ? 'open' : '' ?>>
                <summary>Edit as text</summary>
                <textarea id="extra_protected" name="extra_protected" form="local-form" rows="8" aria-label="This local's protected paths, one per line" placeholder="resources/&#10;package.json"><?= e($source['extra_protected']) ?></textarea>
                <p class="help">One path per line from the app folder. End folders with <code>/</code>; <code>*</code> stands for one folder name.</p>
                <?= $error('extra_protected') ?>
            </details>
            <details class="protect-text">
                <summary>Also protected on every site <span class="pill"><?= count($global) ?></span></summary>
                <p class="code-list"><?php foreach ($global as $rule): ?><code><?= e($rule) ?></code> <?php endforeach ?></p>
                <p class="help"><a href="index.php?r=settings">Change this list in Settings</a></p>
            </details>
        </div>

        <div class="protect-add">
            <h3 class="protect-heading">Add paths</h3>
            <div class="segmented tab-switch" role="tablist">
                <button type="button" role="tab" class="js-tab" data-tab="server" aria-selected="false"><span>On the server <span class="pill js-tab-count"></span></span></button>
                <button type="button" role="tab" class="js-tab" data-tab="suggest" aria-selected="false"><span>Suggested <span class="pill js-tab-count"></span></span></button>
                <button type="button" role="tab" class="js-tab" data-tab="browse" aria-selected="false"><span>All folders</span></button>
            </div>
            <p class="meta js-protect-status">Loading the repository's folders…</p>
            <div class="tab-panel js-panel" data-tab="server" hidden>
                <p class="meta">Found by compares: on a server but not in git, so usually server data. Tick to protect.</p>
                <ul class="pick-rows"></ul>
            </div>
            <div class="tab-panel js-panel" data-tab="suggest" hidden>
                <p class="meta">Folders that are usually generated or server data. Tick to protect.</p>
                <ul class="pick-rows"></ul>
            </div>
            <div class="tab-panel js-panel" data-tab="browse" hidden>
                <input type="search" class="pick-search js-picker-search" placeholder="Find a folder, for example vendor" aria-label="Find a folder">
                <ul class="pick-rows pick-tree"></ul>
                <p class="meta js-picker-ref"></p>
            </div>
        </div>
    </div>
</section>

<div class="save-bar">
    <span class="meta js-unsaved" hidden>Unsaved changes to protected paths</span>
    <a class="button" href="index.php?r=sites">Cancel</a>
    <button type="submit" form="local-form" class="primary">Save local</button>
</div>
