<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * A site reached over SSH, with key login. Every call runs src/remote-agent.php on the
 * server with one SSH connection, so a whole batch of files (hundreds) goes in one trip,
 * and the server fingerprints itself without anything being put in the web folder.
 * The agent is copied to ~/.deploy-tool/ on the server the first time it is needed.
 */
final class SshTransport implements Transport
{
    private const AGENT_DIR = '~/.deploy-tool';
    private const MARKER = "DTAGENT1\n";

    public function __construct(private readonly array $site)
    {
    }

    public function batchSize(): int
    {
        return 400;
    }

    public function listNames(string $dir = ''): array
    {
        return array_map('strval', array_keys($this->listDetailed($dir)));
    }

    public function listDetailed(string $dir = ''): array
    {
        return $this->listDetailedMany([$dir])[$dir];
    }

    public function listDetailedMany(array $dirs): array
    {
        [$answer] = $this->call('list', ['paths' => array_values($dirs)]);
        $listings = [];
        foreach ($dirs as $dir) {
            $entries = $answer['listings'][$dir] ?? null;
            if ($entries === null) {
                throw new \RuntimeException("Folder not found on the server: $dir");
            }
            $listings[$dir] = [];
            foreach ($entries as [$name, $isDir, $size]) {
                $listings[$dir][$name] = ['dir' => (bool) $isDir, 'size' => (int) $size];
            }
        }
        return $listings;
    }

    public function get(string $path): ?string
    {
        return $this->getMany([$path])[$path];
    }

    public function getMany(array $paths): array
    {
        $paths = array_values(array_map('strval', $paths));
        [$answer, $raw] = $this->call('read', ['paths' => $paths]);
        $contents = [];
        $pos = 0;
        foreach ($paths as $i => $path) {
            $size = (int) ($answer['sizes'][$i] ?? -2);
            if ($size === -2) {
                throw new \RuntimeException('The server sent an incomplete answer.');
            }
            $contents[$path] = $size < 0 ? null : substr($raw, $pos, $size);
            $pos += max(0, $size);
        }
        if ($pos !== strlen($raw)) {
            throw new \RuntimeException('The server sent an incomplete answer.');
        }
        return $contents;
    }

    public function put(string $path, string $contents): void
    {
        $errors = $this->putMany([$path => $contents]);
        if ($errors !== []) {
            throw new \RuntimeException(reset($errors));
        }
    }

    public function putMany(array $files): array
    {
        $list = [];
        foreach ($files as $path => $contents) {
            $list[] = [(string) $path, strlen($contents)];
        }
        [$answer] = $this->call('write', ['files' => $list], implode('', $files));
        return (array) ($answer['errors'] ?? []);
    }

    public function delete(string $path): bool
    {
        [$answer] = $this->call('delete', ['paths' => [$path]]);
        if (!empty($answer['errors'])) {
            throw new \RuntimeException(reset($answer['errors']));
        }
        return in_array($path, $answer['deleted'] ?? [], true);
    }

    public function deleteMany(array $paths): array
    {
        [$answer] = $this->call('delete', ['paths' => array_values(array_map('strval', $paths))]);
        return (array) ($answer['errors'] ?? []);
    }

    /**
     * The fingerprint of every file on the server (protected folders are never read),
     * in the format of Fingerprint::parseHelperOutput().
     */
    public function fingerprintAll(array $protectedRegexes): array
    {
        [, $raw] = $this->call('fingerprint', ['skip' => array_values($protectedRegexes)]);
        if (!str_ends_with(rtrim($raw), 'END')) {
            throw new \RuntimeException('The fingerprint list was cut off. Try the compare again.');
        }
        return Fingerprint::parseHelperOutput($raw);
    }

    /** The server's PHP version and the full path of the site folder. */
    public function ping(): array
    {
        return $this->call('ping', [])[0];
    }

