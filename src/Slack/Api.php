<?php

declare(strict_types=1);

namespace DeployTool\Slack;

/**
 * The few Slack Web API calls the bridge needs: the bot token (xoxb-…) posts and reacts, the
 * app-level token (xapp-…) opens the Socket Mode connection. Only outgoing HTTPS requests;
 * tokens never appear in an error message.
 */
final class Api
{
    /** Slack's Web API; tests point it at a local fake. */
    public static string $base = 'https://slack.com/api';

    /** Characters per message; Slack allows far more, but long walls of text read badly. */
    public const MAX_LENGTH = 3500;

    public function __construct(private readonly string $botToken, private readonly string $appToken = '')
    {
    }

    /** @return array{user_id: string, user: string, team: string} the bot itself */
    public function whoAmI(): array
    {
        return $this->call('auth.test', []);
    }

    /** @return array{id: string, name: string} */
    public function channel(string $id): array
    {
        return $this->call('conversations.info', ['channel' => $id])['channel'];
    }

    /** Posts text (Slack's own formatting), in a thread when $threadTs is given; returns the last message's ts. */
    public function post(string $channel, string $text, ?string $threadTs = null): string
    {
        $ts = '';
        foreach (self::split($text) as $part) {
            $ts = (string) $this->call('chat.postMessage', array_filter([
                'channel' => $channel,
                'text' => $part,
                'thread_ts' => $threadTs,
                'unfurl_links' => false,
                'unfurl_media' => false,
            ], static fn ($v): bool => $v !== null))['ts'];
        }
        return $ts;
    }

    /** Adds the bot's reaction (an emoji name such as white_check_mark). */
    public function react(string $channel, string $ts, string $name): void
    {
        try {
            $this->call('reactions.add', ['channel' => $channel, 'timestamp' => $ts, 'name' => $name]);
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'already_reacted')) {
                throw $e;
            }
        }
    }

    /** The address of a new Socket Mode connection (app-level token). */
    public function socketUrl(): string
    {
        if ($this->appToken === '') {
            throw new \RuntimeException('Paste the app-level token (xapp-…) first.');
        }
        return (string) $this->call('apps.connections.open', [], $this->appToken)['url'];
    }

    /** Splits text into parts of at most MAX_LENGTH, at line breaks where possible. */
    public static function split(string $text): array
    {
        $text = trim($text) === '' ? '(empty answer)' : trim($text);
        $parts = [];
        while (mb_strlen($text) > self::MAX_LENGTH) {
            $chunk = mb_substr($text, 0, self::MAX_LENGTH);
            $cut = mb_strrpos($chunk, "\n");
            $cut = $cut !== false && $cut > self::MAX_LENGTH / 2 ? $cut : self::MAX_LENGTH;
            $parts[] = rtrim(mb_substr($text, 0, $cut));
            $text = ltrim(mb_substr($text, $cut));
        }
        $parts[] = $text;
        return $parts;
    }

    /** One Web API call; waits and tries again when Slack says "too many requests". */
    private function call(string $method, array $params, ?string $token = null): array
    {
        for ($try = 1; ; $try++) {
            $curl = curl_init(self::$base . '/' . $method);
            $local = str_starts_with(self::$base, 'http://127.0.0.1:');
            $headers = [];
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . ($token ?? $this->botToken), 'Content-Type: application/json; charset=utf-8'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_PROTOCOLS => $local ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                    if (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $headers[strtolower(trim($name))] = trim($value);
                    }
                    return strlen($line);
                },
            ]);
            $answer = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($answer === false) {
                throw new \RuntimeException('Could not reach Slack: ' . curl_error($curl));
            }
            if ($status === 429 && $try < 3) {
                sleep(min(5, max(1, (int) ($headers['retry-after'] ?? 1))));
                continue;
            }
            $data = json_decode((string) $answer, true);
            if (!is_array($data) || empty($data['ok'])) {
                $error = is_array($data) ? (string) ($data['error'] ?? "HTTP $status") : "HTTP $status";
                [$why, $code] = match ($error) {
                    'invalid_auth', 'not_authed', 'token_revoked', 'account_inactive' => ['the token is wrong or was revoked', 401],
                    'channel_not_found' => ['channel not found (check the channel ID)', 404],
                    'not_in_channel' => ['the bot is not in the channel: type /invite @your-bot there', 403],
                    'missing_scope' => ['the app lacks a permission: add ' . ($data['needed'] ?? 'the scope') . ' under OAuth & Permissions, then reinstall the app', 403],
                    'not_allowed_token_type' => ['wrong kind of token (the bot token starts with xoxb-, the app-level token with xapp-)', 401],
                    default => [$error, $status >= 400 ? $status : 400],
                };
                throw new \RuntimeException("Slack said no: $why.", $code);
            }
            return $data;
        }
    }
}
