<?php

declare(strict_types=1);

use DeployTool\Ai;
use DeployTool\CloudBackup;
use DeployTool\JsonStore;
use DeployTool\Secrets;
use DeployTool\Sites;

function secretsSetup(): void
{
    JsonStore::write('sources', ['dt' => ['key' => 'dt', 'name' => 'DT', 'repo_path' => dirname(__DIR__), 'branch' => 'master', 'remote' => 'origin', 'extra_protected' => '']]);
    JsonStore::write('sites', ['s' => ['key' => 's', 'name' => 'S', 'type' => 'ftp', 'source' => 'local:dt', 'host' => 'ftp.example.com', 'username' => 'u', 'password' => 'pw-one', 'url' => 'https://x.example']]);
}

/** Forgets the key loaded in this process, as a new request would. */
function forgetKey(): void
{
    $reset = new ReflectionProperty(Secrets::class, 'loaded');
    $reset->setValue(null, false);
    Secrets::$unreadable = 0;
}

test('secrets: plain passwords and keys are encrypted on first read and still work', function () {
    forgetKey();
    secretsSetup();
    JsonStore::write('ai', ['api_key' => 'sk-123']);
    JsonStore::write('cloud', ['secret_key' => 'cloudsecret', 'passphrase' => 'a long passphrase', 'access_key' => 'AKID']);
    check(Sites::get('s')['password'] === 'pw-one' && Ai::settings()['api_key'] === 'sk-123' && CloudBackup::settings()['secret_key'] === 'cloudsecret', 'every value reads back');
    $disk = file_get_contents(STORAGE_DIR . '/sites.json') . file_get_contents(STORAGE_DIR . '/ai.json') . file_get_contents(STORAGE_DIR . '/cloud.json');
    check(!str_contains($disk, 'pw-one') && !str_contains($disk, 'sk-123') && !str_contains($disk, 'cloudsecret'), 'nothing readable on disk');
    check(str_contains($disk, 'AKID'), 'the access key ID (not secret) stays readable');
    check(Secrets::seal('x') !== Secrets::seal('x'), 'each seal is different (random nonce)');
});

test('secrets: another key never wipes a value; a restore re-seals; a damaged key is replaced', function () {
    forgetKey();
    secretsSetup();
    Sites::get('s'); // seals with the test key
    $sealed = JsonStore::read('sites')['s']['password'];
    $original = (string) file_get_contents(getenv('DEPLOY_TOOL_KEY_FILE'));

    file_put_contents(getenv('DEPLOY_TOOL_KEY_FILE'), 'garbage');
    touch(getenv('DEPLOY_TOOL_KEY_FILE'), time() - 60);
    forgetKey();
    check(Sites::get('s')['password'] === $sealed && Secrets::$unreadable === 1, 'unreadable: kept exactly, counted for the warning');
    check(count(Secrets::brokenKeys()) === 1 && Secrets::isSealed(Secrets::seal('new')), 'the damaged key was set aside and a fresh one encrypts');
    try {
        Sites::transport(Sites::get('s'));
        check(false, 'should refuse');
    } catch (\RuntimeException $e) {
        check(str_contains($e->getMessage(), "can't be unlocked"), 'using it gives a clear message');
    }
    Sites::setHidden('s', true);
    check(JsonStore::read('sites')['s']['password'] === $sealed, 'saving the site leaves the sealed value untouched');
    Secrets::resealFile('sites', ['password'], true, Secrets::importKey($original));
    forgetKey();
    check(Sites::get('s')['password'] === 'pw-one', 'after a restore with the old key, it reads again');
    foreach (Secrets::brokenKeys() as $file) {
        unlink($file);
    }
});
