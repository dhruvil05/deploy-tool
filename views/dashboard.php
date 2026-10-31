<?php
/**
 * One local at a time: its numbers, its pipeline and its recent runs.
 *
 * @var array $sources every local, for the switcher
 * @var array<string, int> $siteCounts visible sites per local
 * @var array $local LocalStatus::collect() result for the picked local
 * @var array|false $pipeline its entry in $layout['pipelines']
 * @var array{pipelines: array, unconnected: array, hidden: array} $layout
 * @var array $stats Activity::forSource()
 * @var bool $nameMissing
 * @var array $openRuns
 */

use DeployTool\Activity;
use DeployTool\Http;
use DeployTool\RunView;
use DeployTool\View;

$source = $local['source'];
$last = $stats['last'];
?>
<div class="page-head dash-head">
    <h1>Dashboard</h1>
    <?php if (count($sources) > 1): ?>
        <nav class="local-tabs" aria-label="Locals">
            <?php foreach ($sources as $key => $item): ?>
                <a href="index.php?local=<?= e(urlencode($key)) ?>"<?= $key === $source['key'] ? ' class="active" aria-current="page"' : '' ?>>
                    <?= e($item['name']) ?> <span class="pill"><?= $siteCounts[$key] ?? 0 ?></span>
                </a>
            <?php endforeach ?>
        </nav>
    <?php endif ?>
    <a class="button" href="index.php?r=source-edit">Add local</a>
</div>

<?php if ($nameMissing): ?>
    <div class="notice notice-warning">Start in <a href="index.php?r=settings">Settings</a>: enter your name. It is recorded with every deploy.</div>
<?php endif ?>

<?php foreach ($openRuns as $open): ?>
    <div class="notice notice-<?= $open['status'] === 'failed' ? 'danger' : 'warning' ?>">
        <?= e(RunView::title($open)) ?>: <?= e(strtolower(RunView::STATUSES[$open['status']][0])) ?>.
        <a href="index.php?r=run&amp;id=<?= e(urlencode($open['id'])) ?>">Open it</a>
    </div>
<?php endforeach ?>

<div class="metrics dash-stats">
    <div class="metric">
        <p class="metric-label">Deploys · <?= Activity::DAYS ?> days</p>
        <p class="metric-value"><?= $stats['deploys'] ?></p>
        <p class="metric-sub"><?= $stats['production'] ?> to production</p>
    </div>
    <div class="metric">
        <p class="metric-label">Files uploaded · <?= Activity::DAYS ?> days</p>
        <p class="metric-value"><?= number_format($stats['files']) ?></p>
        <p class="metric-sub"><?= $stats['deploys'] > 0 ? 'about ' . round($stats['files'] / $stats['deploys']) . ' per deploy' : 'no deploys yet' ?></p>
    </div>
    <div class="metric<?= $stats['failed'] > 0 ? ' metric-warning' : '' ?>">
        <p class="metric-label">Failed · <?= Activity::DAYS ?> days</p>
        <p class="metric-value"><?= $stats['failed'] ?></p>
        <p class="metric-sub"><?= $stats['rollbacks'] ?> rollback<?= $stats['rollbacks'] === 1 ? '' : 's' ?></p>
    </div>
    <div class="metric">
        <p class="metric-label">Last deploy</p>
        <?php if ($last === null): ?>
            <p class="metric-value">—</p>
            <p class="metric-sub">none from this tool yet</p>
        <?php else: ?>
            <p class="metric-value"><a href="index.php?r=run&amp;id=<?= e(urlencode($last['id'])) ?>"><?= e(ago($last['finished_at'] ?? $last['created_at'])) ?></a></p>
            <p class="metric-sub"><?= e($last['site_name']) ?> · <?= e(short_sha($last['to'])) ?> · <?= e($last['by']) ?></p>
        <?php endif ?>
    </div>
</div>

<?= View::partial('local_card', ['local' => $local]) ?>

<?php if ($pipeline === false || $pipeline['rows'] === []): ?>
    <p class="meta pipeline-empty">
        No site gets its code from this local yet.
        <a href="index.php?r=site-edit&amp;source=<?= e(urlencode('local:' . $source['key'])) ?>">Add a site</a>
        or change a site's "Gets code from" on its card.
    </p>
<?php else: ?>
    <?php foreach ($pipeline['rows'] as $number => $row): ?>
        <p class="row-label">
            <?= $row['from'] === null ? '↓ Pipeline' . (count($pipeline['rows']) > 1 ? ' ' . ($number + 1) : '') : '↳ Also from ' . e($row['from']) ?>
        </p>
        <div class="pipeline">
            <?php foreach ($row['sites'] as $index => $site): ?>
                <?php if ($index > 0): ?><div class="arrow" aria-hidden="true">→</div><?php endif ?>
                <?= View::partial('site_card', ['site' => $site, 'lastDeploy' => $stats['lastBySite'][$site['key']] ?? null]) ?>
            <?php endforeach ?>
        </div>
    <?php endforeach ?>
<?php endif ?>

<section class="card section">
    <div class="settings-card-head">
        <h2>Recent activity</h2>
        <a class="meta" href="index.php?r=history">All history</a>
    </div>
    <?php if (!$stats['recent']): ?>
        <p class="meta">No deploys or compares from <?= e($source['name']) ?> yet.</p>
    <?php else: ?>
        <ul class="activity">
            <?php foreach ($stats['recent'] as $run): ?>
                <?php [$label, $style] = RunView::STATUSES[$run['status']] ?? [$run['status'], 'neutral']; ?>
                <li>
                    <a href="index.php?r=run&amp;id=<?= e(urlencode($run['id'])) ?>"><?= e(RunView::title($run)) ?></a>
                    <span class="meta">
                        <?php if ($run['type'] !== 'compare' && $run['files']): ?><?= count($run['files']) ?> file<?= count($run['files']) === 1 ? '' : 's' ?> · <?php endif ?>
                        <?php if ($run['to']): ?><code><?= e(short_sha($run['to'])) ?></code> · <?php endif ?>
                        <?= e($run['by']) ?> · <?= e(ago($run['created_at'])) ?>
                    </span>
                    <span class="badge badge-<?= e($style) ?>"><?= e($label) ?></span>
                </li>
            <?php endforeach ?>
        </ul>
    <?php endif ?>
</section>

<?php if ($layout['unconnected']): ?>
    <section class="pipeline-group section">
        <p class="row-label">Not connected to a local: choose where these sites get their code from</p>
        <div class="pipeline">
            <?php foreach ($layout['unconnected'] as $site): ?>
                <?= View::partial('site_card', ['site' => $site]) ?>
            <?php endforeach ?>
        </div>
    </section>
<?php endif ?>

<?php if ($layout['hidden']): ?>
    <details class="hidden-sites">
        <summary>Show <?= count($layout['hidden']) ?> hidden site(s)</summary>
        <ul>
            <?php foreach ($layout['hidden'] as $site): ?>
                <li>
                    <?= e($site['name']) ?>
                    <form method="post" action="index.php?r=site-hide" class="inline">
                        <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
                        <input type="hidden" name="key" value="<?= e($site['key']) ?>">
                        <input type="hidden" name="hidden" value="0">
                        <button type="submit" class="small">Show on dashboard</button>
                    </form>
                </li>
            <?php endforeach ?>
        </ul>
    </details>
<?php endif ?>
