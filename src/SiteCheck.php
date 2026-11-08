<?php

declare(strict_types=1);

namespace DeployTool;

/** After a deploy: does the site's check page still load without PHP errors? */
final class SiteCheck
{
    private const ERROR_MARKERS = ['Fatal error', 'Parse error', 'A PHP Error was encountered', 'A Database Error Occurred'];

    /** @return array{ok: bool, skipped: bool, message: string} */
    public static function run(array $site): array
    {
        $path = trim((string) ($site['check_path'] ?? ''));
        $base = trim((string) ($site['url'] ?? ''));
        if ($path === '' || $base === '') {
            return ['ok' => true, 'skipped' => true, 'message' => 'Skipped: no site address or check page set for this site.'];
        }

        $url = rtrim($base, '/') . '/' . ltrim($path, '/');
        // The certificate is checked first. This only reads a public page and sends nothing,
        // so when it can't be checked the page is still loaded, and the result says so.
        $note = '';
        [$body, $status, $errno, $error] = self::load($url, !Fingerprint::isIpUrl($url));
        if ($body === false && in_array($errno, Fingerprint::CERTIFICATE_ERRORS, true)) {
            $note = " The HTTPS certificate could not be checked ($error).";
            [$body, $status, , $error] = self::load($url, false);
        }

        if ($body === false) {
            return ['ok' => false, 'skipped' => false, 'message' => "Could not load $url: $error"];
        }
        foreach (self::ERROR_MARKERS as $marker) {
            if (stripos((string) $body, $marker) !== false) {
                return ['ok' => false, 'skipped' => false, 'message' => "$url shows \"$marker\".$note"];
            }
        }
        if ($status !== 200) {
            return ['ok' => false, 'skipped' => false, 'message' => "$url returned HTTP $status.$note"];
        }
        return ['ok' => true, 'skipped' => false, 'message' => "$url loads (HTTP 200, no PHP errors).$note"];
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
