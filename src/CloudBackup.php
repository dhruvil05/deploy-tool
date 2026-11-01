<?php

declare(strict_types=1);

namespace DeployTool;

use DeployTool\Cloud\S3;

/**
 * Encrypted copies of the tool's data in S3-compatible cloud storage. The tool keeps
 * working from its local folder; a backup zips that folder, encrypts it with a passphrase
 * (AES-256-GCM, key from PBKDF2), and uploads one file. The provider never sees the
 * passwords and keys inside. Settings in cloud.json, the last result in cloud-state.json.
 */
final class CloudBackup
{
    /** preset => [label, endpoint example, region] */
    public const PRESETS = [
        'r2' => ['Cloudflare R2', 'https://ACCOUNT_ID.r2.cloudflarestorage.com', 'auto'],
        'b2' => ['Backblaze B2', 'https://s3.us-west-004.backblazeb2.com', 'us-west-004'],
        's3' => ['Amazon S3', 'https://s3.us-east-1.amazonaws.com', 'us-east-1'],
        'wasabi' => ['Wasabi', 'https://s3.us-east-1.wasabisys.com', 'us-east-1'],
        'custom' => ['Other', '', 'us-east-1'],
    ];

    public const SCHEDULES = ['off' => 'Only when I click', 'daily' => 'Once a day', 'deploy' => 'After every deploy, match or rollback'];

    private const MAGIC = "DTBK1\n";
    private const ITERATIONS = 200000;
    /** Never in a backup: this computer's own state, and the cloud settings themselves. */
    private const SKIP = ['location.json', 'cloud.json', 'cloud-state.json', 'worker.log', '.htaccess'];
    private const SKIP_FOLDERS = ['cache', 'restore-safety'];

    public static function defaults(): array
    {
        return [
            'provider' => 'r2',
            'endpoint' => '',
            'region' => 'auto',
            'bucket' => '',
            'prefix' => 'deploy-tool/',
            'access_key' => '',
            'secret_key' => '',
            'passphrase' => '',
            'schedule' => 'off',
            'keep' => '10',
            'include_backups' => '1',
        ];
    }

    public static function settings(): array
    {
        return array_merge(self::defaults(), array_intersect_key(JsonStore::read('cloud'), self::defaults()));
    }

    public static function configured(?array $settings = null): bool
    {
        $s = $settings ?? self::settings();
        return $s['endpoint'] !== '' && $s['bucket'] !== '' && $s['access_key'] !== '' && $s['secret_key'] !== '' && $s['passphrase'] !== '';
    }

