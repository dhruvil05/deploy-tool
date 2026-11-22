<?php

// Background process for the timed checks (server watch, uptime monitor):
// php src/scheduler-worker.php. Started by the tool itself (Scheduler::ensure on page loads);
// it ends when both speed-ups are switched off.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/bootstrap.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', DeployTool\Worker::logFile());
set_time_limit(0);

try {
    DeployTool\Scheduler::main();
} catch (\Throwable $e) {
    error_log('Scheduler: ' . $e->getMessage());
    exit(1);
}
