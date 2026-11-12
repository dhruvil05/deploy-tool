<?php

declare(strict_types=1);

use DeployTool\Ai;
use DeployTool\Ai\Markdown;
use DeployTool\Ai\Tools;
use DeployTool\HostKeys;
use DeployTool\JsonStore;
use DeployTool\RunController;
use DeployTool\Safety;
use DeployTool\Secrets;
use DeployTool\Settings;
use DeployTool\Sites;
use DeployTool\StorageLocation;

test('security: CSV cells that would run as Excel formulas are escaped', function () {
    $out = fopen('php://memory', 'w+');
    (new ReflectionMethod(RunController::class, 'csv'))->invoke(null, $out, ['=HYPERLINK("x")', '+1', '-2', '@SUM(1)', 'normal/path.php']);
    rewind($out);
    check(stream_get_contents($out) === "\"'=HYPERLINK(\"\"x\"\")\",'+1,'-2,'@SUM(1),normal/path.php\n", 'each risky cell starts with a quote');
});

test('security: the saved AI key only goes to the saved address', function () {
    JsonStore::write('ai', ['provider' => 'openrouter', 'base_url' => 'https://openrouter.ai/api/v1', 'api_key' => 'sk-saved', 'model' => 'm']);
    [$same] = Ai::fromInput(['ai_provider' => 'openrouter', 'ai_base_url' => 'https://openrouter.ai/api/v1/', 'ai_api_key' => '']);
    [$moved] = Ai::fromInput(['ai_provider' => 'custom', 'ai_base_url' => 'https://evil.example/v1', 'ai_api_key' => '']);
    check($same['api_key'] === 'sk-saved' && $moved['api_key'] === '', 'kept for the same address, blank for a new one');
});

test('security: the AI cannot redirect a saved password, add folder sites or point a local elsewhere', function () {
    JsonStore::write('sources', ['dt' => ['key' => 'dt', 'name' => 'DT', 'repo_path' => dirname(__DIR__), 'branch' => 'master', 'remote' => 'origin', 'extra_protected' => '']]);
    JsonStore::write('ai', ['enabled' => '1', 'perm_edit_site' => '1', 'perm_add_site' => '1', 'perm_add_local' => '1', 'perm_edit_local' => '1']);
    Sites::save(['key' => 'stg', 'name' => 'Stg', 'type' => 'ftp', 'source' => 'local:dt', 'host' => 'ftp.example.com', 'port' => 21, 'username' => 'u', 'password' => 'secret', 'root' => '/', 'verify_tls' => '1', 'connections' => 4, 'url' => 'https://example.com', 'check_path' => ''], null);
    foreach ([['host' => 'evil.example.net'], ['port' => 2121], ['username' => 'other'], ['verify_tls' => false], ['type' => 'folder']] as $change) {
        check(!Tools::run('edit_site', ['site' => 'stg', 'changes' => $change], 'dt')['ok'], 'refused: ' . json_encode($change));
    }
    check(Tools::run('edit_site', ['site' => 'stg', 'changes' => ['name' => 'Renamed']], 'dt')['ok'], 'a harmless change still works');
    check(Sites::get('stg')['host'] === 'ftp.example.com' && Sites::get('stg')['password'] === 'secret', 'host and password unchanged');
    check(!Tools::run('add_site', ['key' => 'f1', 'name' => 'F', 'type' => 'folder', 'path' => sys_get_temp_dir()], 'dt')['ok'], 'no folder sites from the chat');
    check(!Tools::run('add_local', ['key' => 'other', 'name' => 'O', 'repo_path' => 'C:/somewhere/else', 'branch' => 'main'], 'dt')['ok'], 'no local outside the usual folder');
    check(!Tools::run('edit_local', ['changes' => ['repo_path' => dirname(__DIR__, 2) . '/x']], 'dt')['ok'], "no change of a local's folder");
});