    /**
     * Settings from the form's cloud_* fields; blank secrets keep the saved ones.
     *
     * @return array{0: array, 1: array<string, string>}
     */
    public static function fromInput(array $input): array
    {
        $saved = self::settings();
        $values = [];
        foreach (array_keys(self::defaults()) as $key) {
            $values[$key] = trim((string) ($input['cloud_' . $key] ?? ''));
        }
        foreach (['secret_key', 'passphrase'] as $secret) {
            if ($values[$secret] === '') {
                $values[$secret] = $saved[$secret];
            }
        }
        $values['include_backups'] = $values['include_backups'] === '1' ? '1' : '0';
        $values['endpoint'] = rtrim($values['endpoint'], '/');
        $values['prefix'] = $values['prefix'] === '' ? '' : trim(str_replace('\\', '/', $values['prefix']), '/') . '/';

        $errors = [];
        $empty = $values['endpoint'] === '' && $values['bucket'] === '' && $values['access_key'] === '';
        if (!isset(self::PRESETS[$values['provider']])) {
            $errors['cloud_provider'] = 'Pick a provider.';
        }
        if (!isset(self::SCHEDULES[$values['schedule']])) {
            $errors['cloud_schedule'] = 'Pick when to back up.';
        }
        if (!$empty || $values['schedule'] !== 'off') {
            if (!preg_match('#^https?://[^\s/]+(/\S*)?$#i', $values['endpoint']) || str_contains($values['endpoint'], 'ACCOUNT_ID')) {
                $errors['cloud_endpoint'] = 'Enter the storage endpoint, starting with https://.';
            }
            if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $values['bucket'])) {
                $errors['cloud_bucket'] = 'Enter the bucket name (lowercase letters, numbers, dots and dashes).';
            }
            if (!preg_match('/^[\w-]+$/', $values['region'])) {
                $errors['cloud_region'] = 'Enter the region, e.g. auto for R2 or us-east-1.';
            }
            if ($values['access_key'] === '') {
                $errors['cloud_access_key'] = 'Enter the access key ID.';
            }
            if ($values['secret_key'] === '') {
                $errors['cloud_secret_key'] = 'Enter the secret access key.';
            }
            if (strlen($values['passphrase']) < 12) {
                $errors['cloud_passphrase'] = 'Use a passphrase of at least 12 characters, and keep it somewhere safe: without it a backup can\'t be opened.';
            }
        }
        if (str_contains($values['prefix'], '..')) {
            $errors['cloud_prefix'] = 'The folder cannot contain "..".';
        }
        if (!ctype_digit($values['keep']) || (int) $values['keep'] < 1 || (int) $values['keep'] > 100) {
            $errors['cloud_keep'] = 'Enter 1 to 100.';
        }
        return [$values, $errors];
    }

    public static function save(array $values): void
    {
        JsonStore::write('cloud', array_intersect_key($values, self::defaults()));
    }

    /** The last backup's result: at, name, size, error, by. */
    public static function state(): array
    {
        return JsonStore::read('cloud-state') + ['at' => null, 'name' => null, 'size' => null, 'error' => null, 'error_at' => null, 'running_since' => null];
    }

    /** Checks the bucket answers with these keys (lists one object). */
    public static function test(array $settings): int
    {
        return count(self::client($settings)->list($settings['prefix'], 1));
    }

    /**
     * Zips, encrypts and uploads the data now, then removes copies beyond "keep".
     *
     * @return array{name: string, size: int, files: int}
     */
    public static function backup(?array $settings = null): array
    {
        $settings ??= self::settings();
        if (!self::configured($settings)) {
            throw new \RuntimeException('Set up the cloud backup first.');
        }
        $lock = fopen(STORAGE_DIR . '/cloud-backup.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('A backup is already being made.');
        }
        self::setState(['running_since' => time()]);
        $zip = tempnam(sys_get_temp_dir(), 'dtbk');
        try {
            $files = Zip::create($zip, self::entries($settings['include_backups'] === '1'));
            $sealed = self::encrypt((string) file_get_contents($zip), $settings['passphrase']);
            $host = strtolower((string) preg_replace('/[^A-Za-z0-9-]+/', '-', (string) gethostname())) ?: 'pc';
            $name = $settings['prefix'] . 'deploy-tool-' . date('Ymd-His') . "-$host.dtbk";
            $client = self::client($settings);
            $client->put($name, $sealed);
            self::prune($client, $settings);
            self::setState(['at' => time(), 'name' => $name, 'size' => strlen($sealed), 'files' => $files, 'error' => null, 'error_at' => null, 'running_since' => null]);
            return ['name' => $name, 'size' => strlen($sealed), 'files' => $files];
        } catch (\Throwable $e) {
            self::setState(['error' => $e->getMessage(), 'error_at' => time(), 'running_since' => null]);
            throw $e instanceof \RuntimeException ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        } finally {
            @unlink($zip);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<array{key: string, name: string, size: int, modified: int}> this tool's backups, newest first */
    public static function list(?array $settings = null): array
    {
        $settings ??= self::settings();
        $backups = [];
        foreach (self::client($settings)->list($settings['prefix']) as $object) {
            if (preg_match('/deploy-tool-\d{8}-\d{6}(-[\w-]+)?\.dtbk$/', $object['key'])) {
                $backups[] = $object + ['name' => basename($object['key'])];
            }
        }
        usort($backups, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));
        return $backups;
    }

    /**
     * Replaces the local data with a backup. The current data is zipped to
     * restore-safety/ first. Runs must not be running.
     */
    public static function restore(string $key, string $passphrase, ?array $settings = null): array
    {
        $settings ??= self::settings();
        if (!str_starts_with($key, $settings['prefix']) || !preg_match('/\.dtbk$/', $key)) {
            throw new \RuntimeException('That is not one of this tool\'s backups.');
        }
        foreach (RunStore::open() as $run) {
            if ($run['status'] === 'running') {
                throw new \RuntimeException(RunView::title($run) . ' is running. Wait for it to finish, then restore.');
            }
        }
        $zipData = self::decrypt(self::client($settings)->get($key), $passphrase !== '' ? $passphrase : $settings['passphrase']);
        $zip = tempnam(sys_get_temp_dir(), 'dtrs');
        file_put_contents($zip, $zipData);
        try {
            $names = array_keys(Zip::index($zip));
            if (array_intersect($names, ['settings.json', 'sites.json', 'sources.json']) === []) {
                throw new \RuntimeException('That file does not look like a deploy tool backup.');
            }
            foreach ($names as $name) {
                if ($name === '' || str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/^[a-z]:/i', $name)) {
                    throw new \RuntimeException('The backup holds an unsafe file name, so it was not restored.');
                }
            }

            // Keep what is here now, then clear it and unpack the backup.
            $safety = STORAGE_DIR . '/restore-safety/before-restore-' . date('Ymd-His') . '.zip';
            @mkdir(dirname($safety), 0700, true);
            Zip::create($safety, self::entries(true));
            foreach (self::localFiles(true) as $relative => $full) {
                unlink($full);
            }
            foreach ($names as $name) {
                $target = STORAGE_DIR . '/' . $name;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
                    throw new \RuntimeException('Could not create ' . dirname($target));
                }
                file_put_contents($target, (string) Zip::read($zip, $name));
            }
            return ['files' => count($names), 'safety' => StorageLocation::display($safety)];
        } finally {
            @unlink($zip);
        }
    }

    /**
     * Starts a backup in the background when the schedule says one is due: "daily" a day
     * after the last one, "deploy" after a deploy, match or rollback finished. Never throws.
     */
    public static function maybeStart(bool $afterRun = false): void
    {
        try {
            $settings = self::settings();
            if (!self::configured($settings) || $settings['schedule'] === 'off') {
                return;
            }
            $state = self::state();
            $due = $afterRun ? $settings['schedule'] === 'deploy' : ($settings['schedule'] === 'daily' && time() - (int) $state['at'] > 86400);
            // Don't retry a failing backup on every page; once an hour is enough.
            $recentError = $state['error_at'] !== null && time() - (int) $state['error_at'] < 3600 && !$afterRun;
            $running = $state['running_since'] !== null && time() - (int) $state['running_since'] < 1800;
            if ($due && !$recentError && !$running) {
                self::setState(['running_since' => time()]);
                Worker::spawnScript(APP_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'cloud-backup.php', []);
            }
        } catch (\Throwable) {
            // A backup problem never stops the page; the Settings page shows the last error.
        }
    }

    /** @return \Generator<string, string> the data's files as zip entries */
    private static function entries(bool $withBackups): \Generator
    {
        foreach (self::localFiles($withBackups) as $relative => $full) {
            yield $relative => (string) file_get_contents($full);
        }
    }

    /** @return array<string, string> relative name => full path of the data files to back up */
    private static function localFiles(bool $withBackups): array
    {
        $files = [];
        $base = strlen((string) realpath(STORAGE_DIR)) + 1;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(STORAGE_DIR, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            $relative = str_replace('\\', '/', substr((string) $item->getRealPath(), $base));
            $top = explode('/', $relative)[0];
            if (!$item->isFile() || in_array($relative, self::SKIP, true) || in_array($top, self::SKIP_FOLDERS, true)
                || (!$withBackups && $top === 'backups') || preg_match('/\.(lock|worker|starting|stop|tmp)$/', $relative)) {
                continue;
            }
            $files[$relative] = (string) $item->getRealPath();
        }
        ksort($files);
        return $files;
    }

    private static function prune(S3 $client, array $settings): void
    {
        foreach (array_slice(self::list($settings), max(1, (int) $settings['keep'])) as $old) {
            $client->delete($old['key']);
        }
    }

    private static function encrypt(string $data, string $passphrase): string
    {
        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);
        $tag = '';
        $cipher = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC, 16);
        if ($cipher === false) {
            throw new \RuntimeException('Could not encrypt the backup.');
        }
        return self::MAGIC . $salt . $iv . $tag . $cipher;
    }

    private static function decrypt(string $sealed, string $passphrase): string
    {
        if (!str_starts_with($sealed, self::MAGIC) || strlen($sealed) < strlen(self::MAGIC) + 44) {
            throw new \RuntimeException('That file is not a deploy tool backup.');
        }
        $offset = strlen(self::MAGIC);
        $salt = substr($sealed, $offset, 16);
        $iv = substr($sealed, $offset + 16, 12);
        $tag = substr($sealed, $offset + 28, 16);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::ITERATIONS, 32, true);
        $data = openssl_decrypt(substr($sealed, $offset + 44), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC);
        if ($data === false) {
            throw new \RuntimeException('Wrong passphrase, or the backup is damaged.');
        }
        return $data;
    }

    private static function client(array $settings): S3
    {
        return new S3($settings['endpoint'], $settings['region'] !== '' ? $settings['region'] : 'us-east-1', $settings['bucket'], $settings['access_key'], $settings['secret_key']);
    }

    private static function setState(array $changes): void
    {
        JsonStore::write('cloud-state', array_merge(JsonStore::read('cloud-state'), $changes));
    }
}
