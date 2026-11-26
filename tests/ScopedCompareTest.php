<?php

declare(strict_types=1);

use DeployTool\CompareRun;
use DeployTool\DeployRun;
use DeployTool\Fingerprint;
use DeployTool\Git;
use DeployTool\JsonStore;
use DeployTool\Partials;
use DeployTool\RollbackRun;
use DeployTool\Runner;
use DeployTool\RunStore;
use DeployTool\Settings;
use DeployTool\Sites;

/**
 * Speed-up "Compare only some commits". A repo of three commits, A (base), B "hotfix" (a.php,
 * deletes gone.php) and C "feature" (b.php, and a.php again), and a folder site on A.
 *
 * @return array{0: string, 1: array<string, string>} the site folder and the commits by letter
 */
function scopedSetup(bool $recorded = true, array $settings = []): array
{
    $repo = Tests::repo([
        ['base', ['index.php' => '1', 'a.php' => 'a1', 'b.php' => 'b1', 'gone.php' => 'g']],
        ['hotfix', ['a.php' => 'a2', 'gone.php' => null]],
        ['feature', ['b.php' => 'b2', 'a.php' => 'a3']],
    ]);
    $git = new Git($repo, Settings::gitBinary());
    $git->okRaw(['update-ref', 'refs/remotes/origin/main', 'HEAD']);
    $shas = ['A' => $git->resolve('HEAD~2'), 'B' => $git->resolve('HEAD~1'), 'C' => $git->resolve('HEAD')];
    $site = Tests::folder('site');
    foreach (['index.php' => '1', 'a.php' => 'a1', 'b.php' => 'b1', 'gone.php' => 'g', 'notes.txt' => 'server only'] as $path => $contents) {
        file_put_contents("$site/$path", $contents);
    }
    if ($recorded) {
        @mkdir("$site/application/.deploy", 0777, true);
        file_put_contents("$site/application/.deploy/state.json", json_encode(['commit' => $shas['A']]));
    }
    JsonStore::write('sources', ['shop' => ['key' => 'shop', 'name' => 'Shop', 'repo_path' => $repo, 'branch' => 'main', 'remote' => 'origin', 'extra_protected' => '']]);
    JsonStore::write('sites', ['stg' => ['key' => 'stg', 'name' => 'Staging', 'type' => 'folder', 'source' => 'local:shop', 'production' => false, 'path' => $site, 'url' => '', 'check_path' => '']]);
    JsonStore::write('settings', $settings + ['deployer_name' => 'Tester', 'background_runs' => '0']);
    return [$site, $shas];
}

function scopedStep(string $id): array
{
    for ($i = 0; $i < 50; $i++) {
        $run = Runner::step($id);
        if ($run['status'] !== 'running') {
            return $run;
        }
    }
    return $run;
}

