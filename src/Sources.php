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
    ];

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
        return array_map(static fn (array $source): array => $source + self::BLANK, $sources);
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
        return new Git($source['repo_path'], Settings::gitBinary());
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
        ];

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
            JsonStore::write('sources', $sources);
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
        JsonStore::write('sources', $sources);
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
        JsonStore::write('sources', $sources);
        return null;
    }
}