test('security: storage folders inside web folders are refused; synced folders warned', function () {
    check(StorageLocation::problem('C:\\laragon\\www\\dt-data') !== null && StorageLocation::problem('C:\\xampp\\htdocs\\data') !== null, 'web folders refused');
    check(StorageLocation::problem('D:\\deploy-tool-data') === null, 'a normal folder is fine');
    check(StorageLocation::warning('C:\\Users\\me\\OneDrive\\dt') !== null && StorageLocation::warning('D:\\data') === null, 'OneDrive warned');
});

test('security: assistant links show where they go; other schemes lose the link', function () {
    $html = Markdown::html('[notes](https://evil.example/x?y=1) [run](index.php?r=history) [bad](javascript:alert(1))');
    check(str_contains($html, '(evil.example)') && str_contains($html, 'nofollow'), 'an external link shows its site');
    check(str_contains($html, '<a href="index.php?r=history">run</a>') && !str_contains($html, 'javascript:'), 'own links stay; javascript: is dropped');
});

test('security: the chat webhook must be https and is stored encrypted', function () {
    $base = ['deployer_name' => 'Tester', 'review_minutes' => '60', 'compare_method' => 'fingerprint', 'ftp_connections' => '4', 'background_runs' => '1', 'protected_paths' => '', 'bundle_min_files' => '50'];
    check(isset(Settings::save($base + ['notify_webhook' => 'http://plain.example/hook'], false)['notify_webhook']), 'http refused');
    check(Settings::save($base + ['notify_webhook' => 'https://hooks.example/abc']) === [], 'https saved');
    check(Secrets::isSealed(JsonStore::read('settings')['notify_webhook']) && Settings::get('notify_webhook') === 'https://hooks.example/abc', 'encrypted on disk, readable in the tool');
    Settings::save($base + ['notify_webhook' => '']);
    check(Settings::get('notify_webhook') === 'https://hooks.example/abc', 'a blank field keeps it');
    Settings::save($base + ['notify_webhook' => '', 'notify_webhook_remove' => '1']);
    check(Settings::get('notify_webhook') === '', 'Remove removes it');
});

test('security: trusted SSH keys are matched by host and port, plain or hashed', function () {
    $blob = base64_encode('fake-key-bytes');
    $salt = random_bytes(20);
    $hashed = '|1|' . base64_encode($salt) . '|' . base64_encode(hash_hmac('sha1', '[git.example]:2222', $salt, true));
    file_put_contents(HostKeys::file(), "example.org ssh-ed25519 $blob\n$hashed ssh-ed25519 $blob\n# a comment\n");
    $fingerprint = 'SHA256:' . rtrim(base64_encode(hash('sha256', 'fake-key-bytes', true)), '=');
    check(HostKeys::trusted('example.org', 22) === [['type' => 'ssh-ed25519', 'fingerprint' => $fingerprint]], 'plain entry found, fingerprint as ssh shows it');
    check(count(HostKeys::trusted('git.example', 2222)) === 1 && HostKeys::trusted('git.example', 22) === [], 'hashed entry found for its port only');
    check(HostKeys::trusted('other.example', 22) === [], 'an unknown server is not trusted');
});

test('security: malware markers are found by name and count only; clean code passes', function () {
    $repo = Tests::repo([
        ['clean', ['tailwind.config.js' => "module.exports = {};\n", 'package.json' => '{"scripts":{"dev":"mix"}}']],
        ['infected', [
            'tailwind.config.js' => 'module.exports = {};' . str_repeat("\t", 300) . 'var _0x1a2b=1;' . str_repeat('x', 1200) . "\n",
            'package.json' => '{"scripts":{"dev":"node init && mix"}}',
            'init' => "var a=1;\n",
        ]],
    ]);
    $git = new DeployTool\Git($repo, Settings::gitBinary());
    check(Safety::malwareFindings($git, $git->resolve('HEAD~1')) === [], 'the clean commit passes');
    $found = implode("\n", Safety::malwareFindings($git, $git->resolve('HEAD')));
    foreach (['hidden script', '1000+ characters', 'hundreds of spaces or tabs', 'obfuscated'] as $marker) {
        check(str_contains($found, $marker), "finds: $marker");
    }
});
