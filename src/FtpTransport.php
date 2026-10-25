<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Explicit FTPS access to a site, built on PHP's curl.
 * One curl handle is reused, so requests share the same logged-in connection.
 */
final class FtpTransport implements Transport
{
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

    /** Whether this server lets RNTO replace an existing file; learned on the first upload that needs it. */
    private ?bool $renameReplaces = null;

    public function __construct(private readonly array $site)
    {
        $this->curl = curl_init();
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
        try {
            return self::parseMlsd($this->exec($url, [CURLOPT_CUSTOMREQUEST => 'MLSD']));
        } catch (FtpException $e) {
            if (in_array($e->getCode(), self::CONNECTION_ERRORS, true)) {
                throw $e;
            }
            return self::parseList($this->exec($url)); // server without MLSD
        }
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
        $verify = !empty($this->site['verify_tls']);
        curl_reset($this->curl); // clears options, keeps the open connection
        curl_setopt_array($this->curl, $options + [
            CURLOPT_URL => $url,
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
        ]);

        $result = curl_exec($this->curl);
        if ($result === false) {
            $code = curl_errno($this->curl);
            throw new FtpException(self::MESSAGES[$code] ?? 'FTP error: ' . curl_error($this->curl), $code);
        }
        return (string) $result;
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
