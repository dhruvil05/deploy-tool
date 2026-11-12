<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * What a reviewer should look at first (speed-ups "Review insights" and "Spot database
 * changes"): lines added and removed per file and per folder, files that are risky to change
 * on a live site, and files that mean database changes.
 */
final class ReviewInsights
{
    /** pattern => why the file deserves a look. First match wins. */
    private const RISKY = [
        '#(^|/)\.htaccess$#i' => 'server rules',
        '#(^|/)(\.env[^/]*|app-config\.php|config\.php|database\.php|constants\.php)$#i' => 'configuration',
        '#(^|/)config/[^/]+\.php$#i' => 'configuration',
        '#(^|/)(composer\.(json|lock)|package(-lock)?\.json)$#i' => 'dependencies',
        '#(^|/)routes?(\.php|/)#i' => 'routes',
        '#(^|/)index\.php$#i' => 'entry point',
        '#(^|/)(hooks|core)/[^/]+\.php$#i' => 'runs on every page',
    ];

    /** pattern => what it means. CodeIgniter / Perfex migrations, SQL files, module installers. */
    private const DATABASE = [
        '#\.sql$#i' => 'SQL file',
        '#(^|/)migrations?/#i' => 'migration',
        '#(^|/)config/migration\.php$#i' => 'migration version',
        '#^modules/[^/]+/install\.php$#i' => 'module installer (runs when the module is activated)',
        '#(^|/)database/(schema|seeds?|updates?)/#i' => 'database script',
    ];

    /** pattern => what it is: debug code that shouldn't reach a live site (in added lines only). */
    private const DEBUG = [
        '/\bvar_dump\s*\(/' => 'var_dump()',
        '/\bprint_r\s*\((?![^;]*,\s*true\s*\))/i' => 'print_r()',
        '/(?<![\w>:$])dd\s*\(/' => 'dd()',
        '/\bdie\s*\(\s*(?![\'"]No direct script)/' => 'die()',
        '/\bconsole\.(log|debug|dir)\s*\(/' => 'console.log()',
        '/(?<![\w.])debugger\s*;/' => 'debugger;',
        '/\bphpinfo\s*\(/' => 'phpinfo()',
    ];

    /**
     * Speed-up "Flag leftover debug code": debug calls in the lines this deploy adds.
     * Only deploys (a match has no commit before it to compare with).
     *
     * @return array<string, list<array{0: int, 1: string, 2: string}>> path => [line, what, the line]
     */
    public static function debugCode(array $run): array
    {
        if (!Settings::on('feature_debug_flags') || $run['type'] !== 'deploy' || $run['from'] === null || $run['to'] === null || $run['from'] === $run['to']) {
            return [];
        }
        $paths = array_column(array_filter($run['files'], static fn (array $f): bool => $f['action'] === 'upload'
            && preg_match('/\.(php|phtml|js|ts|vue)$/i', $f['path']) && !preg_match('/\.min\.js$/i', $f['path'])), 'path');
        if ($paths === []) {
            return [];
        }
        try {
            $git = Sources::git(RunStore::source($run));
            $text = $git->okRaw(array_merge(['diff', '-U0', '--no-color', '--no-ext-diff', '--no-renames', $run['from'], $run['to'], '--'], array_slice($paths, 0, 400)));
        } catch (\RuntimeException) {
            return [];
        }
        $found = [];
        $path = null;
        $line = 0;
        foreach (explode("\n", $text) as $raw) {
            if (str_starts_with($raw, '+++ ')) {
                $path = str_starts_with($raw, '+++ b/') ? substr($raw, 6) : null;
            } elseif (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)/', $raw, $m)) {
                $line = (int) $m[1];
            } elseif ($path !== null && str_starts_with($raw, '+')) {
                $code = substr($raw, 1);
                $trimmed = ltrim($code);
                $isComment = str_starts_with($trimmed, '//') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*');
                foreach (self::DEBUG as $pattern => $what) {
                    if (!$isComment && preg_match($pattern, $code) && count($found[$path] ?? []) < 5) {
                        $found[$path][] = [$line, $what, mb_substr(trim($code), 0, 140)];
                        break;
                    }
                }
                $line++;
            }
        }
        return $found;
    }

    /** @return array<string, string> path => why it is risky */
    public static function riskyFiles(array $paths): array
    {
        return self::match($paths, self::RISKY);
    }

    /** Changed lines that create, alter or drop tables, written inside PHP (Perfex models, helpers). */
    private const TABLE_CODE = '((ALTER|CREATE|DROP|RENAME|TRUNCATE) +TABLE|(alter|create|drop|rename|truncate) +table|dbforge->|add_column\()';

    /** @return array<string, string> path => what kind of database change, by file name only */
    public static function databaseFiles(array $paths): array
    {
        return self::match($paths, self::DATABASE);
    }

    /**
     * Database changes between two commits: files named like migrations or SQL, and files
     * whose changed lines create, alter or drop tables (git diff -G, names only). Without
     * $from (a match) only the names of $paths are checked.
     *
     * @return array<string, string> path => what kind of database change
     */
    public static function databaseChanges(?Git $git, ?string $from, ?string $to, array $paths): array
    {
        $found = self::databaseFiles($paths);
        if ($git !== null && $from !== null && $to !== null && $from !== $to) {
            try {
                // -G picks the files whose added or removed lines have table code; only added
                // lines count (a deploy that removes an ALTER TABLE changes no table).
                $diff = $git->okRaw(['diff', '-U0', '--no-color', '--no-renames', '--no-ext-diff', '--src-prefix=a/', '--dst-prefix=b/', '-G', self::TABLE_CODE, $from, $to]);
                $path = null;
                foreach (preg_split('/\r?\n/', $diff) ?: [] as $line) {
                    if (str_starts_with($line, '+++ ')) {
                        $path = str_starts_with($line, '+++ b/') ? substr($line, 6) : null;
                    } elseif ($path !== null && str_starts_with($line, '+') && in_array($path, $paths, true)
                        && preg_match('/' . self::TABLE_CODE . '/', substr($line, 1))) {
                        $found[$path] ??= 'changes database tables in the code (ALTER / CREATE TABLE…)';
                    }
                }
            } catch (\RuntimeException) {
                // the file names above still count
            }
        }
        return $found;
    }

    /** databaseChanges() for a run's uploads. */
    public static function databaseChangesOf(array $run): array
    {
        $paths = array_column(array_filter($run['files'], static fn (array $f): bool => $f['action'] !== 'delete'), 'path');
        $git = null;
        try {
            $git = Sources::git(RunStore::source($run));
        } catch (\RuntimeException) {
            // names only
        }
        return self::databaseChanges($git, $run['type'] === 'deploy' ? $run['from'] : null, $run['to'], $paths);
    }

    /**
     * Lines added and removed per file between the server's commit and the new one, from one
     * git call. Only for deploys (a match compares with the server's own files, not a commit).
     *
     * @return array<string, array{0: ?int, 1: ?int}> path => [added, removed]; null for binary files
     */
    public static function lineStats(array $run): array
    {
        if ($run['type'] !== 'deploy' || $run['from'] === null || $run['to'] === null || $run['from'] === $run['to']) {
            return [];
        }
        try {
            return Sources::git(RunStore::source($run))->numstat($run['from'], $run['to']);
        } catch (\RuntimeException) {
            return [];
        }
    }

    /**
     * The files grouped by folder (two levels deep), biggest change first.
     *
     * @return array<string, array{files: int, added: int, removed: int}>
     */
    public static function folders(array $paths, array $stats): array
    {
        $folders = [];
        foreach ($paths as $path) {
            $parts = explode('/', $path);
            $folder = count($parts) > 1 ? implode('/', array_slice($parts, 0, min(2, count($parts) - 1))) . '/' : '(top folder)';
            $folders[$folder] ??= ['files' => 0, 'added' => 0, 'removed' => 0];
            $folders[$folder]['files']++;
            $folders[$folder]['added'] += (int) ($stats[$path][0] ?? 0);
            $folders[$folder]['removed'] += (int) ($stats[$path][1] ?? 0);
        }
        uasort($folders, static fn (array $a, array $b): int => [$b['added'] + $b['removed'], $b['files']] <=> [$a['added'] + $a['removed'], $a['files']]);
        return $folders;
    }

    private static function match(array $paths, array $rules): array
    {
        $found = [];
        foreach ($paths as $path) {
            foreach ($rules as $pattern => $why) {
                if (preg_match($pattern, (string) $path)) {
                    $found[(string) $path] = $why;
                    break;
                }
            }
        }
        return $found;
    }
}
