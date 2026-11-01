<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Tool settings: who is deploying, which git, PHP and ssh programs to use, how runs behave,
 * and the paths protected on every site. Repositories are locals (Sources).
 */
final class Settings
{
    private const LARAGON_GIT = 'C:\\laragon\\bin\\git\\bin\\git.exe';
    private const WINDOWS_SSH = 'C:\\Windows\\System32\\OpenSSH\\ssh.exe';

    /** Choices for the default compare method: method => label. */
    public const COMPARE_METHODS = ['fingerprint' => 'Fingerprint file', 'sizes' => 'File sizes over FTP'];

    /** The Settings page's sections, in menu order. */
    public const TABS = ['general', 'deploys', 'protected', 'programs', 'ai', 'permissions', 'cloud', 'storage'];

    /** The Settings section a form field is in, to open it when that field has a mistake. */
    public static function tabOf(string $field): string
    {
        return match (true) {
            str_starts_with($field, 'ai_perm_') => 'permissions',
            str_starts_with($field, 'cloud_') => 'cloud',
            str_starts_with($field, 'ai_') => 'ai',
            $field === 'deployer_name' => 'general',
            in_array($field, ['git_path', 'php_path', 'ssh_path'], true) => 'programs',
            $field === 'protected_paths' => 'protected',
            default => 'deploys',
        };
    }

    public static function defaults(): array
    {
        return [
            'deployer_name' => '',
            'git_path' => '',
            'php_path' => '',
            'ssh_path' => '',
            'review_minutes' => '60',
            'compare_method' => 'fingerprint',
            'background_runs' => '1',
            'ftp_connections' => '4',
            'protected_paths' => implode("\n", ProtectedPaths::DEFAULTS),
        ];
    }

    public static function all(): array
    {
        return array_merge(self::defaults(), array_intersect_key(JsonStore::read('settings'), self::defaults()));
    }

    public static function get(string $key): string
    {
        return (string) self::all()[$key];
    }

    /** Repository settings saved before locals existed (used once, to fill in the first local). */
    public static function legacy(): array
    {
        return array_intersect_key(JsonStore::read('settings'), array_flip(['repo_path', 'branch', 'remote', 'extra_protected']));
    }

    /**
     * Validates and saves. Returns [field => error]; nothing is saved when there are errors.
     * $write false only checks.
     */
    public static function save(array $input, bool $write = true): array
    {
        $values = [];
        foreach (array_keys(self::defaults()) as $key) {
            $values[$key] = trim((string) ($input[$key] ?? ''));
        }
        $values['protected_paths'] = implode("\n", array_unique(ProtectedPaths::parse($values['protected_paths'])));
        $values['background_runs'] = $values['background_runs'] === '1' ? '1' : '0';

        $errors = [];
        if (str_contains($values['protected_paths'], '..')) {
            $errors['protected_paths'] = 'Paths cannot contain "..".';
        }
        if ($values['deployer_name'] === '') {
            $errors['deployer_name'] = 'Enter your name. It is recorded on the server with every deploy.';
        }
        foreach (['git_path', 'php_path', 'ssh_path'] as $key) {
            if ($values[$key] !== '' && !is_file($values[$key])) {
                $errors[$key] = 'File not found.';
            }
        }
        if (!ctype_digit($values['review_minutes']) || (int) $values['review_minutes'] < 5 || (int) $values['review_minutes'] > 720) {
            $errors['review_minutes'] = 'Enter 5 to 720 minutes.';
        }
        if (!isset(self::COMPARE_METHODS[$values['compare_method']])) {
            $errors['compare_method'] = 'Pick a method.';
        }
        if (!ctype_digit($values['ftp_connections']) || (int) $values['ftp_connections'] < 1 || (int) $values['ftp_connections'] > FtpTransport::MAX_CONNECTIONS) {
            $errors['ftp_connections'] = 'Enter 1 to ' . FtpTransport::MAX_CONNECTIONS . '.';
        }

        if (!$errors && $write) {
            JsonStore::write('settings', $values + self::legacy());
        }
        return $errors;
    }

    /** How long a prepared deploy can wait for approval before its backups count as out of date. */
    public static function reviewMinutes(): int
    {
        return max(5, (int) self::get('review_minutes'));
    }

    public static function backgroundRuns(): bool
    {
        return self::get('background_runs') === '1';
    }

    /** The configured git, else Laragon's git, else git from PATH. */
    public static function gitBinary(?string $configured = null): string
    {
        $configured ??= self::get('git_path');
        if ($configured !== '') {
            return $configured;
        }
        return is_file(self::LARAGON_GIT) ? self::LARAGON_GIT : 'git';
    }

    /** The configured ssh, else Windows' own OpenSSH, else ssh from PATH. */
    public static function sshBinary(?string $configured = null): string
    {
        $configured ??= self::get('ssh_path');
        if ($configured !== '') {
            return $configured;
        }
        return is_file(self::WINDOWS_SSH) ? self::WINDOWS_SSH : 'ssh';
    }

    /** The configured PHP, else the PHP running this tool, else php.exe next to the loaded php.ini. */
    public static function phpBinary(?string $configured = null): string
    {
        $configured ??= self::get('php_path');
        if ($configured !== '') {
            return $configured;
        }
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && PHP_BINARY !== '') {
            return PHP_BINARY;
        }
        $ini = php_ini_loaded_file();
        if ($ini && is_file(dirname($ini) . DIRECTORY_SEPARATOR . 'php.exe')) {
            return dirname($ini) . DIRECTORY_SEPARATOR . 'php.exe';
        }
        return 'php';
    }
}
