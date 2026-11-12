<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Saved passwords and keys (FTP passwords, the AI key, the cloud secret and passphrase) are
 * encrypted in the data folder (AES-256-GCM) with a key kept somewhere else:
 * %APPDATA%\DeployTool\secret.key on Windows, ~/.config/deploy-tool/secret.key elsewhere,
 * or the file DEPLOY_TOOL_KEY_FILE names. A copied, synced or leaked data folder then holds
 * nothing readable. Plain values saved before this keep working and are sealed on the way.
 *
 * A value this computer can't unlock (another computer's key) is kept exactly as it is, so
 * nothing is lost; using it gives a clear error instead.
 */
final class Secrets
{
    private const PREFIX = 'enc:v1:';

    private static ?string $key = null;
    private static bool $loaded = false;

    /** Saved values that couldn't be unlocked during this request. */
    public static int $unreadable = 0;

    public static function seal(string $value): string
    {
        $key = self::key();
        if ($value === '' || $key === null || self::isSealed($value)) {
            return $value;
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::PREFIX, 16);
        if ($cipher === false) {
            throw new \RuntimeException('Could not encrypt a saved password.');
        }
        return self::PREFIX . base64_encode($nonce . $tag . $cipher);
    }

    /** The plain value; a sealed value that can't be unlocked comes back unchanged. */
    public static function open(string $value, ?string $key = null): string
    {
        if (!self::isSealed($value)) {
            return $value;
        }
        $key ??= self::key();
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        $plain = $key === null || $raw === false || strlen($raw) < 28 ? false
            : openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), self::PREFIX);
        if ($plain === false) {
            self::$unreadable++;
            return $value;
        }
        return $plain;
    }

    public static function isSealed(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    public static function sealFields(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = self::seal($data[$field]);
            }
        }
        return $data;
    }

    public static function openFields(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = self::open($data[$field]);
            }
        }
        return $data;
    }

    /** Whether one of the fields still holds plain text that can be sealed now. */
    public static function needsSealing(array $data, array $fields): bool
    {
        foreach ($fields as $field) {
            if (is_string($data[$field] ?? null) && $data[$field] !== '' && !self::isSealed($data[$field])) {
                return self::key() !== null;
            }
        }
        return false;
    }

    /**
     * Re-seals a stored file's fields with this computer's key, after a restore brought
     * values sealed with another key. $perItem: the file is a list (sites.json).
     */
    public static function resealFile(string $name, array $fields, bool $perItem, string $fromKey): void
    {
        if (!JsonStore::exists($name)) {
            return;
        }
        $reseal = static function (array $data) use ($fields, $fromKey): array {
            foreach ($fields as $field) {
                if (is_string($data[$field] ?? null) && self::isSealed($data[$field])) {
                    $data[$field] = self::seal(self::open($data[$field], $fromKey));
                }
            }
            return $data;
        };
        $stored = JsonStore::read($name);
        JsonStore::write($name, $perItem ? array_map($reseal, $stored) : $reseal($stored));
    }

    /** The key, base64, for the encrypted cloud backup (null when there is none). */
    public static function exportKey(): ?string
    {
        $key = self::key();
        return $key === null ? null : base64_encode($key);
    }

    public static function importKey(string $base64): ?string
    {
        $raw = base64_decode(trim($base64), true);
        return $raw !== false && strlen($raw) === 32 ? $raw : null;
    }

    /** For Settings → Security. */
    public static function status(): array
    {
        $file = self::keyFile();
        return ['file' => $file, 'ready' => self::key() !== null, 'fromEnv' => (bool) env('DEPLOY_TOOL_KEY_FILE')];
    }

    /**
     * Where the key is kept, or null when there's no safe place: never in the data folder,
     * the tool's folder or the web folder, since that would defeat the point.
     */
    public static function keyFile(): ?string
    {
        $configured = env('DEPLOY_TOOL_KEY_FILE');
        if ($configured) {
            return str_replace('\\', '/', $configured);
        }
        $base = PHP_OS_FAMILY === 'Windows'
            ? getenv('APPDATA')
            : (getenv('XDG_CONFIG_HOME') ?: (getenv('HOME') ? getenv('HOME') . '/.config' : false));
        if (!is_string($base) || $base === '') {
            return null;
        }
        $file = rtrim(str_replace('\\', '/', $base), '/') . (PHP_OS_FAMILY === 'Windows' ? '/DeployTool' : '/deploy-tool') . '/secret.key';
        foreach ([STORAGE_DIR, APP_ROOT, (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')] as $folder) {
            if ($folder !== '' && self::inside(dirname($file), $folder)) {
                return null;
            }
        }
        return $file;
    }

    /** Loads the key, making one the first time it's needed. */
    private static function key(): ?string
    {
        if (self::$loaded) {
            return self::$key;
        }
        self::$loaded = true;
        $file = self::keyFile();
        if ($file === null) {
            return null;
        }
        if (!is_file($file)) {
            self::create($file);
        }
        $key = self::read($file);
        // A key file that can't be used (cut short, edited by hand) is set aside, not trusted
        // and not silently dropped: a new key keeps new passwords encrypted, and values sealed
        // with the old one show the "can't be unlocked" warning until they're typed again.
        // A file only seconds old may still be being written by another request: left alone.
        if ($key === null && is_file($file) && time() - (int) @filemtime($file) > 5) {
            if (@rename($file, $file . '.broken-' . date('Ymd-His'))) {
                self::create($file);
                $key = self::read($file);
            }
        }
        return self::$key = $key;
    }

    /** Makes a new key file; never replaces one another process just made. */
    private static function create(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $handle = @fopen($file, 'x');
        if ($handle !== false) {
            fwrite($handle, base64_encode(random_bytes(32)) . "\n");
            fclose($handle);
            @chmod($file, 0600);
        }
    }

    /** The key in the file, trying a few times while another process may still be writing it. */
    private static function read(string $file): ?string
    {
        for ($try = 0; $try < 10; $try++) {
            $key = is_file($file) ? self::importKey((string) @file_get_contents($file)) : null;
            if ($key !== null || !is_file($file)) {
                return $key;
            }
            usleep(50_000);
        }
        return null;
    }

    /** Damaged key files set aside so far (newest first), for Settings → Security. */
    public static function brokenKeys(): array
    {
        $file = self::keyFile();
        $found = $file === null ? [] : (glob($file . '.broken-*') ?: []);
        rsort($found);
        return $found;
    }

    private static function inside(string $path, string $folder): bool
    {
        $normal = static fn (string $p): string => strtolower(rtrim(str_replace('\\', '/', (string) (realpath($p) ?: $p)), '/')) . '/';
        return str_starts_with($normal($path), $normal($folder));
    }
}
