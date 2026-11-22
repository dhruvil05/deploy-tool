<?php

declare(strict_types=1);

namespace DeployTool\Remote;

use DeployTool\Ai;
use DeployTool\Ai\Agent;
use DeployTool\Ai\ChatStore;
use DeployTool\Ai\QuickCommands;
use DeployTool\Settings;
use DeployTool\Sources;

/**
 * The assistant in a chat app (Discord, Slack), the same for each app:
 *
 *  - In the bot's channel: !new <local> starts an assistant chat and opens a thread for it;
 *    !chats lists recent chats, !open <number> opens a thread for one of them; !help.
 *  - In a chat's thread: !status, !history (and !compare, !deploy unless the app is set to
 *    "Only read") run at once; anything else goes to the assistant. Actions that need asking
 *    post a "Confirm: …?" question, answered with ✅ / ❌ or yes / no.
 *
 * The chats are the tool's own (storage/chats), so the Assistant page shows them too. What
 * the assistant may do comes from AI permissions, narrowed by the app's own setting
 * (Ai::$remote, Ai::mode). $state is the app's state: threads => chat id, pending questions.
 */
final class Conversation
{
    /** Quick commands that only read; !compare and !deploy also work unless the app is set to "Only read". */
    private const READ_COMMANDS = ['status', 'history'];

    /** Replies that answer a confirm question, besides the ✅ and ❌ reactions. */
    private const YES = '/^(y|yes|ok|okay|allow|go|go ahead|do it|sure|✅|👍|:white_check_mark:|:\+1:)[.!]*$/iu';
    private const NO = '/^(n|no|nope|deny|cancel|don\'?t|stop|❌|👎|:x:|:-1:)[.!]*$/iu';

    /** Splits text into parts of at most $max characters, at line breaks where possible (each app has a limit). */
    public static function split(string $text, int $max): array
    {
        $text = trim($text) === '' ? '(empty answer)' : trim($text);
        $parts = [];
        while (mb_strlen($text) > $max) {
            $chunk = mb_substr($text, 0, $max);
            $cut = mb_strrpos($chunk, "\n");
            $cut = $cut !== false && $cut > $max / 2 ? $cut : $max;
            $parts[] = rtrim(mb_substr($text, 0, $cut));
            $text = ltrim(mb_substr($text, $cut));
        }
        $parts[] = $text;
        return $parts;
    }

    /** Whether the app is set to "Only read" (Settings → Speed-ups). */
    public static function readOnly(Outbox $out): bool
    {
        return Settings::get(strtolower($out->name()) . '_actions') === 'read';
    }

    public static function help(Outbox $out): string
    {
        $app = $out->name();
        return "**Deploy tool assistant**\n"
            . "In this channel:\n"
            . "- `!new <local>`: start a chat about a local; it opens a thread here\n"
            . "- `!chats`: the latest chats · `!open <number>`: open a thread for one\n"
            . "In a chat's thread:\n"
            . (self::readOnly($out)
                ? "- ask anything, e.g. *is staging up to date?* or *what changed in the last deploy?*\n"
                    . "- `!status`, `!status <site>`, `!history`: instant answers, no AI\n"
                    . "From $app it only reads (Settings → Speed-ups → $app chat); actions are done in the tool."
                : "- ask or ask for things, e.g. *is staging up to date?*, *compare staging*, *prepare a deploy to staging*\n"
                    . "- `!status`, `!status <site>`, `!history`, `!compare <site>`, `!deploy <site>`: instant, no AI\n"
                    . "- before an action it asks: react ✅ or ❌ (or reply yes / no)\n"
                    . 'It may do what AI permissions allow. Approving a production deploy is always done in the tool.');
    }

