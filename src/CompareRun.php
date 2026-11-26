<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Compares a whole server with a commit, for servers with no recorded version (like prod
 * after copy-paste deploys). Two ways to read an FTP server:
 *
 *  - 'fingerprint' (the default): upload a temporary PHP file that lists git's hash of
 *    every file, call it once, delete it. Exact, and takes seconds.
 *  - 'sizes': walk every folder over FTP (several at once) and compare file sizes. Uploads
 *    nothing, but can't spot an edit that keeps the size, and Windows line endings show as different.
 *
 * Folder sites are always fingerprinted directly, and SSH sites fingerprint themselves over SSH.
 */
final class CompareRun
{
    public const PHASES = [
        'walk' => 'Read the files',
        'remote' => 'Fingerprint the server over SSH',
        'helper_upload' => 'Upload the fingerprint file',
        'helper_call' => 'Fingerprint the server',
        'helper_delete' => 'Delete the fingerprint file',
        'scoped' => "Read the commits' files",
        'analyze' => 'Compare with the commit',
    ];

    /**
     * Speed-up "Compare only some commits": a compare of just the files the ticked commits
     * changed, read one by one (no helper file, no walk of the whole site). Compared with the
     * newest ticked commit; files it deleted show as extra, to be deleted if you tick them.
     */
    public static function createScoped(array $site, array $commits): array
    {
        if (!Settings::on('feature_commit_compare')) {
            throw new \RuntimeException('Comparing only some commits is switched off (Settings → Speed-ups).');
        }
        $git = Sites::git($site);
        $wanted = [];
        foreach (array_unique(array_filter($commits, 'is_string')) as $commit) {
            $sha = $git->resolve($commit);
            if ($sha === null) {
                throw new \RuntimeException('Commit ' . substr($commit, 0, 12) . ' is not in your repository.');
            }
            $wanted[$sha] = true;
        }
        if ($wanted === []) {
            throw new \RuntimeException('Tick at least one commit to compare.');
        }
        // Newest first, in history order (one git call over the commits and their history).
        $ordered = array_values(array_filter(explode("\n", trim($git->ok(array_merge(['log', '--format=%H', '--topo-order', '-n', '5000'], array_keys($wanted))))), static fn (string $sha): bool => isset($wanted[$sha])));
        $ordered = array_values(array_unique(array_merge($ordered, array_keys($wanted))));
        $paths = [];
        foreach ($ordered as $sha) {
            foreach (CommitFiles::filesOf($git, $sha) as [, $path]) {
                $paths[$path] = true;
            }
        }
        if ($paths === []) {
            throw new \RuntimeException('These commits change no files.');
        }
        if (count($paths) > 1000) {
            throw new \RuntimeException('These commits change ' . count($paths) . ' files: compare the whole site instead.');
        }
        ksort($paths);
        return self::create($site, $ordered[0], 'fingerprint', ['commits' => $ordered, 'paths' => array_map('strval', array_keys($paths))]);
    }

    public static function create(array $site, string $commit, string $method, ?array $scope = null): array
    {
        $method = $site['type'] === 'ftp' && $method === 'sizes' ? 'sizes' : 'fingerprint';
        $first = match (true) {
            $scope !== null => 'scoped',
            $site['type'] === 'ssh' => 'remote',
            $site['type'] === 'ftp' && $method === 'fingerprint' => 'helper_upload',
            default => 'walk',
        };
        $run = Runner::newRun('compare', $site, $first) + [
            'method' => $method,
            'site_type' => $site['type'],
            'queue' => [''],
            'server' => [],
            'dirs_scanned' => 0,
            'helper' => null,
            'result' => null,
            'recorded' => false,
            'finished_at' => null,
        ];
        $run['to'] = $commit;
        if ($scope !== null) {
            $run['scope'] = $scope + ['read' => 0];
        }
        RunStore::save($run);
        return $run;
    }

    /** The phases this compare goes through, for the progress list. */
    public static function phases(array $run): array
    {
        $keys = match (true) {
            isset($run['scope']) => ['scoped', 'analyze'],
            $run['site_type'] === 'ssh' => ['remote', 'analyze'],
            $run['site_type'] === 'ftp' && $run['method'] === 'fingerprint' => ['helper_upload', 'helper_call', 'helper_delete', 'analyze'],
            default => ['walk', 'analyze'],
        };
        return array_intersect_key(self::PHASES, array_flip($keys));
    }

