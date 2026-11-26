<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Speed-up "Compare only some commits": what a partial "Make it match" sent to a site, so the
 * next normal deploy knows those files. A partial run leaves the site's recorded version as it
 * was (the site isn't on any one commit then), so without this the next deploy would see them
 * as "Edited on server". storage/partials.json: site => path => [git blob hash or null for a
 * deleted file, run id, time]. A deploy or match that records a version starts afresh.
 */
final class Partials
{
    /** After a partial run: the files it uploaded (with the hash they now have) and deleted. */
    public static function remember(string $site, array $run, Git $git): void
    {
        $all = JsonStore::read('partials');
        $uploads = array_column(array_filter($run['files'], static fn (array $f): bool => !empty($f['done']) && $f['action'] === 'upload'), 'path');
        $hashes = $uploads === [] ? [] : self::blobHashes($git, (string) $run['to'], $uploads);
        foreach ($run['files'] as $file) {
            if (empty($file['done'])) {
                continue;
            }
            $all[$site][$file['path']] = [$file['action'] === 'upload' ? ($hashes[$file['path']] ?? null) : null, $run['id'], time()];
        }
        JsonStore::write('partials', $all);
    }

    /** Whether a partial run left this file so: $sha the server copy's blob hash, null when it isn't there. */
    public static function expects(string $site, string $path, ?string $sha): bool
    {
        $entry = JsonStore::read('partials')[$site][$path] ?? null;
        return $entry !== null && $entry[0] === $sha;
    }

    /** @return array<string, array> what partial runs sent to a site, path => [hash|null, run id, time] */
    public static function of(string $site): array
    {
        return JsonStore::read('partials')[$site] ?? [];
    }

    /** A version was recorded (a full deploy or match): the site is on one commit again. */
    public static function clear(string $site): void
    {
        $all = JsonStore::read('partials');
        if (isset($all[$site])) {
            unset($all[$site]);
            JsonStore::write('partials', $all);
        }
    }

    /** A partial run was rolled back: its files are as before it again. */
    public static function forgetRun(string $site, string $runId): void
    {
        $all = JsonStore::read('partials');
        foreach ($all[$site] ?? [] as $path => $entry) {
            if ($entry[1] === $runId) {
                unset($all[$site][$path]);
            }
        }
        JsonStore::write('partials', $all);
    }

    /** @return array<string, string> path => git blob hash in $commit, from one git call */
    private static function blobHashes(Git $git, string $commit, array $paths): array
    {
        $hashes = [];
        foreach ($git->tree($commit) as $path => $file) {
            if (in_array((string) $path, $paths, true)) {
                $hashes[(string) $path] = $file['sha'];
            }
        }
        return $hashes;
    }
}
