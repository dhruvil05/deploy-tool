<?php

declare(strict_types=1);

use DeployTool\CommitFiles;
use DeployTool\Git;
use DeployTool\Settings;

/** A clone of $origin with a local identity, as a local of the tool sees it. */
function cloneOf(string $origin): array
{
    $dir = Tests::folder('clone');
    (new Git(dirname($dir), Settings::gitBinary()))->okRaw(['clone', '-q', $origin, $dir]);
    $git = new Git($dir, Settings::gitBinary());
    $git->okRaw(['config', 'user.email', 'tests@example.invalid']);
    $git->okRaw(['config', 'user.name', 'Tests']);
    $git->okRaw(['config', 'core.autocrlf', 'false']);
    return [['key' => 'clone', 'name' => 'Clone', 'repo_path' => $dir, 'branch' => 'main', 'remote' => 'origin', 'extra_protected' => ''], $git];
}

function commitIn(string $dir, string $message, array $files): void
{
    foreach ($files as $path => $contents) {
        @mkdir(dirname("$dir/$path"), 0777, true);
        file_put_contents("$dir/$path", $contents);
    }
    $git = new Git($dir, Settings::gitBinary());
    $git->okRaw(['add', '-A']);
    $git->okRaw(['commit', '-q', '-m', $message]);
}

test('commit files: each commit between two versions with its own files; otherwise the target alone', function () {
    $repo = Tests::repo([
        ['first', ['index.php' => '1', 'app/a.php' => 'a']],
        ['second', ['app/a.php' => 'a2', 'app/b.php' => 'b']],
        ['third', ['index.php' => null, 'assets/x.js' => 'x']],
    ]);
    $git = new Git($repo, Settings::gitBinary());
    [$first, $second, $third] = [$git->resolve('HEAD~2'), $git->resolve('HEAD~1'), $git->resolve('HEAD')];

    $range = CommitFiles::between($git, $first, $third);
    check(array_column($range['commits'], 'subject') === ['third', 'second'] && $range['total'] === 2 && !$range['only_target'], 'two commits, newest first');
    check($range['commits'][0]['files'] === [['A', 'assets/x.js'], ['D', 'index.php']], 'third: its own files only, with new and removed');
    check($range['commits'][1]['files'] === [['M', 'app/a.php'], ['A', 'app/b.php']], 'second: its own files only');

    $alone = CommitFiles::between($git, null, $second);
    check($alone['only_target'] && count($alone['commits']) === 1 && $alone['commits'][0]['sha'] === $second, 'no version before: the target commit alone');
    check(CommitFiles::between($git, $third, $first)['only_target'], 'a "before" that is newer (not an ancestor): the target alone');
    check(CommitFiles::between($git, null, $first)['commits'][0]['files'] === [['A', 'app/a.php'], ['A', 'index.php']], "the repository's first commit lists all its files");

    $html = DeployTool\View::partial('commit_files', ['source' => 'repo', 'commitData' => $range, 'fromGiven' => true]);
    check(substr_count($html, 'class="commit-block"') === 2 && str_contains($html, 'x.js') && str_contains($html, '>removed<') && str_contains($html, 'r=commit-diff'), 'the page part shows each commit with its tree');

    $diff = CommitFiles::diff($git, $second, 'app/a.php');
    check($diff['added'] === 1 && $diff['removed'] === 1 && !$diff['binary'], 'a file\'s changes in one commit');
    check(CommitFiles::diff($git, $first, 'index.php')['added'] === 1, 'a file of the first commit');
});

test('pull: fast-forwards only; refuses uncommitted work and diverged branches', function () {
    $origin = Tests::repo([['one', ['index.php' => '1']]]);
    [$source, $git] = cloneOf($origin);
    check(str_starts_with(CommitFiles::pull($source), 'Already up to date'), 'nothing new: says so');

    commitIn($origin, 'two', ['index.php' => '2', 'new.php' => 'n']);
    $message = CommitFiles::pull($source);
    check(str_starts_with($message, 'Pulled 1 commit(s)') && file_get_contents($source['repo_path'] . '/index.php') === '2', 'pulled: ' . $message);

    commitIn($origin, 'three', ['index.php' => '3']);
    file_put_contents($source['repo_path'] . '/index.php', 'my work');
    check(str_contains(pullError($source), 'uncommitted changes') && file_get_contents($source['repo_path'] . '/index.php') === 'my work', 'uncommitted work: refused, untouched');
    $git->okRaw(['checkout', '-q', '--', 'index.php']);

    commitIn($source['repo_path'], 'mine', ['mine.php' => 'm']);
    $mine = $git->resolve('HEAD');
    check(str_contains(pullError($source), 'both have new commits') && $git->resolve('HEAD') === $mine && file_get_contents($source['repo_path'] . '/index.php') === '2', 'diverged: refused, nothing changed');
});

test('pull: stops before any file changes when GitHub\'s commit has malware; runs no git hooks', function () {
    $origin = Tests::repo([['one', ['index.php' => '1', 'package.json' => '{"scripts":{"dev":"mix"}}']]]);
    [$source, $git] = cloneOf($origin);
    $hook = $source['repo_path'] . '/.git/hooks/post-merge';
    file_put_contents($hook, "#!/bin/sh\necho ran > hook-ran.txt\n");
    @chmod($hook, 0755);

    commitIn($origin, 'clean', ['index.php' => '2']);
    CommitFiles::pull($source);
    check(file_get_contents($source['repo_path'] . '/index.php') === '2' && !is_file($source['repo_path'] . '/hook-ran.txt'), 'pulled, and the post-merge hook did not run');

    $before = $git->resolve('HEAD');
    commitIn($origin, 'infected', ['package.json' => '{"scripts":{"dev":"node init && mix"}}', 'init' => "var a=1;\n", 'index.php' => '3']);
    $error = pullError($source);
    check(str_contains($error, 'malware markers') && str_contains($error, 'hidden script'), 'stopped with what was found: ' . $error);
    check($git->resolve('HEAD') === $before && file_get_contents($source['repo_path'] . '/index.php') === '2' && !is_file($source['repo_path'] . '/init'), 'nothing changed in the folder');
});

function pullError(array $source): string
{
    try {
        CommitFiles::pull($source);
        return '(no error)';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
}
