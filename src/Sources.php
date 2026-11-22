<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Locals: the starts of pipelines. Each is a git repository on this computer and the
 * branch its first sites get. Sites refer to one as 'local:<key>'.
 */
final class Sources
{
    private const BLANK = [
        'key' => '',
        'name' => '',
        'repo_path' => '',
        'branch' => '',
        'remote' => 'origin',
        'extra_protected' => '',
        'ai_notes' => '',
        // Speed-up "Locals from a Git address": the address, and a token for a private one (kept encrypted).
        'repo_url' => '',
        'repo_token' => '',
    ];

    /** Where the tool keeps its own copy of a local from a Git address. */
    public static function managedPath(string $key): string
    {
        return STORAGE_DIR . '/repos/' . $key;
    }

    /** Whether a local is the tool's own copy of a Git address (no folder of yours). */
    public static function isManaged(array $source): bool
    {
        return trim((string) ($source['repo_url'] ?? '')) !== '';
    }

    private static function write(array $sources): void
    {
        JsonStore::write('sources', array_map(static fn (array $s): array => Secrets::sealFields($s, ['repo_token']), $sources));
    }

    /**
     * Fetches a local's remote. For the tool's own copy of a Git address it also moves the
     * branch forward to GitHub's, the same safe way as Pull (fast-forward only, stopped by
     * malware markers), since nobody works in that copy. Returns a note for the page.
     */
    public static function fetch(array $source): string
    {
        $git = self::git($source);
        $git->fetch($source['remote']);
        if (!self::isManaged($source)) {
            return "Fetched the latest commits for {$source['name']}. Your branches and files were not changed.";
        }
        $branch = $source['branch'];
        $remoteSha = $git->resolve("refs/remotes/{$source['remote']}/$branch");
        $local = $git->resolve($branch);
        if ($remoteSha === null || $remoteSha === $local) {
            return "Fetched {$source['name']}: up to date.";
        }
        if ($local !== null && !$git->isAncestor($local, $remoteSha)) {
            $git->ok(['update-ref', "refs/heads/$branch", $remoteSha]); // force-pushed upstream: follow it (nothing of yours is in this copy)
        } elseif (($findings = Safety::malwareFindings($git, $remoteSha)) !== []) {
            throw new \RuntimeException("Fetched, but {$source['name']} stays on " . substr((string) $local, 0, 8) . ': GitHub\'s ' . $branch . ' has malware markers (' . implode('; ', $findings) . ').');
        } else {
            $git->ok(['update-ref', "refs/heads/$branch", $remoteSha]);
        }
        return "Fetched {$source['name']}: now at " . substr($remoteSha, 0, 8) . ' ' . $git->commitInfo($remoteSha)['subject'] . '.';
    }

    /** Makes the tool's copy of a Git address (a bare clone that keeps GitHub's branches as origin/*). */
    private static function cloneManaged(array $source): void
    {
        $path = self::managedPath($source['key']);
        if (is_dir($path . '/objects')) {
            return;
        }
        @mkdir(dirname($path), 0700, true);
        $parent = new Git(dirname($path), Settings::gitBinary(), self::credentials($source));
        [$code, , $stderr] = $parent->run(['clone', '--bare', '--quiet', '--', $source['repo_url'], basename($path)]);
        if ($code !== 0) {
            self::removeFolder($path);
            $why = str_contains($stderr, 'Authentication') || str_contains($stderr, 'could not read Username') || str_contains($stderr, 'Repository not found')
                ? 'Git could not get in: check the address, and for a private repository the access token.' : $stderr;
            throw new \RuntimeException('Could not copy the repository: ' . mb_substr($why, 0, 300));
        }
        $git = self::git($source + ['repo_path' => $path]);
        $git->ok(['config', 'remote.origin.fetch', '+refs/heads/*:refs/remotes/origin/*']);
        $git->fetch('origin');
    }

