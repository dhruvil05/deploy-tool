<?php

declare(strict_types=1);

namespace DeployTool;

/** A local: which commit its first sites would get, and whether it is safe to deploy. */
final class LocalStatus
{
    public static function collect(array $source): array
    {
        $git = Sources::git($source);
        $branch = $source['branch'];
        $remote = $source['remote'];
        $status = [
            'source' => $source,
            'error' => null,
            'warnings' => [],
            'commit' => null,
            'remote_commit' => null,
            'ahead' => 0,
            'behind' => 0,
            'malware' => [],
            'remote_malware' => [],
            'last_fetch' => null,
        ];

        if (!$git->isRepo()) {
            $status['error'] = 'The folder of this local is not a git repository.';
            return $status;
        }
        $sha = $git->resolve($branch);
        if ($sha === null) {
            $status['error'] = "Branch $branch was not found in the repository.";
            return $status;
        }
        $status['commit'] = $git->commitInfo($sha);
        $status['malware'] = Safety::malwareFindings($git, $sha);

        $current = $git->currentBranch();
        if ($current !== $branch) {
            $status['warnings'][] = "You have $current checked out, not $branch.";
        } elseif ($count = count($git->uncommittedFiles())) {
            $status['warnings'][] = "$count file(s) have uncommitted changes. Commit them before deploying.";
        }

        $remoteSha = $git->resolve("refs/remotes/$remote/$branch");
        $status['remote_commit'] = $remoteSha;
        $status['last_fetch'] = $git->lastFetchTime();
        if ($remoteSha === null) {
            $status['warnings'][] = "$remote/$branch is not known yet. Fetch from GitHub.";
        } else {
            $status['ahead'] = $git->countBetween($remoteSha, $sha);
            $status['behind'] = $git->countBetween($sha, $remoteSha);
            if ($remoteSha !== $sha) {
                $status['remote_malware'] = Safety::malwareFindings($git, $remoteSha);
            }
        }

        return $status;
    }
}
