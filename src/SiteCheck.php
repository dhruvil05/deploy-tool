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
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Deploy tool site check',
            // Only reads a public page and sends nothing; Windows PHP often has no CA bundle.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($body === false) {
            return ['ok' => false, 'skipped' => false, 'message' => "Could not load $url: " . curl_error($curl)];
        }
        foreach (self::ERROR_MARKERS as $marker) {
            if (stripos((string) $body, $marker) !== false) {
                return ['ok' => false, 'skipped' => false, 'message' => "$url shows \"$marker\"."];
            }
        }
        if ($status !== 200) {
            return ['ok' => false, 'skipped' => false, 'message' => "$url returned HTTP $status."];
        }
        return ['ok' => true, 'skipped' => false, 'message' => "$url loads (HTTP 200, no PHP errors)."];
    }
}
