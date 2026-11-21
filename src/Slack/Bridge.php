<?php

declare(strict_types=1);

namespace DeployTool\Slack;

use DeployTool\Ai;
use DeployTool\JsonStore;
use DeployTool\Remote\Conversation;
use DeployTool\Remote\Process;
use DeployTool\Settings;

/**
 * Speed-up "Chat with the assistant from Slack". A background process (src/slack-worker.php)
 * keeps a Socket Mode connection to Slack: the tool connects out and Slack sends the channel's
 * messages and reactions down it, so nothing on this computer is reachable from the internet.
 * Each event is acknowledged at once (Slack wants that within 3 seconds), queued, and handled
 * in order by Remote\Conversation, the same as for Discord.
 *
 * Only messages and reactions from the Slack member ids in Settings count. State (thread → chat,
 * waiting questions, events already handled) is in storage/slack.json.
 */
final class Bridge
{
    /** Reactions that answer a confirm question. */
    private const YES = ['white_check_mark', 'heavy_check_mark', 'ballot_box_with_check', '+1', 'thumbsup'];
    private const NO = ['x', 'negative_squared_cross_mark', 'heavy_multiplication_x', '-1', 'thumbsdown'];

    private static ?Socket $socket = null;

    /** @var list<array> events received and acknowledged, not handled yet */
    private static array $queue = [];

    /** The body of src/slack-worker.php: runs until the speed-up is switched off. */
    public static function main(): void
    {
        $lock = Process::lock('slack');
        if ($lock === null) {
            return; // already running
        }
        $code = Process::codeVersion();
        $restart = false;
        try {
            while (Settings::on('feature_slack_bridge')) {
                if (Process::codeVersion() !== $code) {
                    $restart = true; // the tool was updated: start again with the new code
                    break;
                }
                try {
                    $restart = self::serve($code);
                    if ($restart) {
                        break;
                    }
                    sleep(1); // Slack asked to reconnect, or the connection dropped
                } catch (\RuntimeException $e) {
                    self::saveStatus(['error' => $e->getMessage(), 'connected' => false]);
                    sleep($e->getCode() === 401 ? 60 : 15); // a wrong token won't fix itself soon
                }
            }
            if (!$restart) {
                self::saveStatus(['error' => null, 'connected' => false, 'stopped_at' => time()]);
            }
        } finally {
            self::$socket?->close();
            Process::release($lock);
        }
        if ($restart) {
            self::saveStatus(['restarted_at' => time()]);
            Process::spawn('slack');
        }
    }

    public static function ensure(): void
    {
        Process::ensure('slack');
    }

    public static function isRunning(): bool
    {
        return Process::isRunning('slack');
    }

    /** For Settings: running or not, connected, when it last heard from Slack, and the last problem. */
    public static function status(): array
    {
        $state = JsonStore::read('slack');
        return [
            'running' => self::isRunning(),
            'connected' => !empty($state['connected']),
            'checked_at' => $state['checked_at'] ?? null,
            'error' => $state['error'] ?? null,
            'bot' => $state['bot']['name'] ?? null,
            'threads' => count($state['threads'] ?? []),
        ];
    }

    /**
     * One connection: opens it, then answers events until Slack closes it, the speed-up is
     * switched off, or the code changes (returns true then, to restart).
     */
    private static function serve(int $code): bool
    {
        [$api] = self::config();
        $me = $api->whoAmI();
        self::saveStatus(['bot' => ['id' => (string) $me['user_id'], 'name' => (string) ($me['user'] ?? 'bot')]]);
        self::$socket = Socket::open($api->socketUrl());
        self::saveStatus(['connected' => true, 'error' => null, 'checked_at' => time()]);
        $beat = time();
        try {
            while (!self::$socket->closed && Settings::on('feature_slack_bridge')) {
                if (Process::codeVersion() !== $code) {
                    return true;
                }
                $message = self::$socket->receive(2.0);
                if ($message !== null) {
                    self::envelope($message);
                }
                while (self::$queue !== []) {
                    self::handle(array_shift(self::$queue));
                }
                if (time() - $beat >= 15) {
                    self::saveStatus(['checked_at' => time()]);
                    $beat = time();
                }
            }
        } finally {
            self::$socket->close();
            self::$socket = null;
            self::saveStatus(['connected' => false]);
        }
        return false;
    }

    /** One message from Slack's connection: acknowledged at once, events queued. */
    private static function envelope(string $json): void
    {
        $envelope = json_decode($json, true);
        if (!is_array($envelope)) {
            return;
        }
        if (isset($envelope['envelope_id'])) {
            self::$socket?->send((string) json_encode(['envelope_id' => $envelope['envelope_id']]));
        }
        match ($envelope['type'] ?? '') {
            'disconnect' => self::$socket?->close(), // Slack moves us to a fresh connection
            'events_api' => self::$queue[] = ((array) ($envelope['payload']['event'] ?? [])) + ['event_id' => (string) ($envelope['payload']['event_id'] ?? '')],
            default => null,
        };
    }

