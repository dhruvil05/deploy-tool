<?php

declare(strict_types=1);

namespace DeployTool\Cloud;

/**
 * A small client for S3-compatible storage (Cloudflare R2, Backblaze B2, Amazon S3,
 * Wasabi, MinIO…): put, get, list and delete objects in one bucket, signed with AWS
 * Signature Version 4. Path-style addresses (endpoint/bucket/key), which all of them accept.
 */
final class S3
{
    private const TIMEOUT = 300;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $bucket,
        private readonly string $accessKey,
        private readonly string $secretKey,
    ) {
    }

    public function put(string $key, string $body): void
    {
        $this->request('PUT', $key, [], $body, ['content-type' => 'application/octet-stream']);
    }

    public function get(string $key): string
    {
        return $this->request('GET', $key)[1];
    }

    public function delete(string $key): void
    {
        $this->request('DELETE', $key);
    }

    /** @return list<array{key: string, size: int, modified: int}> objects under $prefix, all pages */
    public function list(string $prefix, int $max = 0): array
    {
        $objects = [];
        $token = null;
        do {
            $query = ['list-type' => '2', 'prefix' => $prefix] + ($token !== null ? ['continuation-token' => $token] : []) + ($max > 0 ? ['max-keys' => (string) $max] : []);
            $xml = self::xml($this->request('GET', '', $query)[1]);
            foreach ($xml->Contents ?? [] as $item) {
                $objects[] = ['key' => (string) $item->Key, 'size' => (int) $item->Size, 'modified' => (int) strtotime((string) $item->LastModified)];
            }
            $token = (string) ($xml->IsTruncated ?? '') === 'true' && $max === 0 ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');
        return $objects;
    }

    /**
     * One signed request. Returns [status, body]; throws with the provider's own message
     * (never the keys) when it fails.
     *
     * @return array{0: int, 1: string}
     */
    private function request(string $method, string $key, array $query = [], string $body = '', array $headers = []): array
    {
        $url = parse_url(rtrim($this->endpoint, '/'));
        if (!isset($url['scheme'], $url['host'])) {
            throw new \RuntimeException('The endpoint is not a valid address.');
        }
        $host = $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
        $path = rtrim($url['path'] ?? '', '/') . '/' . rawurlencode($this->bucket)
            . ($key !== '' ? '/' . implode('/', array_map('rawurlencode', explode('/', $key))) : '');

        ksort($query);
        $canonicalQuery = implode('&', array_map(static fn (string $k, string $v): string => rawurlencode($k) . '=' . rawurlencode($v), array_keys($query), $query));
        $payloadHash = hash('sha256', $body);
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $headers = array_change_key_case($headers) + ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
        ksort($headers);
        $canonicalHeaders = implode('', array_map(static fn (string $k, string $v): string => $k . ':' . trim($v) . "\n", array_keys($headers), $headers));
        $signedHeaders = implode(';', array_keys($headers));
        $scope = "$date/{$this->region}/s3/aws4_request";
        $canonicalRequest = "$method\n$path\n$canonicalQuery\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
        $toSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);
        $signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $this->region, hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true), true), true), true);
        $headers['authorization'] = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/$scope, SignedHeaders=$signedHeaders, Signature=" . hash_hmac('sha256', $toSign, $signingKey);
        unset($headers['host']);

        $curl = curl_init($url['scheme'] . '://' . $host . $path . ($canonicalQuery !== '' ? '?' . $canonicalQuery : ''));
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_map(static fn (string $k, string $v): string => "$k: $v", array_keys($headers), $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($method === 'PUT') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new \RuntimeException("Couldn't reach {$url['host']}: $error");
        }
        if ($status >= 300) {
            $code = $message = '';
            if (str_starts_with(ltrim((string) $response), '<')) {
                $xml = @simplexml_load_string((string) $response);
                $code = $xml !== false ? (string) $xml->Code : '';
                $message = $xml !== false ? rtrim((string) $xml->Message, '. ') : '';
            }
            throw new \RuntimeException(match (true) {
                $code === 'NoSuchBucket' => "The bucket {$this->bucket} doesn't exist (or isn't in this account).",
                $code === 'NoSuchKey' || $status === 404 => 'That file is not in the bucket.',
                in_array($code, ['InvalidAccessKeyId', 'SignatureDoesNotMatch', 'AccessDenied'], true) || $status === 403 => 'The storage refused the keys' . ($message !== '' ? ": $message." : '.') . ' Check the access key, secret key and region.',
                default => 'The storage answered with an error (HTTP ' . $status . ($code !== '' ? ", $code" : '') . ')' . ($message !== '' ? ": $message" : '.'),
            });
        }
        return [$status, (string) $response];
    }

    private static function xml(string $text): \SimpleXMLElement
    {
        $xml = @simplexml_load_string($text);
        if ($xml === false) {
            throw new \RuntimeException('The storage answered with something that is not a file list.');
        }
        return $xml;
    }
}
