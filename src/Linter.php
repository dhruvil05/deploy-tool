<?php

declare(strict_types=1);

namespace DeployTool;

/** PHP syntax check with "php -l" on the committed file contents. */
final class Linter
{
    /** Files per "php -l" call (PHP 8.3 and later check several files in one call). */
    private const PER_CALL = 100;

    /** Returns null when the code parses, otherwise PHP's message. */
    public static function check(string $code, string $displayPath): ?string
    {
        return self::checkMany([$displayPath => $code])[$displayPath] ?? null;
    }

    /**
     * Checks several files (path => code). Returns path => PHP's message for the files
     * that don't parse; files that parse are left out.
     */
    public static function checkMany(array $files): array
    {
        if ($files === []) {
            return [];
        }
        $dir = sys_get_temp_dir() . '/dtlint-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException('Could not create a temporary folder for the syntax check.');
        }
        $temps = [];
        try {
            $index = 0;
            foreach ($files as $path => $code) {
                $temp = $dir . '/' . $index++ . '.php';
                file_put_contents($temp, $code);
                $temps[$temp] = (string) $path;
            }
            $errors = [];
            foreach (array_chunk($temps, self::PER_CALL, true) as $chunk) {
                $errors += self::lint($chunk);
            }
            return $errors;
        } finally {
            foreach (array_keys($temps) as $temp) {
                @unlink($temp);
            }
            @rmdir($dir);
        }
    }

    /**
     * php -l prints "No syntax errors detected in <file>" or "Errors parsing <file>" for each
     * file. A PHP older than 8.3 only checks the first file, so files without either line
     * are checked again one at a time.
     *
     * @param array<string, string> $temps temp file => display path
     */
    private static function lint(array $temps): array
    {
        $output = self::runPhp(array_keys($temps), $code);
        $lines = array_map('trim', preg_split('/\r?\n/', $output));
        $errors = [];
        $unknown = [];
        foreach ($temps as $temp => $path) {
            if (in_array("No syntax errors detected in $temp", $lines, true)) {
                continue;
            }
            if (!in_array("Errors parsing $temp", $lines, true)) {
                $unknown[$temp] = $path;
                continue;
            }
            $errors[$path] = "PHP could not parse $path.";
            foreach ($lines as $line) {
                if (str_contains($line, $temp) && !str_starts_with($line, 'Errors parsing') && stripos($line, 'error') !== false) {
                    $errors[$path] = str_replace($temp, $path, $line);
                    break;
                }
            }
        }
        if ($unknown !== [] && count($temps) > 1) {
            foreach ($unknown as $temp => $path) {
                $errors += self::lint([$temp => $path]);
            }
        } elseif ($unknown !== [] && $code !== 0) {
            $path = reset($unknown);
            $errors[$path] = trim(str_replace(array_keys($unknown), array_values($unknown), $output)) ?: "PHP could not check $path.";
        }
        return $errors;
    }

    private static function runPhp(array $files, ?int &$code): string
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'dtlint');
        try {
            // -n: no php.ini, so it starts fast and no extension can fail to load.
            $process = proc_open(array_merge([Settings::phpBinary(), '-n', '-l'], $files), [0 => ['pipe', 'r'], 1 => ['file', $outputFile, 'w'], 2 => ['file', $outputFile, 'a']], $pipes);
            if (!is_resource($process)) {
                throw new \RuntimeException('Could not start PHP for the syntax check. Check the PHP path in Settings.');
            }
            fclose($pipes[0]);
            $code = proc_close($process);
            return (string) file_get_contents($outputFile);
        } finally {
            @unlink($outputFile);
        }
    }
}
