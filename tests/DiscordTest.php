<?php

declare(strict_types=1);

use DeployTool\Ai;
use DeployTool\Discord\Api;
use DeployTool\Discord\Bridge;
use DeployTool\JsonStore;
use DeployTool\RunStore;
use DeployTool\Secrets;
use DeployTool\Settings;

const DISCORD_CHANNEL = '1100000000000000001';
const DISCORD_ME = '1200000000000000001';
const DISCORD_OTHER = '1200000000000000002';

/**
 * A fake Discord API on a local test server: messages per channel in store.json, which the
 * test adds to like a person typing. The bot's own posts are stored too, as in Discord.
 */
function fakeDiscord(): string
{
    $dir = Tests::folder('discord');
    file_put_contents("$dir/store.json", json_encode(['next' => 1300000000000000000, 'channels' => [DISCORD_CHANNEL => []], 'typing' => 0]));
    file_put_contents("$dir/router.php", <<<'PHP'
<?php
$file = __DIR__ . '/store.json';
$lock = fopen($file . '.lock', 'c');
flock($lock, LOCK_EX);
$store = json_decode((string) file_get_contents($file), true);
header('Content-Type: application/json');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
$reply = static function (int $status, array $data) use (&$store, $file): void {
    file_put_contents($file, json_encode($store));
    http_response_code($status);
    echo json_encode($data);
};
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bot good-token') {
    $reply(401, ['message' => '401: Unauthorized']);
} elseif (preg_match('#^/api/v10/channels/(\d+)/messages/(\d+)/reactions/([^/]+)(/@me)?$#', $path, $m)) {
    $emoji = rawurldecode($m[3]);
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $store['reactions'][$m[2]][$emoji][] = '1400000000000000001';
        $reply(204, []);
    } else {
        $reply(200, array_map(static fn ($id) => ['id' => $id], $store['reactions'][$m[2]][$emoji] ?? []));
    }
} elseif ($path === '/api/v10/users/@me') {
    $reply(200, ['id' => '1400000000000000001', 'username' => 'DeployBot']);
} elseif (preg_match('#^/api/v10/channels/(\d+)(/(\w+))?$#', $path, $m)) {
    [$channel, $what] = [$m[1], $m[3] ?? ''];
    if (!isset($store['channels'][$channel])) {
        $reply(404, ['message' => 'Unknown Channel']);
    } elseif ($what === '') {
        $reply(200, ['id' => $channel, 'name' => 'deploys', 'type' => 0]);
    } elseif ($what === 'typing') {
        $store['typing']++;
        $reply(204, []);
    } elseif ($what === 'threads') {
        $id = (string) $store['next']++;
        $store['channels'][$id] = [];
        $store['thread_names'][$id] = $body['name'];
        $reply(201, ['id' => $id, 'name' => $body['name'], 'type' => 11]);
    } elseif ($what === 'messages' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $message = ['id' => (string) $store['next']++, 'content' => $body['content'], 'type' => 0, 'author' => ['id' => '1400000000000000001', 'bot' => true], 'mentions' => $body['allowed_mentions'] ?? null];
        $store['channels'][$channel][] = $message;
        $reply(200, $message);
    } else {
        $all = $store['channels'][$channel];
        $after = $query['after'] ?? null;
        $list = array_values(array_filter($all, static fn ($m) => $after === null || strlen($m['id']) > strlen($after) || (strlen($m['id']) === strlen($after) && strcmp($m['id'], $after) > 0)));
        $list = array_reverse($list); // newest first, like Discord
        $reply(200, array_slice($list, 0, (int) ($query['limit'] ?? 50)));
    }
} else {
    $reply(404, ['message' => 'Not found']);
}
PHP);
    $GLOBALS['discordDir'] = $dir;
    return Tests::serve($dir, "$dir/router.php") . '/api/v10';
}

