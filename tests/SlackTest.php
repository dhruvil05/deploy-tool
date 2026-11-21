<?php

declare(strict_types=1);

use DeployTool\Ai\ChatStore;
use DeployTool\JsonStore;
use DeployTool\RunStore;
use DeployTool\Secrets;
use DeployTool\Settings;
use DeployTool\Slack\Api as SlackApi;
use DeployTool\Slack\Bridge as SlackBridge;
use DeployTool\Slack\Outbox as SlackOutbox;
use DeployTool\Slack\Socket;

const SLACK_CHANNEL = 'C0123ABCDE';
const SLACK_ME = 'U0123ABCDE';
const SLACK_OTHER = 'U0999ZZZZZ';
const SLACK_BOT = 'U0BOT00001';

/** A fake Slack Web API on a local test server; posts and reactions are kept in store.json. */
function fakeSlack(): string
{
    $dir = Tests::folder('slack');
    file_put_contents("$dir/store.json", json_encode(['next' => 1000, 'posts' => [], 'reactions' => [], 'ws' => '']));
    file_put_contents("$dir/router.php", <<<'PHP'
<?php
$file = __DIR__ . '/store.json';
$lock = fopen($file . '.lock', 'c');
flock($lock, LOCK_EX);
$store = json_decode((string) file_get_contents($file), true);
header('Content-Type: application/json');
$method = basename((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$reply = static function (array $data) use (&$store, $file): void {
    file_put_contents($file, json_encode($store));
    echo json_encode($data);
};
if ($method === 'apps.connections.open') {
    $reply($auth === 'Bearer xapp-good' ? ['ok' => true, 'url' => $store['ws']] : ['ok' => false, 'error' => 'invalid_auth']);
} elseif ($auth !== 'Bearer xoxb-good') {
    $reply(['ok' => false, 'error' => $auth === 'Bearer xapp-good' ? 'not_allowed_token_type' : 'invalid_auth']);
} elseif ($method === 'auth.test') {
    $reply(['ok' => true, 'user_id' => 'U0BOT00001', 'user' => 'deploybot', 'team' => 'Test team']);
} elseif ($method === 'conversations.info') {
    $reply($body['channel'] === 'C0123ABCDE' ? ['ok' => true, 'channel' => ['id' => 'C0123ABCDE', 'name' => 'deploys', 'is_member' => true]] : ['ok' => false, 'error' => 'channel_not_found']);
} elseif ($method === 'chat.postMessage') {
    $ts = '1700000000.' . str_pad((string) $store['next']++, 6, '0', STR_PAD_LEFT);
    $store['posts'][] = ['ts' => $ts, 'channel' => $body['channel'], 'text' => $body['text'], 'thread_ts' => $body['thread_ts'] ?? null];
    $reply(['ok' => true, 'ts' => $ts]);
} elseif ($method === 'reactions.add') {
    $store['reactions'][$body['timestamp']][] = $body['name'];
    $reply(['ok' => true]);
} else {
    $reply(['ok' => false, 'error' => 'unknown_method']);
}
PHP);
    $GLOBALS['slackDir'] = $dir;
    return Tests::serve($dir, "$dir/router.php") . '/api';
}

function slackStore(): array
{
    return json_decode((string) file_get_contents($GLOBALS['slackDir'] . '/store.json'), true);
}

/** What the bot posted (in a thread, or in the channel with $thread null) since the last look. */
function slackSaid(?string $thread): array
{
    static $seen = [];
    $posts = array_values(array_filter(slackStore()['posts'], static fn ($p) => $p['thread_ts'] === $thread));
    $key = $GLOBALS['slackDir'] . '|' . $thread;
    $new = array_slice($posts, $seen[$key] ?? 0);
    $seen[$key] = count($posts);
    return array_column($new, 'text');
}

/** A Slack event, as Socket Mode delivers it (after the envelope). */
function slackEvent(string $text, ?string $thread = null, string $user = SLACK_ME, array $extra = []): array
{
    static $n = 0;
    $n++;
    return $extra + ['type' => 'message', 'channel' => SLACK_CHANNEL, 'user' => $user, 'text' => $text, 'ts' => "1800000000.$n", 'thread_ts' => $thread, 'event_id' => "Ev$n"];
}

function slackSetup(array $ai = [], string $actions = 'ask'): void
{
    SlackApi::$base = fakeSlack();
    $repo = Tests::repo([['first', ['index.php' => '1']], ['second', ['index.php' => '2']]]);
    $git = new DeployTool\Git($repo, Settings::gitBinary());
    $git->okRaw(['update-ref', 'refs/remotes/origin/main', 'HEAD']);
    $site = Tests::folder('site');
    @mkdir("$site/application/.deploy", 0777, true);
    file_put_contents("$site/application/.deploy/state.json", json_encode(['commit' => $git->resolve('HEAD~1')]));
    JsonStore::write('sources', ['shop' => ['key' => 'shop', 'name' => 'Shop', 'repo_path' => $repo, 'branch' => 'main', 'remote' => 'origin', 'extra_protected' => '']]);
    JsonStore::write('sites', ['stg' => ['key' => 'stg', 'name' => 'Staging', 'type' => 'folder', 'source' => 'local:shop', 'production' => false, 'path' => $site, 'url' => '', 'check_path' => '']]);
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'background_runs' => '0', 'feature_slack_bridge' => '1', 'slack_bot_token' => Secrets::seal('xoxb-good'),
        'slack_app_token' => Secrets::seal('xapp-good'), 'slack_channel_id' => SLACK_CHANNEL, 'slack_user_ids' => SLACK_ME, 'slack_actions' => $actions]);
    JsonStore::write('ai', $ai + ['enabled' => '0']);
    @unlink(STORAGE_DIR . '/slack.json');
}

