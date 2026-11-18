<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Speed-ups "Files of each commit" and "Pull from GitHub": the commits between two versions,
 * each with the files it changed (shown as a folder tree on the dashboard, compare and run
 * pages), and a pull that only fast-forwards and stops on malware.
 */
final class CommitFiles
{
    /** The most commits listed at once; older ones are counted, not listed. */
    public const LIMIT = 30;

    /** git's empty tree: what the first commit of a repository is compared with. */
    private const EMPTY_TREE = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

    /**
     * The commits from $from (not included) to $to, newest first, each with its files. With no
     * $from, or one that isn't an ancestor of $to (a site never recorded, or on another branch),
     * only $to itself: the files that commit changed.
     *
     * @return array{commits: list<array{sha: string, subject: string, date: string, author: string, files: list<array{0: string, 1: string}>}>, total: int, only_target: bool}
     */
    public static function between(Git $git, ?string $from, string $to): array
    {
        $onlyTarget = $from === null || $from === $to || !$git->isAncestor($from, $to);
        $range = $onlyTarget ? null : "$from..$to";
        $total = $range === null ? 1 : $git->countBetween($from, $to);
        $commits = $range === null ? [$git->commitInfo($to)] : $git->recentCommits($range, self::LIMIT);
        foreach ($commits as &$commit) {
            $commit['files'] = self::filesOf($git, $commit['sha']);
        }
        unset($commit);
        return ['commits' => $commits, 'total' => $total, 'only_target' => $onlyTarget];
    }

    /** @return list<array{0: string, 1: string}> [A/M/D, path] the files one commit changed (against its first parent) */
    public static function filesOf(Git $git, string $sha): array
    {
        return $git->changedFiles(self::parentOf($git, $sha), $sha);
    }

    /**
     * One file's changes in one commit, as DiffView::parse() rows. Read by git straight from
     * the repository, never through temporary copies of the file.
     */
    public static function diff(Git $git, string $sha, string $path): array
    {
        $text = $git->okRaw(['diff', '--no-color', '--no-ext-diff', '--no-renames', '-U3', self::parentOf($git, $sha), $sha, '--', $path]);
        return DiffView::parse($text);
    }

    /** The commit's first parent, or git's empty tree for a repository's first commit. */
    public static function parentOf(Git $git, string $sha): string
    {
        return $git->resolve($sha . '^1') ?? self::EMPTY_TREE;
    }

    /**
     * Pulls GitHub's commits into the local's folder, only when that is a plain fast-forward:
     * the local's branch is checked out, nothing is uncommitted, and the local has no commits
     * GitHub lacks. Stops before any file changes when GitHub's commit has malware markers
     * (the same check as Fetch). Git hooks are off for the pull, so a pulled commit can't run
     * code on this computer (hooks can live in the project, e.g. with husky).
     *
     * @return string what happened, for the page
     * @throws \RuntimeException why nothing was pulled
     */
    public static function pull(array $source): string
    {
        $git = Sources::git($source);
        $branch = $source['branch'];
        $remote = $source['remote'];
        $git->fetch($remote);

        $current = $git->currentBranch();
        if ($current !== $branch) {
            throw new \RuntimeException("Nothing pulled: the folder has $current checked out, not $branch. Switch to $branch in your git tool first.");
        }
        if ($count = count($git->uncommittedFiles())) {
            throw new \RuntimeException("Nothing pulled: $count file(s) have uncommitted changes. Commit or stash them first, so a pull can't mix with your work.");
        }
        $local = $git->resolve($branch);
        $remoteSha = $git->resolve("refs/remotes/$remote/$branch");
        if ($local === null || $remoteSha === null) {
            throw new \RuntimeException("Nothing pulled: $remote/$branch is not on GitHub.");
        }
        $behind = $git->countBetween($local, $remoteSha);
        if ($behind === 0) {
            return "Already up to date: {$source['name']} has everything GitHub has. Fetched just now.";
        }
        if ($git->countBetween($remoteSha, $local) > 0) {
            throw new \RuntimeException("Nothing pulled: your $branch and GitHub's both have new commits, so they need a merge or rebase. Do that in your git tool, where you can check the result.");
        }
        $findings = Safety::malwareFindings($git, $remoteSha);
        if ($findings) {
            throw new \RuntimeException('Pull stopped, nothing changed: GitHub\'s ' . $branch . ' (' . substr($remoteSha, 0, 8) . ') has malware markers: '
                . implode('; ', $findings) . '. Don\'t pull, merge or build from it until it is cleaned up on GitHub.');
        }

        $noHooks = STORAGE_DIR . '/no-git-hooks'; // never created: git finds no hooks there
        $git->ok(['-c', 'core.hooksPath=' . $noHooks, 'merge', '--ff-only', '--no-edit', '--quiet', "refs/remotes/$remote/$branch"]);
        $now = $git->commitInfo($remoteSha);
        return "Pulled $behind commit(s) into {$source['name']}: now at " . substr($remoteSha, 0, 8) . ' ' . $now['subject'] . '.';
    }
}