function scopedError(callable $make): string
{
    try {
        $make();
        return '(no error)';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
}

/** A finished compare of only $letters' commits. */
function scopedCompare(array $shas, array $letters): array
{
    $picked = array_map(static fn (string $letter): string => $shas[$letter], $letters);
    $run = scopedStep(CompareRun::createScoped(Sites::get('stg'), $picked)['id']);
    check($run['status'] === 'done', 'the compare finishes: ' . ($run['error'] ?? $run['status']));
    return $run;
}

/** Runs a deploy or match through the review to the end. */
function scopedFinish(array $run): array
{
    $run = scopedStep($run['id']);
    check($run['status'] === 'review', 'stops at the review: ' . ($run['error'] ?? $run['status']));
    check(DeployRun::approve($run, ['approve_deletes' => '1', 'ack_warnings' => '1']) === null, 'approved');
    RunStore::save($run);
    $run = scopedStep($run['id']);
    check(in_array($run['status'], ['sql', 'done'], true), 'finished: ' . ($run['error'] ?? $run['status']));
    $run['status'] = 'done'; // past any SQL step
    RunStore::save($run);
    return $run;
}

function scopedState(string $site): ?string
{
    return json_decode((string) @file_get_contents("$site/application/.deploy/state.json"), true)['commit'] ?? null;
}

test('compare only some commits: reads just their files, compares with the newest ticked one', function () {
    [, $shas] = scopedSetup();
    $run = scopedCompare($shas, ['B']);
    check($run['to'] === $shas['B'] && $run['scope']['paths'] === ['a.php', 'gone.php'], 'compared with B, only a.php and gone.php');
    check($run['scope']['read'] === 2, 'only those files were read from the server');
    $result = $run['result'];
    check($result['differs'] === ['a.php'] && $result['missing'] === [] && $result['extra'] === ['gone.php'], 'a.php differs; gone.php, which B deleted, is extra; notes.txt is not looked at');
    check($result['shared'] === ['known' => true, 'paths' => [], 'commits' => []], 'no other commit touched these files');
    check(array_keys(CompareRun::phases($run)) === ['scoped', 'analyze'], 'two steps');

    $both = scopedCompare($shas, ['C', 'B']);
    check($both['to'] === $shas['C'] && $both['scope']['commits'] === [$shas['C'], $shas['B']], 'two commits: newest first, compared with C');
    check($both['result']['differs'] === ['a.php', 'b.php'] && $both['result']['shared']['paths'] === [], 'a.php and b.php differ; nothing shared');

    $html = DeployTool\View::partial('compare_result', ['run' => $run]);
    check(str_contains($html, 'Only 2 files compared') && str_contains($html, 'Removed by these commits'), 'the page says only some files were compared');
    check(!str_contains($html, 'compare-record') && !str_contains($html, 'ack_shared'), 'no "Record version"; no shared box');
    check((bool) preg_match('/name="delete\[\]" value="gone.php"[^>]*checked/', $html), 'the file the commit deleted starts ticked to delete');
});

test('compare only some commits: files other commits also changed are warned about, and need a tick', function () {
    [, $shas] = scopedSetup();
    $run = scopedCompare($shas, ['C']);
    check(array_keys($run['result']['shared']['paths']) === ['a.php'] && $run['result']['shared']['paths']['a.php'] === [$shas['B']], 'a.php also has B\'s change');
    check($run['result']['shared']['commits'] === [$shas['B'] => 'hotfix'], 'B is named');

    $html = DeployTool\View::partial('compare_result', ['run' => $run]);
    check(str_contains($html, 'Include that commit too') && str_contains($html, 'value="' . $shas['B'] . '"') && str_contains($html, 'name="ack_shared"'), 'warning, "Include that commit too" and the box');

    check(str_contains(scopedError(static fn () => CompareRun::createSync($run, null)), 'Tick the box'), 'refused without the tick');
    check(str_contains(scopedError(static function () use ($run) { CompareRun::recordVersion($run, true); }), 'only some commits'), 'its version can never be recorded');
    $sync = CompareRun::createSync($run, null, false, [], true);
    check($sync['partial'] === ['commits' => [$shas['C']]], 'with the tick: a partial match');
    $sync['status'] = 'cancelled';
    RunStore::save($sync);

    [, $shas] = scopedSetup(false);
    $unknown = scopedCompare($shas, ['C']);
    check($unknown['result']['shared'] === ['known' => false], 'no recorded version: unknown');
    check(str_contains(DeployTool\View::partial('compare_result', ['run' => $unknown]), 'can\'t tell whether other commits'), 'and the page says so');
});

test('compare only some commits: a partial match keeps the recorded version; the next deploy knows its files', function () {
    [$site, $shas] = scopedSetup();
    $compare = scopedCompare($shas, ['B']);
    $sync = scopedFinish(CompareRun::createSync($compare, ['a.php'], false, ['gone.php']));
    check(file_get_contents("$site/a.php") === 'a2' && !is_file("$site/gone.php") && file_get_contents("$site/b.php") === 'b1', 'B\'s files are on the server, nothing else');
    check(scopedState($site) === $shas['A'], 'the recorded version is still A');
    $partials = Partials::of('stg');
    check(array_keys($partials) === ['a.php', 'gone.php'] && $partials['a.php'][0] === Fingerprint::blobSha('a2') && $partials['gone.php'][0] === null, 'what it sent is remembered');

    $deploy = scopedStep(DeployRun::create(Sites::get('stg'), 'deploy')['id']);
    check($deploy['status'] === 'review', 'the next deploy reaches its review: ' . ($deploy['error'] ?? ''));
    $states = array_column($deploy['files'], 'server', 'path');
    check($states['a.php'] === 'partial' && $states['gone.php'] === 'partial' && $states['b.php'] === 'match', 'a.php and gone.php: "Sent by a partial match", not "Edited on server"');
    check(DeployTool\RunView::serverBadge(['server' => 'partial'] + $deploy['files'][0])[0] === 'Sent by a partial match', 'with its own label');
    $deploy = scopedFinish($deploy);
    check(scopedState($site) === $shas['C'] && file_get_contents("$site/a.php") === 'a3', 'the full deploy brings the rest and records C');
    check(Partials::of('stg') === [], 'and starts afresh');

    // Someone edits a sent file afterwards: that's still a warning.
    [$site, $shas] = scopedSetup();
    scopedFinish(CompareRun::createSync(scopedCompare($shas, ['B']), ['a.php']));
    file_put_contents("$site/a.php", 'edited by hand');
    $deploy = scopedStep(DeployRun::create(Sites::get('stg'), 'deploy')['id']);
    check(array_column($deploy['files'], 'server', 'path')['a.php'] === 'edited', 'a file changed after the partial match is "Edited on server" again');
});

test('compare only some commits: rolling back a partial match forgets it; switched off it is refused', function () {
    [$site, $shas] = scopedSetup();
    $sync = scopedFinish(CompareRun::createSync(scopedCompare($shas, ['B']), ['a.php']));
    $sync['status'] = 'done';
    RunStore::save($sync);
    $rollback = scopedStep(RollbackRun::create($sync)['id']);
    check(RollbackRun::approve($rollback, []) === null, 'rollback approved: ' . ($rollback['error'] ?? $rollback['status']));
    RunStore::save($rollback);
    scopedStep($rollback['id']);
    check(file_get_contents("$site/a.php") === 'a1' && Partials::of('stg') === [], 'a.php is back and the partial is forgotten');
    check(scopedState($site) === $shas['A'], 'the recorded version never moved');

    [, $shas] = scopedSetup(true, ['feature_commit_compare' => '0']);
    check(str_contains(scopedError(static fn () => CompareRun::createScoped(Sites::get('stg'), [$shas['B']])), 'switched off'), 'switched off: refused');
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'background_runs' => '0']);
    check(str_contains(scopedError(static fn () => CompareRun::createScoped(Sites::get('stg'), [])), 'Tick at least one'), 'no commit ticked: refused');
    check(str_contains(scopedError(static fn () => CompareRun::createScoped(Sites::get('stg'), ['0123456789abcdef0123456789abcdef01234567'])), 'not in your repository'), 'an unknown commit: refused');
});

