<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Speed-up "Watch servers for changes": every few hours (Scheduler) each site's files are
 * fingerprinted, the same way a compare reads them, and compared with the last time. Files
 * changed, added or removed since then that no deploy, match or rollback of the tool touched
 * are reported (a hotfix on the server, or a hack), and the top folder's build and config
 * files are scanned for the npm malware's markers. Findings go to Alerts and the site's card.
 *
 * Snapshots: storage/watch/<site>.json (path => git blob hash). Results: storage/watch.json.
 */
final class ServerWatch
{
    /** Changes listed per kind; the rest are counted. */
    private const LIST_LIMIT = 200;

    public static function hours(): int
    {
        return max(1, min(48, (int) Settings::get('watch_hours')));
    }

    /** @return array<string, array> site key => the last result */
    public static function results(): array
    {
        return JsonStore::read('watch');
    }

    public static function result(string $site): ?array
    {
        return self::results()[$site] ?? null;
    }

    /** Whether a site's next check is due. */
    public static function due(array $site): bool
    {
        $last = (int) (self::result($site['key'])['checked_at'] ?? 0);
        return time() - $last >= self::hours() * 3600;
    }

    /** "Mark as seen" on the watch page: the findings stay listed but no longer flag the site. */
    public static function markSeen(string $site): void
    {
        $results = self::results();
        if (isset($results[$site])) {
            $results[$site]['seen'] = true;
            JsonStore::write('watch', $results);
        }
    }

    /**
     * Checks one site now: snapshot, compare with the last one, scan for malware markers, save,
     * and alert when something new was found. A site with an open run is skipped.
     */
    public static function check(array $site): array
    {
        $results = self::results();
        $previous = $results[$site['key']] ?? null;
        if (RunStore::activeRunFor($site['key']) !== null) {
            return $previous ?? ['checked_at' => 0];
        }
        $now = time();
        $result = ['checked_at' => $now, 'error' => null, 'baseline' => false, 'added' => [], 'changed' => [], 'removed' => [], 'counts' => [0, 0, 0], 'malware' => [],
            'seen' => $previous['seen'] ?? true, 'found_at' => $previous['found_at'] ?? null, 'files' => 0];
        try {
            $files = self::snapshot($site);
            $result['files'] = count($files);
            $before = JsonStore::readFile(self::snapshotFile($site['key']));
            if (!isset($before['files'])) {
                $result['baseline'] = true; // first look: nothing to compare with yet
            } else {
                $expected = self::touchedByTool($site['key'], (int) $before['taken_at']);
                $old = $before['files'];
                $kinds = ['added' => [], 'changed' => [], 'removed' => []];
                foreach ($files as $path => $sha) {
                    if (!isset($old[$path])) {
                        $kinds['added'][] = (string) $path;
                    } elseif ($old[$path] !== $sha) {
                        $kinds['changed'][] = (string) $path;
                    }
                }
                foreach (array_diff_key($old, $files) as $path => $sha) {
                    $kinds['removed'][] = (string) $path;
                }
                foreach ($kinds as $kind => $paths) {
                    $paths = array_values(array_filter($paths, static fn (string $p): bool => !isset($expected[$p])));
                    $result['counts'][array_search($kind, array_keys($kinds), true)] = count($paths);
                    $result[$kind] = array_slice($paths, 0, self::LIST_LIMIT);
                }
            }
            JsonStore::writeFile(self::snapshotFile($site['key']), ['taken_at' => $now, 'files' => $files], false);
            $suspect = array_values(array_filter(array_keys($files), static fn ($p): bool => Safety::isSuspectPath((string) $p)));
            $result['malware'] = $suspect === [] ? [] : Safety::scanContents(Sites::transport($site)->getMany($suspect));
        } catch (\RuntimeException $e) {
            $result['error'] = $e->getMessage();
            $result['seen'] = $previous['seen'] ?? true;
            $results[$site['key']] = $result + ($previous ?? []);
            JsonStore::write('watch', $results);
            return $results[$site['key']];
        }

        $changed = array_sum($result['counts']);
        if ($changed > 0 || $result['malware'] !== []) {
            $result['seen'] = false;
            $result['found_at'] = $now;
            self::alert($site, $result, (int) ($before['taken_at'] ?? $now));
        }
        $results[$site['key']] = $result;
        JsonStore::write('watch', $results);
        return $result;
    }