/** !new in the channel; returns the chat's thread (the ts of the bot's "🧵 …" message). */
function slackThread(string $local): string
{
    SlackBridge::handle(slackEvent("!new $local"));
    $thread = (string) array_key_last(JsonStore::read('slack')['threads']);
    slackSaid($thread);
    slackSaid(null);
    return $thread;
}

test('slack: !new opens a thread under the bot\'s message; only the listed member, in the channel, counts', function () {
    slackSetup();
    SlackBridge::handle(slackEvent('!new', null, SLACK_OTHER));
    SlackBridge::handle(slackEvent('!new', null, SLACK_ME, ['bot_id' => 'B1']));
    SlackBridge::handle(slackEvent('!new', null, SLACK_ME, ['subtype' => 'message_changed']));
    SlackBridge::handle(slackEvent('!new', null, SLACK_ME, ['channel' => 'C0OTHER000']));
    check(slackStore()['posts'] === [], 'other people, bots, edits and other channels are ignored');

    $event = slackEvent('!new');
    SlackBridge::handle($event);
    $state = JsonStore::read('slack');
    $thread = (string) array_key_last($state['threads']);
    check(slackSaid(null) === ['🧵 *Shop · chat*'] && ChatStore::load($state['threads'][$thread])['local'] === 'shop', 'a chat and its thread message');
    check(str_contains(implode("\n", slackSaid($thread)), 'Chat about *Shop* started'), 'the intro is in the thread, in Slack\'s bold');
    SlackBridge::handle($event);
    check(count(slackStore()['posts']) === 2, 'the same event sent again is handled once');

    SlackBridge::handle(slackEvent('!status', $thread));
    $said = implode("\n", slackSaid($thread));
    check(str_contains($said, '*Shop* · `main`') && str_contains($said, 'Staging'), '!status answers in the thread: ' . $said);
    SlackBridge::handle(slackEvent('!status', '1700000000.999999'));
    check(slackSaid('1700000000.999999') === [], 'a thread the bot did not open is ignored');
});

test('slack: "Ask" asks before an action; a ✅ reaction runs it, ❌ runs nothing; "Only read" refuses', function () {
    $url = fakeCallingAi('prepare_deploy', ['site' => 'stg']);
    slackSetup(['enabled' => '1', 'provider' => 'custom', 'base_url' => "$url/v1", 'model' => 'fake', 'perm_prepare' => '1']);
    $thread = slackThread('shop');

    SlackBridge::handle(slackEvent('prepare a deploy to staging', $thread));
    $question = (string) JsonStore::read('slack')['pending'][$thread];
    check(str_contains(implode("\n", slackSaid($thread)), '*Confirm:* Prepare a deploy to Staging?') && RunStore::all() === [], 'asks first, nothing started');
    check((slackStore()['reactions'][$question] ?? []) === ['white_check_mark', 'x'], 'the bot put ✅ and ❌ under it');

    SlackBridge::handle(['type' => 'reaction_added', 'user' => SLACK_OTHER, 'reaction' => 'white_check_mark', 'item' => ['type' => 'message', 'channel' => SLACK_CHANNEL, 'ts' => $question], 'event_id' => 'EvR0']);
    check(RunStore::all() === [], 'someone else\'s ✅ does nothing');
    SlackBridge::handle(['type' => 'reaction_added', 'user' => SLACK_ME, 'reaction' => 'x', 'item' => ['type' => 'message', 'channel' => SLACK_CHANNEL, 'ts' => $question], 'event_id' => 'EvR1']);
    check(RunStore::all() === [], '❌: nothing started');

    SlackBridge::handle(slackEvent('prepare a deploy to staging', $thread));
    $question = (string) JsonStore::read('slack')['pending'][$thread];
    SlackBridge::handle(['type' => 'reaction_added', 'user' => SLACK_ME, 'reaction' => 'white_check_mark', 'item' => ['type' => 'message', 'channel' => SLACK_CHANNEL, 'ts' => $question], 'event_id' => 'EvR2']);
    check(count(RunStore::all()) === 1 && str_contains(implode("\n", slackSaid($thread)), '✔ Prepared a deploy to Staging'), '✅: prepared, and it says so');

    $settings = JsonStore::read('settings');
    JsonStore::write('settings', ['slack_actions' => 'read'] + $settings);
    SlackBridge::handle(slackEvent('!compare staging', $thread));
    check(str_contains(implode("\n", slackSaid($thread)), 'only reads') && count(RunStore::all()) === 1, '"Only read": !compare refused');
});

