<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * A site that can run commands in its folder: SSH sites on the server, folder sites on this
 * computer. Used by the speed-ups "Commands after a deploy" and "Database backup".
 */
interface CommandRunner
{
    /**
     * Runs $command (a shell command line) in the site's folder, with $input on its stdin.
     * With $outputFile, stdout goes straight to that file (large output such as a database
     * dump) and comes back empty.
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    public function exec(string $command, string $input = '', ?string $outputFile = null): array;

    /** Quotes one argument for this site's shell. */
    public function quote(string $argument): string;
}
