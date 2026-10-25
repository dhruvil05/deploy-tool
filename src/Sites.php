<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Deploy targets. Each site gets its code from a local ('local:<key>', a repository and
 * branch on this computer) or from the commit another site runs ('site:<key>').
 * A site is reached over FTPS ('ftp') or is a folder on this computer ('folder', for testing).
 */
final class Sites
{
    private const BLANK = [
        'key' => '',
        'name' => '',
        'type' => 'ftp',
        'source' => '',
        'production' => false,
        'hidden' => false,
        'host' => '',
        'port' => 21,
        'username' => '',
        'password' => '',
        'root' => '/',
        'verify_tls' => false,
        'path' => '',
        'url' => '',
        'check_path' => '/admin/authentication',
    ];

    /** Shown until the sites are saved for the first time. */
    private const DEFAULT_SITES = [
        'staging-site' => [
            'key' => 'staging-site',
            'name' => 'Staging site',
            'source' => 'local',
            'host' => '148.66.159.26',
            'url' => 'https://stagingoffice.dvijinfotech.com',
        ] + self::BLANK,
        'prod' => [
            'key' => 'prod',
            'name' => 'Prod',
            'source' => 'site:staging-site',
            'production' => true,
            'url' => 'https://office.dvijinfotech.com',
        ] + self::BLANK,
    ];

    public static function all(): array
    {
        $sites = JsonStore::exists('sites') ? JsonStore::read('sites') : self::DEFAULT_SITES;
        $firstLocal = 'local:' . Sources::firstKey();
        return array_map(static function (array $site) use ($firstLocal): array {
            $site += self::BLANK;
            if ($site['source'] === 'local' || $site['source'] === '') {
                $site['source'] = $firstLocal; // saved before there were several locals
            }
            return $site;
        }, $sites);
    }

