<?php
// Bootstrap and module loader for PromoPilot helpers

// Language (default ru)
$current_lang = $_SESSION['lang'] ?? 'ru';

// Ensure root path constant exists for reliable includes
if (!defined('PP_ROOT_PATH')) {
    define('PP_ROOT_PATH', realpath(__DIR__ . '/..'));
}

// Load modular helpers
// These files are guarded via function_exists checks and can be included repeatedly.
require_once __DIR__ . '/runtime.php';         // Node/Chrome and runner helpers
require_once __DIR__ . '/network_check.php';   // Network diagnostics helpers
require_once __DIR__ . '/core.php';            // Core (i18n, csrf, auth, base url, small utils)
require_once __DIR__ . '/db.php';              // DB, settings, currency, avatars
require_once __DIR__ . '/payments.php';        // Payment gateways and transactions
require_once __DIR__ . '/networks.php';        // Networks registry and utilities
require_once __DIR__ . '/crowd_links.php';     // Crowd marketing links management
require_once __DIR__ . '/crowd_deep.php';      // Crowd deep submission verification
require_once __DIR__ . '/page_meta.php';       // Page meta + URL analysis helpers
require_once __DIR__ . '/project_brief.php';   // Project brief (AI-assisted naming)
require_once __DIR__ . '/publication_queue.php'; // Publication queue processing
require_once __DIR__ . '/update.php';          // Version and update checks

// Load translations when not RU
if ($current_lang != 'ru') {
    $langFile = PP_ROOT_PATH . '/lang/' . basename($current_lang) . '.php';
    if (file_exists($langFile)) { include $langFile; }
}

