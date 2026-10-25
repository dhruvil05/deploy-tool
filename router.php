<?php

// Router for PHP's built-in server: php -S 127.0.0.1:8090 router.php
// Only index.php and files in assets/ are reachable, so storage/ (FTP passwords)
// and src/ can never be downloaded.

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (preg_match('#^/assets/[\w-]+\.(css|js)$#', $path) && is_file(__DIR__ . $path)) {
    return false;
}
if ($path !== '/' && $path !== '/index.php') {
    http_response_code(404);
    echo 'Not found.';
    return true;
}

require __DIR__ . '/index.php';