/** Reads and changes the fake Discord's store. */
function discordStore(?callable $change = null): array
{
    $file = $GLOBALS['discordDir'] . '/store.json';
    $lock = fopen($file . '.lock', 'c');
    flock($lock, LOCK_EX);
    $store = json_decode((string) file_get_contents($file), true);
    if ($change !== null) {
        $change($store);
        file_put_contents($file, json_encode($store));
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $store;
}

/** Someone types a message in a channel or thread. */
function discordSay(string $channel, string $text, string $user = DISCORD_ME): void
{
    discordStore(static function (array &$store) use ($channel, $text, $user): void {
        $store['channels'][$channel][] = ['id' => (string) $store['next']++, 'content' => $text, 'type' => 0, 'author' => ['id' => $user]];
    });
}

/** What the bot posted in a channel since the last look, as text. */
function botSaid(string $channel): array
{
    static $seen = [];
    $messages = array_values(array_filter(discordStore()['channels'][$channel] ?? [], static fn ($m) => !empty($m['author']['bot'])));
    $key = $GLOBALS['discordDir'] . '|' . $channel; // thread ids repeat between fakes
    $new = array_slice($messages, $seen[$key] ?? 0);
    $seen[$key] = count($messages);
    return array_column($new, 'content');
}

/** A local "shop" with a staging site, Discord set up and switched on. */
function discordSetup(array $ai = []): void
{
    Api::$base = fakeDiscord();
    $repo = Tests::repo([['first', ['index.php' => '1']], ['second', ['index.php' => '2']]]);
    $git = new DeployTool\Git($repo, Settings::gitBinary());
    $git->okRaw(['update-ref', 'refs/remotes/origin/main', 'HEAD']);
    $site = Tests::folder('site');
    @mkdir("$site/application/.deploy", 0777, true);
    file_put_contents("$site/application/.deploy/state.json", json_encode(['commit' => $git->resolve('HEAD~1')])); // staging is one commit behind
    JsonStore::write('sources', ['shop' => ['key' => 'shop', 'name' => 'Shop', 'repo_path' => $repo, 'branch' => 'main', 'remote' => 'origin', 'extra_protected' => '']]);
    JsonStore::write('sites', ['stg' => ['key' => 'stg', 'name' => 'Staging', 'type' => 'folder', 'source' => 'local:shop', 'production' => false, 'path' => $site, 'url' => '', 'check_path' => '']]);
    discordSettings();
    JsonStore::write('ai', $ai + ['enabled' => '0']);
    @unlink(STORAGE_DIR . '/discord.json');
}

/** Discord switched on for DISCORD_ME; $actions: what it may do from Discord (read, ask, same). */
function discordSettings(string $actions = 'ask'): void
{
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'background_runs' => '0', 'feature_discord_bridge' => '1', 'discord_bot_token' => Secrets::seal('good-token'),
        'discord_channel_id' => DISCORD_CHANNEL, 'discord_user_ids' => DISCORD_ME, 'discord_actions' => $actions]);
}

/** A fake AI that calls $tool with $arguments once, then answers with the action's result. */
function fakeCallingAi(string $tool, array $arguments): string
{
    $dir = Tests::folder('fakeai');
    file_put_contents("$dir/router.php", '<?php $call = ' . var_export(['name' => $tool, 'arguments' => json_encode($arguments)], true) . ';' . <<<'PHP'

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
header('Content-Type: application/json');
$last = end($body['messages']);
$base = ['model' => 'fake', 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]];
if (($last['role'] ?? '') === 'tool') {
    echo json_encode($base + ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Result: ' . $last['content']]]]]);
    return;
}
echo json_encode($base + ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['content' => '', 'tool_calls' => [
    ['id' => 'c1', 'type' => 'function', 'function' => $call],
]]]]]);
PHP);
    return Tests::serve($dir, "$dir/router.php");
}

/** A listed user reacts to a message. */
function discordReact(string $message, string $emoji): void
{
    discordStore(static function (array &$store) use ($message, $emoji): void {
        $store['reactions'][$message][$emoji][] = DISCORD_ME;
    });
}

/** Starts the bridge and opens a chat thread for $local; returns the thread. */
function discordThread(string $local): string
{
    Bridge::tick();
    discordSay(DISCORD_CHANNEL, "!new $local");
    Bridge::tick();
    $thread = lastThread();
    botSaid($thread);
    return $thread;
}

/** The thread the bot opened last. */
function lastThread(): string
{
    return (string) array_key_last(JsonStore::read('discord')['threads']);
}

