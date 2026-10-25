<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * The commit a server runs, recorded on the server itself after each deploy.
 * It lives under application/, which the app's own .htaccess blocks from the web.
 */
final class ServerState
{
    public const PATH = 'application/.deploy/state.json';

    public static function readRaw(Transport $transport): ?string
    {
        return $transport->get(self::PATH);
    }

    /** ['commit', 'deployed_at', 'deployed_by', ...] or null when nothing is recorded. */
    public static function parse(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $state = json_decode($raw, true);
        if (!is_array($state) || !preg_match('/^[0-9a-f]{40}$/', (string) ($state['commit'] ?? ''))) {
            throw new \RuntimeException(self::PATH . ' on the server is not valid. Check it over FTP.');
        }
        return $state;
    }

    public static function read(Transport $transport): ?array
    {
        return self::parse(self::readRaw($transport));
    }

    /** Writes the state and returns exactly what was written. */
    public static function write(Transport $transport, string $commit, string $runId, string $note = ''): string
    {
        $raw = json_encode([
            'commit' => $commit,
            'deployed_at' => date('Y-m-d H:i:s P'),
            'deployed_by' => Settings::get('deployer_name'),
            'run' => $runId,
            'note' => $note,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $transport->put(self::PATH, $raw);
        return $raw;
    }

    /** Puts back an earlier state exactly (null = there was none). */
    public static function restore(Transport $transport, ?string $raw): void
    {
        $raw === null ? $transport->delete(self::PATH) : $transport->put(self::PATH, $raw);
    }
}
