<?php
/**
 * Every run, newest first, grouped by day, with filters.
 *
 * @var array $runs this page of the filtered runs
 * @var int $total filtered runs on every page
 * @var array<string, int> $counts runs per type ('' is all) before the type filter
 * @var array{type: string, local: string, site: string, status: string, q: string} $filters
 * @var int $pageNumber
 * @var int $pages
 * @var array $sources
 * @var array<string, string> $sites site key => name, from every run
 */

use DeployTool\Backup;
use DeployTool\RunView;

// A link to this page with some filters changed; paging starts over unless given.
$url = static function (array $changes) use ($filters): string {
    $query = array_filter(['r' => 'history'] + $changes + $filters + ['page' => null], static fn ($value): bool => $value !== '' && $value !== null);
    return 'index.php?' . e(http_build_query($query));
};
$filtered = array_filter($filters) !== [];
$types = ['' => 'All', 'deploy' => 'Deploys', 'compare' => 'Compares', 'rollback' => 'Rollbacks'];

$backupLink = static function (string $runId, string $label): string {
    $size = Backup::size($runId);
    return $size === null ? '' : '<a class="run-backup" href="index.php?r=backup-download&amp;id=' . e(urlencode($runId)) . '" title="' . e(Backup::location($runId)) . '">'
        . e($label) . ' · ' . e(number_format($size / 1024, 1)) . ' KB</a>';
};

$summary = static function (array $run): string {
    if ($run['type'] === 'compare') {
        $result = $run['result'] ?? null;
        if ($run['status'] !== 'done' || !is_array($result)) {
            return 'Compared with <code>' . e(short_sha($run['to'])) . '</code>';
        }
        $parts = [];
        foreach (['differs' => 'different', 'missing' => 'missing', 'extra' => 'extra on server'] as $key => $label) {
            if (count($result[$key]) > 0) {
                $parts[] = count($result[$key]) . ' ' . $label;
            }
        }
        $state = count($result['differs']) + count($result['missing']) === 0 ? '<span class="ok-text">Matches</span>' : '';
        return trim($state . ' <code>' . e(short_sha($run['to'])) . '</code>' . ($parts ? ' · ' . e(implode(', ', $parts)) : ''));
    }
    $files = count($run['files']);
    $text = '<code>' . e(short_sha($run['from']) ?: '—') . '</code> → <code>' . e(short_sha($run['to'])) . '</code>';
    return $text . ' · ' . $files . ' file' . ($files === 1 ? '' : 's') . ($run['type'] === 'rollback' ? ' restored' : '');
};

$dayLabel = static function (int $time): string {
    return match (date('Y-m-d', $time)) {
        date('Y-m-d') => 'Today',
        date('Y-m-d', strtotime('-1 day')) => 'Yesterday',
        default => date('D, j M Y', $time),
    };
};
$days = [];
foreach ($runs as $run) {
    $days[$dayLabel((int) $run['created_at'])][] = $run;
}
?>
<div class="page-head">
    <h1>History</h1>
    <?php if ($total > 0): ?>
        <a class="button" href="<?= $url(['format' => 'csv']) ?>">Download CSV</a>
    <?php endif ?>
</div>

