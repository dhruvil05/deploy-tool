<?php

declare(strict_types=1);

namespace DeployTool\Slack;

/**
 * A minimal WebSocket client (RFC 6455) for Slack's Socket Mode: the tool connects out to
 * Slack, Slack sends events down the connection. Text messages only; pings are answered.
 * wss:// with a verified certificate; plain ws:// only to 127.0.0.1 (the automatic tests).
 */
final class Socket
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var resource */
    private $stream;
    private string $buffer = '';
    private string $partial = '';
    public bool $closed = false;

    private function __construct($stream, string $leftover)
    {
        $this->stream = $stream;
        $this->buffer = $leftover;
    }

    public static function open(string $url, float $timeout = 15.0): self
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        $host = (string) ($parts['host'] ?? '');
        if ($scheme === 'ws' && $host !== '127.0.0.1') {
            throw new \RuntimeException('Only secure connections (wss://) are used.');
        }
        if (!in_array($scheme, ['ws', 'wss'], true) || $host === '') {
            throw new \RuntimeException('Not a WebSocket address.');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'wss' ? 443 : 80));
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $stream = @stream_socket_client(($scheme === 'wss' ? 'tls://' : 'tcp://') . "$host:$port", $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new \RuntimeException("Could not connect to Slack: $error");
        }
        stream_set_timeout($stream, (int) $timeout);
        $key = base64_encode(random_bytes(16));
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        fwrite($stream, "GET $path HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fread($stream, 4096);
            if ($chunk === false || $chunk === '') {
                fclose($stream);
                throw new \RuntimeException('Slack closed the connection while opening it.');
            }
            $response .= $chunk;
        }
        [$head, $leftover] = explode("\r\n\r\n", $response, 2);
        $accept = base64_encode(sha1($key . self::GUID, true));
        if (!preg_match('#^HTTP/1\.1 101#', $head) || !preg_match('/^Sec-WebSocket-Accept:\s*' . preg_quote($accept, '/') . '\s*$/mi', $head)) {
            fclose($stream);
            throw new \RuntimeException('Slack did not accept the connection: ' . strtok($head, "\r\n"));
        }
        return new self($stream, $leftover);
    }

    /** The next text message, waiting at most $seconds; null when none came (or the connection closed). */
    public function receive(float $seconds): ?string
    {
        $until = microtime(true) + $seconds;
        while (!$this->closed) {
            $frame = $this->frame();
            if ($frame !== null) {
                [$fin, $opcode, $payload] = $frame;
                if ($opcode === 0x9) {
                    $this->write(0xA, $payload); // ping → pong
                } elseif ($opcode === 0x8) {
                    $this->closed = true;
                    $this->quietly(fn () => $this->write(0x8, ''));
                } elseif ($opcode === 0x1 || $opcode === 0x0) {
                    $this->partial .= $payload;
                    if ($fin) {
                        $message = $this->partial;
                        $this->partial = '';
                        return $message;
                    }
                }
                continue;
            }
            $left = $until - microtime(true);
            if ($left <= 0) {
                return null;
            }
            stream_set_timeout($this->stream, (int) $left, (int) (($left - (int) $left) * 1_000_000));
            $chunk = fread($this->stream, 65536);
            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                $this->closed = true;
                return null;
            }
            $this->buffer .= $chunk;
        }
        return null;
    }

    public function send(string $text): void
    {
        $this->write(0x1, $text);
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->quietly(fn () => $this->write(0x8, ''));
            $this->closed = true;
        }
        @fclose($this->stream);
    }

    /** Saying goodbye on a connection that may already be gone. */
    private function quietly(callable $goodbye): void
    {
        try {
            $goodbye();
        } catch (\RuntimeException) {
        }
    }

    /** @return array{0: bool, 1: int, 2: string}|null one whole frame from the buffer: fin, opcode, payload */
    private function frame(): ?array
    {
        if (strlen($this->buffer) < 2) {
            return null;
        }
        $first = ord($this->buffer[0]);
        $second = ord($this->buffer[1]);
        $length = $second & 0x7F;
        $offset = 2;
        if ($length === 126) {
            if (strlen($this->buffer) < 4) {
                return null;
            }
            $length = unpack('n', substr($this->buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($this->buffer) < 10) {
                return null;
            }
            $length = unpack('J', substr($this->buffer, 2, 8))[1];
            $offset = 10;
        }
        $masked = ($second & 0x80) !== 0;
        $mask = '';
        if ($masked) {
            if (strlen($this->buffer) < $offset + 4) {
                return null;
            }
            $mask = substr($this->buffer, $offset, 4);
            $offset += 4;
        }
        if (strlen($this->buffer) < $offset + $length) {
            return null;
        }
        $payload = substr($this->buffer, $offset, $length);
        $this->buffer = (string) substr($this->buffer, $offset + $length);
        if ($masked) {
            $payload = $payload ^ str_repeat($mask, intdiv($length, 4) + 1);
            $payload = substr($payload, 0, $length);
        }
        return [($first & 0x80) !== 0, $first & 0x0F, $payload];
    }

    /** Frames from a client are always masked. */
    private function write(int $opcode, string $payload): void
    {
        $length = strlen($payload);
        $head = chr(0x80 | $opcode);
        if ($length < 126) {
            $head .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $head .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $head .= chr(0x80 | 127) . pack('J', $length);
        }
        $mask = random_bytes(4);
        $masked = $length > 0 ? substr($payload ^ str_repeat($mask, intdiv($length, 4) + 1), 0, $length) : '';
        $frame = $head . $mask . $masked;
        stream_set_timeout($this->stream, 10);
        for ($sent = 0; $sent < strlen($frame);) {
            $wrote = fwrite($this->stream, substr($frame, $sent));
            if ($wrote === false || $wrote === 0) {
                $this->closed = true;
                throw new \RuntimeException('The Slack connection broke.');
            }
            $sent += $wrote;
        }
    }
}
