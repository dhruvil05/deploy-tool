<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Explicit FTPS access to a site, built on PHP's curl.
 * One curl handle is reused, so requests share the same logged-in connection.
 * Batches run on several connections at once (the site's "connections" setting); a file
 * that fails there is tried once more on its own, so the outcome is the same as one by one.
 */
final class FtpTransport implements Transport
{
    public const MAX_CONNECTIONS = 8;

    // libcurl error numbers.
    private const ERR_CWD_FAILED = 9;       // folder doesn't exist
    private const ERR_RETR_FAILED = 19;     // 550 on download
    private const ERR_QUOTE_FAILED = 21;    // a DELE/RNFR/RNTO command failed
    private const ERR_FILE_NOT_FOUND = 78;
    private const CONNECTION_ERRORS = [6, 7, 28, 35, 56, 60, 64, 67];

    private const MESSAGES = [
        6 => 'Could not find the FTP host. Check the host name.',
        7 => 'Could not connect to the FTP server. Check the host and port.',
        28 => 'The FTP server took too long to answer.',
        35 => 'The secure (TLS) connection could not be set up.',
        56 => 'The connection dropped while receiving data.',
        60 => "The server's certificate could not be verified. Turn off the certificate check for IP-address hosts.",
        64 => 'The server does not support secure FTP (FTPS).',
        67 => 'Login failed. Check the FTP username and password.',
    ];

    private \CurlHandle $curl;

    /** Batches: the multi handle keeps its logged-in connections between batches. */
    private ?\CurlMultiHandle $multi = null;

    /** @var \CurlHandle[] */
    private array $idle = [];

    private readonly int $connections;

    /** Whether this server lets RNTO replace an existing file; learned on the first upload that needs it. */
    private ?bool $renameReplaces = null;

    /** Whether this server knows MLSD; learned on the first listing. */
    private ?bool $mlsd = null;

    public function __construct(private readonly array $site)
    {
        $this->curl = curl_init();
        $this->connections = max(1, min(self::MAX_CONNECTIONS, (int) ($site['connections'] ?? 4)));
    }

    public function __destruct()
    {
        if ($this->multi !== null) {
            curl_multi_close($this->multi);
        }
    }

    public function batchSize(): int
    {
        return $this->connections * 10;
    }

    public function listNames(string $dir = ''): array
    {
        $listing = $this->exec($this->url(self::dirPath($dir)), [CURLOPT_DIRLISTONLY => true]);
        $names = [];
        foreach (preg_split('/\r?\n/', $listing) as $line) {
            $name = basename(trim($line));
            if ($name !== '' && $name !== '.' && $name !== '..') {
                $names[] = $name;
            }
        }
        return $names;
    }

    public function listDetailed(string $dir = ''): array
    {
        $url = $this->url(self::dirPath($dir));
        if ($this->mlsd === false) {
            return self::parseList($this->exec($url));
        }
        try {
            $entries = self::parseMlsd($this->exec($url, [CURLOPT_CUSTOMREQUEST => 'MLSD']));
            $this->mlsd = true;
            return $entries;
        } catch (FtpException $e) {
            if (in_array($e->getCode(), self::CONNECTION_ERRORS, true)) {
                throw $e;
            }
            $entries = self::parseList($this->exec($url)); // server without MLSD
            $this->mlsd ??= false;
            return $entries;
        }
    }

    public function listDetailedMany(array $dirs): array
    {
        $jobs = [];
        foreach ($dirs as $dir) {
            $jobs[$dir] = [CURLOPT_URL => $this->url(self::dirPath($dir))] + ($this->mlsd === false ? [] : [CURLOPT_CUSTOMREQUEST => 'MLSD']);
        }
        $listings = [];
        foreach ($this->execMany($jobs) as $dir => $result) {
            $listings[$dir] = $result instanceof FtpException
                ? $this->listDetailed((string) $dir) // tried again on its own (also finds out about MLSD)
                : ($this->mlsd === false ? self::parseList($result) : self::parseMlsd($result));
        }
        return $listings;
    }

    public function getMany(array $paths): array
    {
        $jobs = [];
        foreach ($paths as $path) {
            $jobs[$path] = [CURLOPT_URL => $this->url($path)];
        }
        $contents = [];
        foreach ($this->execMany($jobs) as $path => $result) {
            if (!$result instanceof FtpException) {
                $contents[$path] = $result;
            } elseif (in_array($result->getCode(), [self::ERR_CWD_FAILED, self::ERR_RETR_FAILED, self::ERR_FILE_NOT_FOUND], true)) {
                $contents[$path] = null;
            } else {
                $contents[$path] = $this->get((string) $path);
            }
        }
        return $contents;
    }

    public function putMany(array $files): array
    {
        $jobs = [];
        $streams = [];
        foreach ($files as $path => $contents) {
            [$dir, $name] = self::split((string) $path);
            $temp = '.' . $name . '.dtup';
            $streams[$path] = fopen('php://temp', 'w+b');
            fwrite($streams[$path], $contents);
            rewind($streams[$path]);
            $jobs[$path] = [
                CURLOPT_URL => $this->url(self::join($dir, $temp)),
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $streams[$path],
                CURLOPT_INFILESIZE => strlen($contents),
                CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY, // retries when another connection made the folder first
                CURLOPT_POSTQUOTE => $this->renameCommands($temp, $name),
            ];
        }
        try {
            $results = $this->execMany($jobs);
        } finally {
            array_map('fclose', $streams);
        }

        // A failed upload is done again on its own: put() also handles servers that
        // won't rename over an existing file, and removes a half-written temp file.
        $errors = [];
        foreach ($results as $path => $result) {
            if ($result instanceof FtpException) {
                try {
                    $this->put((string) $path, $files[$path]);
                } catch (FtpException $e) {
                    $errors[$path] = $e->getMessage();
                }
            }
        }
        return $errors;
    }

    public function deleteMany(array $paths): array
    {
        $jobs = [];
        foreach ($paths as $path) {
            [$dir, $name] = self::split($path);
            $jobs[$path] = [CURLOPT_URL => $this->url(self::dirPath($dir)), CURLOPT_DIRLISTONLY => true, CURLOPT_POSTQUOTE => ["DELE $name"]];
        }
        $errors = [];
        foreach ($this->execMany($jobs) as $path => $result) {
            if ($result instanceof FtpException) {
                try {
                    $this->delete((string) $path); // also tells "already gone" from a real failure
                } catch (FtpException $e) {
                    $errors[$path] = $e->getMessage();
                }
            }
        }
        return $errors;
    }

    public function get(string $path): ?string
    {
        try {
            return $this->exec($this->url($path));
        } catch (FtpException $e) {
            if (in_array($e->getCode(), [self::ERR_CWD_FAILED, self::ERR_RETR_FAILED, self::ERR_FILE_NOT_FOUND], true)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Uploads to a temporary name, then renames over the real file in the same request.
     * If the upload breaks, the live file is never touched.
     */
    public function put(string $path, string $contents): void
    {
        [$dir, $name] = self::split($path);
        $temp = '.' . $name . '.dtup';
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $contents);
        rewind($stream);

        try {
            $this->exec($this->url(self::join($dir, $temp)), [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $stream,
                CURLOPT_INFILESIZE => strlen($contents),
                CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
                CURLOPT_POSTQUOTE => $this->renameCommands($temp, $name),
            ]);
        } catch (FtpException $e) {
            if ($e->getCode() !== self::ERR_QUOTE_FAILED || $this->renameReplaces === false) {
                $this->deleteQuietly($dir, $temp);
                throw $e;
            }
            // Uploaded, but this server won't rename over an existing file: delete it first.
            $this->renameReplaces = false;
            try {
                $this->exec($this->url(self::dirPath($dir)), [
                    CURLOPT_DIRLISTONLY => true,
                    CURLOPT_POSTQUOTE => $this->renameCommands($temp, $name),
                ]);
            } catch (FtpException $retry) {
                $this->deleteQuietly($dir, $temp);
                throw $retry;
            }
        } finally {
            fclose($stream);
        }
    }

    public function delete(string $path): bool
    {
        [$dir, $name] = self::split($path);
        try {
            // List the folder, then DELE runs inside it (post-transfer command).
            $this->exec($this->url(self::dirPath($dir)), [CURLOPT_DIRLISTONLY => true, CURLOPT_POSTQUOTE => ["DELE $name"]]);
            return true;
        } catch (FtpException $e) {
            if ($e->getCode() === self::ERR_CWD_FAILED) {
                return false;
            }
            if ($e->getCode() === self::ERR_QUOTE_FAILED && !in_array($name, $this->listNames($dir), true)) {
                return false;
            }
            throw $e;
        }
    }

    public static function parseMlsd(string $listing): array
    {
        $entries = [];
        foreach (preg_split('/\r?\n/', $listing) as $line) {
            if (!str_contains($line, ' ')) {
                continue;
            }
            [$factText, $name] = explode(' ', $line, 2);
            $facts = [];
            foreach (explode(';', strtolower($factText)) as $fact) {
                if (str_contains($fact, '=')) {
                    [$key, $value] = explode('=', $fact, 2);
                    $facts[$key] = $value;
                }
            }
            $type = $facts['type'] ?? '';
            if (($type === 'dir' || $type === 'file') && $name !== '.' && $name !== '..') {
                $entries[$name] = ['dir' => $type === 'dir', 'size' => (int) ($facts['size'] ?? 0)];
            }
        }
        return $entries;
    }

    /** Unix "ls -l" style listing. Symlinks are skipped. */
    public static function parseList(string $listing): array
    {
        $entries = [];
        foreach (preg_split('/\r?\n/', $listing) as $line) {
            if (!preg_match('/^([dl-])\S{9}\S*\s+\d+\s+\S+\s+\S+\s+(\d+)\s+\w{3}\s+\d{1,2}\s+[\d:]{4,5}\s(.+)$/', $line, $m)) {
                continue;
            }
            if ($m[1] !== 'l' && $m[3] !== '.' && $m[3] !== '..') {
                $entries[$m[3]] = ['dir' => $m[1] === 'd', 'size' => (int) $m[2]];
            }
        }
        return $entries;
    }

    private function renameCommands(string $temp, string $name): array
    {
        $commands = ["RNFR $temp", "RNTO $name"];
        // A leading "*" tells curl to carry on if DELE fails (the file may be new).
        return $this->renameReplaces === false ? array_merge(["*DELE $name"], $commands) : $commands;
    }

    private function deleteQuietly(string $dir, string $name): void
    {
        try {
            $this->delete(self::join($dir, $name));
        } catch (FtpException) {
            // Best effort: a leftover ".name.dtup" file is harmless.
        }
    }

    private function exec(string $url, array $options = []): string
    {
        curl_reset($this->curl); // clears options, keeps the open connection
        curl_setopt_array($this->curl, $options + [CURLOPT_URL => $url] + $this->baseOptions());

        $result = curl_exec($this->curl);
        if ($result === false) {
            throw self::error($this->curl, curl_errno($this->curl));
        }
        return (string) $result;
    }

    /**
     * Runs one request per job (key => curl options), up to "connections" at a time.
     * Returns key => response body, or the FtpException for that job. Never throws for one job.
     *
     * @return array<string, string|FtpException>
     */
    private function execMany(array $jobs): array
    {
        if ($jobs === []) {
            return [];
        }
        if (count($jobs) === 1 || $this->connections === 1) {
            $results = [];
            foreach ($jobs as $key => $options) {
                try {
                    $results[$key] = $this->exec($options[CURLOPT_URL], $options);
                } catch (FtpException $e) {
                    $results[$key] = $e;
                }
            }
            return $results;
        }

        if ($this->multi === null) {
            $this->multi = curl_multi_init();
            curl_multi_setopt($this->multi, CURLMOPT_MAX_HOST_CONNECTIONS, $this->connections);
            curl_multi_setopt($this->multi, CURLMOPT_MAXCONNECTS, $this->connections);
        }
        $queue = $jobs;
        $running = []; // handle id => [key, handle]
        $results = [];
        do {
            while ($queue !== [] && count($running) < $this->connections) {
                $key = array_key_first($queue);
                $handle = array_pop($this->idle) ?? curl_init();
                curl_reset($handle);
                curl_setopt_array($handle, $queue[$key] + $this->baseOptions());
                unset($queue[$key]);
                curl_multi_add_handle($this->multi, $handle);
                $running[spl_object_id($handle)] = [$key, $handle];
            }
            curl_multi_exec($this->multi, $stillRunning);
            if (curl_multi_select($this->multi, 1.0) === -1) {
                usleep(2000);
            }
            while (($info = curl_multi_info_read($this->multi)) !== false) {
                $handle = $info['handle'];
                [$key] = $running[spl_object_id($handle)];
                $results[$key] = $info['result'] === CURLE_OK
                    ? (string) curl_multi_getcontent($handle)
                    : self::error($handle, $info['result']);
                curl_multi_remove_handle($this->multi, $handle);
                unset($running[spl_object_id($handle)]);
                $this->idle[] = $handle;
            }
        } while ($running !== [] || $queue !== []);

        // Same order as the jobs.
        return array_replace(array_intersect_key($jobs, $results), $results);
    }

    private function baseOptions(): array
    {
        $verify = !empty($this->site['verify_tls']);
        return [
            CURLOPT_USERNAME => (string) $this->site['username'],
            CURLOPT_PASSWORD => (string) $this->site['password'],
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            // TLS 1.2 only: uploads over TLS 1.3 left 0-byte files on staging (Oct 2026).
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2 | CURL_SSLVERSION_MAX_TLSv1_2,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_RETURNTRANSFER => true,
        ];
    }

    private static function error(\CurlHandle $handle, int $code): FtpException
    {
        return new FtpException(self::MESSAGES[$code] ?? 'FTP error: ' . (curl_error($handle) ?: curl_strerror($code)), $code);
    }

    private function url(string $path): string
    {
        $fullPath = rtrim((string) $this->site['root'], '/') . '/' . ltrim($path, '/');
        $encoded = implode('/', array_map('rawurlencode', explode('/', $fullPath)));
        return 'ftp://' . $this->site['host'] . ':' . (int) $this->site['port'] . $encoded;
    }

    /** @return array{0: string, 1: string} folder and file name */
    private static function split(string $path): array
    {
        $path = trim($path, '/');
        $slash = strrpos($path, '/');
        return $slash === false ? ['', $path] : [substr($path, 0, $slash), substr($path, $slash + 1)];
    }

    private static function join(string $dir, string $name): string
    {
        return $dir === '' ? $name : $dir . '/' . $name;
    }

    private static function dirPath(string $dir): string
    {
        return rtrim($dir, '/') . '/';
    }
}
