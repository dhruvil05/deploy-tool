<?php

declare(strict_types=1);

namespace DeployTool;

/** PHP syntax check with "php -l" on the committed file contents. */
final class Linter
{
    /** Returns null when the code parses, otherwise PHP's message. */
    public static function check(string $code, string $displayPath): ?string
    {
        $file = tempnam(sys_get_temp_dir(), 'dtlint');
        file_put_contents($file, $code);
        try {
            // -n: no php.ini, so it starts fast and no extension can fail to load.
            $process = proc_open([Settings::phpBinary(), '-n', '-l', $file], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new \RuntimeException('Could not start PHP for the syntax check. Check the PHP path in Settings.');
            }
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) === 0) {
                return null;
            }
            foreach (preg_split('/\r?\n/', $output) as $line) {
                if (stripos($line, 'error') !== false) {
                    return trim(str_replace($file, $displayPath, $line));
                }
            }
            return trim(str_replace($file, $displayPath, $output));
        } finally {
            @unlink($file);
        }
    }
}