    public static function isSaved(): bool
    {
        return JsonStore::exists('sites');
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function blank(): array
    {
        return ['source' => 'local:' . Sources::firstKey()] + self::BLANK;
    }

    public static function transport(array $site): Transport
    {
        return $site['type'] === 'folder' ? new FolderTransport($site['path']) : new FtpTransport($site);
    }

    /** The local at the start of this site's pipeline. */
    public static function rootSource(array $site): array
    {
        $sites = self::all();
        $seen = [];
        $ref = $site['source'];
        while (str_starts_with($ref, 'site:')) {
            $key = substr($ref, 5);
            if (!isset($sites[$key]) || isset($seen[$key])) {
                throw new \RuntimeException("{$site['name']} is not connected to a local. Choose where it gets its code from.");
            }
            $seen[$key] = true;
            $ref = $sites[$key]['source'];
        }
        return Sources::get(substr($ref, 6))
            ?? throw new \RuntimeException("{$site['name']} is not connected to a local. Choose where it gets its code from.");
    }

    /** For a folder site inside a git checkout: the checkout's top folder, else null. */
    public static function gitCheckoutRoot(array $site): ?string
    {
        if ($site['type'] !== 'folder' || $site['path'] === '' || !is_dir($site['path'])) {
            return null;
        }
        $dir = realpath($site['path']);
        while (is_string($dir) && $dir !== '') {
            if (file_exists($dir . DIRECTORY_SEPARATOR . '.git')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return null;
    }

    /** True when a folder site is inside the very repository its pipeline deploys from. */
    public static function isInsideOwnLocal(array $site): bool
    {
        $checkout = self::gitCheckoutRoot($site);
        if ($checkout === null) {
            return false;
        }
        try {
            $repo = realpath(self::rootSource($site)['repo_path']);
        } catch (\RuntimeException) {
            return false;
        }
        return is_string($repo) && strcasecmp($repo, $checkout) === 0;
    }

    /** Why deploying into this folder site will confuse git, or null when it won't. */
    public static function checkoutWarning(array $site): ?string
    {
        $checkout = self::gitCheckoutRoot($site);
        return $checkout === null ? null : "$checkout is a git checkout. The tool copies files into it without using git, "
            . 'so they show up there as uncommitted changes and can block git pull. For testing, use a plain copy of the files instead.';
    }

    /**
     * For a folder site that is a git checkout: what git there thinks versus what was deployed,
     * and the command that lines git up with the deployed commit without touching any file.
     *
     * @return array{checkout: string, head: ?string, recorded: ?string, command: ?string, note: string}|null
     */
    public static function checkoutStatus(array $site): ?array
    {
        $checkout = self::gitCheckoutRoot($site);
        if ($checkout === null) {
            return null;
        }
        $head = (new Git($checkout, Settings::gitBinary()))->resolve('HEAD');
        try {
            $recorded = ServerState::read(self::transport($site))['commit'] ?? null;
        } catch (\RuntimeException) {
            $recorded = null;
        }

        $command = null;
        if ($recorded === null) {
            $note = 'Nothing has been deployed here yet, so there is nothing to fix.';
        } elseif ($recorded === $head) {
            $note = 'Git here is already on the deployed commit ' . short_sha($recorded) . '. Nothing to fix.';
        } elseif (strcasecmp((string) realpath($site['path']), $checkout) !== 0) {
            $note = "The site is a subfolder of the checkout $checkout, so git there can't simply be moved to the deployed commit. Use a plain copy of the files.";
        } else {
            $dir = str_replace('\\', '/', $checkout);
            $command = sprintf('git -C "%s" fetch && git -C "%s" reset %s', $dir, $dir, $recorded);
            $note = 'Git here is on ' . short_sha($head) . ' but the files are from the deployed commit ' . short_sha($recorded)
                . '. This command moves git to that commit and keeps every file as it is. Run it in Git Bash.'
                . ' If git says the commit is unknown, push it from your local first.';
        }
        return ['checkout' => $checkout, 'head' => $head, 'recorded' => $recorded, 'command' => $command, 'note' => $note];
    }

    /** Git for the repository at the start of this site's pipeline. */
    public static function git(array $site): Git
    {
        return Sources::git(self::rootSource($site));
    }

    public static function sourceLabel(array $site): string
    {
        if (str_starts_with($site['source'], 'local:')) {
            $source = Sources::get(substr($site['source'], 6));
            return $source !== null ? 'Local ' . Sources::label($source) : 'missing local';
        }
        return self::get(substr($site['source'], 5))['name'] ?? 'missing site';
    }

    /** Choices for "Gets code from": every local, and every site that wouldn't make a loop. */
    public static function sourceOptions(?string $siteKey): array
    {
        $options = [];
        foreach (Sources::all() as $key => $source) {
            $options['local:' . $key] = 'Local · ' . Sources::label($source);
        }
        $excluded = $siteKey === null ? [] : self::subtree($siteKey);
        foreach (self::all() as $key => $site) {
            if (!in_array($key, $excluded, true)) {
                $options['site:' . $key] = $site['name'];
            }
        }
        return $options;
    }

    /**
     * Pipelines for the dashboard, per local: rows of connected sites. A site's first
     * visible follower continues its row; any other follower starts a new row.
     *
     * @return array{pipelines: array, unconnected: array, hidden: array}
     */
    public static function pipelines(): array
    {
        $sites = self::all();
        $children = [];
        foreach ($sites as $key => $site) {
            $children[$site['source']][] = $key;
        }
        $placed = [];
        $pipelines = [];

        foreach (Sources::all() as $sourceKey => $source) {
            $rows = [];
            $build = static function (string $key, int $row) use (&$build, &$rows, &$placed, $children, $sites): void {
                if (isset($placed[$key])) {
                    return;
                }
                $placed[$key] = true;
                $site = $sites[$key];
                if (!$site['hidden']) {
                    $rows[$row]['sites'][] = $site;
                }
                foreach ($children['site:' . $key] ?? [] as $index => $child) {
                    if ($index === 0 && !$site['hidden']) {
                        $build($child, $row);
                    } else {
                        $rows[] = ['from' => $site['name'] . ($site['hidden'] ? ' (hidden)' : ''), 'sites' => []];
                        $build($child, array_key_last($rows));
                    }
                }
            };
            foreach ($children['local:' . $sourceKey] ?? [] as $head) {
                $rows[] = ['from' => null, 'sites' => []];
                $build($head, array_key_last($rows));
            }
            $pipelines[] = [
                'source' => $source,
                'rows' => array_values(array_filter($rows, static fn (array $row): bool => $row['sites'] !== [])),
            ];
        }

        return [
            'pipelines' => $pipelines,
            'unconnected' => array_values(array_filter($sites, static fn (array $site): bool => !isset($placed[$site['key']]) && !$site['hidden'])),
            'hidden' => array_values(array_filter($sites, static fn (array $site): bool => $site['hidden'])),
        ];
    }

    /** Sites in pipeline order (for lists): every site comes after the site it gets code from. */
    public static function ordered(): array
    {
        $sites = self::all();
        $ordered = [];
        $add = static function (string $ref) use (&$add, &$ordered, $sites): void {
            foreach ($sites as $key => $site) {
                if ($site['source'] === $ref && !isset($ordered[$key])) {
                    $ordered[$key] = $site;
                    $add('site:' . $key);
                }
            }
        };
        foreach (array_keys(Sources::all()) as $sourceKey) {
            $add('local:' . $sourceKey);
        }
        return $ordered + $sites;
    }

    /** Changes where a site gets its code from (dashboard dropdown). Returns an error or null. */
    public static function setSource(string $key, string $source): ?string
    {
        $sites = self::all();
        if (!isset($sites[$key])) {
            return 'Site not found.';
        }
        if (!self::isValidSource($sites, $source, $key)) {
            return 'That choice would not connect the site to a local.';
        }
        if (RunStore::activeRunFor($key) !== null) {
            return "{$sites[$key]['name']} has an open run. Finish or cancel it first.";
        }
        $sites[$key]['source'] = $source;
        JsonStore::write('sites', $sites);
        return null;
    }

    public static function setHidden(string $key, bool $hidden): void
    {
        $sites = self::all();
        if (isset($sites[$key])) {
            $sites[$key]['hidden'] = $hidden;
            JsonStore::write('sites', $sites);
        }
    }

    /**
     * Validates and saves a site. $originalKey is null when adding.
     * Returns [errors, site]; nothing is saved when there are errors.
     */
    public static function save(array $input, ?string $originalKey): array
    {
        $sites = self::all();
        $existing = $originalKey !== null ? ($sites[$originalKey] ?? null) : null;
        if ($originalKey !== null && $existing === null) {
            return [['name' => 'This site no longer exists.'], self::blank()];
        }

        $site = [
            'key' => $originalKey ?? strtolower(trim((string) ($input['key'] ?? ''))),
            'name' => trim((string) ($input['name'] ?? '')),
            'type' => ($input['type'] ?? '') === 'folder' ? 'folder' : 'ftp',
            'source' => (string) ($input['source'] ?? ''),
            'production' => !empty($input['production']),
            'hidden' => $existing['hidden'] ?? false,
            'host' => trim((string) ($input['host'] ?? '')),
            'port' => (int) ($input['port'] ?? 21),
            'username' => trim((string) ($input['username'] ?? '')),
            'password' => (string) ($input['password'] ?? ''),
            'root' => rtrim('/' . trim(trim((string) ($input['root'] ?? '')), '/'), '/') . '/',
            'verify_tls' => !empty($input['verify_tls']),
            'path' => rtrim(trim((string) ($input['path'] ?? '')), '\\/'),
            'url' => rtrim(trim((string) ($input['url'] ?? '')), '/'),
            'check_path' => trim((string) ($input['check_path'] ?? '')),
        ];
        if ($site['password'] === '' && $existing !== null) {
            $site['password'] = $existing['password'];
        }

        $errors = [];
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $site['key'])) {
            $errors['key'] = 'Use 2 to 40 lowercase letters, numbers or dashes.';
        } elseif ($originalKey === null && isset($sites[$site['key']])) {
            $errors['key'] = 'Another site already uses this key.';
        }
        if ($site['name'] === '') {
            $errors['name'] = 'Enter a name.';
        }
        if (!self::isValidSource($sites, $site['source'], $site['key'])) {
            $errors['source'] = 'Choose where this site gets its code from.';
        } elseif ($existing !== null && $existing['source'] !== $site['source'] && RunStore::activeRunFor($site['key']) !== null) {
            $errors['source'] = 'This site has an open run. Finish it before changing where it gets code from.';
        }

        if ($site['type'] === 'folder') {
            if ($site['path'] === '' || !is_dir($site['path'])) {
                $errors['path'] = 'Folder not found.';
            } elseif (!isset($errors['source']) && self::isInsideOwnLocal($site)) {
                $errors['path'] = 'This folder is inside the repository this site gets its code from. Deploying here would overwrite your working files. Use a separate copy.';
            }
        } else {
            if (!preg_match('/^[a-z0-9.-]+$/i', $site['host'])) {
                $errors['host'] = 'Enter the FTP host name or IP address.';
            }
            if ($site['port'] < 1 || $site['port'] > 65535) {
                $errors['port'] = 'Enter a port between 1 and 65535.';
            }
            if ($site['username'] === '') {
                $errors['username'] = 'Enter the FTP username.';
            }
            if ($site['password'] === '') {
                $errors['password'] = 'Enter the FTP password.';
            }
            if (str_contains($site['root'], '..')) {
                $errors['root'] = 'The folder cannot contain "..".';
            }
        }

        if ($site['url'] !== '' && (!filter_var($site['url'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#', $site['url']))) {
            $errors['url'] = 'Enter the site address, starting with https://';
        } elseif ($site['url'] === '' && $site['type'] === 'ftp') {
            $errors['url'] = 'Enter the site address, starting with https://';
        }
        if ($site['check_path'] !== '' && !str_starts_with($site['check_path'], '/')) {
            $errors['check_path'] = 'Start the page with /, for example /admin/authentication';
        }

        if (!$errors) {
            $sites[$site['key']] = $site;
            JsonStore::write('sites', $sites);
        }
        return [$errors, $site];
    }

    /** Returns an error message, or null when deleted. */
    public static function delete(string $key): ?string
    {
        $sites = self::all();
        foreach ($sites as $other) {
            if ($other['source'] === 'site:' . $key) {
                return "{$other['name']} gets its code from this site. Change that first.";
            }
        }
        if (RunStore::activeRunFor($key) !== null) {
            return 'This site has an open run. Finish or cancel it first.';
        }
        unset($sites[$key]);
        JsonStore::write('sites', $sites);
        return null;
    }

    /** The site and every site that gets code from it, directly or further down. */
    private static function subtree(string $key): array
    {
        $keys = [$key];
        for ($i = 0; $i < count($keys); $i++) {
            foreach (self::all() as $otherKey => $other) {
                if ($other['source'] === 'site:' . $keys[$i] && !in_array($otherKey, $keys, true)) {
                    $keys[] = $otherKey;
                }
            }
        }
        return $keys;
    }

    /** The source must lead, without a loop, to an existing local. */
    private static function isValidSource(array $sites, string $source, string $selfKey): bool
    {
        $seen = [];
        while (str_starts_with($source, 'site:')) {
            $key = substr($source, 5);
            if ($key === $selfKey || !isset($sites[$key]) || isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
            $source = $sites[$key]['source'];
        }
        return str_starts_with($source, 'local:') && Sources::get(substr($source, 6)) !== null;
    }
}