test('slack: Markdown becomes Slack formatting; settings are checked and the tokens sealed', function () {
    check(SlackOutbox::mrkdwn("## Status\n**Staging** is *up to date* · [docs](https://example.com/a?b=1&c=2) · [run](index.php?r=run&id=R1) · 1 < 2")
        === "*Status*\n*Staging* is _up to date_ · <https://example.com/a?b=1&amp;c=2|docs> · run (run `R1`) · 1 &lt; 2", 'bold, italic, headings, links and escaping');
    $base = ['deployer_name' => 'Tester', 'review_minutes' => '60', 'compare_method' => 'fingerprint', 'ftp_connections' => '4', 'background_runs' => '1', 'protected_paths' => '', 'bundle_min_files' => '50'];
    check(isset(Settings::save($base + ['feature_slack_bridge' => '1'], false)['feature_slack_bridge']), 'on without the app: refused');
    $errors = Settings::save($base + ['slack_bot_token' => 'xapp-wrong', 'slack_app_token' => 'xoxb-wrong', 'slack_channel_id' => '#deploys', 'slack_user_ids' => '@me'], false);
    check(isset($errors['slack_bot_token'], $errors['slack_app_token'], $errors['slack_channel_id'], $errors['slack_user_ids']), 'swapped tokens, a channel name and a user name are refused');
    check(Settings::save($base + ['feature_slack_bridge' => '1', 'slack_bot_token' => 'xoxb-1', 'slack_app_token' => 'xapp-1', 'slack_channel_id' => 'c0123abcde', 'slack_user_ids' => 'u0123abcde']) === [], 'complete: saved');
    $stored = JsonStore::read('settings');
    check(Secrets::isSealed($stored['slack_bot_token']) && Secrets::isSealed($stored['slack_app_token']) && $stored['slack_channel_id'] === 'C0123ABCDE' && Settings::get('slack_app_token') === 'xapp-1', 'tokens encrypted; ids in capitals');
});

