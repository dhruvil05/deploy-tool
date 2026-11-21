<?php

declare(strict_types=1);

namespace DeployTool\Slack;

use DeployTool\Remote\Conversation;
use DeployTool\Remote\Outbox as RemoteOutbox;

/**
 * Slack for the shared chat logic: a chat's thread is the thread under the bot's own
 * "🧵 <title>" message in the channel, named by that message's ts.
 */
final class Outbox implements RemoteOutbox
{
    /** @param ?\Closure $keepAlive called while the assistant works, to answer the live connection */
    public function __construct(private readonly Api $api, private readonly string $channel, private readonly ?\Closure $keepAlive = null)
    {
    }

    public function name(): string
    {
        return 'Slack';
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function send(string $where, string $markdown): string
    {
        return $this->api->post($this->channel, self::mrkdwn($markdown), $where === $this->channel ? null : $where);
    }

    public function startThread(string $title): string
    {
        return $this->api->post($this->channel, '🧵 *' . self::escape($title) . '*');
    }

    public function react(string $where, string $messageId, bool $yes): void
    {
        $this->api->react($this->channel, $messageId, $yes ? 'white_check_mark' : 'x');
    }

    /** Slack has no "typing…" for bots; the time is used to keep the live connection answered. */
    public function typing(string $where): void
    {
        if ($this->keepAlive !== null) {
            ($this->keepAlive)();
        }
    }

    /**
     * The tool's Markdown as Slack's "mrkdwn": & < > escaped, [text](https://…) as <https://…|text>,
     * **bold** as *bold*, *italic* as _italic_, headings as bold lines.
     */
    public static function mrkdwn(string $markdown): string
    {
        $text = self::escape(Conversation::plain($markdown));
        $text = (string) preg_replace('/\[([^\]]+)\]\((https?:[^)\s]+)\)/', '<$2|$1>', $text);
        $text = (string) preg_replace('/(?<![*\w])\*(?!\*|\s)([^*\n]+?)(?<!\s)\*(?![*\w])/u', '_$1_', $text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/us', '*$1*', $text);
        return (string) preg_replace('/^#{1,6}\s+(.+)$/mu', '*$1*', $text);
    }

    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
