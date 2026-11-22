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

        // The dashboard draws this on every visit, and each git call costs ~0.1 s on Windows:
        // five calls when the malware results of both commits are already known (Safety caches them).
        $status['commit'] = $git->commitInfoOf($branch);
        if ($status['commit'] === null) {
            $status['error'] = $git->isRepo() ? "Branch $branch was not found in the repository." : 'The folder of this local is not a git repository.';
            return $status;
        }
        $sha = $status['commit']['sha'];
        $status['malware'] = Safety::malwareFindings($git, $sha);

        $current = Sources::isManaged($source) ? $branch : $git->currentBranch(); // the tool's own copy has no checkout
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
        } elseif ($remoteSha !== $sha) {
            [$status['ahead'], $status['behind']] = $git->aheadBehind($sha, $remoteSha);
            $status['remote_malware'] = Safety::malwareFindings($git, $remoteSha);
        }

        return $status;
    }
}
