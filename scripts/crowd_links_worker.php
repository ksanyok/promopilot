<?php
// Crowd links background worker. Executes a single run in CLI context.
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

pp_crowd_links_log('CLI worker invoked', [
    'runId' => $runId,
    'argv' => $argv,
    'phpBinary' => PHP_BINARY,
    'phpSapi' => PHP_SAPI,
    'cwd' => getcwd(),
]);

try {
    pp_crowd_links_process_run($runId);
    pp_crowd_links_log('CLI worker completed', ['runId' => $runId]);
} catch (Throwable $e) {
    pp_crowd_links_log('CLI worker failed', ['runId' => $runId, 'error' => $e->getMessage()]);
}

exit(0);
