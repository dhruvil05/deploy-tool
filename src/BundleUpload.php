<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Speed-up "Upload big FTP deploys in one go": instead of hundreds of FTP transfers, the
 * files go up as one compressed file plus a small helper that unpacks it on the server, each
 * file to a temporary name and then renamed into place, like a normal upload. The helper
 * works like the fingerprint helper: a random name, a token, gone after 15 minutes, and it
 * deletes itself and the bundle. Anything that goes wrong just means the deploy uploads file
 * by file as before; the verify step still checks every file afterwards.
 */
final class BundleUpload
{
    /** Bundles bigger than this go file by file (they'd be held in memory). */
    private const MAX_BYTES = 150 * 1048576;

    /** Whether this many uploads to this site go up in one go. */
    public static function suits(array $site, int $files): bool
    {
        return $site['type'] === 'ftp' && Settings::on('feature_bundle_upload')
            && $files >= max(2, (int) Settings::get('bundle_min_files')) && trim((string) $site['url']) !== '';
    }

    /**
     * Uploads the files (path => contents) in one go. Returns path => error for the files the
     * helper couldn't write (the caller uploads those one by one). Throws when the one-go
     * route can't be used at all; then nothing was written.
     */
    public static function upload(Transport $transport, string $siteUrl, array $files): array
    {
        if (!function_exists('gzencode')) {
            throw new \RuntimeException('this PHP has no zlib');
        }
        $bundle = self::pack($files);
        if (strlen($bundle) > self::MAX_BYTES) {
            throw new \RuntimeException('the deploy is too big for one go');
        }
        $id = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(24));
        $bundleName = "dt-bundle-$id.php";
        $helperName = "dt-unpack-$id.php";
        try {
            $transport->put($bundleName, $bundle);
            $transport->put($helperName, self::helperScript(hash('sha256', $token), $bundleName));
            $answer = self::call($siteUrl, $helperName, $token);
        } finally {
            // The helper deletes both itself; this makes sure, even when the call failed.
            foreach ([$helperName, $bundleName] as $name) {
                try {
                    $transport->delete($name);
                } catch (\RuntimeException) {
                    // already gone, or it expires by itself after 15 minutes
                }
            }
        }
        return array_map('strval', (array) ($answer['errors'] ?? []));
    }

    /**
     * The bundle: a PHP line that exits at once (so requesting it on the web shows nothing),
     * then gzip of a JSON line listing [path, size] and the files' bytes one after another.
     */
    public static function pack(array $files): string
    {
        $list = [];
        foreach ($files as $path => $contents) {
            $list[] = [(string) $path, strlen($contents)];
        }
        return "<?php exit; __halt_compiler();" . gzencode(json_encode($list, JSON_UNESCAPED_SLASHES) . "\n" . implode('', $files), 6);
    }

    /** The helper that unpacks the bundle beside it. Written for PHP 7.0+. */
    public static function helperScript(string $tokenHash, string $bundleName): string
    {
        $script = <<<'PHP'
<?php
// Temporary file from the deploy tool: unpacks the files it just uploaded, then deletes
// itself and them. Stops working after 15 minutes.
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
header('Content-Type: application/json');
$answer = function ($data) {
    echo json_encode($data);
    @unlink(__FILE__);
    exit;
};
$root = __DIR__;
$raw = @file_get_contents($root . '/__BUNDLE__');
@unlink($root . '/__BUNDLE__');
$marker = '__halt_compiler();';
$at = $raw === false ? false : strpos($raw, $marker);
if ($at === false) {
    $answer(['error' => 'The bundle could not be read.']);
}
if (!function_exists('gzdecode')) {
    $answer(['error' => 'The server has no zlib.']);
}
$data = @gzdecode(substr($raw, $at + strlen($marker)));
$newline = $data === false ? false : strpos($data, "\n");
$list = $newline === false ? null : json_decode(substr($data, 0, $newline), true);
if (!is_array($list)) {
    $answer(['error' => 'The bundle is damaged.']);
}
$pos = $newline + 1;
$errors = array();
foreach ($list as $item) {
    $path = (string) $item[0];
    $size = (int) $item[1];
    $contents = (string) substr($data, $pos, $size);
    $pos += $size;
    if ($path === '' || $path[0] === '/' || strpos($path, "\0") !== false || strpos($path, '\\') !== false || in_array('..', explode('/', $path), true)) {
        $errors[$path] = 'Not a valid path.';
        continue;
    }
    if (strlen($contents) !== $size) {
        $errors[$path] = 'The bundle was cut off.';
        continue;
    }
    $full = $root . '/' . $path;
    $dir = dirname($full);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        $errors[$path] = 'Could not create its folder.';
        continue;
    }
    if (is_dir($full)) {
        $errors[$path] = 'It is a folder on the server.';
        continue;
    }
    $temp = $dir . '/.' . basename($full) . '.dtup';
    if (@file_put_contents($temp, $contents) !== $size) {
        @unlink($temp);
        $errors[$path] = 'Could not write it.';
        continue;
    }
    if (!@rename($temp, $full) && !(@unlink($full) && @rename($temp, $full))) {
        @unlink($temp);
        $errors[$path] = 'Could not put it in place.';
    }
}
$answer(array('errors' => (object) $errors, 'written' => count($list) - count($errors)));
PHP;
        return strtr($script, ['__TOKEN_HASH__' => $tokenHash, '__BUNDLE__' => $bundleName]);
    }

    /** Calls the helper over HTTPS (checking the certificate, as for the fingerprint helper). */
    private static function call(string $siteUrl, string $name, string $token): array
    {
        $url = rtrim($siteUrl, '/') . '/' . $name;
        $verify = !Fingerprint::isIpUrl($url);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['t' => $token]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        if ($body === false) {
            throw new \RuntimeException('the unpack helper could not be reached (' . curl_error($curl) . ')');
        }
        $answer = json_decode((string) $body, true);
        if ($status !== 200 || !is_array($answer)) {
            throw new \RuntimeException("the unpack helper answered HTTP $status; check that the site address points at the FTP folder");
        }
        if (isset($answer['error'])) {
            throw new \RuntimeException('on the server: ' . $answer['error']);
        }
        return $answer;
    }
}