    public static function advance(array &$run, RunContext $context, float $deadline): void
    {
        match ($run['phase']) {
            'walk' => self::walk($run, $context, $deadline),
            'remote' => self::remote($run, $context),
            'helper_upload' => self::helperUpload($run, $context),
            'helper_call' => self::helperCall($run, $context),
            'helper_delete' => self::helperDelete($run, $context),
            'scoped' => self::readScoped($run, $context, $deadline),
            'analyze' => self::analyze($run, $context),
        };
    }

    /** A compare of only some commits: reads just their files from the server, a batch at a time. */
    private static function readScoped(array &$run, RunContext $context, float $deadline): void
    {
        $regexes = $context->protectedRegexes();
        $transport = $context->transport();
        $paths = array_values(array_filter($run['scope']['paths'], static fn (string $p): bool => !ProtectedPaths::matches($p, $regexes)));
        while ($run['scope']['read'] < count($paths)) {
            if (microtime(true) >= $deadline) {
                return;
            }
            $batch = array_slice($paths, $run['scope']['read'], $transport->batchSize());
            foreach ($transport->getMany($batch) as $path => $contents) {
                if ($contents !== null) {
                    $normal = str_replace("\r\n", "\n", $contents);
                    $run['server'][(string) $path] = ['sha' => Fingerprint::blobSha($contents), 'nsha' => $normal !== $contents ? Fingerprint::blobSha($normal) : null, 'size' => strlen($contents)];
                }
            }
            $run['scope']['read'] += count($batch);
        }
        Runner::log($run, 'info', 'Read ' . count($paths) . ' file(s) of ' . count($run['scope']['commits']) . ' commit(s) on the server (' . count($run['server']) . ' there).');
        $run['phase'] = 'analyze';
    }

    /**
     * For a compare of only some commits: files the ticked commits change that other commits
     * also change, between the server's recorded version and the newest ticked one. Uploading
     * such a file brings those other commits' changes to it too.
     *
     * @return array{known: bool, paths?: array<string, list<string>>, commits?: array<string, string>}
     */
    private static function sharedChanges(Git $git, array $run, array $sendable): array
    {
        $base = $run['server_commit'] ?? null;
        if ($base === null || $git->resolve($base) === null || !$git->isAncestor($base, $run['to'])) {
            return ['known' => false]; // no recorded version, or not in this history: nothing to go by
        }
        $picked = array_flip($run['scope']['commits']);
        $scope = array_flip($sendable); // only files a match would send: one already the same doesn't matter
        $paths = [];
        $commits = [];
        foreach (CommitFiles::log($git, "$base..{$run['to']}", 2000) as $commit) {
            if (isset($picked[$commit['sha']])) {
                continue;
            }
            foreach ($commit['files'] as [, $path]) {
                if (isset($scope[$path])) {
                    $paths[$path][] = $commit['sha'];
                    $commits[$commit['sha']] = $commit['subject'];
                }
            }
        }
        ksort($paths);
        return ['known' => true, 'paths' => $paths, 'commits' => $commits];
    }

    /** The most commits kept in the "Not in your selection" list (the files are still counted). */
    public const OUTSIDE_COMMITS = 100;

    /**
     * Speed-up "Files outside the picked commits": the commits the server still lacks (from its
     * recorded version to the newest commit the site gets code from) that weren't picked, each
     * with its files that the compare didn't cover. From git alone: nothing is read from the server.
     *
     * @return array{known: bool, head?: string, files?: int, total?: int, commits?: list<array{sha: string, subject: string, author: string, date: string, files: list<array{0: string, 1: string}>}>}
     */
    private static function outsideSelection(Git $git, array $run, array $site, array $regexes): array
    {
        $base = $run['server_commit'] ?? null;
        $head = $run['to'];
        try {
            $newest = DeployRun::sourceCommit($site, $git);
            if ($newest !== $head && $git->isAncestor($head, $newest)) {
                $head = $newest;
            }
        } catch (\RuntimeException) {
            // the site's source is gone: the newest picked commit is as far as it goes
        }
        if ($base === null || $git->resolve($base) === null || !$git->isAncestor($base, $head)) {
            return ['known' => false];
        }
        $picked = array_flip($run['scope']['commits']);
        $covered = array_flip($run['scope']['paths']);
        $commits = [];
        $files = [];
        $total = 0;
        foreach (CommitFiles::log($git, "$base..$head", 5000) as $commit) {
            if (isset($picked[$commit['sha']])) {
                continue;
            }
            $left = array_values(array_filter($commit['files'], static fn (array $file): bool => !isset($covered[$file[1]]) && !ProtectedPaths::matches($file[1], $regexes)));
            if ($left === []) {
                continue; // all its files were compared (they show in the "also changed" warning)
            }
            $total++;
            foreach ($left as [, $path]) {
                $files[$path] = true;
            }
            if (count($commits) < self::OUTSIDE_COMMITS) {
                $commits[] = ['sha' => $commit['sha'], 'subject' => $commit['subject'], 'author' => $commit['author'], 'date' => $commit['date'], 'files' => $left];
            }
        }
        return ['known' => true, 'head' => $head, 'files' => count($files), 'total' => $total, 'commits' => $commits];
    }

