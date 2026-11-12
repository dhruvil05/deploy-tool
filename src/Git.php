<?php

declare(strict_types=1);

namespace DeployTool;

/** Read-only git commands against the project repository. */
final class Git
{
    public function __construct(
        private readonly string $repo,
        private readonly string $binary,
    ) {
    }

    /**
     * Runs git directly (no shell, so nothing in an argument is interpreted).
     * Returns [exit code, stdout, stderr].
     */
    public function run(array $args, ?string $input = null): array
    {
        // No automatic maintenance: git would start it in the background after a fetch, and on
        // Windows that background process keeps our output files open. Your own git still does it.
        $command = array_merge([$this->binary, '-C', $this->repo, '-c', 'core.quotepath=off', '-c', 'maintenance.auto=false', '-c', 'gc.auto=0'], $args);
        // Input and output go through files: pipes can deadlock when git writes a lot while
        // we write, and on Windows PHP reads a big pipe output many times slower than a file.
        $inputFile = tempnam(sys_get_temp_dir(), 'dtgit');
        $outputFile = tempnam(sys_get_temp_dir(), 'dtgit');
        $errorFile = tempnam(sys_get_temp_dir(), 'dtgit');
        file_put_contents($inputFile, (string) $input);
        $env = array_merge(getenv(), ['GIT_TERMINAL_PROMPT' => '0']);

        try {
            $process = proc_open($command, [0 => ['file', $inputFile, 'r'], 1 => ['file', $outputFile, 'w'], 2 => ['file', $errorFile, 'w']], $pipes, null, $env);
            if (!is_resource($process)) {
                throw new \RuntimeException("Could not start git ({$this->binary}). Check the git path in Settings.");
            }
            $code = proc_close($process);
            $stdout = TempFile::read($outputFile, 'git');
            $stderr = trim(TempFile::read($errorFile, 'git'));
        } finally {
            @unlink($inputFile);
            @unlink($outputFile);
            @unlink($errorFile);
        }

        return [$code, $stdout, $stderr];
    }

    /** Runs git and returns trimmed stdout, or throws with git's own message. */
    public function ok(array $args): string
    {
        return trim($this->okRaw($args));
    }