/** Runs one assistant action for the chat's local, as the chat would: the decoded result, or ['error' => …]. */
function scopedTool(string $name, array $arguments): array
{
    $outcome = DeployTool\Ai\Tools::run($name, $arguments, 'shop');
    return (array) json_decode($outcome['result'], true);
}

test('compare only some commits: the assistant lists the missing commits and compares just some', function () {
    [, $shas] = scopedSetup();
    JsonStore::write('ai', ['enabled' => '1', 'provider' => 'custom', 'base_url' => 'http://127.0.0.1:9/v1', 'model' => 'none']);
    $tools = array_column(DeployTool\Ai\Tools::allowedDefinitions(), null, 'name');
    check(isset($tools['commits_not_on_site'], $tools['start_compare']['parameters']['properties']['commits']), 'the actions are offered');

    $list = scopedTool('commits_not_on_site', ['site' => 'stg']);
    check($list['version_known'] === true && $list['site_version'] === short_sha($shas['A']), 'reads the site\'s version: ' . json_encode($list));
    check(array_column($list['commits'], 'subject') === ['feature', 'hotfix'] && in_array('D gone.php', $list['commits'][1]['files'], true), 'the two commits it lacks, newest first, with their files');

    $started = scopedTool('start_compare', ['site' => 'stg', 'commits' => [short_sha($shas['B'])]]);
    $run = scopedStep((string) $started['run_id']);
    check(($run['scope']['commits'] ?? null) === [$shas['B']], 'a compare of only B: ' . json_encode($started));
    $details = scopedTool('get_run', ['run_id' => $run['id']]);
    check($details['only_commits'] === [short_sha($shas['B'])] && $details['files_compared'] === 2 && $details['shared_with_other_commits'] === [], 'get_run tells it is partial');
    check(isset(scopedTool('record_version', ['compare_run_id' => $run['id'], 'accept_differences' => true])['error']), 'record_version is refused');

    $chat = DeployTool\Ai\ChatStore::create('shop');
    DeployTool\Ai\QuickCommands::handle($chat, '/compare stg ' . short_sha($shas['C']) . ' ' . short_sha($shas['B']));
    $answer = (string) end($chat['messages'])['content'];
    check(str_contains($answer, 'Compare of 2 commit(s) started on **Staging**'), '/compare with commit ids: ' . $answer);

    JsonStore::write('settings', ['deployer_name' => 'Tester', 'background_runs' => '0', 'feature_commit_compare' => '0']);
    $tools = array_column(DeployTool\Ai\Tools::allowedDefinitions(), null, 'name');
    check(!isset($tools['commits_not_on_site']) && !isset($tools['start_compare']['parameters']['properties']['commits']), 'switched off: not offered');
    check(str_contains((string) (scopedTool('start_compare', ['site' => 'stg', 'commits' => [$shas['B']]])['error'] ?? ''), 'switched off'), 'and refused');
});

