<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use DeployTool\Controller;
use DeployTool\Http;
use DeployTool\View;

Http::guard();
Http::sendSecurityHeaders();
Http::startSession();

try {
    (new Controller())->handle((string) ($_GET['r'] ?? 'dashboard'));
} catch (\Throwable $e) {
    if (Http::wantsJson()) {
        Http::json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    http_response_code(500);
    View::render('error', ['message' => $e->getMessage()], 'Error');
}
