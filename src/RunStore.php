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
    public static function open(): array
    {
        return array_values(array_filter(self::all(), static fn (array $run): bool => self::holdsSite($run)
            || ($run['type'] === 'compare' && $run['status'] === 'running')));
    }
}