test('discord: answers only the listed user, never old messages; !new opens a chat thread', function () {
    discordSetup();
    discordSay(DISCORD_CHANNEL, '!help');
    Bridge::tick();
    check(botSaid(DISCORD_CHANNEL) === [], 'messages from before it started are not answered');

    discordSay(DISCORD_CHANNEL, '!new', DISCORD_OTHER);
    Bridge::tick();
    check(botSaid(DISCORD_CHANNEL) === [], 'someone not on the list is ignored');

    discordSay(DISCORD_CHANNEL, '!new');
    Bridge::tick();
    $thread = lastThread();
    $chat = DeployTool\Ai\ChatStore::load(JsonStore::read('discord')['threads'][$thread]);
    check($chat !== null && $chat['local'] === 'shop', 'a chat about the only local was created');
    check(str_contains(implode("\n", botSaid($thread)), 'Chat about **Shop** started'), 'and its thread says so');
    check(discordStore()['channels'][$thread][0]['mentions'] === ['parse' => []], 'posts never ping anyone');

    discordSay(DISCORD_CHANNEL, 'hello');
    Bridge::tick();
    check(str_contains(implode("\n", botSaid(DISCORD_CHANNEL)), '!new <local>'), 'plain text in the channel points to threads');
    discordSay(DISCORD_CHANNEL, '!chats');
    Bridge::tick();
    check(str_contains(implode("\n", botSaid(DISCORD_CHANNEL)), '1. New chat · Shop'), '!chats lists the chat');
});

test('discord: "Only read": quick commands in a thread; actions are refused, nothing is started', function () {
    discordSetup();
    discordSettings('read');
    Bridge::tick();
    discordSay(DISCORD_CHANNEL, '!new shop');
    Bridge::tick();
    $thread = lastThread();
    botSaid($thread);

    discordSay($thread, '!status');
    Bridge::tick();
    $said = implode("\n", botSaid($thread));
    check(str_contains($said, '**Shop** · `main`') && str_contains($said, 'Staging') && !str_contains($said, 'index.php?r='), '!status answers, without links to the tool: ' . $said);

    discordSay($thread, '!deploy staging');
    discordSay($thread, '!compare staging');
    Bridge::tick();
    check(count(array_filter(botSaid($thread), static fn ($t) => str_contains($t, 'only reads'))) === 2 && RunStore::all() === [], 'deploy and compare refused; no run created');
    check(Ai::$remote === null, 'read-only mode ends with the round');
});

test('discord: "Only read": questions go to the assistant with no actions offered; long answers are split', function () {
    $dir = Tests::folder('fakeai');
    file_put_contents("$dir/router.php", <<<'PHP'
<?php
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
file_put_contents(__DIR__ . '/tools.json', json_encode(array_column(array_column($body['tools'] ?? [], 'function'), 'name')));
file_put_contents(__DIR__ . '/system.txt', $body['messages'][0]['content'] ?? '');
header('Content-Type: application/json');
$text = "Staging runs [run 1](index.php?r=run&id=1). " . str_repeat("Line of a long answer.\n", 120);
echo json_encode(['model' => 'fake', 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1], 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => $text]]]]);
PHP);
    $url = Tests::serve($dir, "$dir/router.php");
    discordSetup(['enabled' => '1', 'provider' => 'custom', 'base_url' => "$url/v1", 'model' => 'fake', 'perm_compare' => '1', 'perm_prepare' => '1', 'perm_approve' => '1', 'perm_pull' => '1']);
    discordSettings('read');
    Bridge::tick();
    discordSay(DISCORD_CHANNEL, '!new shop');
    Bridge::tick();
    $thread = lastThread();
    botSaid($thread);

    discordSay($thread, 'is staging up to date?');
    Bridge::tick();
    $parts = botSaid($thread);
    check(count($parts) === 2 && max(array_map('mb_strlen', $parts)) <= 2000, 'a 2,800-character answer arrives as two messages: ' . json_encode(array_map(static fn ($p) => mb_substr($p, 0, 120) . ' [' . mb_strlen($p) . ']', $parts)));
    check(str_starts_with($parts[0], 'Staging runs run 1.'), 'the tool link keeps only its text');
    $tools = json_decode((string) file_get_contents("$dir/tools.json"), true);
    check(in_array('site_status', $tools, true) && array_intersect($tools, ['start_compare', 'prepare_deploy', 'approve_run', 'pull_local', 'fetch_local']) === [], 'only reading actions are offered, even with them allowed in the tool');
    check(str_contains((string) file_get_contents("$dir/system.txt"), 'writing from Discord'), 'the assistant knows the answer goes to Discord');
    check(discordStore()['typing'] >= 1, '"typing…" was shown');
    $chat = DeployTool\Ai\ChatStore::load(JsonStore::read('discord')['threads'][$thread]);
    check(end($chat['messages'])['role'] === 'assistant', 'the same chat is in the tool');
});

