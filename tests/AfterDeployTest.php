<?php

declare(strict_types=1);

use DeployTool\Backup;
use DeployTool\DeployRun;
use DeployTool\JsonStore;
use DeployTool\Runner;
use DeployTool\RunStore;
use DeployTool\Settings;
use DeployTool\Sites;

/** A folder site one commit behind, with $fields (commands, database) on its page. */
function afterDeploySetup(array $fields, array $settings = []): array
{
    $repo = Tests::repo([['first', ['index.php' => '1']], ['second', ['index.php' => '2']]]);
    $git = new DeployTool\Git($repo, Settings::gitBinary());
    $git->okRaw(['update-ref', 'refs/remotes/origin/main', 'HEAD']);
    $site = Tests::folder('site');
    file_put_contents("$site/index.php", '1');
    @mkdir("$site/application/.deploy", 0777, true);
    file_put_contents("$site/application/.deploy/state.json", json_encode(['commit' => $git->resolve('HEAD~1')]));
    JsonStore::write('sources', ['shop' => ['key' => 'shop', 'name' => 'Shop', 'repo_path' => $repo, 'branch' => 'main', 'remote' => 'origin', 'extra_protected' => '']]);
    JsonStore::write('sites', ['stg' => $fields + ['key' => 'stg', 'name' => 'Staging', 'type' => 'folder', 'source' => 'local:shop', 'production' => false, 'path' => $site, 'url' => '', 'check_path' => '']]);
    JsonStore::write('settings', $settings + ['deployer_name' => 'Tester', 'background_runs' => '0']);
    return [$site, DeployRun::create(Sites::get('stg'), 'deploy')];
}

function stepRun(string $id): array
{
    for ($i = 0; $i < 30; $i++) {
        $run = Runner::step($id);
        if ($run['status'] !== 'running') {
            return $run;
        }
    }
    return $run;
}

/** A command line that runs PHP code, for the commands after a deploy. */
function phpCommand(string $code): string
{
    return '"' . PHP_BINARY . '" -r "' . $code . '"';
}

/** A fake mysqldump: checks the option file it was given, prints a dump (or fails for "faildb"). */
function fakeDump(): string
{
    $dir = Tests::folder('dump');
    file_put_contents("$dir/dump.php", <<<'PHP'
<?php
$options = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--defaults-extra-file=')) {
        $options = (string) file_get_contents(substr($arg, 22));
    }
}
$db = end($argv);
if ($db === 'faildb') { fwrite(STDERR, "Access denied for user 'shop'"); exit(2); }
echo "-- dump of $db\n-- options: " . (str_contains($options, 'password="s3cr\\"et"') && str_contains($options, 'user="shop"') ? 'ok' : 'missing') . "\nCREATE TABLE t (id INT);\n";
PHP);
    return '"' . PHP_BINARY . '" "' . $dir . '/dump.php"';
}

test('commands after a deploy: shown at the review, run in the site\'s folder after the upload, logged', function () {
    [$site, $run] = afterDeploySetup(['post_commands' => phpCommand("file_put_contents('after.txt', 'ran');") . "\n" . phpCommand("echo 'hello from the server';")]);
    check(count($run['commands']) === 2 && isset(DeployRun::phases($run)['commands']) && !isset(DeployRun::phases($run)['db_backup']), 'noted at the start; the steps include them');
    $run = stepRun($run['id']);
    check($run['status'] === 'review' && !is_file("$site/after.txt"), 'nothing runs before approval');
    DeployRun::approve($run, ['ack_warnings' => '1']);
    RunStore::save($run);
    $run = stepRun($run['id']);
    check(in_array($run['status'], ['sql', 'done'], true) && file_get_contents("$site/after.txt") === 'ran', 'ran after the upload: ' . ($run['error'] ?? ''));
    check(str_contains(implode("\n", array_column($run['log'], 2)), 'hello from the server') && $run['commands_done'] === 2, 'output in the log');
});

