<?php
/**
 * Result of a compare, with "Record version" and "Make it match".
 *
 * @var array $run
 */

use DeployTool\Http;
use DeployTool\ProtectedPaths;
use DeployTool\ProtectPicker;
use DeployTool\RunStore;
use DeployTool\RunView;
use DeployTool\Sources;

$result = $run['result'];

// Extra files grouped by the folder git doesn't have, each with a Protect button.
$extraGroups = null;
$regexes = [];
if ($result['extra']) {
    try {
        $source = RunStore::source($run);
        $extraGroups = ProtectPicker::groupServerOnly($result['extra'], ProtectPicker::foldersAt(Sources::git($source), $run['to']));
        $regexes = ProtectedPaths::regexes($source);
    } catch (\RuntimeException) { // the commit is gone from the repository
        $extraGroups = null; // plain list below
    }
}
$issues = count($result['differs']) + count($result['missing']);
$isSizes = $result['method'] === 'sizes';
$lists = [
    'differs' => [$isSizes ? 'Different size' : 'Different', 'Content differs from the commit.'],
    'eol' => ['Line endings only', 'Same as the commit except Windows line endings. Harmless.'],
    'missing' => ['Missing on server', 'In the commit, not on the server.'],
    'extra' => ['Extra on server', 'On the server, not in the commit. Never deleted by the tool.'],
];
$hidden = '<input type="hidden" name="_csrf" value="' . e(Http::csrfToken()) . '"><input type="hidden" name="id" value="' . e($run['id']) . '">';

// Files "Make it match" can upload get a checkbox (part of #sync-form): different and
// missing ones start ticked, line-ending-only ones unticked.
$pickable = $run['recorded'] ? [] : ['differs' => true, 'missing' => true, 'eol' => false];
$ticked = count($result['differs']) + count($result['missing']);

