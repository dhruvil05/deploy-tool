<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Runs a run in a background PHP process (src/run-worker.php), so it keeps going when its
 * page is closed or reloaded, and keeps one set of server connections for the whole run.
 *
 * A worker holds runs/<id>.worker locked while it lives; the lock goes away when the
 * process ends in any way, so "is a worker running?" is always answered correctly.
 * The worker stops once the run stops running (done, failed, or waiting for you).
 */
final class Worker
{
    /** A worker that hasn't taken its lock this long after being started is assumed to have failed. */
    private const START_SECONDS = 20;

    /** After a failed start, the run page steps the run itself for this long before trying again. */
    private const RETRY_SECONDS = 300;

    /** The body of src/run-worker.php. */
    public static function main(string $id): void
    {
        RunStore::load($id); // validates the id
        $lock = fopen(self::lockFile($id), 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return; // another worker has this run
        }
        @unlink(self::startedFile($id));
        try {
            $context = null;
            do {
                $run = Runner::step($id, $context);
                if (!empty($run['busy'])) {
                    sleep(1); // an older page is stepping it right now
                }
            } while ($run['status'] === 'running');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Makes sure a running run has a worker, starting one when needed. Returns false when no
     * worker can run it; the page then steps the run itself, like before workers existed.
     */
    public static function ensure(array $run): bool
    {
        if ($run['status'] !== 'running' || self::isRunning($run['id'])) {
            return true;
        }
        if (!Settings::backgroundRuns()) {
            return false; // turned off in Settings: the run page steps it
        }
        $started = @filemtime(self::startedFile($run['id']));
        if ($started !== false && time() - $started < self::START_SECONDS) {
            return true; // still starting
        }
        if ($started !== false && time() - $started < self::START_SECONDS + self::RETRY_SECONDS) {
            return false; // the last start failed: see storage/worker.log
        }
        touch(self::startedFile($run['id']));
        return self::spawn($run['id']);
    }

    public static function isRunning(string $id): bool
    {
        $lock = @fopen(self::lockFile($id), 'c');
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

    /** Where workers write PHP errors that happen outside a run's own log. */
    public static function logFile(): string
    {
        return STORAGE_DIR . '/worker.log';
    }

    private static function spawn(string $id): bool
    {
        return self::spawnScript(APP_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'run-worker.php', [$id]);
    }

    /**
     * Starts "php <script> <args>" detached from this request (run workers, cloud backups).
     * On Windows it goes through PowerShell's Start-Process, so it has no window and doesn't
     * end when the window that started the tool is closed. Arguments must be simple words.
     */
    public static function spawnScript(string $script, array $args): bool
    {
        $php = Settings::phpBinary();
        if (PHP_OS_FAMILY === 'Windows') {
            $quote = static fn (string $value): string => "'" . str_replace("'", "''", $value) . "'";
            $command = 'Start-Process -WindowStyle Hidden -FilePath ' . $quote($php)
                . ' -ArgumentList ' . implode(', ', array_merge([$quote('"' . $script . '"')], array_map($quote, $args)));
            $process = proc_open(['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $command], [
                0 => ['file', 'NUL', 'r'],
                1 => ['file', 'NUL', 'w'],
                2 => ['file', self::logFile(), 'a'],
            ], $pipes);
            return is_resource($process) && proc_close($process) === 0;
        }
        exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . implode('', array_map(static fn (string $arg): string => ' ' . escapeshellarg($arg), $args))
            . ' >> ' . escapeshellarg(self::logFile()) . ' 2>&1 < /dev/null &', $output, $code);
        return $code === 0;
    }

    private static function lockFile(string $id): string
    {
        return RunStore::dir() . "/$id.worker";
    }

    private static function startedFile(string $id): string
    {
        return RunStore::dir() . "/$id.starting";
    }
}
