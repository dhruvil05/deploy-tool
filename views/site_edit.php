<?php
/**
 * Add or edit a site: basics, where it is and how to log in, and its web address.
 *
 * @var array $site
 * @var array $errors
 * @var ?string $originalKey
 * @var array $sourceOptions
 */

use DeployTool\FtpTransport;
use DeployTool\Http;
use DeployTool\Sites;

$hasError = static fn (string $field): string => isset($errors[$field]) ? ' has-error' : '';
$error = static fn (string $field): string => isset($errors[$field]) ? '<p class="error-text">' . e($errors[$field]) . '</p>' : '';
$type = $site['type'];
$types = [
    'ftp' => ['FTPS', 'A server you reach over FTP. Works with any host.'],
    'ssh' => ['SSH', 'Fastest: the server reads and writes files in batches. Needs SSH access.'],
    'folder' => ['Folder', 'A folder on this computer, for testing.'],
];
?>
<header class="card run-hero">
    <p class="run-hero-kind"><span class="run-kind kind-deploy">Site</span><?php if ($site['production']): ?><span class="badge badge-danger">Production</span><?php endif ?></p>
    <h1><?= $originalKey === null ? 'Add a site' : e($site['name']) ?></h1>
    <p class="meta">A site is where code gets deployed: a server or a test folder. It gets its code from a local or from another site.</p>
</header>

<?php if ($errors): ?>
    <div class="notice notice-danger">Some fields need a fix: they're marked below.</div>
<?php endif ?>

