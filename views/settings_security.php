<?php
/**
 * Settings → Security: the tool's password, how saved passwords are kept, and the last
 * check that the data folder isn't served on the web. The password fields belong to
 * #security-form, which acts at once.
 *
 * @var array $security
 */

use DeployTool\Auth;

$secrets = $security['secrets'];
$exposure = $security['exposure'];
?>
<div class="card settings-card">
    <div class="settings-card-head">
        <h2>Password</h2>
        <span class="pill<?= $security['password'] ? ' pill-success' : '' ?>"><?= $security['password'] ? 'On' : 'Off' ?></span>
    </div>
    <p class="meta"><?= $security['password']
        ? 'Every page asks for this password first. A browser stays signed in while it is used; after 8 idle hours it asks again. Lock (top right) signs out at once.'
        : 'Without a password, anyone who can use this computer, including other Windows users and programs running on it, can open the tool and deploy with the saved FTP passwords.' ?></p>

    <div class="set-pair">
        <?php if ($security['password']): ?>
            <div class="field">
                <label for="sec_current">Current password</label>
                <input type="password" id="sec_current" name="current" form="security-form" autocomplete="current-password">
            </div>
            <div></div>
        <?php endif ?>
        <div class="field">
            <label for="sec_new"><?= $security['password'] ? 'New password' : 'Password' ?></label>
            <input type="password" id="sec_new" name="new" form="security-form" autocomplete="new-password" minlength="<?= Auth::MIN_LENGTH ?>">
            <p class="help">At least <?= Auth::MIN_LENGTH ?> characters.</p>
        </div>
        <div class="field">
            <label for="sec_repeat">Type it again</label>
            <input type="password" id="sec_repeat" name="repeat" form="security-form" autocomplete="new-password">
        </div>
    </div>
    <div class="cloud-actions">
        <button type="submit" form="security-form" name="action" value="save" class="primary"><?= $security['password'] ? 'Change password' : 'Set password' ?></button>
        <?php if ($security['password']): ?>
            <button type="submit" form="security-form" name="action" value="remove" class="danger">Remove password</button>
        <?php endif ?>
        <span class="meta">Acts at once, separately from "Save settings". Forgot it? Delete <code>auth.json</code> in the data folder.</span>
    </div>
</div>

<div class="card settings-card section">
    <div class="settings-card-head">
        <h2>Saved passwords and keys</h2>
        <span class="pill<?= $secrets['ready'] ? ' pill-success' : ' pill-warning' ?>"><?= $secrets['ready'] ? 'Encrypted' : 'Not encrypted' ?></span>
    </div>
    <?php if ($secrets['ready']): ?>
        <p class="meta">FTP passwords, the AI key and the cloud keys are encrypted in the data folder with a key kept outside it, so a copied, synced or leaked data folder shows nothing readable.</p>
        <dl class="stats">
            <div><dt>Key file</dt><dd><code><?= e($secrets['file']) ?></code><?= $secrets['fromEnv'] ? ' <span class="pill">Set by DEPLOY_TOOL_KEY_FILE</span>' : '' ?></dd></div>
        </dl>
        <p class="set-callout">Keep this key: without it the saved passwords must be typed again. A cloud backup carries it (encrypted with your passphrase), so a restore on a new computer brings it along.</p>
    <?php else: ?>
        <p class="meta">There is no safe place for the key on this computer<?= $secrets['file'] !== null ? ' (' . e($secrets['file']) . ' could not be used)' : '' ?>, so passwords are kept as plain text in the data folder. Set <code>DEPLOY_TOOL_KEY_FILE</code> in the .env file to a path outside the tool's folder and the data folder, for example <code>C:\Users\you\deploy-tool.key</code>.</p>
    <?php endif ?>
</div>

<div class="card settings-card section">
    <div class="settings-card-head">
        <h2>Web check</h2>
        <?php if (isset($exposure['at'])): ?>
            <span class="pill<?= !empty($exposure['exposed']) ? ' pill-warning' : ' pill-success' ?>"><?= !empty($exposure['exposed']) ? 'Data folder served!' : 'Data folder not served' ?></span>
        <?php else: ?>
            <span class="pill"><?= PHP_SAPI === 'cli-server' ? 'Not needed' : 'Not checked yet' ?></span>
        <?php endif ?>
    </div>
    <p class="meta">Once a day the tool asks its own web server for a random file it puts in the data folder. If the server hands it out, every page shows a warning until it's fixed.<?= isset($exposure['at']) ? ' Last checked ' . e(ago((int) $exposure['at'])) . '.' : (PHP_SAPI === 'cli-server' ? ' Not needed here: started with start.bat, only index.php and assets/ are served.' : '') ?></p>
</div>
