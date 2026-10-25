<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Backups of server files, one zip per run: storage/backups/<run id>.zip.
 * While a run downloads, copies go to a folder (storage/backups/<run id>/); just before
 * the review they are packed into the zip and the folder is removed.
 */
final class Backup
{
    /** Notes for anyone opening the zip by hand. Never a real path in a project. */
    public const INFO_NAME = '__deploy-tool-backup.txt';

    public static function zipFile(string $runId): string
    {
        return STORAGE_DIR . '/backups/' . $runId . '.zip';
    }

    public static function folder(string $runId): string
    {
        return STORAGE_DIR . '/backups/' . $runId;
    }

    /** Saves one server copy while the run is still downloading. */
    public static function save(string $runId, string $path, string $contents): void
    {
        $file = self::folder($runId) . '/' . $path;
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create the backup folder $dir");
        }
        if (file_put_contents($file, $contents) === false) {
            throw new \RuntimeException("Could not save the backup of $path");
        }
    }

    /** The server's copy from before the run, or null when the file wasn't there. */
    public static function get(string $runId, string $path): ?string
    {
        if (is_file(self::zipFile($runId))) {
            return Zip::read(self::zipFile($runId), $path);
        }
        $file = self::folder($runId) . '/' . $path;
        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    public static function exists(string $runId): bool
    {
        return is_file(self::zipFile($runId)) || is_dir(self::folder($runId));
    }

    public static function size(string $runId): ?int
    {
        return is_file(self::zipFile($runId)) ? (int) filesize(self::zipFile($runId)) : null;
    }

    /** Where it is on disk, written the Windows way for copying into Explorer. */
    public static function location(string $runId): ?string
    {
        $path = is_file(self::zipFile($runId)) ? self::zipFile($runId) : (is_dir(self::folder($runId)) ? self::folder($runId) : null);
        return $path === null ? null : str_replace('/', '\\', realpath($path) ?: $path);
    }

    /**
     * Packs the run's backup folder into its zip, checks the zip, then removes the folder.
     * Returns the number of backed-up files (0 when no file existed on the server).
     */
    public static function pack(array $run): int
    {
        $folder = self::folder($run['id']);
        if (!is_dir($folder)) {
            return 0;
        }
        $files = self::listFolder($folder);
        $temp = self::zipFile($run['id']) . '.tmp';
        $entries = (static function () use ($files, $folder, $run): \Generator {
            yield self::INFO_NAME => self::infoText($run, array_keys($files));
            foreach ($files as $path => $size) {
                yield $path => (string) file_get_contents("$folder/$path");
            }
        })();
        $count = Zip::create($temp, $entries);

        // Check before deleting anything: every file is listed with the right size.
        $index = Zip::index($temp);
        foreach ($files as $path => $size) {
            if (($index[$path]['size'] ?? -1) !== $size) {
                @unlink($temp);
                throw new \RuntimeException("Packing the backup failed for $path. The backup folder was kept.");
            }
        }
        if (!rename($temp, self::zipFile($run['id']))) {
            throw new \RuntimeException('Could not save the backup zip.');
        }
        self::removeFolder($folder);
        return $count - 1;
    }

    /** Backups saved as folders (before zips existed): pack them. Skips runs still downloading. */
    public static function packOldFolders(): void
    {
        foreach (glob(STORAGE_DIR . '/backups/*', GLOB_ONLYDIR) ?: [] as $folder) {
            $id = basename($folder);
            try {
                $run = RunStore::load($id);
            } catch (\RuntimeException) {
                $run = ['id' => $id, 'type' => 'deploy', 'site_name' => '?', 'by' => '?', 'created_at' => filemtime($folder), 'from' => null, 'to' => null, 'files' => [], 'status' => 'done'];
            }
            if ($run['status'] !== 'running' && !is_file(self::zipFile($id))) {
                self::pack($run);
            }
        }
    }

    /** @return array<string, int> relative path => size */
    private static function listFolder(string $folder): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[substr(str_replace('\\', '/', $file->getPathname()), strlen(str_replace('\\', '/', $folder)) + 1)] = (int) $file->getSize();
            }
        }
        ksort($files);
        return $files;
    }

    private static function infoText(array $run, array $paths): string
    {
        $added = [];
        foreach ($run['files'] ?? [] as $file) {
            if (($file['action'] ?? '') === 'upload' && ($file['existed'] ?? null) === false) {
                $added[] = '  ' . $file['path'];
            }
        }
        $lines = [
            'Deploy tool backup',
            '',
            'Run:      ' . $run['id'] . ' (' . (RunView::TYPES[$run['type']] ?? $run['type']) . ' to ' . $run['site_name'] . ')',
            'Made:     ' . date('Y-m-d H:i:s P', (int) $run['created_at']) . ' by ' . $run['by'],
            'Commits:  ' . ($run['from'] ?? 'none recorded') . ' -> ' . ($run['to'] ?? '?'),
            '',
            'These ' . count($paths) . ' file(s) are the server\'s copies from just before the run changed them.',
            'To restore one by hand, upload it back to the same path on the server.',
            'The tool\'s Rollback button does this for every file at once.',
        ];
        if ($added) {
            $lines[] = '';
            $lines[] = 'Files the run added (they were not on the server, so they are not in this zip):';
            array_push($lines, ...$added);
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    private static function removeFolder(string $folder): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($folder);
    }
}
