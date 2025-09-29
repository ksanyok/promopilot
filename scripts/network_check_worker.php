<?php
// Network diagnostics worker. Launches from background process to execute a single run.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only";
    exit(1);
}

require_once __DIR__ . '/../includes/init.php';

$runId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($runId <= 0) {
    fwrite(STDERR, "Missing run id\n");
    exit(1);
}

pp_network_check_log('CLI worker invoked', ['runId' => $runId, 'argv' => $argv]);

pp_process_network_check_run($runId);

pp_network_check_log('CLI worker completed', ['runId' => $runId]);

exit(0);
