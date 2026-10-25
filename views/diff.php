<?php
/**
 * @var array $run
 * @var string $path
 * @var array $labels
 * @var bool $beforeMissing
 * @var bool $afterMissing
 * @var string $text
 */
?>
<h1><code><?= e($path) ?></code></h1>
<p class="lead">
    <span class="diff-del">− <?= e($labels[0]) ?><?= $beforeMissing ? ' (no file)' : '' ?></span>
    &nbsp; <span class="diff-add">+ <?= e($labels[1]) ?><?= $afterMissing ? ' (no file)' : '' ?></span>
    <br><a href="index.php?r=run&amp;id=<?= e(urlencode($run['id'])) ?>">Back to the run</a>
</p>
<?php if (trim($text) === ''): ?>
    <p>No differences.</p>
<?php else: ?>
    <pre class="diff"><?php foreach (preg_split('/\r?\n/', $text) as $line): ?><?php
        $class = match (true) {
            str_starts_with($line, '+++') || str_starts_with($line, '---') || str_starts_with($line, 'diff ') || str_starts_with($line, 'index ') => 'diff-meta',
            str_starts_with($line, '@@') => 'diff-hunk',
            str_starts_with($line, '+') => 'diff-add',
            str_starts_with($line, '-') => 'diff-del',
            default => '',
        };
        ?><span class="<?= $class ?>"><?= e($line) ?></span>
<?php endforeach ?></pre>
<?php endif ?>