    /**
     * Every file on the server as path => git blob hash, protected paths left out: over SSH in
     * one call, on FTP with the compare's temporary helper file, in a folder directly.
     *
     * @return array<string, string>
     */
    public static function snapshot(array $site): array
    {
        $regexes = ProtectedPaths::regexes(Sites::rootSource($site));
        $transport = Sites::transport($site);
        if ($transport instanceof SshTransport) {
            return array_map(static fn (array $f): string => (string) $f['sha'], $transport->fingerprintAll($regexes));
        }
        if ($site['type'] === 'folder') {
            return self::folderFiles((string) $site['path'], $regexes);
        }
        if (trim((string) $site['url']) === '') {
            throw new \RuntimeException('The server watch needs the site address (on the site\'s page) to read an FTP site.');
        }
        $token = bin2hex(random_bytes(24));
        $name = 'dt-fingerprint-' . bin2hex(random_bytes(8)) . '.php';
        $transport->put($name, Fingerprint::helperScript(hash('sha256', $token), $regexes));
        try {
            $server = Fingerprint::parseHelperOutput(Fingerprint::callHelper($site['url'], $name, $token));
        } finally {
            $transport->delete($name);
        }
        return array_map(static fn (array $f): string => (string) $f['sha'], $server);
    }

    /** @return array<string, string> */
    private static function folderFiles(string $root, array $regexes): array
    {
        $files = [];
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $items = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $item) use ($root, $regexes): bool {
                $path = substr(str_replace('\\', '/', $item->getPathname()), strlen($root) + 1);
                return !ProtectedPaths::matches($item->isDir() ? $path . '/' : $path, $regexes);
            }
        ));
        foreach ($items as $item) {
            if ($item->isFile() && !$item->isLink()) {
                $path = substr(str_replace('\\', '/', $item->getPathname()), strlen($root) + 1);
                $files[$path] = Fingerprint::blobSha((string) file_get_contents($item->getPathname()));
            }
        }
        ksort($files);
        return $files;
    }

    /** Files the tool's own deploys, matches and rollbacks wrote or deleted since $since. */
    private static function touchedByTool(string $site, int $since): array
    {
        $paths = [];
        foreach (RunStore::summaries() as $summary) {
            if ($summary['site'] !== $site || !in_array($summary['type'], ['deploy', 'sync', 'rollback'], true) || (int) $summary['created_at'] < $since - 3600) {
                continue;
            }
            $run = RunStore::load($summary['id']);
            if (empty($run['touched'])) {
                continue;
            }
            foreach ($run['files'] as $file) {
                $paths[$file['path']] = true;
            }
            $paths[ServerState::PATH] = true; // the recorded version
            if (!empty($run['helper']['name'])) {
                $paths[$run['helper']['name']] = true; // a one-go upload's helper
            }
        }
        return $paths;
    }

    private static function alert(array $site, array $result, int $since): void
    {
        $lines = [];
        $changed = array_sum($result['counts']);
        if ($changed > 0) {
            $names = array_slice(array_merge($result['added'], $result['changed'], $result['removed']), 0, 6);
            $lines[] = "⚠️ Server watch · **{$site['name']}**: $changed file(s) changed on the server since " . date('d M H:i', $since)
                . ' that no deploy of the tool made (' . implode(', ', array_map(static fn (string $p): string => "`$p`", $names)) . ($changed > 6 ? ', …' : '') . ').';
        }
        if ($result['malware'] !== []) {
            $lines[] = "🦠 Malware markers on **{$site['name']}**: " . implode('; ', $result['malware']) . '.';
        }
        $lines[] = 'Open the tool\'s dashboard → the site\'s card → Server watch for the list.';
        Alerts::send(implode("\n", $lines));
    }

    private static function snapshotFile(string $site): string
    {
        return STORAGE_DIR . '/watch/' . $site . '.json';
    }
}
