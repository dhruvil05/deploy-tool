<?php
/**
 * Settings, one section at a time: a menu on the left, the chosen section on the right,
 * and one Save button for all of them.
 *
 * @var array $values
 * @var array $errors
 * @var string $gitBinary
 * @var string $phpBinary
 * @var string $sshBinary
 * @var array $storage
 * @var bool $aiAllowed
 * @var array $ai Ai::settings(), or the posted values after a mistake
 * @var bool $aiKeySaved
 * @var string $tab the section to show
 * @var array $cloud CloudBackup::settings(), or the posted values after a mistake
 * @var array $cloudSaved CloudBackup::settings()
 */

use DeployTool\CloudBackup;
use DeployTool\FtpTransport;
use DeployTool\Http;
use DeployTool\ProtectedPaths;
use DeployTool\Settings;
use DeployTool\View;

$hasError = static fn (string $field): string => isset($errors[$field]) ? ' has-error' : '';
$error = static fn (string $field): string => isset($errors[$field]) ? '<p class="error-text">' . e($errors[$field]) . '</p>' : '';
$programs = [
    'git_path' => ['Git', $gitBinary, 'Reads commits and files from your locals.'],
    'php_path' => ['PHP', $phpBinary, 'Runs the PHP syntax check before uploads.'],
    'ssh_path' => ['SSH', $sshBinary, "Connects to SSH sites. Blank uses Windows' own OpenSSH."],
];
$removedDefaults = array_diff(ProtectedPaths::DEFAULTS, ProtectedPaths::parse($values['protected_paths']));
$protectedCount = count(ProtectedPaths::parse($values['protected_paths']));

$icon = static fn (string $path): string => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
// tab => [label, short description, icon, badge]
$tabs = [
    'general' => ['General', 'Your name', $icon('<circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path>'), ''],
    'deploys' => ['Runs and deploys', 'Review time, compare, background', $icon('<path d="M12 3v12M7 10l5 5 5-5"></path><path d="M4 21h16"></path>'), ''],
    'protected' => ['Protected paths', 'Never touched on any site', $icon('<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"></path>'), (string) $protectedCount],
    'programs' => ['Programs', 'Git, PHP and SSH', $icon('<rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M7 9l3 3-3 3M13 15h4"></path>'), ''],
    'ai' => ['AI assistant', $aiAllowed ? 'Provider, model and limits' : 'Turned off in .env', $icon('<rect x="4" y="8" width="16" height="12" rx="3"></rect><path d="M12 8V4M9 14h.01M15 14h.01"></path>'), $aiAllowed ? ($ai['enabled'] === '1' ? 'On' : 'Off') : ''],
    'permissions' => ['AI permissions', $aiAllowed ? 'What the assistant may do' : 'Turned off in .env', $icon('<rect x="5" y="11" width="14" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path>'), $aiAllowed ? count(array_filter(array_keys(\DeployTool\Ai::PERMISSIONS), static fn (string $key): bool => ($ai['perm_' . $key] ?? '0') === '1')) . '/' . count(\DeployTool\Ai::PERMISSIONS) : ''],
    'cloud' => ['Cloud backup', CloudBackup::configured($cloudSaved) ? CloudBackup::SCHEDULES[$cloudSaved['schedule']] : 'Encrypted copies in the cloud', $icon('<path d="M7 18a5 5 0 1 1 .9-9.9A6 6 0 0 1 19 10a4 4 0 0 1-1 7.9z"></path><path d="M12 12v6M9.5 14.5 12 12l2.5 2.5"></path>'), CloudBackup::configured($cloudSaved) && $cloudSaved['schedule'] !== 'off' ? 'On' : ''],
    'storage' => ['Storage', 'Where the tool keeps its data', $icon('<ellipse cx="12" cy="6" rx="8" ry="3"></ellipse><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"></path>'), ''],
];
$errorTabs = array_unique(array_map([Settings::class, 'tabOf'], array_keys($errors)));
$pane = static fn (string $key): string => ' id="tab-' . $key . '" class="settings-pane js-settings-pane" data-tab="' . $key . '" role="tabpanel" aria-labelledby="tab-link-' . $key . '"' . ($key === $tab ? '' : ' hidden');
?>
<div class="page-head">
    <h1>Settings</h1>
</div>
<p class="lead">Settings for this computer. Repositories and branches are set per local on the Sites page.</p>

