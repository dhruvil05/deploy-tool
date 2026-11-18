<?php

declare(strict_types=1);

use DeployTool\Backup;
use DeployTool\CompareRun;
use DeployTool\DeployRun;
use DeployTool\Git;
use DeployTool\JsonStore;
use DeployTool\RollbackRun;
use DeployTool\Runner;
use DeployTool\RunStore;
use DeployTool\Settings;

/**
 * A folder site on the repo's first commit plus files git never had or later deleted, and a
 * finished compare of it with the second commit (old.php deleted in git, still on the server).
 */
function extrasSetup(bool $production = false): array
{
    $repo = Tests::repo([
        ['one', ['index.php' => '1', 'old.php' => 'old']],
        ['two', ['index.php' => '2', 'old.php' => null]],
    ]);
    $git = new Git($repo, Settings::gitBinary());
    $git->okRaw(['update-ref', 'refs/remotes/origin/main', 'HEAD']);
    $site = Tests::folder('site');
    file_put_contents("$site/index.php", '1');
    file_put_contents("$site/old.php", 'old');
    file_put_contents("$site/notes.txt", 'keep me');
    mkdir("$site/cache");
    file_put_contents("$site/cache/x.tmp", 'tmp');
    JsonStore::write('sources', ['repo' => ['key' => 'repo', 'name' => 'Repo', 'repo_path' => $repo, 'branch' => 'main', 'remote' => 'origin', 'extra_protected' => 'cache/']]);
    JsonStore::write('sites', ['stg' => ['key' => 'stg', 'name' => 'Staging', 'type' => 'folder', 'source' => 'local:repo', 'production' => $production, 'path' => $site, 'url' => '', 'check_path' => '']]);
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'background_runs' => '0']);
    $compare = ['id' => '20261118-100000-compare-stg-aaaa', 'type' => 'compare', 'site' => 'stg', 'status' => 'done', 'to' => $git->resolve('HEAD'),
        'result' => ['method' => 'fingerprint', 'same' => 0, 'differs' => ['index.php'], 'missing' => [], 'eol' => [], 'extra' => ['old.php', 'notes.txt', 'cache/x.tmp']]];
    return [$compare, $site];
}

/** Steps a run until it stops for the user or ends. */
function stepUntilWaiting(string $id): array
{
    for ($i = 0; $i < 50; $i++) {
        $run = Runner::step($id);
        if ($run['status'] !== 'running') {
            return $run;
        }
    }
    return $run;
}

test('delete extras: "Make it match" deletes only the ticked extra files, after a backup; Roll back restores them', function () {
    [$compare, $site] = extrasSetup();
    $run = CompareRun::createSync($compare, ['index.php'], false, ['old.php', 'cache/x.tmp']);
    $run = stepUntilWaiting($run['id']);
    check($run['status'] === 'review', 'stops at the review: ' . ($run['error'] ?? ''));
    $deletes = array_values(array_filter($run['files'], static fn (array $f): bool => $f['action'] === 'delete'));
    check(array_column($deletes, 'path') === ['old.php'] && array_column($run['skipped'], 'path') === ['cache/x.tmp'], 'old.php to delete; the protected cache file is skipped');
    check($deletes[0]['server'] === 'extra' && DeployTool\RunView::serverBadge($deletes[0])[0] === 'On server', 'shown as "On server" at the review');
    check(is_file("$site/old.php"), 'nothing deleted before approval');

    check(DeployRun::approve($run, ['approve_deletes' => '1', 'ack_warnings' => '1']) === null, 'approved');
    RunStore::save($run);
    $run = stepUntilWaiting($run['id']);
    check(in_array($run['status'], ['sql', 'done'], true), 'finished: ' . ($run['error'] ?? $run['status']));
    check(!is_file("$site/old.php") && file_get_contents("$site/index.php") === '2', 'old.php deleted, index.php uploaded');
    check(is_file("$site/notes.txt") && is_file("$site/cache/x.tmp"), 'unticked and protected files stay');
    check(Backup::get($run['id'], 'old.php') === 'old', 'the deleted file is in the backup');

    $run['status'] = 'done';
    RunStore::save($run);
    $rollback = stepUntilWaiting(RollbackRun::create($run)['id']);
    check(RollbackRun::approve($rollback, []) === null, 'rollback approved: ' . ($rollback['error'] ?? $rollback['status']));
    RunStore::save($rollback);
    stepUntilWaiting($rollback['id']);
    check(file_get_contents("$site/old.php") === 'old' && file_get_contents("$site/index.php") === '1', 'Roll back puts the deleted file back');
});

test('delete extras: unticking at the review keeps them; never on production, never files outside the list, off when switched off', function () {
    [$compare, $site] = extrasSetup();
    $run = stepUntilWaiting(CompareRun::createSync($compare, [], false, ['old.php'])['id']);
    DeployRun::approve($run, ['ack_warnings' => '1']);
    RunStore::save($run);
    stepUntilWaiting($run['id']);
    check(is_file("$site/old.php"), 'delete box unticked at the review: the file stays');

    $error = static function (callable $make): string {
        try {
            $make();
            return '(no error)';
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    };
    check(str_contains($error(static fn () => CompareRun::createSync($compare, ['index.php'], false, ['index.php'])), 'Extra on server'), 'a file not in the extra list is refused');
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'background_runs' => '0', 'feature_delete_extras' => '0']);
    check(str_contains($error(static fn () => CompareRun::createSync($compare, ['index.php'], false, ['notes.txt'])), 'switched off'), 'switched off: refused');

    [$compare] = extrasSetup(true);
    check(str_contains($error(static fn () => CompareRun::createSync($compare, ['index.php'], false, ['old.php'])), 'Production never deletes'), 'production: refused');
    $html = DeployTool\View::partial('compare_result', ['run' => $compare + ['recorded' => false, 'production' => true, 'to' => $compare['to']]]);
    check(!str_contains($html, 'name="delete[]"'), 'production: no delete boxes on the compare page');
});

test('delete extras: the compare page offers unticked delete boxes for extra files only', function () {
    [$compare] = extrasSetup();
    $html = DeployTool\View::partial('compare_result', ['run' => $compare + ['recorded' => false, 'production' => false]]);
    check(substr_count($html, 'name="delete[]"') === 3 && !preg_match('/name="delete\[\]"[^>]*checked/', $html), 'three extra files, none ticked');
    check(str_contains($html, 'name="files[]" value="index.php"') && str_contains($html, 'js-delete-count'), 'uploads keep their own boxes; a delete count is shown');
});
