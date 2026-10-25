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
        if ($site['type'] === 'ftp' && ($site['host'] === '' || $site['username'] === '' || $site['password'] === '')) {
            return ['ok' => false, 'error' => 'FTP details are missing. Edit this site to add them.'];
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
            $git = Sites::git($site);
            $sha = $status['state']['commit'];
            if ($git->resolve($sha) === null) {
                $status['commit'] = ['sha' => $sha];
            } else {
                $status['commit_known'] = true;
                $status['commit'] = $git->commitInfo($sha);
                if ($sourceCommit !== null) {
                    if ($git->isAncestor($sha, $sourceCommit)) {
                        $status['behind'] = $git->countBetween($sha, $sourceCommit);
                    } else {
                        $status['diverged'] = true;
                    }
                }
            }
        }

        return $status;
    }
}
