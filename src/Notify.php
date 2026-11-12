<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Speed-up "Messages to Slack, Teams or Discord": a short message to a chat webhook when a
 * deploy, match or rollback finishes, waits for SQL or fails. Sent once per change of status,
 * never more than a few seconds of waiting, and a failure never stops the run.
 */
final class Notify
{
    /** Statuses worth a message, with how they start. */
    private const STATUSES = [
        'sql' => '⏸ Waiting for SQL:',
        'done' => '✅ Finished:',
        'failed' => '❌ Failed:',
    ];

    /** Called when a run is saved: sends a message if its status just changed to one above. */
    public static function runSaved(?string $before, array $run): void
    {
        if ($before === $run['status'] || !isset(self::STATUSES[$run['status']])
            || !in_array($run['type'], RunStore::CHANGING_TYPES, true) || !Settings::on('feature_notify')) {
            return;
        }
        $webhook = Settings::get('notify_webhook');
        if ($webhook === '' || Secrets::isSealed($webhook)) {
            return;
        }
        self::send($webhook, self::message($run));
    }

    public static function message(array $run): string
    {
        $files = count(array_filter($run['files'], static fn (array $f): bool => !empty($f['done'])));
        $parts = [
            self::STATUSES[$run['status']] . ' ' . RunView::title($run) . (!empty($run['production']) ? ' (production)' : ''),
            $run['to'] !== null ? 'commit ' . short_sha($run['to']) : null,
            $files > 0 ? "$files file" . ($files === 1 ? '' : 's') : null,
            $run['by'] !== '' ? 'by ' . $run['by'] : null,
        ];
        $text = implode(' · ', array_filter($parts));
        if ($run['status'] === 'failed' && !empty($run['error'])) {
            $text .= "\n" . mb_substr((string) $run['error'], 0, 300);
        }
        if ($run['status'] === 'done' && !empty($run['site_check']) && !$run['site_check']['ok']) {
            $text .= "\nSite check: " . $run['site_check']['message'];
        }
        return $text;
    }

    /**
     * Posts the text; Slack and Teams read "text", Discord reads "content". Returns an error
     * message, or null when it went out.
     */
    public static function send(string $webhook, string $text): ?string
    {
        $curl = curl_init($webhook);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['text' => $text, 'content' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $answer = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = $answer === false ? curl_error($curl) : ($status >= 300 ? "HTTP $status" : null);
        JsonStore::write('notify-state', ['at' => time(), 'error' => $error]);
        return $error;
    }
}
