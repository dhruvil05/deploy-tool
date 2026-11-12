<?php

declare(strict_types=1);

use DeployTool\Ai\Agent;
use DeployTool\Ai\ChatStore;
use DeployTool\JsonStore;
use DeployTool\RunStore;

/** A fake AI: model "broken" fails; others first ask to approve run $runId, then answer in words. */
function fakeAssistantAi(string $runId): string
{
    $dir = Tests::folder('fakeai');
    file_put_contents("$dir/router.php", '<?php $runId = ' . var_export($runId, true) . ';' . <<<'PHP'

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
header('Content-Type: application/json');
if (($body['model'] ?? '') === 'broken') { http_response_code(503); echo json_encode(['error' => ['message' => 'Overloaded']]); return; }
$last = end($body['messages']);
$base = ['model' => $body['model'], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]];
if (($last['role'] ?? '') === 'tool') {
    echo json_encode($base + ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Result: ' . $last['content']]]]]);
    return;
}
echo json_encode($base + ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['content' => '', 'tool_calls' => [
    ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'approve_run', 'arguments' => json_encode(['run_id' => $runId, 'confirm' => 'live'])]],
]]]]]);
PHP);
    return Tests::serve($dir, "$dir/router.php");
}

/** A production folder site "live" with a deploy waiting for review. */
function productionReview(): array
{
    $repo = Tests::repo([['one', ['index.php' => '1']], ['two', ['index.php' => '2']]]);
    $run = Tests::deployRun($repo, 'HEAD~1', 'HEAD');
    $sites = JsonStore::read('sites');
    $sites['site']['production'] = true;
    JsonStore::write('sites', $sites);
    $run['production'] = true;
    RunStore::save($run);
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'background_runs' => '0']); // no worker left running after the test
    return $run;
}

test('assistant: approving production always waits for the typed site key; the model\'s own key is ignored', function () {
    $run = productionReview();
    $url = fakeAssistantAi($run['id']);
    JsonStore::write('ai', ['enabled' => '1', 'provider' => 'custom', 'base_url' => "$url/v1", 'model' => 'fake', 'perm_approve' => '1']);
    $chat = ChatStore::create('repo');
    Agent::fromSettings()->reply($chat, 'approve it');
    check(Agent::pendingIndex($chat) !== null && end($chat['messages'])['approvals'] === [$run['id']], 'the chat stops at the approval card, even with Approve set to Allow');
    check(RunStore::load($run['id'])['status'] === 'review', 'the model sending the key itself approved nothing');
    check(str_contains((string) Agent::checkApprovals($chat, [$run['id'] => ['key' => 'wrong', 'ack' => true]]), 'Type site exactly'), 'a wrong key is refused before anything runs');
    check(Agent::checkApprovals($chat, [$run['id'] => ['key' => 'site', 'ack' => true]]) === null, 'the right key passes');
    Agent::fromSettings()->resume($chat, true, null, [$run['id'] => ['key' => 'site', 'ack' => false]]);
    $approved = RunStore::load($run['id']);
    check($approved['status'] === 'running' && $approved['delete_approved'] === false, 'approved, and production never deletes');
    check(str_contains(implode("\n", array_column($approved['log'], 2)), 'who typed the site key'), 'the log says the user typed the key');
    check(str_contains((string) end($chat['messages'])['content'], 'Approved by the user'), 'the assistant carries on with the result');
});

test('assistant: Cancel approves nothing; a backup model answers when the main one fails', function () {
    $run = productionReview();
    $url = fakeAssistantAi($run['id']);
    JsonStore::write('ai', ['enabled' => '1', 'provider' => 'custom', 'base_url' => "$url/v1", 'model' => 'fake', 'perm_approve' => '1']);
    $chat = ChatStore::create('repo');
    Agent::fromSettings()->reply($chat, 'approve it');
    Agent::fromSettings()->resume($chat, false);
    check(RunStore::load($run['id'])['status'] === 'review' && str_contains((string) end($chat['messages'])['content'], 'the user said no'), 'Cancel: still waiting, the assistant told no');

    JsonStore::write('ai', ['enabled' => '1', 'provider' => 'custom', 'base_url' => "$url/v1", 'model' => 'broken', 'fallback_model' => '']);
    $chat = ChatStore::create('repo');
    Agent::fromSettings()->reply($chat, 'hello');
    check(!empty(end($chat['messages'])['error']), 'no backup: the error shows');
    JsonStore::write('ai', ['enabled' => '1', 'provider' => 'custom', 'base_url' => "$url/v1", 'model' => 'broken', 'fallback_model' => 'fake', 'perm_approve' => '0']);
    $chat = ChatStore::create('repo');
    Agent::fromSettings()->reply($chat, 'hello');
    check(in_array('fake', array_column($chat['messages'], 'model'), true), 'with a backup: the backup model answered');
});
