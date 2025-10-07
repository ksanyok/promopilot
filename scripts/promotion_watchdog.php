<?php
declare(strict_types=1);

// Backward compatibility wrapper: promotion_watchdog.php is deprecated and now forwards to promotion_cron_tick.php.
// Legacy cron entries pointing here will continue to work while logging a warning.

$warning = '[promopilot] scripts/promotion_watchdog.php is deprecated. Forwarding to scripts/promotion_cron_tick.php.';

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, $warning . "\n");
} else {
    header('X-Promopilot-Warning: promotion_watchdog.php deprecated');
}

require __DIR__ . '/promotion_cron_tick.php';
