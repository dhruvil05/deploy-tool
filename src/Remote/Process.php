<?php

declare(strict_types=1);

namespace DeployTool\Remote;

use DeployTool\Settings;
use DeployTool\Worker;

/**
 * The background process of a chat app bridge ($app: "discord", "slack"): one at a time
 * (storage/<app>.worker stays locked while it lives), started on page loads while its
 * speed-up is on, and restarted when the tool's code changes.
 */
final class Process
{
    /** Takes the app's lock; null when its process already runs. Release with release(). */
    public static function lock(string $app)
    {
        $lock = fopen(self::lockFile($app), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }
        @unlink(self::startedFile($app));
        return $lock;
    }

    public static function release($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /** Starts src/<app>-worker.php when the app's speed-up is on and it isn't running (any page load). */
    public static function ensure(string $app): void
    {
        if (!Settings::on("feature_{$app}_bridge") || self::isRunning($app)) {
            return;
        }
        $started = @filemtime(self::startedFile($app));
        if ($started !== false && time() - $started < 60) {
            return; // starting, or the last start failed a moment ago (see storage/worker.log)
        }
        touch(self::startedFile($app));
        self::spawn($app);
    }

    public static function spawn(string $app): void
    {
        Worker::spawnScript(APP_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . "$app-worker.php", []);
    }

    public static function isRunning(string $app): bool
    {
        $lock = @fopen(self::lockFile($app), 'c');
        if ($lock === false) {
            return false;
        }
        $free = flock($lock, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);
        return !$free;
    }

    /** Changes whenever a file of the tool's code changes (newest change time of src/). */
    public static function codeVersion(): int
    {
        clearstatcache();
        $newest = 0;
        foreach (array_merge(glob(APP_ROOT . '/src/*.php') ?: [], glob(APP_ROOT . '/src/*/*.php') ?: []) as $file) {
            $newest = max($newest, (int) @filemtime($file));
        }
        return $newest;
    }

    private static function lockFile(string $app): string
    {
        return STORAGE_DIR . "/$app.worker";
    }

    private static function startedFile(string $app): string
    {
        return STORAGE_DIR . "/$app.started";
    }
}
