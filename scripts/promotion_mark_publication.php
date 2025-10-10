<?php
// Manual recovery script for marking a publication as successful and syncing the related promotion node.

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "Unable to resolve project root.\n");
    exit(1);
}
require_once $root . '/includes/init.php';
require_once $root . '/includes/promotion.php';
require_once $root . '/includes/promotion_helpers.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/promotion_mark_publication.php <publication_id> <published_url> [log_file]\n");
    exit(1);
}

$publicationId = (int)$argv[1];
$publishedUrl = trim((string)$argv[2]);
$logFileArgument = $argv[3] ?? null;

if ($publicationId <= 0 || $publishedUrl === '') {
    fwrite(STDERR, "Invalid publication id or URL.\n");
    exit(1);
}

try {
    $conn = connect_db();
} catch (Throwable $e) {
    fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!$conn instanceof mysqli) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

// Fetch existing publication to ensure it exists and capture metadata.
$stmt = $conn->prepare('SELECT id, project_id, status, log_file FROM publications WHERE id = ? LIMIT 1');
if (!$stmt) {
    fwrite(STDERR, 'Failed to prepare publication lookup statement.' . PHP_EOL);
    exit(1);
}
$stmt->bind_param('i', $publicationId);
if (!$stmt->execute()) {
    $stmt->close();
    fwrite(STDERR, 'Failed to execute publication lookup.' . PHP_EOL);
    exit(1);
}
$pubRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$pubRow) {
    fwrite(STDERR, 'Publication not found.' . PHP_EOL);
    exit(1);
}

$logRelative = null;
$jobResult = null;

if ($logFileArgument) {
    $logPath = $logFileArgument;
    if (!is_file($logPath)) {
        fwrite(STDERR, 'Warning: specified log file not found, continuing without job result.' . PHP_EOL);
    } else {
        $logContent = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($logContent)) {
            foreach (array_reverse($logContent) as $line) {
                $line = trim($line);
                if ($line === '') { continue; }
                $prefix = 'Success result ';
                if (strpos($line, $prefix) === 0) {
                    $payloadJson = substr($line, strlen($prefix));
                    $decoded = json_decode($payloadJson, true);
                    if (is_array($decoded)) {
                        $jobResult = $decoded;
                    }
                    break;
                }
            }
        }
        $stored = pp_promotion_store_log_path($logPath, null);
        if ($stored !== null) {
            $logRelative = $stored;
        }
    }
}

// Update publication record to successful state.
$updateSql = 'UPDATE publications SET post_url = ?, network = network, published_by = COALESCE(published_by, \'system\'), status = \'success\', error = NULL, log_file = ?, finished_at = CURRENT_TIMESTAMP, cancel_requested = 0, pid = NULL, verification_status = \'success\', verification_details = NULL WHERE id = ? LIMIT 1';
$stmtUpdate = $conn->prepare($updateSql);
if (!$stmtUpdate) {
    fwrite(STDERR, 'Failed to prepare update statement.' . PHP_EOL);
    exit(1);
}
$stmtUpdate->bind_param('ssi', $publishedUrl, $logRelative, $publicationId);
if (!$stmtUpdate->execute()) {
    $stmtUpdate->close();
    fwrite(STDERR, 'Failed to update publication record.' . PHP_EOL);
    exit(1);
}
$stmtUpdate->close();

// Clean up queue entry if present.
@$conn->query('UPDATE publication_queue SET status=\'success\' WHERE publication_id = ' . $publicationId);
@$conn->query('DELETE FROM publication_queue WHERE publication_id = ' . $publicationId);

$jobResultFinal = $jobResult;
if (is_array($jobResultFinal)) {
    // Ensure publishedUrl present inside job result for downstream use.
    if (empty($jobResultFinal['publishedUrl'])) {
        $jobResultFinal['publishedUrl'] = $publishedUrl;
    }
    if (!empty($logRelative) && empty($jobResultFinal['_log_relative'])) {
        $jobResultFinal['_log_relative'] = $logRelative;
    }
}

pp_promotion_handle_publication_update($publicationId, 'success', $publishedUrl, null, $jobResultFinal);

fwrite(STDOUT, 'Publication #' . $publicationId . ' marked as success and promotion node synchronized.' . PHP_EOL);

$conn->close();

exit(0);
