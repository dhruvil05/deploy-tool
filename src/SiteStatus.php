<?php

declare(strict_types=1);

namespace DeployTool;

/** Connects to a site (read-only) and reports which commit it runs. */
final class SiteStatus
{
    /**
     * $sourceCommit is the commit this site's source has (local branch or the
     * previous site); when given, the result says how far behind the site is.
     */
    public static function check(array $site, ?string $sourceCommit): array
    {
        if (($missing = Sites::missingLogin($site)) !== null) {
            return ['ok' => false, 'error' => $missing];
        }

        $transport = Sites::transport($site);
        $names = $transport->listNames();
        $status = [
            'ok' => true,
            'looks_like_app' => in_array('index.php', $names, true) && in_array('application', $names, true),
            'state' => ServerState::read($transport),
            'commit' => null,
            'commit_known' => false,
            'behind' => null,
            'diverged' => false,
        ];

        if ($status['state'] !== null) {
            // Two git calls instead of four (each ~0.1 s on Windows), for every site card.
            $git = Sites::git($site);
            $sha = $status['state']['commit'];
            $info = $git->commitInfoOf($sha);
            if ($info === null) {
                $status['commit'] = ['sha' => $sha];
            } else {
                $status['commit_known'] = true;
                $status['commit'] = $info;
                if ($sourceCommit !== null) {
                    // Commits the site has that the source lacks mean it isn't simply behind.
                    [$onlyOnSite, $behind] = $git->aheadBehind($info['sha'], $sourceCommit);
                    if ($onlyOnSite > 0) {
                        $status['diverged'] = true;
                    } else {
                        $status['behind'] = $behind;
                    }
                }
            }
        }

        return $status;
    }
}
