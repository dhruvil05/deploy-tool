<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Moves runs forward a few seconds at a time. Normally a background Worker calls step()
 * until the run stops running, so closing or reloading the page changes nothing. When no
 * worker can be started, the run page calls step() itself, over and over.
 *
 * Every step is safe to repeat: uploading the same file twice or deleting a file
 * that is already gone changes nothing.
 */
final class Runner
{
    private const STEP_SECONDS = 8.0;

    /**
     * $context lets a worker keep its connections between steps: pass the same variable
     * every time (null the first time). It is reset after an error.
     */
    public static function step(string $id, ?RunContext &$context = null): array
    {
        RunStore::load($id); // validates the id
        $lock = fopen(RunStore::dir() . "/$id.lock", 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['busy' => true] + RunStore::load($id); // another tab is stepping this run
        }

        try {
            $run = RunStore::load($id);
            if ($run['status'] !== 'running') {
                return $run;
            }
            $deadline = microtime(true) + self::STEP_SECONDS;
            $stopFile = self::stopFile($id);
            try {
                $site = Sites::get($run['site']) ?? throw new \RuntimeException('This site no longer exists.');
                $context ??= new RunContext($site, $run);
                while ($run['status'] === 'running' && microtime(true) < $deadline && !is_file($stopFile)) {
                    match ($run['type']) {
                        'deploy', 'sync' => DeployRun::advance($run, $context, $deadline),
                        'rollback' => RollbackRun::advance($run, $context, $deadline),
                        'compare' => CompareRun::advance($run, $context, $deadline),
                    };
                }
            } catch (\Throwable $e) {
                $context = null; // connections may be broken: start fresh next time
                $run['status'] = 'failed';
                $run['error'] = $e->getMessage();
                self::log($run, 'error', $e->getMessage());
            }
            self::applyStopFlag($run);
            RunStore::save($run);
            // A deploy, match or rollback just changed a server: a cloud backup may be due.
            if (in_array($run['type'], RunStore::CHANGING_TYPES, true) && !empty($run['touched']) && in_array($run['status'], ['sql', 'done'], true)) {
                CloudBackup::maybeStart(true);
            }
            return $run;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Asks a running run to stop. It stops after the file it is on (within a few seconds);
     * if no step is running right now, it stops immediately.
     */
    public static function requestStop(string $id): void
    {
        touch(self::stopFile($id));
        $lock = fopen(RunStore::dir() . "/$id.lock", 'c');
        if (flock($lock, LOCK_EX | LOCK_NB)) {
            $run = RunStore::load($id);
            self::applyStopFlag($run);
            RunStore::save($run);
            flock($lock, LOCK_UN);
        }
        fclose($lock);
    }

    private static function applyStopFlag(array &$run): void
    {
        $stopFile = self::stopFile($run['id']);
        if (!is_file($stopFile)) {
            return;
        }
        @unlink($stopFile);
        if ($run['status'] === 'running') {
            $run['status'] = 'failed';
            $run['error'] = 'Stopped by you.';
            self::log($run, 'warning', 'Stopped by you.');
        }
    }

    private static function stopFile(string $id): string
    {
        return RunStore::dir() . "/$id.stop";
    }

    /** Indexes of the next (at most $limit) files that $filter accepts: one batch. */
    public static function pick(array $files, callable $filter, int $limit): array
    {
        $picked = [];
        foreach ($files as $i => $file) {
            if ($filter($file)) {
                $picked[] = $i;
                if (count($picked) >= $limit) {
                    break;
                }
            }
        }
        return $picked;
    }

    /** Throws when a batch had failures, naming the first few. */
    public static function failOnErrors(array $errors, string $verb): void
    {
        if ($errors === []) {
            return;
        }
        $first = array_slice($errors, 0, 3, true);
        $details = implode('; ', array_map(static fn ($path, $error): string => "$path: $error", array_keys($first), $first));
        throw new \RuntimeException(count($errors) . " file(s) could not be $verb ($details). Press Retry to try them again.");
    }

    public static function log(array &$run, string $level, string $message): void
    {
        $run['log'][] = [date('H:i:s'), $level, $message];
        if (count($run['log']) > 1000) {
            $run['log'] = array_slice($run['log'], -1000);
        }
    }

    /** Base fields every run has. */
    public static function newRun(string $type, array $site, string $firstPhase): array
    {
        return [
            'id' => RunStore::newId($type, $site['key']),
            'type' => $type,
            'site' => $site['key'],
            'site_name' => $site['name'],
            'repo_source' => Sites::rootSource($site)['key'],
            'production' => (bool) $site['production'],
            'by' => Settings::get('deployer_name'),
            'created_at' => time(),
            'updated_at' => time(),
            'status' => 'running',
            'phase' => $firstPhase,
            'touched' => false,
            'from' => null,
            'to' => null,
            'files' => [],
            'skipped' => [],
            'checks' => [],
            'error' => null,
            'log' => [],
        ];
    }
}
