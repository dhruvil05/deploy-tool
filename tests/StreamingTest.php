<?php

declare(strict_types=1);

use DeployTool\Ai\Provider;
use DeployTool\JsonStore;

/**
 * A fake AI server. $mode: openai (streams text in pieces and a tool call in pieces), anthropic
 * (the same in Anthropic's events), plain (ignores "stream", answers in one piece), nostream
 * (refuses stream with 400, answers normally without), badkey (401). Each request body is
 * appended to requests.log.
 */
function fakeStreamingAi(string $mode): array
{
    $dir = Tests::folder('fakeai');
    file_put_contents("$dir/router.php", '<?php $mode = ' . var_export($mode, true) . ';' . <<<'PHP'

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
file_put_contents(__DIR__ . '/requests.log', json_encode($body) . "\n", FILE_APPEND);
$stream = !empty($body['stream']);
$sse = function (array $events): void {
    header('Content-Type: text/event-stream');
    foreach ($events as $event) {
        echo isset($event['type']) ? "event: {$event['type']}\n" : '', 'data: ', json_encode($event), "\n\n";
        flush();
    }
};
if ($mode === 'badkey') { http_response_code(401); echo json_encode(['error' => ['message' => 'bad key']]); return; }
if ($mode === 'nostream' && $stream) { http_response_code(400); echo json_encode(['error' => ['message' => 'stream_options not supported']]); return; }
if ($stream && $mode === 'openai') {
    $sse([
        ['model' => 'm1', 'choices' => [['delta' => ['content' => 'Hel']]]],
        ['choices' => [['delta' => ['content' => 'lo']]]],
        ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_9', 'function' => ['name' => 'get_run', 'arguments' => '{"run_']]]]]]],
        ['choices' => [['delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'id":"x"}']]]], 'finish_reason' => 'tool_calls']]],
        ['choices' => [], 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3]],
    ]);
    echo "data: [DONE]\n\n";
    return;
}
if ($stream && $mode === 'anthropic') {
    $sse([
        ['type' => 'message_start', 'message' => ['model' => 'claude-x', 'usage' => ['input_tokens' => 7]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hel']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'lo']],
        ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'get_run', 'input' => []]],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"run_']],
        ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'id":"x"}']],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 3]],
        ['type' => 'message_stop'],
    ]);
    return;
}
header('Content-Type: application/json');
echo json_encode(['model' => 'm1', 'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3], 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Hello']]]]);
PHP);
    return [Tests::serve($dir, "$dir/router.php"), $dir];
}

function streamingChat(string $url, string $provider = 'custom'): array
{
    $texts = [];
    $reply = (new Provider(['provider' => $provider, 'base_url' => $url . '/v1', 'api_key' => $provider === 'anthropic' ? 'k' : '', 'model' => 'm1']))
        ->chat('system', [['role' => 'user', 'content' => 'hi']], [], 100, function (string $text) use (&$texts): void { $texts[] = $text; });
    return [$reply, $texts];
}

test('streaming: OpenAI style, text arrives in pieces and the tool call is put back together', function () {
    [$url, $dir] = fakeStreamingAi('openai');
    [$reply, $texts] = streamingChat($url);
    check($texts === ['Hel', 'Hello'], 'text so far, piece by piece: ' . json_encode($texts));
    check($reply['text'] === 'Hello' && $reply['model'] === 'm1', 'the whole text and model');
    check($reply['calls'] === [['id' => 'call_9', 'name' => 'get_run', 'arguments' => ['run_id' => 'x']]], 'the tool call, its arguments joined: ' . json_encode($reply['calls']));
    check($reply['usage']['in'] === 7 && $reply['usage']['out'] === 3 && $reply['stop'] === 'tool', 'usage and stop reason');
    check(str_contains((string) file_get_contents("$dir/requests.log"), '"stream":true'), 'it asked to stream');
});

test('streaming: Anthropic style events give the same result', function () {
    [$url] = fakeStreamingAi('anthropic');
    [$reply, $texts] = streamingChat($url, 'anthropic');
    check($texts === ['Hel', 'Hello'] && $reply['text'] === 'Hello' && $reply['model'] === 'claude-x', 'text pieces and model');
    check($reply['calls'] === [['id' => 'toolu_9', 'name' => 'get_run', 'arguments' => ['run_id' => 'x']]], 'the tool call: ' . json_encode($reply['calls']));
    check($reply['usage']['in'] === 7 && $reply['usage']['out'] === 3 && $reply['stop'] === 'tool', 'usage and stop reason');
});

test('streaming: providers that ignore or refuse it still answer; a bad key is not retried', function () {
    [$url, $dir] = fakeStreamingAi('plain');
    [$reply] = streamingChat($url);
    check($reply['text'] === 'Hello' && substr_count((string) file_get_contents("$dir/requests.log"), "\n") === 1, 'ignored stream: one request, normal answer');

    [$url, $dir] = fakeStreamingAi('nostream');
    [$reply, $texts] = streamingChat($url);
    $log = (string) file_get_contents("$dir/requests.log");
    check($reply['text'] === 'Hello' && $texts === [], 'refused stream: the answer comes the normal way');
    check(substr_count($log, "\n") === 2 && !str_contains(explode("\n", $log)[1], '"stream"'), 'with one retry without streaming');

    [$url, $dir] = fakeStreamingAi('badkey');
    try {
        streamingChat($url);
        check(false, 'should throw');
    } catch (\RuntimeException $e) {
        check(str_contains($e->getMessage(), 'refused the API key'), 'the key error is shown');
    }
    check(substr_count((string) file_get_contents("$dir/requests.log"), "\n") === 1, 'and not retried');
});

test('streaming: off sends normal requests', function () {
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'feature_streaming' => '0']);
    [$url, $dir] = fakeStreamingAi('openai');
    [$reply, $texts] = streamingChat($url);
    check($texts === [] && $reply['text'] === 'Hello' && !str_contains((string) file_get_contents("$dir/requests.log"), '"stream"'), 'no streaming asked, normal answer');
});
