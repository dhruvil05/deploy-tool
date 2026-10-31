<?php
/**
 * Locals and sites as pipelines: one card per local, its sites listed in the order code
 * moves through them.
 *
 * @var array $tree Sites::bySource()
 * @var bool $saved
 * @var array $sources
 */

use DeployTool\Http;
use DeployTool\Sites;

$csrf = '<input type="hidden" name="_csrf" value="' . e(Http::csrfToken()) . '">';

$renderSite = static function (array $site, ?array $entry) use ($csrf, $saved): string {
    $key = $site['key'];
    ob_start(); ?>
    <div class="flow-body">
        <div class="flow-head">
            <div class="flow-title">
                <strong><?= e($site['name']) ?></strong>
                <?php if ($site['production']): ?><span class="badge badge-danger">Production</span><?php endif ?>
                <span class="badge badge-neutral"><?= e(match ($site['type']) { 'ssh' => 'SSH', 'folder' => 'Test folder', default => 'FTP' }) ?></span>
                <?php if ($site['hidden']): ?><span class="badge badge-neutral">Hidden on dashboard</span><?php endif ?>
            </div>
            <div class="actions">
                <button type="button" class="small js-test" data-site="<?= e($key) ?>">Test connection</button>
                <a class="button small" href="index.php?r=site-edit&amp;key=<?= e(urlencode($key)) ?>">Edit</a>
                <?php if ($saved): ?>
                    <form method="post" action="index.php?r=site-delete" class="inline js-confirm" data-confirm="Delete <?= e($site['name']) ?>?">
                        <?= $csrf ?>
                        <input type="hidden" name="key" value="<?= e($key) ?>">
                        <button type="submit" class="small danger">Delete</button>
                    </form>
                <?php endif ?>
            </div>
        </div>
        <p class="meta">Gets code from <strong><?= e($entry['from'] ?? Sites::sourceLabel($site)) ?></strong>
            <?php if ($site['url'] !== ''): ?>
                · <a href="<?= e($site['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(parse_url($site['url'], PHP_URL_HOST) ?: $site['url']) ?></a>
            <?php endif ?>
        </p>
        <p class="meta flow-login">
            <?php if ($site['type'] === 'folder'): ?>
                <code><?= e($site['path']) ?></code>
                <?php if (Sites::gitCheckoutRoot($site) !== null): ?>
                    <span class="badge badge-warning" title="<?= e(Sites::checkoutWarning($site)) ?>">Git checkout</span>
                <?php endif ?>
            <?php elseif ($site['type'] === 'ssh'): ?>
                <?= e($site['ssh_user'] !== '' ? $site['ssh_user'] : '(no user)') ?>@<?= e($site['ssh_host'] !== '' ? $site['ssh_host'] : '(no host)') ?>:<?= (int) $site['ssh_port'] ?>
                · folder <code><?= e($site['ssh_root']) ?></code>
            <?php else: ?>
                <?= e($site['username'] !== '' ? $site['username'] : '(no user)') ?>@<?= e($site['host'] !== '' ? $site['host'] : '(no host)') ?>:<?= (int) $site['port'] ?>
                · folder <code><?= e($site['root']) ?></code>
                <?php if ($site['password'] === ''): ?><span class="badge badge-warning">No password</span><?php endif ?>
            <?php endif ?>
        </p>
        <div class="test-row" data-for="<?= e($key) ?>" hidden><div class="test-result"></div></div>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<div class="page-head">
    <h1>Sites</h1>
    <div class="actions-row flush">
        <a class="button" href="index.php?r=source-edit">Add local</a>
        <a class="button" href="index.php?r=site-edit">Add site</a>
    </div>
</div>
<p class="lead">Each local is a repository on this computer. Code moves down its list: step 1 gets it from the local, each next step from the site above. FTP passwords stay on this computer in storage/sites.json.</p>

<?php if (!$saved): ?>
    <div class="notice notice-info">These are the default sites. Edit each one to add its FTP login.</div>
<?php endif ?>

<?php foreach ($sources as $sourceKey => $source): ?>
    <?php
    $entries = $tree['locals'][$sourceKey] ?? [];
    $protected = $source['extra_protected'] === '' ? 0 : count(explode("\n", $source['extra_protected']));
    $mainLine = array_values(array_filter($entries, static fn (array $entry): bool => $entry['level'] === 0));
    $last = $mainLine ? end($mainLine)['site'] : null;
    ?>
    <section class="card source-card section">
        <div class="source-head">
            <div>
                <p class="card-label"><span class="badge badge-info">Local</span> <span class="meta"><?= e($sourceKey) ?></span></p>
                <h2><?= e($source['name']) ?></h2>
                <p class="meta">
                    <code><?= e($source['repo_path']) ?></code>
                    · branch <code><?= e($source['branch']) ?></code> from <?= e($source['remote']) ?>
                    · <?= $protected === 0 ? 'no extra protected paths' : $protected . ' extra protected path' . ($protected === 1 ? '' : 's') ?>
                </p>
            </div>
            <div class="actions">
                <a class="button small" href="index.php?r=source-edit&amp;key=<?= e(urlencode($sourceKey)) ?>">Edit local</a>
                <?php if (count($sources) > 1): ?>
                    <form method="post" action="index.php?r=source-delete" class="inline js-confirm" data-confirm="Delete the local <?= e($source['name']) ?>?">
                        <?= $csrf ?>
                        <input type="hidden" name="key" value="<?= e($sourceKey) ?>">
                        <button type="submit" class="small danger">Delete</button>
                    </form>
                <?php endif ?>
            </div>
        </div>

        <?php if ($entries === []): ?>
            <p class="meta flow-empty">No site gets code from this local yet.</p>
        <?php else: ?>
            <ol class="flow">
                <?php foreach ($entries as $entry): ?>
                    <li class="flow-step level-<?= min($entry['level'], 3) ?>">
                        <span class="flow-dot" title="Step <?= $entry['step'] ?>"><?= $entry['step'] ?></span>
                        <?= $renderSite($entry['site'], $entry) ?>
                    </li>
                <?php endforeach ?>
            </ol>
        <?php endif ?>

        <div class="flow-add">
            <?php if ($last !== null): ?>
                <a class="button small" href="index.php?r=site-edit&amp;source=<?= e(urlencode('site:' . $last['key'])) ?>">+ Add a site after <?= e($last['name']) ?></a>
            <?php endif ?>
            <a class="button small" href="index.php?r=site-edit&amp;source=<?= e(urlencode('local:' . $sourceKey)) ?>">+ Add a site from this local</a>
        </div>
    </section>
<?php endforeach ?>

<?php if ($tree['unconnected']): ?>
    <section class="card source-card section">
        <h2>Not connected</h2>
        <p class="meta">These sites get code from a local or site that no longer exists. Edit each one and pick where it gets code from.</p>
        <ol class="flow">
            <?php foreach ($tree['unconnected'] as $site): ?>
                <li class="flow-step"><span class="flow-dot">?</span><?= $renderSite($site, null) ?></li>
            <?php endforeach ?>
        </ol>
    </section>
<?php endif ?>