// The ticked files as a folder tree (RunView::pathTree). A folder's checkbox ticks every
// file in it; only file checkboxes are sent with the form.
$renderTree = static function (array $node, string $list, bool $checked, bool $open, string $prefix = '') use (&$renderTree): string {
    $html = '';
    foreach ($node['dirs'] as $name => $child) {
        $folder = $prefix . $name . '/';
        $html .= '<li><details class="tree-dir"' . ($open ? ' open' : '') . '><summary>'
            . '<input type="checkbox" class="js-tree-dir" aria-label="Tick every file in ' . e($folder) . '"' . ($checked ? ' checked' : '') . '>'
            . '<span class="tree-name">' . e($name) . '/</span><span class="pill">' . $child['count'] . '</span></summary>'
            . '<ul class="tree">' . $renderTree($child, $list, $checked, $open, $folder) . '</ul></details></li>';
    }
    foreach ($node['files'] as $name => $path) {
        $html .= '<li class="tree-file"><label class="checkbox">'
            . '<input type="checkbox" form="sync-form" name="files[]" value="' . e($path) . '" data-list="' . e($list) . '"' . ($checked ? ' checked' : '') . '>'
            . '<span class="tree-name" title="' . e($path) . '">' . e((string) $name) . '</span></label></li>';
    }
    return $html;
};
?>
<section class="section">
    <div class="result-headline <?= $issues === 0 ? 'is-ok' : 'is-warning' ?>">
        <?php if ($issues === 0): ?>
            <strong>The server matches the commit.</strong>
            <span><?= count($result['eol']) ? count($result['eol']) . ' file(s) differ only in line endings, which is harmless.' : 'Every file is the same.' ?></span>
        <?php else: ?>
            <strong><?= $issues ?> file<?= $issues === 1 ? '' : 's' ?> on the server <?= $issues === 1 ? 'doesn\'t' : 'don\'t' ?> match the commit.</strong>
            <span><?= $run['recorded'] ? 'Its version was recorded with these differences accepted.' : 'Tick the ones to upload below, then use "Make it match", or record the version as it is.' ?></span>
        <?php endif ?>
    </div>

    <div class="metrics result-metrics">
        <div class="metric metric-ok"><p class="metric-label">Same</p><p class="metric-value"><?= $result['same'] ?></p></div>
        <?php foreach ($lists as $key => [$label]): ?>
            <?php if ($key === 'eol' && $isSizes) { continue; } ?>
            <?php $warn = in_array($key, ['differs', 'missing'], true) && $result[$key] ? ' metric-warning' : ''; ?>
            <?php if ($result[$key]): ?>
                <a class="metric js-open-section<?= $warn ?>" href="#<?= e($key) ?>"><p class="metric-label"><?= e($label) ?></p><p class="metric-value"><?= count($result[$key]) ?></p></a>
            <?php else: ?>
                <div class="metric"><p class="metric-label"><?= e($label) ?></p><p class="metric-value">0</p></div>
            <?php endif ?>
        <?php endforeach ?>
    </div>
    <?php if ($isSizes): ?>
        <p class="meta">Compared by file size: an edit that keeps the same size isn't spotted, and files with Windows line endings show as different. Use the fingerprint method for an exact answer.</p>
    <?php endif ?>

    <?php foreach ($lists as $key => [$label, $help]): ?>
        <?php if (!empty($result[$key])): ?>
            <details class="card section result-section" id="<?= e($key) ?>" <?= ($key === 'extra' && ($_GET['open'] ?? '') === 'extra') || ($pickable[$key] ?? false) ? 'open' : '' ?>>
                <summary>
                    <span class="section-title"><?= e($label) ?></span> <span class="pill<?= in_array($key, ['differs', 'missing'], true) ? ' pill-warning' : '' ?>"><?= count($result[$key]) ?></span>
                    <span class="section-help"><?= e($help) ?></span>
                </summary>
                <?php if (isset($pickable[$key])): ?>
                    <div class="check-tools">
                        <span class="meta">Ticked files are uploaded by "Make it match".</span>
                        <button type="button" class="small js-check-all" data-list="<?= e($key) ?>" data-checked="1">Tick all</button>
                        <button type="button" class="small js-check-all" data-list="<?= e($key) ?>" data-checked="0">Untick all</button>
                    </div>
                    <ul class="tree tree-root">
                        <?= $renderTree(RunView::pathTree($result[$key]), $key, $pickable[$key], count($result[$key]) <= 50) ?>
                    </ul>
                <?php else: ?>
                <?php if ($key === 'extra' && $extraGroups !== null): ?>
                    <p class="meta">Grouped by the folder git doesn't have. Protect server data (uploads, generated files, vendor folders) so compares and deploys skip it.</p>
                    <ul class="extra-groups">
                        <?php foreach (array_slice($extraGroups, 0, 100, true) as $rule => $paths): ?>
                            <?php $rule = (string) $rule; ?>
                            <li>
                                <code><?= e($rule) ?></code>
                                <span class="meta"><?= count($paths) ?> file<?= count($paths) === 1 ? '' : 's' ?></span>
                                <?php if (ProtectedPaths::matches($rule, $regexes)): ?>
                                    <span class="badge badge-success">Protected</span>
                                <?php else: ?>
                                    <form method="post" action="index.php?r=protect-add" class="inline">
                                        <?= $hidden ?>
                                        <input type="hidden" name="rule" value="<?= e($rule) ?>">
                                        <button type="submit" class="small">Protect</button>
                                    </form>
                                <?php endif ?>
                            </li>
                        <?php endforeach ?>
                    </ul>
                    <p class="meta">Every path:</p>
                <?php endif ?>
                <ul class="path-list">
                    <?php foreach (array_slice($result[$key], 0, 500) as $path): ?>
                        <li><code><?= e($path) ?></code></li>
                    <?php endforeach ?>
                </ul>
                <?php if (count($result[$key]) > 500): ?>
                    <p class="meta">Showing 500. The export has all of them.</p>
                <?php endif ?>
                <?php endif ?>
            </details>
        <?php endif ?>
    <?php endforeach ?>

    <?php if ($run['recorded']): ?>
        <div class="notice notice-success section">Commit <?= e(short_sha($run['to'])) ?> was recorded as this server's version.</div>
    <?php else: ?>
        <h2 class="section next-head">What next</h2>
        <div class="choice-grid">
            <form method="post" action="index.php?r=compare-sync" class="card" id="sync-form">
                <?= $hidden ?>
                <input type="hidden" name="pick" value="1">
                <h3>Make it match</h3>
                <p class="meta">Uploads the files ticked above from the commit, with the usual review, backup and verify, then records the version. Extra files stay.</p>
                <p class="meta"><strong class="js-pick-count"><?= $ticked ?></strong> of <?= $issues + count($result['eol']) ?> file(s) ticked.</p>
                <button type="submit" class="primary">Prepare the upload</button>
            </form>
            <form method="post" action="index.php?r=compare-record" class="card">
                <?= $hidden ?>
                <h3>Record version only</h3>
                <p class="meta">Writes commit <?= e(short_sha($run['to'])) ?> as the server's version without uploading anything. Future deploys then start from it.</p>
                <?php if ($issues > 0): ?>
                    <label class="checkbox"><input type="checkbox" name="ack" value="1" required>
                        <span>I know <?= $issues ?> file(s) differ and accept them as they are</span></label>
                <?php endif ?>
                <button type="submit">Record version</button>
            </form>
        </div>
    <?php endif ?>
</section>
