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

    /** @return array{text: string, calls: list<array{id: string, name: string, arguments: array}>, stop: string, usage: array{in: int, out: int, cost: ?float}} */
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
            'usage' => ['in' => (int) ($answer['usage']['input_tokens'] ?? 0), 'out' => (int) ($answer['usage']['output_tokens'] ?? 0), 'cost' => null],
        ];
    }
}
