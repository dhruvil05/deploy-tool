<?php

declare(strict_types=1);

use DeployTool\DiffView;
use DeployTool\Git;
use DeployTool\JsonStore;
use DeployTool\RunController;
use DeployTool\Settings;

test('three-way: finds the server hotfix lines an upload would lose', function () {
    $git = new Git(Tests::folder('any'), Settings::gitBinary());
    $expected = "<?php\n\$a = 1;\n\$b = 2;\n";
    $server = "<?php\n\$a = 1;\nif (\$x) { fix(); } // hotfix\n\$b = 2;\n\n";
    $loses = DiffView::lostServerLines($git, $expected, $server, "<?php\n\$a = 1;\n\$b = 3;\n");
    check($loses['added'] === 1 && $loses['lost'] === [3 => 'if ($x) { fix(); } // hotfix'], 'the hotfix line (line 3 on the server) is lost: ' . json_encode($loses));
    $keeps = DiffView::lostServerLines($git, $expected, $server, "<?php\n\$a = 1;\n  if (\$x) { fix(); } // hotfix\n\$b = 3;\n");
    check($keeps['lost'] === [] && $keeps['added'] === 1, 'an upload that already has the line (other indent) loses nothing');
    check(DiffView::lostServerLines($git, $expected, "<?php\n\$a = 1;\n", "x")['added'] === 0, 'a server edit that only removed lines adds nothing');
    check(DiffView::lostServerLines($git, $expected, "<?php\r\n\$a = 1;\r\nnew();\r\n", "<?php\nnew();\n")['lost'] === [], 'Windows line endings on the server are handled');
});

test('three-way: offered only for deploy files edited on the server, and only when switched on', function () {
    $run = ['type' => 'deploy', 'from' => str_repeat('a', 40)];
    check(RunController::hasThreeWay($run, ['server' => 'edited']), 'on: an edited file gets it');
    check(!RunController::hasThreeWay($run, ['server' => 'match']), 'a file that matches does not');
    check(!RunController::hasThreeWay(['type' => 'sync', 'from' => null], ['server' => 'edited']), 'a match (no commit before) does not');
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'feature_three_way' => '0']);
    check(!RunController::hasThreeWay($run, ['server' => 'edited']), 'off: nobody gets it');
});
