<?php
require_once __DIR__ . '/../includes/init.php';

$runId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($runId <= 0) {
    fwrite(STDERR, "Usage: php crowd_links_deep_worker.php <runId>\n");
    exit(1);
}

try {
    pp_crowd_deep_process_run($runId);
} catch (Throwable $e) {
    pp_crowd_links_log('Deep worker crashed', ['runId' => $runId, 'error' => $e->getMessage()]);
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}

exit(0);
