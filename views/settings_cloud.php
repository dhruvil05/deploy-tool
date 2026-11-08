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
$running = $state['running_since'] !== null && time() - (int) $state['running_since'] < 1800;
$failed = $state['error'] !== null && (int) $state['error_at'] >= (int) $state['at'];

// The line under each storage provider's name on its card.
$providerNotes = [
    'r2' => 'Endpoint needs your account ID',
    'b2' => 'Endpoint names your region',
    's3' => 'Endpoint names your region',
    'wasabi' => 'Endpoint names your region',
    'custom' => 'MinIO, DigitalOcean Spaces…',
];
?>
<div class="card settings-card cloud-card">
    <div class="settings-card-head">
        <h2>Cloud backup</h2>
        <span class="pill<?= $ready && $saved['schedule'] !== 'off' ? ' pill-success' : '' ?>"><?= $ready ? e(CloudBackup::SCHEDULES[$saved['schedule']]) : 'Not set up' ?></span>
    </div>
    <p class="meta">Keeps encrypted copies of the tool's data in S3-compatible storage. Everything is encrypted on this computer with your passphrase before it leaves, so the provider never sees your FTP passwords or keys. The tool keeps working from its local folder.</p>

    <div class="cloud-status<?= $failed ? ' is-failed' : ($state['at'] !== null ? ' is-ok' : '') ?>">
        <span class="cloud-status-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 19a4.5 4.5 0 1 0-1.4-8.8A6 6 0 0 0 4.5 12.5 3.5 3.5 0 0 0 6.5 19z"></path></svg>
        </span>
        <div class="cloud-status-text">
            <?php if ($state['at'] !== null): ?>
                <span>Last backup <strong><?= e(ago((int) $state['at'])) ?></strong> · <?= e(number_format((int) $state['size'] / 1048576, 2)) ?> MB</span>
                <code><?= e(basename((string) $state['name'])) ?></code>
            <?php else: ?>
                <span>No backup made yet.</span>
            <?php endif ?>
            <?php if ($failed): ?>
                <p class="error-text">The last try failed <?= e(ago((int) $state['error_at'])) ?>: <?= e($state['error']) ?></p>
            <?php endif ?>
        </div>
        <?php if ($running): ?>
            <span class="badge badge-info">Backing up now…</span>
        <?php endif ?>
    </div>

    <section class="set-section">
        <div class="set-section-head">
            <h3><span class="set-step">1</span>Where</h3>
            <p class="meta">The storage provider and the bucket the copies go to.</p>
        </div>
        <fieldset class="field<?= $hasError('cloud_provider') ?>">
            <legend class="sr-only">Provider</legend>
            <div class="provider-grid">
                <?php foreach (CloudBackup::PRESETS as $key => [$label, $endpoint, $region]): ?>
                    <label class="provider-card">
                        <input type="radio" name="cloud_provider" value="<?= e($key) ?>" class="js-cloud-provider" data-endpoint="<?= e($endpoint) ?>" data-region="<?= e($region) ?>"<?= $cloud['provider'] === $key ? ' checked' : '' ?>>
                        <span class="provider-mark" aria-hidden="true"><?= e(mb_substr($label, 0, 1)) ?></span>
                        <span class="provider-text">
                            <strong><?= e($label) ?></strong>
                            <span><?= e($providerNotes[$key] ?? '') ?></span>
                        </span>
                    </label>
                <?php endforeach ?>
            </div>
        </fieldset>
        <div class="field<?= $hasError('cloud_endpoint') ?>">
            <label for="cloud_endpoint">Endpoint</label>
            <input type="text" id="cloud_endpoint" name="cloud_endpoint" value="<?= e($cloud['endpoint']) ?>" class="mono-input" spellcheck="false" placeholder="https://…">
            <p class="help">For R2, replace ACCOUNT_ID with your Cloudflare account ID.</p>
            <?= $error('cloud_endpoint') ?>
        </div>
        <div class="set-trio">
            <div class="field<?= $hasError('cloud_bucket') ?>">
                <label for="cloud_bucket">Bucket</label>
                <input type="text" id="cloud_bucket" name="cloud_bucket" value="<?= e($cloud['bucket']) ?>" spellcheck="false">
                <?= $error('cloud_bucket') ?>
            </div>
            <div class="field<?= $hasError('cloud_region') ?>">
                <label for="cloud_region">Region</label>
                <input type="text" id="cloud_region" name="cloud_region" value="<?= e($cloud['region']) ?>" spellcheck="false">
                <?= $error('cloud_region') ?>
            </div>
            <div class="field<?= $hasError('cloud_prefix') ?>">
                <label for="cloud_prefix">Folder in the bucket</label>
                <input type="text" id="cloud_prefix" name="cloud_prefix" value="<?= e($cloud['prefix']) ?>" spellcheck="false" placeholder="deploy-tool/">
                <?= $error('cloud_prefix') ?>
            </div>
        </div>
    </section>

    <section class="set-section">
        <div class="set-section-head">
            <h3><span class="set-step">2</span>Keys</h3>
            <p class="meta">An access key for that bucket, from the provider's dashboard.</p>
        </div>
        <div class="set-pair">
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
        </div>
    </section>

    <section class="set-section">
        <div class="set-section-head">
            <h3><span class="set-step">3</span>Encryption</h3>
            <p class="meta">Every copy is locked with this passphrase before it leaves this computer.</p>
        </div>
        <div class="field set-narrow<?= $hasError('cloud_passphrase') ?>">
            <label for="cloud_passphrase">Encryption passphrase</label>
            <input type="password" id="cloud_passphrase" name="cloud_passphrase" value="" autocomplete="new-password" placeholder="<?= $saved['passphrase'] !== '' ? 'Saved. Blank keeps it.' : 'At least 12 characters' ?>">
            <?= $error('cloud_passphrase') ?>
        </div>
        <p class="set-callout">Write it down somewhere safe, away from this computer: without it no backup can be opened, by you or anyone.</p>
    </section>

    <section class="set-section">
        <div class="set-section-head">
            <h3><span class="set-step">4</span>Schedule</h3>
            <p class="meta">When copies are made, and how many stay in the bucket.</p>
        </div>
        <div class="set-pair">
            <div class="field<?= $hasError('cloud_schedule') ?>">
                <label for="cloud_schedule">Back up</label>
                <select id="cloud_schedule" name="cloud_schedule">
                    <?php foreach (CloudBackup::SCHEDULES as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= $cloud['schedule'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
                <?= $error('cloud_schedule') ?>
            </div>
            <div class="field<?= $hasError('cloud_keep') ?>">
                <label for="cloud_keep">Copies to keep</label>
                <input type="number" id="cloud_keep" name="cloud_keep" min="1" max="100" value="<?= e($cloud['keep']) ?>">
                <p class="help">The newest are kept; older ones are deleted from the bucket.</p>
                <?= $error('cloud_keep') ?>
            </div>
        </div>
        <input type="hidden" name="cloud_include_backups" value="0">
        <label class="set-toggle">
            <span class="set-toggle-text">
                <strong>Include the deploy backups</strong>
                <span>The server files saved before each run. Bigger copies, but a rollback still works after a restore.</span>
            </span>
            <input type="checkbox" class="switch" name="cloud_include_backups" value="1" role="switch"<?= $cloud['include_backups'] === '1' ? ' checked' : '' ?>>
        </label>
    </section>

    <div class="cloud-actions">
        <button type="button" class="js-cloud-test" data-busy="Testing…">Test connection</button>
        <button type="button" class="primary js-cloud-now" data-busy="Backing up…"<?= $ready ? '' : ' disabled title="Save the settings first"' ?>>Back up now</button>
        <span class="meta js-cloud-result" aria-live="polite">Test uses the fields above, saved or not. Back up uses the saved settings.</span>
    </div>
</div>

<div class="card settings-card section cloud-list-card">
    <div class="settings-card-head">
        <h2>Backups in the cloud</h2>
        <button type="button" class="small js-cloud-list"<?= $ready ? '' : ' disabled' ?>>Show</button>
    </div>
    <p class="meta">Restoring replaces this computer's data with the backup. What's here now is first saved to <code>restore-safety\</code> in the storage folder.</p>
    <ul class="cloud-backups js-cloud-backups"></ul>
</div>
