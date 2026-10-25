<?php

declare(strict_types=1);

namespace DeployTool;

/** A site that is a folder on this computer. Used to test deploys without touching a real server. */
final class FolderTransport implements Transport
{
    private readonly string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        if (!is_dir($this->root)) {
            throw new \RuntimeException("Folder not found: $root");
        }
    }

    public function listNames(string $dir = ''): array
    {
        return array_map('strval', array_keys($this->listDetailed($dir)));
    }

    public function listDetailed(string $dir = ''): array
    {
        $full = $this->full($dir);
        if (!is_dir($full)) {
            throw new \RuntimeException("Folder not found: $dir");
        }
        $entries = [];
        foreach (scandir($full) ?: [] as $name) {
            $path = $full . '/' . $name;
            if ($name === '.' || $name === '..' || is_link($path)) {
                continue;
            }
            $entries[$name] = ['dir' => is_dir($path), 'size' => is_file($path) ? (int) filesize($path) : 0];
        }
        return $entries;
    }

    public function get(string $path): ?string
    {
        $full = $this->full($path);
        return is_file($full) ? (string) file_get_contents($full) : null;
    }

    public function put(string $path, string $contents): void
    {
        $full = $this->full($path);
        $dir = dirname($full);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create the folder for $path");
        }
        $temp = $dir . '/.' . basename($full) . '.dtup';
        if (file_put_contents($temp, $contents) === false || !rename($temp, $full)) {
            @unlink($temp);
            throw new \RuntimeException("Could not write $path");
        }
    }

    public function delete(string $path): bool
    {
        $full = $this->full($path);
        if (!is_file($full)) {
            return false;
        }
        if (!unlink($full)) {
            throw new \RuntimeException("Could not delete $path");
        }
        return true;
    }

    private function full(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if (in_array('..', explode('/', $path), true)) {
            throw new \RuntimeException('A path cannot contain "..".');
        }
        return $path === '' ? $this->root : $this->root . '/' . $path;
    }
}
