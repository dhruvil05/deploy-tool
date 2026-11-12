<?php

declare(strict_types=1);

namespace DeployTool\Ai;

use DeployTool\Ai;
use DeployTool\Secrets;
use DeployTool\Settings;

/**
 * Talks to the AI provider set in Settings: any OpenAI-style API (OpenRouter, OpenAI,
 * Groq, Ollama, LM Studio, ...) or Anthropic's own. The key is sent only in headers and
 * never appears in errors.
 */
final class Provider
{
    private const TIMEOUT = 60;

    public function __construct(private readonly array $settings)
    {
    }

    /** @return list<string> model ids the provider offers, sorted */
    public function models(): array
    {
        $answer = $this->request('GET', '/models');
        $ids = array_values(array_filter(array_map(
            static fn ($model): string => is_array($model) ? (string) ($model['id'] ?? '') : '',
            (array) ($answer['data'] ?? $answer['models'] ?? [])
        ), 'strlen'));
        sort($ids, SORT_NATURAL | SORT_FLAG_CASE);
        return $ids;
    }

    /**
     * One turn of a chat: sends the conversation and the tools the model may call, and
     * returns what it said and which tools it wants run. The same message format works
     * for every provider:
     *   ['role' => 'user', 'content' => '…']
     *   ['role' => 'assistant', 'content' => '…', 'calls' => [['id', 'name', 'arguments' => [...]], …]]
     *   ['role' => 'tool', 'call_id' => '…', 'name' => '…', 'content' => 'the tool's result as text']
     *
     * @param list<array{name: string, description: string, parameters: array}> $tools parameters is a JSON schema
     * @return array{text: string, calls: list<array{id: string, name: string, arguments: array}>, stop: string, model: ?string, usage: array{in: int, out: int, cost: ?float}}
     *         stop is tool (run calls, then ask again), end, or length (cut off by max tokens)
     */
    public function chat(string $system, array $messages, array $tools = [], int $maxTokens = 2048, ?callable $onText = null): array
    {
        if ($this->settings['model'] === '') {
            throw new \RuntimeException('Pick a model first.');
        }
        if ((Ai::PRESETS[$this->settings['provider']][3] ?? false) && $this->settings['api_key'] === '') {
            throw new \RuntimeException('Enter the API key first.');
        }
        $api = Ai::apiStyle($this->settings) === 'anthropic' ? AnthropicStyle::class : OpenAiStyle::class;
        $body = $api::body($this->settings['model'], $system, $messages, $tools, $maxTokens);
        if ($this->settings['provider'] === 'openrouter') {
            $body['usage'] = ['include' => true]; // OpenRouter then reports what the request cost
        }
        if ($onText !== null && Settings::on('feature_streaming')) {
            $this->streamed = false;
            try {
                return $api::reply($this->stream($api, $body, $onText));
            } catch (\RuntimeException $e) {
                // Before any text arrived, a provider that can't stream (or refused the extra
                // stream options) gets the same request the normal way. Afterwards, it's an error.
                if ($this->streamed || preg_match('/refused the API key|no credit left|Not found at this base URL/', $e->getMessage())) {
                    throw $e;
                }
            }
        }
        return $api::reply($this->request('POST', $api::PATH, $body));
    }

    /**
     * One small chat that offers the model a tool, to see whether it answers and whether
     * it can call tools (the assistant needs that).
     *
     * @return array{seconds: float, tools: bool, reply: string}
     */
    public function test(): array
    {
        $started = microtime(true);
        $reply = $this->chat(
            'You are checking a connection. Do what the user asks.',
            [['role' => 'user', 'content' => 'This is a connection test. Call the ping tool once.']],
            [['name' => 'ping', 'description' => 'Answers pong.', 'parameters' => []]],
            100,
        );
        return ['seconds' => round(microtime(true) - $started, 1), 'tools' => $reply['calls'] !== [], 'reply' => self::cut($reply['text'])];
    }

    /** A tool's parameters as a JSON schema object; empty properties stay an object ({}), not a list. */
    public static function schema(array $parameters): array
    {
        $parameters += ['type' => 'object'];
        $parameters['properties'] = ($parameters['properties'] ?? []) ?: new \stdClass();
        return $parameters;
    }

    /** One JSON request; throws with the provider's own error message when it fails. */
    /** The request headers: JSON, the key the way this API style wants it. */
    private function headers(string $accept = 'application/json'): array
    {
        $headers = ['Accept: ' . $accept, 'Content-Type: application/json'];
        $key = $this->settings['api_key'];
        if (Secrets::isSealed($key)) {
            throw new \RuntimeException("The saved API key can't be unlocked on this computer (the key file is missing or different). Type it again in Settings → AI assistant.");
        }
        if (Ai::apiStyle($this->settings) === 'anthropic') {
            $headers[] = 'anthropic-version: 2023-06-01';
            if ($key !== '') {
                $headers[] = 'x-api-key: ' . $key;
            }
        } elseif ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        if ($this->settings['provider'] === 'openrouter') {
            $headers[] = 'X-Title: Deploy tool';
        }
        return $headers;
    }

