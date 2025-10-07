<?php
// Schema bootstrap extracted from functions.php for clarity.

if (!function_exists('pp_run_schema_bootstrap')) {
function pp_run_schema_bootstrap(): void {
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
    // Users table: referral program fields
    if (!empty($usersCols) && !isset($usersCols['referred_by'])) {
        @$conn->query("ALTER TABLE `users` ADD COLUMN `referred_by` INT NULL AFTER `promotion_discount`");
        $usersCols = $getCols('users');
    }
    if (!empty($usersCols) && !isset($usersCols['referral_code'])) {
        @$conn->query("ALTER TABLE `users` ADD COLUMN `referral_code` VARCHAR(32) NULL AFTER `referred_by`");
        // unique index for quick lookup
        if (pp_mysql_index_exists($conn, 'users', 'uniq_users_referral_code') === false) {
            @$conn->query("CREATE UNIQUE INDEX `uniq_users_referral_code` ON `users`(`referral_code`)");
        }
        $usersCols = $getCols('users');
    }
    if (!empty($usersCols) && !isset($usersCols['referral_commission_percent'])) {
        @$conn->query("ALTER TABLE `users` ADD COLUMN `referral_commission_percent` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `referral_code`");
        $usersCols = $getCols('users');
    }
    // Helpful index on referred_by
    if (pp_mysql_index_exists($conn, 'users', 'idx_users_referred_by') === false && isset($usersCols['referred_by'])) {
        @$conn->query("CREATE INDEX `idx_users_referred_by` ON `users`(`referred_by`)");
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

    // User notification preferences table
    $notifCols = $getCols('user_notification_settings');
    if (empty($notifCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `user_notification_settings` (
            `user_id` INT NOT NULL,
            `event_key` VARCHAR(64) NOT NULL,
            `enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`, `event_key`),
            INDEX `idx_user_notification_event` (`event_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
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

    // Balance change history table
    $bhCols = $getCols('balance_history');
    if (empty($bhCols)) {
        @$conn->query("CREATE TABLE IF NOT EXISTS `balance_history` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `delta` DECIMAL(12,2) NOT NULL,
            `balance_before` DECIMAL(12,2) NOT NULL,
            `balance_after` DECIMAL(12,2) NOT NULL,
            `source` VARCHAR(50) NOT NULL,
            `meta_json` LONGTEXT NULL,
            `created_by_admin_id` INT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_balance_history_user` (`user_id`),
            INDEX `idx_balance_history_source` (`source`),
            CONSTRAINT `fk_balance_history_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_balance_history_admin` FOREIGN KEY (`created_by_admin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } else {
        $ensureBalanceCol = static function(string $column, string $ddl) use ($bhCols, $conn): void {
            if (!isset($bhCols[$column])) {
                @$conn->query("ALTER TABLE `balance_history` {$ddl}");
            }
        };
        $ensureBalanceCol('user_id', "ADD COLUMN `user_id` INT NOT NULL AFTER `id`");
        $ensureBalanceCol('delta', "ADD COLUMN `delta` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `user_id`");
        $ensureBalanceCol('balance_before', "ADD COLUMN `balance_before` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `delta`");
        $ensureBalanceCol('balance_after', "ADD COLUMN `balance_after` DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER `balance_before`");
        $ensureBalanceCol('source', "ADD COLUMN `source` VARCHAR(50) NOT NULL DEFAULT 'system' AFTER `balance_after`");
        $ensureBalanceCol('meta_json', "ADD COLUMN `meta_json` LONGTEXT NULL AFTER `source`");
        $ensureBalanceCol('created_by_admin_id', "ADD COLUMN `created_by_admin_id` INT NULL AFTER `meta_json`");
        $ensureBalanceCol('created_at', "ADD COLUMN `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `created_by_admin_id`");
        if (pp_mysql_index_exists($conn, 'balance_history', 'idx_balance_history_user') === false) {
            @$conn->query("CREATE INDEX `idx_balance_history_user` ON `balance_history`(`user_id`)");
        }
        if (pp_mysql_index_exists($conn, 'balance_history', 'idx_balance_history_source') === false) {
            @$conn->query("CREATE INDEX `idx_balance_history_source` ON `balance_history`(`source`)");
        }
    }

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
}
