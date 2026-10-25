<?php

declare(strict_types=1);

namespace DeployTool;

/** Labels and progress numbers for showing runs. */
final class RunView
{
    public const TYPES = [
        'deploy' => 'Deploy',
        'sync' => 'Match to commit',
        'rollback' => 'Rollback',
        'compare' => 'Compare',
    ];

    /** status => [label, badge style] */
    public const STATUSES = [
        'running' => ['Running', 'info'],
        'review' => ['Waiting for your review', 'warning'],
        'sql' => ['Waiting for SQL', 'warning'],
        'done' => ['Done', 'success'],
        'failed' => ['Failed', 'danger'],
        'cancelled' => ['Cancelled', 'neutral'],
        'abandoned' => ['Abandoned', 'neutral'],
        'rolled_back' => ['Rolled back', 'neutral'],
    ];

    public static function phases(array $run): array
    {
        return match ($run['type']) {
            'rollback' => RollbackRun::PHASES,
            'compare' => CompareRun::phases($run),
            default => DeployRun::PHASES,
        };
    }

    /** @return array{0: int, 1: int} done and total for a phase */
    public static function progress(array $run, ?string $phase = null): array
    {
        $phase ??= $run['phase'];
        $files = $run['files'] ?? [];
        $count = static fn (callable $filter): int => count(array_filter($files, $filter));
        return match ($phase) {
            'lint' => [
                $count(static fn (array $f): bool => $f['action'] === 'upload' && !empty($f['linted'])),
                $count(static fn (array $f): bool => $f['action'] === 'upload'),
            ],
            'download' => [$count(static fn (array $f): bool => !empty($f['downloaded'])), count($files)],
            'upload' => [
                $count(static fn (array $f): bool => $f['action'] === 'upload' && $f['done']),
                $count(static fn (array $f): bool => $f['action'] === 'upload'),
            ],
            'delete' => [
                $count(static fn (array $f): bool => $f['action'] === 'delete' && $f['done']),
                $count(static fn (array $f): bool => $f['action'] === 'delete'),
            ],
            'restore' => [$count(static fn (array $f): bool => $f['done']), count($files)],
            'verify' => [
                $count(static fn (array $f): bool => $f['verified']),
                $count(static fn (array $f): bool => $f['done'] || $f['verified']),
            ],
            'walk' => [$run['dirs_scanned'] ?? 0, ($run['dirs_scanned'] ?? 0) + count($run['queue'] ?? [])],
            default => [0, 0],
        };
    }

    /** What the run page's script needs after each step. */
    public static function live(array $run): array
    {
        [$done, $total] = self::progress($run);
        return [
            'status' => $run['status'],
            'phase' => $run['phase'],
            'phase_label' => self::phases($run)[$run['phase']] ?? $run['phase'],
            'done' => $done,
            'total' => $total,
            'log' => array_slice($run['log'], -12),
            'busy' => !empty($run['busy']),
        ];
    }

    public static function title(array $run): string
    {
        return self::TYPES[$run['type']] . ' · ' . $run['site_name'];
    }

    /** Badge for a file's server copy in a review list. */
    public static function serverBadge(array $file): array
    {
        if ($file['action'] === 'delete' && $file['server'] === 'not_found') {
            return ['Already gone', 'neutral'];
        }
        return DeployRun::SERVER_STATES[$file['server']] ?? ['', 'neutral'];
    }
}
