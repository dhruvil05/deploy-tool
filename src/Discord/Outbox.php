<?php

declare(strict_types=1);

namespace DeployTool\Discord;

use DeployTool\Remote\Conversation;
use DeployTool\Remote\Outbox as RemoteOutbox;

/** Discord for the shared chat logic: a thread is a Discord thread (a channel of its own). */
final class Outbox implements RemoteOutbox
{
    public function __construct(private readonly Api $api, private readonly string $channel)
    {
    }

    public function name(): string
    {
        return 'Discord';
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function send(string $where, string $markdown): string
    {
        return (string) ($this->api->send($where, Conversation::plain($markdown))['id'] ?? '');
    }

    public function startThread(string $title): string
    {
        return (string) $this->api->createThread($this->channel, $title)['id'];
    }

    public function react(string $where, string $messageId, bool $yes): void
    {
        $this->api->react($where, $messageId, $yes ? '✅' : '❌');
    }

    public function typing(string $where): void
    {
        $this->api->typing($where);
    }
}
