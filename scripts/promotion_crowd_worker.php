<?php
// Background worker for automated crowd promotion tasks

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(1);
}
require_once $root . '/includes/init.php';
require_once $root . '/includes/promotion.php';
require_once $root . '/includes/crowd_deep.php';

$taskId = null;
if (PHP_SAPI === 'cli') {
    global $argc, $argv;
    if ($argc > 1) {
        $candidate = (int)$argv[1];
        if ($candidate > 0) { $taskId = $candidate; }
    }
}

try {
    pp_promotion_crowd_worker($taskId, 60);
} catch (Throwable $e) {
    pp_promotion_log('Crowd worker crashed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    fwrite(STDERR, 'Crowd worker error: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

exit(0);