    /**
     * Of $paths (files a match would send), the ones that also hold changes from commits
     * that weren't picked on a compare of only some commits: path => [commit sha, …].
     */
    public static function sharedIn(array $compare, array $paths): array
    {
        return array_intersect_key($compare['result']['shared']['paths'] ?? [], array_flip($paths));
    }

    /** Writes the compared commit to the server as its recorded version. */
    public static function recordVersion(array &$compare, bool $acknowledged): void
    {
        $site = Sites::get($compare['site']) ?? throw new \RuntimeException('This site no longer exists.');
        if ($compare['status'] !== 'done') {
            throw new \RuntimeException('This compare has not finished.');
        }
        if (isset($compare['scope'])) {
            throw new \RuntimeException('A compare of only some commits looked at a few files, so it can\'t say the server runs that commit.');
        }
        $issues = count($compare['result']['differs']) + count($compare['result']['missing']);
        if ($issues > 0 && !$acknowledged) {
            throw new \RuntimeException("Tick the box to confirm: $issues file(s) differ from the commit.");
        }
        if (RunStore::activeRunFor($site['key']) !== null) {
            throw new \RuntimeException("{$site['name']} has an open run. Finish it first.");
        }
        ServerState::write(Sites::transport($site), $compare['to'], $compare['id'], 'Recorded after a compare');
        $compare['recorded'] = true;
        Runner::log($compare, 'info', 'Recorded commit ' . short_sha($compare['to']) . " on the server ($issues difference(s) accepted).");
        RunStore::save($compare);
    }

    /**
     * "Make it match": a run that uploads the files the compare found different or missing
     * and, with the speed-up "Delete extra server files", deletes the ticked extra ones
     * ($deletes). $selected limits it to the paths ticked on the result (null = every
     * different or missing file); only paths the compare listed count, whatever the form
     * sends. After a compare of only some commits the run is partial: the site's recorded
     * version stays (DeployRun::record).
     */
    public static function createSync(array $compare, ?array $selected, bool $includeLineEndings = false, array $deletes = [], bool $sharedChecked = false): array
    {
        $site = Sites::get($compare['site']) ?? throw new \RuntimeException('This site no longer exists.');
        $deleting = self::deletesFor($compare, $site, $deletes);
        $wanted = $selected === null ? null : array_flip(array_map('strval', $selected));
        $pick = static fn (string $path, bool $byDefault): bool => $wanted === null ? $byDefault : isset($wanted[$path]);
        $files = [];
        foreach ($compare['result']['differs'] as $path) {
            if ($pick((string) $path, true)) {
                $files[] = ['path' => (string) $path, 'change' => 'changed'];
            }
        }
        foreach ($compare['result']['missing'] as $path) {
            if ($pick((string) $path, true)) {
                $files[] = ['path' => (string) $path, 'change' => 'new'];
            }
        }
        foreach ($compare['result']['eol'] as $path) {
            if ($pick((string) $path, $includeLineEndings)) {
                $files[] = ['path' => (string) $path, 'change' => 'changed'];
            }
        }
        foreach ($deleting as $path) {
            $files[] = ['path' => $path, 'change' => 'deleted'];
        }
        if ($files === [] && $selected !== null && ($compare['result']['differs'] || $compare['result']['missing'] || $compare['result']['eol'])) {
            throw new \RuntimeException('No file is ticked. Tick the files to upload, or use "Record version" instead.');
        }
        // Files that also hold changes from commits that weren't picked ("Compare only some commits").
        $shared = self::sharedIn($compare, array_column($files, 'path'));
        if ($shared !== [] && !$sharedChecked) {
            throw new \RuntimeException(count($shared) . ' file(s) also hold changes from commits you didn\'t pick. Tick the box to send them anyway, or include those commits.');
        }
        if ($files === []) {
            throw new \RuntimeException(isset($compare['scope']) ? 'Nothing to upload: the server already has these commits\' files.' : 'Nothing to upload: the server already matches. Use "Record version" instead.');
        }
        $run = DeployRun::create($site, 'sync', null, $files, $compare['to'], $compare['id']);
        if (isset($compare['scope'])) {
            $run['partial'] = ['commits' => $compare['scope']['commits']]; // the recorded version stays (DeployRun::record)
            RunStore::save($run);
        }
        return $run;
    }

