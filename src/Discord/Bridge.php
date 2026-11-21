<?php

declare(strict_types=1);

namespace DeployTool\Discord;

use DeployTool\Ai;
use DeployTool\JsonStore;
use DeployTool\Remote\Conversation;
use DeployTool\Remote\Process;
use DeployTool\Settings;

/**
 * Speed-up "Chat with the assistant from Discord". A background process
 * (src/discord-worker.php) asks Discord every few seconds for new messages in one channel and
 * in the threads it opened there; nothing on this computer is reachable from the internet.
 * What the messages do is the same for every chat app: Remote\Conversation.
 *
 * Only messages and reactions from the Discord user ids in Settings count; others are ignored.
 * State (where reading got to, thread → chat, waiting questions) is in storage/discord.json.
 */
final class Bridge
{
    /** How often Discord is asked for new messages while the bridge runs. */
    public const POLL_SECONDS = 3;

    /** Threads read on every round; older ones only every tenth round. */
    private const ACTIVE_SECONDS = 86400;

    private static int $round = 0;

    /** The body of src/discord-worker.php: runs until the speed-up is switched off. */
    public static function main(): void
    {
        $lock = Process::lock('discord');
        if ($lock === null) {
            return; // already running
        }
        $code = Process::codeVersion();
        $restart = false;
        try {
            while (Settings::on('feature_discord_bridge')) {
                // The tool was updated: start again with the new code (PHP keeps the old in memory).
                if (Process::codeVersion() !== $code) {
                    $restart = true;
                    break;
                }
                $pause = self::POLL_SECONDS;
                try {
                    self::tick();
                } catch (\RuntimeException $e) {
                    self::saveStatus(['error' => $e->getMessage()]);
                    $pause = $e->getCode() === 401 ? 60 : 15; // a wrong token won't fix itself soon
                }
                sleep($pause);
            }
            if (!$restart) {
                self::saveStatus(['error' => null, 'stopped_at' => time()]);
            }
        } finally {
            Process::release($lock);
        }
        if ($restart) {
            self::saveStatus(['restarted_at' => time()]);
            Process::spawn('discord');
        }
    }

    /** Starts the background process when the speed-up is on and it isn't running (any page load). */
    public static function ensure(): void
    {
        Process::ensure('discord');
    }

    public static function isRunning(): bool
    {
        return Process::isRunning('discord');
    }

    /** For Settings: running or not, when it last read Discord, and the last problem. */
    public static function status(): array
    {
        $state = JsonStore::read('discord');
        return [
            'running' => self::isRunning(),
            'checked_at' => $state['checked_at'] ?? null,
            'error' => $state['error'] ?? null,
            'bot' => $state['bot']['name'] ?? null,
            'threads' => count($state['threads'] ?? []),
        ];
    }

    /** One round: reads new messages in the channel and the chat threads, and answers them. */
    public static function tick(?Api $api = null): void
    {
        if (!Settings::on('feature_discord_bridge')) {
            return;
        }
        $channel = Settings::get('discord_channel_id');
        $users = preg_split('/[\s,]+/', Settings::get('discord_user_ids'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $token = Settings::get('discord_bot_token');
        if ($token === '' || $channel === '' || $users === []) {
            throw new \RuntimeException('Fill in the bot token, the channel ID and your user ID in Settings → Speed-ups.');
        }
        $api ??= new Api($token);
        $previous = Ai::$remote;
        Ai::$remote = 'Discord'; // AI permissions as narrowed for Discord (Ai::mode)
        try {
            self::round($api, new Outbox($api, $channel), $users);
        } finally {
            Ai::$remote = $previous;
        }
    }

    /** @param string[] $users */
    private static function round(Api $api, Outbox $out, array $users): void
    {
        $channel = $out->channel();
        $state = JsonStore::read('discord');
        if (($state['channel'] ?? null) !== $channel) {
            $state = ['channel' => $channel, 'last' => [], 'threads' => [], 'used' => []]; // another channel: start fresh
        }
        if (empty($state['bot'])) {
            $me = $api->me();
            $state['bot'] = ['id' => (string) $me['id'], 'name' => (string) ($me['username'] ?? 'bot')];
        }
        self::$round++;

        $channels = [$channel];
        foreach ($state['threads'] as $thread => $chatId) {
            $active = time() - (int) ($state['used'][$thread] ?? 0) < self::ACTIVE_SECONDS;
            if ($active || self::$round % 10 === 0) {
                $channels[] = (string) $thread;
            }
        }
        foreach ($channels as $from) {
            // A thread the bot opened is read from its start; the channel from when the bot first looked.
            $after = $state['last'][$from] ?? ($from === $channel ? null : $from);
            try {
                $messages = $api->messages($from, $after);
            } catch (\RuntimeException $e) {
                if ($from !== $channel && in_array($e->getCode(), [403, 404], true)) {
                    unset($state['threads'][$from], $state['last'][$from], $state['used'][$from], $state['pending'][$from]); // thread deleted
                    continue;
                }
                throw $e;
            }
            if ($after === null) {
                // First look at the channel: start from now, never answer old messages.
                $state['last'][$from] = $messages !== [] ? (string) end($messages)['id'] : '0';
                continue;
            }
            foreach ($messages as $message) {
                $state['last'][$from] = (string) $message['id'];
                self::saveState($state); // a crash mid-answer never answers the same message twice
                if (!empty($message['author']['bot']) || !in_array((string) ($message['author']['id'] ?? ''), $users, true)
                    || !in_array((int) ($message['type'] ?? 0), [0, 19], true)) {
                    continue; // other people, bots (and this one), and Discord's own notices
                }
                $text = trim((string) ($message['content'] ?? ''));
                if ($text === '') {
                    $out->send($from, "I can't read your message: in the Discord Developer Portal, open the bot's page and switch on **Message Content Intent**.");
                    continue;
                }
                if ($from === $channel) {
                    Conversation::inChannel($out, $state, $text);
                } else {
                    Conversation::inThread($out, $state, $from, $text);
                }
                self::saveState($state);
            }
        }
        // Confirm questions waiting for a ✅ or ❌ reaction from a listed user.
        foreach ($state['pending'] ?? [] as $thread => $question) {
            $thread = (string) $thread;
            try {
                $yes = array_intersect($api->reactedBy($thread, (string) $question, '✅'), $users);
                $no = array_intersect($api->reactedBy($thread, (string) $question, '❌'), $users);
            } catch (\RuntimeException $e) {
                if (in_array($e->getCode(), [403, 404], true)) {
                    unset($state['pending'][$thread]); // the question or thread was deleted
                    continue;
                }
                throw $e;
            }
            if ($yes !== [] || $no !== []) {
                Conversation::answer($out, $state, $thread, $no === []);
                self::saveState($state);
            }
        }
        $state['checked_at'] = time();
        $state['error'] = null;
        self::saveState($state);
    }

    private static function saveState(array $state): void
    {
        JsonStore::write('discord', $state);
    }

    private static function saveStatus(array $changes): void
    {
        JsonStore::write('discord', $changes + JsonStore::read('discord'));
    }

}
