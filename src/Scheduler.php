<?php

declare(strict_types=1);

namespace DeployTool;

use DeployTool\Remote\Process;

/**
 * The background process for timed checks (src/scheduler-worker.php): the server watch and the
 * uptime monitor. It runs while either speed-up is on, started on page loads like the chat
 * bridges, and restarts itself when the tool's code changes. Sites that are hidden on the
 * dashboard are left alone.
 */
final class Scheduler
{
    private const PAUSE_SECONDS = 20;

    public static function wanted(): bool
    {
        return Settings::on('feature_server_watch') || Settings::on('feature_uptime');
    }

    public static function ensure(): void
    {
        Process::ensure('scheduler', self::wanted());
    }

    public static function main(): void
    {
        $lock = Process::lock('scheduler');
        if ($lock === null) {
            return;
        }
        $code = Process::codeVersion();
        $restart = false;
        try {
            while (self::wanted()) {
                if (Process::codeVersion() !== $code) {
                    $restart = true;
                    break;
                }
                self::tick();
                sleep(self::PAUSE_SECONDS);
            }
        } finally {
            Process::release($lock);
        }
        if ($restart) {
            Process::spawn('scheduler');
        }
    }

    /** One round: every check that is due. A problem with one site never stops the others. */
    public static function tick(): void
    {
        foreach (Sites::all() as $site) {
            if (!empty($site['hidden'])) {
                continue;
            }
            if (Settings::on('feature_uptime') && Monitor::due($site)) {
                try {
                    Monitor::check($site);
                } catch (\Throwable $e) {
                    error_log("Uptime check of {$site['key']}: " . $e->getMessage());
                }
            }
            if (Settings::on('feature_server_watch') && ServerWatch::due($site)) {
                try {
                    ServerWatch::check($site);
                } catch (\Throwable $e) {
                    error_log("Server watch of {$site['key']}: " . $e->getMessage());
                }
            }
        }
    }
}
