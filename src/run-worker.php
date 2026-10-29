<?php

// Background worker for one run: php src/run-worker.php <run id>
// Started by the tool itself (Worker::ensure); it ends when the run stops running.

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !isset($argv[1])) {
    exit(1);
}

require __DIR__ . '/bootstrap.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', DeployTool\Worker::logFile());
set_time_limit(0);

try {
    DeployTool\Worker::main((string) $argv[1]);
} catch (\Throwable $e) {
    error_log('Worker for ' . $argv[1] . ': ' . $e->getMessage());
    exit(1);
}
