<?php

declare(strict_types=1);

use DeployTool\JsonStore;

/** A small test runner: test() registers, check() asserts, serve() starts a local web server. */
final class Tests
{
    public static string $work = '';
    private static array $tests = [];
    private static array $servers = [];
    private static int $checks = 0;
    private static array $failures = [];

    public static function add(string $name, callable $test): void
    {
        self::$tests[$name] = $test;
    }

    public static function check(bool $ok, string $what): void
    {
        self::$checks++;
        if (!$ok) {
            throw new \AssertionError($what);
        }
    }

    public static function run(string $filter): int
    {
        $started = microtime(true);
        $ran = 0;
        foreach (self::$tests as $name => $test) {
            if ($filter !== '' && stripos($name, $filter) === false) {
                continue;
            }
            $ran++;
            self::resetStorage();
            try {
                $test();
                self::say("  ok    $name");
            } catch (\Throwable $e) {
                self::$failures[] = $name;
                self::say("  FAIL  $name\n        " . $e->getMessage() . ($e instanceof \AssertionError ? '' : ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'));
            }
            // Anything the tested code printed (a PHP warning, say) is shown under its test.
            $printed = trim((string) ob_get_clean());
            if ($printed !== '') {
                self::say("        printed: " . str_replace("\n", "\n                 ", $printed));
            }
            ob_start();
        }
        self::stopServers();
        self::remove(self::$work);
        self::say(sprintf("\n%d test(s), %d check(s), %d failed, %.1f s", $ran, self::$checks, count(self::$failures), microtime(true) - $started));
        return self::$failures === [] ? 0 : 1;
    }

    /**
     * Results go straight to the terminal; PHP's own output is buffered, so the tested code
     * runs as in a web request (headers not sent yet, so sign-in can renew the session).
     */
    private static function say(string $line): void
    {
        fwrite(STDOUT, $line . "\n");
    }

    /** Empties the throwaway data folder between tests, so each starts fresh. */
    private static function resetStorage(): void
    {
        self::remove(STORAGE_DIR);
        @mkdir(STORAGE_DIR, 0700, true);
        JsonStore::write('settings', ['deployer_name' => 'Tester']);
    }

    /** A new empty folder for one test. */
    public static function folder(string $name): string
    {
        $dir = self::$work . '/' . $name . '-' . bin2hex(random_bytes(3));
        mkdir($dir, 0777, true);
        return str_replace('\\', '/', $dir);
    }

    /** Starts "php -S" on a free port (docroot, optional router script) and returns its URL. */
    public static function serve(string $docroot, ?string $router = null): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $command = array_merge([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $docroot], $router !== null ? [$router] : []);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        self::$servers[] = $process;
        for ($try = 0; $try < 50; $try++) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if ($connection) {
                fclose($connection);
                return "http://127.0.0.1:$port";
            }
            usleep(100_000);
        }
        throw new \RuntimeException('The test server did not start.');
    }

    /** A git repository in a new folder, with the given commits: list of [message, [path => contents|null]]. */
    public static function repo(array $commits): string
    {
        $dir = self::folder('repo');
        $git = static function (array $args) use ($dir): string {
            $git = new DeployTool\Git($dir, DeployTool\Settings::gitBinary());
            return $git->okRaw($args);
        };
        $git(['init', '-q', '-b', 'main']);
        $git(['config', 'user.email', 'tests@example.invalid']);
        $git(['config', 'user.name', 'Tests']);
        $git(['config', 'core.autocrlf', 'false']);
        foreach ($commits as [$message, $files]) {
            foreach ($files as $path => $contents) {
                $full = "$dir/$path";
                if ($contents === null) {
                    @unlink($full);
                    continue;
                }
                @mkdir(dirname($full), 0777, true);
                file_put_contents($full, $contents);
            }
            $git(['add', '-A']);
            $git(['commit', '-q', '-m', $message]);
        }
        return $dir;
    }

    /**
     * A deploy run waiting for review, from $from to $to in $repo, saved in the throwaway data
     * folder with its local ("repo") and a folder site ("site"). Every changed file is an upload
     * (a delete when removed), as the real checks would list them.
     */
    public static function deployRun(string $repo, string $from, string $to, array $extra = []): array
    {
        JsonStore::write('sources', ['repo' => ['key' => 'repo', 'name' => 'Repo', 'repo_path' => $repo, 'branch' => 'main', 'remote' => 'origin', 'extra_protected' => '']]);
        $siteDir = self::folder('site');
        JsonStore::write('sites', ['site' => ['key' => 'site', 'name' => 'Test site', 'type' => 'folder', 'source' => 'local:repo', 'production' => false, 'path' => $siteDir, 'url' => '', 'check_path' => '']]);
        $git = new DeployTool\Git($repo, DeployTool\Settings::gitBinary());
        $run = DeployTool\Runner::newRun('deploy', DeployTool\Sites::get('site'), 'review');
        $run['status'] = 'review';
        $run['from'] = $git->resolve($from);
        $run['to'] = $git->resolve($to);
        $run['reviewed_at'] = time();
        $run['prev_state_raw'] = DeployTool\ServerState::readRaw(DeployTool\Sites::transport(DeployTool\Sites::get('site')));
        foreach ($git->changedFiles($run['from'], $run['to']) as [$status, $path]) {
            $run['files'][] = ['path' => $path, 'change' => $status === 'A' ? 'new' : ($status === 'D' ? 'deleted' : 'changed'), 'action' => $status === 'D' ? 'delete' : 'upload',
                'server' => $status === 'A' ? 'missing' : 'match', 'existed' => $status !== 'A', 'linted' => true, 'downloaded' => true, 'sent' => false, 'done' => false, 'verified' => false];
        }
        $run = $extra + $run;
        DeployTool\RunStore::save($run);
        return $run;
    }

    private static function stopServers(): void
    {
        foreach (self::$servers as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        self::$servers = [];
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0666);
            @unlink($path);
            return;
        }
        foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove("$path/$name");
            }
        }
        @rmdir($path);
    }
}

function test(string $name, callable $test): void
{
    Tests::add($name, $test);
}

function check(bool $ok, string $what): void
{
    Tests::check($ok, $what);
}
