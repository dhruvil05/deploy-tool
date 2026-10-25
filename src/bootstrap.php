<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
// DEPLOY_TOOL_STORAGE lets automated tests use their own folder instead of your real storage.
define('STORAGE_DIR', getenv('DEPLOY_TOOL_STORAGE') ?: APP_ROOT . '/storage');

// Laragon's php.ini says UTC; show and record times in Indian time.
date_default_timezone_set('Asia/Kolkata');

spl_autoload_register(static function (string $class): void {
    $prefix = 'DeployTool\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/** Escapes a value for HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** First 8 characters of a commit hash. */
function short_sha(?string $sha): string
{
    return $sha ? substr($sha, 0, 8) : '';
}

/** "5 min ago" style time. */
function ago(?int $timestamp): string
{
    if (!$timestamp) {
        return 'never';
    }
    $seconds = time() - $timestamp;
    if ($seconds < 60) {
        return 'just now';
    }
    [$count, $unit] = match (true) {
        $seconds < 3600 => [intdiv($seconds, 60), 'minute'],
        $seconds < 86400 => [intdiv($seconds, 3600), 'hour'],
        default => [intdiv($seconds, 86400), 'day'],
    };
    return $count . ' ' . $unit . ($count === 1 ? '' : 's') . ' ago';
}
