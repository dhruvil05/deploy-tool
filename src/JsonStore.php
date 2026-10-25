<?php

declare(strict_types=1);

namespace DeployTool;

/** JSON files in storage/ (never committed, blocked from the web). */
final class JsonStore
{
    public static function exists(string $name): bool
    {
        return is_file(self::path($name));
    }

    public static function read(string $name): array
    {
        return self::readFile(self::path($name));
    }

    public static function write(string $name, array $data): void
    {
        self::writeFile(self::path($name), $data);
    }

    public static function readFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new \RuntimeException(basename($file) . ' is not valid JSON.');
        }
        return $data;
    }

    /** Writes to a temp file first, so a crash never leaves a half-written file. */
    public static function writeFile(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create ' . $dir);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (file_put_contents($file . '.tmp', $json, LOCK_EX) === false || !rename($file . '.tmp', $file)) {
            throw new \RuntimeException('Could not save ' . basename($file));
        }
    }

    private static function path(string $name): string
    {
        return STORAGE_DIR . '/' . $name . '.json';
    }
}
