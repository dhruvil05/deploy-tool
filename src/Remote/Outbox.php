<?php

declare(strict_types=1);

namespace DeployTool\Remote;

/**
 * What the shared chat logic (Conversation) needs from a chat app such as Discord or Slack.
 * Places are opaque strings: the main channel (channel()) or a chat's thread.
 */
interface Outbox
{
    /** The app's name as the user knows it: "Discord", "Slack". */
    public function name(): string;

    /** The one channel the bot reads, where chats are started. */
    public function channel(): string;

    /** Posts the tool's Markdown, converted to what the app shows; returns the (last) message's id. */
    public function send(string $where, string $markdown): string;

    /** Starts a thread for a chat in the channel; returns the thread, as send() takes it. */
    public function startThread(string $title): string;

    /** Puts the bot's ✅ ($yes) or ❌ under a message, so the user only has to tap it. */
    public function react(string $where, string $messageId, bool $yes): void;

    /** Shows that the bot is working, where the app has such a thing. */
    public function typing(string $where): void;
}
