<?php

// Background process for the speed-up "Chat with the assistant from Discord":
// php src/discord-worker.php. Started by the tool itself (Discord\Bridge::ensure on page
// loads); it ends when the speed-up is switched off.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/bootstrap.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', DeployTool\Worker::logFile());
set_time_limit(0);

// The automatic tests point the worker at a fake Discord on this computer; nothing else can.
$fake = (string) getenv('DEPLOY_TOOL_DISCORD_API');
if (preg_match('#^http://127\.0\.0\.1:\d+/api/v10$#', $fake)) {
    DeployTool\Discord\Api::$base = $fake;
}

try {
    DeployTool\Discord\Bridge::main();
} catch (\Throwable $e) {
    error_log('Discord bridge: ' . $e->getMessage());
    exit(1);
}
