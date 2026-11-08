<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Is the tool's data folder (FTP passwords, keys) reachable over the web? .htaccess blocks
 * it on Apache, but a server that ignores .htaccess (AllowOverride None, Nginx, IIS) serves
 * it like any other file. So the tool checks for itself: it puts a random file in the data
 * folder, asks the web server for it, and deletes it again. The answer is kept a day, or a
 * few minutes while it is bad, so a fixed server is noticed soon.
 */
final class Exposure
{
    private const OK_SECONDS = 86400;
    private const BAD_SECONDS = 300;

    /** What to tell the user on every page, or null when the data folder is safe (or it can't tell). */
    public static function warning(): ?string
    {
        if (PHP_SAPI === 'cli-server') {
            return self::builtInServerWarning();
        }
        $state = JsonStore::read('exposure');
        $fresh = ($state['dir'] ?? null) === STORAGE_DIR
            && time() - (int) ($state['at'] ?? 0) < (!empty($state['exposed']) ? self::BAD_SECONDS : self::OK_SECONDS);
        if (!$fresh) {
            $state = self::check();
            try {
                JsonStore::write('exposure', $state);
            } catch (\RuntimeException) {
                // the next page tries again
            }
        }
        if (empty($state['exposed'])) {
            return null;
        }
        return "The web server hands out this tool's data folder ({$state['url']} could be downloaded), so anyone who can reach this server may get the FTP passwords and keys in it."
            . ' Move the data folder outside the web folder in Settings → Storage, or let the server use .htaccess files (Apache: AllowOverride All), then reload this page.';
    }

    /**
     * Asks the web server for a random file put in the data folder. Unknown (not exposed)
     * when the data folder isn't under this site, or the server doesn't answer.
     *
     * @return array{at: int, dir: string, exposed: bool, url: ?string}
     */
    private static function check(): array
    {
        $state = ['at' => time(), 'dir' => STORAGE_DIR, 'exposed' => false, 'url' => null];
        $base = self::webPath(STORAGE_DIR);
        if ($base === null || !is_dir(STORAGE_DIR)) {
            return $state;
        }
        $name = 'exposure-check-' . bin2hex(random_bytes(8)) . '.txt';
        $token = bin2hex(random_bytes(16));
        if (@file_put_contents(STORAGE_DIR . '/' . $name, $token) === false) {
            return $state;
        }
        try {
            $path = $base . '/' . $name;
            $address = (string) ($_SERVER['SERVER_ADDR'] ?? '127.0.0.1');
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $curl = curl_init(($https ? 'https' : 'http') . '://' . (str_contains($address, ':') ? "[$address]" : $address)
                . ':' . (int) ($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80)) . $path);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Host: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost')],
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => false,
                // Our own server, asked for our own random file: nothing secret is sent.
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $body = curl_exec($curl);
            $state['exposed'] = curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200 && $body === $token;
            $state['url'] = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $base . '/';
        } finally {
            @unlink(STORAGE_DIR . '/' . $name);
        }
        return $state;
    }

    /**
     * The URL path a folder would have on this site, or null when this site can't serve it:
     * under the tool's own folder it follows index.php's address, else the document root's.
     */
    private static function webPath(string $folder): ?string
    {
        $folder = self::normal((string) (realpath($folder) ?: $folder));
        $app = self::normal((string) realpath(APP_ROOT));
        $root = self::normal((string) (realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: ''));
        $script = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        $encode = static fn (string $relative): string => implode('/', array_map('rawurlencode', explode('/', $relative)));
        if (str_starts_with(strtolower($folder . '/'), strtolower($app . '/'))) {
            return $script . '/' . $encode(ltrim(substr($folder, strlen($app)), '/'));
        }
        if ($root !== '' && str_starts_with(strtolower($folder . '/'), strtolower($root . '/'))) {
            return '/' . $encode(ltrim(substr($folder, strlen($root)), '/'));
        }
        return null;
    }

    /**
     * PHP's built-in server serves every file in its folder unless router.php answers each
     * request, as start.bat does. Started without it, the data folder can be downloaded.
     */
    private static function builtInServerWarning(): ?string
    {
        $entry = basename((string) (get_included_files()[0] ?? ''));
        if ($entry === 'router.php' || self::webPath(STORAGE_DIR) === null) {
            return null;
        }
        return "The tool runs on PHP's built-in server without router.php, which hands out every file in its folder, the data folder with the FTP passwords included."
            . ' Stop it and start the tool with start.bat (php -S 127.0.0.1:8090 router.php).';
    }

    private static function normal(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
