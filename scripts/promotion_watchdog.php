<?php
declare(strict_types=1);

// Deprecated stub: the watchdog functionality has been superseded by scripts/promotion_cron_tick.php.
// Kept only to surface a clear error for any legacy cron entries still pointing here.

$message = '[promopilot] scripts/promotion_watchdog.php is deprecated. Run scripts/promotion_cron_tick.php instead.';

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, $message . "\n");
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'DEPRECATED',
        'message' => 'Use scripts/promotion_cron_tick.php instead of promotion_watchdog.php',
    ]);
}

exit(1);
