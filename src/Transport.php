<?php

declare(strict_types=1);

namespace DeployTool;

/** File access to one site. Paths are relative to the site folder (the one with index.php). */
interface Transport
{
    /** Names in a folder ('' = the site folder). */
    public function listNames(string $dir = ''): array;

    /** Folder entries as name => ['dir' => bool, 'size' => int]. */
    public function listDetailed(string $dir = ''): array;

    /** File contents, or null when the file doesn't exist. */
    public function get(string $path): ?string;

    /** Writes a file, creating folders. The old file is only replaced once the new one is complete. */
    public function put(string $path, string $contents): void;

    /** Deletes a file. Returns false when it wasn't there. */
    public function delete(string $path): bool;
}
