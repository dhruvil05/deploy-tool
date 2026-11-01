<?php

declare(strict_types=1);

// Started by the tool itself (CloudBackup::maybeStart) to make one cloud backup without
// keeping a page waiting. The result goes to storage/cloud-state.json.

require __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
set_time_limit(0);
try {
    DeployTool\CloudBackup::backup();
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' cloud backup: ' . $e->getMessage() . "\n");
    exit(1);
}
