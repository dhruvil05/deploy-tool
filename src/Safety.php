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

    /** @return string[] what was found; empty when the commit is clean */
    public static function malwareFindings(Git $git, string $sha): array
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
