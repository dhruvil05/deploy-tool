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

    /** How many files one batch call should handle: small for slow per-file transports, large for one-trip ones. */
    public function batchSize(): int;

    /** Several folders at once: dir => listDetailed(dir). */
    public function listDetailedMany(array $dirs): array;

    /** Several files at once: path => contents, or null when missing. Throws when a file can't be read. */
    public function getMany(array $paths): array;

    /**
     * Writes several files (path => contents), each like put().
     * Returns path => error message for the files that failed; every other file was written.
     */
    public function putMany(array $files): array;

    /** Deletes several files. Returns path => error message for the ones that failed (a missing file is not a failure). */
    public function deleteMany(array $paths): array;
}
