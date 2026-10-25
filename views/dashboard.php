<?php
/**
 * @var array $locals per local key: LocalStatus::collect() result
 * @var array{pipelines: array, unconnected: array, hidden: array} $layout
 * @var bool $nameMissing
 * @var array $openRuns
 */

use DeployTool\RunView;
use DeployTool\View;
?>
<div class="page-head">
    <h1>Dashboard</h1>
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

<?php $number = 0; ?>
<?php foreach ($layout['pipelines'] as $pipeline): ?>
    <?php $source = $pipeline['source']; ?>
    <section class="pipeline-group">
        <?= View::partial('local_card', ['local' => $locals[$source['key']]]) ?>

        <?php if ($pipeline['rows'] === []): ?>
            <p class="meta pipeline-empty">
                No site gets its code from this local yet.
                <a href="index.php?r=site-edit&amp;source=<?= e(urlencode('local:' . $source['key'])) ?>">Add a site</a>
                or change a site's "Gets code from" below.
            </p>
        <?php endif ?>

        <?php foreach ($pipeline['rows'] as $row): ?>
            <p class="row-label">
                <?php if ($row['from'] === null): ?>
                    ↓ Pipeline <?= ++$number ?>
                <?php else: ?>
                    ↳ Also from <?= e($row['from']) ?>
                <?php endif ?>
            </p>
            <div class="pipeline">
                <?php foreach ($row['sites'] as $index => $site): ?>
                    <?php if ($index > 0): ?><div class="arrow" aria-hidden="true">→</div><?php endif ?>
                    <?= View::partial('site_card', ['site' => $site]) ?>
                <?php endforeach ?>
            </div>
        <?php endforeach ?>
    </section>
<?php endforeach ?>

<?php if ($layout['unconnected']): ?>
    <section class="pipeline-group">
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
                        <input type="hidden" name="_csrf" value="<?= e(\DeployTool\Http::csrfToken()) ?>">
                        <input type="hidden" name="key" value="<?= e($site['key']) ?>">
                        <input type="hidden" name="hidden" value="0">
                        <button type="submit" class="small">Show on dashboard</button>
                    </form>
                </li>
            <?php endforeach ?>
        </ul>
    </details>
<?php endif ?>
