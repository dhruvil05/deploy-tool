<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Moves runs forward a few seconds at a time. The run page calls step() over and over,
 * so nothing depends on PHP time limits or background processes. If the page is closed,
 * the run waits and continues when the page is opened again.
 *
 * Every step is safe to repeat: uploading the same file twice or deleting a file
 * that is already gone changes nothing.
 */
final class Runner
{
    private const STEP_SECONDS = 8.0;

    public static function step(string $id): array
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
                $context = new RunContext($site, $run);
                while ($run['status'] === 'running' && microtime(true) < $deadline && !is_file($stopFile)) {
                    match ($run['type']) {
                        'deploy', 'sync' => DeployRun::advance($run, $context, $deadline),
                        'rollback' => RollbackRun::advance($run, $context, $deadline),
                        'compare' => CompareRun::advance($run, $context, $deadline),
                    };
                }
            } catch (\Throwable $e) {
                $run['status'] = 'failed';
                $run['error'] = $e->getMessage();
                self::log($run, 'error', $e->getMessage());
            }
            self::applyStopFlag($run);
            RunStore::save($run);
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
