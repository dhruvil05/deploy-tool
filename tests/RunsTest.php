<?php

declare(strict_types=1);

use DeployTool\Activity;
use DeployTool\Ai\ChatStore;
use DeployTool\JsonStore;
use DeployTool\Notify;
use DeployTool\RunStore;
use DeployTool\Settings;

/** Saves a few varied runs (deploys, a compare, a failed one) for the index tests. */
function someRuns(): void
{
    foreach ([['deploy', 'done', 3], ['sync', 'review', 2], ['deploy', 'failed', 1], ['compare', 'done', 0]] as $i => [$type, $status, $files]) {
        $run = ['id' => sprintf('20261111-1000%02d-%s-stg-aaa%d', $i, $type, $i), 'type' => $type, 'site' => 'stg', 'site_name' => 'Staging', 'repo_source' => 'dt',
            'production' => false, 'by' => 'Tester', 'status' => $status, 'created_at' => time() - $i * 60, 'from' => null, 'to' => str_repeat('b', 40), 'touched' => $status === 'failed',
            'files' => array_fill(0, $files, ['path' => 'a.php', 'action' => 'upload', 'done' => $status === 'done']), 'skipped' => [], 'log' => array_fill(0, 50, ['00:00', 'info', 'line']), 'error' => null, 'site_check' => null];
        if ($type === 'compare') {
            $run['result'] = ['differs' => ['a', 'b'], 'missing' => ['c'], 'extra' => [], 'eol' => ['d', 'e', 'f'], 'same' => 9];
        }
        RunStore::save($run);
    }
}

test('fast history: the index gives the same pages as reading every run, and follows changes', function () {
    someRuns();
    $all = RunStore::all();
    $index = RunStore::summaries();
    check(array_column($all, 'id') === array_column($index, 'id') && array_column($all, 'status') === array_column($index, 'status'), 'same runs, order and statuses');
    check(array_map(static fn ($r) => count($r['files']), $all) === array_map(static fn ($r) => count($r['files']), $index), 'same file counts');
    $compare = current(array_filter($index, static fn (array $r): bool => $r['type'] === 'compare'));
    check(count($compare['result']['eol']) === 3 && count($compare['result']['differs']) === 2 && !isset($index[0]['log']), 'compare counts kept, logs left out');
    check(Activity::forSource($all, 'dt')['files'] === Activity::forSource($index, 'dt')['files'], 'dashboard numbers identical');
    check(is_file(STORAGE_DIR . '/cache/runs-index.json'), 'the index was written');
    $run = RunStore::load($all[1]['id']);
    $run['status'] = 'done';
    RunStore::save($run);
    check(RunStore::summaries()[1]['status'] === 'done', 'a changed run is read fresh');
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'feature_run_index' => '0']);
    unlink(STORAGE_DIR . '/cache/runs-index.json');
    check(count(RunStore::summaries()) === 4 && !is_file(STORAGE_DIR . '/cache/runs-index.json'), 'off: every run is read and no index is kept');
});

test('messages: once per status change, never on repeat saves; https only', function () {
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'notify_webhook' => DeployTool\Secrets::seal('https://127.0.0.1:9/hook')]);
    $run = ['id' => '20261111-120000-deploy-stg-zzzz', 'type' => 'deploy', 'site' => 'stg', 'site_name' => 'Staging', 'production' => false, 'by' => 'Tester',
        'status' => 'running', 'to' => str_repeat('a', 40), 'files' => [['action' => 'upload', 'done' => true]], 'error' => null, 'site_check' => null, 'created_at' => time()];
    RunStore::save($run);
    check(JsonStore::read('notify-state') === [], 'starting a run sends nothing');
    $run['status'] = 'sql';
    RunStore::save($run);
    check(JsonStore::read('notify-state') !== [], 'waiting for SQL: a message is attempted');
    @unlink(STORAGE_DIR . '/notify-state.json');
    RunStore::save($run);
    check(JsonStore::read('notify-state') === [], 'saving again with the same status: nothing');
    $run['status'] = 'failed';
    $run['error'] = 'Upload failed.';
    check(Notify::message($run) === "❌ Failed: Deploy · Staging · commit aaaaaaaa · 1 file · by Tester\nUpload failed.", 'the message reads well');
    check(Notify::send('http://127.0.0.1:9/x', 'x') !== null, 'plain http is never used');
});

test('chats: renaming works again and again, and survives an older copy being saved', function () {
    $chat = ChatStore::create('dt');
    ChatStore::save($chat);
    foreach (['First', 'Second', 'Third'] as $name) {
        ChatStore::rename($chat['id'], $name);
        check(ChatStore::load($chat['id'])['title'] === $name, "renamed to $name");
    }
    $old = ChatStore::load($chat['id']);
    ChatStore::rename($chat['id'], 'Renamed mid-answer');
    $old['messages'][] = ['role' => 'assistant', 'content' => 'hi'];
    ChatStore::save($old);
    check(ChatStore::load($chat['id'])['title'] === 'Renamed mid-answer', 'the name given during an answer wins');
});

test('speed-ups: every switch exists, defaults are as documented', function () {
    foreach (array_keys(Settings::FEATURES) as $feature) {
        $off = in_array($feature, ['feature_bundle_upload', 'feature_discord_bridge', 'feature_slack_bridge'], true);
        check(Settings::on($feature) === !$off, "$feature is " . ($off ? 'off' : 'on') . ' by default');
    }
});
