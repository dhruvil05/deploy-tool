<?php
// Deploy tool agent. SshTransport copies this file to ~/.deploy-tool/ on the server (outside
// the web folder) and runs it over SSH with "php". It never runs from the web.
//
// Request on stdin: one JSON line {"op", "root", ...}, then raw file contents for "write".
// Answer on stdout: "DTAGENT1\n", one JSON line, then raw file contents for "read" and
// the fingerprint list for "fingerprint". Written for PHP 7.0+, since the server's PHP is unknown.

if (PHP_SAPI !== 'cli') {
    exit;
}
@ini_set('display_errors', 'stderr');
@set_time_limit(0);
error_reporting(E_ALL);

$in = fopen('php://stdin', 'rb');
$out = fopen('php://stdout', 'wb');

function dt_answer($out, array $data, $raw = '')
{
    $flags = JSON_UNESCAPED_SLASHES | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
    fwrite($out, "DTAGENT1\n" . json_encode($data, $flags) . "\n");
    if ($raw !== '') {
        fwrite($out, $raw);
    }
}

function dt_path($root, $path)
{
    $path = (string) $path;
    if ($path === '' || $path[0] === '/' || strpos($path, "\0") !== false || in_array('..', explode('/', $path), true)) {
        throw new Exception('Not a valid path: ' . $path);
    }
    return $root . '/' . $path;
}

function dt_read_exact($in, $length)
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($in, min(1048576, $length - strlen($data)));
        if ($chunk === false || $chunk === '') {
            throw new Exception('The request was cut off.');
        }
        $data .= $chunk;
    }
    return $data;
}

function dt_blob($contents)
{
    return sha1('blob ' . strlen($contents) . "\0" . $contents);
}

try {
    $request = json_decode((string) fgets($in), true);
    if (!is_array($request) || !isset($request['op'], $request['root'])) {
        throw new Exception('The request could not be read.');
    }
    $root = rtrim((string) $request['root'], '/');
    if ($root !== '' && $root[0] === '~') {
        $root = rtrim((string) getenv('HOME'), '/') . substr($root, 1);
    }
    if ($root === '' || !is_dir($root)) {
        throw new Exception('Folder not found on the server: ' . $request['root']);
    }
    $op = $request['op'];

    if ($op === 'ping') {
        dt_answer($out, ['php' => PHP_VERSION, 'root' => realpath($root)]);
    } elseif ($op === 'list') {
        $listings = [];
        foreach ($request['paths'] as $dir) {
            $full = $dir === '' ? $root : dt_path($root, rtrim($dir, '/'));
            if (!is_dir($full)) {
                $listings[$dir] = null;
                continue;
            }
            $entries = [];
            foreach (scandir($full) ?: [] as $name) {
                $path = $full . '/' . $name;
                if ($name === '.' || $name === '..' || is_link($path)) {
                    continue;
                }
                $isDir = is_dir($path);
                $entries[] = [$name, $isDir, $isDir ? 0 : (int) filesize($path)];
            }
            $listings[$dir] = $entries;
        }
        dt_answer($out, ['listings' => $listings]);
    } elseif ($op === 'read') {
        $sizes = [];
        $raw = '';
        foreach ($request['paths'] as $path) {
            $full = dt_path($root, $path);
            if (!is_file($full)) {
                $sizes[] = -1;
                continue;
            }
            $contents = file_get_contents($full);
            if ($contents === false) {
                throw new Exception('Could not read ' . $path);
            }
            $sizes[] = strlen($contents);
            $raw .= $contents;
        }
        dt_answer($out, ['sizes' => $sizes], $raw);
    } elseif ($op === 'write') {
        // Each file goes to a temp name next to it, then is renamed into place, so a
        // half-written file never replaces the live one.
        $errors = [];
        foreach ($request['files'] as $file) {
            $path = (string) $file[0];
            $contents = dt_read_exact($in, (int) $file[1]);
            try {
                $full = dt_path($root, $path);
                $dir = dirname($full);
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new Exception('Could not create the folder for ' . $path);
                }
                if (is_dir($full)) {
                    throw new Exception($path . ' is a folder on the server.');
                }
                $temp = $dir . '/.' . basename($full) . '.dtup';
                if (@file_put_contents($temp, $contents) !== strlen($contents) || !@rename($temp, $full)) {
                    @unlink($temp);
                    throw new Exception('Could not write ' . $path);
                }
            } catch (Exception $e) {
                $errors[$path] = $e->getMessage();
            }
        }
        dt_answer($out, ['errors' => (object) $errors]);
    } elseif ($op === 'delete') {
        $errors = [];
        $deleted = [];
        foreach ($request['paths'] as $path) {
            try {
                $full = dt_path($root, $path);
                if (is_file($full) || is_link($full)) {
                    if (!@unlink($full)) {
                        throw new Exception('Could not delete ' . $path);
                    }
                    $deleted[] = $path;
                }
            } catch (Exception $e) {
                $errors[$path] = $e->getMessage();
            }
        }
        dt_answer($out, ['errors' => (object) $errors, 'deleted' => $deleted]);
    } elseif ($op === 'fingerprint') {
        // Same list as the fingerprint helper: blob hash, hash without Windows line
        // endings ("-" when there are none), size, path. Protected folders are never read.
        $skip = $request['skip'];
        $skipped = function ($path) use ($skip) {
            foreach ($skip as $regex) {
                if (preg_match($regex, $path)) {
                    return true;
                }
            }
            return false;
        };
        $realRoot = str_replace('\\', '/', realpath($root));
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($realRoot, FilesystemIterator::SKIP_DOTS),
            function ($file) use ($realRoot, $skipped) {
                $path = substr(str_replace('\\', '/', $file->getPathname()), strlen($realRoot) + 1);
                return !$file->isLink() && !$skipped($file->isDir() ? $path . '/' : $path);
            }
        ));
        $lines = '';
        $count = 0;
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = substr(str_replace('\\', '/', $file->getPathname()), strlen($realRoot) + 1);
            $contents = @file_get_contents($file->getPathname());
            if ($contents === false) {
                $lines .= "ERR\t-\t0\t" . $path . "\n";
            } else {
                $normalized = str_replace("\r\n", "\n", $contents);
                $lines .= dt_blob($contents) . "\t" . ($normalized === $contents ? '-' : dt_blob($normalized)) . "\t" . strlen($contents) . "\t" . $path . "\n";
            }
            $count++;
        }
        dt_answer($out, ['count' => $count], $lines . "END\n");
    } else {
        throw new Exception('Unknown request: ' . $op);
    }
} catch (Throwable $e) {
    dt_answer($out, ['error' => $e->getMessage()]);
}
