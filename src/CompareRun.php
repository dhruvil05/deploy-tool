<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Compares a whole server with a commit, for servers with no recorded version (like prod
 * after copy-paste deploys). Two ways to read an FTP server:
 *
 *  - 'sizes': walk every folder over FTP and compare file sizes. Uploads nothing,
 *    but can't spot an edit that keeps the size, and Windows line endings show as different.
 *  - 'fingerprint': upload a temporary PHP file that lists git's hash of every file,
 *    call it once, delete it. Exact, and takes seconds.
 *
 * Folder sites are always fingerprinted directly.
 */
final class CompareRun
{
    public const PHASES = [
        'walk' => 'Read the files',
        'helper_upload' => 'Upload the fingerprint file',
        'helper_call' => 'Fingerprint the server',
        'helper_delete' => 'Delete the fingerprint file',
        'analyze' => 'Compare with the commit',
    ];

    public static function create(array $site, string $commit, string $method): array
    {
        $method = $site['type'] === 'folder' ? 'fingerprint' : ($method === 'fingerprint' ? 'fingerprint' : 'sizes');
        $first = $site['type'] === 'ftp' && $method === 'fingerprint' ? 'helper_upload' : 'walk';
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
        RunStore::save($run);
        return $run;
    }

    /** The phases this compare goes through, for the progress list. */
    public static function phases(array $run): array
    {
        $keys = $run['site_type'] === 'ftp' && $run['method'] === 'fingerprint'
            ? ['helper_upload', 'helper_call', 'helper_delete', 'analyze']
            : ['walk', 'analyze'];
        return array_intersect_key(self::PHASES, array_flip($keys));
    }

    public static function advance(array &$run, RunContext $context, float $deadline): void
    {
        match ($run['phase']) {
            'walk' => self::walk($run, $context, $deadline),
            'helper_upload' => self::helperUpload($run, $context),
            'helper_call' => self::helperCall($run, $context),
            'helper_delete' => self::helperDelete($run, $context),
            'analyze' => self::analyze($run, $context),
        };
    }

    /** Writes the compared commit to the server as its recorded version. */
    public static function recordVersion(array &$compare, bool $acknowledged): void
    {
        $site = Sites::get($compare['site']) ?? throw new \RuntimeException('This site no longer exists.');
        if ($compare['status'] !== 'done') {
            throw new \RuntimeException('This compare has not finished.');
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

    /** A "match" run that uploads every file the compare found different or missing. */
    public static function createSync(array $compare, bool $includeLineEndings): array
    {
        $site = Sites::get($compare['site']) ?? throw new \RuntimeException('This site no longer exists.');
        $files = [];
        foreach ($compare['result']['differs'] as $path) {
            $files[] = ['path' => $path, 'change' => 'changed'];
        }
        foreach ($compare['result']['missing'] as $path) {
            $files[] = ['path' => $path, 'change' => 'new'];
        }
        if ($includeLineEndings) {
            foreach ($compare['result']['eol'] as $path) {
                $files[] = ['path' => $path, 'change' => 'changed'];
            }
        }
        if ($files === []) {
            throw new \RuntimeException('Nothing to upload: the server already matches. Use "Record version" instead.');
        }
        return DeployRun::create($site, 'sync', null, $files, $compare['to'], $compare['id']);
    }

    /** Reads folder by folder, a few seconds per step. Folder sites are fingerprinted, FTP sites give sizes. */
    private static function walk(array &$run, RunContext $context, float $deadline): void
    {
        $regexes = $context->protectedRegexes();
        $transport = $context->transport();
        $fingerprint = $run['site_type'] === 'folder';
        while ($run['queue']) {
            if (microtime(true) >= $deadline) {
                return;
            }
            $dir = (string) array_shift($run['queue']);
            foreach ($transport->listDetailed($dir) as $name => $entry) {
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
        Runner::log($run, 'info', "Read {$run['dirs_scanned']} folder(s), " . count($run['server']) . ' file(s).');
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
        $regexes = $context->protectedRegexes();
        $tree = array_filter($context->git()->tree($run['to']), static fn ($path): bool => !ProtectedPaths::matches((string) $path, $regexes), ARRAY_FILTER_USE_KEY);
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
