<?php

declare(strict_types=1);

use DeployTool\Ai;
use DeployTool\Ai\Agent;
use DeployTool\Ai\ChatStore;
use DeployTool\Ai\Tools;
use DeployTool\JsonStore;

/** A cloned local "clone" one commit behind its origin, as the chat's local. */
function pullSetup(array $ai): array
{
    $origin = Tests::repo([['one', ['index.php' => '1']]]);
    [$source] = cloneOf($origin);
    commitIn($origin, 'two', ['index.php' => '2']);
    JsonStore::write('sources', ['clone' => $source]);
    JsonStore::write('settings', ['deployer_name' => 'Tester']);
    JsonStore::write('ai', ['enabled' => '1'] + $ai);
    return [$origin, $source];
}

/** A fake AI that asks to pull once, then answers with the action's result. */
function fakePullAi(): string
{
    $dir = Tests::folder('fakeai');
    file_put_contents("$dir/router.php", <<<'PHP'
<?php
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
header('Content-Type: application/json');
$last = end($body['messages']);
$base = ['model' => $body['model'], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]];
if (($last['role'] ?? '') === 'tool') {
    echo json_encode($base + ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Result: ' . $last['content']]]]]);
    return;
}
echo json_encode($base + ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['content' => '', 'tool_calls' => [
    ['id' => 'p1', 'type' => 'function', 'function' => ['name' => 'pull_local', 'arguments' => '{}']],
]]]]]);
PHP);
    return Tests::serve($dir, "$dir/router.php");
}

test('assistant pull: "Ask" by default, so the chat asks first; Allow pulls, Deny pulls nothing', function () {
    check(Ai::defaults()['perm_pull'] === 'ask', 'the new permission starts at Ask');
    $url = fakePullAi();
    [, $source] = pullSetup(['provider' => 'custom', 'base_url' => "$url/v1", 'model' => 'fake']);
    check(in_array('pull_local', array_column(Tools::allowedDefinitions(), 'name'), true), 'offered to the model');

    $chat = ChatStore::create('clone');
    Agent::fromSettings()->reply($chat, 'pull the latest');
    check(Agent::pendingIndex($chat) !== null && file_get_contents($source['repo_path'] . '/index.php') === '1', 'waits for Allow; nothing pulled yet');
    Agent::fromSettings()->resume($chat, false);
    check(file_get_contents($source['repo_path'] . '/index.php') === '1' && str_contains((string) end($chat['messages'])['content'], 'the user said no'), 'Deny: nothing pulled');

    $chat = ChatStore::create('clone');
    Agent::fromSettings()->reply($chat, 'pull the latest');
    Agent::fromSettings()->resume($chat, true);
    check(file_get_contents($source['repo_path'] . '/index.php') === '2' && str_contains((string) end($chat['messages'])['content'], 'Pulled 1 commit(s)'), 'Allow: pulled, and the assistant reports it');
});

test('assistant pull: Off or the speed-up switched off refuse; malware on GitHub stops it', function () {
    [$origin, $source] = pullSetup(['perm_pull' => '0']);
    check(!in_array('pull_local', array_column(Tools::allowedDefinitions(), 'name'), true), 'Off: not offered');
    $off = Tools::run('pull_local', [], 'clone');
    check(!$off['ok'] && str_contains($off['result'], 'AI permissions'), 'Off: refused even if asked for directly');

    JsonStore::write('ai', ['enabled' => '1', 'perm_pull' => '1']);
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'feature_git_pull' => '0']);
    check(!in_array('pull_local', array_column(Tools::allowedDefinitions(), 'name'), true), 'speed-up off: not offered');
    check(str_contains(Tools::run('pull_local', [], 'clone')['result'], 'switched off'), 'speed-up off: refused');

    JsonStore::write('settings', ['deployer_name' => 'Tester']);
    commitIn($origin, 'infected', ['package.json' => '{"scripts":{"dev":"node init && mix"}}', 'init' => "var a=1;\n"]);
    $result = Tools::run('pull_local', [], 'clone');
    check(!$result['ok'] && str_contains($result['result'], 'malware markers') && file_get_contents($source['repo_path'] . '/index.php') === '1' && !is_file($source['repo_path'] . '/init'), 'malware: stopped, nothing changed');
    check(Tools::label('pull_local', [], true) === 'Pull from GitHub', 'the Allow / Deny question reads well');
});