test('discord: "Ask" asks before each action; ✅ runs it, ❌ or "no" runs nothing, "yes" runs it', function () {
    $url = fakeCallingAi('prepare_deploy', ['site' => 'stg']);
    discordSetup(['enabled' => '1', 'provider' => 'custom', 'base_url' => "$url/v1", 'model' => 'fake', 'perm_prepare' => '1']); // Allow in the tool
    $thread = discordThread('shop');

    discordSay($thread, 'prepare a deploy to staging');
    Bridge::tick();
    $said = implode("\n", botSaid($thread));
    $question = (string) JsonStore::read('discord')['pending'][$thread];
    check(str_contains($said, '**Confirm:** Prepare a deploy to Staging?') && RunStore::all() === [], 'asks first (even though the tool allows it), nothing started: ' . $said);
    check(count(discordStore()['reactions'][$question] ?? []) === 2, 'the bot put ✅ and ❌ under the question');

    discordReact($question, '❌');
    Bridge::tick();
    check(RunStore::all() === [] && str_contains(implode("\n", botSaid($thread)), 'Not run'), '❌: nothing started');

    discordSay($thread, 'prepare a deploy to staging');
    Bridge::tick();
    botSaid($thread);
    discordSay($thread, 'no');
    Bridge::tick();
    check(RunStore::all() === [], '"no": nothing started');

    discordSay($thread, 'prepare a deploy to staging');
    Bridge::tick();
    discordReact((string) JsonStore::read('discord')['pending'][$thread], '✅');
    Bridge::tick();
    $said = implode("\n", botSaid($thread));
    check(count(RunStore::all()) === 1 && str_contains($said, '✔ Prepared a deploy to Staging') && empty(JsonStore::read('discord')['pending'][$thread]), '✅: prepared, and it says so: ' . $said);

    $first = RunStore::all()[0];
    $first['status'] = 'cancelled'; // free the site for the next one
    RunStore::save($first);
    discordSay($thread, 'prepare a deploy to staging');
    Bridge::tick();
    discordSay($thread, 'yes');
    Bridge::tick();
    check(count(RunStore::all()) === 2, '"yes": prepared');
});

test('discord: !deploy runs as a direct command; approving production is refused from Discord', function () {
    discordSetup();
    $thread = discordThread('shop');
    discordSay($thread, '!deploy staging');
    Bridge::tick();
    check(count(RunStore::all()) === 1 && str_contains(implode("\n", botSaid($thread)), '(run `'), '!deploy prepares at once and names the run');

    $run = productionReview(); // a production deploy waiting for review, local "repo"
    $url = fakeCallingAi('approve_run', ['run_id' => $run['id']]);
    JsonStore::write('ai', ['enabled' => '1', 'provider' => 'custom', 'base_url' => "$url/v1", 'model' => 'fake', 'perm_approve' => '1']);
    discordSettings('same');
    $thread = discordThread('repo');
    discordSay($thread, 'approve it');
    Bridge::tick();
    $said = implode("\n", botSaid($thread));
    check(RunStore::load($run['id'])['status'] === 'review' && str_contains($said, 'never done from Discord') && !str_contains($said, 'Confirm'), 'production stays waiting; the assistant is told to send the user to the tool: ' . $said);
});

test('discord: the background process answers, shows as running, and stops when switched off', function () {
    discordSetup();
    $env = array_merge(getenv(), ['DEPLOY_TOOL_DISCORD_API' => Api::$base]);
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/src/discord-worker.php'], [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, null, $env);
    $waitFor = static function (callable $done, int $seconds): bool {
        for ($i = 0; $i < $seconds * 5; $i++) {
            if ($done()) {
                return true;
            }
            usleep(200_000);
        }
        return false;
    };
    check($waitFor(static fn (): bool => isset(JsonStore::read('discord')['checked_at']), 15), 'it started reading Discord');
    check(Bridge::isRunning() && Bridge::status()['bot'] === 'DeployBot', 'shown as running, as the bot');
    discordSay(DISCORD_CHANNEL, '!new');
    check($waitFor(static fn (): bool => !empty(JsonStore::read('discord')['threads']), 15), 'it answered !new by itself');

    $settings = JsonStore::read('settings');
    JsonStore::write('settings', ['feature_discord_bridge' => '0'] + $settings);
    check($waitFor(static fn (): bool => !proc_get_status($process)['running'], 15), 'switched off: the process ended');
    proc_close($process);
    check(!Bridge::isRunning(), 'and shows as stopped');
});

