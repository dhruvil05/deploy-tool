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
if (\DeployTool\Ai::enabled()) {
    $nav['assistant'] = ['index.php?r=assistant', 'Assistant'];
}
$password = \DeployTool\Auth::enabled();
$signedOut = $password && !\DeployTool\Auth::signedIn();
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
    <?php if (!$signedOut): ?>
        <nav>
            <?php foreach ($nav as $key => [$href, $label]): ?>
                <a href="<?= e($href) ?>" class="<?= $page === $key ? 'active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach ?>
        </nav>
        <?php if ($password): ?>
            <form method="post" action="index.php?r=logout" class="topbar-lock">
                <input type="hidden" name="_csrf" value="<?= e(Http::csrfToken()) ?>">
                <button type="submit" class="small" title="Sign out of the tool">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg>
                    Lock
                </button>
            </form>
        <?php endif ?>
    <?php endif ?>
</header>
<main>
    <?php if (($exposed = \DeployTool\Exposure::warning()) !== null): ?>
        <div class="notice notice-danger exposure-warning" role="alert"><strong>Your passwords may be public.</strong> <?= e($exposed) ?></div>
    <?php endif ?>
    <?php if (\DeployTool\Secrets::$unreadable > 0): ?>
        <div class="notice notice-warning" role="alert">Some saved passwords or keys can't be unlocked on this computer: they were sealed with another key file. Type them again where they're used (the site's page, Settings → AI assistant or Cloud backup), or restore a cloud backup, which brings its key along.</div>
    <?php endif ?>
    <?php foreach ($flash as [$type, $message]): ?>
        <div class="notice notice-<?= e($type) ?>"><?= e($message) ?></div>
    <?php endforeach ?>
    <?= $content ?>
</main>
<script src="assets/app.js?v=<?= filemtime(APP_ROOT . '/assets/app.js') ?>"></script>
</body>
</html>
