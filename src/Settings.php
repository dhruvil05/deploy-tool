<?php

declare(strict_types=1);

namespace DeployTool;

/** Tool settings: who is deploying, and which git and PHP programs to use. Repositories are locals (Sources). */
final class Settings
{
    private const DEFAULTS = [
        'deployer_name' => '',
        'git_path' => '',
        'php_path' => '',
    ];

    private const LARAGON_GIT = 'C:\\laragon\\bin\\git\\bin\\git.exe';

    public static function all(): array
    {
        return array_merge(self::DEFAULTS, array_intersect_key(JsonStore::read('settings'), self::DEFAULTS));
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

    /** Validates and saves. Returns [field => error]; nothing is saved when there are errors. */
    public static function save(array $input): array
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $values[$key] = trim((string) ($input[$key] ?? ''));
        }

        $errors = [];
        if ($values['deployer_name'] === '') {
            $errors['deployer_name'] = 'Enter your name. It is recorded on the server with every deploy.';
        }
        foreach (['git_path', 'php_path'] as $key) {
            if ($values[$key] !== '' && !is_file($values[$key])) {
                $errors[$key] = 'File not found.';
            }
        }

        if (!$errors) {
            JsonStore::write('settings', $values + self::legacy());
        }
        return $errors;
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
