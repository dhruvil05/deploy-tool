<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use DeployTool\Auth;
use DeployTool\Controller;
use DeployTool\Http;
use DeployTool\View;

Http::guard();
Http::sendSecurityHeaders();
Http::startSession();

try {
    $route = (string) ($_GET['r'] ?? 'dashboard');
    Auth::guard($route); // when a password is set, nothing but the sign-in page works signed out
    (new Controller())->handle($route);
} catch (\Throwable $e) {
    if (Http::wantsJson()) {
        Http::json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    http_response_code(500);
    View::render('error', ['message' => $e->getMessage()], 'Error');
}
