<?php
/**
 * @var array $site
 * @var array $errors
 * @var ?string $originalKey
 * @var array $sourceOptions
 */

use DeployTool\Http;

$hasError = static fn (string $field): string => isset($errors[$field]) ? ' has-error' : '';
$error = static fn (string $field): string => isset($errors[$field]) ? '<p class="error-text">' . e($errors[$field]) . '</p>' : '';
$isFolder = $site['type'] === 'folder';
$type = $site['type'];
?>
<h1><?= $originalKey === null ? 'Add site' : 'Edit ' . e($site['name']) ?></h1>

<form method="post" action="index.php?r=site-save" class="form card" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
    <input type="hidden" name="original_key" value="<?= e($originalKey ?? '') ?>">

    <div class="field<?= $hasError('name') ?>">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" value="<?= e($site['name']) ?>" required>
        <?= $error('name') ?>
    </div>

    <div class="field<?= $hasError('key') ?>">
        <label for="key">Key</label>
        <input type="text" id="key" name="key" value="<?= e($site['key']) ?>" <?= $originalKey !== null ? 'readonly' : 'required' ?>>
        <p class="help">Short id used in backups and history, for example staging-site. It can't be changed later.</p>
        <?= $error('key') ?>
    </div>

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

    <div class="field">
        <label class="checkbox">
            <input type="checkbox" name="production" value="1" <?= $site['production'] ? 'checked' : '' ?>>
            <span>Production site</span>
        </label>
        <p class="help">Deploys must be confirmed by typing the site key, and files removed in git are never deleted automatically.</p>
    </div>

    <div class="field">
        <label for="type">Where the site is</label>
        <select id="type" name="type" class="js-site-type">
            <option value="ftp" <?= $type === 'ftp' ? 'selected' : '' ?>>On a server (FTPS)</option>
            <option value="ssh" <?= $type === 'ssh' ? 'selected' : '' ?>>On a server (SSH, fastest)</option>
            <option value="folder" <?= $isFolder ? 'selected' : '' ?>>A folder on this computer (for testing)</option>
        </select>
    </div>

    <div class="js-type-group" data-type="ssh" <?= $type === 'ssh' ? '' : 'hidden' ?>>
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
            <label for="ssh_user">SSH username</label>
            <input type="text" id="ssh_user" name="ssh_user" value="<?= e($site['ssh_user']) ?>">
            <p class="help">On cPanel this is the cPanel account name, not an FTP sub-account.</p>
            <?= $error('ssh_user') ?>
        </div>

        <div class="field<?= $hasError('ssh_key') ?>">
            <label for="ssh_key">Private key file</label>
            <input type="text" id="ssh_key" name="ssh_key" value="<?= e($site['ssh_key']) ?>" placeholder="C:\Users\you\.ssh\id_ed25519">
            <p class="help">SSH sites log in with a key, never a password. The key must have no passphrase, and its public half must be added on the server (cPanel → SSH Access → Manage SSH Keys → Import, then Authorize). Leave blank to use your default key.</p>
            <?= $error('ssh_key') ?>
        </div>

        <div class="field<?= $hasError('ssh_root') ?>">
            <label for="ssh_root">Folder on the server</label>
            <input type="text" id="ssh_root" name="ssh_root" value="<?= e($site['ssh_root']) ?>" placeholder="~/public_html/office">
            <p class="help">The folder that holds index.php: a full path, or one starting with ~/ for your home folder.</p>
            <?= $error('ssh_root') ?>
        </div>

        <div class="field<?= $hasError('ssh_php') ?>">
            <label for="ssh_php">PHP command on the server</label>
            <input type="text" id="ssh_php" name="ssh_php" value="<?= e($site['ssh_php']) ?>">
            <p class="help">The tool runs a small helper with this command to read and write files in batches. Usually just php.</p>
            <?= $error('ssh_php') ?>
        </div>
    </div>

    <div class="js-type-group" data-type="folder" <?= $isFolder ? '' : 'hidden' ?>>
        <div class="field<?= $hasError('path') ?>">
            <label for="path">Folder</label>
            <input type="text" id="path" name="path" value="<?= e($site['path']) ?>" placeholder="C:\deploy-test\staging">
            <p class="help">A plain copy of the site's files, not a git folder. The tool treats it exactly like a server.</p>
            <?= $error('path') ?>
            <?php if (!isset($errors['path']) && ($warning = \DeployTool\Sites::checkoutWarning($site)) !== null): ?>
                <p class="warning-text"><?= e($warning) ?></p>
            <?php endif ?>
        </div>
    </div>

    <div class="js-type-group" data-type="ftp" <?= $type === 'ftp' ? '' : 'hidden' ?>>
        <div class="field-row">
            <div class="field<?= $hasError('host') ?>">
                <label for="host">FTP host</label>
                <input type="text" id="host" name="host" value="<?= e($site['host']) ?>">
                <?= $error('host') ?>
            </div>
            <div class="field field-small<?= $hasError('port') ?>">
                <label for="port">Port</label>
                <input type="number" id="port" name="port" value="<?= (int) $site['port'] ?>" min="1" max="65535">
                <?= $error('port') ?>
            </div>
        </div>

        <div class="field<?= $hasError('username') ?>">
            <label for="username">FTP username</label>
            <input type="text" id="username" name="username" value="<?= e($site['username']) ?>">
            <?= $error('username') ?>
        </div>

        <div class="field<?= $hasError('password') ?>">
            <label for="password">FTP password</label>
            <input type="password" id="password" name="password" value="" autocomplete="new-password"
                   placeholder="<?= $site['password'] !== '' ? 'Saved. Leave blank to keep it.' : '' ?>">
            <?= $error('password') ?>
        </div>

        <div class="field<?= $hasError('root') ?>">
            <label for="root">FTP folder</label>
            <input type="text" id="root" name="root" value="<?= e($site['root']) ?>">
            <p class="help">The folder that holds index.php, counted from where the FTP login lands. Use / when the login lands right in the app folder.</p>
            <?= $error('root') ?>
        </div>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="verify_tls" value="1" <?= $site['verify_tls'] ? 'checked' : '' ?>>
                <span>Check the server's certificate</span>
            </label>
            <p class="help">Leave this off when the host is an IP address: the certificate can't match it. The connection is encrypted either way.</p>
        </div>

        <div class="field field-small<?= $hasError('connections') ?>">
            <label for="connections">Connections at once</label>
            <input type="number" id="connections" name="connections" value="<?= (int) $site['connections'] ?>" min="1" max="<?= \DeployTool\FtpTransport::MAX_CONNECTIONS ?>">
            <p class="help">Files are sent and checked over this many connections side by side. 4 is safe on most hosts; use 1 if the server limits connections.</p>
            <?= $error('connections') ?>
        </div>
    </div>

    <div class="field<?= $hasError('url') ?>">
        <label for="url">Site address</label>
        <input type="text" id="url" name="url" value="<?= e($site['url']) ?>" placeholder="https://">
        <p class="help">Used for the site check after a deploy and, on FTP sites, by the fingerprint compare. Optional for SSH and folder sites.</p>
        <?= $error('url') ?>
    </div>

    <div class="field<?= $hasError('check_path') ?>">
        <label for="check_path">Check page</label>
        <input type="text" id="check_path" name="check_path" value="<?= e($site['check_path']) ?>">
        <p class="help">Loaded after every deploy; it must answer HTTP 200 without PHP errors. Leave blank to skip the check.</p>
        <?= $error('check_path') ?>
    </div>

    <div class="form-actions">
        <button type="submit" class="primary">Save site</button>
        <a class="button" href="index.php?r=sites">Cancel</a>
    </div>
</form>
