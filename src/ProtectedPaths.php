<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Paths the tool never uploads, overwrites, deletes or reads on a server.
 * A rule ending in "/" covers a whole folder; "*" stands for one folder name.
 * Each local can add its own rules on top of the built-in ones.
 */
final class ProtectedPaths
{
    /** Always protected, whatever the settings say. */
    public const BUILT_IN = [
        'uploads/',
        'modules/*/uploads/',
        'media/',
        'backups/',
        'temp/',
        'application/config/app-config.php',
        'application/logs/',
        'application/cache/',
        'application/.deploy/',
        '.htaccess',
        'deployment/',
        '.git/',
        '.claude/',
    ];

    public static function rules(array $source): array
    {
        $extra = array_filter(array_map('trim', preg_split('/\r?\n/', (string) $source['extra_protected'])));
        return array_values(array_unique(array_merge(self::BUILT_IN, $extra)));
    }

    /** @return string[] one regex per rule (case-insensitive, to protect more rather than less) */
    public static function regexes(array $source): array
    {
        return array_map(static function (string $rule): string {
            $pattern = str_replace('\*', '[^/]+', preg_quote($rule, '#'));
            return '#^' . $pattern . (str_ends_with($rule, '/') ? '' : '$') . '#i';
        }, self::rules($source));
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
