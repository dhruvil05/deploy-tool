<?php
/**
 * Speed-up "Files of each commit": each commit with the files it changed as a folder tree.
 * Clicking a file opens its changes under it.
 *
 * @var string $source the local's key
 * @var array $commitData CommitFiles::between()
 * @var bool $fromGiven whether a version before was asked for
 */

use DeployTool\RunView;

$commits = $commitData['commits'];
$marks = ['A' => ['new', 'badge-success'], 'D' => ['removed', 'badge-danger']];

$renderTree = static function (array $node, array $status, string $sha, bool $open) use (&$renderTree, $source, $marks): string {
    $html = '';
    foreach ($node['dirs'] as $name => $child) {
        $html .= '<li><details class="tree-dir"' . ($open ? ' open' : '') . '><summary>'
            . '<span class="tree-name">' . e((string) $name) . '/</span><span class="pill">' . $child['count'] . '</span></summary>'
            . '<ul class="tree">' . $renderTree($child, $status, $sha, $open) . '</ul></details></li>';
    }
    foreach ($node['files'] as $name => $path) {
        $url = 'index.php?' . http_build_query(['r' => 'commit-diff', 'source' => $source, 'sha' => $sha, 'path' => $path]);
        [$label, $style] = $marks[$status[$path] ?? 'M'] ?? [null, null];
        $html .= '<li class="tree-file commit-file">'
            . '<button type="button" class="commit-file-button js-commit-file" data-url="' . e($url) . '" aria-expanded="false" title="Show the changes to ' . e($path) . '">'
            . '<span class="tree-name">' . e((string) $name) . '</span>'
            . ($label !== null ? ' <span class="badge ' . $style . '">' . $label . '</span>' : '')
            . '</button></li>';
    }
    return $html;
};
?>
<p class="meta commit-files-note">
    <?php if ($commitData['only_target'] && $fromGiven): ?>
        The server's recorded version isn't in this branch's history (or there is none), so this shows the files of commit <code><?= e(short_sha($commits[0]['sha'])) ?></code> itself.
    <?php elseif ($commitData['only_target']): ?>
        The files this commit changed. Click a file to see its changes.
    <?php elseif ($commitData['total'] > count($commits)): ?>
        <?= $commitData['total'] ?> commits; the newest <?= count($commits) ?> are listed, newest first. Click a file to see its changes.
    <?php else: ?>
        <?= count($commits) ?> commit(s), newest first. Click a file to see its changes.
    <?php endif ?>
</p>
<div class="commit-file-list">
    <?php foreach ($commits as $i => $commit): ?>
        <?php
        $status = [];
        foreach ($commit['files'] as [$letter, $path]) {
            $status[$path] = $letter;
        }
        $count = count($status);
        ?>
        <details class="commit-block"<?= $i === 0 || count($commits) <= 3 ? ' open' : '' ?>>
            <summary>
                <code class="commit-sha"><?= e(short_sha($commit['sha'])) ?></code>
                <span class="commit-subject"><?= e($commit['subject']) ?></span>
                <span class="meta commit-meta"><?= e($commit['author']) ?> · <?= e(substr($commit['date'], 0, 16)) ?></span>
                <span class="pill"><?= $count ?> file<?= $count === 1 ? '' : 's' ?></span>
            </summary>
            <?php if ($count === 0): ?>
                <p class="meta">No file changes (a merge or an empty commit).</p>
            <?php else: ?>
                <ul class="tree tree-root commit-tree">
                    <?= $renderTree(RunView::pathTree(array_keys($status)), $status, $commit['sha'], $count <= 40) ?>
                </ul>
            <?php endif ?>
        </details>
    <?php endforeach ?>
</div>
<?php unset($renderTree); ?>
