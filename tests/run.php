<?php
// The deploy tool's automatic tests:   php tests/run.php   (add a word to run only the tests
// whose name contains it, e.g. php tests/run.php bundle). Uses its own throwaway data folder,
// key and local test servers: it never touches your real data and needs no internet.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

ob_start(); // see Tests::say()
$work = sys_get_temp_dir() . '/dt-tests-' . getmypid();
@mkdir($work . '/storage', 0700, true);
putenv('DEPLOY_TOOL_STORAGE=' . $work . '/storage');
putenv('DEPLOY_TOOL_KEY_FILE=' . $work . '/secret.key');
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support.php';
session_save_path($work);
ini_set('session.use_cookies', '0'); // no cookie headers in a terminal
ini_set('session.use_only_cookies', '0');
session_start(); // the sign-in tests use the session, as in a browser

Tests::$work = $work;
foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}
exit(Tests::run($argv[1] ?? ''));