    /** Environment for git with the local's access token: an HTTP header, never in the address or the command line. */
    private static function credentials(array $source): array
    {
        $token = (string) ($source['repo_token'] ?? '');
        if ($token === '') {
            return [];
        }
        $host = strtolower((string) parse_url((string) $source['repo_url'], PHP_URL_HOST));
        $user = str_contains($host, 'gitlab') ? 'oauth2' : (str_contains($host, 'bitbucket') ? 'x-token-auth' : 'x-access-token');
        return [
            'GIT_CONFIG_COUNT' => '2',
            'GIT_CONFIG_KEY_0' => 'http.extraHeader',
            'GIT_CONFIG_VALUE_0' => 'Authorization: Basic ' . base64_encode("$user:$token"),
            'GIT_CONFIG_KEY_1' => 'credential.helper',
            'GIT_CONFIG_VALUE_1' => '',
        ];
    }

    private static function removeFolder(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            @chmod($item->getPathname(), 0666);
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    public static function all(): array
    {
        if (JsonStore::exists('sources')) {
            $sources = JsonStore::read('sources');
        } else {
            // Before locals existed, the one repository lived in settings.
            $legacy = Settings::legacy();
            $sources = ['backoffice' => [
                'key' => 'backoffice',
                'name' => 'Backoffice',
                'repo_path' => $legacy['repo_path'] ?? 'C:\\laragon\\www\\backoffice-bkp',
                'branch' => $legacy['branch'] ?? 'staging-latest',
                'remote' => $legacy['remote'] ?? 'origin',
                'extra_protected' => $legacy['extra_protected'] ?? '',
            ]];
        }
        return array_map(static fn (array $source): array => Secrets::openFields($source + self::BLANK, ['repo_token']), $sources);
    }

    public static function isSaved(): bool
    {
        return JsonStore::exists('sources');
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function firstKey(): string
    {
        return (string) array_key_first(self::all());
    }

    public static function blank(): array
    {
        return self::BLANK;
    }

    public static function label(array $source): string
    {
        return $source['name'] . ' (' . $source['branch'] . ')';
    }

    public static function git(array $source): Git
    {
        return new Git($source['repo_path'], Settings::gitBinary(), self::isManaged($source) ? self::credentials($source) : []);
    }

    /**
     * Validates and saves a local. $originalKey is null when adding.
     * Returns [errors, source]; nothing is saved when there are errors.
     */
    public static function save(array $input, ?string $originalKey): array
    {
        $sources = self::all();
        if ($originalKey !== null && !isset($sources[$originalKey])) {
            return [['name' => 'This local no longer exists.'], self::BLANK];
        }
        $source = [
            'key' => $originalKey ?? strtolower(trim((string) ($input['key'] ?? ''))),
            'name' => trim((string) ($input['name'] ?? '')),
            'repo_path' => rtrim(trim((string) ($input['repo_path'] ?? '')), '\\/'),
            'branch' => trim((string) ($input['branch'] ?? '')),
            'remote' => trim((string) ($input['remote'] ?? '')),
            'extra_protected' => implode("\n", array_filter(array_map(
                [ProtectedPaths::class, 'normalize'],
                preg_split('/\r?\n/', (string) ($input['extra_protected'] ?? ''))
            ))),
            'ai_notes' => trim(str_replace("\r\n", "\n", (string) ($input['ai_notes'] ?? ''))),
            'repo_url' => '',
            'repo_token' => '',
        ];
        if (strlen($source['ai_notes']) > 2000) {
            return [['ai_notes' => 'Keep the notes under 2,000 characters.'], $source];
        }

        // Speed-up "Locals from a Git address": the tool's own copy instead of a folder of yours.
        $existing = $originalKey !== null ? $sources[$originalKey] : null;
        // No choice sent (the assistant's edit_local): a local from a Git address stays one.
        $fromAddress = ($input['repo_kind'] ?? null) === 'url' || (!isset($input['repo_kind']) && $existing !== null && self::isManaged($existing));
        if ($fromAddress && !Settings::on('feature_remote_locals') && ($existing === null || !self::isManaged($existing))) {
            $fromAddress = false; // new ones only while the speed-up is on; existing ones keep working
        }
        if ($fromAddress) {
            $source['repo_url'] = trim((string) ($input['repo_url'] ?? $existing['repo_url'] ?? ''));
            $source['repo_token'] = !empty($input['repo_token_remove']) ? '' : (trim((string) ($input['repo_token'] ?? '')) ?: (string) ($existing['repo_token'] ?? ''));
            $source['repo_path'] = self::managedPath($source['key']);
            $source['remote'] = 'origin';
            if (!preg_match('#^(https://[\w.-]+(:\d+)?/[\w./~-]+|git@[\w.-]+:[\w./~-]+|ssh://[\w.@-]+(:\d+)?/[\w./~-]+|file:///[\w:./~ -]+)$#', $source['repo_url'])) {
                return [['repo_url' => 'Enter the repository address, like https://github.com/you/project.git or git@github.com:you/project.git'], $source];
            }
            if ($source['repo_token'] !== '' && !str_starts_with($source['repo_url'], 'https://')) {
                return [['repo_token' => 'A token works with https:// addresses; git@ addresses use your SSH key instead.'], $source];
            }
            if (preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $source['key'])) {
                try {
                    if ($existing !== null && ($existing['repo_url'] ?? '') !== $source['repo_url']) {
                        self::removeFolder(self::managedPath($source['key'])); // another repository: copy it afresh
                    }
                    self::cloneManaged($source);
                } catch (\RuntimeException $e) {
                    return [['repo_url' => $e->getMessage()], $source];
                }
            }
        }

        $errors = [];
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $source['key'])) {
            $errors['key'] = 'Use 2 to 40 lowercase letters, numbers or dashes.';
        } elseif ($originalKey === null && isset($sources[$source['key']])) {
            $errors['key'] = 'Another local already uses this key.';
        }
        if ($source['name'] === '') {
            $errors['name'] = 'Enter a name.';
        }
        if ($source['repo_path'] === '' || !is_dir($source['repo_path'])) {
            $errors['repo_path'] = 'Folder not found.';
        }
        if (!preg_match('#^[\w./-]+$#', $source['branch'])) {
            $errors['branch'] = 'Enter a branch name.';
        }
        if (!preg_match('/^[\w.-]+$/', $source['remote'])) {
            $errors['remote'] = 'Enter a remote name, usually origin.';
        }
        if (str_contains($source['extra_protected'], '..')) {
            $errors['extra_protected'] = 'Paths cannot contain "..".';
        }
        if (!isset($errors['repo_path'])) {
            $git = self::git($source);
            if (!$git->isRepo()) {
                $errors['repo_path'] = 'This folder is not a git repository.';
            } elseif (!isset($errors['branch']) && $git->resolve($source['branch']) === null) {
                $errors['branch'] = 'This branch does not exist in that repository.';
            }
        }

