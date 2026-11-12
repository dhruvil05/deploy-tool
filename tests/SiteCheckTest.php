<?php

declare(strict_types=1);

use DeployTool\JsonStore;
use DeployTool\SiteCheck;

/** A test site whose pages change when "after" exists: one slows down, one breaks. */
function siteForChecks(): array
{
    $root = Tests::folder('checksite');
    file_put_contents("$root/router.php", <<<'PHP'
<?php
$after = is_file(__DIR__ . '/after');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/ok') { echo 'fine'; return; }
if ($path === '/slow') { if ($after) { usleep(1500000); } echo 'fine'; return; }
if ($path === '/breaks') { echo $after ? '<b>Fatal error</b>: Uncaught Error' : 'fine'; return; }
http_response_code(404);
PHP);
    return [$root, Tests::serve($root, "$root/router.php")];
}

test('site check: several pages, measured before and after; slower and broken pages named', function () {
    [$root, $url] = siteForChecks();
    $site = ['url' => $url, 'check_path' => '/ok, /slow,/breaks'];
    check(SiteCheck::paths($site) === ['/ok', '/slow', '/breaks'], 'pages split on commas and spaces');
    $before = SiteCheck::measure($site);
    check(count($before) === 3 && $before['/ok'][0] && $before['/breaks'][1] === 200, 'all three measured before: ' . json_encode($before));
    touch("$root/after");
    $result = SiteCheck::run($site, $before);
    $pages = array_column($result['pages'], null, 'path');
    check(!$result['ok'], 'the check fails because a page broke');
    check($pages['/ok']['ok'] && !isset($pages['/ok']['note']), '/ok is fine and unremarked');
    check($pages['/slow']['ok'] && str_contains($pages['/slow']['note'] ?? '', 'got slower'), '/slow is named as slower: ' . ($pages['/slow']['note'] ?? 'no note'));
    check(!$pages['/breaks']['ok'] && ($pages['/breaks']['note'] ?? '') === 'worked before the deploy' && str_contains($result['message'], 'Fatal error'), '/breaks is named, with "worked before"');
});

test('site check: off keeps the old single-page check', function () {
    [$root, $url] = siteForChecks();
    JsonStore::write('settings', ['deployer_name' => 'Tester', 'feature_smart_checks' => '0']);
    $site = ['url' => $url, 'check_path' => '/ok, /breaks'];
    check(SiteCheck::paths($site) === ['/ok'], 'only the first page');
    check(SiteCheck::measure($site) === [], 'nothing measured before');
    touch("$root/after");
    $result = SiteCheck::run($site);
    check($result['ok'] && $result['message'] === "$url/ok loads (HTTP 200, no PHP errors).", 'same message as before: ' . $result['message']);
});

test('site check: no address or page is skipped, as before', function () {
    check(SiteCheck::run(['url' => '', 'check_path' => '/x'])['skipped'], 'no address: skipped');
    check(SiteCheck::run(['url' => 'https://x.example', 'check_path' => ''])['skipped'], 'no page: skipped');
});
