<?php
/**
 * Speed-up "Files of each commit": a section that loads the commits and their file trees
 * when it is opened, so the page around it stays quick.
 *
 * @var string $source the local's key
 * @var ?string $from the version before (not listed); null for only $to's own files
 * @var string $to
 * @var string $title
 * @var string $help
 * @var bool $card a page section (true) or a panel inside the dashboard's local card
 * @var bool $open load and show it at once
 */

$card ??= true;
$open ??= false;
$url = 'index.php?' . http_build_query(array_filter(['r' => 'commit-files', 'source' => $source, 'from' => $from, 'to' => $to], static fn ($v): bool => $v !== null));
?>
<details class="commit-files js-commit-files<?= $card ? ' card section result-section' : ' commit-files-panel' ?>" data-url="<?= e($url) ?>"<?= $open ? ' open' : '' ?>>
    <summary>
        <span class="section-title"><?= e($title) ?></span>
        <span class="section-help"><?= e($help) ?></span>
    </summary>
    <div class="commit-files-body"><p class="meta">Loading the commits…</p></div>
</details>