    /**
     * Runs one agent request. Installs the agent first when the server doesn't have this version.
     *
     * @return array{0: array, 1: string} the answer and the raw data after it
     */
    private function call(string $op, array $request, string $body = ''): array
    {
        $input = json_encode(['op' => $op, 'root' => $this->site['ssh_root']] + $request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n" . $body;
        $command = $this->site['ssh_php'] . ' ' . $this->agentPath();

        [$code, $stdout, $stderr] = $this->ssh($command, $input);
        if (!str_contains($stdout, self::MARKER) && str_contains($stdout . $stderr, 'Could not open input file')) {
            $this->installAgent();
            [$code, $stdout, $stderr] = $this->ssh($command, $input);
        }

        $start = strpos($stdout, self::MARKER);
        if ($start === false) {
            throw new \RuntimeException($this->failure($code, $stdout, $stderr));
        }
        $start += strlen(self::MARKER);
        $end = strpos($stdout, "\n", $start);
        $answer = $end === false ? null : json_decode(substr($stdout, $start, $end - $start), true);
        if (!is_array($answer)) {
            throw new \RuntimeException('The server sent an answer the tool could not read.');
        }
        if (isset($answer['error'])) {
            throw new \RuntimeException('On the server: ' . $answer['error']);
        }
        return [$answer, (string) substr($stdout, $end + 1)];
    }

    private function installAgent(): void
    {
        $path = $this->agentPath();
        [$code, $stdout, $stderr] = $this->ssh(
            'mkdir -p ' . self::AGENT_DIR . " && cat > $path.tmp && mv -f $path.tmp $path",
            self::agentCode()
        );
        if ($code !== 0) {
            throw new \RuntimeException('Could not copy the tool\'s helper to ' . self::AGENT_DIR . ' on the server: ' . $this->failure($code, $stdout, $stderr));
        }
    }

    /** One ssh call. Input, output and errors go through files, so nothing can deadlock. */
    private function ssh(string $remoteCommand, string $input): array
    {
        $inputFile = tempnam(sys_get_temp_dir(), 'dtssh');
        $outputFile = tempnam(sys_get_temp_dir(), 'dtssh');
        $errorFile = tempnam(sys_get_temp_dir(), 'dtssh');
        file_put_contents($inputFile, $input);
        try {
            $process = proc_open(
                array_merge([Settings::sshBinary()], $this->sshOptions(), [$remoteCommand]),
                [0 => ['file', $inputFile, 'r'], 1 => ['file', $outputFile, 'w'], 2 => ['file', $errorFile, 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('Could not start ssh (' . Settings::sshBinary() . '). Check the SSH program in Settings.');
            }
            $code = proc_close($process);
            return [$code, (string) file_get_contents($outputFile), trim((string) file_get_contents($errorFile))];
        } finally {
            @unlink($inputFile);
            @unlink($outputFile);
            @unlink($errorFile);
        }
    }

    /** Options for ssh: never ask anything, remember the server's key in storage/. */
    public function sshOptions(): array
    {
        $options = [
            '-T',
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile="' . str_replace('\\', '/', STORAGE_DIR) . '/known_hosts"',
            '-o', 'ConnectTimeout=20',
            '-o', 'ServerAliveInterval=15',
            '-o', 'ServerAliveCountMax=4',
            '-o', 'LogLevel=ERROR',
            '-p', (string) (int) $this->site['ssh_port'],
        ];
        if ($this->site['ssh_key'] !== '') {
            array_push($options, '-i', $this->site['ssh_key'], '-o', 'IdentitiesOnly=yes');
        }
        $options[] = $this->site['ssh_user'] . '@' . $this->site['ssh_host'];
        return $options;
    }

    private function failure(int $code, string $stdout, string $stderr): string
    {
        $text = trim($stderr . "\n" . $stdout);
        if ($code === 255) {
            return match (true) {
                str_contains($text, 'Permission denied') => 'SSH login failed: the server did not accept the key. Check the username and key file, and that the public key is added on the server (cPanel → SSH Access).',
                str_contains($text, 'Host key verification failed'), str_contains($text, 'REMOTE HOST IDENTIFICATION HAS CHANGED') => 'The server\'s SSH key changed since the last connection. If that is expected, remove its line from storage/known_hosts.',
                str_contains($text, 'timed out') => 'The SSH server took too long to answer. Check the host and port.',
                str_contains($text, 'Could not resolve') => 'Could not find the SSH host. Check the host name.',
                str_contains($text, 'Connection refused') => 'The server refused the SSH connection. Check the port.',
                default => 'SSH failed: ' . ($text !== '' ? self::firstLines($text) : 'exit code 255'),
            };
        }
        if ($code === 127 || str_contains($text, 'command not found')) {
            return "PHP was not found on the server as \"{$this->site['ssh_php']}\". Set the PHP command for this site, for example /usr/local/bin/php.";
        }
        return 'The server answered with an error' . ($text !== '' ? ': ' . self::firstLines($text) : " (exit code $code).");
    }

    private static function firstLines(string $text): string
    {
        return implode(' ', array_slice(preg_split('/\r?\n/', $text), 0, 3));
    }

    private function agentPath(): string
    {
        return self::AGENT_DIR . '/agent-' . substr(sha1(self::agentCode()), 0, 12) . '.php';
    }

    private static function agentCode(): string
    {
        return (string) file_get_contents(__DIR__ . '/remote-agent.php');
    }
}