    /** Reads what arrived meanwhile, while the assistant works on an answer (keeps the connection answered). */
    private static function keepAlive(): void
    {
        for ($i = 0; $i < 20 && self::$socket !== null && !self::$socket->closed; $i++) {
            $message = self::$socket->receive(0.01);
            if ($message === null) {
                return;
            }
            self::envelope($message);
        }
    }

    /**
     * One Slack event: a message in the channel or in a chat's thread, or a ✅ / ❌ reaction
     * to a waiting question. Public for the automatic tests.
     */
    public static function handle(array $event, ?Api $api = null): void
    {
        if (!Settings::on('feature_slack_bridge')) {
            return;
        }
        [$configured, $channel, $users] = self::config();
        $api ??= $configured;
        $state = JsonStore::read('slack');
        if (($state['channel'] ?? null) !== $channel) {
            $state = ['channel' => $channel, 'threads' => [], 'used' => [], 'seen' => [], 'bot' => $state['bot'] ?? null];
        }
        $id = (string) ($event['event_id'] ?? '');
        if ($id !== '' && in_array($id, $state['seen'] ?? [], true)) {
            return; // Slack sent it again
        }
        $state['seen'] = array_slice([...($state['seen'] ?? []), ...($id !== '' ? [$id] : [])], -200);
        JsonStore::write('slack', $state); // before answering: a crash never answers it twice

        $out = new Outbox($api, $channel, static fn () => self::keepAlive());
        $previous = Ai::$remote;
        Ai::$remote = 'Slack'; // AI permissions as narrowed for Slack (Ai::mode)
        try {
            if (($event['type'] ?? '') === 'reaction_added') {
                self::reaction($out, $state, $event, $users);
            } elseif (($event['type'] ?? '') === 'message') {
                self::message($out, $state, $event, $users);
            }
        } finally {
            Ai::$remote = $previous;
        }
        $state['checked_at'] = time();
        JsonStore::write('slack', $state + JsonStore::read('slack'));
    }

    /** @param string[] $users */
    private static function message(Outbox $out, array &$state, array $event, array $users): void
    {
        if (isset($event['subtype']) || isset($event['bot_id']) || ($event['channel'] ?? '') !== $out->channel()
            || !in_array((string) ($event['user'] ?? ''), $users, true)) {
            return; // edits, joins and other notices, bots (and this one), other channels and people
        }
        $text = trim(html_entity_decode((string) ($event['text'] ?? ''), ENT_QUOTES | ENT_HTML5));
        if ($text === '') {
            return;
        }
        $thread = (string) ($event['thread_ts'] ?? '');
        if ($thread !== '' && $thread !== ($event['ts'] ?? '')) {
            if (isset($state['threads'][$thread])) {
                Conversation::inThread($out, $state, $thread, $text);
            }
            return; // a thread the bot didn't open
        }
        Conversation::inChannel($out, $state, $text);
    }

    /** @param string[] $users */
    private static function reaction(Outbox $out, array &$state, array $event, array $users): void
    {
        $reaction = (string) ($event['reaction'] ?? '');
        $item = (array) ($event['item'] ?? []);
        if (!in_array((string) ($event['user'] ?? ''), $users, true) || ($item['channel'] ?? '') !== $out->channel()
            || (!in_array($reaction, self::YES, true) && !in_array($reaction, self::NO, true))) {
            return;
        }
        $thread = array_search((string) ($item['ts'] ?? ''), $state['pending'] ?? [], true);
        if ($thread !== false) {
            Conversation::answer($out, $state, (string) $thread, in_array($reaction, self::YES, true));
        }
    }

    /** @return array{0: Api, 1: string, 2: string[]} the API, the channel and the listed members */
    private static function config(): array
    {
        $bot = Settings::get('slack_bot_token');
        $app = Settings::get('slack_app_token');
        $channel = Settings::get('slack_channel_id');
        $users = preg_split('/[\s,]+/', Settings::get('slack_user_ids'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($bot === '' || $app === '' || $channel === '' || $users === []) {
            throw new \RuntimeException('Fill in both tokens, the channel ID and your member ID in Settings → Speed-ups.');
        }
        return [new Api($bot, $app), $channel, $users];
    }

    private static function saveStatus(array $changes): void
    {
        JsonStore::write('slack', $changes + JsonStore::read('slack'));
    }
}
