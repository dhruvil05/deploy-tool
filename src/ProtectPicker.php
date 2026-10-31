<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Helps choose protected paths without typing them: the repository's folders with file
 * counts, how much each rule covers, and folders that only exist on a server (found by
 * compares). Used by the local form's folder picker and the compare result.
 */
final class ProtectPicker
{
    /** Folder names that are usually generated or server data, offered as suggestions. */
    private const SUGGESTED_NAMES = ['vendor', 'node_modules', 'cache', 'logs', 'tmp', 'sessions', 'storage', 'bower_components'];

    /** Everything the picker on the local form needs, for a repository, branch and the rules typed so far. */
    public static function info(array $source, ?string $localKey): array
    {
        $git = Sources::git($source);
        if (!$git->isRepo()) {
            throw new \RuntimeException('The repository folder is not a git repository.');
        }
        $ref = $git->resolve($source['branch']) ?? $git->resolve('HEAD') ?? throw new \RuntimeException('The repository has no commits.');
        $paths = self::paths($git, $ref);
        $folders = self::folders($paths);
        $server = $localKey !== null ? self::serverOnlyForLocal($localKey) : [];
        $serverPaths = [];
        foreach ($server as $entry) {
            $serverPaths = array_merge($serverPaths, $entry['paths']);
        }

        $regexes = ProtectedPaths::regexes($source);
        $suggestions = [];
        foreach (array_keys($folders) as $folder) {
            $folder = (string) $folder;
            $insideSuggested = array_filter($suggestions, static fn (string $parent): bool => str_starts_with($folder, $parent));
            if (!$insideSuggested && in_array(strtolower(basename($folder)), self::SUGGESTED_NAMES, true) && !ProtectedPaths::matches($folder . '/', $regexes)) {
                $suggestions[] = $folder . '/';
            }
        }

        // Per rule: files it covers in git and on servers, and a fix when it covers nothing
        // but would as a folder (a missing "/" is the usual mistake).
        $rules = [];
        $global = ProtectedPaths::global();
        foreach (ProtectedPaths::rules($source) as $rule) {
            if (in_array($rule, $global, true)) {
                continue;
            }
            $inGit = self::countMatches($rule, $paths);
            $onServer = self::countMatches($rule, $serverPaths);
            $fix = null;
            if ($inGit + $onServer === 0 && !str_ends_with($rule, '/')
                && self::countMatches($rule . '/', $paths) + self::countMatches($rule . '/', $serverPaths) > 0) {
                $fix = $rule . '/';
            }
            $rules[] = [$rule, $inGit, $onServer, $fix];
        }

        return [
            'ok' => true,
            'ref' => short_sha($ref),
            'builtIn' => $global,
            'folders' => array_map(null, array_keys($folders), array_values($folders)),
            'server' => array_map(static fn (array $entry): array => [$entry['folder'], count($entry['paths']), $entry['sites']], $server),
            'suggestions' => array_slice($suggestions, 0, 20),
            'rules' => $rules,
        ];
    }

    /**
     * Groups paths that are on a server but not in git by the highest folder git doesn't
     * have (e.g. modules/backup/), so one rule covers them. Paths whose folder git does
     * have stay single files.
     *
     * @return array<string, string[]> rule (folder with "/", or file) => paths it covers
     */
    public static function groupServerOnly(array $extraPaths, array $gitFolders): array
    {
        $groups = [];
        foreach ($extraPaths as $path) {
            $parts = explode('/', (string) $path);
            $rule = (string) $path;
            $prefix = '';
            for ($i = 0; $i < count($parts) - 1; $i++) {
                $prefix .= ($i > 0 ? '/' : '') . $parts[$i];
                if (!isset($gitFolders[$prefix])) {
                    $rule = $prefix . '/';
                    break;
                }
            }
            $groups[$rule][] = (string) $path;
        }
        uasort($groups, static fn (array $a, array $b): int => count($b) <=> count($a));
        return $groups;
    }

    /** Folders of a commit as folder => number of files under it (at any depth). */
    public static function foldersAt(Git $git, string $ref): array
    {
        // A commit's tree never changes, so its folder list is kept per commit.
        $cache = preg_match('/^[0-9a-f]{40}$/', $ref) ? STORAGE_DIR . "/cache/folders-$ref.json" : null;
        if ($cache !== null && is_file($cache)) {
            return JsonStore::readFile($cache);
        }
        $folders = self::folders(self::paths($git, $ref));
        if ($cache !== null) {
            JsonStore::writeFile($cache, $folders);
        }
        return $folders;
    }

    /**
     * Server-only folders found by the latest finished compare of each site that gets its
     * code from this local.
     *
     * @return array<int, array{folder: string, paths: string[], sites: string[]}>
     */
    private static function serverOnlyForLocal(string $localKey): array
    {
        $latest = [];
        foreach (RunStore::all() as $run) { // newest first
            if ($run['type'] === 'compare' && $run['status'] === 'done' && ($run['repo_source'] ?? '') === $localKey
                && !isset($latest[$run['site']]) && Sites::get($run['site']) !== null) {
                $latest[$run['site']] = $run;
            }
        }
        $source = Sources::get($localKey);
        if ($source === null || $latest === []) {
            return [];
        }
        $git = Sources::git($source);
        $merged = [];
        foreach ($latest as $run) {
            if ($git->resolve($run['to']) === null) {
                continue;
            }
            foreach (self::groupServerOnly($run['result']['extra'], self::foldersAt($git, $run['to'])) as $rule => $paths) {
                $merged[$rule]['paths'] = array_values(array_unique(array_merge($merged[$rule]['paths'] ?? [], $paths)));
                $merged[$rule]['sites'][] = $run['site_name'];
            }
        }
        $list = [];
        foreach ($merged as $rule => $entry) {
            $list[] = ['folder' => (string) $rule, 'paths' => $entry['paths'], 'sites' => array_values(array_unique($entry['sites']))];
        }
        usort($list, static fn (array $a, array $b): int => count($b['paths']) <=> count($a['paths']));
        return array_slice($list, 0, 100);
    }

    /** @return string[] every file in a commit */
    private static function paths(Git $git, string $ref): array
    {
        return array_values(array_filter(explode("\0", $git->okRaw(['ls-tree', '-r', '--name-only', '-z', '--full-tree', $ref])), 'strlen'));
    }

    /** @return array<string, int> folder => files under it, sorted by path */
    private static function folders(array $paths): array
    {
        $folders = [];
        foreach ($paths as $path) {
            $parts = explode('/', $path);
            $prefix = '';
            for ($i = 0; $i < count($parts) - 1; $i++) {
                $prefix .= ($i > 0 ? '/' : '') . $parts[$i];
                $folders[$prefix] = ($folders[$prefix] ?? 0) + 1;
            }
        }
        ksort($folders, SORT_STRING);
        return $folders;
    }

    private static function countMatches(string $rule, array $paths): int
    {
        return count(preg_grep(ProtectedPaths::regex($rule), $paths));
    }
}
