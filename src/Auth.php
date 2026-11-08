<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * An optional password for the tool (Settings → Security). Without one, anyone who can use
 * this computer can open the tool; with one, every page asks for it first. Kept as a hash
 * in auth.json, which stays on this computer (never in a cloud backup). Signing in lasts
 * until the browser is idle for IDLE_SECONDS; changing the password signs everyone out.
 * After a few wrong tries, signing in waits longer each time.
 */
final class Auth
{
    public const MIN_LENGTH = 8;
    private const IDLE_SECONDS = 8 * 3600;
    private const FREE_TRIES = 5;

    /** Routes that work while signed out. */
    private const OPEN_ROUTES = ['login'];

    public static function enabled(): bool
    {
        return (self::stored()['hash'] ?? '') !== '';
    }

    /** Whether this browser is signed in now (doesn't extend it). */
    public static function signedIn(): bool
    {
        $session = $_SESSION['auth'] ?? null;
        return is_array($session)
            && hash_equals(self::tag(), (string) ($session['tag'] ?? ''))
            && time() - (int) ($session['last'] ?? 0) < self::IDLE_SECONDS;
    }

    /**
     * Lets the request through, or sends it to the sign-in page (JSON calls get a 401).
     * A page asked for while signed out is opened after signing in.
     */
    public static function guard(string $route): void
    {
        if (!self::enabled() || in_array($route, self::OPEN_ROUTES, true)) {
            return;
        }
        if (self::signedIn()) {
            $_SESSION['auth']['last'] = time();
            return;
        }
        unset($_SESSION['auth']);
        if (Http::wantsJson() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            Http::deny(401, 'Signed out. Reload the page and sign in again.');
        }
        $_SESSION['after_login'] = (string) ($_SERVER['QUERY_STRING'] ?? '');
        Http::redirect('r=login');
    }

    /** Returns an error message, or null when signed in. */
    public static function login(string $password): ?string
    {
        $stored = self::stored();
        $wait = (int) ($stored['locked_until'] ?? 0) - time();
        if ($wait > 0) {
            return 'Too many wrong tries. Wait ' . ($wait < 90 ? $wait . ' seconds' : ceil($wait / 60) . ' minutes') . ', then try again.';
        }
        if (!password_verify($password, (string) ($stored['hash'] ?? ''))) {
            $fails = (int) ($stored['fails'] ?? 0) + 1;
            $extra = $fails - self::FREE_TRIES;
            self::write(['fails' => $fails, 'locked_until' => $extra >= 0 ? time() + min(900, 30 * 2 ** $extra) : 0] + $stored);
            return 'That password is wrong.';
        }
        if (password_needs_rehash($stored['hash'], PASSWORD_DEFAULT)) {
            $stored['hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        self::write(['fails' => 0, 'locked_until' => 0] + $stored);
        self::start();
        return null;
    }

    public static function logout(): void
    {
        unset($_SESSION['auth']);
        session_regenerate_id(true);
    }

    /** Where to go after signing in: the page asked for, or the dashboard. */
    public static function takeReturn(): string
    {
        $query = (string) ($_SESSION['after_login'] ?? '');
        unset($_SESSION['after_login']);
        return preg_match('/^r=[\w-]+(&[\w%.=&-]*)?$/', $query) && !str_starts_with($query, 'r=login') ? $query : '';
    }

    /**
     * Sets, changes ($new not null) or removes ($new null) the password. When there is one,
     * the current password is needed. Returns an error message, or null when done.
     */
    public static function change(string $current, ?string $new, string $repeat = ''): ?string
    {
        if (self::enabled() && !password_verify($current, (string) self::stored()['hash'])) {
            return 'The current password is wrong.';
        }
        if ($new === null) {
            self::write([]);
            unset($_SESSION['auth']);
            return null;
        }
        if (strlen($new) < self::MIN_LENGTH) {
            return 'Use at least ' . self::MIN_LENGTH . ' characters.';
        }
        if ($new !== $repeat) {
            return 'The two new passwords are not the same.';
        }
        self::write(['hash' => password_hash($new, PASSWORD_DEFAULT), 'fails' => 0, 'locked_until' => 0]);
        self::start(); // this browser stays signed in; every other one is signed out
        return null;
    }

    private static function start(): void
    {
        session_regenerate_id(true);
        $_SESSION['auth'] = ['tag' => self::tag(), 'last' => time()];
    }

    /** Ties a sign-in to the password it was made with. */
    private static function tag(): string
    {
        return hash('sha256', (string) (self::stored()['hash'] ?? ''));
    }

    private static function stored(): array
    {
        return JsonStore::read('auth');
    }

    private static function write(array $data): void
    {
        JsonStore::write('auth', $data);
    }
}