test('discord: the background process restarts itself with the new code when the tool is updated', function () {
    discordSetup();
    $env = array_merge(getenv(), ['DEPLOY_TOOL_DISCORD_API' => Api::$base]);
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/src/discord-worker.php'], [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes, null, $env);
    $waitFor = static function (callable $done, int $seconds): bool {
        for ($i = 0; $i < $seconds * 5; $i++) {
            if ($done()) {
                return true;
            }
            usleep(200_000);
        }
        return false;
    };
    check($waitFor(static fn (): bool => isset(JsonStore::read('discord')['checked_at']), 15), 'running');
    sleep(1);
    touch(dirname(__DIR__) . '/src/Discord/Bridge.php'); // as if the tool was updated
    check($waitFor(static fn (): bool => !proc_get_status($process)['running'], 15), 'the old process ended');
    proc_close($process);
    check($waitFor(static fn (): bool => Bridge::isRunning() && isset(JsonStore::read('discord')['restarted_at']), 30), 'a new one took over');

    $settings = JsonStore::read('settings');
    JsonStore::write('settings', ['feature_discord_bridge' => '0'] + $settings);
    check($waitFor(static fn (): bool => !Bridge::isRunning(), 15), 'and it stops when switched off');
});

test('discord: off reads nothing; missing setup or a wrong token is reported; settings are checked and the token sealed', function () {
    discordSetup();
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'feature_discord_bridge' => '0', 'discord_bot_token' => Secrets::seal('good-token'), 'discord_channel_id' => DISCORD_CHANNEL, 'discord_user_ids' => DISCORD_ME]);
    Bridge::tick();
    check(!is_file(STORAGE_DIR . '/discord.json'), 'switched off: Discord is not read');

    JsonStore::write('settings', ['deployer_name' => 'Tester', 'feature_discord_bridge' => '1', 'discord_bot_token' => Secrets::seal('wrong'), 'discord_channel_id' => DISCORD_CHANNEL, 'discord_user_ids' => DISCORD_ME]);
    try {
        Bridge::tick();
        $error = '';
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
    check(str_contains($error, 'token is wrong') && !str_contains($error, 'wrong-token') && !str_contains($error, 'Bot '), 'a wrong token is named, without the token: ' . $error);

    $base = ['deployer_name' => 'Tester', 'review_minutes' => '60', 'compare_method' => 'fingerprint', 'ftp_connections' => '4', 'background_runs' => '1', 'protected_paths' => '', 'bundle_min_files' => '50'];
    check(isset(Settings::save($base + ['feature_discord_bridge' => '1'], false)['feature_discord_bridge']), 'on without a bot: refused');
    check(isset(Settings::save($base + ['discord_channel_id' => 'general'], false)['discord_channel_id']), 'a channel name instead of its ID: refused');
    check(Settings::save($base + ['feature_discord_bridge' => '1', 'discord_bot_token' => 'abc.def', 'discord_channel_id' => DISCORD_CHANNEL, 'discord_user_ids' => DISCORD_ME . ' ' . DISCORD_OTHER]) === [], 'complete: saved');
    $stored = JsonStore::read('settings');
    check(Secrets::isSealed($stored['discord_bot_token']) && Settings::get('discord_bot_token') === 'abc.def' && $stored['discord_user_ids'] === DISCORD_ME . ', ' . DISCORD_OTHER, 'token encrypted on disk; user ids tidied');
    Settings::save($base + ['feature_discord_bridge' => '1', 'discord_bot_token' => '', 'discord_channel_id' => DISCORD_CHANNEL, 'discord_user_ids' => DISCORD_ME]);
    check(Settings::get('discord_bot_token') === 'abc.def', 'a blank token field keeps the saved one');
    check(Api::split(str_repeat('x', 4500)) === [str_repeat('x', 2000), str_repeat('x', 2000), str_repeat('x', 500)], 'text without line breaks is split at 2,000');
});
