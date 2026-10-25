<?php

declare(strict_types=1);

namespace DeployTool;

/** What a run step needs: the site, its local, transport and git, created on first use. */
final class RunContext
{
    private ?Transport $transport = null;
    private ?Git $git = null;
    private ?array $source = null;

    public function __construct(public readonly array $site, private readonly array $run)
    {
    }

    /** The local this run deploys from (fixed when the run was created). */
    public function source(): array
    {
        return $this->source ??= RunStore::source($this->run);
    }

    public function transport(): Transport
    {
        return $this->transport ??= Sites::transport($this->site);
    }

    public function git(): Git
    {
        return $this->git ??= Sources::git($this->source());
    }

    public function protectedRegexes(): array
    {
        return ProtectedPaths::regexes($this->source());
    }

    /** A file exactly as committed. */
    public function blob(string $commit, string $path): string
    {
        return $this->git()->fileAt($commit, $path)
            ?? throw new \RuntimeException("$path is not in commit " . short_sha($commit) . '.');
    }
}
