<?php

declare(strict_types=1);

namespace DeployTool;

/** Checks a commit for the malware that was force-pushed to GitHub in October 2026. */
final class Safety
{
    /** @return string[] what was found; empty when the commit is clean */
    public static function malwareFindings(Git $git, string $sha): array
    {
        $findings = [];
        if ($git->hasFile($sha, 'api.js')) {
            $findings[] = 'api.js exists in the project root';
        }
        $tailwind = $git->fileAt($sha, 'tailwind.config.js');
        if ($tailwind !== null && str_contains($tailwind, "global['!']")) {
            $findings[] = "tailwind.config.js contains the global['!'] script";
        }
        $package = $git->fileAt($sha, 'package.json');
        if ($package !== null && str_contains($package, 'node api.js')) {
            $findings[] = 'package.json runs node api.js';
        }
        return $findings;
    }
}
