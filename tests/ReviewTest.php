<?php

declare(strict_types=1);

use DeployTool\Ai\Explain;
use DeployTool\JsonStore;
use DeployTool\ReviewInsights;

/** A repo whose second commit adds debug code, a config change, a migration and table code. */
function reviewRepo(): string
{
    return Tests::repo([
        ['base', [
            'app/Controller.php' => "<?php\ndefined('BASEPATH') or exit('No direct script access allowed');\nclass C {}\n",
            'assets/app.js' => "let a = 1;\n",
            'application/config/config.php' => "<?php \$config = [];\n",
        ]],
        ['work', [
            'app/Controller.php' => "<?php\ndefined('BASEPATH') or exit('No direct script access allowed');\nclass C {\n  function a() {\n    var_dump(\$x);\n    \$s = print_r(\$y, true);\n    // var_dump(\$old);\n    die('stop');\n    \$this->db->query('ALTER TABLE tblx ADD y INT');\n    \$obj->dd(1);\n    dd(\$z);\n  }\n}\n",
            'assets/app.js' => "let a = 1;\nconsole.log(a);\n",
            'application/config/config.php' => "<?php \$config = ['x' => 1];\n",
            'application/migrations/101_add_x.php' => "<?php // migration\n",
            'assets/vendor.min.js' => "console.log(1);",
        ]],
    ]);
}

test('review: debug code in added lines is flagged with file and line; comments and safe calls are not', function () {
    $run = Tests::deployRun(reviewRepo(), 'HEAD~1', 'HEAD');
    $found = ReviewInsights::debugCode($run);
    $whats = array_map(static fn (array $hits): array => array_column($hits, 1), $found);
    check(($whats['app/Controller.php'] ?? []) === ['var_dump()', 'die()', 'dd()'], 'Controller: var_dump, die, dd (not print_r with true, the comment, ->dd or the BASEPATH exit): ' . json_encode($whats));
    check($found['app/Controller.php'][0][0] === 5, 'var_dump is on line 5');
    check(($whats['assets/app.js'] ?? []) === ['console.log()'], 'app.js: console.log');
    check(!isset($found['assets/vendor.min.js']), 'minified files are skipped');
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'feature_debug_flags' => '0']);
    check(ReviewInsights::debugCode($run) === [], 'off: nothing flagged');
});

test('review: risky files, database changes and line counts', function () {
    $run = Tests::deployRun(reviewRepo(), 'HEAD~1', 'HEAD');
    $paths = array_column($run['files'], 'path');
    check(ReviewInsights::riskyFiles($paths) === ['application/config/config.php' => 'configuration'], 'the config file is risky');
    $db = ReviewInsights::databaseChangesOf($run);
    check(isset($db['application/migrations/101_add_x.php'], $db['app/Controller.php']), 'the migration and the ALTER TABLE in code are database changes: ' . json_encode($db));
    $stats = ReviewInsights::lineStats($run);
    check($stats['assets/app.js'] === [1, 0], 'app.js: one line added');
});

test('review: removing table code is not a database change', function () {
    $repo = Tests::repo([
        ['with', ['m.php' => "<?php\n\$db->query('ALTER TABLE t ADD c INT');\n\$a = 1;\n"]],
        ['without', ['m.php' => "<?php\n\$a = 2;\n"]],
        ['again', ['m.php' => "<?php\n\$a = 2;\n\$db->query('CREATE TABLE u (id INT)');\n"]],
    ]);
    $git = new DeployTool\Git($repo, DeployTool\Settings::gitBinary());
    check(ReviewInsights::databaseChanges($git, $git->resolve('HEAD~2'), $git->resolve('HEAD~1'), ['m.php']) === [], 'a deploy that only removes an ALTER TABLE: nothing');
    check(isset(ReviewInsights::databaseChanges($git, $git->resolve('HEAD~1'), $git->resolve('HEAD'), ['m.php'])['m.php']), 'adding a CREATE TABLE is flagged');
});

test('explain: sends the facts and diffs to the AI provider and returns its answer', function () {
    $fake = Tests::folder('fakeai');
    file_put_contents("$fake/router.php", <<<'PHP'
<?php
file_put_contents(__DIR__ . '/request.json', file_get_contents('php://input'));
header('Content-Type: application/json');
echo json_encode(['model' => 'fake-model', 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1], 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => "**What changes**\n- Adds debugging"]]]]);
PHP);
    $url = Tests::serve($fake, "$fake/router.php");
    JsonStore::write('ai', ['enabled' => '1', 'provider' => 'custom', 'base_url' => "$url/v1", 'model' => 'fake-model']);
    check(Explain::available(), 'available with the assistant on');
    $run = Tests::deployRun(reviewRepo(), 'HEAD~1', 'HEAD');
    $answer = Explain::run($run);
    check($answer['text'] === "**What changes**\n- Adds debugging" && $answer['model'] === 'fake-model', 'the answer and model come back');
    $sent = json_decode((string) file_get_contents("$fake/request.json"), true)['messages'][1]['content'] ?? '';
    check(str_contains($sent, 'app/Controller.php') && str_contains($sent, 'line 5 var_dump()') && str_contains($sent, '--- Diff of app/Controller.php'), 'the facts name the file, the debug line and include its diff');
    check(str_contains($sent, 'application/migrations/101_add_x.php (migration)'), 'and the database change');
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'feature_explain' => '0']);
    check(!Explain::available(), 'off: not available');
});
