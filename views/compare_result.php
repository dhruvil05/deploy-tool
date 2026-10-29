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
use DeployTool\Sources;

$result = $run['result'];

// Extra files grouped by the folder git doesn't have, each with a Protect button.
$extraGroups = null;
$regexes = [];
if ($result['extra']) {
    try {
        $source = RunStore::source($run);
        $git = Sources::git($source);
        if ($git->resolve($run['to']) !== null) {
            $extraGroups = ProtectPicker::groupServerOnly($result['extra'], ProtectPicker::foldersAt($git, $run['to']));
            $regexes = ProtectedPaths::regexes($source);
        }
    } catch (\RuntimeException) {
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
?>
<section class="section">
    <h2>Result</h2>
    <div class="metrics">
        <div class="metric"><p class="metric-label">Same</p><p class="metric-value"><?= $result['same'] ?></p></div>
        <?php if (!$isSizes): ?>
            <div class="metric"><p class="metric-label">Line endings only</p><p class="metric-value"><?= count($result['eol']) ?></p></div>
        <?php endif ?>
        <div class="metric<?= $result['differs'] ? ' metric-warning' : '' ?>"><p class="metric-label"><?= $isSizes ? 'Different size' : 'Different' ?></p><p class="metric-value"><?= count($result['differs']) ?></p></div>
        <div class="metric<?= $result['missing'] ? ' metric-warning' : '' ?>"><p class="metric-label">Missing on server</p><p class="metric-value"><?= count($result['missing']) ?></p></div>
        <div class="metric"><p class="metric-label">Extra on server</p><p class="metric-value"><?= count($result['extra']) ?></p></div>
    </div>
    <?php if ($isSizes): ?>
        <p class="meta">Compared by file size: an edit that keeps the same size isn't spotted, and files with Windows line endings show as different. Use the fingerprint method for an exact answer.</p>
    <?php endif ?>

    <?php foreach ($lists as $key => [$label, $help]): ?>
        <?php if (!empty($result[$key])): ?>
            <details class="card section" id="<?= e($key) ?>" <?= $key === 'extra' && ($_GET['open'] ?? '') === 'extra' ? 'open' : '' ?>>
                <summary><?= e($label) ?> (<?= count($result[$key]) ?>)</summary>
                <p class="meta"><?= e($help) ?></p>
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
            </details>
        <?php endif ?>
    <?php endforeach ?>

    <?php if ($run['recorded']): ?>
        <div class="notice notice-success">Commit <?= e(short_sha($run['to'])) ?> was recorded as this server's version.</div>
    <?php else: ?>
        <div class="choice-grid">
            <form method="post" action="index.php?r=compare-sync" class="card">
                <?= $hidden ?>
                <h3>Make it match</h3>
                <p class="meta">Uploads the <?= $issues ?> different or missing file(s) from the commit, with the usual review, backup and verify, then records the version. Extra files stay.</p>
                <?php if ($result['eol']): ?>
                    <label class="checkbox"><input type="checkbox" name="include_eol" value="1">
                        <span>Also replace the <?= count($result['eol']) ?> file(s) that differ only in line endings</span></label>
                <?php endif ?>
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
