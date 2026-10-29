<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Paths the tool never uploads, overwrites, deletes or reads on a server.
 * A rule ending in "/" covers a whole folder; "*" stands for one folder name.
 * Every site gets the list from Settings; each local can add its own rules on top.
 */
final class ProtectedPaths
{
    /** The tool's own record on the server (the deployed commit): always protected, whatever the settings say. */
    public const LOCKED = ['application/.deploy/'];

    /** Protected on every site until changed in Settings. */
    public const DEFAULTS = [
        'uploads/',
        'modules/*/uploads/',
        'media/',
        'backups/',
        'temp/',
        'application/config/app-config.php',
        'application/logs/',
        'application/cache/',
        '.htaccess',
        'deployment/',
        '.git/',
        '.claude/',
    ];

    /** Protected on every site: the locked rules and the list in Settings. */
    public static function global(): array
    {
        return array_values(array_unique(array_merge(self::LOCKED, self::parse(Settings::get('protected_paths')))));
    }

    public static function rules(array $source): array
    {
        return array_values(array_unique(array_merge(self::global(), self::parse((string) $source['extra_protected']))));
    }

    /** One rule per line, cleaned; empty lines dropped. */
    public static function parse(string $text): array
    {
        return array_values(array_filter(array_map([self::class, 'normalize'], preg_split('/\r?\n/', $text)), 'strlen'));
    }

    /** @return string[] one regex per rule (case-insensitive, to protect more rather than less) */
    public static function regexes(array $source): array
    {
        return array_map([self::class, 'regex'], self::rules($source));
    }

    public static function regex(string $rule): string
    {
        $pattern = str_replace('\*', '[^/]+', preg_quote($rule, '#'));
        return '#^' . $pattern . (str_ends_with($rule, '/') ? '' : '$') . '#i';
    }

    /** Cleans one typed rule the way a saved local stores it: forward slashes, no leading slash. */
    public static function normalize(string $rule): string
    {
        return ltrim(trim(str_replace('\\', '/', $rule)), '/');
    }

    /** For folders, pass the path with a trailing "/". */
    public static function matches(string $path, array $regexes): bool
    {
        foreach ($regexes as $regex) {
            if (preg_match($regex, $path)) {
                return true;
            }
        }
        return false;
    }
}
