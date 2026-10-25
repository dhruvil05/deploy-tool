<?php
/**
 * @var string $title
 * @var string $page
 * @var array $flash
 * @var string $content
 */

use DeployTool\Http;

$nav = [
    'dashboard' => ['index.php', 'Dashboard'],
    'history' => ['index.php?r=history', 'History'],
    'sites' => ['index.php?r=sites', 'Sites'],
    'settings' => ['index.php?r=settings', 'Settings'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(Http::csrfToken()) ?>">
    <title><?= e($title) ?> · Deploy tool</title>
    <link rel="stylesheet" href="assets/app.css?v=<?= filemtime(APP_ROOT . '/assets/app.css') ?>">
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php">Deploy tool</a>
    <nav>
        <?php foreach ($nav as $key => [$href, $label]): ?>
            <a href="<?= e($href) ?>" class="<?= $page === $key ? 'active' : '' ?>"><?= e($label) ?></a>
        <?php endforeach ?>
    </nav>
</header>
<main>
    <?php foreach ($flash as [$type, $message]): ?>
        <div class="notice notice-<?= e($type) ?>"><?= e($message) ?></div>
    <?php endforeach ?>
    <?= $content ?>
</main>
<script src="assets/app.js?v=<?= filemtime(APP_ROOT . '/assets/app.js') ?>"></script>
</body>
</html>
