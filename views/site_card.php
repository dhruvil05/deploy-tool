<?php
/**
 * One site on the dashboard, with "Gets code from" and Hide.
 *
 * @var array $site
 * @var array|null $lastDeploy its latest deploy that reached the server (Activity)
 */

use DeployTool\Http;
use DeployTool\Sites;

$csrf = '<input type="hidden" name="_csrf" value="' . e(Http::csrfToken()) . '"><input type="hidden" name="key" value="' . e($site['key']) . '">';
$checkout = Sites::checkoutStatus($site);
$lastDeploy ??= null;
?>
<section class="card site-card" data-site="<?= e($site['key']) ?>" data-source="<?= e($site['source']) ?>">
    <div class="card-label">
        <strong><?= e($site['name']) ?></strong>
        <?php if ($site['type'] === 'folder'): ?><span class="badge badge-neutral">Test folder</span><?php endif ?>
        <?php if ($site['production']): ?><span class="badge badge-danger">Production</span><?php endif ?>
        <form method="post" action="index.php?r=site-hide" class="inline card-edit">
            <?= $csrf ?>
            <input type="hidden" name="hidden" value="1">
            <button type="submit" class="link-button" title="Hide from the dashboard">Hide</button>
        </form>
    </div>
    <p class="meta">
        <?php if ($site['url'] !== ''): ?>
            <a href="<?= e($site['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($site['url']) ?></a>
        <?php else: ?>
            <?= e($site['type'] === 'ssh' ? $site['ssh_host'] . ':' . $site['ssh_root'] : $site['path']) ?>
        <?php endif ?>
        <?php if ($checkout !== null): ?>
            <span class="tip" tabindex="0" aria-label="Warning: this folder is a git checkout">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 21h20L12 3Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M12 10v5M12 18h.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span class="tip-body" role="tooltip">
                    <strong class="tip-title">This folder is a git checkout</strong>
                    <span class="tip-text"><?= e(Sites::checkoutWarning($site)) ?></span>
                    <span class="tip-text"><?= e($checkout['note']) ?></span>
                    <?php if ($checkout['command'] !== null): ?>
                        <code class="tip-cmd"><?= e($checkout['command']) ?></code>
                        <button type="button" class="small js-copy" data-copy="<?= e($checkout['command']) ?>">Copy command</button>
                    <?php endif ?>
                </span>
            </span>
        <?php endif ?>
    </p>

    <div class="site-status"><p class="meta">Checking…</p></div>

    <?php // Speed-ups "Uptime and certificate monitor" and "Watch servers for changes". ?>
    <?php $uptime = \DeployTool\Settings::on('feature_uptime') ? \DeployTool\Monitor::result($site['key']) : null; ?>
    <?php $watch = \DeployTool\Settings::on('feature_server_watch') ? \DeployTool\ServerWatch::result($site['key']) : null; ?>
    <?php if ($uptime !== null || $watch !== null): ?>
        <div class="site-checks">
            <?php if ($uptime !== null): ?>
                <?php if (!$uptime['up']): ?>
                    <span class="badge badge-danger" title="<?= e((string) $uptime['problem']) ?>">Down since <?= e(date('H:i', (int) $uptime['down_since'])) ?></span>
                <?php elseif (!$uptime['ok']): ?>
                    <span class="badge badge-warning" title="<?= e((string) $uptime['problem']) ?>">Last check failed</span>
                <?php else: ?>
                    <span class="badge badge-success" title="Checked <?= e(ago($uptime['checked_at'])) ?>">Up · <?= number_format($uptime['ms'] / 1000, 1) ?> s</span>
                <?php endif ?>
                <?php $days = $uptime['cert']['days'] ?? null; ?>
                <?php if ($days !== null && $days <= \DeployTool\Monitor::CERT_WARN_DAYS): ?>
                    <span class="badge badge-danger"><?= $days < 0 ? 'Certificate expired' : "Certificate ends in $days day" . ($days === 1 ? '' : 's') ?></span>
                <?php elseif (isset($uptime['cert']['error'])): ?>
                    <span class="badge badge-warning" title="<?= e($uptime['cert']['error']) ?>">Certificate not checked</span>
                <?php endif ?>
            <?php endif ?>
            <?php if ($watch !== null): ?>
                <?php $watchCount = array_sum($watch['counts'] ?? [0]); ?>
                <a class="watch-link" href="index.php?r=watch&amp;site=<?= e(urlencode($site['key'])) ?>">
                    <?php if (!empty($watch['malware'])): ?>
                        <span class="badge badge-danger">Malware markers on the server</span>
                    <?php elseif ($watchCount > 0 && empty($watch['seen'])): ?>
                        <span class="badge badge-warning"><?= $watchCount ?> file<?= $watchCount === 1 ? '' : 's' ?> changed on the server</span>
                    <?php elseif (!empty($watch['error'])): ?>
                        <span class="badge badge-warning">Server watch failed</span>
                    <?php else: ?>
                        <span class="meta">Server watch: no surprises · <?= e(ago($watch['checked_at'])) ?></span>
                    <?php endif ?>
                </a>
            <?php endif ?>
        </div>
    <?php endif ?>

    <p class="meta last-deploy">
        <?php if ($lastDeploy === null): ?>
            No deploy from this tool yet.
        <?php else: ?>
            Last deploy <a href="index.php?r=run&amp;id=<?= e(urlencode($lastDeploy['id'])) ?>"><?= e(ago($lastDeploy['finished_at'] ?? $lastDeploy['created_at'])) ?></a>
            · <code><?= e(short_sha($lastDeploy['to'])) ?></code> · <?= count($lastDeploy['files']) ?> file<?= count($lastDeploy['files']) === 1 ? '' : 's' ?> · <?= e($lastDeploy['by']) ?>
            <?php if ($lastDeploy['status'] === 'rolled_back'): ?><span class="badge badge-neutral">Rolled back</span><?php endif ?>
        <?php endif ?>
    </p>

    <form method="post" action="index.php?r=site-source" class="source-form js-source"
          data-production="<?= $site['production'] ? '1' : '0' ?>" data-name="<?= e($site['name']) ?>">
        <?= $csrf ?>
        <label>
            <span>Gets code from</span>
            <select name="source">
                <?php foreach (Sites::sourceOptions($site['key']) as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $value === $site['source'] ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach ?>
            </select>
        </label>
        <noscript><button type="submit" class="small">Save</button></noscript>
    </form>

    <div class="card-actions">
        <a class="button primary-link" href="index.php?r=deploy-new&amp;site=<?= e(urlencode($site['key'])) ?>">Deploy</a>
        <a class="button" href="index.php?r=compare-new&amp;site=<?= e(urlencode($site['key'])) ?>">Compare</a>
        <a class="button" href="index.php?r=site-edit&amp;key=<?= e(urlencode($site['key'])) ?>">Edit</a>
    </div>
</section>
