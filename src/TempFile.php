<?php

declare(strict_types=1);

namespace DeployTool;

/** Temporary files that git, ssh and php write their output to. */
final class TempFile
{
    /**
     * Reads a file a child process wrote. On Windows something can still hold it for a moment
     * after the process ended (a background job it left running, an antivirus scan), so it
     * tries again for up to two seconds, then says so plainly instead of a PHP warning.
     */
    public static function read(string $file, string $program): string
    {
        for ($try = 1; ; $try++) {
            $data = @file_get_contents($file);
            if ($data !== false) {
                return $data;
            }
            if ($try === 40) {
                throw new \RuntimeException("Could not read what $program wrote (another program still has " . basename($file) . ' open). Try again.');
            }
            usleep(50_000);
        }
    }
}