    /** Like ok(), without trimming (for -z output). */
    public function okRaw(array $args, ?string $input = null): string
    {
        [$code, $stdout, $stderr] = $this->run($args, $input);
        if ($code !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ' failed: ' . ($stderr !== '' ? $stderr : "exit code $code"));
        }
        return $stdout;
    }

    /** @return array<int, array{0: string, 1: string}> [status letter, path] between two commits */
    public function changedFiles(string $from, string $to): array
    {
        $parts = explode("\0", rtrim($this->okRaw(['diff', '--name-status', '--no-renames', '--no-ext-diff', '-z', $from, $to]), "\0"));
        $changes = [];
        for ($i = 0; $i + 1 < count($parts); $i += 2) {
            $changes[] = [$parts[$i][0], $parts[$i + 1]];
        }
        return $changes;
    }

    /**
     * Lines added and removed per file between two commits (one git call).
     *
     * @return array<string, array{0: ?int, 1: ?int}> path => [added, removed]; null for binary files
     */
    public function numstat(string $from, string $to): array
    {
        $stats = [];
        $parts = explode("\0", rtrim($this->okRaw(['diff', '--numstat', '--no-renames', '--no-ext-diff', '-z', $from, $to]), "\0"));
        foreach ($parts as $part) {
            if (preg_match('/^(\d+|-)\t(\d+|-)\t(.+)$/s', $part, $m)) {
                $stats[$m[3]] = [$m[1] === '-' ? null : (int) $m[1], $m[2] === '-' ? null : (int) $m[2]];
            }
        }
        return $stats;
    }

    /** @return array<string, array{sha: string, size: int}> every file in a commit */
    public function tree(string $commit): array
    {
        $files = [];
        foreach (explode("\0", rtrim($this->okRaw(['ls-tree', '-r', '-l', '-z', '--full-tree', $commit]), "\0")) as $entry) {
            if (!str_contains($entry, "\t")) {
                continue;
            }
            [$meta, $path] = explode("\t", $entry, 2);
            [$mode, $type, $sha, $size] = preg_split('/\s+/', trim($meta));
            if ($type === 'blob' && $mode !== '120000') { // skip symlinks
                $files[$path] = ['sha' => $sha, 'size' => (int) $size];
            }
        }
        return $files;
    }

    /** @return array<int, array{sha: string, subject: string, date: string, author: string}> */
    public function recentCommits(string $ref, int $count): array
    {
        $commits = [];
        foreach (explode("\n", $this->ok(['log', '-n', (string) $count, '--format=%H%x1f%s%x1f%ci%x1f%an', $ref])) as $line) {
            if ($line !== '') {
                [$sha, $subject, $date, $author] = explode("\x1f", $line);
                $commits[] = ['sha' => $sha, 'subject' => $subject, 'date' => $date, 'author' => $author];
            }
        }
        return $commits;
    }

    /**
     * Unified diff between two local files (git works outside the repository with --no-index),
     * with $context unchanged lines around each change.
     */
    public function diffFiles(string $fileA, string $fileB, int $context = 3): string
    {
        [, $stdout] = $this->run(['diff', '--no-index', '--no-color', '--no-ext-diff', '-U' . $context, '--', $fileA, $fileB]);
        return $stdout;
    }

    public function isRepo(): bool
    {
        return $this->run(['rev-parse', '--is-inside-work-tree'])[0] === 0;
    }

    /** Full commit hash for a branch, tag or hash, or null if it doesn't exist. */
    public function resolve(string $ref): ?string
    {
        [$code, $stdout] = $this->run(['rev-parse', '--verify', '--quiet', $ref . '^{commit}']);
        return $code === 0 ? trim($stdout) : null;
    }

    public function currentBranch(): string
    {
        return $this->ok(['rev-parse', '--abbrev-ref', 'HEAD']);
    }

    /** @return array{sha: string, subject: string, date: string, author: string} */
    public function commitInfo(string $sha): array
    {
        [$fullSha, $subject, $date, $author] = explode("\x1f", $this->ok(['log', '-1', '--format=%H%x1f%s%x1f%ci%x1f%an', $sha]));
        return ['sha' => $fullSha, 'subject' => $subject, 'date' => $date, 'author' => $author];
    }

    /**
     * commitInfo() for several commits in one git call. Commits the repository doesn't have
     * are left out.
     *
     * @return array<string, array{sha: string, subject: string, date: string, author: string}> by the sha asked for
     */
    public function commitInfos(array $shas): array
    {
        $shas = array_values(array_unique(array_filter($shas, static fn ($sha): bool => is_string($sha) && preg_match('/^[0-9a-f]{4,40}$/', $sha) === 1)));
        if ($shas === []) {
            return [];
        }
        [$code, $stdout] = $this->run(array_merge(['log', '--no-walk=unsorted', '--ignore-missing', '--format=%H%x1f%s%x1f%ci%x1f%an'], $shas));
        $infos = [];
        foreach ($code === 0 ? explode("\n", trim($stdout)) : [] as $line) {
            [$fullSha, $subject, $date, $author] = explode("\x1f", $line) + ['', '', '', ''];
            foreach ($shas as $sha) {
                if ($fullSha !== '' && str_starts_with($fullSha, $sha)) {
                    $infos[$sha] = ['sha' => $fullSha, 'subject' => $subject, 'date' => $date, 'author' => $author];
                }
            }
        }
        return $infos;
    }

    /** Number of commits in $to that are not in $from. */
    public function countBetween(string $from, string $to): int
    {
        return (int) $this->ok(['rev-list', '--count', "$from..$to"]);
    }

    public function isAncestor(string $ancestor, string $descendant): bool
    {
        return $this->run(['merge-base', '--is-ancestor', $ancestor, $descendant])[0] === 0;
    }

    /** Tracked files with uncommitted changes in the working folder. */
    public function uncommittedFiles(): array
    {
        [$code, $stdout, $stderr] = $this->run(['status', '--porcelain', '--untracked-files=no']);
        if ($code !== 0) {
            throw new \RuntimeException('git status failed: ' . $stderr);
        }
        $stdout = rtrim($stdout);
        // Each line is "XY path"; don't trim the start, X can be a space.
        return $stdout === '' ? [] : array_map(static fn (string $line): string => substr($line, 3), explode("\n", $stdout));
    }

    public function hasFile(string $sha, string $path): bool
    {
        return $this->run(['cat-file', '-e', "$sha:$path"])[0] === 0;
    }

    /** File contents exactly as committed (no line-ending conversion), or null if missing. */
    public function fileAt(string $sha, string $path): ?string
    {
        [$code, $stdout] = $this->run(['cat-file', 'blob', "$sha:$path"]);
        return $code === 0 ? $stdout : null;
    }

    /**
     * Many files of one commit with a single git call: path => contents exactly as
     * committed, or null when the path is not a file in that commit.
     */
    public function filesAt(string $sha, array $paths): array
    {
        $paths = array_values(array_unique(array_map('strval', $paths)));
        if ($paths === []) {
            return [];
        }
        $input = '';
        foreach ($paths as $path) {
            if (str_contains($path, "\n")) {
                throw new \RuntimeException('A path cannot contain a line break: ' . json_encode($path));
            }
            $input .= "$sha:$path\n";
        }
        $output = $this->okRaw(['cat-file', '--batch'], $input);

        // Each answer is "<object> <type> <size>\n<contents>\n", or "<name> missing\n".
        $files = [];
        $pos = 0;
        foreach ($paths as $path) {
            $end = strpos($output, "\n", $pos);
            if ($end === false) {
                throw new \RuntimeException('git cat-file stopped early.');
            }
            $header = substr($output, $pos, $end - $pos);
            $pos = $end + 1;
            $parts = explode(' ', $header);
            if (count($parts) === 3 && ctype_digit($parts[2])) {
                $size = (int) $parts[2];
                $files[$path] = $parts[1] === 'blob' ? substr($output, $pos, $size) : null;
                $pos += $size + 1;
            } else {
                $files[$path] = null; // missing or ambiguous
            }
        }
        return $files;
    }

    /**
     * Files of a commit with lines matching an extended regex: path => number of such lines.
     * Only names and counts come back, never the lines, so suspect content is never written
     * to a temporary file (where an antivirus would rightly quarantine it).
     *
     * @param string[] $paths pathspecs, for example 'package.json' or ':(glob)*.config.js'
     */
    public function grepCount(string $sha, string $pattern, array $paths): array
    {
        [$code, $stdout, $stderr] = $this->run(array_merge(['grep', '-c', '-I', '-E', '-e', $pattern, $sha, '--'], $paths));
        if ($code > 1) { // 1 means no match
            throw new \RuntimeException('git grep failed: ' . ($stderr !== '' ? $stderr : "exit code $code"));
        }
        $hits = [];
        foreach (explode("\n", trim($stdout)) as $line) {
            if (preg_match('/^[^:]+:(.+):(\d+)$/', $line, $m)) {
                $hits[$m[1]] = (int) $m[2];
            }
        }
        return $hits;
    }

    /** Which of these paths are files at the top of a commit (names only). */
    public function rootFiles(string $sha, array $names): array
    {
        $output = trim($this->okRaw(array_merge(['ls-tree', '--name-only', $sha, '--'], $names)));
        return $output === '' ? [] : explode("\n", $output);
    }

    /** Downloads new commits from the remote. Changes no local branch or file. */
    public function fetch(string $remote): void
    {
        $this->ok(['fetch', '--no-tags', $remote]);
    }

    public function lastFetchTime(): ?int
    {
        $file = $this->ok(['rev-parse', '--absolute-git-dir']) . '/FETCH_HEAD';
        return is_file($file) ? (int) filemtime($file) : null;
    }
}
