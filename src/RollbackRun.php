<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Undoes a deploy with the backups taken before it: files it replaced are put back,
 * files it added are deleted, and the server's recorded version is restored.
 *
 *   checks → review (waits for you) → restore → verify → record → site_check → done
 */
final class RollbackRun
{
    public const PHASES = [
        'checks' => 'Safety checks',
        'review' => 'Your review',
        'restore' => 'Restore files',
        'verify' => 'Verify',
        'record' => 'Restore recorded version',
        'site_check' => 'Site check',
    ];

    /** Why this run can't be rolled back, or null when it can. */
    public static function blocker(array $run): ?string
    {
        if (!in_array($run['type'], ['deploy', 'sync'], true)) {
            return 'Only deploys can be rolled back.';
        }
        if (empty($run['touched'])) {
            return 'This run never changed the server.';
        }
        if (!empty($run['rolled_back_by'])) {
            return 'This run was already rolled back.';
        }
        if (in_array($run['status'], ['running', 'review'], true)) {
            return 'Stop this run first.';
        }
        foreach (RunStore::changingAfter($run['id'], $run['site']) as $other) {
            if ($other['site'] === $run['site'] && in_array($other['type'], RunStore::CHANGING_TYPES, true) && !empty($other['touched'])) {
                return 'A later run changed this site. Roll that one back first.';
            }
        }
        return null;
    }

    public static function create(array $original): array
    {
        if (($reason = self::blocker($original)) !== null) {
            throw new \RuntimeException($reason);
        }
        $site = Sites::get($original['site']) ?? throw new \RuntimeException('This site no longer exists.');
        $open = RunStore::activeRunFor($site['key']);
        if ($open !== null && $open['id'] !== $original['id']) {
            throw new \RuntimeException("{$site['name']} has another open run. Finish it first.");
        }

        $run = Runner::newRun('rollback', $site, 'checks') + [
            'rollback_of' => $original['id'],
            'expected_state_raw' => $original['new_state_raw'] ?? $original['prev_state_raw'],
            'restore_state_raw' => $original['prev_state_raw'],
            'reviewed_at' => null,
            'approved_at' => null,
            'finished_at' => null,
            'site_check' => null,
        ];
        $run['from'] = $original['to'];
        $run['to'] = $original['from'];
        foreach (array_reverse($original['files']) as $file) {
            if (empty($file['sent'])) {
                continue; // never changed on the server
            }
            if ($file['existed']) {
                $run['files'][] = ['path' => $file['path'], 'action' => 'restore', 'change' => 'changed', 'done' => false, 'verified' => false];
            } elseif ($file['action'] === 'upload') {
                $run['files'][] = ['path' => $file['path'], 'action' => 'delete', 'change' => 'deleted', 'done' => false, 'verified' => false];
            }
        }
        RunStore::save($run);
        return $run;
    }

    public static function advance(array &$run, RunContext $context, float $deadline): void
    {
        match ($run['phase']) {
            'checks' => self::checks($run, $context),
            'restore' => self::restore($run, $context, $deadline),
            'verify' => self::verify($run, $context, $deadline),
            'record' => self::record($run, $context),
            'site_check' => self::siteCheck($run, $context),
        };
    }

    public static function approve(array &$run, array $input): ?string
    {
        if ($run['status'] !== 'review') {
            return 'This rollback is not waiting for your review.';
        }
        if ($run['production'] && trim((string) ($input['confirm'] ?? '')) !== $run['site']) {
            return "Type {$run['site']} to confirm the rollback on {$run['site_name']}.";
        }
        $site = Sites::get($run['site']);
        if ($site === null || ServerState::readRaw(Sites::transport($site)) !== $run['expected_state_raw']) {
            return "The server's recorded version changed, so rolling back is no longer safe.";
        }
        $original = RunStore::load($run['rollback_of']);
        $original['rolled_back_by'] = $run['id'];
        $original['status'] = 'rolled_back';
        Runner::log($original, 'info', 'Rolled back by ' . $run['id'] . '.');
        RunStore::save($original);

        $run['status'] = 'running';
        $run['phase'] = 'restore';
        $run['approved_at'] = time();
        Runner::log($run, 'info', 'Rollback approved by ' . Settings::get('deployer_name') . '.');
        return null;
    }

