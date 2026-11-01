<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Where the tool keeps its data (settings, sites with their passwords, runs, backups,
 * chats): storage/ next to index.php, or a folder chosen in Settings → Storage. The choice
 * is written to storage/location.json, which bootstrap.php reads first.
 */
final class StorageLocation
{
    private const POINTER = DEFAULT_STORAGE_DIR . '/location.json';

    /** Files that mark a folder as already holding the tool's data. */
    private const MARKERS = ['settings.json', 'sites.json', 'sources.json', 'runs'];

    /** Set by DEPLOY_TOOL_STORAGE: then the folder can't be changed in Settings. */
    public static function fromEnvironment(): bool
    {
        return (bool) env('DEPLOY_TOOL_STORAGE');
    }

    public static function isDefault(): bool
    {
        return self::same(STORAGE_DIR, DEFAULT_STORAGE_DIR);
    }

    /** The path written in Settings, even when it isn't available right now. */
    public static function chosen(): ?string
    {
        $path = is_file(self::POINTER) ? (json_decode((string) file_get_contents(self::POINTER), true)['path'] ?? null) : null;
        return is_string($path) && $path !== '' ? $path : null;
    }

    public static function display(string $path): string
    {
        return str_replace('/', '\\', (string) (realpath($path) ?: $path));
    }

    /** Whether a folder already holds the tool's data. */
    public static function hasData(string $path): bool
    {
        foreach (self::MARKERS as $marker) {
            if (file_exists($path . '/' . $marker)) {
                return true;
            }
        }
        return false;
    }

    /** Bytes in a folder, all levels. */
    public static function size(string $path): int
    {
        $bytes = 0;
        if (is_dir($path)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $bytes += $file->isFile() ? $file->getSize() : 0;
            }
        }
        return $bytes;
    }

    /** An error for a folder the tool can't use, or null. */
    public static function problem(string $path): ?string
    {
        if (!preg_match('#^([a-z]:[\\\\/]|\\\\\\\\|/)#i', $path)) {
            return 'Enter a full path, for example D:\deploy-tool-data.';
        }
        if (str_contains($path, '..')) {
            return 'The path cannot contain "..".';
        }
        $real = realpath($path);
        if ($real !== false && !is_dir($real)) {
            return 'That is a file, not a folder.';
        }
        $full = $real !== false ? $real : $path; // a folder that doesn't exist yet is checked as typed
        $app = (string) realpath(APP_ROOT);
        if (!self::same($full, DEFAULT_STORAGE_DIR) && self::inside($full, $app)) {
            return "Choose a folder outside the tool's own folder ($app), so it can never be served on the web.";
        }
        if (self::inside($app, $full)) {
            return 'That folder holds the tool itself. Choose one beside it or elsewhere.';
        }
        return null;
    }

    /**
     * Switches to another folder ($path null: back to storage/ here). With $copy the
     * current data is copied there first (the folder must not hold the tool's data yet);
     * without it, the tool uses whatever is there. The old folder is left as it is.
     */
    public static function change(?string $path, bool $copy): void
    {
        if (self::fromEnvironment()) {
            throw new \RuntimeException('The folder is set by DEPLOY_TOOL_STORAGE, so it can\'t be changed here.');
        }
        $target = $path === null ? DEFAULT_STORAGE_DIR : rtrim(trim($path), '\\/');
        if ($path !== null && ($problem = self::problem($target)) !== null) {
            throw new \RuntimeException($problem);
        }
        if (self::same($target, STORAGE_DIR)) {
            throw new \RuntimeException('The tool already uses that folder.');
        }
        foreach (RunStore::open() as $run) {
            if ($run['status'] === 'running') {
                throw new \RuntimeException(RunView::title($run) . ' is running. Wait for it to finish, then move the storage.');
            }
        }
        if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
            throw new \RuntimeException("Could not create $target.");
        }
        $probe = $target . '/.write-test-' . bin2hex(random_bytes(3));
        if (@file_put_contents($probe, 'ok') === false) {
            throw new \RuntimeException("The tool can't write in $target.");
        }
        unlink($probe);

        if ($copy) {
            if (self::hasData($target)) {
                throw new \RuntimeException('That folder already holds deploy tool data. Use it as it is, or choose an empty folder.');
            }
            self::copyTree(STORAGE_DIR, $target);
        }
        // Blocked from the web even if the folder is ever under a web root.
        if (!is_file($target . '/.htaccess')) {
            file_put_contents($target . '/.htaccess', "Require all denied\n");
        }

        if (!is_dir(DEFAULT_STORAGE_DIR)) {
            mkdir(DEFAULT_STORAGE_DIR, 0700, true);
        }
        if ($path === null) {
            @unlink(self::POINTER);
        } else {
            file_put_contents(self::POINTER, json_encode(['path' => (string) realpath($target)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * Folders inside $path, for the folder picker. An empty path lists the drives.
     *
     * @return array{path: string, parent: ?string, folders: list<array{name: string, path: string}>, hasData: bool, writable: bool}
     */
    public static function browse(string $path): array
    {
        $folders = [];
        if ($path === '') {
            foreach (range('A', 'Z') as $letter) {
                if (is_dir("$letter:\\")) {
                    $folders[] = ['name' => "$letter:", 'path' => "$letter:\\"];
                }
            }
            return ['path' => '', 'parent' => null, 'folders' => $folders, 'hasData' => false, 'writable' => false];
        }
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException('Folder not found.');
        }
        foreach (scandir($real) ?: [] as $name) {
            $full = $real . DIRECTORY_SEPARATOR . $name;
            if ($name === '.' || $name === '..' || $name[0] === '$' || !is_dir($full)) {
                continue;
            }
            $folders[] = ['name' => $name, 'path' => $full];
            if (count($folders) >= 500) {
                break;
            }
        }
        usort($folders, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        $parent = dirname($real);
        return [
            'path' => $real,
            'parent' => $parent !== $real ? $parent : '',
            'folders' => $folders,
            'hasData' => self::hasData($real),
            'writable' => is_writable($real),
        ];
    }

    private static function copyTree(string $from, string $to): void
    {
        $base = strlen((string) realpath($from)) + 1;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $relative = substr((string) $item->getRealPath(), $base);
            // The pointer stays with storage/ here; locks belong to running work only.
            if ($relative === 'location.json' || str_ends_with($relative, '.lock') || str_ends_with($relative, '.worker') || str_ends_with($relative, '.starting')) {
                continue;
            }
            $dest = $to . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                if (!is_dir($dest) && !mkdir($dest, 0700, true) && !is_dir($dest)) {
                    throw new \RuntimeException("Could not create $dest.");
                }
            } elseif (!copy($item->getPathname(), $dest)) {
                throw new \RuntimeException("Could not copy $relative.");
            }
        }
    }

    private static function same(string $a, string $b): bool
    {
        return strcasecmp(rtrim(str_replace('\\', '/', (string) (realpath($a) ?: $a)), '/'), rtrim(str_replace('\\', '/', (string) (realpath($b) ?: $b)), '/')) === 0;
    }

    private static function inside(string $path, string $folder): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        $folder = rtrim(str_replace('\\', '/', $folder), '/') . '/';
        return stripos($path, $folder) === 0;
    }
}
