<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Speed-up "Uptime and certificate monitor": every few minutes (Scheduler) each site with an
 * address loads its check pages (or its home page), and its HTTPS certificate's end date is
 * read. Alerts when a site goes down (two failed checks in a row, so one hiccup isn't
 * reported), when it is back up, and once a day while the certificate ends within 14 days.
 * Results: storage/monitor.json, shown on the site's card.
 */
final class Monitor
{
    public const CERT_WARN_DAYS = 14;

    public static function minutes(): int
    {
        return max(1, min(60, (int) Settings::get('monitor_minutes')));
    }

    /** @return array<string, array> site key => the latest result */
    public static function results(): array
    {
        return JsonStore::read('monitor');
    }

    public static function result(string $site): ?array
    {
        return self::results()[$site] ?? null;
    }

    public static function due(array $site): bool
    {
        return trim((string) $site['url']) !== '' && time() - (int) (self::result($site['key'])['checked_at'] ?? 0) >= self::minutes() * 60;
    }

    /** Checks one site now, saves the result, and alerts on a change. */
    public static function check(array $site): array
    {
        $previous = self::result($site['key']) ?? ['up' => true, 'fails' => 0, 'down_since' => null];
        $paths = SiteCheck::paths($site) ?: ['/'];
        $pages = [];
        foreach (array_slice($paths, 0, 5) as $path) {
            $pages[] = SiteCheck::page($site, $path);
        }
        $failed = array_values(array_filter($pages, static fn (array $p): bool => !$p['ok']));
        $now = time();
        $result = [
            'checked_at' => $now,
            'ok' => $failed === [],
            'problem' => $failed[0]['problem'] ?? null,
            'ms' => max(array_column($pages, 'ms') ?: [0]),
            'fails' => $failed === [] ? 0 : (int) $previous['fails'] + 1,
            'up' => (bool) $previous['up'],
            'down_since' => $previous['down_since'],
            'cert' => self::certificate((string) $site['url']),
            'cert_alerted' => $previous['cert_alerted'] ?? null,
        ];
        if ($result['up'] && $result['fails'] >= 2) {
            $result['up'] = false;
            $result['down_since'] = $now - self::minutes() * 60;
            Alerts::send("🔴 **{$site['name']}** is down: {$result['problem']}");
        } elseif (!$result['up'] && $result['ok']) {
            $minutes = (int) round(($now - (int) $result['down_since']) / 60);
            $result['up'] = true;
            $result['down_since'] = null;
            Alerts::send("🟢 **{$site['name']}** is back up after about $minutes minute(s).");
        }
        $days = $result['cert']['days'] ?? null;
        if ($days !== null && $days <= self::CERT_WARN_DAYS && $result['cert_alerted'] !== date('Y-m-d')) {
            $result['cert_alerted'] = date('Y-m-d');
            Alerts::send("🔒 The HTTPS certificate of **{$site['name']}** " . ($days < 0 ? 'has expired' : "ends in $days day(s) (" . date('d M Y', (int) $result['cert']['ends']) . ')') . '. Renew it before visitors see a warning.');
        }
        $results = self::results();
        $results[$site['key']] = $result;
        JsonStore::write('monitor', $results);
        return $result;
    }

    /**
     * The site's HTTPS certificate: when it ends and in how many days, or why it couldn't be
     * read. Null for http:// addresses and IP addresses.
     *
     * @return array{ends?: int, days?: int, error?: string}|null
     */
    public static function certificate(string $url): ?array
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || Fingerprint::isIpUrl($url)) {
            return null;
        }
        $host = (string) $parts['host'];
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $client = @stream_socket_client("ssl://$host:" . (int) ($parts['port'] ?? 443), $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if ($client === false) {
            return ['error' => $error !== '' ? $error : 'the certificate could not be checked'];
        }
        $certificate = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
        fclose($client);
        $info = $certificate !== null ? openssl_x509_parse($certificate) : false;
        if ($info === false || !isset($info['validTo_time_t'])) {
            return ['error' => 'the certificate could not be read'];
        }
        $ends = (int) $info['validTo_time_t'];
        return ['ends' => $ends, 'days' => (int) floor(($ends - time()) / 86400)];
    }
}
