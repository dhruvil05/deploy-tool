<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * The SSH servers this tool trusts, in known_hosts in the data folder. ssh only connects to
 * a server whose key is in it, so a server can't be swapped for another one unnoticed. A new
 * server's key is looked up and shown as a fingerprint for you to compare with
 * the one your host gives (cPanel → SSH Access), and saved when you trust it.
 */
final class HostKeys
{
    public static function file(): string
    {
        return STORAGE_DIR . '/known_hosts';
    }

    /** How ssh names the server in known_hosts. */
    public static function hostSpec(string $host, int $port): string
    {
        return $port === 22 ? strtolower($host) : '[' . strtolower($host) . "]:$port";
    }

    /** @return list<array{type: string, fingerprint: string}> the keys trusted for this server */
    public static function trusted(string $host, int $port): array
    {
        $keys = [];
        foreach (self::lines() as $line) {
            [$hosts, $type, $blob] = preg_split('/\s+/', $line, 4) + ['', '', ''];
            if ($blob !== '' && self::matches($hosts, self::hostSpec($host, $port))) {
                $keys[] = ['type' => $type, 'fingerprint' => self::fingerprint($blob)];
            }
        }
        return $keys;
    }

    /**
     * Asks the server for its key without logging in: ssh connects with every login method
     * off, notes the key in a temporary file, and stops. (Windows' own ssh-keyscan can't talk
     * to current servers, and this way the key is the one real connections will check.)
     *
     * @return list<array{type: string, fingerprint: string, line: string}>
     */
    public static function scan(string $host, int $port, string $user = ''): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'dtkh');
        try {
            $process = proc_open([
                Settings::sshBinary(), '-T',
                '-o', 'BatchMode=yes',
                '-o', 'StrictHostKeyChecking=accept-new',
                '-o', 'UserKnownHostsFile="' . str_replace('\\', '/', $temp) . '"',
                '-o', 'GlobalKnownHostsFile=' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'),
                '-o', 'HashKnownHosts=no',
                '-o', 'PreferredAuthentications=none',
                '-o', 'PubkeyAuthentication=no',
                '-o', 'PasswordAuthentication=no',
                '-o', 'KbdInteractiveAuthentication=no',
                '-o', 'ConnectTimeout=10',
                '-o', 'LogLevel=ERROR',
                '-p', (string) $port,
                ($user !== '' ? $user : 'hostkey-check') . '@' . $host,
                'exit',
            ], [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);
            if (!is_resource($process)) {
                throw new \RuntimeException('Could not start ssh (' . Settings::sshBinary() . '). Check the SSH program in Settings → Programs.');
            }
            $errors = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[2]);
            proc_close($process);

            $keys = [];
            foreach (preg_split('/\r?\n/', TempFile::read($temp, 'ssh')) as $line) {
                [, $type, $blob] = preg_split('/\s+/', trim($line), 4) + ['', '', ''];
                if ($blob !== '' && base64_decode($blob, true) !== false) {
                    $keys[] = ['type' => $type, 'fingerprint' => self::fingerprint($blob), 'line' => self::hostSpec($host, $port) . " $type $blob"];
                }
            }
        } finally {
            @unlink($temp);
        }
        if ($keys === []) {
            throw new \RuntimeException("$host didn't show an SSH key on port $port" . ($errors !== '' ? ': ' . strtok($errors, "\n") : '.') . ' Check the host and port.');
        }
        return $keys;
    }

    /**
     * Trusts the server's key with this fingerprint (looked up again, so only the key you
     * saw is saved). Keys saved before for this server are replaced.
     */
    public static function trust(string $host, int $port, string $fingerprint, string $user = ''): void
    {
        $chosen = current(array_filter(self::scan($host, $port, $user),static fn (array $key): bool => hash_equals($key['fingerprint'], $fingerprint)));
        if ($chosen === false) {
            throw new \RuntimeException("The server doesn't show that key any more, so nothing was saved. Look it up again and compare.");
        }
        $spec = self::hostSpec($host, $port);
        $kept = array_filter(self::lines(), static fn (string $line): bool => !self::matches((string) strtok($line, " \t"), $spec));
        $kept[] = $chosen['line'];
        if (file_put_contents(self::file(), implode("\n", $kept) . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Could not save ' . self::file());
        }
    }

    /** ssh's own SHA256 fingerprint of a key. */
    public static function fingerprint(string $blob): string
    {
        return 'SHA256:' . rtrim(base64_encode(hash('sha256', (string) base64_decode($blob), true)), '=');
    }

    /** @return list<string> the known_hosts lines, without comments */
    private static function lines(): array
    {
        $lines = is_file(self::file()) ? (file(self::file(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== '' && $line[0] !== '#'));
    }

    /** Whether a known_hosts host field (names, or a hashed |1|salt|hash) means $spec. */
    private static function matches(string $hosts, string $spec): bool
    {
        foreach (explode(',', $hosts) as $name) {
            if (str_starts_with($name, '|1|')) {
                [, , $salt, $hash] = explode('|', $name) + ['', '', '', ''];
                if (hash_equals((string) base64_decode($hash), hash_hmac('sha1', $spec, (string) base64_decode($salt), true))) {
                    return true;
                }
            } elseif (strtolower($name) === $spec) {
                return true;
            }
        }
        return false;
    }
}