test('compare only some commits: the assistant must ask before sending files other commits also changed', function () {
    [$site, $shas] = scopedSetup();
    JsonStore::write('ai', ['enabled' => '1', 'provider' => 'custom', 'base_url' => 'http://127.0.0.1:9/v1', 'model' => 'none']);
    $compare = scopedCompare($shas, ['C']);
    check(scopedTool('get_run', ['run_id' => $compare['id']])['shared_with_other_commits'] === ['a.php (also changed by ' . short_sha($shas['B']) . ' "hotfix")'], 'get_run names the shared file and commit');

    $refused = (string) (scopedTool('prepare_match', ['compare_run_id' => $compare['id']])['error'] ?? '');
    check(str_contains($refused, 'a.php') && str_contains($refused, 'hotfix') && str_contains($refused, 'shared_files_checked'), 'refused, naming the file and commit: ' . $refused);
    $only = scopedTool('prepare_match', ['compare_run_id' => $compare['id'], 'paths' => ['b.php']]);
    check(isset($only['run_id']), 'b.php alone needs no yes: ' . json_encode($only));
    $run = RunStore::load($only['run_id']);
    check($run['partial'] === ['commits' => [$shas['C']]] && array_column($run['files'], 'path') === ['b.php'], 'a partial match of b.php');
    $run['status'] = 'cancelled';
    RunStore::save($run);
    $both = scopedTool('prepare_match', ['compare_run_id' => $compare['id'], 'shared_files_checked' => true]);
    check(isset($both['run_id']) && array_column(RunStore::load($both['run_id'])['files'], 'path') === ['a.php', 'b.php'], 'after the user\'s yes: both');

    // A file other commits also changed that is already the same on the server doesn't count.
    [$site, $shas] = scopedSetup();
    file_put_contents("$site/a.php", 'a3');
    check(scopedCompare($shas, ['C'])['result']['shared']['paths'] === [], 'a.php already the same: nothing shared');
});

test('compare only some commits: the files outside the picked commits are listed from git', function () {
    [, $shas] = scopedSetup();
    $run = scopedCompare($shas, ['B']);
    $outside = $run['result']['outside'];
    check($outside['known'] && $outside['head'] === $shas['C'], 'up to the newest commit the site gets code from (C), not just the picked B');
    check(count($outside['commits']) === 1 && $outside['commits'][0]['sha'] === $shas['C'] && $outside['commits'][0]['files'] === [['M', 'b.php']], 'C is listed with b.php only: its a.php was compared');
    check($outside['files'] === 1 && $outside['total'] === 1, 'one file from one commit');

    $html = DeployTool\View::partial('compare_result', ['run' => $run]);
    check(str_contains($html, 'Not in your selection') && str_contains($html, 'up to <code>' . short_sha($shas['C']) . '</code>'), 'the page lists them, up to C');
    check((bool) preg_match('/type="checkbox" name="commits\[\]" value="' . $shas['C'] . '"/', $html) && (bool) preg_match('/type="hidden" name="commits\[\]" value="' . $shas['B'] . '"/', $html), 'C can be ticked; B stays picked');

    $details = scopedTool('get_run', ['run_id' => $run['id']]);
    check(($details['not_in_selection']['commits'][0]['files'] ?? null) === ['M b.php'] && $details['not_in_selection']['file_count'] === 1, 'the assistant sees it: ' . json_encode($details['not_in_selection'] ?? null));

    $c = scopedCompare($shas, ['C']);
    check($c['result']['outside']['commits'][0]['sha'] === $shas['B'] && $c['result']['outside']['commits'][0]['files'] === [['D', 'gone.php']], 'picking C: B\'s deleted gone.php is outside');

    $all = scopedCompare($shas, ['B', 'C']);
    check($all['result']['outside']['commits'] === [] && $all['result']['outside']['files'] === 0, 'both picked: nothing outside');
    check(str_contains(DeployTool\View::partial('compare_result', ['run' => $all]), 'Nothing outside your selection'), 'and the page says so');
});

test('compare only some commits: files outside the selection, unknown version and switched off', function () {
    [, $shas] = scopedSetup(false);
    $run = scopedCompare($shas, ['B']);
    check($run['result']['outside'] === ['known' => false], 'no recorded version: unknown');
    $html = DeployTool\View::partial('compare_result', ['run' => $run]);
    check(str_contains($html, 'which other commits it still lacks') && !str_contains($html, 'Not in your selection'), 'the page says it can\'t tell');
    check(is_string(scopedTool('get_run', ['run_id' => $run['id']])['not_in_selection'] ?? null), 'the assistant is told it is unknown');

    [, $shas] = scopedSetup(true, ['feature_outside_selection' => '0']);
    $off = scopedCompare($shas, ['B']);
    check(!isset($off['result']['outside']), 'switched off: not worked out');
    check(!str_contains(DeployTool\View::partial('compare_result', ['run' => $off]), 'Not in your selection'), 'nor shown');
    check(!isset(scopedTool('get_run', ['run_id' => $off['id']])['not_in_selection']), 'nor given to the assistant');
});