<form method="get" action="index.php" class="history-filters js-autosubmit">
    <input type="hidden" name="r" value="history">
    <?php if ($filters['type'] !== ''): ?><input type="hidden" name="type" value="<?= e($filters['type']) ?>"><?php endif ?>
    <nav class="local-tabs" aria-label="Run type">
        <?php foreach ($types as $type => $label): ?>
            <a href="<?= $url(['type' => $type]) ?>"<?= $filters['type'] === $type ? ' class="active" aria-current="page"' : '' ?>><?= e($label) ?> <span class="pill"><?= $counts[$type] ?? 0 ?></span></a>
        <?php endforeach ?>
    </nav>
    <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search commit, site or person" aria-label="Search">
    <?php if (count($sources) > 1): ?>
        <select name="local" aria-label="Local">
            <option value="">Every local</option>
            <?php foreach ($sources as $key => $source): ?>
                <option value="<?= e($key) ?>"<?= $filters['local'] === $key ? ' selected' : '' ?>><?= e($source['name']) ?></option>
            <?php endforeach ?>
        </select>
    <?php endif ?>
    <select name="site" aria-label="Site">
        <option value="">Every site</option>
        <?php foreach ($sites as $key => $name): ?>
            <option value="<?= e($key) ?>"<?= $filters['site'] === $key ? ' selected' : '' ?>><?= e($name) ?></option>
        <?php endforeach ?>
    </select>
    <select name="status" aria-label="Status">
        <option value="">Any status</option>
        <?php foreach (RunView::STATUSES as $key => [$label]): ?>
            <option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach ?>
    </select>
    <noscript><button type="submit">Filter</button></noscript>
    <?php if ($filtered): ?><a class="meta" href="index.php?r=history">Clear</a><?php endif ?>
</form>

<?php if (!$runs): ?>
    <div class="card empty-state">
        <p><?= $filtered ? 'No runs match these filters.' : 'No deploys or compares yet.' ?></p>
        <?php if ($filtered): ?><a class="button small" href="index.php?r=history">Show every run</a><?php endif ?>
    </div>
<?php else: ?>
    <?php foreach ($days as $day => $dayRuns): ?>
        <section class="history-day">
            <h2 class="day-head"><?= e($day) ?> <span class="pill"><?= count($dayRuns) ?></span></h2>
            <ul class="run-list">
                <?php foreach ($dayRuns as $run): ?>
                    <?php
                    [$label, $style] = RunView::STATUSES[$run['status']] ?? [$run['status'], 'neutral'];
                    $kind = $run['type'] === 'sync' ? 'deploy' : $run['type'];
                    $backup = match ($run['type']) {
                        'compare' => '',
                        'rollback' => $backupLink((string) ($run['rollback_of'] ?? ''), 'Deploy\'s backup'),
                        default => $backupLink($run['id'], 'Backup'),
                    };
                    ?>
                    <li class="run-row">
                        <time class="run-time" datetime="<?= e(date('c', $run['created_at'])) ?>"><?= e(date('H:i', $run['created_at'])) ?></time>
                        <span class="run-kind kind-<?= e($kind) ?>"><?= e(RunView::TYPES[$run['type']] ?? $run['type']) ?></span>
                        <div class="run-main">
                            <a class="run-title" href="index.php?r=run&amp;id=<?= e(urlencode($run['id'])) ?>"><?= e($run['site_name']) ?></a>
                            <?php if (!empty($run['production'])): ?><span class="badge badge-danger">Production</span><?php endif ?>
                            <p class="meta"><?= $summary($run) ?> · <?= e($run['by']) ?><?= $backup !== '' ? ' · ' . $backup : '' ?></p>
                            <?php if ($run['status'] === 'failed' && !empty($run['error'])): ?>
                                <p class="error-text run-error"><?= e((string) $run['error']) ?></p>
                            <?php endif ?>
                        </div>
                        <span class="badge badge-<?= e($style) ?>"><?= e($label) ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
        </section>
    <?php endforeach ?>

    <?php if ($pages > 1): ?>
        <nav class="pager" aria-label="Pages">
            <?php if ($pageNumber > 1): ?><a class="button small" href="<?= $url(['page' => $pageNumber - 1]) ?>">Newer</a><?php endif ?>
            <span class="meta">Page <?= $pageNumber ?> of <?= $pages ?> · <?= $total ?> runs</span>
            <?php if ($pageNumber < $pages): ?><a class="button small" href="<?= $url(['page' => $pageNumber + 1]) ?>">Older</a><?php endif ?>
        </nav>
    <?php endif ?>
    <p class="meta history-note">Each backup is a zip of the server's files from just before that run, in <code><?= e(str_replace('/', '\\', realpath(STORAGE_DIR . '/backups') ?: STORAGE_DIR . '/backups')) ?></code>.</p>
<?php endif ?>