    /** A message in the bot's channel: managing chats. */
    public static function inChannel(Outbox $out, array &$state, string $text): void
    {
        $channel = $out->channel();
        [$command, $argument] = array_pad(preg_split('/\s+/', ltrim($text, '!'), 2) ?: [''], 2, '');
        if (!str_starts_with($text, '!')) {
            $out->send($channel, "Ask in a chat's thread: start one with `!new <local>`, or `!chats` to continue one. `!help` lists everything.");
            return;
        }
        switch (strtolower($command)) {
            case 'new':
                $source = self::findLocal(trim($argument));
                if (is_string($source)) {
                    $out->send($channel, $source);
                    return;
                }
                $chat = ChatStore::create($source['key']);
                ChatStore::save($chat);
                $thread = self::openThread($out, $state, $chat, $source['name'] . ' · chat');
                $out->send($thread, "Chat about **{$source['name']}** started. Ask here, e.g. *is staging up to date?* · `!status` · `!history` · `!help`\nThe same chat is on the tool's Assistant page.");
                return;
            case 'chats':
                $chats = array_slice(ChatStore::all(), 0, 10);
                $state['listed'] = array_column($chats, 'id');
                if ($chats === []) {
                    $out->send($channel, 'No chats yet. Start one with `!new <local>`.');
                    return;
                }
                $threads = array_flip($state['threads'] ?? []);
                $lines = ['**Latest chats** (`!open <number>` opens a thread):'];
                foreach ($chats as $i => $chat) {
                    $local = Sources::get((string) $chat['local'])['name'] ?? (string) $chat['local'];
                    $lines[] = ($i + 1) . '. ' . $chat['title'] . " · $local · " . \ago((int) $chat['updated_at']) . (isset($threads[$chat['id']]) ? ' · has a thread' : '');
                }
                $out->send($channel, implode("\n", $lines));
                return;
            case 'open':
                $chatId = $state['listed'][(int) trim($argument) - 1] ?? trim($argument);
                $chat = ChatStore::load((string) $chatId);
                if ($chat === null) {
                    $out->send($channel, 'Which chat? Use `!chats`, then `!open <number>`.');
                    return;
                }
                $existing = array_search($chat['id'], $state['threads'] ?? [], true);
                if ($existing !== false) {
                    $out->send((string) $existing, 'This chat continues here.');
                    return;
                }
                $thread = self::openThread($out, $state, $chat, $chat['title']);
                $last = '';
                foreach (array_reverse($chat['messages']) as $message) {
                    if ($message['role'] === 'assistant' && trim((string) ($message['content'] ?? '')) !== '') {
                        $last = "\nLast answer:\n" . self::plain((string) $message['content']);
                        break;
                    }
                }
                $out->send($thread, "Continuing **{$chat['title']}**.$last");
                return;
            default:
                $out->send($channel, self::help($out));
        }
    }

    /** A message in a chat's thread: quick commands, a yes / no to a confirm question, or a message for the assistant. */
    public static function inThread(Outbox $out, array &$state, string $thread, string $text): void
    {
        $chat = ChatStore::load((string) ($state['threads'][$thread] ?? ''));
        if ($chat === null) {
            $out->send($thread, 'This chat was deleted in the tool. Start a new one in the channel with `!new <local>`.');
            return;
        }
        $state['used'][$thread] = time();
        $waiting = Agent::pendingIndex($chat) !== null;
        $readOnly = self::readOnly($out);
        if (str_starts_with($text, '!')) {
            $command = strtolower((string) preg_replace('/^!(\S+).*$/s', '$1', $text));
            if (in_array($command, ['help', '?'], true)) {
                $out->send($thread, self::help($out));
                return;
            }
            if (!in_array($command, $readOnly ? self::READ_COMMANDS : [...self::READ_COMMANDS, 'compare', 'deploy'], true)) {
                $out->send($thread, $readOnly && in_array($command, ['compare', 'deploy'], true)
                    ? "From {$out->name()} the assistant only reads (Settings → Speed-ups → {$out->name()} chat). Start compares and deploys in the tool."
                    : "There's no command `!$command` here. `!help` lists what works.");
                return;
            }
            if ($waiting) {
                $out->send($thread, 'First answer the question above: react ✅ or ❌, or reply yes / no.');
                return;
            }
            self::converse($out, $state, $thread, $chat, static fn (Agent $agent, array &$c) => QuickCommands::handle($c, '/' . substr($text, 1)));
            return;
        }
        if (!Ai::enabled()) {
            $out->send($thread, 'The AI assistant is switched off in the tool. `!status` and `!history` still work.');
            return;
        }
        if ($waiting && (preg_match(self::YES, $text) || preg_match(self::NO, $text))) {
            self::answer($out, $state, $thread, preg_match(self::YES, $text) === 1);
            return;
        }
        unset($state['pending'][$thread]); // a new message instead of an answer counts as "no" (Agent::reply)
        self::converse($out, $state, $thread, $chat, static fn (Agent $agent, array &$c, callable $checkpoint) => $agent->reply($c, $text, $checkpoint));
    }

    /** The user's ✅ / ❌ (or yes / no) to the thread's waiting question: the actions run, or not. */
    public static function answer(Outbox $out, array &$state, string $thread, bool $allow): void
    {
        unset($state['pending'][$thread]);
        $chat = ChatStore::load((string) ($state['threads'][$thread] ?? ''));
        if ($chat === null || Agent::pendingIndex($chat) === null) {
            return; // already answered, for example in the tool
        }
        self::converse($out, $state, $thread, $chat, static fn (Agent $agent, array &$c, callable $checkpoint) => $agent->resume($c, $allow, $checkpoint), true);
    }