<form method="post" action="index.php?r=site-save" class="edit-grid" id="site-form" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
    <input type="hidden" name="original_key" value="<?= e($originalKey ?? '') ?>">

    <div class="settings-col">
        <section class="card settings-card">
            <h2>Basics</h2>
            <div class="field-row">
                <div class="field<?= $hasError('name') ?>">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" value="<?= e($site['name']) ?>" placeholder="Staging site" required>
                    <?= $error('name') ?>
                </div>
                <div class="field<?= $hasError('key') ?>">
                    <label for="key">Key</label>
                    <input type="text" id="key" name="key" value="<?= e($site['key']) ?>" placeholder="staging-site" <?= $originalKey !== null ? 'readonly' : 'required' ?>>
                    <?= $error('key') ?>
                </div>
            </div>
            <p class="help field-note">The key names backups and history, so it can't be changed later.</p>

            <div class="field<?= $hasError('source') ?>">
                <label for="source">Gets code from</label>
                <select id="source" name="source">
                    <?php foreach ($sourceOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $site['source'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <p class="help">Staging gets your local branch. Prod gets exactly the commit that is on the staging site.</p>
                <?= $error('source') ?>
            </div>

            <label class="choice-tile toggle-tile">
                <input type="checkbox" name="production" value="1" <?= $site['production'] ? 'checked' : '' ?>>
                <strong>Production site</strong>
                <span>Deploys must be confirmed by typing the site key, and files removed in git are never deleted automatically.</span>
            </label>
        </section>

        <section class="card settings-card">
            <h2>Website</h2>
            <div class="field<?= $hasError('url') ?>">
                <label for="url">Site address</label>
                <input type="text" id="url" name="url" value="<?= e($site['url']) ?>" placeholder="https://">
                <p class="help">Used for the site check after a deploy and, on FTP sites, by the fingerprint compare. Optional for SSH and folder sites.</p>
                <?= $error('url') ?>
            </div>
            <div class="field<?= $hasError('check_path') ?>">
                <label for="check_path">Check page</label>
                <input type="text" id="check_path" name="check_path" value="<?= e($site['check_path']) ?>" placeholder="/login">
                <p class="help">Loaded after every deploy; it must answer HTTP 200 without PHP errors. Leave blank to skip the check.</p>
                <?= $error('check_path') ?>
            </div>
        </section>
    </div>

    <div class="settings-col">
        <section class="card settings-card">
            <h2>Where the site is</h2>
            <div class="choice-tiles type-tiles" role="radiogroup" aria-label="Where the site is">
                <?php foreach ($types as $value => [$label, $help]): ?>
                    <label class="choice-tile">
                        <input type="radio" name="type" value="<?= e($value) ?>" class="js-site-type" <?= $type === $value ? 'checked' : '' ?>>
                        <strong><?= e($label) ?><?php if ($value === 'ssh'): ?> <span class="pill pill-success">fastest</span><?php endif ?></strong>
                        <span><?= e($help) ?></span>
                    </label>
                <?php endforeach ?>
            </div>

            <div class="js-type-group type-fields" data-type="ftp" <?= $type === 'ftp' ? '' : 'hidden' ?>>
                <div class="field-row">
                    <div class="field<?= $hasError('host') ?>">
                        <label for="host">FTP host</label>
                        <input type="text" id="host" name="host" value="<?= e($site['host']) ?>" placeholder="ftp.example.com">
                        <?= $error('host') ?>
                    </div>
                    <div class="field field-small<?= $hasError('port') ?>">
                        <label for="port">Port</label>
                        <input type="number" id="port" name="port" value="<?= (int) $site['port'] ?>" min="1" max="65535">
                        <?= $error('port') ?>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field<?= $hasError('username') ?>">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" value="<?= e($site['username']) ?>">
                        <?= $error('username') ?>
                    </div>
                    <div class="field<?= $hasError('password') ?>">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" value="" autocomplete="new-password"
                               placeholder="<?= $site['password'] !== '' ? 'Saved. Blank keeps it.' : '' ?>">
                        <?= $error('password') ?>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field<?= $hasError('root') ?>">
                        <label for="root">FTP folder</label>
                        <input type="text" id="root" name="root" value="<?= e($site['root']) ?>" placeholder="/">
                        <?= $error('root') ?>
                    </div>
                    <div class="field field-small<?= $hasError('connections') ?>">
                        <label for="connections">Connections</label>
                        <input type="number" id="connections" name="connections" value="<?= (int) $site['connections'] ?>" min="1" max="<?= FtpTransport::MAX_CONNECTIONS ?>">
                        <?= $error('connections') ?>
                    </div>
                </div>
                <p class="help field-note">The folder that holds index.php, counted from where the login lands (/ when it lands right in the app folder). Files go over this many connections side by side: 4 is safe on most hosts, 1 if the server limits connections.</p>
                <label class="checkbox">
                    <input type="checkbox" name="verify_tls" value="1" <?= $site['verify_tls'] ? 'checked' : '' ?>>
                    <span>Check the server's certificate</span>
                </label>
                <p class="help">Leave this off when the host is an IP address: the certificate can't match it. The connection is encrypted either way.</p>
            </div>

            <div class="js-type-group type-fields" data-type="ssh" <?= $type === 'ssh' ? '' : 'hidden' ?>>
                <div class="field-row">
                    <div class="field<?= $hasError('ssh_host') ?>">
                        <label for="ssh_host">SSH host</label>
                        <input type="text" id="ssh_host" name="ssh_host" value="<?= e($site['ssh_host']) ?>">
                        <?= $error('ssh_host') ?>
                    </div>
                    <div class="field field-small<?= $hasError('ssh_port') ?>">
                        <label for="ssh_port">Port</label>
                        <input type="number" id="ssh_port" name="ssh_port" value="<?= (int) $site['ssh_port'] ?>" min="1" max="65535">
                        <?= $error('ssh_port') ?>
                    </div>
                </div>
                <div class="field<?= $hasError('ssh_user') ?>">
                    <label for="ssh_user">Username</label>
                    <input type="text" id="ssh_user" name="ssh_user" value="<?= e($site['ssh_user']) ?>">
                    <p class="help">On cPanel this is the cPanel account name, not an FTP sub-account.</p>
                    <?= $error('ssh_user') ?>
                </div>
                <div class="field<?= $hasError('ssh_key') ?>">
                    <label for="ssh_key">Private key file</label>
                    <input type="text" id="ssh_key" name="ssh_key" value="<?= e($site['ssh_key']) ?>" placeholder="C:\Users\you\.ssh\id_ed25519">
                    <p class="help">SSH sites log in with a key without a passphrase, never a password. Add its public half on the server (cPanel → SSH Access → Manage SSH Keys → Import, then Authorize). Blank uses your default key.</p>
                    <?= $error('ssh_key') ?>
                </div>
                <div class="field-row">
                    <div class="field<?= $hasError('ssh_root') ?>">
                        <label for="ssh_root">Folder on the server</label>
                        <input type="text" id="ssh_root" name="ssh_root" value="<?= e($site['ssh_root']) ?>" placeholder="~/public_html/office">
                        <?= $error('ssh_root') ?>
                    </div>
                    <div class="field field-small<?= $hasError('ssh_php') ?>">
                        <label for="ssh_php">PHP command</label>
                        <input type="text" id="ssh_php" name="ssh_php" value="<?= e($site['ssh_php']) ?>">
                        <?= $error('ssh_php') ?>
                    </div>
                </div>
                <p class="help field-note">The folder that holds index.php: a full path, or one starting with ~/ for your home folder. The PHP command runs the tool's small helper there; usually just php.</p>
            </div>

            <div class="js-type-group type-fields" data-type="folder" <?= $type === 'folder' ? '' : 'hidden' ?>>
                <div class="field<?= $hasError('path') ?>">
                    <label for="path">Folder</label>
                    <input type="text" id="path" name="path" value="<?= e($site['path']) ?>" placeholder="C:\deploy-test\staging">
                    <p class="help">A plain copy of the site's files, not a git folder. The tool treats it exactly like a server.</p>
                    <?= $error('path') ?>
                    <?php if (!isset($errors['path']) && ($warning = Sites::checkoutWarning($site)) !== null): ?>
                        <p class="warning-text"><?= e($warning) ?></p>
                    <?php endif ?>
                </div>
            </div>
        </section>
    </div>

    <div class="save-bar">
        <span class="meta">Passwords stay on this computer in storage/sites.json.</span>
        <a class="button" href="index.php?r=sites">Cancel</a>
        <button type="submit" class="primary">Save site</button>
    </div>
</form>
