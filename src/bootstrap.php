<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

/**
 * A setting from the real environment, else from the .env file next to index.php
 * (KEY=value lines, # comments). Read each request, never put into the process
 * environment, so an edit to .env counts at once.
 */
function env(string $key): ?string
{
    static $file = null;
    $real = getenv($key);
    if ($real !== false) {
        return $real;
    }
    if ($file === null) {
        $file = [];
        foreach (is_file(APP_ROOT . '/.env') ? (file(APP_ROOT . '/.env', FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $m)) {
                $file[$m[1]] = trim($m[2], '"\'');
            }
        }
    }
    return $file[$key] ?? null;
}

// Where the tool keeps its data: DEPLOY_TOOL_STORAGE (automated tests use it), else the folder
// chosen in Settings → Storage (noted in storage/location.json here), else storage/ here.
// A chosen folder that is gone (a drive unplugged) falls back to storage/ and says so.
define('DEFAULT_STORAGE_DIR', APP_ROOT . '/storage');
(static function (): void {
    $chosen = null;
    if (!env('DEPLOY_TOOL_STORAGE') && is_file(DEFAULT_STORAGE_DIR . '/location.json')) {
        $chosen = json_decode((string) file_get_contents(DEFAULT_STORAGE_DIR . '/location.json'), true)['path'] ?? null;
    }
    define('STORAGE_MISSING', is_string($chosen) && !is_dir($chosen) ? $chosen : null);
    define('STORAGE_DIR', env('DEPLOY_TOOL_STORAGE') ?: (is_string($chosen) && is_dir($chosen) ? $chosen : DEFAULT_STORAGE_DIR));
})();

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
