<?php
/**
 * One site on the dashboard, with "Gets code from" and Hide.
 *
 * @var array $site
 */

use DeployTool\Http;
use DeployTool\Sites;

$csrf = '<input type="hidden" name="_csrf" value="' . e(Http::csrfToken()) . '"><input type="hidden" name="key" value="' . e($site['key']) . '">';
$checkout = Sites::checkoutStatus($site);
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
            <?= e($site['path']) ?>
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
