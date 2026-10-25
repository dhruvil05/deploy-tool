<?php

declare(strict_types=1);

namespace DeployTool;

/**
 * Request guards and response helpers.
 *
 * This tool can deploy to prod, so every request must come from this computer,
 * use a known host name (blocks DNS rebinding), and every change needs a CSRF token
 * (blocks other websites open in the same browser).
 */
final class Http
{
    private const LOCAL_ADDRESSES = ['127.0.0.1', '::1'];
    private const ALLOWED_HOSTS = ['localhost', '127.0.0.1', '[::1]', 'deploy-tool.test'];

    public static function guard(): void
    {
        if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', self::LOCAL_ADDRESSES, true)) {
            self::deny(403, 'This tool only accepts requests from this computer.');
        }
        if (!self::isAllowedHost($_SERVER['HTTP_HOST'] ?? '')) {
            self::deny(403, 'Unknown host name.');
        }
    }

    public static function sendSecurityHeaders(): void
    {
        header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        // same-origin, not no-referrer: with no-referrer browsers send "Origin: null" on form posts.
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
    }

    public static function startSession(): void
    {
        session_name('deploytool');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
        session_start();
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** For actions that change something: POST only, valid token, and no foreign Origin. */
    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::deny(405, 'This action needs a POST request.');
        }
        self::requireToken();
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin !== null && !self::isAllowedHost((string) parse_url($origin, PHP_URL_HOST))) {
            self::deny(403, 'This request came from another website.');
        }
    }

    /** Token from the form field or the X-CSRF-Token header (used by the page's own scripts). */
    public static function requireToken(): void
    {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || !hash_equals(self::csrfToken(), $token)) {
            self::deny(419, 'This page expired. Reload it and try again.');
        }
    }

    public static function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function deny(int $status, string $message): never
    {
        if (self::wantsJson()) {
            self::json(['ok' => false, 'error' => $message], $status);
        }
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
        exit;
    }

    public static function redirect(string $query = ''): never
    {
        header('Location: index.php' . ($query !== '' ? '?' . $query : ''), true, 303);
        exit;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = [$type, $message];
    }

    public static function takeFlash(): array
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $flash;
    }

    private static function isAllowedHost(string $host): bool
    {
        $host = strtolower((string) preg_replace('/:\d+$/', '', $host));
        return in_array($host, self::ALLOWED_HOSTS, true);
    }
}
