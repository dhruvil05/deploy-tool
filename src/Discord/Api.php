<?php

declare(strict_types=1);

namespace DeployTool\Discord;

/**
 * The few Discord REST calls the bridge needs, with a bot token. Only outgoing HTTPS
 * requests: nothing on this computer is opened to the internet. The token never appears in
 * an error message.
 */
final class Api
{
    /** Discord's API; tests point it at a local fake. */
    public static string $base = 'https://discord.com/api/v10';

    /** Discord's limit for one message. */
    public const MAX_LENGTH = 2000;

    public function __construct(private readonly string $token)
    {
    }

    /** @return array{id: string, username: string} the bot itself */
    public function me(): array
    {
        return $this->call('GET', '/users/@me');
    }

    /** @return array{id: string, name?: string, type: int, guild_id?: string} */
    public function channel(string $id): array
    {
        return $this->call('GET', '/channels/' . self::id($id));
    }

    /**
     * Messages in a channel or thread, oldest first. With $after: only newer ones; without:
     * just the newest one (to know where "new" starts).
     *
     * @return list<array{id: string, content: string, author: array{id: string, bot?: bool}, type: int}>
     */
    public function messages(string $channel, ?string $after): array
    {
        $query = $after === null ? '?limit=1' : '?limit=50&after=' . self::id($after);
        $messages = $this->call('GET', '/channels/' . self::id($channel) . '/messages' . $query);
        usort($messages, static fn (array $a, array $b): int => self::compareIds((string) $a['id'], (string) $b['id']));
        return $messages;
    }

    /** Starts a public thread in a channel; returns the thread (a channel) with its id. */
    public function createThread(string $channel, string $name): array
    {
        return $this->call('POST', '/channels/' . self::id($channel) . '/threads', [
            'name' => mb_substr($name, 0, 100),
            'type' => 11, // public thread
            'auto_archive_duration' => 10080, // a week without messages
        ]);
    }

    /**
     * Sends text, split into several messages when longer than Discord allows. Never pings
     * anyone. Returns the last message posted.
     */
    public function send(string $channel, string $text): array
    {
        $posted = [];
        foreach (self::split($text) as $part) {
            $posted = $this->call('POST', '/channels/' . self::id($channel) . '/messages', [
                'content' => $part,
                'allowed_mentions' => ['parse' => []],
            ]);
        }
        return $posted;
    }

    /** Adds the bot's own reaction, so the user only has to tap it. */
    public function react(string $channel, string $message, string $emoji): void
    {
        $this->call('PUT', '/channels/' . self::id($channel) . '/messages/' . self::id($message) . '/reactions/' . rawurlencode($emoji) . '/@me');
    }

    /** @return string[] ids of the users who reacted with $emoji */
    public function reactedBy(string $channel, string $message, string $emoji): array
    {
        $users = $this->call('GET', '/channels/' . self::id($channel) . '/messages/' . self::id($message) . '/reactions/' . rawurlencode($emoji) . '?limit=25');
        return array_map(static fn (array $user): string => (string) $user['id'], $users);
    }

    /** Shows "Bot is typing…" for about 10 seconds. */
    public function typing(string $channel): void
    {
        try {
            $this->call('POST', '/channels/' . self::id($channel) . '/typing');
        } catch (\RuntimeException) {
            // only a nicety
        }
    }

    /** -1, 0 or 1: Discord ids are numbers too big for some systems, so they're compared as text. */
    public static function compareIds(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }

    /** Splits text into parts Discord accepts (MAX_LENGTH each). */
    public static function split(string $text): array
    {
        return \DeployTool\Remote\Conversation::split($text, self::MAX_LENGTH);
    }

    private static function id(string $id): string
    {
        if (!preg_match('/^\d{1,21}$/', $id)) {
            throw new \RuntimeException('Not a Discord id: ' . $id);
        }
        return $id;
    }

    /** One request; waits and tries again when Discord says "too many requests". */
    private function call(string $method, string $path, ?array $body = null): array
    {
        for ($try = 1; ; $try++) {
            $curl = curl_init(self::$base . $path);
            $local = str_starts_with(self::$base, 'http://127.0.0.1:');
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => array_merge(
                    ['Authorization: Bot ' . $this->token, 'User-Agent: DiscordBot (https://discord.com/developers/docs, 1.0)'],
                    $body !== null || $method === 'POST' ? ['Content-Type: application/json'] : [],
                ),
                CURLOPT_POSTFIELDS => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : match ($method) { 'POST' => '{}', 'PUT' => '', default => null },
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_PROTOCOLS => $local ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $answer = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $network = $answer === false ? curl_error($curl) : null;
            if ($network !== null) {
                throw new \RuntimeException('Could not reach Discord: ' . $network);
            }
            $data = json_decode((string) $answer, true);
            if ($status === 429 && $try < 3) {
                usleep((int) (min(5.0, (float) ($data['retry_after'] ?? 1)) * 1_000_000));
                continue;
            }
            if ($status >= 300) {
                $why = match ($status) {
                    401 => 'the bot token is wrong or was reset',
                    403 => 'the bot has no access to this channel (check its permissions)',
                    404 => 'channel not found (check the channel ID, and that the bot was added to that server)',
                    default => (string) ($data['message'] ?? "HTTP $status"),
                };
                throw new \RuntimeException("Discord said no: $why.", $status);
            }
            return is_array($data) ? $data : [];
        }
    }
}