    /**
     * Speed-up "Answers that appear as they're written": the same request, streamed. Calls
     * $onText with the text so far as it arrives, and returns the whole answer rebuilt in the
     * provider's normal (non-streamed) shape, so it's read exactly like a normal answer. A
     * server that ignores streaming and answers in one piece works too.
     *
     * @param class-string<OpenAiStyle|AnthropicStyle> $api
     */
    private function stream(string $api, array $body, callable $onText): array
    {
        $body['stream'] = true;
        if ($api === OpenAiStyle::class) {
            $body['stream_options'] = ['include_usage' => true];
        }
        $state = $api::streamStart();
        $buffer = '';
        $raw = '';
        $events = 0;
        $failure = null;
        $curl = curl_init(rtrim($this->settings['base_url'], '/') . $api::PATH);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $this->headers('text/event-stream'),
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::TIMEOUT * 3, // streamed answers keep the line busy; a stall still ends it
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => self::TIMEOUT, // no byte for this long: give up, as before
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use ($api, $onText, &$state, &$buffer, &$raw, &$events, &$failure): int {
                if (strlen($raw) < 65536) {
                    $raw .= $chunk; // for error messages, and servers that answer in one piece
                }
                $buffer .= $chunk;
                try {
                    while (($end = strpos($buffer, "\n")) !== false) {
                        $line = rtrim(substr($buffer, 0, $end), "\r");
                        $buffer = substr($buffer, $end + 1);
                        $data = str_starts_with($line, 'data:') ? trim(substr($line, 5)) : '';
                        $event = $data === '' || $data === '[DONE]' ? null : json_decode($data, true);
                        if (!is_array($event)) {
                            continue;
                        }
                        $events++;
                        if ($api::streamEvent($state, $event)) {
                            $this->streamed = true;
                            $onText($state['text']);
                        }
                    }
                } catch (\Throwable $e) {
                    $failure = $e;
                    return 0; // stops the transfer
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        if ($failure !== null) {
            throw $failure instanceof \RuntimeException ? $failure : new \RuntimeException($failure->getMessage(), 0, $failure);
        }
        if ($events === 0) {
            if ($ok === false) {
                throw new \RuntimeException("Couldn't reach {$this->settings['base_url']}: $curlError.");
            }
            $answer = json_decode($raw, true); // a server that ignored "stream" and answered in one piece
            if ($status >= 400 || !is_array($answer) || isset($answer['error'])) {
                throw self::failure($status, $raw, $answer);
            }
            return $answer;
        }
        if ($ok === false && !$this->streamed) {
            throw new \RuntimeException("The answer stopped early: $curlError.");
        }
        return $api::streamAnswer($state);
    }

    /** Whether text of the current answer already reached the page (then no quiet retry). */
    private bool $streamed = false;

    private function request(string $method, string $path, ?array $body = null): array
    {
        $headers = $this->headers();

        $curl = curl_init(rtrim($this->settings['base_url'], '/') . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            throw new \RuntimeException("Couldn't reach {$this->settings['base_url']}: $curlError. Check the base URL" . (str_starts_with($this->settings['base_url'], 'http://localhost') ? ' and that the program is running.' : ' and your internet connection.'));
        }
        $answer = json_decode((string) $raw, true);
        // OpenRouter can answer 200 with an error inside when the model's own host fails.
        if ($status >= 400 || !is_array($answer) || isset($answer['error'])) {
            throw self::failure($status, (string) $raw, $answer);
        }
        return $answer;
    }

    /** The provider's own error, in plain words. */
    private static function failure(int $status, string $raw, mixed $answer): \RuntimeException
    {
        $detail = is_array($answer) ? ($answer['error']['message'] ?? $answer['error'] ?? $answer['message'] ?? '') : '';
        $detail = is_string($detail) && $detail !== '' ? $detail : self::cut(trim(strip_tags($raw)));
        return new \RuntimeException(match (true) {
            $status === 401, $status === 403 => 'The provider refused the API key' . ($detail !== '' ? ": $detail" : '.'),
            $status === 404 => 'Not found at this base URL' . ($detail !== '' ? ": $detail" : '. Check the address and the model name.'),
            $status === 429 => 'The provider says too many requests or no credit left' . ($detail !== '' ? ": $detail" : '.'),
            default => 'The provider answered with an error' . ($status >= 400 ? " (HTTP $status)" : '') . ($detail !== '' ? ": $detail" : '.'),
        });
    }

    /** The first 200 characters, without splitting a UTF-8 character. */
    private static function cut(string $text): string
    {
        return (string) preg_replace('/^(.{0,200}).*$/us', '$1', $text);
    }
}