        if (!$errors) {
            $sources[$source['key']] = $source;
            self::write($sources);
        }
        return [$errors, $source];
    }

    /** Adds one rule to a local's own protected paths (no change when it's already there). */
    public static function addProtected(string $key, string $rule): void
    {
        $sources = self::all();
        $rule = ProtectedPaths::normalize($rule);
        if (!isset($sources[$key])) {
            throw new \RuntimeException('This local no longer exists.');
        }
        if ($rule === '' || str_contains($rule, '..') || str_contains($rule, "\n")) {
            throw new \RuntimeException('That is not a path the tool can protect.');
        }
        if (in_array($rule, ProtectedPaths::rules($sources[$key]), true)) {
            return;
        }
        $sources[$key]['extra_protected'] = trim($sources[$key]['extra_protected'] . "\n" . $rule);
        self::write($sources);
    }

    /** Returns an error message, or null when deleted. */
    public static function delete(string $key): ?string
    {
        $sources = self::all();
        if (count($sources) === 1) {
            return 'Keep at least one local.';
        }
        foreach (Sites::all() as $site) {
            if ($site['source'] === 'local:' . $key) {
                return "{$site['name']} gets its code from this local. Change that first.";
            }
        }
        unset($sources[$key]);
        self::write($sources);
        return null;
    }
}
