<?php
/**
 * @var array $sites
 * @var bool $saved
 * @var array $sources
 */

use DeployTool\Http;
use DeployTool\Sites;
?>
<div class="page-head">
    <h1>Locals</h1>
    <a class="button" href="index.php?r=source-edit">Add local</a>
</div>
<p class="lead">Each pipeline starts from a local: a repository on this computer and a branch.</p>
<table class="table">
    <thead>
    <tr><th>Local</th><th>Repository folder</th><th>Branch</th><th>Extra protected</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($sources as $key => $source): ?>
        <tr>
            <td><strong><?= e($source['name']) ?></strong> <span class="meta"><?= e($key) ?></span></td>
            <td><code><?= e($source['repo_path']) ?></code></td>
            <td><code><?= e($source['branch']) ?></code> <span class="meta">from <?= e($source['remote']) ?></span></td>
            <td><?= $source['extra_protected'] === '' ? '<span class="meta">none</span>' : count(explode("\n", $source['extra_protected'])) . ' path(s)' ?></td>
            <td>
                <div class="actions">
                    <a class="button" href="index.php?r=source-edit&amp;key=<?= e(urlencode($key)) ?>">Edit</a>
                    <?php if (count($sources) > 1): ?>
                        <form method="post" action="index.php?r=source-delete" class="inline js-confirm" data-confirm="Delete the local <?= e($source['name']) ?>?">
                            <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
                            <input type="hidden" name="key" value="<?= e($key) ?>">
                            <button type="submit" class="danger">Delete</button>
                        </form>
                    <?php endif ?>
                </div>
            </td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>

<div class="page-head section">
    <h1>Sites</h1>
    <a class="button" href="index.php?r=site-edit">Add site</a>
</div>
<p class="lead">Each site gets its code from a local or from another site. FTP passwords stay on this computer in storage/sites.json.</p>

<?php if (!$saved): ?>
    <div class="notice notice-info">These are the default sites. Edit each one to add its FTP login.</div>
<?php endif ?>

<table class="table">
    <thead>
    <tr><th>Site</th><th>Gets code from</th><th>FTP login</th><th>Folder</th><th></th></tr>
    </thead>
    <tbody>
    <?php foreach ($sites as $key => $site): ?>
        <tr>
            <td>
                <strong><?= e($site['name']) ?></strong>
                <?php if ($site['production']): ?><span class="badge badge-danger">Production</span><?php endif ?><br>
                <a class="meta" href="<?= e($site['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(parse_url($site['url'], PHP_URL_HOST) ?: $site['url']) ?></a>
            </td>
            <td><?= e(Sites::sourceLabel($site)) ?></td>
            <?php if ($site['type'] === 'folder'): ?>
                <td>
                    <span class="badge badge-neutral">Test folder</span>
                    <?php if (Sites::gitCheckoutRoot($site) !== null): ?>
                        <span class="badge badge-warning" title="<?= e(Sites::checkoutWarning($site)) ?>">Git checkout</span>
                    <?php endif ?>
                </td>
                <td><code><?= e($site['path']) ?></code></td>
            <?php else: ?>
                <td>
                    <?= e($site['username'] !== '' ? $site['username'] : '(no user)') ?>@<?= e($site['host'] !== '' ? $site['host'] : '(no host)') ?>:<?= (int) $site['port'] ?>
                    <?php if ($site['password'] === ''): ?><br><span class="badge badge-warning">No password</span><?php endif ?>
                </td>
                <td><code><?= e($site['root']) ?></code></td>
            <?php endif ?>
            <td>
                <div class="actions">
                    <button type="button" class="js-test" data-site="<?= e($key) ?>">Test connection</button>
                    <a class="button" href="index.php?r=site-edit&amp;key=<?= e(urlencode($key)) ?>">Edit</a>
                    <?php if ($saved): ?>
                        <form method="post" action="index.php?r=site-delete" class="inline js-confirm" data-confirm="Delete <?= e($site['name']) ?>?">
                            <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
                            <input type="hidden" name="key" value="<?= e($key) ?>">
                            <button type="submit" class="danger">Delete</button>
                        </form>
                    <?php endif ?>
                </div>
            </td>
        </tr>
        <tr class="test-row" data-for="<?= e($key) ?>" hidden>
            <td colspan="5"><div class="test-result"></div></td>
        </tr>
    <?php endforeach ?>
    </tbody>
</table>
