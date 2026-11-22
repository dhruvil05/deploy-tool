<?php

declare(strict_types=1);

use DeployTool\CommitFiles;
use DeployTool\Git;
use DeployTool\JsonStore;
use DeployTool\LocalStatus;
use DeployTool\Safety;
use DeployTool\Sites;
use DeployTool\SiteStatus;
use DeployTool\Sources;

/** Git calls made by $work (each costs ~0.1 s on Windows). */
function gitCalls(callable $work): int
{
    Git::$calls = 0;
    $work();
    return Git::$calls;
}

/** A clone 1 commit ahead of and 2 behind its origin, with a folder site recorded on an older commit. */
function perfSetup(): array
{
    $origin = Tests::repo([['one', ['index.php' => '1']], ['two', ['index.php' => '2']]]);
    [$source, $git] = cloneOf($origin);
    commitIn($origin, 'three', ['a.php' => 'a']);
    commitIn($origin, 'four', ['b.php' => 'b']);
    commitIn($source['repo_path'], 'mine', ['m.php' => 'm']);
    $git->fetch('origin');
    JsonStore::write('sources', ['clone' => $source]);
    $site = Tests::folder('site');
    @mkdir("$site/application/.deploy", 0777, true);
    file_put_contents("$site/application/.deploy/state.json", json_encode(['commit' => $git->resolve('HEAD~2')]));
    JsonStore::write('sites', ['stg' => ['key' => 'stg', 'name' => 'Staging', 'type' => 'folder', 'source' => 'local:clone', 'production' => false, 'path' => $site, 'url' => '', 'check_path' => '']]);
    JsonStore::write('settings', ['deployer_name' => 'Tester']);
    return [$source, $git];
}

test('performance: the dashboard\'s local status takes 5 git calls once commits were checked for malware; same results', function () {
    [$source, $git] = perfSetup();
    $first = LocalStatus::collect(Sources::get('clone'));
    check($first['ahead'] === 1 && $first['behind'] === 2 && $first['commit']['subject'] === 'mine' && $first['error'] === null, 'ahead 1, behind 2, the right commit: ' . json_encode([$first['ahead'], $first['behind']]));
    $calls = gitCalls(static fn () => LocalStatus::collect(Sources::get('clone')));
    check($calls <= 5, "5 git calls or fewer after the first visit (was 22): $calls");

    file_put_contents($source['repo_path'] . '/index.php', 'edited');
    $dirty = LocalStatus::collect(Sources::get('clone'));
    check(str_contains(implode(' ', $dirty['warnings']), '1 file(s) have uncommitted changes'), 'uncommitted changes still found');
    $missing = LocalStatus::collect(['branch' => 'nope'] + Sources::get('clone'));
    check($missing['error'] === 'Branch nope was not found in the repository.', 'a missing branch still says so');
    $notRepo = LocalStatus::collect(['repo_path' => Tests::folder('empty')] + Sources::get('clone'));
    check($notRepo['error'] === 'The folder of this local is not a git repository.', 'a folder that is not a repository still says so');
});

test('performance: malware results are kept per commit; site status and commit lists use 2 git calls', function () {
    [$source, $git] = perfSetup();
    $sha = $git->resolve('HEAD');
    $findings = Safety::malwareFindings($git, $sha);
    check(gitCalls(static fn () => Safety::malwareFindings($git, $sha)) === 0 && Safety::malwareFindings($git, $sha) === $findings, 'the second check of the same commit makes no git call');

    $sourceCommit = $git->resolve('origin/main');
    $status = SiteStatus::check(Sites::get('stg'), $sourceCommit);
    check($status['behind'] === 3 && !$status['diverged'] && $status['commit']['subject'] === 'one', 'the site (on "one") is 3 behind GitHub\'s main: ' . json_encode([$status['behind'], $status['diverged']]));
    check(gitCalls(static fn () => SiteStatus::check(Sites::get('stg'), $sourceCommit)) === 2, 'site status: 2 git calls (was 4)');
    check(SiteStatus::check(Sites::get('stg'), $git->resolve('HEAD'))['behind'] === 2, 'and 2 behind the local branch (two, mine)');
    file_put_contents(Sites::get('stg')['path'] . '/application/.deploy/state.json', json_encode(['commit' => $git->resolve('HEAD')]));
    check(SiteStatus::check(Sites::get('stg'), $sourceCommit)['diverged'] === true, 'a site on a commit GitHub lacks is "diverged", as before');

    [$from, $to] = [$git->resolve('HEAD~2'), $git->resolve('origin/main')];
    $calls = gitCalls(static fn () => CommitFiles::between($git, $from, $to));
    check($calls === 2, "commit files for any number of commits: 2 git calls (was 2 per commit): $calls");
    unset($source);
});
