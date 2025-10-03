<?php
// Background worker for promotion cascade runs

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(1);
}
require_once $root . '/includes/init.php';
require_once $root . '/includes/promotion.php';

$runId = null;
if (PHP_SAPI === 'cli') {
    global $argc, $argv;
    if ($argc > 1) {
        $candidate = (int)$argv[1];
        if ($candidate > 0) { $runId = $candidate; }
    }
}

try {
    pp_promotion_worker($runId, 80);
} catch (Throwable $e) {
    pp_promotion_log('Worker crashed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    fwrite(STDERR, 'Promotion worker error: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

exit(0);
