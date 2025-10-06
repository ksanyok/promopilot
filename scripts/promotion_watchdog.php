<?php
// Watchdog script to keep promotion and crowd workers moving

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(1);
}

require_once $root . '/includes/init.php';
require_once $root . '/includes/promotion.php';
require_once $root . '/includes/crowd_deep.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

$startTime = microtime(true);
@set_time_limit(0);
@ignore_user_abort(true);

$lockDir = $root . '/config';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockPath = $lockDir . '/promotion_watchdog.lock';
$lockHandle = @fopen($lockPath, 'c+');
$lockAcquired = false;
if ($lockHandle !== false) {
    $lockAcquired = @flock($lockHandle, LOCK_EX | LOCK_NB);
}
if (!$lockAcquired) {
    pp_promotion_log('promotion.watchdog.skipped_busy', []);
    $busyPayload = [
        'ok' => false,
        'busy' => true,
        'message' => 'Watchdog already running',
    ];
    $busyJson = json_encode($busyPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $busyJson . PHP_EOL);
    } else {
        echo $busyJson;
    }
    if (is_resource($lockHandle)) {
        @fclose($lockHandle);
    }
    exit(0);
}

$releaseLock = static function() use (&$lockHandle, &$lockAcquired): void {
    if ($lockAcquired && is_resource($lockHandle)) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
        $lockAcquired = false;
    }
};

try {
    $staleMinutesEnv = getenv('PROMOPILOT_WATCHDOG_STALE_MINUTES');
    $staleMinutes = is_numeric($staleMinutesEnv) ? (int)$staleMinutesEnv : 15;
    $staleMinutes = max(5, min(180, $staleMinutes));

    $queueStaleMinutesEnv = getenv('PROMOPILOT_WATCHDOG_QUEUE_STALE_MINUTES');
    $queueStaleMinutes = is_numeric($queueStaleMinutesEnv) ? (int)$queueStaleMinutesEnv : max(30, $staleMinutes);
    $queueStaleMinutes = max(10, min(360, $queueStaleMinutes));

    $summary = pp_promotion_active_runs_summary($staleMinutes);
    $runsById = [];
    foreach ($summary['runs'] as $runInfo) {
        if (isset($runInfo['id'])) {
            $runsById[(int)$runInfo['id']] = $runInfo;
        }
    }

    $queueBefore = pp_publication_queue_summary($queueStaleMinutes);
    $pendingCrowd = pp_promotion_crowd_pending_count();

    $actions = [];
    $promotionIterations = 0;
    $crowdIterations = 0;
    $crowdProcessedInline = 0;
    $queueJobsRequested = 0;

    if ($summary['total'] > 0) {
        $promotionIterations = min(220, max(24, $summary['total'] * 12));
        $promotionLaunched = pp_promotion_launch_worker();
        pp_promotion_worker(null, $promotionIterations);
        $actions['promotion'] = [
            'launched' => $promotionLaunched,
            'iterations' => $promotionIterations,
            'active_runs' => $summary['total'],
            'stale_runs' => $summary['stale']['count'] ?? 0,
        ];
    }

    $resumedRuns = [];
    if (!empty($summary['stale']['ids'])) {
        foreach ($summary['stale']['ids'] as $runIdRaw) {
            $runId = (int)$runIdRaw;
            $runInfo = $runsById[$runId] ?? null;
            $idleMinutes = (int)($runInfo['idle_minutes'] ?? $summary['stale']['max_idle_minutes'] ?? 0);
            $resumeIterations = min(160, max(24, (int)ceil(max(1, $idleMinutes) / 2)));

            pp_promotion_log('promotion.watchdog.resume_run', [
                'run_id' => $runId,
                'idle_minutes' => $idleMinutes,
                'iterations' => $resumeIterations,
                'stage' => $runInfo['stage'] ?? null,
                'status' => $runInfo['status'] ?? null,
            ]);

            $launchedRun = pp_promotion_launch_worker($runId);
            pp_promotion_worker($runId, $resumeIterations);

            $resumedRuns[] = [
                'run_id' => $runId,
                'iterations' => $resumeIterations,
                'launched' => $launchedRun,
            ];
        }
        if (!isset($actions['promotion'])) {
            $actions['promotion'] = [];
        }
        $actions['promotion']['resumed_runs'] = $resumedRuns;
    }

    if ($pendingCrowd > 0) {
        $crowdIterations = min(80, max(8, (int)ceil($pendingCrowd / 3)));
        $crowdLaunched = pp_promotion_launch_crowd_worker();
        try {
            $crowdProcessedInline = pp_promotion_crowd_worker(null, $crowdIterations);
        } catch (Throwable $crowdError) {
            pp_promotion_log('promotion.watchdog.crowd_inline_error', [
                'error' => $crowdError->getMessage(),
            ]);
        }
        $actions['crowd'] = [
            'launched' => $crowdLaunched,
            'iterations' => $crowdIterations,
            'pending_tasks' => $pendingCrowd,
            'processed_inline' => $crowdProcessedInline,
        ];
    }

    $queueAfter = $queueBefore;
    if (($queueBefore['pending'] ?? 0) > 0 || ($queueBefore['queue_rows'] ?? 0) > 0) {
        $queueJobsRequested = min(20, max(2, (int)ceil(max($queueBefore['pending'], $queueBefore['queue_rows']) / 2)));
        pp_run_queue_worker($queueJobsRequested);
        $queueAfter = pp_publication_queue_summary($queueStaleMinutes);
        $actions['publications'] = [
            'jobs_requested' => $queueJobsRequested,
            'pending_before' => $queueBefore['pending'],
            'pending_after' => $queueAfter['pending'],
            'processed_estimate' => max(0, ($queueBefore['pending'] ?? 0) - ($queueAfter['pending'] ?? 0)),
            'queue_rows_before' => $queueBefore['queue_rows'],
            'queue_rows_after' => $queueAfter['queue_rows'],
            'stale_pending_after' => $queueAfter['stale_pending'],
        ];
    }

    if (($summary['stale']['count'] ?? 0) > 0) {
        pp_promotion_log('promotion.watchdog.stale_detected', [
            'stale_runs' => $summary['stale']['count'],
            'ids' => $summary['stale']['ids'],
            'threshold' => $summary['stale']['threshold'],
            'max_idle_minutes' => $summary['stale']['max_idle_minutes'],
        ]);
    }

    if (($queueAfter['stale_pending'] ?? 0) > 0) {
        pp_promotion_log('promotion.watchdog.queue_stale', [
            'stale_pending' => $queueAfter['stale_pending'],
            'threshold' => $queueAfter['stale_threshold'],
            'pending' => $queueAfter['pending'],
        ]);
    }

    pp_promotion_log('promotion.watchdog.tick', [
        'active_runs' => $summary['total'],
        'crowd_pending' => $pendingCrowd,
        'queue_pending' => $queueAfter['pending'],
        'actions' => $actions,
        'latest_update' => $summary['latest_update'],
        'oldest_update' => $summary['oldest_update'],
        'duration_ms' => (int)round((microtime(true) - $startTime) * 1000),
    ]);

    $output = [
        'ok' => true,
        'active_runs' => $summary['total'],
        'crowd_pending' => $pendingCrowd,
        'queue_pending' => $queueAfter['pending'],
        'actions' => $actions,
        'stale' => $summary['stale'],
        'queue_before' => $queueBefore,
        'queue_after' => $queueAfter,
    ];

    $json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $json . PHP_EOL);
    } else {
        echo $json;
    }
} finally {
    $releaseLock();
}

exit(0);
