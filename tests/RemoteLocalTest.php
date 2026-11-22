<?php

declare(strict_types=1);

use DeployTool\DeployRun;
use DeployTool\JsonStore;
use DeployTool\LocalStatus;
use DeployTool\Runner;
use DeployTool\RunStore;
use DeployTool\Secrets;
use DeployTool\Sites;
use DeployTool\Sources;

/** A repository standing in for GitHub, and a local made from its address. */
function remoteLocal(array $settings = []): array
{
    $origin = Tests::repo([['one', ['index.php' => '1']], ['two', ['index.php' => '2']]]);
    JsonStore::write('settings', $settings + ['deployer_name' => 'Tester', 'background_runs' => '0']);
    [$errors, $source] = Sources::save(['repo_kind' => 'url', 'repo_url' => 'file:///' . $origin, 'key' => 'remote', 'name' => 'Remote', 'branch' => 'main', 'remote' => 'origin'], null);
    return [$origin, $errors, $source];
}

test('locals from a Git address: the tool keeps its own copy, with no folder of yours and no checkout warnings', function () {
    [, $errors, $source] = remoteLocal();
    check($errors === [], 'saved: ' . json_encode($errors));
    check(Sources::isManaged($source) && str_starts_with(str_replace('\\', '/', $source['repo_path']), str_replace('\\', '/', STORAGE_DIR) . '/repos/remote'), 'its copy is in the data folder');
    $git = Sources::git($source);
    check($git->isBare() && $git->isRepo() && $git->uncommittedFiles() === [], 'a bare copy: nothing can be uncommitted');
    $status = LocalStatus::collect(Sources::get('remote'));
    check($status['error'] === null && $status['warnings'] === [] && $status['commit']['subject'] === 'two', 'the dashboard reads it, without warnings: ' . json_encode($status['warnings']));
});

test('locals from a Git address: fetching follows the branch, but stops on malware markers', function () {
    [$origin] = remoteLocal();
    commitIn($origin, 'three', ['index.php' => '3']);
    check(str_contains(Sources::fetch(Sources::get('remote')), 'now at') && LocalStatus::collect(Sources::get('remote'))['commit']['subject'] === 'three', 'fetched and moved to the new commit');
    commitIn($origin, 'infected', ['package.json' => '{"scripts":{"dev":"node init && mix"}}', 'init' => "var a=1;\n"]);
    try {
        Sources::fetch(Sources::get('remote'));
        $error = '';
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
    $status = LocalStatus::collect(Sources::get('remote'));
    check(str_contains($error, 'malware markers') && $status['commit']['subject'] === 'three' && $status['remote_malware'] !== [], 'stopped: the copy stays on the clean commit, and the card warns');
});

test('locals from a Git address: deploys come from the copy', function () {
    [$origin, , $source] = remoteLocal();
    $git = Sources::git($source);
    $site = Tests::folder('site');
    file_put_contents("$site/index.php", '1');
    @mkdir("$site/application/.deploy", 0777, true);
    file_put_contents("$site/application/.deploy/state.json", json_encode(['commit' => $git->resolve('main~1')]));
    JsonStore::write('sites', ['stg' => ['key' => 'stg', 'name' => 'Staging', 'type' => 'folder', 'source' => 'local:remote', 'production' => false, 'path' => $site, 'url' => '', 'check_path' => '']]);
    $run = DeployRun::create(Sites::get('stg'), 'deploy');
    for ($i = 0; $i < 20 && ($run = Runner::step($run['id']))['status'] === 'running'; $i++) {
    }
    check($run['status'] === 'review' && count($run['files']) === 1, 'prepared from the copy, no uncommitted-changes check in the way: ' . ($run['error'] ?? json_encode($run['checks'] ?? [])));
    DeployRun::approve($run, []);
    RunStore::save($run);
    for ($i = 0; $i < 20 && ($run = Runner::step($run['id']))['status'] === 'running'; $i++) {
    }
    check(file_get_contents("$site/index.php") === '2', 'deployed');
});

test('locals from a Git address: tokens are encrypted and only for https; bad addresses and the switch are checked', function () {
    $origin = Tests::repo([['one', ['index.php' => '1']]]);
    JsonStore::write('settings', ['deployer_name' => 'Tester']);
    [$errors] = Sources::save(['repo_kind' => 'url', 'repo_url' => 'C:\\somewhere', 'key' => 'bad', 'name' => 'B', 'branch' => 'main', 'remote' => 'origin'], null);
    check(isset($errors['repo_url']), 'a folder path is not an address');
    [$errors] = Sources::save(['repo_kind' => 'url', 'repo_url' => 'git@github.com:you/x.git', 'repo_token' => 'ghp_x', 'key' => 'bad2', 'name' => 'B', 'branch' => 'main', 'remote' => 'origin'], null);
    check(isset($errors['repo_token']), 'a token with a git@ address is refused');
    [$errors] = Sources::save(['repo_kind' => 'url', 'repo_url' => 'file:///' . $origin, 'key' => 'tok', 'name' => 'T', 'branch' => 'main', 'remote' => 'origin'], null);
    check($errors === [], 'saved');
    $raw = JsonStore::read('sources');
    $raw['tok']['repo_token'] = 'ghp_secret'; // as if typed for an https address
    JsonStore::write('sources', $raw);
    Sources::addProtected('tok', 'cache/'); // any save
    $stored = JsonStore::read('sources')['tok'];
    check(Secrets::isSealed($stored['repo_token']) && Sources::get('tok')['repo_token'] === 'ghp_secret', 'the token is stored encrypted, readable in the tool');
    $env = (new ReflectionMethod(Sources::class, 'credentials'))->invoke(null, ['repo_url' => 'https://github.com/you/x.git', 'repo_token' => 'ghp_secret']);
    check($env['GIT_CONFIG_KEY_0'] === 'http.extraHeader' && $env['GIT_CONFIG_VALUE_0'] === 'Authorization: Basic ' . base64_encode('x-access-token:ghp_secret') && $env['GIT_CONFIG_VALUE_1'] === '', 'git gets it as a header through the environment, with no credential pop-ups');

    JsonStore::write('settings', ['deployer_name' => 'Tester', 'feature_remote_locals' => '0']);
    [, $source] = Sources::save(['repo_kind' => 'url', 'repo_url' => 'file:///' . $origin, 'repo_path' => $origin, 'key' => 'off1', 'name' => 'O', 'branch' => 'main', 'remote' => 'origin'], null);
    check(!Sources::isManaged($source), 'off: a new local is a folder');
    $raw = JsonStore::read('sources');
    $raw['tok']['repo_token'] = ''; // the test's token above was only for an https address
    JsonStore::write('sources', $raw);
    [$errors, $kept] = Sources::save(['key' => 'tok', 'name' => 'T renamed', 'branch' => 'main', 'remote' => 'origin'], 'tok');
    check($errors === [] && Sources::isManaged($kept) && $kept['repo_url'] === 'file:///' . $origin, 'off, or no choice sent (the assistant): an existing one stays a Git address: ' . json_encode($errors));
});