<div class="settings-shell">
    <nav class="settings-menu" role="tablist" aria-label="Settings sections">
        <?php foreach ($tabs as $key => [$label, $hint, $svg, $badge]): ?>
            <a href="index.php?r=settings&amp;tab=<?= e($key) ?>" id="tab-link-<?= e($key) ?>" class="js-settings-tab<?= $key === $tab ? ' active' : '' ?>" data-tab="<?= e($key) ?>" role="tab" aria-selected="<?= $key === $tab ? 'true' : 'false' ?>" aria-controls="tab-<?= e($key) ?>">
                <span class="settings-menu-icon"><?= $svg ?></span>
                <span class="settings-menu-text"><strong><?= e($label) ?></strong><span><?= e($hint) ?></span></span>
                <?php if (in_array($key, $errorTabs, true)): ?>
                    <span class="settings-menu-error" title="Needs a fix">!</span>
                <?php elseif ($badge !== ''): ?>
                    <span class="pill<?= $badge === 'On' ? ' pill-success' : '' ?>"><?= e($badge) ?></span>
                <?php endif ?>
            </a>
        <?php endforeach ?>
    </nav>

    <form method="post" action="index.php?r=settings-save" id="settings-form" class="settings-content js-settings-form" novalidate>
        <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
        <input type="hidden" name="tab" value="<?= e($tab) ?>" class="js-settings-current">

        <?php if ($errors): ?>
            <div class="notice notice-danger">Nothing was saved: <?= count($errors) === 1 ? 'one field needs' : count($errors) . ' fields need' ?> a fix. Sections with a red <strong>!</strong> have them.</div>
        <?php endif ?>

        <section<?= $pane('general') ?>>
            <div class="card settings-card">
                <h2>General</h2>
                <p class="meta">Who you are, for the record every deploy leaves on the server.</p>
                <div class="field narrow-field<?= $hasError('deployer_name') ?>">
                    <label for="deployer_name">Your name</label>
                    <input type="text" id="deployer_name" name="deployer_name" value="<?= e($values['deployer_name']) ?>" required>
                    <p class="help">Recorded on the server with every deploy, so everyone can see who deployed what.</p>
                    <?= $error('deployer_name') ?>
                </div>
            </div>
        </section>

        <section<?= $pane('deploys') ?>>
            <div class="card settings-card">
                <h2>Runs and deploys</h2>
                <p class="meta">How deploys, compares and new sites behave.</p>
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

                <input type="hidden" name="background_runs" value="0">
                <label class="choice-tile ai-toggle">
                    <input type="checkbox" name="background_runs" value="1"<?= $values['background_runs'] === '1' ? ' checked' : '' ?>>
                    <strong>Run in the background</strong>
                    <span>Runs keep going when you close or reload the page. Turn off only if background runs fail to start (see storage\worker.log); the run page then does the work while it stays open.</span>
                </label>
            </div>
        </section>

        <section<?= $pane('protected') ?>>
            <div class="card settings-card">
                <div class="settings-card-head">
                    <h2>Protected on every site</h2>
                    <span class="pill"><?= $protectedCount ?> path<?= $protectedCount === 1 ? '' : 's' ?></span>
                </div>
                <p class="meta">Never uploaded, overwritten, deleted or read on any server. Each local can add more of its own on its page under Sites.</p>
                <div class="field<?= $hasError('protected_paths') ?>">
                    <textarea id="protected_paths" name="protected_paths" rows="14" aria-label="Protected on every site"><?= e($values['protected_paths']) ?></textarea>
                    <p class="help">One path per line from the app folder. End folders with <code>/</code>; <code>*</code> stands for one folder name.</p>
                    <p class="help">Always protected: <?php foreach (ProtectedPaths::LOCKED as $rule): ?><code><?= e($rule) ?></code> <?php endforeach ?>(where the tool records the deployed commit).</p>
                    <?= $error('protected_paths') ?>
                    <?php if ($removedDefaults): ?>
                        <p class="warning-text">Not protected any more: <?= e(implode(', ', $removedDefaults)) ?>. Files there in git will be uploaded over the server's copies, for example customer uploads or the server's own config.</p>
                    <?php endif ?>
                    <button type="button" class="small js-fill" data-target="protected_paths" data-fill="<?= e(implode("\n", ProtectedPaths::DEFAULTS)) ?>">Restore the default list</button>
                </div>
            </div>
        </section>

        <section<?= $pane('programs') ?>>
            <div class="card settings-card">
                <h2>Programs</h2>
                <p class="meta">The programs the tool runs. Leave blank to find them automatically.</p>
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
            </div>
        </section>

        <section<?= $pane('ai') ?>>
            <?= View::partial('settings_ai', ['allowed' => $aiAllowed, 'ai' => $ai, 'keySaved' => $aiKeySaved, 'errors' => $errors]) ?>
        </section>

        <section<?= $pane('permissions') ?>>
            <?= View::partial('settings_permissions', ['allowed' => $aiAllowed, 'ai' => $ai]) ?>
        </section>

        <section<?= $pane('cloud') ?>>
            <?= View::partial('settings_cloud', ['cloud' => $cloud, 'state' => CloudBackup::state(), 'saved' => $cloudSaved, 'errors' => $errors]) ?>
        </section>

        <section<?= $pane('storage') ?>>
            <?php if ($storage['missing'] !== null): ?>
                <div class="notice notice-warning">Your storage folder <code><?= e($storage['missing']) ?></code> isn't available (an unplugged drive?), so the tool is using the folder next to it for now. Plug it back in, or choose another folder below.</div>
            <?php endif ?>
            <div class="card settings-card">
                <div class="settings-card-head">
                    <h2>Storage</h2>
                    <span class="pill"><?= $storage['fromEnv'] ? 'Set by DEPLOY_TOOL_STORAGE' : ($storage['isDefault'] ? 'Default folder' : 'Your folder') ?></span>
                </div>
                <p class="meta">Everything the tool keeps: settings, sites with their passwords, the AI key, runs, backups and chats. Not part of any deploy.</p>
                <dl class="stats">
                    <div><dt>Folder</dt><dd><code><?= e($storage['dir']) ?></code></dd></div>
                    <div><dt>Size</dt><dd><?= e(number_format($storage['bytes'] / 1048576, 1)) ?> MB</dd></div>
                    <div><dt>Runs in history</dt><dd><?= $storage['runs'] ?></dd></div>
                    <div><dt>Backups</dt><dd><?= $storage['backups'] ?> zip<?= $storage['backups'] === 1 ? '' : 's' ?>, <?= e(number_format($storage['backupBytes'] / 1048576, 1)) ?> MB</dd></div>
                </dl>
            </div>

            <div class="card settings-card section storage-move">
                <h2>Storage folder</h2>
                <?php if ($storage['fromEnv']): ?>
                    <p class="meta">This folder is set by the DEPLOY_TOOL_STORAGE environment variable, so it can't be changed here.</p>
                <?php else: ?>
                    <p class="meta">Keep the data somewhere else, for example a folder that is backed up or on another drive. By default it stays in the storage folder next to the tool.</p>
                    <div class="choice-tiles storage-modes" role="radiogroup" aria-label="Where to keep the data">
                        <label class="choice-tile">
                            <input type="radio" name="storage_mode" value="default" form="storage-form" class="js-storage-mode"<?= $storage['isDefault'] ? ' checked' : '' ?>>
                            <strong>Next to the tool (default)</strong>
                            <span><code><?= e($storage['default']) ?></code></span>
                        </label>
                        <label class="choice-tile">
                            <input type="radio" name="storage_mode" value="custom" form="storage-form" class="js-storage-mode"<?= $storage['isDefault'] ? '' : ' checked' ?>>
                            <strong>A folder I choose</strong>
                            <span>Any folder on this computer, outside the tool's own folder.</span>
                        </label>
                    </div>

                    <div class="js-storage-custom"<?= $storage['isDefault'] ? ' hidden' : '' ?>>
                        <div class="field">
                            <label for="storage_path">Folder</label>
                            <div class="input-button">
                                <input type="text" id="storage_path" name="storage_path" form="storage-form" class="mono-input" spellcheck="false"
                                       value="<?= $storage['isDefault'] ? '' : e($storage['dir']) ?>" placeholder="D:\deploy-tool-data">
                                <button type="button" class="js-folder-browse">Browse…</button>
                            </div>
                            <p class="help">Type a path, or browse to a folder. A folder that doesn't exist yet is created.</p>
                        </div>
                        <div class="folder-picker js-folder-picker" hidden>
                            <div class="folder-picker-head">
                                <button type="button" class="small js-folder-up" aria-label="Up one folder">↑ Up</button>
                                <code class="js-folder-current">This computer</code>
                                <button type="button" class="small primary js-folder-use">Use this folder</button>
                            </div>
                            <p class="meta js-folder-note" hidden></p>
                            <ul class="folder-list js-folder-list"></ul>
                        </div>
                    </div>

                    <label class="checkbox storage-copy">
                        <input type="checkbox" name="storage_copy" value="1" form="storage-form" checked>
                        <span>Copy my current data there (<?= e(number_format($storage['bytes'] / 1048576, 1)) ?> MB)</span>
                    </label>
                    <p class="help">Untick to use what's already in that folder, for example data you moved there yourself. The current folder is left as it is either way.</p>

                    <div class="storage-actions">
                        <button type="submit" form="storage-form" class="primary">Switch folder</button>
                        <span class="meta">Acts at once, separately from "Save settings".</span>
                    </div>
                <?php endif ?>
            </div>
        </section>

        <div class="save-bar settings-save">
            <span class="meta">One Save for every section.</span>
            <span class="meta js-settings-dirty" hidden>Unsaved changes</span>
            <button type="submit" class="primary">Save settings</button>
        </div>
    </form>
</div>

<?php // The Storage section's fields belong to this form (form="storage-form"): it acts at once. ?>
<form method="post" action="index.php?r=storage-save" id="storage-form" class="js-confirm"
      data-confirm="Switch the folder the tool keeps its data in? Nothing can run while it copies.">
    <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
</form>
<?php // Settings → Cloud backup's Restore buttons fill and send this form. ?>
<form method="post" action="index.php?r=cloud-restore" id="cloud-restore-form">
    <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
    <input type="hidden" name="key" class="js-cloud-restore-key">
    <input type="hidden" name="passphrase" class="js-cloud-restore-pass">
</form>
