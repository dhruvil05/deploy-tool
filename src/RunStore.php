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
        $file = self::dir() . "/{$run['id']}.json";
        $changing = in_array($run['type'], self::CHANGING_TYPES, true);
        $before = $changing && is_file($file) ? (JsonStore::readFile($file)['status'] ?? null) : null;
        JsonStore::writeFile($file, $run);
        if ($changing) {
            try {
                Notify::runSaved($before, $run); // speed-up "Messages to Slack, Teams or Discord"
            } catch (\Throwable) {
                // a message that can't go out never stops a run; Settings shows the last error
            }
        }
    }

    /**
     * Every run, newest first, without the heavy parts lists and pages don't need: no log,
     * files cut to what counts need (action, done), compare lists to their sizes. With the
     * speed-up "Fast history" they come from an index in cache/, refreshed for each run file
     * that changed since; runs still in progress are always read fresh. Off: all().
     */
    /** Changes whenever slim() stores runs differently, so an older index is rebuilt. */
    private const INDEX_FORMAT = 2;

    public static function summaries(): array
    {
        if (!Settings::on('feature_run_index')) {
            return self::all();
        }
        $indexFile = STORAGE_DIR . '/cache/runs-index.json';
        $read = is_file($indexFile) ? json_decode((string) @file_get_contents($indexFile), true) : null;
        // Damaged, or written by an older version of the tool: rebuilt below.
        $index = is_array($read) && ($read['format'] ?? null) === self::INDEX_FORMAT ? (array) ($read['runs'] ?? []) : [];
        $files = glob(self::dir() . '/*.json') ?: [];
        rsort($files);
        $runs = [];
        $fresh = [];
        $changed = false;
        foreach ($files as $file) {
            $id = basename($file, '.json');
            $stamp = (int) @filemtime($file) . ':' . (int) @filesize($file);
            $entry = $index[$id] ?? null;
            if ($entry === null || $entry['stamp'] !== $stamp || self::holdsSite($entry['run']) || $entry['run']['status'] === 'running') {
                $entry = ['stamp' => $stamp, 'run' => self::slim(JsonStore::readFile($file))];
                $changed = $changed || ($index[$id] ?? null) != $entry;
            }
            $fresh[$id] = $entry;
            $runs[] = self::expand($entry['run']);
        }
        if ($changed || count($fresh) !== count($index)) {
            // Compact (no pretty-printing): it's read on every dashboard and History page.
            $json = json_encode(['format' => self::INDEX_FORMAT, 'runs' => $fresh], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json !== false && (is_dir(dirname($indexFile)) || @mkdir(dirname($indexFile), 0700, true))
                && @file_put_contents($indexFile . '.tmp', $json, LOCK_EX) !== false) {
                @rename($indexFile . '.tmp', $indexFile); // only a cache: if this fails, the next page tries again
            }
        }
        return $runs;
    }

    /**
     * A run without what summaries leave out: the log, a compare's server listing and queue,
     * and the long lists (files cut to action and done; skipped and compare lists to counts).
     */
    private static function slim(array $run): array
    {
        unset($run['log'], $run['server'], $run['queue']);
        $run['files'] = array_map(static fn (array $f): array => [$f['action'] ?? '', !empty($f['done']) ? 1 : 0], $run['files'] ?? []);
        $run['skipped'] = count($run['skipped'] ?? []);
        if (isset($run['result']) && is_array($run['result'])) {
            foreach (['differs', 'missing', 'extra', 'eol'] as $list) {
                $run['result'][$list] = count($run['result'][$list] ?? []);
            }
        }
        return $run;
    }

    /** A slim run back in the shapes pages expect (lists of the right length). */
    private static function expand(array $run): array
    {
        $run['files'] = array_map(static fn (array $f): array => ['action' => $f[0], 'done' => $f[1] === 1], $run['files']);
        $run['skipped'] = array_fill(0, (int) $run['skipped'], ['path' => '', 'change' => '']);
        if (isset($run['result']) && is_array($run['result'])) {
            foreach (['differs', 'missing', 'extra', 'eol'] as $list) {
                $run['result'][$list] = array_fill(0, (int) ($run['result'][$list] ?? 0), '');
            }
        }
        return $run;
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
        foreach (self::summaries() as $run) {
            if ($run['site'] === $siteKey && self::holdsSite($run)) {
                return self::load($run['id']); // the whole run, for callers that need more
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
        return array_values(array_filter($runs ?? self::summaries(), static fn (array $run): bool => self::holdsSite($run)
            || ($run['type'] === 'compare' && $run['status'] === 'running')));
    }
}