    /**
     * The ticked extra files a match may delete: only files this compare found on the server
     * and not in the commit, never on production, and only with the speed-up switched on.
     * Protected paths are skipped later, like every run's files.
     *
     * @return string[]
     */
    public static function deletesFor(array $compare, array $site, array $deletes): array
    {
        $deletes = array_values(array_unique(array_filter($deletes, 'is_string')));
        if ($deletes === []) {
            return [];
        }
        if (!Settings::on('feature_delete_extras')) {
            throw new \RuntimeException('Deleting extra files is switched off (Settings → Speed-ups).');
        }
        if (!empty($site['production'])) {
            throw new \RuntimeException('Production never deletes files automatically. Delete them on the server yourself.');
        }
        $extra = array_flip(array_map('strval', $compare['result']['extra'] ?? []));
        $unknown = array_filter($deletes, static fn (string $path): bool => !isset($extra[$path]));
        if ($unknown !== []) {
            throw new \RuntimeException('Only files listed under "Extra on server" can be deleted: ' . implode(', ', array_slice($unknown, 0, 3)) . '.');
        }
        return $deletes;
    }

    /**
     * Reads a batch of folders at a time (FTP lists them side by side). Folder sites are
     * fingerprinted, FTP sites give sizes.
     */
    private static function walk(array &$run, RunContext $context, float $deadline): void
    {
        $regexes = $context->protectedRegexes();
        $transport = $context->transport();
        $fingerprint = $run['site_type'] === 'folder';
        while ($run['queue']) {
            if (microtime(true) >= $deadline) {
                return;
            }
            $dirs = array_map('strval', array_splice($run['queue'], 0, $transport->batchSize()));
            foreach ($transport->listDetailedMany($dirs) as $dir => $entries) {
                $dir = (string) $dir;
                foreach ($entries as $name => $entry) {
                    $path = $dir === '' ? (string) $name : $dir . '/' . $name;
                    if ($entry['dir']) {
                        if (!ProtectedPaths::matches($path . '/', $regexes)) {
                            $run['queue'][] = $path;
                        }
                    } elseif (!ProtectedPaths::matches($path, $regexes) && !str_ends_with($path, '.dtup')) {
                        $run['server'][$path] = $fingerprint ? Fingerprint::of((string) $transport->get($path)) : ['size' => $entry['size']];
                    }
                }
                $run['dirs_scanned']++;
            }
        }
        Runner::log($run, 'info', "Read {$run['dirs_scanned']} folder(s), " . count($run['server']) . ' file(s).');
        $run['phase'] = 'analyze';
    }

    /** SSH sites: the server fingerprints every file itself, in one call. */
    private static function remote(array &$run, RunContext $context): void
    {
        $transport = $context->transport();
        if (!$transport instanceof SshTransport) {
            throw new \RuntimeException('This site is no longer an SSH site. Start a new compare.');
        }
        $run['server'] = $transport->fingerprintAll($context->protectedRegexes());
        Runner::log($run, 'info', 'The server listed ' . count($run['server']) . ' file(s).');
        $run['phase'] = 'analyze';
    }

