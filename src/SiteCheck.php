<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * After a deploy: do the site's check pages still load without PHP errors?
 *
 * With the speed-up "Smarter checks after a deploy" a site can list several pages (commas),
 * each page is also measured before anything changes (measure()), and the check after the
 * deploy compares: a page that broke, changed its status or got much slower is named.
 * Off: only the first page, as before.
 */
final class SiteCheck
{
    private const ERROR_MARKERS = ['Fatal error', 'Parse error', 'A PHP Error was encountered', 'A Database Error Occurred'];

    /** Slower than this counts as "got slower": twice as slow and at least a second more. */
    private const SLOWER_FACTOR = 2.0;
    private const SLOWER_MS = 1000;

    /** The check pages of a site (several with the speed-up on, else the first). */
    public static function paths(array $site): array
    {
        $paths = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($site['check_path'] ?? '')) ?: [])));
        return Settings::on('feature_smart_checks') ? array_slice(array_unique($paths), 0, 10) : array_slice($paths, 0, 1);
    }

    /**
     * Each check page as it is now, before a deploy changes anything: path => [ok, status, ms].
     * Empty when the site has no address or check page, or the speed-up is off.
     */
    public static function measure(array $site): array
    {
        if (!Settings::on('feature_smart_checks') || trim((string) ($site['url'] ?? '')) === '') {
            return [];
        }
        $pages = [];
        foreach (self::paths($site) as $path) {
            $page = self::page($site, $path);
            $pages[$path] = [$page['ok'], $page['status'], $page['ms']];
        }
        return $pages;
    }

    /**
     * @param array $before measure() from before the deploy, to compare with
     * @return array{ok: bool, skipped: bool, message: string, pages?: list<array>}
     */
    public static function run(array $site, array $before = []): array
    {
        $paths = self::paths($site);
        $base = trim((string) ($site['url'] ?? ''));
        if ($paths === [] || $base === '') {
            return ['ok' => true, 'skipped' => true, 'message' => 'Skipped: no site address or check page set for this site.'];
        }
        $pages = [];
        $problems = [];
        foreach ($paths as $path) {
            $page = self::page($site, $path);
            $was = $before[$path] ?? null;
            if ($was !== null && $page['ok']) {
                if ($was[1] !== $page['status']) {
                    $page['note'] = "status changed from $was[1] to {$page['status']}";
                } elseif ($page['ms'] > max(self::SLOWER_FACTOR * $was[2], $was[2] + self::SLOWER_MS)) {
                    $page['note'] = 'got slower: ' . self::seconds($was[2]) . ' → ' . self::seconds($page['ms']);
                }
            } elseif ($was !== null && !$page['ok'] && $was[0]) {
                $page['note'] = 'worked before the deploy';
            }
            $pages[] = $page;
            if (!$page['ok']) {
                $problems[] = $page['problem'] . (isset($page['note']) ? ' (' . $page['note'] . ')' : '');
            }
        }
        $ok = $problems === [];
        if (count($pages) === 1 && $before === []) {
            $message = $pages[0]['ok'] ? $pages[0]['url'] . ' loads (HTTP 200, no PHP errors).' . $pages[0]['certNote'] : $problems[0] . $pages[0]['certNote'];
        } else {
            $slower = array_filter($pages, static fn (array $p): bool => $p['ok'] && isset($p['note']));
            $message = $ok
                ? count($pages) . ' page' . (count($pages) === 1 ? '' : 's') . ' load (HTTP 200, no PHP errors)' . ($slower ? '; ' . implode('; ', array_map(static fn (array $p): string => $p['path'] . ' ' . $p['note'], $slower)) : '') . '.'
                : implode(' ', $problems);
        }
        return ['ok' => $ok, 'skipped' => false, 'message' => $message, 'pages' => $pages];
    }

    /** One page: loads it, looks for PHP errors, times it. Also used by the uptime monitor. */
    public static function page(array $site, string $path): array
    {
        $url = rtrim(trim((string) $site['url']), '/') . '/' . ltrim($path, '/');
        // The certificate is checked first. This only reads a public page and sends nothing,
        // so when it can't be checked the page is still loaded, and the result says so.
        $certNote = '';
        $started = microtime(true);
        [$body, $status, $errno, $error] = self::load($url, !Fingerprint::isIpUrl($url));
        if ($body === false && in_array($errno, Fingerprint::CERTIFICATE_ERRORS, true)) {
            $certNote = " The HTTPS certificate could not be checked ($error).";
            $started = microtime(true);
            [$body, $status, , $error] = self::load($url, false);
        }
        $page = ['path' => $path, 'url' => $url, 'status' => $status, 'ms' => (int) round((microtime(true) - $started) * 1000), 'ok' => false, 'problem' => '', 'certNote' => $certNote];
        if ($body === false) {
            return ['problem' => "Could not load $url: $error"] + $page;
        }
        foreach (self::ERROR_MARKERS as $marker) {
            if (stripos((string) $body, $marker) !== false) {
                return ['problem' => "$url shows \"$marker\"."] + $page;
            }
        }
        if ($status !== 200) {
            return ['problem' => "$url returned HTTP $status."] + $page;
        }
        return ['ok' => true] + $page;
    }

    private static function seconds(int $ms): string
    {
        return number_format($ms / 1000, 1) . ' s';
    }

    /** @return array{0: string|false, 1: int, 2: int, 3: string} body, HTTP status, curl error number and text */
    private static function load(string $url, bool $verify): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Deploy tool site check',
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);
        $body = curl_exec($curl);
        return [$body, (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), curl_errno($curl), curl_error($curl)];
    }
}
