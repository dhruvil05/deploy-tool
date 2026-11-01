<?php

declare(strict_types=1);

namespace DeployTool\Ai;

/**
 * The OpenAI-style chat API (POST /chat/completions), spoken by OpenRouter, OpenAI, Groq,
 * Ollama, LM Studio and most others. Turns the assistant's own messages into a request
 * body and the answer back into a reply; no network here (Provider sends it).
 */
final class OpenAiStyle
{
    public const PATH = '/chat/completions';

    /**
     * @param list<array> $messages in Provider::chat()'s format
     * @param list<array{name: string, description: string, parameters: array}> $tools
     */
    public static function body(string $model, string $system, array $messages, array $tools, int $maxTokens): array
    {
        $out = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $message) {
            $out[] = match ($message['role']) {
                'assistant' => self::assistant($message),
                'tool' => ['role' => 'tool', 'tool_call_id' => $message['call_id'], 'content' => $message['content']],
                default => ['role' => 'user', 'content' => $message['content']],
            };
        }
        $body = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => $out];
        if ($tools !== []) {
            $body['tools'] = array_map(static fn (array $tool): array => [
                'type' => 'function',
                'function' => ['name' => $tool['name'], 'description' => $tool['description'], 'parameters' => Provider::schema($tool['parameters'])],
            ], $tools);
        }
        return $body;
    }

    private static function assistant(array $message): array
    {
        // Some servers reject an empty string next to tool calls; null is the documented "no text".
        $out = ['role' => 'assistant', 'content' => ($message['content'] ?? '') !== '' ? $message['content'] : null];
        if (!empty($message['calls'])) {
            $out['tool_calls'] = array_map(static fn (array $call): array => [
                'id' => $call['id'],
                'type' => 'function',
                'function' => ['name' => $call['name'], 'arguments' => json_encode($call['arguments'] ?: new \stdClass(), JSON_UNESCAPED_SLASHES)],
            ], $message['calls']);
        }
        return $out;
    }

    /** @return array{text: string, calls: list<array{id: string, name: string, arguments: array}>, stop: string, usage: array{in: int, out: int, cost: ?float}} */
    public static function reply(array $answer): array
    {
        $choice = (array) ($answer['choices'][0] ?? []);
        $message = (array) ($choice['message'] ?? []);
        $calls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $index => $call) {
            $function = (array) ($call['function'] ?? []);
            $arguments = $function['arguments'] ?? [];
            // Arguments come as a JSON string; a few servers send an object instead.
            $arguments = is_string($arguments) ? json_decode($arguments !== '' ? $arguments : '{}', true) : $arguments;
            $calls[] = [
                'id' => (string) ($call['id'] ?? 'call_' . $index),
                'name' => (string) ($function['name'] ?? ''),
                'arguments' => is_array($arguments) ? $arguments : [],
            ];
        }
        $finish = (string) ($choice['finish_reason'] ?? '');
        return [
            'text' => trim((string) ($message['content'] ?? '')),
            'calls' => $calls,
            'stop' => $calls !== [] ? 'tool' : ($finish === 'length' ? 'length' : 'end'),
            // OpenRouter adds the request's price in credits (US dollars) when asked; others don't.
            'usage' => [
                'in' => (int) ($answer['usage']['prompt_tokens'] ?? 0),
                'out' => (int) ($answer['usage']['completion_tokens'] ?? 0),
                'cost' => is_numeric($answer['usage']['cost'] ?? null) ? (float) $answer['usage']['cost'] : null,
            ],
        ];
    }
}