    private static function helperUpload(array &$run, RunContext $context): void
    {
        $token = bin2hex(random_bytes(24));
        $name = 'dt-fingerprint-' . bin2hex(random_bytes(8)) . '.php';
        $run['helper'] = ['name' => $name, 'token' => $token, 'error' => null, 'deleted' => false];
        $context->transport()->put($name, Fingerprint::helperScript(hash('sha256', $token), $context->protectedRegexes()));
        Runner::log($run, 'info', "Uploaded the temporary file $name.");
        $run['phase'] = 'helper_call';
    }

    private static function helperCall(array &$run, RunContext $context): void
    {
        try {
            $output = Fingerprint::callHelper($context->site['url'], $run['helper']['name'], $run['helper']['token']);
            $run['server'] = Fingerprint::parseHelperOutput($output);
            Runner::log($run, 'info', 'The server listed ' . count($run['server']) . ' file(s).');
        } catch (\RuntimeException $e) {
            $run['helper']['error'] = $e->getMessage(); // still delete the file first
        }
        $run['phase'] = 'helper_delete';
    }

    private static function helperDelete(array &$run, RunContext $context): void
    {
        $transport = $context->transport();
        $transport->delete($run['helper']['name']);
        if ($transport->get($run['helper']['name']) !== null) {
            throw new \RuntimeException("Could not delete {$run['helper']['name']}. Delete it over FTP (it also stops working after 15 minutes).");
        }
        $run['helper']['deleted'] = true;
        Runner::log($run, 'info', 'Deleted the temporary file.');
        if ($run['helper']['error'] !== null) {
            $run['status'] = 'failed';
            $run['error'] = $run['helper']['error'];
            Runner::log($run, 'error', $run['error']);
            return;
        }
        $run['phase'] = 'analyze';
    }

    private static function analyze(array &$run, RunContext $context): void
    {
        // Speed-up "Files of each commit": the version the server says it runs, so the compare
        // page can list the commits between it and the compared one. Only a note: never fails.
        if ((Settings::on('feature_commit_files') || isset($run['scope'])) && !array_key_exists('server_commit', $run)) {
            try {
                $run['server_commit'] = ServerState::parse(ServerState::readRaw($context->transport()))['commit'] ?? null;
            } catch (\Throwable) {
                $run['server_commit'] = null;
            }
        }
        $regexes = $context->protectedRegexes();
        $tree = array_filter($context->git()->tree($run['to']), static fn ($path): bool => !ProtectedPaths::matches((string) $path, $regexes), ARRAY_FILTER_USE_KEY);
        if (isset($run['scope'])) {
            $tree = array_intersect_key($tree, array_flip($run['scope']['paths'])); // only the ticked commits' files
        }
        $server = $run['server'];
        $result = ['method' => $run['method'], 'same' => 0, 'eol' => [], 'differs' => [], 'missing' => [], 'extra' => [], 'tree_files' => count($tree), 'server_files' => count($server)];

        foreach ($tree as $path => $file) {
            $path = (string) $path;
            $copy = $server[$path] ?? null;
            if ($copy === null) {
                $result['missing'][] = $path;
            } elseif ($run['method'] === 'fingerprint') {
                if ($copy['sha'] === $file['sha']) {
                    $result['same']++;
                } elseif (($copy['nsha'] ?? null) === $file['sha']) {
                    $result['eol'][] = $path;
                } else {
                    $result['differs'][] = $path;
                }
            } elseif ($copy['size'] === $file['size']) {
                $result['same']++;
            } else {
                $result['differs'][] = $path;
            }
        }
        foreach (array_keys($server) as $path) {
            if (!isset($tree[(string) $path])) {
                $result['extra'][] = (string) $path;
            }
        }
        foreach (['eol', 'differs', 'missing', 'extra'] as $list) {
            sort($result[$list]);
        }
        if (isset($run['scope'])) {
            $result['shared'] = self::sharedChanges($context->git(), $run, array_merge($result['differs'], $result['missing'], $result['eol']));
            if (Settings::on('feature_outside_selection')) {
                $result['outside'] = self::outsideSelection($context->git(), $run, $context->site, $regexes);
            }
        }

        $run['result'] = $result;
        $run['server'] = [];
        $run['status'] = 'done';
        $run['phase'] = 'done';
        $run['finished_at'] = time();
        Runner::log($run, 'info', sprintf(
            'Same: %d. Line endings only: %d. Different: %d. Missing on server: %d. Extra on server: %d.',
            $result['same'], count($result['eol']), count($result['differs']), count($result['missing']), count($result['extra'])
        ));
    }
}
