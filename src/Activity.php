<?php

declare(strict_types=1);

namespace DeployTool;

/** Deploy numbers and recent runs for one local, for the dashboard. */
final class Activity
{
    public const DAYS = 30;

    private const DEPLOY_TYPES = ['deploy', 'sync'];

    /**
     * @param array $runs RunStore::all(), newest first
     * @return array{deploys: int, production: int, files: int, failed: int, rollbacks: int,
     *               last: ?array, lastBySite: array<string, array>, recent: list<array>}
     */
    public static function forSource(array $runs, string $sourceKey): array
    {
        $runs = array_values(array_filter($runs, static fn (array $run): bool => ($run['repo_source'] ?? null) === $sourceKey));
        $since = time() - self::DAYS * 86400;
        $stats = ['deploys' => 0, 'production' => 0, 'files' => 0, 'failed' => 0, 'rollbacks' => 0, 'last' => null, 'lastBySite' => []];

        foreach ($runs as $run) {
            $isDeploy = in_array($run['type'], self::DEPLOY_TYPES, true);
            // A deploy that was rolled back later still reached the server.
            $reached = $isDeploy && in_array($run['status'], ['done', 'rolled_back'], true);
            if ($reached) {
                $stats['last'] ??= $run;
                $stats['lastBySite'][$run['site']] ??= $run;
            }
            if ($run['created_at'] < $since) {
                continue;
            }
            if ($reached) {
                $stats['deploys']++;
                $stats['production'] += empty($run['production']) ? 0 : 1;
                foreach ($run['files'] as $file) {
                    $stats['files'] += $file['action'] === 'upload' && $file['done'] ? 1 : 0;
                }
            }
            $stats['failed'] += $isDeploy && $run['status'] === 'failed' ? 1 : 0;
            $stats['rollbacks'] += $run['type'] === 'rollback' && $run['status'] === 'done' ? 1 : 0;
        }

        $stats['recent'] = array_slice($runs, 0, 8);
        return $stats;
    }
}