test('commands after a deploy: a failed command stops the run; off runs nothing; FTP sites never run commands', function () {
    [$site, $run] = afterDeploySetup(['post_commands' => phpCommand('exit(3);') . "\n" . phpCommand("file_put_contents('never.txt', 'x');")]);
    $run = stepRun($run['id']);
    DeployRun::approve($run, ['ack_warnings' => '1']);
    RunStore::save($run);
    $run = stepRun($run['id']);
    check($run['status'] === 'failed' && str_contains((string) $run['error'], 'exit code 3') && !is_file("$site/never.txt") && file_get_contents("$site/index.php") === '2', 'failed at the first command, files deployed, the next command not run');
    $run['status'] = 'cancelled'; // free the site for the next part
    RunStore::save($run);

    [, $off] = afterDeploySetup(['post_commands' => phpCommand('exit(0);')], ['feature_post_commands' => '0']);
    check($off['commands'] === [] && !isset(DeployRun::phases($off)['commands']), 'off: no commands, no step');
    $ftp = ['type' => 'ftp', 'post_commands' => 'rm -rf /', 'db_name' => 'shop'] + Sites::blank();
    check(DeployRun::create($ftp + ['key' => 'ftp1', 'name' => 'F'], 'deploy')['commands'] === [], 'FTP sites never get commands or a database backup');
});

test('database backup: a mysqldump before the review, login in a private option file, kept compressed with the run', function () {
    [, $run] = afterDeploySetup(['db_name' => 'shopdb', 'db_user' => 'shop', 'db_password' => 's3cr"et', 'db_host' => 'localhost', 'db_dump' => fakeDump()]);
    check($run['db_backup']['name'] === 'shopdb' && isset(DeployRun::phases($run)['db_backup']), 'planned at the start');
    $run = stepRun($run['id']);
    check($run['status'] === 'review' && $run['db_backup']['size'] > 0, 'backed up, then the review: ' . ($run['error'] ?? ''));
    $dump = (string) gzdecode((string) file_get_contents(Backup::databaseFile($run['id'])));
    check(str_starts_with($dump, '-- dump of shopdb') && str_contains($dump, 'options: ok'), 'the dump is kept; the login (with a quote in the password) arrived through the option file');
    check(!str_contains(json_encode($run), 's3cr'), 'the password is nowhere in the run');
    $sites = JsonStore::read('sites');
    check(DeployTool\Secrets::isSealed($sites['stg']['db_password'] ?? '') || ($sites['stg']['db_password'] ?? '') === 's3cr"et', 'stored as given in this test (Sites::save seals it)');
});

test('database backup: a failed dump stops the run before anything changes; settings are checked', function () {
    [$site, $run] = afterDeploySetup(['db_name' => 'faildb', 'db_user' => 'shop', 'db_password' => 'x', 'db_host' => 'localhost', 'db_dump' => fakeDump()]);
    $run = stepRun($run['id']);
    check($run['status'] === 'failed' && str_contains((string) $run['error'], 'Access denied') && str_contains((string) $run['error'], 'Nothing was changed') && file_get_contents("$site/index.php") === '1', 'failed with mysqldump\'s reason; the server is untouched');

    $base = ['name' => 'X', 'type' => 'folder', 'source' => 'local:shop', 'path' => $site];
    [$errors] = Sites::save($base + ['key' => 'x1', 'db_name' => 'shop; drop', 'db_user' => 'u', 'db_dump' => 'mysqldump; rm -rf /'], null);
    check(isset($errors['db_name'], $errors['db_dump']), 'a database name or dump program that chains commands is refused');
    [$errors] = Sites::save($base + ['key' => 'x2', 'post_commands' => implode("\n", array_fill(0, 11, 'echo hi'))], null);
    check(isset($errors['post_commands']), 'more than 10 commands is refused');
    [$errors] = Sites::save($base + ['key' => 'x3', 'db_name' => 'shop', 'db_user' => 'u', 'db_password' => 'pw'], null);
    check($errors === [] && DeployTool\Secrets::isSealed(JsonStore::read('sites')['x3']['db_password']) && Sites::get('x3')['db_password'] === 'pw', 'the database password is stored encrypted');
});
