<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Git's blob hash of a file ("fingerprint"), so a server file can be compared with a
 * commit without downloading it. The second hash ignores Windows line endings.
 */
final class Fingerprint
{
    public static function blobSha(string $contents): string
    {
        return sha1('blob ' . strlen($contents) . "\0" . $contents);
    }

    /** @return array{sha: string, nsha: ?string, size: int} */
    public static function of(string $contents): array
    {
        $normalized = str_replace("\r\n", "\n", $contents);
        return [
            'sha' => self::blobSha($contents),
            'nsha' => $normalized === $contents ? null : self::blobSha($normalized),
            'size' => strlen($contents),
        ];
    }

    /**
     * A PHP file uploaded to an FTP site for a moment. It prints the fingerprint of every
     * file (never reading protected folders) and deletes itself. Written for PHP 7.0+,
     * since the server's PHP version is unknown.
     */
    public static function helperScript(string $tokenHash, array $regexes): string
    {
        $script = <<<'PHP'
<?php
// Temporary file from the deploy tool. Prints git's fingerprint of every file
// (protected folders are never read), then deletes itself. Stops working after 15 minutes.
if (!isset($_POST['t']) || !hash_equals('__TOKEN_HASH__', hash('sha256', (string) $_POST['t']))) {
    http_response_code(404);
    exit;
}
if (time() - filemtime(__FILE__) > 900) {
    @unlink(__FILE__);
    http_response_code(410);
    exit;
}
@set_time_limit(0);
$skip = __SKIP__;
$root = str_replace('\\', '/', __DIR__);
$self = basename(__FILE__);
$relative = function ($file) use ($root) {
    return substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
};
$skipped = function ($path) use ($skip) {
    foreach ($skip as $regex) {
        if (preg_match($regex, $path)) {
            return true;
        }
    }
    return false;
};
$blob = function ($contents) {
    return sha1('blob ' . strlen($contents) . "\0" . $contents);
};
header('Content-Type: text/plain; charset=utf-8');
$iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    function ($file) use ($relative, $skipped) {
        $path = $relative($file);
        return !$skipped($file->isDir() ? $path . '/' : $path);
    }
));
foreach ($iterator as $file) {
    $path = $relative($file);
    if (!$file->isFile() || $file->isLink() || $path === $self) {
        continue;
    }
    $contents = @file_get_contents($file->getPathname());
    if ($contents === false) {
        echo "ERR\t-\t0\t", $path, "\n";
        continue;
    }
    $normalized = str_replace("\r\n", "\n", $contents);
    echo $blob($contents), "\t", $normalized === $contents ? '-' : $blob($normalized), "\t", strlen($contents), "\t", $path, "\n";
}
echo "END\n";
@unlink(__FILE__);
PHP;
        return strtr($script, [
            '__TOKEN_HASH__' => $tokenHash,
            '__SKIP__' => var_export(array_values($regexes), true),
        ]);
    }

    /** Calls the helper over HTTPS (POST, so the token stays out of server logs). */
    public static function callHelper(string $siteUrl, string $name, string $token): string
    {
        @set_time_limit(360);
        $curl = curl_init(rtrim($siteUrl, '/') . '/' . $name);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['t' => $token]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($body === false) {
            throw new \RuntimeException('Could not call the fingerprint file: ' . curl_error($curl));
        }
        if ($status !== 200) {
            throw new \RuntimeException("The fingerprint file answered HTTP $status. Check that the site address points at the same folder as the FTP folder.");
        }
        if (!str_ends_with(rtrim((string) $body), 'END')) {
            throw new \RuntimeException('The fingerprint list was cut off. Try the compare again.');
        }
        return (string) $body;
    }

    public static function parseHelperOutput(string $output): array
    {
        $files = [];
        foreach (preg_split('/\r?\n/', $output) as $line) {
            $parts = explode("\t", $line, 4);
            if (count($parts) === 4) {
                // An unreadable file (ERR) gets an empty hash, so it shows as different.
                $files[$parts[3]] = [
                    'sha' => $parts[0] === 'ERR' ? '' : $parts[0],
                    'nsha' => $parts[1] === '-' ? null : $parts[1],
                    'size' => (int) $parts[2],
                ];
            }
        }
        return $files;
    }
}
