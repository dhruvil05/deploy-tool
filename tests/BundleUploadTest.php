<?php

declare(strict_types=1);

use DeployTool\BundleUpload;
use DeployTool\FolderTransport;

// A folder served by a local PHP server stands in for an FTP site and its web address.

test('bundle: uploads many files in one go, each in place, helper and bundle gone', function () {
    $site = Tests::folder('site');
    file_put_contents("$site/old.php", 'old');
    mkdir("$site/app");
    file_put_contents("$site/app/keep.php", 'keep me');
    $url = Tests::serve($site);
    $files = ['index.php' => '<?php echo "new";', 'app/a.php' => str_repeat("line\r\n", 500), 'deep/new/b.css' => 'b{}', 'old.php' => 'replaced'];
    $errors = BundleUpload::upload(new FolderTransport($site), $url, $files);
    check($errors === [], 'no errors: ' . json_encode($errors));
    foreach ($files as $path => $contents) {
        check(file_get_contents("$site/$path") === $contents, "$path has exactly the uploaded bytes");
    }
    check(file_get_contents("$site/app/keep.php") === 'keep me', 'other files are left alone');
    check(glob("$site/dt-*") === [] && glob("$site/**/.*.dtup") === [], 'helper, bundle and temp files are gone');
});

test('bundle: the bundle file shows nothing when requested on the web', function () {
    $site = Tests::folder('site');
    $url = Tests::serve($site);
    file_put_contents("$site/dt-bundle-test.php", BundleUpload::pack(['secret.php' => '<?php $password = "x";']));
    check(file_get_contents("$url/dt-bundle-test.php") === '', 'requesting the bundle prints nothing');
});

test('bundle: the helper refuses a wrong token and unsafe paths', function () {
    $site = Tests::folder('site');
    $url = Tests::serve($site);
    file_put_contents("$site/dt-unpack-x.php", BundleUpload::helperScript(hash('sha256', 'right'), 'dt-bundle-x.php'));
    $wrong = @file_get_contents("$url/dt-unpack-x.php", false, stream_context_create(['http' => ['method' => 'POST', 'content' => 't=wrong', 'header' => 'Content-Type: application/x-www-form-urlencoded', 'ignore_errors' => true]]));
    check($wrong === '', 'a wrong token gets nothing');
    check(is_file("$site/dt-unpack-x.php"), 'and the helper stays (so the right call still works)');

    file_put_contents("$site/dt-bundle-x.php", BundleUpload::pack(['../outside.php' => 'x', 'ok.php' => 'fine', 'a\\b.php' => 'x']));
    $answer = json_decode((string) file_get_contents("$url/dt-unpack-x.php", false, stream_context_create(['http' => ['method' => 'POST', 'content' => 't=right', 'header' => 'Content-Type: application/x-www-form-urlencoded']])), true);
    check(isset($answer['errors']['../outside.php'], $answer['errors']['a\\b.php']), 'unsafe paths are refused');
    check(!is_file(dirname($site) . '/outside.php') && file_get_contents("$site/ok.php") === 'fine', 'nothing is written outside; the safe file is');
});

test('bundle: an unreachable helper throws (the deploy then uploads one by one), files cleaned up', function () {
    $site = Tests::folder('site');
    try {
        BundleUpload::upload(new FolderTransport($site), 'http://127.0.0.1:9', ['a.php' => 'x']);
        check(false, 'should throw');
    } catch (\RuntimeException $e) {
        check(str_contains($e->getMessage(), 'could not be reached'), 'says the helper could not be reached');
    }
    check(glob("$site/dt-*") === [], 'the helper and bundle were removed again');
    check(!is_file("$site/a.php"), 'nothing was written');
});
