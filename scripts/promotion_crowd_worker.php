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

try {
    $runId = null;
    $taskId = null;
    foreach ($argv as $arg) {
        if (strpos($arg, '--run=') === 0) {
            $runIdCandidate = (int)substr($arg, 6);
            if ($runIdCandidate > 0) {
                $runId = $runIdCandidate;
            }
        } elseif (is_numeric($arg)) {
            $candidate = (int)$arg;
            if ($candidate > 0) {
                $taskId = $candidate;
            }
        }
    }
    $workerRow = null;
    $processed = 0;
    $pending = 0;
    if ($runId) {
        $claim = pp_promotion_crowd_worker_claim_slot($runId, getmypid());
        if (is_array($claim) && !empty($claim['id']) && ($claim['status'] ?? '') === 'running') {
            $workerRow = $claim;
        } else {
            pp_promotion_log('promotion.crowd.worker_claim_failed', [
                'run_id' => $runId,
                'claim' => $claim,
            ]);
            exit(0);
        }
        $stats = pp_promotion_crowd_worker_run($runId, 400, (int)$workerRow['id'], true);
        $processed = (int)($stats['processed'] ?? 0);
        $pending = (int)($stats['pending'] ?? 0);
        $nextStatus = $pending > 0 ? 'queued' : 'completed';
        pp_promotion_crowd_worker_finish_slot((int)$workerRow['id'], $nextStatus, null);
        if ($pending > 0) {
            pp_promotion_crowd_schedule_worker($runId);
        }
    } else {
        if (!isset($taskId) || $taskId === null) { $taskId = null; }
        $processed = pp_promotion_crowd_worker($taskId, 60, null, null);
        if ($processed > 0) {
            pp_promotion_launch_worker();
            pp_promotion_trigger_worker_inline(null, $processed > 20 ? 6 : 3);
        }
    }
    pp_promotion_log('promotion.crowd.worker_cli_run', [
        'task_id' => $taskId,
        'run_id' => $runId,
        'processed' => $processed,
        'pending' => $pending,
    ]);
} catch (Throwable $e) {
    if (isset($workerRow['id'])) {
        pp_promotion_crowd_worker_finish_slot((int)$workerRow['id'], 'failed', $e->getMessage());
    }
    pp_promotion_log('Crowd worker crashed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    fwrite(STDERR, 'Crowd worker error: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

exit(0);
