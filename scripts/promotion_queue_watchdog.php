<?php
// Standalone watchdog to revive timed-out publication jobs and drive the queue

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(1);
}

require_once $root . '/includes/init.php';
require_once $root . '/includes/promotion.php';
require_once $root . '/includes/publication_queue.php';

$stats = pp_release_stuck_publications();
$batch = max(1, min(150, pp_get_max_concurrent_jobs() * 6));
pp_run_queue_worker($batch);

pp_promotion_log('promotion.queue.watchdog_run', [
    'released' => $stats['released'] ?? 0,
    'failed' => $stats['failed'] ?? 0,
    'checked' => $stats['checked'] ?? 0,
    'batch' => $batch,
]);

echo 'Watchdog processed. Released: ' . ($stats['released'] ?? 0) . ', failed: ' . ($stats['failed'] ?? 0) . ", checked: " . ($stats['checked'] ?? 0) . "\n";

return 0;
