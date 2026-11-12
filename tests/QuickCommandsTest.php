<?php

declare(strict_types=1);

use DeployTool\Ai\ChatStore;
use DeployTool\Ai\QuickCommands;
use DeployTool\JsonStore;
use DeployTool\RunStore;

/** A local with two folder sites (Staging, Staging Two) and a chat about it. */
function quickChat(): array
{
    $repo = Tests::repo([['first', ['index.php' => '<?php echo 1;']], ['second', ['index.php' => '<?php echo 2;']]]);
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'background_runs' => '0']); // no worker left running after the test
    JsonStore::write('sources', ['repo' => ['key' => 'repo', 'name' => 'Repo', 'repo_path' => $repo, 'branch' => 'main', 'remote' => 'origin', 'extra_protected' => '']]);
    // Staging runs the first commit (recorded, as after a deploy), so a deploy can be prepared.
    $staging = Tests::folder('stg');
    mkdir("$staging/application/.deploy", 0777, true);
    file_put_contents("$staging/application/.deploy/state.json", json_encode(['commit' => (new DeployTool\Git($repo, DeployTool\Settings::gitBinary()))->resolve('HEAD~1')]));
    JsonStore::write('sites', [
        'staging' => ['key' => 'staging', 'name' => 'Staging', 'type' => 'folder', 'source' => 'local:repo', 'path' => $staging, 'url' => '', 'check_path' => ''],
        'staging-two' => ['key' => 'staging-two', 'name' => 'Staging Two', 'type' => 'folder', 'source' => 'local:repo', 'path' => Tests::folder('stg2'), 'url' => '', 'check_path' => ''],
    ]);
    JsonStore::write('ai', ['enabled' => '1', 'provider' => 'custom', 'base_url' => 'http://127.0.0.1:9/v1', 'model' => 'none']);
    return ChatStore::create('repo');
}

function quick(array &$chat, string $text): string
{
    QuickCommands::handle($chat, $text);
    return (string) end($chat['messages'])['content'];
}

test('quick commands: run without the AI, through the same actions', function () {
    $chat = quickChat();
    check(QuickCommands::applies('/status') && !QuickCommands::applies('what is the status?'), 'only messages starting with /');
    check(str_contains(quick($chat, '/help'), '`/compare staging`'), '/help lists the commands');
    $status = quick($chat, '/status');
    check(str_contains($status, '**Repo**') && str_contains($status, '**Staging**') && str_contains($status, 'no deploy from this tool yet'), '/status shows the local and each site');
    $site = quick($chat, '/status staging');
    check(str_contains($site, 'Recorded version: `') && str_contains($site, '1 commit(s) behind'), '/status <site> checks the site: ' . $site);
    check(str_contains(quick($chat, '/status staging-two'), 'Recorded version: none yet'), 'a site without a version says so');
    check(str_contains(quick($chat, '/status stag'), 'fits more than one site'), 'an ambiguous name asks which');
    check(str_contains(quick($chat, '/compare staging-two'), 'Compare started on **Staging Two**'), '/compare by key starts a compare');
    check(RunStore::latestFor('staging-two', ['compare'], ['running']) !== null, 'and the compare run exists');
    $d = quick($chat, "/deploy Staging"); check(str_contains($d, "Deploy being prepared on **Staging**"), "/deploy by name prepares a deploy: " . $d);
    $history = quick($chat, '/history');
    check(str_contains($history, '| Started |') && substr_count($history, 'index.php?r=run') === 2, '/history lists both runs');
    check(str_contains(quick($chat, '/nope'), 'no command /nope'), 'an unknown command shows the help');
    $last = end($chat['messages']);
    check($last['model'] === 'quick command' && !isset($last['calls']), 'answers are marked as quick commands, with no AI calls');
});

test('quick commands: AI permissions apply, and off sends everything to the AI', function () {
    $chat = quickChat();
    JsonStore::write('ai', ['enabled' => '1', 'provider' => 'custom', 'base_url' => 'http://127.0.0.1:9/v1', 'model' => 'none', 'perm_prepare' => '0']);
    check(str_contains(quick($chat, '/deploy staging'), 'Not allowed'), 'a permission that is off refuses');
    check(RunStore::latestFor('staging', ['deploy'], ['running']) === null, 'and nothing was prepared');
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'feature_quick_commands' => '0']);
    check(!QuickCommands::applies('/status'), 'off: /status is an ordinary message');
});
