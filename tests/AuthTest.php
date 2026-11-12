<?php

declare(strict_types=1);

use DeployTool\Auth;
use DeployTool\JsonStore;

test('sign-in: off until a password is set; then the right password signs in', function () {
    $_SESSION = [];
    check(!Auth::enabled(), 'no password: the tool is open');
    check(Auth::change('', 'short', 'short') !== null, 'too short is refused');
    check(Auth::change('', 'correct-horse', 'other-horse') !== null, 'two different passwords are refused');
    check(Auth::change('', 'correct-horse', 'correct-horse') === null && Auth::enabled() && Auth::signedIn(), 'set: on, and this browser stays signed in');
    check(!str_contains((string) file_get_contents(STORAGE_DIR . '/auth.json'), 'correct-horse'), 'only a hash is stored');
    $_SESSION = [];
    check(!Auth::signedIn(), 'another browser is signed out');
    check(Auth::login('wrong') === 'That password is wrong.' && Auth::login('correct-horse') === null && Auth::signedIn(), 'wrong fails, right signs in');
});

test('sign-in: wrong tries lock it for a while; changing the password signs others out', function () {
    $_SESSION = [];
    Auth::change('', 'correct-horse', 'correct-horse');
    $other = $_SESSION;
    for ($i = 0; $i < 5; $i++) {
        Auth::login('bad');
    }
    check(str_contains((string) Auth::login('correct-horse'), 'Too many wrong tries'), 'after 5 wrong tries, even the right one waits');
    $stored = JsonStore::read('auth');
    JsonStore::write('auth', ['locked_until' => time() - 1] + $stored);
    check(Auth::login('correct-horse') === null, 'after the wait, the right one works');
    $_SESSION = [];
    Auth::login('correct-horse');
    check(Auth::change('correct-horse', 'battery-staple', 'battery-staple') === null, 'changed with the current password');
    $_SESSION = $other;
    check(!Auth::signedIn(), 'a browser signed in with the old password is signed out');
    $_SESSION = [];
    Auth::login('battery-staple');
    check(Auth::change('wrong', null) !== null && Auth::change('battery-staple', null) === null && !Auth::enabled(), 'removing needs the current password; then the tool is open again');
});
