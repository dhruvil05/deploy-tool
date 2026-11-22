<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Checks a commit for the npm malware force-pushed to GitHub (October and November 2026,
 * Trojan:NPM/PolinRider): code hidden in JS config files, pushed off-screen by thousands of
 * tabs or squeezed into one long obfuscated line, and package.json scripts that run it
 * ("node init", "node api.js") before every build.
 *
 * Only file names and match counts are read (git grep -c, ls-tree), never the file contents,
 * so the malware is never written to a temporary file on this computer, where Microsoft
 * Defender would flag and quarantine it.
 */
final class Safety
{
    /** The files the malware hides in: root JS config and build files, and its own scripts. */
    private const SUSPECT_FILES = [
        ':(glob)*.config.js', ':(glob)*.config.cjs', ':(glob)*.config.mjs',
        'webpack.mix.js', 'gulpfile.js', 'build.mjs', 'init', 'init.js', 'api.js',
    ];

    /** pattern => what it means. Each is checked in SUSPECT_FILES. */
    private const MARKERS = [
        '.{1000,}' => 'has a line of 1000+ characters (code squeezed into one line)',
        '[^[:space:]][[:blank:]]{200,}[^[:space:]]' => 'hides code after hundreds of spaces or tabs',
        '_0x[0-9a-f]{4,}' => 'contains obfuscated code (_0x… names)',
        "global\\[['\"]!['\"]\\]" => "contains the global['!'] script",
    ];

    /** Files on a server that the malware hides in or creates (top folder only), for the server watch. */
    public static function isSuspectPath(string $path): bool
    {
        return !str_contains($path, '/') && (preg_match('/\.config\.(c|m)?js$/', $path) === 1
            || in_array($path, ['webpack.mix.js', 'gulpfile.js', 'build.mjs', 'init', 'init.js', 'api.js', 'package.json'], true));
    }

    /**
     * The same markers as malwareFindings(), in files read from a server (path => contents),
     * checked in memory: nothing is written to this computer's disk.
     *
     * @return string[]
     */
    public static function scanContents(array $files): array
    {
        $findings = [];
        foreach ($files as $path => $contents) {
            $path = (string) $path;
            if ($contents === null) {
                continue;
            }
            if ($path === 'api.js') {
                $findings[] = 'api.js exists in the top folder';
            }
            if ($path === 'package.json') {
                if (preg_match('/node +(\.\/)?(init|api)(\.js)?\b/', $contents)) {
                    $findings[] = 'package.json runs a hidden script (node init or node api.js) before builds';
                }
                continue;
            }
            foreach (self::MARKERS as $pattern => $meaning) {
                if (preg_match('/' . str_replace('/', '\/', $pattern) . '/m', $contents)) {
                    $findings[] = "$path $meaning";
                }
            }
        }
        return array_values(array_unique($findings));
    }

    /**
     * What was found; empty when the commit is clean. A commit never changes, so the answer
     * for a full commit hash is kept (storage/cache/malware/), named after the markers too, so
     * a change to them checks again. Saves about half a second (six git calls) each time.
     *
     * @return string[]
     */
    public static function malwareFindings(Git $git, string $sha): array
    {
        if (preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
            return self::scan($git, $sha);
        }
        $file = STORAGE_DIR . '/cache/malware/' . $sha . '-' . substr(md5(serialize([self::SUSPECT_FILES, self::MARKERS])), 0, 8) . '.json';
        $cached = JsonStore::readFile($file);
        if (isset($cached['findings'])) {
            return $cached['findings'];
        }
        $findings = self::scan($git, $sha);
        try {
            JsonStore::writeFile($file, ['findings' => $findings], false);
        } catch (\RuntimeException) {
            // only a speed-up
        }
        return $findings;
    }

    /** @return string[] */
    private static function scan(Git $git, string $sha): array
    {
        $findings = [];
        foreach (array_intersect($git->rootFiles($sha, ['api.js', 'init', 'init.js']), ['api.js']) as $file) {
            $findings[] = "$file exists in the project root";
        }
        foreach ($git->grepCount($sha, 'node +(\./)?(init|api)(\.js)?\b', ['package.json']) as $file => $count) {
            $findings[] = 'package.json runs a hidden script (node init or node api.js) before builds';
        }
        foreach (self::MARKERS as $pattern => $meaning) {
            foreach (array_keys($git->grepCount($sha, $pattern, self::SUSPECT_FILES)) as $file) {
                $findings[] = "$file $meaning";
            }
        }
        return array_values(array_unique($findings));
    }
}