/** A one-connection WebSocket server for the tests: sends hello, a ping and queued events; logs what the client sends. */
function fakeSlackSocket(): array
{
    $dir = Tests::folder('ws');
    file_put_contents("$dir/server.php", <<<'PHP'
<?php
$dir = __DIR__;
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$name = stream_socket_get_name($server, false);
file_put_contents("$dir/port", substr($name, strrpos($name, ':') + 1));
$client = @stream_socket_accept($server, 60);
if (!$client) { exit; }
$request = '';
while (!str_contains($request, "\r\n\r\n")) { $request .= fread($client, 4096); }
preg_match('/Sec-WebSocket-Key: (.+)\r\n/i', $request, $m);
$accept = base64_encode(sha1(trim($m[1]) . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n");
$frame = static function (int $opcode, string $text): string {
    $length = strlen($text);
    return chr(0x80 | $opcode) . ($length < 126 ? chr($length) : ($length < 65536 ? chr(126) . pack('n', $length) : chr(127) . pack('J', $length))) . $text;
};
fwrite($client, $frame(1, json_encode(['type' => 'hello'])));
fwrite($client, $frame(9, 'are-you-there'));
stream_set_blocking($client, false);
$sent = 0;
$buffer = '';
while (!is_file("$dir/stop")) {
    $queue = is_file("$dir/queue.json") ? (json_decode((string) file_get_contents("$dir/queue.json"), true) ?: []) : [];
    for (; $sent < count($queue); $sent++) { fwrite($client, $frame(1, json_encode($queue[$sent]))); }
    $chunk = fread($client, 65536);
    if ($chunk !== false && $chunk !== '') { $buffer .= $chunk; } elseif (feof($client)) { break; }
    while (strlen($buffer) >= 6) {
        $opcode = ord($buffer[0]) & 0x0F;
        $length = ord($buffer[1]) & 0x7F;
        $offset = 2;
        if ($length === 126) { $length = unpack('n', substr($buffer, 2, 2))[1]; $offset = 4; }
        if (strlen($buffer) < $offset + 4 + $length) { break; }
        $mask = substr($buffer, $offset, 4);
        $payload = substr(substr($buffer, $offset + 4, $length) ^ str_repeat($mask, intdiv($length, 4) + 1), 0, $length);
        $buffer = (string) substr($buffer, $offset + 4 + $length);
        file_put_contents("$dir/received.log", "$opcode $payload\n", FILE_APPEND);
        if ($opcode === 8) { exit; }
    }
    usleep(50000);
}
PHP);
    $process = proc_open([PHP_BINARY, "$dir/server.php"], [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
    for ($i = 0; $i < 50 && !is_file("$dir/port"); $i++) {
        usleep(100_000);
    }
    return [$dir, $process, 'ws://127.0.0.1:' . trim((string) file_get_contents("$dir/port")) . '/link/?ticket=t1'];
}

test('slack: the WebSocket client opens, reads, answers pings and sends', function () {
    [$dir, $process, $url] = fakeSlackSocket();
    $socket = Socket::open($url, 5);
    check(json_decode((string) $socket->receive(5), true) === ['type' => 'hello'], 'the hello arrives');
    $socket->send('{"envelope_id":"e1"}');
    file_put_contents("$dir/queue.json", json_encode([['type' => 'events_api', 'envelope_id' => 'e2', 'payload' => ['event' => ['type' => 'message', 'text' => str_repeat('x', 300)]]]]));
    $long = json_decode((string) $socket->receive(5), true);
    check(strlen($long['payload']['event']['text']) === 300, 'a longer message (two-byte length) arrives whole');
    $socket->close();
    for ($i = 0; $i < 30 && proc_get_status($process)['running']; $i++) {
        usleep(100_000);
    }
    $log = (string) @file_get_contents("$dir/received.log");
    check(str_contains($log, "10 are-you-there") && str_contains($log, '1 {"envelope_id":"e1"}') && str_contains($log, "8 "), 'pong, the ack and a goodbye were sent: ' . $log);
    proc_close($process);
    check(Socket::class !== '' && (static function (): bool {
        try {
            Socket::open('ws://example.com/x', 1);
            return false;
        } catch (RuntimeException) {
            return true;
        }
    })(), 'plain ws:// to anything but this computer is refused');
});

test('slack: the background process connects, acknowledges and answers events, and stops when switched off', function () {
    slackSetup();
    [$dir, $server, $url] = fakeSlackSocket();
    $store = slackStore();
    $store['ws'] = $url;
    file_put_contents($GLOBALS['slackDir'] . '/store.json', json_encode($store));
    $env = array_merge(getenv(), ['DEPLOY_TOOL_SLACK_API' => SlackApi::$base]);
    $worker = proc_open([PHP_BINARY, dirname(__DIR__) . '/src/slack-worker.php'], [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, null, $env);
    $waitFor = static function (callable $done, int $seconds): bool {
        for ($i = 0; $i < $seconds * 5; $i++) {
            if ($done()) {
                return true;
            }
            usleep(200_000);
        }
        return false;
    };
    check($waitFor(static fn (): bool => SlackBridge::status()['connected'], 15), 'connected: ' . json_encode(SlackBridge::status()));
    file_put_contents("$dir/queue.json", json_encode([['type' => 'events_api', 'envelope_id' => 'env-1', 'payload' => ['event_id' => 'EvP1', 'event' => slackEvent('!new')]]]));
    check($waitFor(static fn (): bool => in_array('🧵 *Shop · chat*', array_column(slackStore()['posts'], 'text'), true), 15), 'it answered !new');
    check(str_contains((string) @file_get_contents("$dir/received.log"), '{"envelope_id":"env-1"}'), 'and acknowledged the event');

    $settings = JsonStore::read('settings');
    JsonStore::write('settings', ['feature_slack_bridge' => '0'] + $settings);
    check($waitFor(static fn (): bool => !proc_get_status($worker)['running'], 15), 'switched off: the process ended');
    proc_close($worker);
    touch("$dir/stop");
    proc_close($server);
    check(!SlackBridge::isRunning() && !SlackBridge::status()['connected'], 'shown as stopped');
});
