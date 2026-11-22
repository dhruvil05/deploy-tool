<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Warnings from the background checks (server watch, uptime monitor): to the chat webhook
 * (speed-up "Messages to Slack, Teams or Discord") and to the Discord and Slack channels the
 * assistant reads, whichever are set up. A failure to send is only logged in the result.
 *
 * @return list<string> where it went or why it didn't
 */
final class Alerts
{
    public static function send(string $text): array
    {
        $sent = [];
        $webhook = Settings::get('notify_webhook');
        if (Settings::on('feature_notify') && $webhook !== '' && !Secrets::isSealed($webhook)) {
            $error = Notify::send($webhook, $text);
            $sent[] = $error === null ? 'webhook' : "webhook: $error";
        }
        if (Settings::on('feature_discord_bridge') && Settings::get('discord_bot_token') !== '' && Settings::get('discord_channel_id') !== '') {
            try {
                (new Discord\Api(Settings::get('discord_bot_token')))->send(Settings::get('discord_channel_id'), $text);
                $sent[] = 'discord';
            } catch (\RuntimeException $e) {
                $sent[] = 'discord: ' . $e->getMessage();
            }
        }
        if (Settings::on('feature_slack_bridge') && Settings::get('slack_bot_token') !== '' && Settings::get('slack_channel_id') !== '') {
            try {
                (new Slack\Api(Settings::get('slack_bot_token')))->post(Settings::get('slack_channel_id'), Slack\Outbox::mrkdwn($text));
                $sent[] = 'slack';
            } catch (\RuntimeException $e) {
                $sent[] = 'slack: ' . $e->getMessage();
            }
        }
        return $sent;
    }
}
