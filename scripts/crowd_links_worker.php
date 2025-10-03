<?php
require_once __DIR__ . '/../includes/init.php';

$runId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($runId <= 0) {
    // Fallback mode: auto-pick the latest queued/running run so cron can drive processing
    try {
        $conn = @connect_db();
    } catch (Throwable $e) {
        fwrite(STDERR, "DB connect failed\n");
        exit(1);
    }
    if ($conn) {
        if ($res = @$conn->query("SELECT id FROM crowd_link_runs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")) {
            if ($row = $res->fetch_assoc()) {
                $runId = (int)($row['id'] ?? 0);
            }
            $res->free();
        }
        $conn->close();
    }
    if ($runId <= 0) {
        fwrite(STDERR, "No active runs\n");
        exit(0);
    }
}

try {
    pp_crowd_links_process_run($runId);
} catch (Throwable $e) {
    pp_crowd_links_log('Worker crashed', ['runId' => $runId, 'error' => $e->getMessage()]);
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}

exit(0);
