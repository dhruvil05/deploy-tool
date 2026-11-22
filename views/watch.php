<?php
/**
 * Speed-up "Watch servers for changes": what the last check of a site found.
 *
 * @var array $site
 * @var ?array $result ServerWatch::result()
 */

use DeployTool\Http;
use DeployTool\ServerWatch;
use DeployTool\Settings;

$hidden = '<input type="hidden" name="_csrf" value="' . e(Http::csrfToken()) . '"><input type="hidden" name="site" value="' . e($site['key']) . '">';
$labels = ['added' => ['New on the server', 'badge-warning'], 'changed' => ['Changed on the server', 'badge-warning'], 'removed' => ['Removed from the server', 'badge-neutral']];
?>
<p><a href="index.php">← Dashboard</a></p>
<section class="card section watch-head">
    <div>
        <h1>Server watch · <?= e($site['name']) ?></h1>
        <p class="meta">
            <?php if ($result === null): ?>
                Not checked yet.
            <?php else: ?>
                Last checked <?= e(ago($result['checked_at'])) ?> · <?= number_format((int) ($result['files'] ?? 0)) ?> files on the server
            <?php endif ?>
            <?php if (Settings::on('feature_server_watch')): ?>
                · checked every <?= ServerWatch::hours() ?> hours
            <?php else: ?>
                · the timed check is off (Settings → Speed-ups); "Check now" still works
            <?php endif ?>
        </p>
    </div>
    <div class="actions-row flush">
        <form method="post" action="index.php?r=watch-check" class="inline js-busy">
            <?= $hidden ?>
            <button type="submit" class="primary" data-busy="Checking…">Check now</button>
        </form>
        <?php if ($result !== null && empty($result['seen'])): ?>
            <form method="post" action="index.php?r=watch-seen" class="inline">
                <?= $hidden ?>
                <button type="submit">Mark as seen</button>
            </form>
        <?php endif ?>
    </div>
</section>

<?php if ($result !== null): ?>
    <?php if (!empty($result['error'])): ?>
        <div class="notice notice-danger">The last check failed: <?= e($result['error']) ?></div>
    <?php endif ?>
    <?php if (!empty($result['malware'])): ?>
        <div class="alert malware-alert">
            <strong>Malware markers in the server's files. Don't run npm or builds from them; check who changed them.</strong>
            <ul><?php foreach ($result['malware'] as $finding): ?><li><?= e($finding) ?></li><?php endforeach ?></ul>
        </div>
    <?php endif ?>
    <?php if (!empty($result['baseline'])): ?>
        <div class="notice notice-info">First look: the server's files were noted. The next check lists what changed since.</div>
    <?php elseif (array_sum($result['counts'] ?? [0]) === 0 && empty($result['error'])): ?>
        <div class="notice notice-success">No surprises: since the check before, only the tool's own deploys changed files on the server.</div>
    <?php else: ?>
        <p class="meta">Changes since the check before that no deploy, match or rollback of the tool made<?= !empty($result['found_at']) ? ' (found ' . e(ago($result['found_at'])) . ')' : '' ?>. A hotfix made on the server belongs in git; anything you don't recognise may be a hack.</p>
        <div class="watch-lists">
            <?php foreach ($labels as $kind => [$label, $style]): ?>
                <?php $count = (int) ($result['counts'][array_search($kind, array_keys($labels), true)] ?? 0); ?>
                <?php if ($count > 0): ?>
                    <section class="card section">
                        <h2><?= e($label) ?> <span class="badge <?= $style ?>"><?= $count ?></span></h2>
                        <ul class="path-list">
                            <?php foreach ($result[$kind] as $path): ?><li><code><?= e($path) ?></code></li><?php endforeach ?>
                        </ul>
                        <?php if ($count > count($result[$kind])): ?><p class="meta">And <?= $count - count($result[$kind]) ?> more.</p><?php endif ?>
                    </section>
                <?php endif ?>
            <?php endforeach ?>
        </div>
        <p class="meta">To see the differences with git, run a Compare on the dashboard.</p>
    <?php endif ?>
<?php endif ?>