// Ensure DB schema has required columns/tables
function ensure_schema(): void {
    try {
        $conn = @connect_db();
    } catch (Throwable $e) {
        return; // cannot connect; installer will handle
    }
    if (!$conn) return;

    // Helper to get columns map
    $getCols = function(string $table) use ($conn): array {
        $cols = [];
        try {
            if ($res = @$conn->query("DESCRIBE `{$table}`")) {
                while ($row = $res->fetch_assoc()) {
                    $cols[$row['Field']] = $row;
                }
                $res->free();
            }
        } catch (Throwable $e) { /* ignore */ }
        return $cols;
    };

    // Projects table
    $projectsCols = $getCols('projects');
    if (empty($projectsCols)) {
        // Create minimal projects table if missing
        @$conn->query("CREATE TABLE IF NOT EXISTS `projects` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `description` TEXT NULL,
            `links` TEXT NULL,
            `language` VARCHAR(10) NOT NULL DEFAULT 'ru',
            `wishes` TEXT NULL,
            `domain_host` VARCHAR(190) NULL,
            `region` VARCHAR(100) NULL,
            `topic` VARCHAR(100) NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        // Add missing columns
        if (!isset($projectsCols['links'])) {
            @$conn->query("ALTER TABLE `projects` ADD COLUMN `links` TEXT NULL");
        }
        if (!isset($projectsCols['language'])) {
            @$conn->query("ALTER TABLE `projects` ADD COLUMN `language` VARCHAR(10) NOT NULL DEFAULT 'ru'");
        } else {
            // Ensure language has NOT NULL and default 'ru'
            $lang = $projectsCols['language'];
            $needsFix = (strtoupper($lang['Null'] ?? '') === 'YES') || (($lang['Default'] ?? '') === null);
            if ($needsFix) {
                @$conn->query("ALTER TABLE `projects` MODIFY COLUMN `language` VARCHAR(10) NOT NULL DEFAULT 'ru'");
            }
        }
        if (!isset($projectsCols['wishes'])) {
            @$conn->query("ALTER TABLE `projects` ADD COLUMN `wishes` TEXT NULL");
        }
        // New: domain restriction host
        if (!isset($projectsCols['domain_host'])) {
            @$conn->query("ALTER TABLE `projects` ADD COLUMN `domain_host` VARCHAR(190) NULL AFTER `wishes`");
        }
        if (!isset($projectsCols['homepage_url'])) {
            @$conn->query("ALTER TABLE `projects` ADD COLUMN `homepage_url` TEXT NULL AFTER `domain_host`");
        }
        if (!isset($projectsCols['region'])) {
            @$conn->query("ALTER TABLE `projects` ADD COLUMN `region` VARCHAR(100) NULL");
        }
        if (!isset($projectsCols['topic'])) {
            @$conn->query("ALTER TABLE `projects` ADD COLUMN `topic` VARCHAR(100) NULL");
        }
    }

    // Users table: ensure balance column exists
    $usersCols = $getCols('users');
    if (!empty($usersCols) && !isset($usersCols['balance'])) {
        @$conn->query("ALTER TABLE `users` ADD COLUMN `balance` DECIMAL(12,2) NOT NULL DEFAULT 0");
    }
    if (!empty($usersCols) && !isset($usersCols['promotion_discount'])) {
        @$conn->query("ALTER TABLE `users` ADD COLUMN `promotion_discount` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `balance`");
        $usersCols = $getCols('users');
    }
    // Users table: add profile fields if missing
    if (!empty($usersCols)) {
        if (!isset($usersCols['full_name'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `full_name` VARCHAR(255) NULL AFTER `username`");
        }
        if (!isset($usersCols['email'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `email` VARCHAR(190) NULL AFTER `full_name`");
        }
        if (!isset($usersCols['phone'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(32) NULL AFTER `email`");
        }
        if (!isset($usersCols['avatar'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(255) NULL AFTER `phone`");
        }
        if (!isset($usersCols['newsletter_opt_in'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `newsletter_opt_in` TINYINT(1) NOT NULL DEFAULT 1 AFTER `avatar`");
        }
        // Google OAuth fields
        if (!isset($usersCols['google_id'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `google_id` VARCHAR(64) NULL AFTER `avatar`");
        }
        if (!isset($usersCols['google_picture'])) {
            @$conn->query("ALTER TABLE `users` ADD COLUMN `google_picture` VARCHAR(255) NULL AFTER `google_id`");
        }
        // Ensure unique index on google_id (compat with MySQL 5.7: no IF NOT EXISTS)
        if (pp_mysql_index_exists($conn, 'users', 'uniq_users_google_id') === false) {
            // Only create index when column exists
            $usersCols2 = $getCols('users');
            if (isset($usersCols2['google_id'])) {
                @$conn->query("CREATE UNIQUE INDEX `uniq_users_google_id` ON `users`(`google_id`)");
            }
        }
    }

    // Publications table for history
    $pubCols = $getCols('publications');
    if (empty($pubCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `publications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `project_id` INT NOT NULL,
            `page_url` TEXT NOT NULL,
            `anchor` VARCHAR(255) NULL,
            `network` VARCHAR(100) NULL,
            `published_by` VARCHAR(100) NULL,
            `enqueued_by_user_id` INT NULL,
            `post_url` TEXT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
            `scheduled_at` TIMESTAMP NULL DEFAULT NULL,
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `finished_at` TIMESTAMP NULL DEFAULT NULL,
            `attempts` INT NOT NULL DEFAULT 0,
            `error` TEXT NULL,
            `verification_status` VARCHAR(20) NULL,
            `verification_checked_at` TIMESTAMP NULL DEFAULT NULL,
            `verification_details` TEXT NULL,
            `cancel_requested` TINYINT(1) NOT NULL DEFAULT 0,
            `pid` INT NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (`project_id`),
            INDEX `idx_publications_status` (`status`),
            CONSTRAINT `fk_publications_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        if (!isset($pubCols['anchor'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `anchor` VARCHAR(255) NULL");
        }
        if (!isset($pubCols['network'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `network` VARCHAR(100) NULL");
        }
        if (!isset($pubCols['published_by'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `published_by` VARCHAR(100) NULL");
        }
        if (!isset($pubCols['enqueued_by_user_id'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `enqueued_by_user_id` INT NULL AFTER `published_by`");
        }
        if (!isset($pubCols['post_url'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `post_url` TEXT NULL");
        }
        if (!isset($pubCols['status'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'queued' AFTER `post_url`");
        }
        if (!isset($pubCols['scheduled_at'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `scheduled_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`");
        }
        if (!isset($pubCols['started_at'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `started_at` TIMESTAMP NULL DEFAULT NULL AFTER `scheduled_at`");
        }
        if (!isset($pubCols['finished_at'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `finished_at` TIMESTAMP NULL DEFAULT NULL AFTER `started_at`");
        }
        if (!isset($pubCols['attempts'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `attempts` INT NOT NULL DEFAULT 0 AFTER `finished_at`");
        }
        if (!isset($pubCols['error'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `error` TEXT NULL AFTER `attempts`");
        }
        if (!isset($pubCols['verification_status'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `verification_status` VARCHAR(20) NULL AFTER `error`");
        }
        if (!isset($pubCols['verification_checked_at'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `verification_checked_at` TIMESTAMP NULL DEFAULT NULL AFTER `verification_status`");
        }
        if (!isset($pubCols['verification_details'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `verification_details` TEXT NULL AFTER `verification_checked_at`");
        }
        // New: cancellation flag and process pid
        if (!isset($pubCols['cancel_requested'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `cancel_requested` TINYINT(1) NOT NULL DEFAULT 0 AFTER `verification_details`");
        }
        if (!isset($pubCols['pid'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `pid` INT NULL DEFAULT NULL AFTER `cancel_requested`");
        }
        if (!isset($pubCols['created_at'])) {
            @$conn->query("ALTER TABLE `publications` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
        // Ensure helpful index on status for queue scans
        if (pp_mysql_index_exists($conn, 'publications', 'idx_publications_status') === false && isset($pubCols['status'])) {
            @$conn->query("CREATE INDEX `idx_publications_status` ON `publications`(`status`)");
        }
    }

    // New: lightweight publication queue tracker (optional; mirrors publications queue for visibility and ordering per user)
    $pqCols = $getCols('publication_queue');
    if (empty($pqCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `publication_queue` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `publication_id` INT NOT NULL,
            `project_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `page_url` TEXT NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
            `scheduled_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (`project_id`),
            INDEX (`user_id`),
            INDEX `idx_pubqueue_status` (`status`),
            CONSTRAINT `fk_pubqueue_publication` FOREIGN KEY (`publication_id`) REFERENCES `publications`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_pubqueue_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        if (!isset($pqCols['publication_id'])) { @$conn->query("ALTER TABLE `publication_queue` ADD COLUMN `publication_id` INT NOT NULL"); }
        if (!isset($pqCols['project_id'])) { @$conn->query("ALTER TABLE `publication_queue` ADD COLUMN `project_id` INT NOT NULL"); }
        if (!isset($pqCols['user_id'])) { @$conn->query("ALTER TABLE `publication_queue` ADD COLUMN `user_id` INT NOT NULL"); }
        if (!isset($pqCols['page_url'])) { @$conn->query("ALTER TABLE `publication_queue` ADD COLUMN `page_url` TEXT NOT NULL"); }
        if (!isset($pqCols['status'])) { @$conn->query("ALTER TABLE `publication_queue` ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'queued'"); }
        if (!isset($pqCols['scheduled_at'])) { @$conn->query("ALTER TABLE `publication_queue` ADD COLUMN `scheduled_at` TIMESTAMP NULL DEFAULT NULL"); }
        if (!isset($pqCols['created_at'])) { @$conn->query("ALTER TABLE `publication_queue` ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP"); }
        if (pp_mysql_index_exists($conn, 'publication_queue', 'idx_pubqueue_status') === false && isset($pqCols['status'])) {
            @$conn->query("CREATE INDEX `idx_pubqueue_status` ON `publication_queue`(`status`)");
        }
    }

    // Networks registry tracks available publication handlers
    $netCols = $getCols('networks');
    if (empty($netCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `networks` (
            `slug` VARCHAR(120) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT NULL,
            `handler` VARCHAR(255) NOT NULL,
            `handler_type` VARCHAR(50) NOT NULL DEFAULT 'node',
            `meta` TEXT NULL,
            `regions` TEXT NULL,
            `topics` TEXT NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `priority` INT NOT NULL DEFAULT 0,
            `level` VARCHAR(50) NULL,
            `notes` TEXT NULL,
            `is_missing` TINYINT(1) NOT NULL DEFAULT 0,
            `last_check_status` VARCHAR(20) NULL,
            `last_check_run_id` INT NULL,
            `last_check_started_at` TIMESTAMP NULL DEFAULT NULL,
            `last_check_finished_at` TIMESTAMP NULL DEFAULT NULL,
            `last_check_url` TEXT NULL,
            `last_check_error` TEXT NULL,
            `last_check_updated_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $maybeAdd = function(string $field, string $definition) use ($netCols, $conn) {
            if (!isset($netCols[$field])) {
                @$conn->query("ALTER TABLE `networks` ADD COLUMN {$definition}");
            }
        };
        $maybeAdd('description', "`description` TEXT NULL AFTER `title`");
        $maybeAdd('handler', "`handler` VARCHAR(255) NOT NULL DEFAULT '' AFTER `description`");
        $maybeAdd('handler_type', "`handler_type` VARCHAR(50) NOT NULL DEFAULT 'node' AFTER `handler`");
    $maybeAdd('meta', "`meta` TEXT NULL AFTER `handler_type`");
    $maybeAdd('regions', "`regions` TEXT NULL AFTER `meta`");
    $maybeAdd('topics', "`topics` TEXT NULL AFTER `regions`");
    $maybeAdd('enabled', "`enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `topics`");
    $maybeAdd('priority', "`priority` INT NOT NULL DEFAULT 0 AFTER `enabled`");
    $maybeAdd('level', "`level` VARCHAR(50) NULL AFTER `priority`");
    $maybeAdd('notes', "`notes` TEXT NULL AFTER `level`");
    $maybeAdd('is_missing', "`is_missing` TINYINT(1) NOT NULL DEFAULT 0 AFTER `level`");
    $maybeAdd('last_check_status', "`last_check_status` VARCHAR(20) NULL AFTER `is_missing`");
    $maybeAdd('last_check_run_id', "`last_check_run_id` INT NULL AFTER `last_check_status`");
    $maybeAdd('last_check_started_at', "`last_check_started_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_check_run_id`");
    $maybeAdd('last_check_finished_at', "`last_check_finished_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_check_started_at`");
    $maybeAdd('last_check_url', "`last_check_url` TEXT NULL AFTER `last_check_finished_at`");
    $maybeAdd('last_check_error', "`last_check_error` TEXT NULL AFTER `last_check_url`");
    $maybeAdd('last_check_updated_at', "`last_check_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_check_error`");
    $maybeAdd('created_at', "`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `last_check_updated_at`");
    $maybeAdd('updated_at', "`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
    }

    // Network diagnostics (batch check runs + per-network results)
    $ncRunCols = $getCols('network_check_runs');
    if (empty($ncRunCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `network_check_runs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
            `total_networks` INT NOT NULL DEFAULT 0,
            `success_count` INT NOT NULL DEFAULT 0,
            `failure_count` INT NOT NULL DEFAULT 0,
            `run_mode` VARCHAR(20) NOT NULL DEFAULT 'bulk',
            `notes` TEXT NULL,
            `initiated_by` INT NULL,
            `cancel_requested` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `finished_at` TIMESTAMP NULL DEFAULT NULL,
            INDEX (`status`),
            INDEX `idx_nc_runs_started_at` (`started_at`),
            INDEX `idx_nc_runs_finished_at` (`finished_at`),
            CONSTRAINT `fk_nc_runs_user` FOREIGN KEY (`initiated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $ncRunCheck = function(string $field, string $definition) use ($ncRunCols, $conn) {
            if (!isset($ncRunCols[$field])) {
                @$conn->query("ALTER TABLE `network_check_runs` ADD COLUMN {$definition}");
            }
        };
        $ncRunCheck('status', "`status` VARCHAR(20) NOT NULL DEFAULT 'queued'");
        $ncRunCheck('total_networks', "`total_networks` INT NOT NULL DEFAULT 0");
        $ncRunCheck('success_count', "`success_count` INT NOT NULL DEFAULT 0");
        $ncRunCheck('failure_count', "`failure_count` INT NOT NULL DEFAULT 0");
        $ncRunCheck('notes', "`notes` TEXT NULL");
        $ncRunCheck('run_mode', "`run_mode` VARCHAR(20) NOT NULL DEFAULT 'bulk'");
        $ncRunCheck('cancel_requested', "`cancel_requested` TINYINT(1) NOT NULL DEFAULT 0");
        $ncRunCheck('initiated_by', "`initiated_by` INT NULL");
        $ncRunCheck('created_at', "`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $ncRunCheck('started_at', "`started_at` TIMESTAMP NULL DEFAULT NULL");
        $ncRunCheck('finished_at', "`finished_at` TIMESTAMP NULL DEFAULT NULL");
        if (pp_mysql_index_exists($conn, 'network_check_runs', 'idx_nc_runs_started_at') === false) {
            @$conn->query("CREATE INDEX `idx_nc_runs_started_at` ON `network_check_runs`(`started_at`)");
        }
        if (pp_mysql_index_exists($conn, 'network_check_runs', 'idx_nc_runs_finished_at') === false) {
            @$conn->query("CREATE INDEX `idx_nc_runs_finished_at` ON `network_check_runs`(`finished_at`)");
        }
        if (pp_mysql_index_exists($conn, 'network_check_runs', 'network_check_runs_status') === false) {
            @$conn->query("CREATE INDEX `network_check_runs_status` ON `network_check_runs`(`status`)");
        }
    }

    $ncResCols = $getCols('network_check_results');
    if (empty($ncResCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `network_check_results` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `run_id` INT NOT NULL,
            `network_slug` VARCHAR(120) NOT NULL,
            `network_title` VARCHAR(255) NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `finished_at` TIMESTAMP NULL DEFAULT NULL,
            `published_url` TEXT NULL,
            `error` TEXT NULL,
            `pid` INT NULL,
            `cancel_requested` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (`run_id`),
            INDEX `idx_nc_results_status` (`status`),
            INDEX `idx_nc_results_network` (`network_slug`),
            CONSTRAINT `fk_nc_results_run` FOREIGN KEY (`run_id`) REFERENCES `network_check_runs`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $ncResCheck = function(string $field, string $definition) use ($ncResCols, $conn) {
            if (!isset($ncResCols[$field])) {
                @$conn->query("ALTER TABLE `network_check_results` ADD COLUMN {$definition}");
            }
        };
        $ncResCheck('run_id', "`run_id` INT NOT NULL");
        $ncResCheck('network_slug', "`network_slug` VARCHAR(120) NOT NULL");
        $ncResCheck('network_title', "`network_title` VARCHAR(255) NOT NULL");
        $ncResCheck('status', "`status` VARCHAR(20) NOT NULL DEFAULT 'queued'");
        $ncResCheck('started_at', "`started_at` TIMESTAMP NULL DEFAULT NULL");
        $ncResCheck('finished_at', "`finished_at` TIMESTAMP NULL DEFAULT NULL");
        $ncResCheck('published_url', "`published_url` TEXT NULL");
    $ncResCheck('error', "`error` TEXT NULL");
    $ncResCheck('pid', "`pid` INT NULL");
    $ncResCheck('cancel_requested', "`cancel_requested` TINYINT(1) NOT NULL DEFAULT 0");
        $ncResCheck('created_at', "`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        if (pp_mysql_index_exists($conn, 'network_check_results', 'idx_nc_results_status') === false) {
            @$conn->query("CREATE INDEX `idx_nc_results_status` ON `network_check_results`(`status`)");
        }
        if (pp_mysql_index_exists($conn, 'network_check_results', 'idx_nc_results_network') === false) {
            @$conn->query("CREATE INDEX `idx_nc_results_network` ON `network_check_results`(`network_slug`)");
        }
    }

    // Crowd marketing links storage
    $crowdCols = $getCols('crowd_links');
    if (empty($crowdCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `crowd_links` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `url` TEXT NOT NULL,
            `url_hash` CHAR(40) NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `language` VARCHAR(16) NULL,
            `region` VARCHAR(16) NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `status_code` SMALLINT NULL DEFAULT NULL,
            `error` TEXT NULL,
            `form_required` TEXT NULL,
            `processing_run_id` INT NULL,
            `last_run_id` INT NULL,
            `deep_processing_run_id` INT NULL,
            `deep_last_run_id` INT NULL,
            `deep_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `deep_error` TEXT NULL,
            `deep_message_excerpt` TEXT NULL,
            `deep_evidence_url` TEXT NULL,
            `deep_checked_at` TIMESTAMP NULL DEFAULT NULL,
            `last_checked_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_crowd_links_hash` (`url_hash`),
            INDEX `idx_crowd_links_domain` (`domain`(191)),
            INDEX `idx_crowd_links_status` (`status`),
            INDEX `idx_crowd_links_processing` (`processing_run_id`),
            INDEX `idx_crowd_links_last_run` (`last_run_id`),
            INDEX `idx_crowd_links_deep_status` (`deep_status`),
            INDEX `idx_crowd_links_deep_processing` (`deep_processing_run_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $maybeAddCrowd = static function(string $field, string $ddl) use ($crowdCols, $conn) {
            if (!isset($crowdCols[$field])) {
                @$conn->query("ALTER TABLE `crowd_links` {$ddl}");
            }
        };
        $maybeAddCrowd('url_hash', "ADD COLUMN `url_hash` CHAR(40) NOT NULL AFTER `url`");
        $maybeAddCrowd('domain', "ADD COLUMN `domain` VARCHAR(255) NOT NULL AFTER `url_hash`");
        $maybeAddCrowd('language', "ADD COLUMN `language` VARCHAR(16) NULL AFTER `domain`");
        $maybeAddCrowd('region', "ADD COLUMN `region` VARCHAR(16) NULL AFTER `language`");
        $maybeAddCrowd('status', "ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER `region`");
        $maybeAddCrowd('status_code', "ADD COLUMN `status_code` SMALLINT NULL DEFAULT NULL AFTER `status`");
    $maybeAddCrowd('error', "ADD COLUMN `error` TEXT NULL AFTER `status_code`");
    $maybeAddCrowd('form_required', "ADD COLUMN `form_required` TEXT NULL AFTER `error`");
        $maybeAddCrowd('processing_run_id', "ADD COLUMN `processing_run_id` INT NULL AFTER `error`");
        $maybeAddCrowd('last_run_id', "ADD COLUMN `last_run_id` INT NULL AFTER `processing_run_id`");
        $maybeAddCrowd('deep_processing_run_id', "ADD COLUMN `deep_processing_run_id` INT NULL AFTER `last_run_id`");
        $maybeAddCrowd('deep_last_run_id', "ADD COLUMN `deep_last_run_id` INT NULL AFTER `deep_processing_run_id`");
        $maybeAddCrowd('deep_status', "ADD COLUMN `deep_status` VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER `deep_last_run_id`");
        $maybeAddCrowd('deep_error', "ADD COLUMN `deep_error` TEXT NULL AFTER `deep_status`");
        $maybeAddCrowd('deep_message_excerpt', "ADD COLUMN `deep_message_excerpt` TEXT NULL AFTER `deep_error`");
        $maybeAddCrowd('deep_evidence_url', "ADD COLUMN `deep_evidence_url` TEXT NULL AFTER `deep_message_excerpt`");
        $maybeAddCrowd('deep_checked_at', "ADD COLUMN `deep_checked_at` TIMESTAMP NULL DEFAULT NULL AFTER `deep_evidence_url`");
        $maybeAddCrowd('last_checked_at', "ADD COLUMN `last_checked_at` TIMESTAMP NULL DEFAULT NULL AFTER `deep_checked_at`");
        $maybeAddCrowd('created_at', "ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `last_checked_at`");
        $maybeAddCrowd('updated_at', "ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
        $crowdCols = $getCols('crowd_links');
        if (pp_mysql_index_exists($conn, 'crowd_links', 'uniq_crowd_links_hash') === false && isset($crowdCols['url_hash'])) {
            @$conn->query("CREATE UNIQUE INDEX `uniq_crowd_links_hash` ON `crowd_links`(`url_hash`)");
        }
        if (pp_mysql_index_exists($conn, 'crowd_links', 'idx_crowd_links_domain') === false && isset($crowdCols['domain'])) {
            @$conn->query("CREATE INDEX `idx_crowd_links_domain` ON `crowd_links`(`domain`(191))");
        }
        if (pp_mysql_index_exists($conn, 'crowd_links', 'idx_crowd_links_status') === false && isset($crowdCols['status'])) {
            @$conn->query("CREATE INDEX `idx_crowd_links_status` ON `crowd_links`(`status`)");
        }
        if (pp_mysql_index_exists($conn, 'crowd_links', 'idx_crowd_links_processing') === false && isset($crowdCols['processing_run_id'])) {
            @$conn->query("CREATE INDEX `idx_crowd_links_processing` ON `crowd_links`(`processing_run_id`)");
        }
        if (pp_mysql_index_exists($conn, 'crowd_links', 'idx_crowd_links_last_run') === false && isset($crowdCols['last_run_id'])) {
            @$conn->query("CREATE INDEX `idx_crowd_links_last_run` ON `crowd_links`(`last_run_id`)");
        }
        if (pp_mysql_index_exists($conn, 'crowd_links', 'idx_crowd_links_deep_status') === false && isset($crowdCols['deep_status'])) {
            @$conn->query("CREATE INDEX `idx_crowd_links_deep_status` ON `crowd_links`(`deep_status`)");
        }
        if (pp_mysql_index_exists($conn, 'crowd_links', 'idx_crowd_links_deep_processing') === false && isset($crowdCols['deep_processing_run_id'])) {
            @$conn->query("CREATE INDEX `idx_crowd_links_deep_processing` ON `crowd_links`(`deep_processing_run_id`)");
        }
    }

    $crowdRunCols = $getCols('crowd_link_runs');
    if (empty($crowdRunCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `crowd_link_runs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
            `scope` VARCHAR(20) NOT NULL DEFAULT 'all',
            `total_links` INT NOT NULL DEFAULT 0,
            `processed_count` INT NOT NULL DEFAULT 0,
            `ok_count` INT NOT NULL DEFAULT 0,
            `redirect_count` INT NOT NULL DEFAULT 0,
            `client_error_count` INT NOT NULL DEFAULT 0,
            `server_error_count` INT NOT NULL DEFAULT 0,
            `unreachable_count` INT NOT NULL DEFAULT 0,
            `initiated_by` INT NULL,
            `notes` TEXT NULL,
            `cancel_requested` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `finished_at` TIMESTAMP NULL DEFAULT NULL,
            `last_activity_at` TIMESTAMP NULL DEFAULT NULL,
            INDEX `idx_crowd_runs_status` (`status`),
            INDEX `idx_crowd_runs_created` (`created_at`),
            CONSTRAINT `fk_crowd_runs_user` FOREIGN KEY (`initiated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $maybeAddRun = static function(string $field, string $ddl) use ($crowdRunCols, $conn) {
            if (!isset($crowdRunCols[$field])) {
                @$conn->query("ALTER TABLE `crowd_link_runs` {$ddl}");
            }
        };
        $maybeAddRun('status', "ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'queued'");
        $maybeAddRun('scope', "ADD COLUMN `scope` VARCHAR(20) NOT NULL DEFAULT 'all' AFTER `status`");
        $maybeAddRun('total_links', "ADD COLUMN `total_links` INT NOT NULL DEFAULT 0 AFTER `scope`");
        $maybeAddRun('processed_count', "ADD COLUMN `processed_count` INT NOT NULL DEFAULT 0 AFTER `total_links`");
        $maybeAddRun('ok_count', "ADD COLUMN `ok_count` INT NOT NULL DEFAULT 0 AFTER `processed_count`");
        $maybeAddRun('redirect_count', "ADD COLUMN `redirect_count` INT NOT NULL DEFAULT 0 AFTER `ok_count`");
        $maybeAddRun('client_error_count', "ADD COLUMN `client_error_count` INT NOT NULL DEFAULT 0 AFTER `redirect_count`");
        $maybeAddRun('server_error_count', "ADD COLUMN `server_error_count` INT NOT NULL DEFAULT 0 AFTER `client_error_count`");
        $maybeAddRun('unreachable_count', "ADD COLUMN `unreachable_count` INT NOT NULL DEFAULT 0 AFTER `server_error_count`");
        $maybeAddRun('initiated_by', "ADD COLUMN `initiated_by` INT NULL AFTER `unreachable_count`");
        $maybeAddRun('notes', "ADD COLUMN `notes` TEXT NULL AFTER `initiated_by`");
        $maybeAddRun('cancel_requested', "ADD COLUMN `cancel_requested` TINYINT(1) NOT NULL DEFAULT 0 AFTER `notes`");
        $maybeAddRun('created_at', "ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `cancel_requested`");
        $maybeAddRun('started_at', "ADD COLUMN `started_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`");
        $maybeAddRun('finished_at', "ADD COLUMN `finished_at` TIMESTAMP NULL DEFAULT NULL AFTER `started_at`");
        $maybeAddRun('last_activity_at', "ADD COLUMN `last_activity_at` TIMESTAMP NULL DEFAULT NULL AFTER `finished_at`");
        $crowdRunCols = $getCols('crowd_link_runs');
        if (pp_mysql_index_exists($conn, 'crowd_link_runs', 'idx_crowd_runs_status') === false && isset($crowdRunCols['status'])) {
            @$conn->query("CREATE INDEX `idx_crowd_runs_status` ON `crowd_link_runs`(`status`)");
        }
        if (pp_mysql_index_exists($conn, 'crowd_link_runs', 'idx_crowd_runs_created') === false && isset($crowdRunCols['created_at'])) {
            @$conn->query("CREATE INDEX `idx_crowd_runs_created` ON `crowd_link_runs`(`created_at`)");
        }
    }

    // Deep crowd marketing validation run storage
    $crowdDeepRunCols = $getCols('crowd_deep_runs');
    if (empty($crowdDeepRunCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `crowd_deep_runs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `status` VARCHAR(20) NOT NULL DEFAULT 'queued',
            `scope` VARCHAR(20) NOT NULL DEFAULT 'all',
            `total_links` INT NOT NULL DEFAULT 0,
            `processed_count` INT NOT NULL DEFAULT 0,
            `success_count` INT NOT NULL DEFAULT 0,
            `partial_count` INT NOT NULL DEFAULT 0,
            `failed_count` INT NOT NULL DEFAULT 0,
            `skipped_count` INT NOT NULL DEFAULT 0,
            `message_template` TEXT NULL,
            `message_url` TEXT NULL,
            `options_json` TEXT NULL,
            `token_prefix` VARCHAR(32) NULL,
            `initiated_by` INT NULL,
            `notes` TEXT NULL,
            `cancel_requested` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `finished_at` TIMESTAMP NULL DEFAULT NULL,
            `last_activity_at` TIMESTAMP NULL DEFAULT NULL,
            INDEX `idx_crowd_deep_runs_status` (`status`),
            INDEX `idx_crowd_deep_runs_created` (`created_at`),
            CONSTRAINT `fk_crowd_deep_runs_user` FOREIGN KEY (`initiated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $maybeAddDeepRun = static function(string $field, string $ddl) use ($crowdDeepRunCols, $conn) {
            if (!isset($crowdDeepRunCols[$field])) {
                @$conn->query("ALTER TABLE `crowd_deep_runs` {$ddl}");
            }
        };
        $maybeAddDeepRun('status', "ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'queued'");
        $maybeAddDeepRun('scope', "ADD COLUMN `scope` VARCHAR(20) NOT NULL DEFAULT 'all' AFTER `status`");
        $maybeAddDeepRun('total_links', "ADD COLUMN `total_links` INT NOT NULL DEFAULT 0 AFTER `scope`");
        $maybeAddDeepRun('processed_count', "ADD COLUMN `processed_count` INT NOT NULL DEFAULT 0 AFTER `total_links`");
        $maybeAddDeepRun('success_count', "ADD COLUMN `success_count` INT NOT NULL DEFAULT 0 AFTER `processed_count`");
        $maybeAddDeepRun('partial_count', "ADD COLUMN `partial_count` INT NOT NULL DEFAULT 0 AFTER `success_count`");
        $maybeAddDeepRun('failed_count', "ADD COLUMN `failed_count` INT NOT NULL DEFAULT 0 AFTER `partial_count`");
        $maybeAddDeepRun('skipped_count', "ADD COLUMN `skipped_count` INT NOT NULL DEFAULT 0 AFTER `failed_count`");
        $maybeAddDeepRun('message_template', "ADD COLUMN `message_template` TEXT NULL AFTER `skipped_count`");
        $maybeAddDeepRun('message_url', "ADD COLUMN `message_url` TEXT NULL AFTER `message_template`");
        $maybeAddDeepRun('options_json', "ADD COLUMN `options_json` TEXT NULL AFTER `message_url`");
        $maybeAddDeepRun('token_prefix', "ADD COLUMN `token_prefix` VARCHAR(32) NULL AFTER `options_json`");
        $maybeAddDeepRun('initiated_by', "ADD COLUMN `initiated_by` INT NULL AFTER `token_prefix`");
        $maybeAddDeepRun('notes', "ADD COLUMN `notes` TEXT NULL AFTER `initiated_by`");
        $maybeAddDeepRun('cancel_requested', "ADD COLUMN `cancel_requested` TINYINT(1) NOT NULL DEFAULT 0 AFTER `notes`");
        $maybeAddDeepRun('created_at', "ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `cancel_requested`");
        $maybeAddDeepRun('started_at', "ADD COLUMN `started_at` TIMESTAMP NULL DEFAULT NULL AFTER `created_at`");
        $maybeAddDeepRun('finished_at', "ADD COLUMN `finished_at` TIMESTAMP NULL DEFAULT NULL AFTER `started_at`");
        $maybeAddDeepRun('last_activity_at', "ADD COLUMN `last_activity_at` TIMESTAMP NULL DEFAULT NULL AFTER `finished_at`");
        $crowdDeepRunCols = $getCols('crowd_deep_runs');
        if (pp_mysql_index_exists($conn, 'crowd_deep_runs', 'idx_crowd_deep_runs_status') === false && isset($crowdDeepRunCols['status'])) {
            @$conn->query("CREATE INDEX `idx_crowd_deep_runs_status` ON `crowd_deep_runs`(`status`)");
        }
        if (pp_mysql_index_exists($conn, 'crowd_deep_runs', 'idx_crowd_deep_runs_created') === false && isset($crowdDeepRunCols['created_at'])) {
            @$conn->query("CREATE INDEX `idx_crowd_deep_runs_created` ON `crowd_deep_runs`(`created_at`)");
        }
    }

    $crowdDeepResCols = $getCols('crowd_deep_results');
    if (empty($crowdDeepResCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `crowd_deep_results` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `run_id` INT NOT NULL,
            `link_id` BIGINT UNSIGNED NOT NULL,
            `url` TEXT NOT NULL,
            `status` VARCHAR(20) NOT NULL,
            `http_status` INT NULL,
            `final_url` TEXT NULL,
            `message_token` VARCHAR(64) NULL,
            `message_excerpt` TEXT NULL,
            `response_excerpt` TEXT NULL,
            `evidence_url` TEXT NULL,
            `request_payload` TEXT NULL,
            `duration_ms` INT NULL,
            `error` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_crowd_deep_results_run` (`run_id`),
            INDEX `idx_crowd_deep_results_link` (`link_id`),
            INDEX `idx_crowd_deep_status` (`status`),
            CONSTRAINT `fk_crowd_deep_results_run` FOREIGN KEY (`run_id`) REFERENCES `crowd_deep_runs`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_crowd_deep_results_link` FOREIGN KEY (`link_id`) REFERENCES `crowd_links`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $maybeAddDeepResult = static function(string $field, string $ddl) use ($crowdDeepResCols, $conn) {
            if (!isset($crowdDeepResCols[$field])) {
                @$conn->query("ALTER TABLE `crowd_deep_results` {$ddl}");
            }
        };
        $maybeAddDeepResult('run_id', "ADD COLUMN `run_id` INT NOT NULL");
        $maybeAddDeepResult('link_id', "ADD COLUMN `link_id` BIGINT UNSIGNED NOT NULL AFTER `run_id`");
        $maybeAddDeepResult('url', "ADD COLUMN `url` TEXT NOT NULL AFTER `link_id`");
        $maybeAddDeepResult('status', "ADD COLUMN `status` VARCHAR(20) NOT NULL AFTER `url`");
        $maybeAddDeepResult('http_status', "ADD COLUMN `http_status` INT NULL AFTER `status`");
        $maybeAddDeepResult('final_url', "ADD COLUMN `final_url` TEXT NULL AFTER `http_status`");
        $maybeAddDeepResult('message_token', "ADD COLUMN `message_token` VARCHAR(64) NULL AFTER `final_url`");
        $maybeAddDeepResult('message_excerpt', "ADD COLUMN `message_excerpt` TEXT NULL AFTER `message_token`");
        $maybeAddDeepResult('response_excerpt', "ADD COLUMN `response_excerpt` TEXT NULL AFTER `message_excerpt`");
        $maybeAddDeepResult('evidence_url', "ADD COLUMN `evidence_url` TEXT NULL AFTER `response_excerpt`");
        $maybeAddDeepResult('request_payload', "ADD COLUMN `request_payload` TEXT NULL AFTER `evidence_url`");
        $maybeAddDeepResult('duration_ms', "ADD COLUMN `duration_ms` INT NULL AFTER `request_payload`");
        $maybeAddDeepResult('error', "ADD COLUMN `error` TEXT NULL AFTER `duration_ms`");
        $maybeAddDeepResult('created_at', "ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        $maybeAddDeepResult('updated_at', "ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        if (pp_mysql_index_exists($conn, 'crowd_deep_results', 'idx_crowd_deep_status') === false && isset($crowdDeepResCols['status'])) {
            @$conn->query("CREATE INDEX `idx_crowd_deep_status` ON `crowd_deep_results`(`status`)");
        }
        if (pp_mysql_index_exists($conn, 'crowd_deep_results', 'idx_crowd_deep_results_run') === false && isset($crowdDeepResCols['run_id'])) {
            @$conn->query("CREATE INDEX `idx_crowd_deep_results_run` ON `crowd_deep_results`(`run_id`)");
        }
        if (pp_mysql_index_exists($conn, 'crowd_deep_results', 'idx_crowd_deep_results_link') === false && isset($crowdDeepResCols['link_id'])) {
            @$conn->query("CREATE INDEX `idx_crowd_deep_results_link` ON `crowd_deep_results`(`link_id`)");
        }
    }

    // New: page metadata storage (microdata extracted for links)
    $pmCols = $getCols('page_meta');
    if (empty($pmCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `page_meta` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `project_id` INT NOT NULL,
            `url_hash` CHAR(64) NOT NULL,
            `page_url` TEXT NOT NULL,
            `final_url` TEXT NULL,
            `lang` VARCHAR(16) NULL,
            `region` VARCHAR(16) NULL,
            `title` VARCHAR(512) NULL,
            `description` TEXT NULL,
            `canonical` TEXT NULL,
            `published_time` VARCHAR(64) NULL,
            `modified_time` VARCHAR(64) NULL,
            `hreflang_json` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_page_meta_proj_hash` (`project_id`, `url_hash`),
            INDEX (`project_id`),
            CONSTRAINT `fk_page_meta_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        // Ensure critical columns exist (best-effort; ignore errors)
        foreach ([
            'url_hash' => "ADD COLUMN `url_hash` CHAR(64) NOT NULL AFTER `project_id`",
            'page_url' => "ADD COLUMN `page_url` TEXT NOT NULL AFTER `url_hash`",
            'final_url' => "ADD COLUMN `final_url` TEXT NULL AFTER `page_url`",
            'lang' => "ADD COLUMN `lang` VARCHAR(16) NULL AFTER `final_url`",
            'region' => "ADD COLUMN `region` VARCHAR(16) NULL AFTER `lang`",
            'title' => "ADD COLUMN `title` VARCHAR(512) NULL AFTER `region`",
            'description' => "ADD COLUMN `description` TEXT NULL AFTER `title`",
            'canonical' => "ADD COLUMN `canonical` TEXT NULL AFTER `description`",
            'published_time' => "ADD COLUMN `published_time` VARCHAR(64) NULL AFTER `canonical`",
            'modified_time' => "ADD COLUMN `modified_time` VARCHAR(64) NULL AFTER `published_time`",
            'hreflang_json' => "ADD COLUMN `hreflang_json` TEXT NULL AFTER `modified_time`",
            'created_at' => "ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ] as $field => $ddl) {
            if (!isset($pmCols[$field])) { @($conn->query("ALTER TABLE `page_meta` {$ddl}")); }
        }
        // Ensure unique index if missing
        if (pp_mysql_index_exists($conn, 'page_meta', 'uniq_page_meta_proj_hash') === false) {
            @($conn->query("CREATE UNIQUE INDEX `uniq_page_meta_proj_hash` ON `page_meta`(`project_id`,`url_hash`)"));
        }
    }

    // New: normalized links storage (separate rows instead of JSON in projects.links)
    $plCols = $getCols('project_links');
    if (empty($plCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `project_links` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `project_id` INT NOT NULL,
            `url` TEXT NOT NULL,
            `anchor` VARCHAR(255) NULL,
            `language` VARCHAR(10) NOT NULL DEFAULT 'ru',
            `wish` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (`project_id`),
            CONSTRAINT `fk_project_links_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    // Best-effort one-time migration from projects.links JSON to project_links
    try {
        if (!empty($plCols)) {
            if ($res = @$conn->query("SELECT id, language, links FROM projects WHERE links IS NOT NULL AND TRIM(links) <> ''")) {
                while ($row = $res->fetch_assoc()) {
                    $pid = (int)$row['id'];
                    // Skip if already migrated (rows exist)
                    $cntRes = @$conn->query("SELECT COUNT(*) AS c FROM project_links WHERE project_id = " . (int)$pid);
                    $doMigrate = true;
                    if ($cntRes && ($cntRow = $cntRes->fetch_assoc())) { $doMigrate = ((int)$cntRow['c'] === 0); }
                    if ($cntRes) { $cntRes->free(); }
                    if (!$doMigrate) { continue; }
                    $linksJson = (string)$row['links'];
                    $defaultLang = trim((string)$row['language'] ?? 'ru');
                    $arr = json_decode($linksJson, true);
                    if (!is_array($arr)) { continue; }
                    $stmt = $conn->prepare("INSERT INTO project_links (project_id, url, anchor, language, wish) VALUES (?, ?, ?, ?, ?)");
                    if ($stmt) {
                        foreach ($arr as $it) {
                            $url = '';
                            $anchor = '';
                            $lang = $defaultLang ?: 'ru';
                            $wish = '';
                            if (is_string($it)) { $url = trim($it); }
                            elseif (is_array($it)) {
                                $url = trim((string)($it['url'] ?? ''));
                                $anchor = trim((string)($it['anchor'] ?? ''));
                                $lang = trim((string)($it['language'] ?? $lang)) ?: ($defaultLang ?: 'ru');
                                $wish = trim((string)($it['wish'] ?? ''));
                            }
                            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                                $stmt->bind_param('issss', $pid, $url, $anchor, $lang, $wish);
                                @$stmt->execute();
                            }
                        }
                        $stmt->close();
                    }
                }
                $res->free();
            }
        }
    } catch (Throwable $e) { /* ignore migration errors */ }

    // Payment gateways configuration storage
    $pgCols = $getCols('payment_gateways');
    if (empty($pgCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `payment_gateways` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `code` VARCHAR(60) NOT NULL,
            `title` VARCHAR(191) NOT NULL,
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
            `config` LONGTEXT NULL,
            `instructions` LONGTEXT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_payment_gateways_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $maybeAddPg = static function(string $field, string $ddl) use ($pgCols, $conn) {
            if (!isset($pgCols[$field])) {
                @$conn->query("ALTER TABLE `payment_gateways` {$ddl}");
            }
        };
        $maybeAddPg('title', "ADD COLUMN `title` VARCHAR(191) NOT NULL DEFAULT '' AFTER `code`");
        $maybeAddPg('is_enabled', "ADD COLUMN `is_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `title`");
        $maybeAddPg('config', "ADD COLUMN `config` LONGTEXT NULL AFTER `is_enabled`");
        $maybeAddPg('instructions', "ADD COLUMN `instructions` LONGTEXT NULL AFTER `config`");
        $maybeAddPg('sort_order', "ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `instructions`");
        $maybeAddPg('created_at', "ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `sort_order`");
        $maybeAddPg('updated_at', "ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
        $pgCols = $getCols('payment_gateways');
        if (pp_mysql_index_exists($conn, 'payment_gateways', 'uniq_payment_gateways_code') === false && isset($pgCols['code'])) {
            @$conn->query("CREATE UNIQUE INDEX `uniq_payment_gateways_code` ON `payment_gateways`(`code`)");
        }
    }

    // Payment transactions storage
    $ptCols = $getCols('payment_transactions');
    if (empty($ptCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `payment_transactions` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `gateway_code` VARCHAR(60) NOT NULL,
            `amount` DECIMAL(16,2) NOT NULL,
            `currency` VARCHAR(10) NOT NULL,
            `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
            `provider_reference` VARCHAR(191) NULL,
            `provider_payload` LONGTEXT NULL,
            `customer_payload` LONGTEXT NULL,
            `error_message` TEXT NULL,
            `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_payment_tx_user` (`user_id`),
            INDEX `idx_payment_tx_status` (`status`),
            INDEX `idx_payment_tx_gateway` (`gateway_code`),
            INDEX `idx_payment_tx_reference` (`provider_reference`),
            CONSTRAINT `fk_payment_tx_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $maybeAddPt = static function(string $field, string $ddl) use ($ptCols, $conn) {
            if (!isset($ptCols[$field])) {
                @$conn->query("ALTER TABLE `payment_transactions` {$ddl}");
            }
        };
        $maybeAddPt('currency', "ADD COLUMN `currency` VARCHAR(10) NOT NULL DEFAULT 'UAH' AFTER `amount`");
        $maybeAddPt('provider_reference', "ADD COLUMN `provider_reference` VARCHAR(191) NULL AFTER `status`");
        $maybeAddPt('provider_payload', "ADD COLUMN `provider_payload` LONGTEXT NULL AFTER `provider_reference`");
        $maybeAddPt('customer_payload', "ADD COLUMN `customer_payload` LONGTEXT NULL AFTER `provider_payload`");
        $maybeAddPt('error_message', "ADD COLUMN `error_message` TEXT NULL AFTER `customer_payload`");
        $maybeAddPt('confirmed_at', "ADD COLUMN `confirmed_at` TIMESTAMP NULL DEFAULT NULL AFTER `error_message`");
        $maybeAddPt('created_at', "ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `confirmed_at`");
        $maybeAddPt('updated_at', "ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
        if (!isset($ptCols['user_id'])) {
            @$conn->query("ALTER TABLE `payment_transactions` ADD COLUMN `user_id` INT NOT NULL AFTER `id`");
        }
        $ptCols = $getCols('payment_transactions');
        if (pp_mysql_index_exists($conn, 'payment_transactions', 'idx_payment_tx_user') === false && isset($ptCols['user_id'])) {
            @$conn->query("CREATE INDEX `idx_payment_tx_user` ON `payment_transactions`(`user_id`)");
        }
        if (pp_mysql_index_exists($conn, 'payment_transactions', 'idx_payment_tx_status') === false && isset($ptCols['status'])) {
            @$conn->query("CREATE INDEX `idx_payment_tx_status` ON `payment_transactions`(`status`)");
        }
        if (pp_mysql_index_exists($conn, 'payment_transactions', 'idx_payment_tx_gateway') === false && isset($ptCols['gateway_code'])) {
            @$conn->query("CREATE INDEX `idx_payment_tx_gateway` ON `payment_transactions`(`gateway_code`)");
        }
        if (pp_mysql_index_exists($conn, 'payment_transactions', 'idx_payment_tx_reference') === false && isset($ptCols['provider_reference'])) {
            @$conn->query("CREATE INDEX `idx_payment_tx_reference` ON `payment_transactions`(`provider_reference`)");
        }
    }

    // Seed default payment gateways
    try {
        $defaults = [
            ['code' => 'monobank', 'title' => 'Monobank', 'sort_order' => 10],
            ['code' => 'binance', 'title' => 'Binance Pay (USDT TRC20)', 'sort_order' => 20],
        ];
        foreach ($defaults as $def) {
            $codeEsc = $conn->real_escape_string($def['code']);
            $titleEsc = $conn->real_escape_string($def['title']);
            $sort = (int)$def['sort_order'];
            @$conn->query("INSERT IGNORE INTO `payment_gateways` (`code`,`title`,`is_enabled`,`config`,`instructions`,`sort_order`,`created_at`,`updated_at`) VALUES ('{$codeEsc}','{$titleEsc}',0,'{}','',{$sort},CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        }
    } catch (Throwable $e) { /* ignore seeding errors */ }

    // Promotion cascade tables
    $promoRunsCols = $getCols('promotion_runs');
    if (empty($promoRunsCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `promotion_runs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `project_id` INT NOT NULL,
            `link_id` INT NOT NULL,
            `target_url` TEXT NOT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'queued',
            `stage` VARCHAR(32) NOT NULL DEFAULT 'pending_level1',
            `initiated_by` INT NULL,
            `settings_snapshot` TEXT NULL,
            `charged_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
            `progress_total` INT NOT NULL DEFAULT 0,
            `progress_done` INT NOT NULL DEFAULT 0,
            `error` TEXT NULL,
            `report_json` LONGTEXT NULL,
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `finished_at` TIMESTAMP NULL DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_promotion_runs_project` (`project_id`),
            INDEX `idx_promotion_runs_link` (`link_id`),
            INDEX `idx_promotion_runs_status` (`status`),
            CONSTRAINT `fk_promotion_runs_project` FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_promotion_runs_link` FOREIGN KEY (`link_id`) REFERENCES `project_links`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $ensureRunCol = static function(string $column, string $ddl) use ($promoRunsCols, $conn): void {
            if (!isset($promoRunsCols[$column])) { @($conn->query("ALTER TABLE `promotion_runs` {$ddl}")); }
        };
        $ensureRunCol('stage', "ADD COLUMN `stage` VARCHAR(32) NOT NULL DEFAULT 'pending_level1' AFTER `status`");
        $ensureRunCol('initiated_by', "ADD COLUMN `initiated_by` INT NULL AFTER `stage`");
        $ensureRunCol('settings_snapshot', "ADD COLUMN `settings_snapshot` TEXT NULL AFTER `initiated_by`");
    $ensureRunCol('charged_amount', "ADD COLUMN `charged_amount` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `settings_snapshot`");
    $ensureRunCol('discount_percent', "ADD COLUMN `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `charged_amount`");
    $ensureRunCol('progress_total', "ADD COLUMN `progress_total` INT NOT NULL DEFAULT 0 AFTER `discount_percent`");
        $ensureRunCol('progress_done', "ADD COLUMN `progress_done` INT NOT NULL DEFAULT 0 AFTER `progress_total`");
        $ensureRunCol('error', "ADD COLUMN `error` TEXT NULL AFTER `progress_done`");
        $ensureRunCol('report_json', "ADD COLUMN `report_json` LONGTEXT NULL AFTER `error`");
        $ensureRunCol('started_at', "ADD COLUMN `started_at` TIMESTAMP NULL DEFAULT NULL AFTER `report_json`");
        $ensureRunCol('finished_at', "ADD COLUMN `finished_at` TIMESTAMP NULL DEFAULT NULL AFTER `started_at`");
        $ensureRunCol('updated_at', "ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
        if (pp_mysql_index_exists($conn, 'promotion_runs', 'idx_promotion_runs_status') === false) {
            @$conn->query("CREATE INDEX `idx_promotion_runs_status` ON `promotion_runs`(`status`)");
        }
        if (pp_mysql_index_exists($conn, 'promotion_runs', 'idx_promotion_runs_project') === false) {
            @$conn->query("CREATE INDEX `idx_promotion_runs_project` ON `promotion_runs`(`project_id`)");
        }
        if (pp_mysql_index_exists($conn, 'promotion_runs', 'idx_promotion_runs_link') === false) {
            @$conn->query("CREATE INDEX `idx_promotion_runs_link` ON `promotion_runs`(`link_id`)");
        }
    }

    $promoNodesCols = $getCols('promotion_nodes');
    if (empty($promoNodesCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `promotion_nodes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `run_id` INT NOT NULL,
            `level` INT NOT NULL DEFAULT 1,
            `parent_id` INT NULL,
            `target_url` TEXT NOT NULL,
            `result_url` TEXT NULL,
            `network_slug` VARCHAR(100) NOT NULL,
            `publication_id` INT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
            `anchor_text` VARCHAR(255) NULL,
            `initiated_by` INT NULL,
            `queued_at` TIMESTAMP NULL DEFAULT NULL,
            `started_at` TIMESTAMP NULL DEFAULT NULL,
            `finished_at` TIMESTAMP NULL DEFAULT NULL,
            `error` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_promotion_nodes_run` (`run_id`),
            INDEX `idx_promotion_nodes_publication` (`publication_id`),
            INDEX `idx_promotion_nodes_status` (`status`),
            CONSTRAINT `fk_promotion_nodes_run` FOREIGN KEY (`run_id`) REFERENCES `promotion_runs`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_promotion_nodes_parent` FOREIGN KEY (`parent_id`) REFERENCES `promotion_nodes`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $ensureNodeCol = static function(string $column, string $ddl) use ($promoNodesCols, $conn): void {
            if (!isset($promoNodesCols[$column])) { @($conn->query("ALTER TABLE `promotion_nodes` {$ddl}")); }
        };
        $ensureNodeCol('parent_id', "ADD COLUMN `parent_id` INT NULL AFTER `level`");
        $ensureNodeCol('target_url', "ADD COLUMN `target_url` TEXT NOT NULL AFTER `parent_id`");
        $ensureNodeCol('result_url', "ADD COLUMN `result_url` TEXT NULL AFTER `target_url`");
        $ensureNodeCol('network_slug', "ADD COLUMN `network_slug` VARCHAR(100) NOT NULL AFTER `result_url`");
        $ensureNodeCol('publication_id', "ADD COLUMN `publication_id` INT NULL AFTER `network_slug`");
        $ensureNodeCol('status', "ADD COLUMN `status` VARCHAR(32) NOT NULL DEFAULT 'pending' AFTER `publication_id`");
        $ensureNodeCol('anchor_text', "ADD COLUMN `anchor_text` VARCHAR(255) NULL AFTER `status`");
        $ensureNodeCol('initiated_by', "ADD COLUMN `initiated_by` INT NULL AFTER `anchor_text`");
        $ensureNodeCol('queued_at', "ADD COLUMN `queued_at` TIMESTAMP NULL DEFAULT NULL AFTER `initiated_by`");
        $ensureNodeCol('started_at', "ADD COLUMN `started_at` TIMESTAMP NULL DEFAULT NULL AFTER `queued_at`");
        $ensureNodeCol('finished_at', "ADD COLUMN `finished_at` TIMESTAMP NULL DEFAULT NULL AFTER `started_at`");
        $ensureNodeCol('error', "ADD COLUMN `error` TEXT NULL AFTER `finished_at`");
        $ensureNodeCol('updated_at', "ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
        if (pp_mysql_index_exists($conn, 'promotion_nodes', 'idx_promotion_nodes_run') === false) {
            @$conn->query("CREATE INDEX `idx_promotion_nodes_run` ON `promotion_nodes`(`run_id`)");
        }
        if (pp_mysql_index_exists($conn, 'promotion_nodes', 'idx_promotion_nodes_publication') === false) {
            @$conn->query("CREATE INDEX `idx_promotion_nodes_publication` ON `promotion_nodes`(`publication_id`)");
        }
        if (pp_mysql_index_exists($conn, 'promotion_nodes', 'idx_promotion_nodes_status') === false) {
            @$conn->query("CREATE INDEX `idx_promotion_nodes_status` ON `promotion_nodes`(`status`)");
        }
    }

    $promoCrowdCols = $getCols('promotion_crowd_tasks');
    if (empty($promoCrowdCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `promotion_crowd_tasks` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `run_id` INT NOT NULL,
            `node_id` INT NULL,
            `crowd_link_id` INT NULL,
            `target_url` TEXT NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'planned',
            `result_url` TEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_promotion_crowd_run` (`run_id`),
            INDEX `idx_promotion_crowd_node` (`node_id`),
            CONSTRAINT `fk_promotion_crowd_run` FOREIGN KEY (`run_id`) REFERENCES `promotion_runs`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_promotion_crowd_node` FOREIGN KEY (`node_id`) REFERENCES `promotion_nodes`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $ensureCrowdCol = static function(string $column, string $ddl) use ($promoCrowdCols, $conn): void {
            if (!isset($promoCrowdCols[$column])) { @($conn->query("ALTER TABLE `promotion_crowd_tasks` {$ddl}")); }
        };
        $ensureCrowdCol('node_id', "ADD COLUMN `node_id` INT NULL AFTER `run_id`");
        $ensureCrowdCol('crowd_link_id', "ADD COLUMN `crowd_link_id` INT NULL AFTER `node_id`");
        $ensureCrowdCol('target_url', "ADD COLUMN `target_url` TEXT NOT NULL AFTER `crowd_link_id`");
        $ensureCrowdCol('status', "ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'planned' AFTER `target_url`");
        $ensureCrowdCol('result_url', "ADD COLUMN `result_url` TEXT NULL AFTER `status`");
        $ensureCrowdCol('updated_at', "ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
        if (pp_mysql_index_exists($conn, 'promotion_crowd_tasks', 'idx_promotion_crowd_run') === false) {
            @$conn->query("CREATE INDEX `idx_promotion_crowd_run` ON `promotion_crowd_tasks`(`run_id`)");
        }
        if (pp_mysql_index_exists($conn, 'promotion_crowd_tasks', 'idx_promotion_crowd_node') === false) {
            @$conn->query("CREATE INDEX `idx_promotion_crowd_node` ON `promotion_crowd_tasks`(`node_id`)");
        }
    }

    // Settings table optional—skip if missing

    @$conn->close();

    try { pp_refresh_networks(false); } catch (Throwable $e) { /* ignore */ }
}

// The rest of the file keeps ensure_schema and domain-specific models.

// ---------- Publication networks helpers ----------

// Networks helpers moved to includes/networks.php

// New: aggregate networks taxonomy (regions/topics)
// See includes/networks.php

// See includes/networks.php

// See includes/networks.php

// See includes/networks.php
if (!function_exists('pp_refresh_networks')) {
function pp_refresh_networks(bool $force = false): array {
    if (!$force) {
        $last = (int)get_setting('networks_last_refresh', 0);
        if ($last && (time() - $last) < 300) {
            return pp_get_networks(false, true);
        }
    }

    $dir = pp_networks_dir();
    $files = glob($dir . '/*.php') ?: [];
    $descriptors = [];
    foreach ($files as $file) {
        $descriptor = pp_network_descriptor_from_file($file);
        if ($descriptor) {
            $descriptors[$descriptor['slug']] = $descriptor;
        }
    }

    $conn = null;
    try {
        $conn = @connect_db();
    } catch (Throwable $e) {
        $conn = null;
    }
    if (!$conn) { return array_values($descriptors); }

    // snapshot existing enabled flags
    $existing = [];
    if ($res = @$conn->query("SELECT slug, enabled, priority, level, notes FROM networks")) {
        while ($row = $res->fetch_assoc()) {
            $existing[$row['slug']] = [
                'enabled' => (int)($row['enabled'] ?? 0),
                'priority' => (int)($row['priority'] ?? 0),
                'level' => (string)($row['level'] ?? ''),
                'notes' => (string)($row['notes'] ?? ''),
            ];
        }
        $res->free();
    }

    $defaultPrioritySetting = (int)get_setting('network_default_priority', 10);
    if ($defaultPrioritySetting < 0) { $defaultPrioritySetting = 0; }
    if ($defaultPrioritySetting > 999) { $defaultPrioritySetting = 999; }
    $defaultLevelsSetting = pp_normalize_network_levels(get_setting('network_default_levels', ''));

    $stmt = $conn->prepare("INSERT INTO networks (slug, title, description, handler, handler_type, meta, regions, topics, enabled, priority, level, notes, is_missing) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), handler = VALUES(handler), handler_type = VALUES(handler_type), meta = VALUES(meta), regions = VALUES(regions), topics = VALUES(topics), is_missing = 0, updated_at = CURRENT_TIMESTAMP");
    if ($stmt) {
        foreach ($descriptors as $slug => $descriptor) {
            $enabled = $descriptor['enabled'] ? 1 : 0;
            $priority = (int)($descriptor['priority'] ?? 0);
            $level = trim((string)($descriptor['level'] ?? ''));
            $notes = '';
            if (array_key_exists($slug, $existing)) {
                $enabled = (int)$existing[$slug]['enabled'];
                $priority = (int)$existing[$slug]['priority'];
                $level = (string)$existing[$slug]['level'];
                $notes = (string)$existing[$slug]['notes'];
            } else { $priority = $defaultPrioritySetting; $level = $defaultLevelsSetting; }
            if ($priority < 0) { $priority = 0; }
            if ($priority > 999) { $priority = 999; }
            $level = pp_normalize_network_levels($level);
            if ($notes !== '') { $notes = function_exists('mb_substr') ? mb_substr($notes, 0, 2000, 'UTF-8') : substr($notes, 0, 2000); }
            $metaJson = json_encode($descriptor['meta'], JSON_UNESCAPED_UNICODE);
            $regionsArr = [];
            $topicsArr = [];
            $meta = $descriptor['meta'] ?? [];
            $rawRegions = $meta['regions'] ?? [];
            if (is_string($rawRegions)) { $rawRegions = [$rawRegions]; }
            if (is_array($rawRegions)) { foreach ($rawRegions as $reg) { $val = trim((string)$reg); if ($val !== '') { $regionsArr[$val] = $val; } } }
            $rawTopics = $meta['topics'] ?? [];
            if (is_string($rawTopics)) { $rawTopics = [$rawTopics]; }
            if (is_array($rawTopics)) { foreach ($rawTopics as $topic) { $val = trim((string)$topic); if ($val !== '') { $topicsArr[$val] = $val; } } }
            $regionsStr = implode(', ', array_values($regionsArr));
            $topicsStr = implode(', ', array_values($topicsArr));
            $stmt->bind_param(
                'ssssssssiiss',
                $descriptor['slug'],
                $descriptor['title'],
                $descriptor['description'],
                $descriptor['handler_rel'],
                $descriptor['handler_type'],
                $metaJson,
                $regionsStr,
                $topicsStr,
                $enabled,
                $priority,
                $level,
                $notes
            );
            $stmt->execute();
        }
        $stmt->close();
    }

    $knownSlugs = array_keys($descriptors);
    if (!empty($knownSlugs)) {
        $placeholders = implode(',', array_fill(0, count($knownSlugs), '?'));
        $query = $conn->prepare("UPDATE networks SET is_missing = 1, enabled = 0 WHERE slug NOT IN ($placeholders)");
        if ($query) { $types = str_repeat('s', count($knownSlugs)); $query->bind_param($types, ...$knownSlugs); $query->execute(); $query->close(); }
    } else {
        @$conn->query("UPDATE networks SET is_missing = 1, enabled = 0");
    }

    $conn->close();
    set_setting('networks_last_refresh', (string)time());

    return array_values($descriptors);
}}

if (!function_exists('pp_get_networks')) {
function pp_get_networks(bool $onlyEnabled = false, bool $includeMissing = false): array {
    try {
        $conn = @connect_db();
    } catch (Throwable $e) {
        return [];
    }
    if (!$conn) { return []; }

    $where = [];
    if ($onlyEnabled) { $where[] = "enabled = 1"; }
    if (!$includeMissing) { $where[] = "is_missing = 0"; }
    $sql = "SELECT slug, title, description, handler, handler_type, meta, regions, topics, enabled, priority, level, notes, is_missing, last_check_status, last_check_run_id, last_check_started_at, last_check_finished_at, last_check_url, last_check_error, last_check_updated_at, created_at, updated_at FROM networks";
    if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
    $sql .= ' ORDER BY priority DESC, title ASC';
    $rows = [];
    if ($res = @$conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $rel = (string)$row['handler']; $isAbsolute = preg_match('~^([a-zA-Z]:[\\/]|/)~', $rel) === 1;
            if ($rel === '.') { $abs = PP_ROOT_PATH; } elseif ($isAbsolute) { $abs = $rel; } else { $abs = PP_ROOT_PATH . '/' . ltrim($rel, '/'); }
            $absReal = realpath($abs); if ($absReal) { $abs = $absReal; }
            $regionsRaw = (string)($row['regions'] ?? ''); $topicsRaw = (string)($row['topics'] ?? '');
            $regionsList = array_values(array_filter(array_map(function($item){ return trim((string)$item); }, preg_split('~[,;\n]+~', $regionsRaw) ?: [])));
            $topicsList = array_values(array_filter(array_map(function($item){ return trim((string)$item); }, preg_split('~[,;\n]+~', $topicsRaw) ?: [])));
            $rows[] = [ 'slug' => (string)$row['slug'], 'title' => (string)$row['title'], 'description' => (string)$row['description'], 'handler' => $rel, 'handler_abs' => $abs, 'handler_type' => (string)$row['handler_type'], 'meta' => json_decode((string)($row['meta'] ?? ''), true) ?: [], 'regions_raw' => $regionsRaw, 'topics_raw' => $topicsRaw, 'regions' => $regionsList, 'topics' => $topicsList, 'enabled' => (bool)$row['enabled'], 'priority' => (int)($row['priority'] ?? 0), 'level' => trim((string)($row['level'] ?? ''),), 'notes' => (string)($row['notes'] ?? ''), 'is_missing' => (bool)$row['is_missing'], 'last_check_status' => $row['last_check_status'] !== null ? (string)$row['last_check_status'] : null, 'last_check_run_id' => $row['last_check_run_id'] !== null ? (int)$row['last_check_run_id'] : null, 'last_check_started_at' => $row['last_check_started_at'], 'last_check_finished_at' => $row['last_check_finished_at'], 'last_check_url' => (string)($row['last_check_url'] ?? ''), 'last_check_error' => (string)($row['last_check_error'] ?? ''), 'last_check_updated_at' => $row['last_check_updated_at'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'], ];
        }
        $res->free();
    }

    $conn->close();
    return $rows;
}}

if (!function_exists('pp_get_network')) { function pp_get_network(string $slug): ?array { $slug = pp_normalize_slug($slug); $all = pp_get_networks(false, true); foreach ($all as $network) { if ($network['slug'] === $slug) { return $network; } } return null; } }

// See includes/networks.php

// See includes/networks.php

// See includes/networks.php

// See includes/networks.php

// See includes/networks.php

// See includes/networks.php

// Base URL helpers moved to includes/core.php

// Helpers for page metadata
// Page meta utilities moved to includes/page_meta.php

// -------- URL analysis utilities (microdata/meta extraction) --------
if (!function_exists('pp_http_fetch')) {
function pp_http_fetch(string $url, int $timeout = 12): array {
    $headers = [];
    $status = 0; $body = ''; $finalUrl = $url;
    // Use a realistic browser UA to avoid altered content for bots
    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_USERAGENT => $ua,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9,ru;q=0.8',
                'Upgrade-Insecure-Requests: 1'
            ],
        ]);
        $resp = curl_exec($ch);
        if ($resp !== false) {
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $rawHeaders = substr($resp, 0, $headerSize);
            $body = substr($resp, $headerSize);
            if ($body !== '' && strlen($body) > 1048576) {
                $body = substr($body, 0, 1048576);
            }
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
            // Parse headers (multiple response headers possible on redirects; take the last block)
            $blocks = preg_split("/\r?\n\r?\n/", trim($rawHeaders));
            $last = end($blocks);
            foreach (preg_split("/\r?\n/", (string)$last) as $line) {
                if (strpos($line, ':') !== false) {
                    [$k, $v] = array_map('trim', explode(':', $line, 2));
                    $headers[strtolower($k)] = $v;
                }
            }
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'follow_location' => 1,
                'max_redirects' => 6,
                'ignore_errors' => true,
                'header' => [
                    'User-Agent: ' . $ua,
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9,ru;q=0.8',
                    'Upgrade-Insecure-Requests: 1',
                ],
            ],
            'ssl' => [ 'verify_peer' => true, 'verify_peer_name' => true ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $body = $resp !== false ? (string)$resp : '';
        $status = 0;
        $finalUrl = $url;
        global $http_response_header;
        if (is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('~^HTTP/\d\.\d\s+(\d{3})~', $line, $m)) { $status = (int)$m[1]; }
                elseif (strpos($line, ':') !== false) {
                    [$k, $v] = array_map('trim', explode(':', $line, 2));
                    $headers[strtolower($k)] = $v;
                }
            }
        }
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $body, 'final_url' => $finalUrl];
}}

if (!function_exists('pp_html_dom')) {
function pp_html_dom(string $html): ?DOMDocument {
    if ($html === '') return null;
    if (!class_exists('DOMDocument')) { return null; }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    if (stripos($html, '<meta') === false) {
        $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
    }
    $loaded = @$doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    if (!$loaded) return null;
    return $doc;
}}

if (!function_exists('pp_xpath')) { function pp_xpath(DOMDocument $doc): DOMXPath { return new DOMXPath($doc); } }
if (!function_exists('pp_text')) { function pp_text(?DOMNode $n): string { return trim($n ? $n->textContent : ''); } }
if (!function_exists('pp_attr')) { function pp_attr(?DOMElement $n, string $name): string { return trim($n ? (string)$n->getAttribute($name) : ''); } }

if (!function_exists('pp_abs_url')) {
function pp_abs_url(string $href, string $base): string {
    if ($href === '') return '';
    if (preg_match('~^https?://~i', $href)) return $href;
    $bp = parse_url($base);
    if (!$bp || empty($bp['scheme']) || empty($bp['host'])) return $href;
    $scheme = $bp['scheme'];
    $host = $bp['host'];
    $port = isset($bp['port']) ? (':' . $bp['port']) : '';
    $path = $bp['path'] ?? '/';
    if (substr($href, 0, 1) === '/') {
        return $scheme . '://' . $host . $port . $href;
    }
    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
    $segments = array_filter(explode('/', $dir));
    foreach (explode('/', $href) as $seg) {
        if ($seg === '.' || $seg === '') continue;
        if ($seg === '..') { array_pop($segments); continue; }
        $segments[] = $seg;
    }
    return $scheme . '://' . $host . $port . '/' . implode('/', $segments);
}}

if (!function_exists('pp_normalize_text_content')) {
function pp_normalize_text_content(string $text): string {
    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $decoded = preg_replace('~\s+~u', ' ', $decoded);
    $decoded = trim((string)$decoded);
    if ($decoded === '') { return ''; }
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($decoded, 'UTF-8');
    }
    return strtolower($decoded);
}}

if (!function_exists('pp_plain_text_from_html')) {
function pp_plain_text_from_html(string $html): string {
    $doc = pp_html_dom($html);
    if ($doc) {
        $text = $doc->textContent ?? '';
    } else {
        $text = strip_tags($html);
    }
    return pp_normalize_text_content($text);
}}

if (!function_exists('pp_normalize_url_compare')) {
function pp_normalize_url_compare(string $url): string {
    $url = trim((string)$url);
    if ($url === '') { return ''; }
    $lower = strtolower($url);
    if (!preg_match('~^https?://~', $lower)) { return $lower; }
    $parts = @parse_url($lower);
    if (!$parts || empty($parts['host'])) { return $lower; }
    $scheme = $parts['scheme'] ?? 'http';
    $host = $parts['host'];
    if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }
    $path = $parts['path'] ?? '/';
    $path = $path === '' ? '/' : $path;
    $path = rtrim($path, '/');
    if ($path === '') { $path = '/'; }
    $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
    return $scheme . '://' . $host . $path . $query;
}}

if (!function_exists('pp_verify_published_content')) {
function pp_verify_published_content(string $publishedUrl, ?array $verification, ?array $job = null): array {
    $publishedUrl = trim($publishedUrl);
    $verification = is_array($verification) ? $verification : [];
    $supportsLink = array_key_exists('supportsLinkCheck', $verification) ? (bool)$verification['supportsLinkCheck'] : true;
    $supportsText = array_key_exists('supportsTextCheck', $verification) ? (bool)$verification['supportsTextCheck'] : null;
    $linkUrl = trim((string)($verification['linkUrl'] ?? ''));
    if ($linkUrl === '' && isset($job['url'])) {
        $linkUrl = trim((string)$job['url']);
    }
    $textSample = trim((string)($verification['textSample'] ?? ''));
    if ($supportsText === null) {
        $supportsText = ($textSample !== '');
    }

    $result = [
        'status' => 'skipped',
        'supports_link' => $supportsLink,
        'supports_text' => $supportsText,
        'link_found' => false,
        'text_found' => false,
        'http_status' => null,
        'final_url' => null,
        'content_type' => null,
        'reason' => null,
    ];

    if ($publishedUrl === '' || (!$supportsLink && !$supportsText)) {
        return $result;
    }

    $fetch = pp_http_fetch($publishedUrl, 18);
    $status = (int)($fetch['status'] ?? 0);
    $finalUrl = (string)($fetch['final_url'] ?? $publishedUrl);
    $headers = $fetch['headers'] ?? [];
    $body = (string)($fetch['body'] ?? '');
    $contentType = strtolower((string)($headers['content-type'] ?? ''));

    $result['http_status'] = $status;
    $result['final_url'] = $finalUrl;
    $result['content_type'] = $contentType;

    if ($status >= 400 || $body === '') {
        $result['status'] = 'error';
        $result['reason'] = 'FETCH_FAILED';
        return $result;
    }

    $doc = null;
    if ($contentType === '' || strpos($contentType, 'text/') === 0 || strpos($contentType, 'html') !== false || strpos($contentType, 'xml') !== false) {
        $doc = pp_html_dom($body);
    }

    if ($supportsLink && $linkUrl !== '') {
        $targetNorm = pp_normalize_url_compare($linkUrl);
        if ($doc) {
            $xp = new DOMXPath($doc);
            foreach ($xp->query('//a[@href]') as $node) {
                if (!($node instanceof DOMElement)) { continue; }
                $href = trim((string)$node->getAttribute('href'));
                if ($href === '') { continue; }
                $abs = pp_abs_url($href, $finalUrl);
                $absNorm = pp_normalize_url_compare($abs);
                if ($absNorm === $targetNorm) {
                    $result['link_found'] = true;
                    break;
                }
            }
        }
        if (!$result['link_found']) {
            $haystack = strtolower($body);
            $direct = strtolower($linkUrl);
            if ($direct !== '' && strpos($haystack, $direct) !== false) {
                $result['link_found'] = true;
            } else {
                $noScheme = preg_replace('~^https?://~i', '', $direct);
                if ($noScheme && strpos($haystack, $noScheme) !== false) {
                    $result['link_found'] = true;
                }
            }
        }
    } elseif ($supportsLink) {
        $result['supports_link'] = false;
    }

    if ($supportsText) {
        if ($textSample === '') {
            $result['supports_text'] = false;
        } else {
            $bodyPlain = $doc ? pp_normalize_text_content($doc->textContent ?? '') : pp_plain_text_from_html($body);
            $sampleNorm = pp_normalize_text_content($textSample);
            $matchFragment = '';
            if ($sampleNorm !== '' && strpos($bodyPlain, $sampleNorm) !== false) {
                $result['text_found'] = true;
                $matchFragment = $sampleNorm;
            } elseif ($sampleNorm !== '') {
                if (function_exists('mb_strlen')) {
                    $strlen = static function($str) { return mb_strlen($str, 'UTF-8'); };
                } else {
                    $strlen = static function($str) { return strlen($str); };
                }
                if (function_exists('mb_substr')) {
                    $substr = static function($str, $start, $length) { return mb_substr($str, $start, $length, 'UTF-8'); };
                } else {
                    $substr = static function($str, $start, $length) { return substr($str, $start, $length); };
                }
                $len = $strlen($sampleNorm);
                $short = $len > 120 ? $substr($sampleNorm, 0, 120) : $sampleNorm;
                if ($short !== '' && strpos($bodyPlain, $short) !== false) {
                    $result['text_found'] = true;
                    $matchFragment = $short;
                }
                if (!$result['text_found'] && $len > 0) {
                    $window = min(220, max(80, (int)ceil($len * 0.4)));
                    $step = max(40, (int)floor($window / 2));
                    for ($offset = 0; $offset < $len; $offset += $step) {
                        if ($offset + $window > $len) {
                            $offset = max(0, $len - $window);
                        }
                        $fragment = trim($substr($sampleNorm, $offset, $window));
                        if ($fragment === '' || $strlen($fragment) < 40) {
                            if ($offset + $window >= $len) { break; }
                            continue;
                        }
                        if (strpos($bodyPlain, $fragment) !== false) {
                            $result['text_found'] = true;
                            $matchFragment = $fragment;
                            break;
                        }
                        if ($offset + $window >= $len) { break; }
                    }
                }
                if (!$result['text_found']) {
                    $sentences = preg_split('~[.!?…]+\s*~u', $sampleNorm) ?: [];
                    $foundParts = [];
                    foreach ($sentences as $sentence) {
                        $sentence = trim($sentence);
                        if ($sentence === '') { continue; }
                        if ($strlen($sentence) < 40) { continue; }
                        if (strpos($bodyPlain, $sentence) !== false) {
                            $foundParts[] = $sentence;
                            if (count($foundParts) >= 2) {
                                $result['text_found'] = true;
                                $matchFragment = implode(' ', array_slice($foundParts, 0, 2));
                                break;
                            }
                        }
                    }
                }
            }
            if ($result['text_found'] && $matchFragment !== '') {
                $result['matched_fragment'] = $strlen($matchFragment) > 220 ? $substr($matchFragment, 0, 220) : $matchFragment;
            }
        }
    }

    if ($supportsLink && !$result['link_found']) {
        $result['status'] = 'failed';
        $result['reason'] = 'LINK_MISSING';
    } elseif ($supportsText && !$result['text_found']) {
        if ($supportsLink && $result['link_found']) {
            $result['status'] = 'partial';
            $result['reason'] = 'TEXT_MISSING';
        } else {
            $result['status'] = 'failed';
            $result['reason'] = 'TEXT_MISSING';
        }
    } else {
        $result['status'] = 'success';
    }

    return $result;
}}

if (!function_exists('pp_analyze_url_data')) {
function pp_analyze_url_data(string $url): ?array {
    if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) { return null; }
    $fetch = pp_http_fetch($url, 12);
    if (($fetch['status'] ?? 0) >= 400 || ($fetch['body'] ?? '') === '') {
        return null;
    }
    $finalUrl = $fetch['final_url'] ?: $url;
    $headers = $fetch['headers'] ?? [];
    $body = (string)$fetch['body'];
    $doc = pp_html_dom($body);
    if (!$doc) { return null; }
    $xp = pp_xpath($doc);

    $baseHref = '';
    $baseEl = $xp->query('//base[@href]')->item(0);
    if ($baseEl instanceof DOMElement) { $baseHref = pp_attr($baseEl, 'href'); }
    $base = $baseHref !== '' ? $baseHref : $finalUrl;

    $title = '';
    $titleEl = $xp->query('//title')->item(0);
    if ($titleEl) { $title = pp_text($titleEl); }
    $ogTitle = $xp->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content | //meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:title"]/@content')->item(0);
    if ($ogTitle && !$title) { $title = trim($ogTitle->nodeValue ?? ''); }

    $desc = '';
    $metaDesc = $xp->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]/@content')->item(0);
    if ($metaDesc) { $desc = trim($metaDesc->nodeValue ?? ''); }
    if ($desc === '') {
        $ogDesc = $xp->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:description"]/@content')->item(0);
        if ($ogDesc) { $desc = trim($ogDesc->nodeValue ?? ''); }
    }

    $canonical = '';
    $canonEl = $xp->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="canonical"]/@href')->item(0);
    if ($canonEl) { $canonical = pp_abs_url(trim($canonEl->nodeValue ?? ''), $base); }

    $lang = '';
    $region = '';
    $htmlEl = $xp->query('//html')->item(0);
    if ($htmlEl instanceof DOMElement) {
        $langAttr = trim($htmlEl->getAttribute('lang'));
        if ($langAttr) {
            $parts = preg_split('~[-_]~', $langAttr);
            $lang = strtolower($parts[0] ?? '');
            if (isset($parts[1])) { $region = strtoupper($parts[1]); }
        }
    }

    $hreflangs = [];
    foreach ($xp->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="alternate" and @hreflang and @href]') as $lnk) {
        if (!($lnk instanceof DOMElement)) continue;
        $hl = trim($lnk->getAttribute('hreflang'));
        $href = pp_abs_url(trim($lnk->getAttribute('href')), $base);
        if ($hl && $href) { $hreflangs[] = ['hreflang' => $hl, 'href' => $href]; }
    }
    if (!$lang && !empty($hreflangs)) {
        $hl0 = $hreflangs[0]['hreflang'];
        $parts = preg_split('~[-_]~', $hl0);
        $lang = strtolower($parts[0] ?? '');
        if (isset($parts[1])) { $region = strtoupper($parts[1]); }
    }

    if (!$lang) {
        $contentLang = $xp->query('//meta[translate(@http-equiv, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="content-language"]/@content')->item(0);
        if ($contentLang) {
            $v = trim($contentLang->nodeValue ?? '');
            if ($v) {
                $parts = preg_split('~[,;\s]+~', $v);
                $p0 = $parts[0] ?? '';
                $pp = preg_split('~[-_]~', $p0);
                $lang = strtolower($pp[0] ?? '');
                if (isset($pp[1])) { $region = strtoupper($pp[1]); }
            }
        }
    }

    $published = '';
    $modified = '';
    $q = function(string $xpath) use ($xp): ?string { $n = $xp->query($xpath)->item(0); return $n ? trim($n->nodeValue ?? '') : null; };
    $published = $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:published_time"]/@content') ?: $q('//meta[translate(@itemprop, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="datePublished"]/@content') ?: $q('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="pubdate"]/@content');
    $modified = $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:modified_time"]/@content') ?: $q('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="og:updated_time"]/@content') ?: $q('//meta[translate(@itemprop, "ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="dateModified"]/@content');

    foreach ($xp->query('//script[@type="application/ld+json"]') as $script) {
        $json = trim($script->textContent ?? '');
        if ($json === '') continue;
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $json2 = preg_replace('/,\s*([}\]])/', '$1', $json);
            $data = json_decode($json2, true);
        }
        if (is_array($data)) {
            $stack = [$data];
            while ($stack) {
                $cur = array_pop($stack);
                if (isset($cur['datePublished']) && !$published) { $published = (string)$cur['datePublished']; }
                if (isset($cur['dateModified']) && !$modified) { $modified = (string)$cur['dateModified']; }
                foreach ($cur as $v) { if (is_array($v)) $stack[] = $v; }
            }
        }
        if ($published && $modified) break;
    }

    if (!$modified && !empty($headers['last-modified'])) { $modified = $headers['last-modified']; }

    return [
        'final_url' => $finalUrl,
        'lang' => $lang,
        'region' => $region,
        'title' => $title,
        'description' => $desc,
        'canonical' => $canonical,
        'published_time' => $published,
        'modified_time' => $modified,
        'hreflang' => $hreflangs,
    ];
}}

// Publication queue helpers moved to includes/publication_queue.php

// -------- Network diagnostics (batch publishing check) --------
// Worker launcher is defined in includes/network_check.php

if (!function_exists('pp_network_check_start')) {
    function pp_network_check_start(?int $userId = null, ?string $mode = 'bulk', ?string $targetSlug = null, ?array $targetSlugs = null): array {
        $mode = in_array($mode, ['bulk','single','selection'], true) ? $mode : 'bulk';
        $targetSlug = pp_normalize_slug((string)$targetSlug);
        $selectionMap = [];
        if (is_array($targetSlugs)) {
            foreach ($targetSlugs as $sel) {
                $normalized = pp_normalize_slug((string)$sel);
                if ($normalized !== '') {
                    $selectionMap[$normalized] = true;
                }
            }
        }
        $targetSlugs = array_keys($selectionMap);

        $allNetworks = pp_get_networks(false, false);
        $availableNetworks = [];
        foreach ($allNetworks as $network) {
            $slug = pp_normalize_slug((string)$network['slug']);
            if ($slug === '') { continue; }
            if (!empty($network['is_missing'])) { continue; }
            $availableNetworks[$slug] = $network;
        }

        $eligibleNetworks = [];
        if ($mode === 'bulk') {
            foreach ($availableNetworks as $net) {
                if (!empty($net['enabled'])) {
                    $eligibleNetworks[] = $net;
                }
            }
            if (empty($eligibleNetworks)) {
                return ['ok' => false, 'error' => 'NO_ENABLED_NETWORKS'];
            }
        } elseif ($mode === 'single') {
            if ($targetSlug === '') {
                return ['ok' => false, 'error' => 'MISSING_SLUG'];
            }
            if (!isset($availableNetworks[$targetSlug])) {
                return ['ok' => false, 'error' => 'NETWORK_NOT_FOUND'];
            }
            $eligibleNetworks[] = $availableNetworks[$targetSlug];
        } else { // selection
            foreach ($targetSlugs as $sel) {
                if (isset($availableNetworks[$sel])) {
                    $eligibleNetworks[] = $availableNetworks[$sel];
                }
            }
            if (empty($eligibleNetworks)) {
                return ['ok' => false, 'error' => 'NETWORK_NOT_FOUND'];
            }
        }

        pp_network_check_log('Request to start network check', [
            'mode' => $mode,
            'targetSlug' => $targetSlug ?: null,
            'selectedSlugs' => $mode === 'selection' ? $targetSlugs : null,
            'eligibleNetworks' => count($eligibleNetworks),
            'userId' => $userId,
        ]);

        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            pp_network_check_log('Network check start failed: DB connection error', ['mode' => $mode, 'targetSlug' => $targetSlug]);
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) { return ['ok' => false, 'error' => 'DB_CONNECTION']; }

        $activeId = null;
        if ($res = @$conn->query("SELECT id FROM network_check_runs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")) {
            if ($row = $res->fetch_assoc()) { $activeId = (int)$row['id']; }
            $res->free();
        }
        if ($activeId) {
            pp_network_check_log('Network check already running', ['existingRunId' => $activeId]);
            $conn->close();
            return ['ok' => true, 'runId' => $activeId, 'alreadyRunning' => true];
        }

        $total = count($eligibleNetworks);
        if ($userId !== null) {
            $stmt = $conn->prepare("INSERT INTO network_check_runs (status, total_networks, initiated_by, run_mode) VALUES ('queued', ?, ?, ?)");
            if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB_WRITE']; }
            $stmt->bind_param('iis', $total, $userId, $mode);
        } else {
            $stmt = $conn->prepare("INSERT INTO network_check_runs (status, total_networks, run_mode) VALUES ('queued', ?, ?)");
            if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB_WRITE']; }
            $stmt->bind_param('is', $total, $mode);
        }
        if (!$stmt->execute()) {
            $stmt->close();
            $conn->close();
            pp_network_check_log('Network check start failed: insert run', ['mode' => $mode, 'targetSlug' => $targetSlug]);
            return ['ok' => false, 'error' => 'DB_WRITE'];
        }
        $stmt->close();
        $runId = (int)$conn->insert_id;

        $resStmt = $conn->prepare("INSERT INTO network_check_results (run_id, network_slug, network_title) VALUES (?, ?, ?)");
        if ($resStmt) {
            foreach ($eligibleNetworks as $net) {
                $slug = (string)$net['slug'];
                $title = (string)($net['title'] ?? $slug);
                $resStmt->bind_param('iss', $runId, $slug, $title);
                $resStmt->execute();
            }
            $resStmt->close();
        }
        $conn->close();

        pp_network_check_log('Network check run created', ['runId' => $runId, 'mode' => $mode, 'targetSlug' => $targetSlug ?: null, 'networks' => $total]);

        if (!pp_network_check_launch_worker($runId)) {
            try {
                $conn2 = @connect_db();
                if ($conn2) {
                    $msg = __('Не удалось запустить фоновый процесс.');
                    $upd = $conn2->prepare("UPDATE network_check_runs SET status='failed', notes=? WHERE id=? LIMIT 1");
                    if ($upd) {
                        $upd->bind_param('si', $msg, $runId);
                        $upd->execute();
                        $upd->close();
                    }
                    $conn2->close();
                }
            } catch (Throwable $e) { /* ignore */ }
            pp_network_check_log('Network check worker launch failed', ['runId' => $runId]);
            return ['ok' => false, 'error' => 'WORKER_LAUNCH_FAILED'];
        }

        pp_network_check_log('Network check worker launched', ['runId' => $runId]);

        if (!pp_network_check_wait_for_worker_start($runId, 3.0)) {
            pp_network_check_log('Worker did not start in time; processing inline', ['runId' => $runId]);
            try {
                pp_process_network_check_run($runId);
                pp_network_check_log('Inline network check processing completed', ['runId' => $runId]);
            } catch (Throwable $e) {
                pp_network_check_log('Inline network check processing failed', ['runId' => $runId, 'error' => $e->getMessage()]);
            }
        }

        return ['ok' => true, 'runId' => $runId, 'alreadyRunning' => false];
    }
}

if (!function_exists('pp_network_check_format_ts')) {
    function pp_network_check_format_ts(?string $ts): ?string {
        if (!$ts) { return null; }
        $ts = trim($ts);
        if ($ts === '' || $ts === '0000-00-00 00:00:00') { return null; }
        $time = strtotime($ts);
        if ($time === false) { return $ts; }
        return date(DATE_ATOM, $time);
    }
}

if (!function_exists('pp_network_check_get_status')) {
    function pp_network_check_get_status(?int $runId = null): array {
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) { return ['ok' => false, 'error' => 'DB_CONNECTION']; }

        if ($runId === null || $runId <= 0) {
            if ($res = @$conn->query("SELECT id FROM network_check_runs ORDER BY id DESC LIMIT 1")) {
                if ($row = $res->fetch_assoc()) { $runId = (int)$row['id']; }
                $res->free();
            }
        }
        if (!$runId) {
            $conn->close();
            return ['ok' => true, 'run' => null, 'results' => []];
        }

    $stmt = $conn->prepare("SELECT id, status, total_networks, success_count, failure_count, notes, initiated_by, run_mode, cancel_requested, created_at, started_at, finished_at FROM network_check_runs WHERE id = ? LIMIT 1");
        if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB_READ']; }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $runRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$runRow) {
            $conn->close();
            return ['ok' => false, 'error' => 'RUN_NOT_FOUND'];
        }

        $results = [];
        $stmtRes = $conn->prepare("SELECT id, network_slug, network_title, status, started_at, finished_at, published_url, error, created_at FROM network_check_results WHERE run_id = ? ORDER BY id ASC");
        if ($stmtRes) {
            $stmtRes->bind_param('i', $runId);
            $stmtRes->execute();
            $res = $stmtRes->get_result();
            while ($row = $res->fetch_assoc()) {
                $results[] = [
                    'id' => (int)$row['id'],
                    'network_slug' => (string)$row['network_slug'],
                    'network_title' => (string)$row['network_title'],
                    'status' => (string)$row['status'],
                    'started_at' => $row['started_at'],
                    'started_at_iso' => pp_network_check_format_ts($row['started_at'] ?? null),
                    'finished_at' => $row['finished_at'],
                    'finished_at_iso' => pp_network_check_format_ts($row['finished_at'] ?? null),
                    'created_at' => $row['created_at'],
                    'created_at_iso' => pp_network_check_format_ts($row['created_at'] ?? null),
                    'published_url' => (string)($row['published_url'] ?? ''),
                    'error' => (string)($row['error'] ?? ''),
                ];
            }
            $stmtRes->close();
        }
        $conn->close();

        $run = [
            'id' => (int)$runRow['id'],
            'status' => (string)$runRow['status'],
            'total_networks' => (int)$runRow['total_networks'],
            'success_count' => (int)$runRow['success_count'],
            'failure_count' => (int)$runRow['failure_count'],
            'notes' => (string)($runRow['notes'] ?? ''),
            'run_mode' => (string)($runRow['run_mode'] ?? 'bulk'),
            'initiated_by' => $runRow['initiated_by'] !== null ? (int)$runRow['initiated_by'] : null,
            'cancel_requested' => !empty($runRow['cancel_requested']),
            'created_at' => $runRow['created_at'],
            'created_at_iso' => pp_network_check_format_ts($runRow['created_at'] ?? null),
            'started_at' => $runRow['started_at'],
            'started_at_iso' => pp_network_check_format_ts($runRow['started_at'] ?? null),
            'finished_at' => $runRow['finished_at'],
            'finished_at_iso' => pp_network_check_format_ts($runRow['finished_at'] ?? null),
        ];
        $run['completed_count'] = $run['success_count'] + $run['failure_count'];
        $run['in_progress'] = ($run['status'] === 'running');
        $run['has_failures'] = ($run['failure_count'] > 0);

        return ['ok' => true, 'run' => $run, 'results' => $results];
    }
}

if (!function_exists('pp_network_check_cancel')) {
    function pp_network_check_cancel(?int $runId = null, bool $force = false): array {
            pp_network_check_log('Cancel network check requested', ['runId' => $runId, 'force' => $force]);
        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
                pp_network_check_log('Cancel network check failed: DB connection error', ['runId' => $runId]);
            return ['ok' => false, 'error' => 'DB_CONNECTION'];
        }
        if (!$conn) { return ['ok' => false, 'error' => 'DB_CONNECTION']; }

        if ($runId === null || $runId <= 0) {
            if ($res = @$conn->query("SELECT id FROM network_check_runs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1")) {
                if ($row = $res->fetch_assoc()) { $runId = (int)$row['id']; }
                $res->free();
            }
        }
        if (!$runId) {
            $conn->close();
            return ['ok' => true, 'status' => 'idle'];
        }

        $stmt = $conn->prepare("SELECT id, status, cancel_requested, notes, success_count, failure_count FROM network_check_runs WHERE id = ? LIMIT 1");
        if (!$stmt) { $conn->close(); return ['ok' => false, 'error' => 'DB_READ']; }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $runRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$runRow) {
            $conn->close();
            return ['ok' => false, 'error' => 'RUN_NOT_FOUND'];
        }

        $status = (string)$runRow['status'];
        $alreadyDone = !in_array($status, ['queued','running'], true);
        $existingNote = trim((string)($runRow['notes'] ?? ''));
        $cancelRequested = !empty($runRow['cancel_requested']);
        $cancelNote = __('Проверка остановлена администратором.');
        $note = $cancelNote;
        if ($existingNote !== '') {
            if (stripos($existingNote, $cancelNote) !== false) {
                $note = $existingNote;
            } else {
                $note .= ' | ' . $existingNote;
            }
        }

        @$conn->query("UPDATE network_check_runs SET cancel_requested=1 WHERE id=" . (int)$runId . " LIMIT 1");

        if ($alreadyDone && !$force) {
            pp_network_check_log('Cancel ignored: run already finished', ['runId' => $runId, 'status' => $status]);
            $conn->close();
            return [
                'ok' => true,
                'runId' => $runId,
                'status' => $status,
                'cancelRequested' => true,
                'alreadyFinished' => true,
                'finished' => true,
            ];
        }

        $forceApply = $force || $status === 'queued';
        if ($forceApply) {
            @$conn->query("UPDATE network_check_results SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP) WHERE run_id=" . (int)$runId . " AND status IN ('queued','running')");
            $success = 0;
            $failed = 0;
            if ($resCnt = @$conn->query("SELECT SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) AS success_count, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failure_count FROM network_check_results WHERE run_id=" . (int)$runId)) {
                if ($rowCnt = $resCnt->fetch_assoc()) {
                    $success = (int)($rowCnt['success_count'] ?? 0);
                    $failed = (int)($rowCnt['failure_count'] ?? 0);
                }
                $resCnt->free();
            }
            $upd = $conn->prepare("UPDATE network_check_runs SET status='cancelled', success_count=?, failure_count=?, finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP), notes=?, cancel_requested=1 WHERE id=? LIMIT 1");
            if ($upd) {
                $upd->bind_param('iisi', $success, $failed, $note, $runId);
                $upd->execute();
                $upd->close();
            }
            if ($resSlugs = @$conn->query("SELECT DISTINCT network_slug FROM network_check_results WHERE run_id=" . (int)$runId . " AND status='cancelled'")) {
                while ($rowSlug = $resSlugs->fetch_assoc()) {
                    $slugCancel = (string)$rowSlug['network_slug'];
                    if ($slugCancel === '') { continue; }
                    pp_network_check_update_network_row($conn, $slugCancel, [
                        'last_check_status' => 'cancelled',
                        'last_check_run_id' => $runId,
                        'last_check_finished_at' => date('Y-m-d H:i:s'),
                        'last_check_error' => null,
                    ]);
                }
                $resSlugs->free();
            }
            pp_network_check_log('Cancel applied immediately', ['runId' => $runId, 'success' => $success, 'failed' => $failed]);
            $conn->close();
            return ['ok' => true, 'runId' => $runId, 'status' => 'cancelled', 'cancelRequested' => true, 'finished' => true];
        }

        if (!$cancelRequested) {
            $updNote = $conn->prepare("UPDATE network_check_runs SET notes=?, cancel_requested=1 WHERE id=? LIMIT 1");
            if ($updNote) {
                $updNote->bind_param('si', $note, $runId);
                $updNote->execute();
                $updNote->close();
            }
        }
        $conn->close();
        pp_network_check_log('Cancel request recorded', ['runId' => $runId, 'status' => $status]);
        return ['ok' => true, 'runId' => $runId, 'status' => $status, 'cancelRequested' => true, 'finished' => false];
    }
}

if (!function_exists('pp_process_network_check_run')) {
    function pp_process_network_check_run(int $runId): void {
        if ($runId <= 0) { return; }
        if (function_exists('session_write_close')) { @session_write_close(); }
        @ignore_user_abort(true);

        try {
            $conn = @connect_db();
        } catch (Throwable $e) {
            pp_network_check_log('Worker unable to connect to DB', ['runId' => $runId]);
            return;
        }
        if (!$conn) { return; }

    $stmt = $conn->prepare("SELECT id, status, total_networks, run_mode, cancel_requested, notes, success_count, failure_count FROM network_check_runs WHERE id = ? LIMIT 1");
        if (!$stmt) { $conn->close(); return; }
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $runRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$runRow) {
            pp_network_check_log('Worker run not found', ['runId' => $runId]);
            $conn->close();
            return;
        }

    $status = (string)$runRow['status'];
    $runMode = isset($runRow['run_mode']) ? (string)$runRow['run_mode'] : 'bulk';
        $existingNote = trim((string)($runRow['notes'] ?? ''));
        $cancelRequested = !empty($runRow['cancel_requested']);
        $cancelNoteBase = __('Проверка остановлена администратором.');
        $noteFormatter = static function(string $baseNote, string $existing): string {
            $base = trim($baseNote);
            $existing = trim($existing);
            if ($base === '') { return $existing; }
            if ($existing === '') { return $base; }
            if (stripos($existing, $base) !== false) { return $existing; }
            return $base . ' | ' . $existing;
        };
        $recalcCounts = function() use ($conn, $runId): array {
            $success = 0;
            $failed = 0;
            if ($res = @$conn->query("SELECT SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) AS success_count, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failure_count FROM network_check_results WHERE run_id = " . (int)$runId)) {
                if ($row = $res->fetch_assoc()) {
                    $success = (int)($row['success_count'] ?? 0);
                    $failed = (int)($row['failure_count'] ?? 0);
                }
                $res->free();
            }
            return [$success, $failed];
        };
        $finalizeCancelled = function(?int $successOverride = null, ?int $failureOverride = null) use ($conn, $runId, $cancelNoteBase, &$existingNote, $recalcCounts, $noteFormatter): void {
            @$conn->query("UPDATE network_check_results SET status='cancelled', finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP) WHERE run_id = " . (int)$runId . " AND status IN ('queued','running')");
            [$success, $failed] = $recalcCounts();
            if ($successOverride !== null) { $success = $successOverride; }
            if ($failureOverride !== null) { $failed = $failureOverride; }
            $note = $noteFormatter($cancelNoteBase, $existingNote);
            $existingNote = $note;
            $upd = $conn->prepare("UPDATE network_check_runs SET status='cancelled', success_count=?, failure_count=?, finished_at=COALESCE(finished_at, CURRENT_TIMESTAMP), notes=?, cancel_requested=1 WHERE id=? LIMIT 1");
            if ($upd) {
                $upd->bind_param('iisi', $success, $failed, $note, $runId);
                $upd->execute();
                $upd->close();
            }
            if ($resSlugs = @$conn->query("SELECT DISTINCT network_slug FROM network_check_results WHERE run_id = " . (int)$runId . " AND status = 'cancelled'")) {
                while ($rowSlug = $resSlugs->fetch_assoc()) {
                    $slugCancel = (string)$rowSlug['network_slug'];
                    if ($slugCancel === '') { continue; }
                    pp_network_check_update_network_row($conn, $slugCancel, [
                        'last_check_status' => 'cancelled',
                        'last_check_run_id' => $runId,
                        'last_check_finished_at' => date('Y-m-d H:i:s'),
                        'last_check_error' => null,
                    ]);
                }
                $resSlugs->free();
            }
            pp_network_check_log('Worker marked run as cancelled', ['runId' => $runId, 'success' => $success, 'failed' => $failed]);
        };

        if (!in_array($status, ['queued','running'], true)) {
            pp_network_check_log('Worker exiting: status not actionable', ['runId' => $runId, 'status' => $status]);
            $conn->close();
            return;
        }

        if ($cancelRequested && $status === 'queued') {
            $finalizeCancelled(null, null);
            pp_network_check_log('Worker cancelled queued run before start', ['runId' => $runId]);
            $conn->close();
            return;
        }

        $conn->query("UPDATE network_check_runs SET status='running', started_at=COALESCE(started_at, CURRENT_TIMESTAMP) WHERE id=" . (int)$runId . " LIMIT 1");
        pp_network_check_log('Worker started processing', ['runId' => $runId, 'status' => $status]);

        $results = [];
        $resStmt = $conn->prepare("SELECT id, network_slug, network_title FROM network_check_results WHERE run_id = ? ORDER BY id ASC");
        if ($resStmt) {
            $resStmt->bind_param('i', $runId);
            $resStmt->execute();
            $res = $resStmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $results[] = $row;
            }
            $resStmt->close();
        }

        $total = count($results);
        if ($total === 0) {
            $msg = __('Нет активных сетей для проверки.');
            $upd = $conn->prepare("UPDATE network_check_runs SET status='failed', notes=?, finished_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
            if ($upd) {
                $upd->bind_param('si', $msg, $runId);
                $upd->execute();
                $upd->close();
            }
            pp_network_check_log('Worker found no networks to process', ['runId' => $runId]);
            $conn->close();
            return;
        }
        if ((int)$runRow['total_networks'] !== $total) {
            $conn->query("UPDATE network_check_runs SET total_networks=" . $total . " WHERE id=" . (int)$runId . " LIMIT 1");
            pp_network_check_log('Worker adjusted total networks', ['runId' => $runId, 'total' => $total]);
        }

        if ($cancelRequested && $status === 'running') {
            $successExisting = isset($runRow['success_count']) ? (int)$runRow['success_count'] : null;
            $failureExisting = isset($runRow['failure_count']) ? (int)$runRow['failure_count'] : null;
            $finalizeCancelled($successExisting, $failureExisting);
            pp_network_check_log('Worker aborted run before loop due to cancellation', ['runId' => $runId]);
            $conn->close();
            return;
        }

        $success = 0;
        $failed = 0;
        $checkCancelled = function() use ($conn, $runId): bool {
            if ($res = @$conn->query("SELECT cancel_requested FROM network_check_runs WHERE id = " . (int)$runId . " LIMIT 1")) {
                $row = $res->fetch_assoc();
                $res->free();
                return !empty($row['cancel_requested']);
            }
            return false;
        };

        $updateResultRunning = $conn->prepare("UPDATE network_check_results SET status='running', started_at=CURRENT_TIMESTAMP, error=NULL WHERE id=? LIMIT 1");
        $updateResultSuccess = $conn->prepare("UPDATE network_check_results SET status='success', finished_at=CURRENT_TIMESTAMP, published_url=?, error=NULL WHERE id=? LIMIT 1");
        $updateResultFail = $conn->prepare("UPDATE network_check_results SET status='failed', finished_at=CURRENT_TIMESTAMP, error=? WHERE id=? LIMIT 1");
        $updateRunCounts = $conn->prepare("UPDATE network_check_runs SET success_count=?, failure_count=?, status='running' WHERE id=? LIMIT 1");

    $cancelledMidway = false;
        foreach ($results as $row) {
            if ($checkCancelled()) {
                $cancelledMidway = true;
                pp_network_check_log('Worker detected cancellation during loop', ['runId' => $runId]);
                break;
            }

            $resId = (int)$row['id'];
            $slug = (string)$row['network_slug'];
            pp_network_check_log('Worker starting network check', ['runId' => $runId, 'resultId' => $resId, 'slug' => $slug]);
            if ($updateResultRunning) {
                $updateResultRunning->bind_param('i', $resId);
                $updateResultRunning->execute();
            }
            pp_network_check_update_network_row($conn, $slug, [
                'last_check_status' => 'running',
                'last_check_run_id' => $runId,
                'last_check_started_at' => date('Y-m-d H:i:s'),
                'last_check_finished_at' => null,
                'last_check_url' => null,
                'last_check_error' => null,
            ]);

            $network = pp_get_network($slug);
            $allowDisabled = in_array($runMode, ['single','selection'], true);
            if (!$network || !empty($network['is_missing']) || (!$allowDisabled && empty($network['enabled']))) {
                $errMsg = __('Обработчик сети недоступен.');
                if ($updateResultFail) {
                    $updateResultFail->bind_param('si', $errMsg, $resId);
                    $updateResultFail->execute();
                }
                $failed++;
                if ($updateRunCounts) {
                    $updateRunCounts->bind_param('iii', $success, $failed, $runId);
                    $updateRunCounts->execute();
                }
                pp_network_check_log('Worker skipped network: handler missing/disabled', ['runId' => $runId, 'slug' => $slug]);
                pp_network_check_update_network_row($conn, $slug, [
                    'last_check_status' => 'failed',
                    'last_check_run_id' => $runId,
                    'last_check_finished_at' => date('Y-m-d H:i:s'),
                    'last_check_error' => $errMsg,
                ]);
                continue;
            }

            $aiProvider = strtolower((string)get_setting('ai_provider', 'openai')) === 'byoa' ? 'byoa' : 'openai';
            $openaiKey = trim((string)get_setting('openai_api_key', ''));
            $openaiModel = trim((string)get_setting('openai_model', 'gpt-3.5-turbo')) ?: 'gpt-3.5-turbo';
            $job = [
                'url' => 'https://example.com/promo-diagnostics',
                'anchor' => 'PromoPilot diagnostics link',
                'language' => 'ru',
                'wish' => 'Пожалуйста, создай короткую тестовую заметку (диагностика сетей PromoPilot) с положительным нейтральным тоном.',
                'projectId' => 0,
                'projectName' => 'PromoPilot Diagnostics',
                'testMode' => true,
                'aiProvider' => $aiProvider,
                'openaiApiKey' => $openaiKey,
                'openaiModel' => $openaiModel,
                'waitBetweenCallsMs' => 2000,
                'diagnosticRunId' => $runId,
                'networkSlug' => $slug,
                'page_meta' => null,
                'captcha' => [
                    'provider' => (string)get_setting('captcha_provider', 'none'),
                    'apiKey' => (string)get_setting('captcha_api_key', ''),
                    'fallback' => [
                        'provider' => (string)get_setting('captcha_fallback_provider', 'none'),
                        'apiKey' => (string)get_setting('captcha_fallback_api_key', ''),
                    ],
                ],
            ];

            $result = null;
            try {
                pp_network_check_log('Worker invoking network handler', [
                    'runId' => $runId,
                    'slug' => $slug,
                    'jobUrl' => $job['url'],
                    'testMode' => !empty($job['testMode']),
                ]);
                $result = pp_publish_via_network($network, $job, 480);
            } catch (Throwable $e) {
                $result = ['ok' => false, 'error' => 'PHP_EXCEPTION', 'details' => $e->getMessage()];
            }

            $publishedUrl = '';
            $ok = is_array($result) && !empty($result['ok']) && !empty($result['publishedUrl']);
            if ($ok) {
                $publishedUrl = trim((string)$result['publishedUrl']);
                if ($updateResultSuccess) {
                    $updateResultSuccess->bind_param('si', $publishedUrl, $resId);
                    $updateResultSuccess->execute();
                }
                $success++;
                pp_network_check_log('Worker network success', ['runId' => $runId, 'slug' => $slug, 'publishedUrl' => $publishedUrl]);
                pp_network_check_update_network_row($conn, $slug, [
                    'last_check_status' => 'success',
                    'last_check_run_id' => $runId,
                    'last_check_finished_at' => date('Y-m-d H:i:s'),
                    'last_check_url' => $publishedUrl,
                    'last_check_error' => null,
                ]);
            } else {
                $err = '';
                if (is_array($result)) {
                    $err = (string)($result['details'] ?? $result['error'] ?? $result['stderr'] ?? 'UNKNOWN_ERROR');
                } else {
                    $err = 'UNKNOWN_ERROR';
                }
                $errLen = function_exists('mb_strlen') ? mb_strlen($err) : strlen($err);
                if ($errLen > 2000) {
                    $err = function_exists('mb_substr') ? mb_substr($err, 0, 2000) : substr($err, 0, 2000);
                }
                if ($updateResultFail) {
                    $updateResultFail->bind_param('si', $err, $resId);
                    $updateResultFail->execute();
                }
                $failed++;
                pp_network_check_log('Worker network failed', ['runId' => $runId, 'slug' => $slug, 'error' => $err]);
                pp_network_check_update_network_row($conn, $slug, [
                    'last_check_status' => 'failed',
                    'last_check_run_id' => $runId,
                    'last_check_finished_at' => date('Y-m-d H:i:s'),
                    'last_check_error' => $err,
                ]);
            }

            if ($updateRunCounts) {
                $updateRunCounts->bind_param('iii', $success, $failed, $runId);
                $updateRunCounts->execute();
            }
        }

        if ($updateResultRunning) { $updateResultRunning->close(); }
        if ($updateResultSuccess) { $updateResultSuccess->close(); }
        if ($updateResultFail) { $updateResultFail->close(); }
        if ($updateRunCounts) { $updateRunCounts->close(); }

        if ($cancelledMidway || $checkCancelled()) {
            $finalizeCancelled($success, $failed);
            pp_network_check_log('Worker finalised cancellation after partial run', ['runId' => $runId, 'success' => $success, 'failed' => $failed]);
            $conn->close();
            return;
        }

        $finalStatus = ($failed === 0) ? 'success' : 'completed';
        $updFinish = $conn->prepare("UPDATE network_check_runs SET status=?, success_count=?, failure_count=?, total_networks=?, finished_at=CURRENT_TIMESTAMP WHERE id=? LIMIT 1");
        if ($updFinish) {
            $updFinish->bind_param('siiii', $finalStatus, $success, $failed, $total, $runId);
            $updFinish->execute();
            $updFinish->close();
        }

        pp_network_check_log('Worker finished run', ['runId' => $runId, 'status' => $finalStatus, 'success' => $success, 'failed' => $failed, 'total' => $total]);

        $conn->close();
    }
}

?>
