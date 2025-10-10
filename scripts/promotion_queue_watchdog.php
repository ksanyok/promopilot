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
$recovery = pp_promotion_recover_overdue_nodes(900, 40);

pp_promotion_log('promotion.queue.watchdog_run', [
    'released' => $stats['released'] ?? 0,
    'failed' => $stats['failed'] ?? 0,
    'checked' => $stats['checked'] ?? 0,
    'batch' => $batch,
    'recovered_runs' => $recovery['runs'] ?? 0,
    'recovery_candidates' => $recovery['candidates'] ?? 0,
]);

echo 'Watchdog processed. Released: ' . ($stats['released'] ?? 0)
    . ', failed: ' . ($stats['failed'] ?? 0)
    . ', checked: ' . ($stats['checked'] ?? 0)
    . ', recovered runs: ' . ($recovery['runs'] ?? 0)
    . "\n";

return 0;