    /**
     * The tool's Markdown for a chat app: links to the tool's own pages (which don't open
     * there) keep only their text and run id, and "/command" hints become "!command".
     */
    public static function plain(string $markdown): string
    {
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\((?!https?:)([^)]*)\)/', static function (array $m): string {
            if (preg_match('/[?&]id=([\w-]+)/', $m[2], $id) && !str_contains($m[1], $id[1])) {
                return "{$m[1]} (run `{$id[1]}`)";
            }
            return $m[1];
        }, $markdown);
        return (string) preg_replace('/`\/(status|history|help|compare|deploy)\b/', '`!$1', $text);
    }

    /**
     * Runs $work on the chat (holding its lock, so the tool's page and the app never answer at
     * once), then posts what came of it: the answers, the actions done, and a confirm question
     * when actions wait for ✅. $resuming: the waiting question's actions run now, so they count
     * as new. While the assistant works, $out->typing() is called every few seconds, which
     * also keeps a live connection (Slack) answered.
     */
    private static function converse(Outbox $out, array &$state, string $thread, array $chat, callable $work, bool $resuming = false): void
    {
        $lock = ChatStore::lock($chat['id']);
        if ($lock === null) {
            $out->send($thread, 'This chat is answering a message in the tool right now. Send yours again in a moment.');
            return;
        }
        $from = $resuming ? (int) Agent::pendingIndex($chat) : count($chat['messages']);
        try {
            $out->typing($thread);
            $typedAt = time();
            $work(Agent::fromSettings(), $chat, static function (array $progress) use ($out, $thread, &$typedAt): bool {
                try {
                    ChatStore::save($progress); // the tool's page shows the progress too
                } catch (\RuntimeException) {
                }
                if (time() - $typedAt >= 8) {
                    $out->typing($thread);
                    $typedAt = time();
                }
                return ChatStore::stopRequested($progress['id']);
            });
            ChatStore::save($chat);
        } catch (\RuntimeException $e) {
            $out->send($thread, '⚠️ ' . $e->getMessage());
            return;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $lines = [];
        foreach (array_slice($chat['messages'], $from) as $message) {
            if ($message['role'] !== 'assistant') {
                continue;
            }
            foreach ($message['actions'] ?? [] as $action) {
                $lines[] = ($action['ok'] ? '✔ ' : '✖ ') . $action['label'];
            }
            $content = trim((string) ($message['content'] ?? ''));
            if ($content !== '' && empty($message['pending'])) {
                $lines[] = (!empty($message['error']) ? '⚠️ ' : '') . self::plain($content);
            }
        }
        $index = Agent::pendingIndex($chat);
        if ($index === null) {
            $out->send($thread, $lines !== [] ? implode("\n\n", $lines) : 'No answer came back. Look at the chat in the tool.');
            return;
        }
        // Actions that wait for the user: one question, answered with a reaction or yes / no.
        $asking = $chat['messages'][$index];
        $intro = trim((string) ($asking['content'] ?? ''));
        $question = ($lines !== [] ? implode("\n\n", $lines) . "\n\n" : '') . ($intro !== '' ? self::plain($intro) . "\n\n" : '')
            . '**Confirm:** ' . implode('; ', (array) ($asking['asking'] ?? ['go ahead'])) . "?\nReact ✅ to allow or ❌ to deny, or reply yes / no.";
        $posted = $out->send($thread, $question);
        $state['pending'][$thread] = $posted;
        foreach ([true, false] as $yes) {
            try {
                $out->react($thread, $posted, $yes);
            } catch (\RuntimeException) {
                // the bot may not add reactions: the user's own reaction or a yes / no still works
            }
        }
    }

    /** A local by key or part of its name, or a message saying which ones exist. */
    private static function findLocal(string $name): array|string
    {
        $sources = Sources::all();
        if ($name === '' && count($sources) === 1) {
            return current($sources);
        }
        $matches = array_filter($sources, static fn (array $s): bool => $name !== ''
            && (strcasecmp($s['key'], $name) === 0 || stripos($s['name'], $name) !== false));
        if (count($matches) === 1) {
            return current($matches);
        }
        $list = implode(', ', array_map(static fn (array $s): string => "`{$s['key']}` ({$s['name']})", $sources));
        return ($name === '' ? 'Which local?' : "No single local matches \"$name\".") . " Use `!new <local>` with one of: $list.";
    }

    private static function openThread(Outbox $out, array &$state, array $chat, string $title): string
    {
        $thread = $out->startThread($title);
        $state['threads'][$thread] = $chat['id'];
        $state['used'][$thread] = time();
        return $thread;
    }
}
