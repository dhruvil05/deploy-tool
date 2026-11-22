<?php

declare(strict_types=1);

use DeployTool\JsonStore;
use DeployTool\Monitor;
use DeployTool\RunStore;
use DeployTool\Safety;
use DeployTool\Scheduler;
use DeployTool\ServerWatch;
use DeployTool\Sites;

/** A folder site "stg" with a few files; $settings added to the speed-ups. */
function watchSetup(array $settings = []): string
{
    $repo = Tests::repo([['first', ['index.php' => '1']]]);
    $site = Tests::folder('site');
    file_put_contents("$site/index.php", '1');
    file_put_contents("$site/about.php", 'a');
    mkdir("$site/uploads");
    file_put_contents("$site/uploads/photo.jpg", 'x');
    JsonStore::write('sources', ['shop' => ['key' => 'shop', 'name' => 'Shop', 'repo_path' => $repo, 'branch' => 'main', 'remote' => 'origin', 'extra_protected' => 'uploads/']]);
    JsonStore::write('sites', ['stg' => ['key' => 'stg', 'name' => 'Staging', 'type' => 'folder', 'source' => 'local:shop', 'production' => false, 'path' => $site, 'url' => '', 'check_path' => '']]);
    JsonStore::write('settings', $settings + ['deployer_name' => 'Tester', 'background_runs' => '0']);
    return $site;
}

test('server watch: notes the files, then lists what changed outside the tool; protected paths and the tool\'s deploys are left out', function () {
    $site = watchSetup();
    $first = ServerWatch::check(Sites::get('stg'));
    check($first['baseline'] && $first['files'] === 2, 'first look: 2 files noted, the protected upload left out');

    file_put_contents("$site/index.php", 'hotfix');
    file_put_contents("$site/shell.php", '<?php eval($_POST["x"]);');
    unlink("$site/about.php");
    file_put_contents("$site/uploads/new.jpg", 'y');
    file_put_contents("$site/deployed.php", 'from the tool');
    $deploy = ['id' => '20261122-100000-deploy-stg-aaaa', 'type' => 'deploy', 'site' => 'stg', 'site_name' => 'Staging', 'production' => false, 'by' => 'Tester',
        'status' => 'done', 'created_at' => time(), 'touched' => true, 'to' => str_repeat('a', 40), 'files' => [['path' => 'deployed.php', 'action' => 'upload', 'done' => true]], 'error' => null, 'site_check' => null];
    RunStore::save($deploy);

    $result = ServerWatch::check(Sites::get('stg'));
    check($result['changed'] === ['index.php'] && $result['added'] === ['shell.php'] && $result['removed'] === ['about.php'], 'the hotfix, the new file and the removed one: ' . json_encode([$result['changed'], $result['added'], $result['removed']]));
    check($result['seen'] === false && $result['counts'] === [1, 1, 1], 'flagged until seen');
    ServerWatch::markSeen('stg');
    check(ServerWatch::result('stg')['seen'] === true, '"Mark as seen" clears the flag');
    check(array_sum(ServerWatch::check(Sites::get('stg'))['counts']) === 0, 'nothing new on the next check');
});

test('server watch: malware markers in the server\'s build files are found; the scan reads nothing to disk', function () {
    $site = watchSetup();
    ServerWatch::check(Sites::get('stg'));
    file_put_contents("$site/package.json", '{"scripts":{"dev":"node init && mix"}}');
    file_put_contents("$site/tailwind.config.js", 'module.exports = {};' . str_repeat("\t", 300) . 'var _0x1a2b=1;');
    file_put_contents("$site/api.js", 'x');
    $found = implode("\n", ServerWatch::check(Sites::get('stg'))['malware']);
    foreach (['hidden script', 'api.js exists', 'hundreds of spaces', 'obfuscated'] as $marker) {
        check(str_contains($found, $marker), "finds: $marker");
    }
    check(Safety::scanContents(['tailwind.config.js' => "module.exports = { content: ['./src/**/*.php'] };\n", 'package.json' => '{"scripts":{"dev":"mix"}}']) === [], 'clean files pass');
    check(!Safety::isSuspectPath('src/api.js') && Safety::isSuspectPath('vite.config.mjs'), 'only top-folder build files are read');
});

test('uptime monitor: down after two failed checks, back up when it loads again; plain http has no certificate', function () {
    $dir = Tests::folder('web');
    file_put_contents("$dir/index.php", '<?php if (is_file(__DIR__ . "/broken")) { http_response_code(500); echo "Fatal error"; return; } echo "ok";');
    $url = Tests::serve($dir);
    watchSetup(['feature_uptime' => '1', 'feature_smart_checks' => '1']);
    $sites = JsonStore::read('sites');
    $sites['stg']['url'] = $url;
    $sites['stg']['check_path'] = '/index.php';
    JsonStore::write('sites', $sites);

    $ok = Monitor::check(Sites::get('stg'));
    check($ok['ok'] && $ok['up'] && $ok['cert'] === null, 'up; no certificate for http');
    touch("$dir/broken");
    check(Monitor::check(Sites::get('stg'))['up'], 'one failure is not "down" yet');
    $down = Monitor::check(Sites::get('stg'));
    check(!$down['up'] && $down['down_since'] !== null && str_contains((string) $down['problem'], 'Fatal error'), 'two in a row: down, with the reason');
    unlink("$dir/broken");
    $back = Monitor::check(Sites::get('stg'));
    check($back['up'] && $back['down_since'] === null, 'back up');
});

test('scheduler: runs only what is switched on and due; hidden sites are skipped', function () {
    $site = watchSetup(['feature_server_watch' => '0', 'feature_uptime' => '0']);
    check(!Scheduler::wanted(), 'both off: no background process wanted');
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'background_runs' => '0', 'feature_server_watch' => '1', 'watch_hours' => '6']);
    check(Scheduler::wanted(), 'the watch on: wanted');
    Scheduler::tick();
    check(ServerWatch::result('stg')['baseline'] ?? false, 'the due watch ran');
    file_put_contents("$site/new.php", 'n');
    Scheduler::tick();
    check(array_sum(ServerWatch::result('stg')['counts']) === 0, 'not due again for 6 hours');
    $sites = JsonStore::read('sites');
    $sites['stg']['hidden'] = true;
    JsonStore::write('sites', $sites);
    JsonStore::write('watch', []);
    Scheduler::tick();
    check(ServerWatch::result('stg') === null, 'a hidden site is left alone');
});