    private static function checks(array &$run, RunContext $context): void
    {
        $run['checks'] = [];
        $current = ServerState::readRaw($context->transport());
        $run['checks'][] = [$current === $run['expected_state_raw'], "The server's recorded version is still the one this deploy left"];
        $missing = array_filter($run['files'], static fn (array $file): bool => $file['action'] === 'restore'
            && Backup::get($run['rollback_of'], $file['path']) === null);
        $run['checks'][] = [$missing === [], $missing === [] ? 'Every backup file is there' : count($missing) . ' backup file(s) are missing from storage/backups'];

        if (array_filter($run['checks'], static fn (array $item): bool => !$item[0])) {
            $run['status'] = 'failed';
            $run['error'] = 'Rolling back is not safe. Nothing was changed on the server.';
            Runner::log($run, 'error', $run['error']);
            return;
        }
        $run['status'] = 'review';
        $run['phase'] = 'review';
        $run['reviewed_at'] = time();
        Runner::log($run, 'info', count($run['files']) . ' file(s) to put back. Waiting for your review.');
    }

    private static function restore(array &$run, RunContext $context, float $deadline): void
    {
        $run['touched'] = true;
        $transport = $context->transport();
        while ($batch = Runner::pick($run['files'], static fn (array $file): bool => !$file['done'], $transport->batchSize())) {
            if (microtime(true) >= $deadline) {
                return;
            }
            $puts = [];
            $deletes = [];
            foreach ($batch as $i) {
                $path = $run['files'][$i]['path'];
                if ($run['files'][$i]['action'] === 'restore') {
                    $puts[$path] = (string) Backup::get($run['rollback_of'], $path);
                } else {
                    $deletes[] = $path;
                }
            }
            $errors = ($puts !== [] ? $transport->putMany($puts) : []) + ($deletes !== [] ? $transport->deleteMany($deletes) : []);
            foreach ($batch as $i) {
                $run['files'][$i]['sent'] = true;
                if (!isset($errors[$run['files'][$i]['path']])) {
                    $run['files'][$i]['done'] = true;
                }
            }
            Runner::failOnErrors($errors, 'restored');
        }
        Runner::log($run, 'info', 'Files restored.');
        $run['phase'] = 'verify';
    }

    private static function verify(array &$run, RunContext $context, float $deadline): void
    {
        $transport = $context->transport();
        while ($batch = Runner::pick($run['files'], static fn (array $file): bool => $file['done'] && !$file['verified'], $transport->batchSize())) {
            if (microtime(true) >= $deadline) {
                return;
            }
            $servers = $transport->getMany(array_map(static fn (int $i): string => $run['files'][$i]['path'], $batch));
            foreach ($batch as $i) {
                $file = $run['files'][$i];
                $server = $servers[$file['path']];
                $ok = $file['action'] === 'restore' ? $server === Backup::get($run['rollback_of'], $file['path']) : $server === null;
                if ($ok) {
                    $run['files'][$i]['verified'] = true;
                } else {
                    $run['files'][$i]['done'] = false;
                    Runner::log($run, 'error', "{$file['path']} did not match the backup after restoring.");
                }
            }
        }
        $pending = count(array_filter($run['files'], static fn (array $file): bool => !$file['done']));
        if ($pending > 0) {
            $run['phase'] = 'restore';
            $run['status'] = 'failed';
            $run['error'] = "$pending file(s) did not match after restoring. Press Retry.";
            return;
        }
        Runner::log($run, 'info', 'Every restored file matches its backup.');
        $run['phase'] = 'record';
    }

    private static function record(array &$run, RunContext $context): void
    {
        ServerState::restore($context->transport(), $run['restore_state_raw']);
        $run['new_state_raw'] = $run['restore_state_raw'];
        Runner::log($run, 'info', $run['restore_state_raw'] === null ? 'Removed the recorded version (there was none before).' : 'Restored the earlier recorded version.');
        $run['phase'] = 'site_check';
    }

    private static function siteCheck(array &$run, RunContext $context): void
    {
        $run['site_check'] = SiteCheck::run($context->site);
        Runner::log($run, $run['site_check']['ok'] ? 'info' : 'error', 'Site check: ' . $run['site_check']['message']);
        $run['status'] = 'done';
        $run['phase'] = 'done';
        $run['finished_at'] = time();
    }
}
