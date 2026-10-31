<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Runs (deploys, matches, rollbacks, compares) saved as storage/runs/<id>.json.
 * Backups of server files go to storage/backups/<id>/.
 */
final class RunStore
{
    /** Run types that change a server and so need the site to themselves. */
    public const CHANGING_TYPES = ['deploy', 'sync', 'rollback'];

    public static function dir(): string
    {
        return STORAGE_DIR . '/runs';
    }

    /** The local a run deploys from: the one recorded when it started, else the site's current one. */
    public static function source(array $run): array
    {
        $source = Sources::get((string) ($run['repo_source'] ?? ''));
        if ($source !== null) {
            return $source;
        }
        $site = Sites::get($run['site']) ?? throw new \RuntimeException('The site of this run no longer exists.');
        return Sites::rootSource($site);
    }

    public static function newId(string $type, string $siteKey): string
    {
        return date('Ymd-His') . '-' . $type . '-' . $siteKey . '-' . bin2hex(random_bytes(2));
    }

    public static function load(string $id): array
    {
        if (!preg_match('/^[\w-]+$/', $id) || !is_file(self::dir() . "/$id.json")) {
            throw new \RuntimeException('Run not found.');
        }
        return JsonStore::readFile(self::dir() . "/$id.json");
    }

    public static function save(array &$run): void
    {
        $run['updated_at'] = time();
        JsonStore::writeFile(self::dir() . "/{$run['id']}.json", $run);
    }

    /**
     * Runs started after $id that change $siteKey, newest first. Run ids hold their type and
     * site, so only those files are read.
     */
    public static function changingAfter(string $id, string $siteKey): array
    {
        $files = glob(self::dir() . '/*.json') ?: [];
        rsort($files);
        $runs = [];
        foreach ($files as $file) {
            $other = basename($file, '.json');
            if ($other <= $id) {
                break;
            }
            if (preg_match('/^\d{8}-\d{6}-([a-z]+)-(.+)-[0-9a-f]{4}$/', $other, $m)
                && ($m[2] !== $siteKey || !in_array($m[1], self::CHANGING_TYPES, true))) {
                continue;
            }
            $runs[] = JsonStore::readFile($file);
        }
        return $runs;
    }

    /** The newest run of one of $types on a site with one of $statuses; reads only that site's run files. */
    public static function latestFor(string $siteKey, array $types, array $statuses): ?array
    {
        $files = [];
        foreach ($types as $type) {
            array_push($files, ...(glob(self::dir() . "/*-$type-$siteKey-*.json") ?: []));
        }
        rsort($files);
        foreach ($files as $file) {
            $run = JsonStore::readFile($file);
            if (($run['site'] ?? null) === $siteKey && in_array($run['status'] ?? '', $statuses, true)) {
                return $run;
            }
        }
        return null;
    }

    /** All runs, newest first. */
    public static function all(): array
    {
        $files = glob(self::dir() . '/*.json') ?: [];
        rsort($files);
        return array_map([JsonStore::class, 'readFile'], $files);
    }

    /**
     * The run that has this site to itself, if any. A run holds its site while it is
     * running, waiting for review or SQL, or failed after it already changed the server.
     */
    public static function activeRunFor(string $siteKey): ?array
    {
        foreach (self::all() as $run) {
            if ($run['site'] === $siteKey && self::holdsSite($run)) {
                return $run;
            }
        }
        return null;
    }

    public static function holdsSite(array $run): bool
    {
        if (!in_array($run['type'], self::CHANGING_TYPES, true)) {
            return false;
        }
        return in_array($run['status'], ['running', 'review', 'sql'], true)
            || ($run['status'] === 'failed' && !empty($run['touched']));
    }

    /** Runs that are still open (shown on the dashboard). */
    public static function open(?array $runs = null): array
    {
        return array_values(array_filter($runs ?? self::all(), static fn (array $run): bool => self::holdsSite($run)
            || ($run['type'] === 'compare' && $run['status'] === 'running')));
    }
}
