<?php
// Cron-friendly entry point that keeps promotion pipelines and crowd workers moving.

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(1);
}

require_once $root . '/includes/init.php';
require_once $root . '/includes/promotion.php';
require_once $root . '/includes/promotion/crowd.php';
require_once $root . '/includes/promotion/utils.php';
require_once $root . '/includes/publication_queue.php';

if (function_exists('session_write_close')) {
    @session_write_close();
}
@ignore_user_abort(true);

$activeStages = [
    'queued',
    'pending_level1',
    'running',
    'level1_active',
    'pending_level2',
    'level2_active',
    'pending_level3',
    'level3_active',
    'pending_crowd',
    'crowd_ready',
    'report_ready',
];

$maxRunsRaw = getenv('PROMOPILOT_CRON_MAX_RUNS');
$maxRuns = 25;
if ($maxRunsRaw !== false) {
    $maxRuns = (int)$maxRunsRaw;
    if ($maxRuns <= 0) {
        $maxRuns = 25;
    }
}
$maxRuns = max(1, min(100, $maxRuns));

$runIds = [];
$crowdPending = 0;
$pubQueued = 0;
$pubRunning = 0;
try {
    $conn = @connect_db();
} catch (Throwable $e) {
    pp_promotion_log('promotion.cron.tick_db_error', ['error' => $e->getMessage()]);
    $conn = null;
}

if ($conn instanceof mysqli) {
    $stageList = "'" . implode("','", array_map(static fn(string $s): string => $conn->real_escape_string($s), $activeStages)) . "'";
    $sql = 'SELECT id FROM promotion_runs WHERE status IN (' . $stageList . ') ORDER BY updated_at ASC, id ASC LIMIT ' . $maxRuns;
    if ($res = @$conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $runId = isset($row['id']) ? (int)$row['id'] : 0;
            if ($runId > 0) {
                $runIds[] = $runId;
            }
        }
        $res->free();
    }
    $crowdPending = pp_promotion_crowd_pending_count();
    // Publication queue stats (queued and running)
    if ($resQ = @$conn->query("SELECT COUNT(*) AS c FROM publications WHERE status='queued' AND (scheduled_at IS NULL OR scheduled_at <= CURRENT_TIMESTAMP)")) {
        if ($rowQ = $resQ->fetch_assoc()) { $pubQueued = (int)($rowQ['c'] ?? 0); }
        $resQ->free();
    }
    if ($resR = @$conn->query("SELECT COUNT(*) AS c FROM publications WHERE status='running'")) {
        if ($rowR = $resR->fetch_assoc()) { $pubRunning = (int)($rowR['c'] ?? 0); }
        $resR->free();
    }
    $conn->close();
}

$workerLaunched = pp_promotion_launch_worker(null, true);
foreach ($runIds as $runId) {
    pp_promotion_launch_worker($runId, true);
}

$crowdLaunched = pp_promotion_launch_crowd_worker(null, true);

// Drive publications queue in the background as part of the cron tick.
// This ensures enqueued publications progress even without any web requests.
try {
    $batch = max(1, min(50, (pp_get_max_concurrent_jobs() * 5)));
    @pp_run_queue_worker($batch);
} catch (Throwable $e) {
    pp_promotion_log('promotion.cron.pubqueue_error', ['error' => $e->getMessage()]);
}

pp_promotion_log('promotion.cron.tick', [
    'runs_checked' => count($runIds),
    'crowd_pending' => $crowdPending,
    'worker_launched' => $workerLaunched,
    'crowd_worker_launched' => $crowdLaunched,
    'max_runs' => $maxRuns,
    'pub_queued' => $pubQueued,
    'pub_running' => $pubRunning,
]);

exit(0);
