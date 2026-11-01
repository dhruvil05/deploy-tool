<?php
/**
 * Settings → Cloud backup (its fields are part of the settings form; the buttons act at once).
 *
 * @var array $cloud CloudBackup::settings(), or the posted values after a mistake
 * @var array $state CloudBackup::state()
 * @var array $saved CloudBackup::settings() as saved (for "saved" hints)
 * @var array $errors
 */

use DeployTool\CloudBackup;

$hasError = static fn (string $field): string => isset($errors[$field]) ? ' has-error' : '';
$error = static fn (string $field): string => isset($errors[$field]) ? '<p class="error-text">' . e($errors[$field]) . '</p>' : '';
$ready = CloudBackup::configured($saved);
?>
<div class="card settings-card">
    <div class="settings-card-head">
        <h2>Cloud backup</h2>
        <span class="pill<?= $ready && $saved['schedule'] !== 'off' ? ' pill-success' : '' ?>"><?= $ready ? e(CloudBackup::SCHEDULES[$saved['schedule']]) : 'Not set up' ?></span>
    </div>
    <p class="meta">Keeps encrypted copies of the tool's data in S3-compatible storage. Everything is encrypted on this computer with your passphrase before it leaves, so the provider never sees your FTP passwords or keys. The tool keeps working from its local folder.</p>

    <div class="cloud-status">
        <?php if ($state['running_since'] !== null && time() - (int) $state['running_since'] < 1800): ?>
            <span class="badge badge-info">Backing up now…</span>
        <?php endif ?>
        <?php if ($state['at'] !== null): ?>
            <span>Last backup <strong><?= e(ago((int) $state['at'])) ?></strong> · <?= e(number_format((int) $state['size'] / 1048576, 2)) ?> MB · <code><?= e(basename((string) $state['name'])) ?></code></span>
        <?php else: ?>
            <span class="meta">No backup made yet.</span>
        <?php endif ?>
        <?php if ($state['error'] !== null && (int) $state['error_at'] >= (int) $state['at']): ?>
            <p class="error-text">The last try failed <?= e(ago((int) $state['error_at'])) ?>: <?= e($state['error']) ?></p>
        <?php endif ?>
    </div>

    <div class="ai-grid">
        <div>
            <fieldset class="field<?= $hasError('cloud_provider') ?>">
                <legend>Provider</legend>
                <div class="segmented">
                    <?php foreach (CloudBackup::PRESETS as $key => [$label, $endpoint, $region]): ?>
                        <label><input type="radio" name="cloud_provider" value="<?= e($key) ?>" class="js-cloud-provider" data-endpoint="<?= e($endpoint) ?>" data-region="<?= e($region) ?>"<?= $cloud['provider'] === $key ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                    <?php endforeach ?>
                </div>
                <p class="help">Any S3-compatible storage works with Other (MinIO, DigitalOcean Spaces…).</p>
            </fieldset>
            <div class="field<?= $hasError('cloud_endpoint') ?>">
                <label for="cloud_endpoint">Endpoint</label>
                <input type="text" id="cloud_endpoint" name="cloud_endpoint" value="<?= e($cloud['endpoint']) ?>" class="mono-input" spellcheck="false" placeholder="https://…">
                <p class="help">For R2, replace ACCOUNT_ID with your Cloudflare account ID.</p>
                <?= $error('cloud_endpoint') ?>
            </div>
            <div class="field-row">
                <div class="field<?= $hasError('cloud_bucket') ?>">
                    <label for="cloud_bucket">Bucket</label>
                    <input type="text" id="cloud_bucket" name="cloud_bucket" value="<?= e($cloud['bucket']) ?>" spellcheck="false">
                    <?= $error('cloud_bucket') ?>
                </div>
                <div class="field field-small<?= $hasError('cloud_region') ?>">
                    <label for="cloud_region">Region</label>
                    <input type="text" id="cloud_region" name="cloud_region" value="<?= e($cloud['region']) ?>" spellcheck="false">
                    <?= $error('cloud_region') ?>
                </div>
            </div>
            <div class="field<?= $hasError('cloud_prefix') ?>">
                <label for="cloud_prefix">Folder in the bucket</label>
                <input type="text" id="cloud_prefix" name="cloud_prefix" value="<?= e($cloud['prefix']) ?>" spellcheck="false" placeholder="deploy-tool/">
                <?= $error('cloud_prefix') ?>
            </div>
        </div>

        <div>
            <div class="field<?= $hasError('cloud_access_key') ?>">
                <label for="cloud_access_key">Access key ID</label>
                <input type="text" id="cloud_access_key" name="cloud_access_key" value="<?= e($cloud['access_key']) ?>" spellcheck="false" autocomplete="off">
                <?= $error('cloud_access_key') ?>
            </div>
            <div class="field<?= $hasError('cloud_secret_key') ?>">
                <label for="cloud_secret_key">Secret access key</label>
                <input type="password" id="cloud_secret_key" name="cloud_secret_key" value="" autocomplete="new-password" placeholder="<?= $saved['secret_key'] !== '' ? 'Saved. Blank keeps it.' : '' ?>">
                <?= $error('cloud_secret_key') ?>
            </div>
            <div class="field<?= $hasError('cloud_passphrase') ?>">
                <label for="cloud_passphrase">Encryption passphrase</label>
                <input type="password" id="cloud_passphrase" name="cloud_passphrase" value="" autocomplete="new-password" placeholder="<?= $saved['passphrase'] !== '' ? 'Saved. Blank keeps it.' : 'At least 12 characters' ?>">
                <p class="help">Write it down somewhere safe, away from this computer: without it no backup can be opened, by you or anyone.</p>
                <?= $error('cloud_passphrase') ?>
            </div>
            <div class="field-row">
                <div class="field<?= $hasError('cloud_schedule') ?>">
                    <label for="cloud_schedule">Back up</label>
                    <select id="cloud_schedule" name="cloud_schedule">
                        <?php foreach (CloudBackup::SCHEDULES as $key => $label): ?>
                            <option value="<?= e($key) ?>"<?= $cloud['schedule'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach ?>
                    </select>
                    <?= $error('cloud_schedule') ?>
                </div>
                <div class="field field-small<?= $hasError('cloud_keep') ?>">
                    <label for="cloud_keep">Keep</label>
                    <input type="number" id="cloud_keep" name="cloud_keep" min="1" max="100" value="<?= e($cloud['keep']) ?>">
                    <?= $error('cloud_keep') ?>
                </div>
            </div>
            <p class="help field-note">Keeps the newest copies; older ones are deleted from the bucket.</p>
            <input type="hidden" name="cloud_include_backups" value="0">
            <label class="checkbox">
                <input type="checkbox" name="cloud_include_backups" value="1"<?= $cloud['include_backups'] === '1' ? ' checked' : '' ?>>
                <span>Include the deploy backups (the server files saved before each run)</span>
            </label>
        </div>
    </div>

    <div class="cloud-actions">
        <button type="button" class="js-cloud-test" data-busy="Testing…">Test connection</button>
        <button type="button" class="js-cloud-now" data-busy="Backing up…"<?= $ready ? '' : ' disabled title="Save the settings first"' ?>>Back up now</button>
        <span class="meta js-cloud-result" aria-live="polite">Test uses the fields above, saved or not. Back up uses the saved settings.</span>
    </div>
</div>

<div class="card settings-card section">
    <div class="settings-card-head">
        <h2>Backups in the cloud</h2>
        <button type="button" class="small js-cloud-list"<?= $ready ? '' : ' disabled' ?>>Show</button>
    </div>
    <p class="meta">Restoring replaces this computer's data with the backup. What's here now is first saved to <code>restore-safety\</code> in the storage folder.</p>
    <ul class="cloud-backups js-cloud-backups"></ul>
</div>
