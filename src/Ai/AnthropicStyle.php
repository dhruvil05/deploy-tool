<?php

declare(strict_types=1);

namespace DeployTool\Ai;

/**
 * Anthropic's Messages API (POST /messages). Tool results go back as tool_result blocks
 * inside a user turn, and turns must alternate, so neighbouring turns of the same role
 * are joined. No network here (Provider sends it).
 */
final class AnthropicStyle
{
    public const PATH = '/messages';

    /**
     * @param list<array> $messages in Provider::chat()'s format
     * @param list<array{name: string, description: string, parameters: array}> $tools
     */
    public static function body(string $model, string $system, array $messages, array $tools, int $maxTokens): array
    {
        $turns = [];
        foreach ($messages as $message) {
            [$role, $blocks] = match ($message['role']) {
                'assistant' => ['assistant', self::assistantBlocks($message)],
                'tool' => ['user', [['type' => 'tool_result', 'tool_use_id' => $message['call_id'], 'content' => $message['content']]]],
                default => ['user', [['type' => 'text', 'text' => $message['content']]]],
            };
            if ($blocks === []) {
                continue;
            }
            $last = array_key_last($turns);
            if ($last !== null && $turns[$last]['role'] === $role) {
                array_push($turns[$last]['content'], ...$blocks);
            } else {
                $turns[] = ['role' => $role, 'content' => $blocks];
            }
        }
        $body = ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system, 'messages' => $turns];
        if ($tools !== []) {
            $body['tools'] = array_map(static fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => Provider::schema($tool['parameters']),
            ], $tools);
        }
        return $body;
    }

    private static function assistantBlocks(array $message): array
    {
        $blocks = ($message['content'] ?? '') !== '' ? [['type' => 'text', 'text' => $message['content']]] : [];
        foreach ($message['calls'] ?? [] as $call) {
            $blocks[] = ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['name'], 'input' => $call['arguments'] ?: new \stdClass()];
        }
        return $blocks;
    }

    /** @return array{text: string, calls: list<array{id: string, name: string, arguments: array}>, stop: string, model: ?string, usage: array{in: int, out: int, cost: ?float}} */
    /** A streamed answer being put together (Provider::stream). */
    public static function streamStart(): array
    {
        return ['text' => '', 'blocks' => [], 'stop' => null, 'model' => null, 'in' => 0, 'out' => 0];
    }

    /**
     * One streamed event into $state. Returns whether new text arrived. Blocks start with
     * their type (text, or tool_use with id and name), then grow by deltas; a tool's input
     * arrives as pieces of JSON.
     */
    public static function streamEvent(array &$state, array $event): bool
    {
        switch ($event['type'] ?? '') {
            case 'error':
                throw new \RuntimeException('The provider answered with an error: ' . ($event['error']['message'] ?? 'unknown'));
            case 'message_start':
                $state['model'] = $event['message']['model'] ?? $state['model'];
                $state['in'] = (int) ($event['message']['usage']['input_tokens'] ?? 0);
                return false;
            case 'content_block_start':
                $block = (array) ($event['content_block'] ?? []);
                $state['blocks'][(int) ($event['index'] ?? count($state['blocks']))] = ($block['type'] ?? '') === 'tool_use'
                    ? ['type' => 'tool_use', 'id' => (string) ($block['id'] ?? ''), 'name' => (string) ($block['name'] ?? ''), 'json' => '']
                    : ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
                return false;
            case 'content_block_delta':
                $i = (int) ($event['index'] ?? 0);
                $delta = (array) ($event['delta'] ?? []);
                if (($delta['type'] ?? '') === 'input_json_delta') {
                    $state['blocks'][$i]['json'] = ($state['blocks'][$i]['json'] ?? '') . (string) ($delta['partial_json'] ?? '');
                    return false;
                }
                if (($delta['type'] ?? '') === 'text_delta' && ($delta['text'] ?? '') !== '') {
                    $state['blocks'][$i]['text'] = ($state['blocks'][$i]['text'] ?? '') . $delta['text'];
                    $state['text'] = implode("\n\n", array_column(array_filter($state['blocks'], static fn (array $b): bool => $b['type'] === 'text'), 'text'));
                    return true;
                }
                return false;
            case 'message_delta':
                $state['stop'] = $event['delta']['stop_reason'] ?? $state['stop'];
                $state['out'] = (int) ($event['usage']['output_tokens'] ?? $state['out']);
                return false;
        }
        return false;
    }

    /** The streamed answer in the normal (non-streamed) shape, for reply(). */
    public static function streamAnswer(array $state): array
    {
        ksort($state['blocks']);
        return [
            'model' => $state['model'],
            'stop_reason' => $state['stop'],
            'usage' => ['input_tokens' => $state['in'], 'output_tokens' => $state['out']],
            'content' => array_values(array_map(static fn (array $b): array => $b['type'] === 'tool_use'
                ? ['type' => 'tool_use', 'id' => $b['id'], 'name' => $b['name'], 'input' => json_decode($b['json'] !== '' ? $b['json'] : '{}', true) ?: []]
                : ['type' => 'text', 'text' => $b['text']], $state['blocks'])),
        ];
    }

    public static function reply(array $answer): array
    {
        $text = [];
        $calls = [];
        foreach ((array) ($answer['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text[] = (string) $block['text'];
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $calls[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'arguments' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ];
            }
        }
        $stop = (string) ($answer['stop_reason'] ?? '');
        return [
            'text' => trim(implode("\n\n", $text)),
            'calls' => $calls,
            'stop' => $calls !== [] ? 'tool' : ($stop === 'max_tokens' ? 'length' : 'end'),
            'model' => isset($answer['model']) && is_string($answer['model']) ? $answer['model'] : null,
            'usage' => ['in' => (int) ($answer['usage']['input_tokens'] ?? 0), 'out' => (int) ($answer['usage']['output_tokens'] ?? 0), 'cost' => null],
        ];
    }
}
