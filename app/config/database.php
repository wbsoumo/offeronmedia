<?php
/**
 * Database connection (PHP 7.1 compatible)
 */

if (!defined('APP_INIT')) {
    die('Direct access not allowed');
}

$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'connectpaw_offeronmedia';
$DB_USER = getenv('DB_USER') ?: 'connectpaw_pawan';
$DB_PASS = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ']OiEKD9*FSQJPaaa';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Fallback to alternate production database credentials
    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname=zktddbzk_offeronmedia;charset=utf8mb4",
            'zktddbzk_azhar',
            'Soumojit1234@',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e2) {
        error_log('DB Connection failed: ' . $e->getMessage());
        die('Database connection error');
    }
}

/**
 * Auto-ensure all required database tables and columns exist (Self-healing schema)
 */
function auto_ensure_all_schema_tables($pdo)
{
    static $executed = false;
    if ($executed || !$pdo) {
        return;
    }
    $executed = true;

    try {
        // 1. Core and Enhancement Tables
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `roles` (
                `role_id` INT PRIMARY KEY,
                `role_name` VARCHAR(50) NOT NULL UNIQUE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `admin_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `admin_id` INT NOT NULL,
                `admin_name` VARCHAR(150) DEFAULT NULL,
                `action` VARCHAR(100) NOT NULL,
                `target_type` VARCHAR(50) DEFAULT NULL,
                `target_id` INT DEFAULT NULL,
                `target_name` VARCHAR(150) DEFAULT NULL,
                `details` TEXT DEFAULT NULL,
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_admin` (`admin_id`),
                INDEX `idx_action` (`action`),
                INDEX `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `manager_permissions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `manager_id` INT NOT NULL UNIQUE,
                `can_view_affiliates` TINYINT(1) NOT NULL DEFAULT 1,
                `can_create_affiliates` TINYINT(1) NOT NULL DEFAULT 1,
                `can_edit_affiliates` TINYINT(1) NOT NULL DEFAULT 1,
                `can_approve_affiliates` TINYINT(1) NOT NULL DEFAULT 1,
                `can_block_affiliates` TINYINT(1) NOT NULL DEFAULT 1,
                `can_export_affiliates` TINYINT(1) NOT NULL DEFAULT 1,
                `can_view_campaigns` TINYINT(1) NOT NULL DEFAULT 1,
                `can_assign_offers` TINYINT(1) NOT NULL DEFAULT 1,
                `can_edit_campaign_payouts` TINYINT(1) NOT NULL DEFAULT 1,
                `can_manage_campaign_access` TINYINT(1) NOT NULL DEFAULT 1,
                `can_view_affiliate_reports` TINYINT(1) NOT NULL DEFAULT 1,
                `can_view_advertiser_reports` TINYINT(1) NOT NULL DEFAULT 0,
                `can_view_campaign_reports` TINYINT(1) NOT NULL DEFAULT 1,
                `can_view_click_reports` TINYINT(1) NOT NULL DEFAULT 1,
                `can_view_all_reports` TINYINT(1) NOT NULL DEFAULT 0,
                `can_export_reports` TINYINT(1) NOT NULL DEFAULT 1,
                `can_manage_affiliates` TINYINT(1) NOT NULL DEFAULT 1,
                `can_link_tester` TINYINT(1) NOT NULL DEFAULT 1,
                `can_manage_postbacks` TINYINT(1) NOT NULL DEFAULT 1,
                `can_view_financials` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_manager_id` (`manager_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `account_managers` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT DEFAULT NULL,
                `name` VARCHAR(150) NOT NULL,
                `email` VARCHAR(150) NOT NULL,
                `skype` VARCHAR(100) DEFAULT NULL,
                `telegram` VARCHAR(100) DEFAULT NULL,
                `status` ENUM('active','inactive') DEFAULT 'active',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `affiliate_offer_approval` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `affiliate_id` INT NOT NULL,
                `offer_id` INT NOT NULL,
                `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
                `custom_payout` DECIMAL(10,2) DEFAULT NULL,
                `notes` TEXT DEFAULT NULL,
                `suspended_by_user_block` TINYINT(1) NOT NULL DEFAULT 0,
                `approved_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_aff` (`affiliate_id`),
                INDEX `idx_offer` (`offer_id`),
                INDEX `idx_status` (`status`),
                UNIQUE KEY `uniq_aff_offer` (`affiliate_id`, `offer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `affiliate_postbacks` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `affiliate_id` INT NOT NULL,
                `offer_id` INT DEFAULT NULL,
                `postback_url` TEXT NOT NULL,
                `postback_type` VARCHAR(50) DEFAULT 'global',
                `postback_name` VARCHAR(100) DEFAULT NULL,
                `status` VARCHAR(20) DEFAULT 'active',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_aff` (`affiliate_id`),
                INDEX `idx_offer` (`offer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS `error_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `error_code` INT DEFAULT NULL,
                `requested_url` TEXT DEFAULT NULL,
                `referrer_url` TEXT DEFAULT NULL,
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `user_agent` TEXT DEFAULT NULL,
                `user_id` INT DEFAULT NULL,
                `user_role` VARCHAR(50) DEFAULT NULL,
                `request_method` VARCHAR(10) DEFAULT NULL,
                `query_string` TEXT DEFAULT NULL,
                `channel` VARCHAR(50) DEFAULT 'system',
                `message` TEXT DEFAULT NULL,
                `context` TEXT DEFAULT NULL,
                `logged_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // 2. Insert Default Roles if empty
        $pdo->exec("
            INSERT IGNORE INTO `roles` (`role_id`, `role_name`) VALUES 
            (1, 'admin'), (2, 'manager'), (3, 'affiliate'), (4, 'advertiser');
        ");

        // 3. Ensure Columns in `users`
        $uCols = $pdo->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('skype', $uCols, true)) {
            try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `skype` VARCHAR(100) DEFAULT NULL AFTER `telegram_id`"); } catch (Exception $e) {}
        }
        if (!in_array('telegram_id', $uCols, true)) {
            try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `telegram_id` VARCHAR(100) DEFAULT NULL AFTER `company`"); } catch (Exception $e) {}
        }
        if (!in_array('mobile', $uCols, true)) {
            try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `mobile` VARCHAR(50) DEFAULT NULL AFTER `password_hash`"); } catch (Exception $e) {}
        }
        if (!in_array('company', $uCols, true)) {
            try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `company` VARCHAR(150) DEFAULT NULL AFTER `mobile`"); } catch (Exception $e) {}
        }
        if (!in_array('account_manager_id', $uCols, true)) {
            try { $pdo->exec("ALTER TABLE `users` ADD COLUMN `account_manager_id` INT DEFAULT NULL AFTER `balance`"); } catch (Exception $e) {}
        }

        // 4. Ensure Columns in `offers`
        $offCols = $pdo->query("SHOW COLUMNS FROM `offers`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('currency', $offCols, true)) {
            try { $pdo->exec("ALTER TABLE `offers` ADD COLUMN `currency` VARCHAR(10) NOT NULL DEFAULT 'USD' AFTER `revenue`"); } catch (Exception $e) {}
        }

        // 5. Ensure Columns in `affiliate_offer_approval`
        $aoaCols = $pdo->query("SHOW COLUMNS FROM `affiliate_offer_approval`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('suspended_by_user_block', $aoaCols, true)) {
            try { $pdo->exec("ALTER TABLE `affiliate_offer_approval` ADD COLUMN `suspended_by_user_block` TINYINT(1) NOT NULL DEFAULT 0 AFTER `notes`"); } catch (Exception $e) {}
        }
        if (!in_array('custom_payout', $aoaCols, true)) {
            try { $pdo->exec("ALTER TABLE `affiliate_offer_approval` ADD COLUMN `custom_payout` DECIMAL(10,2) DEFAULT NULL"); } catch (Exception $e) {}
        }
        if (!in_array('notes', $aoaCols, true)) {
            try { $pdo->exec("ALTER TABLE `affiliate_offer_approval` ADD COLUMN `notes` TEXT DEFAULT NULL"); } catch (Exception $e) {}
        }
        if (!in_array('approved_at', $aoaCols, true)) {
            try { $pdo->exec("ALTER TABLE `affiliate_offer_approval` ADD COLUMN `approved_at` DATETIME DEFAULT NULL"); } catch (Exception $e) {}
        }

        // 6. Ensure Columns in `account_managers`
        $amCols = $pdo->query("SHOW COLUMNS FROM `account_managers`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('user_id', $amCols, true)) {
            try { $pdo->exec("ALTER TABLE `account_managers` ADD COLUMN `user_id` INT DEFAULT NULL AFTER `id`"); } catch (Exception $e) {}
        }

    } catch (Exception $e) {
        // Silently continue if restricted
    }
}

// Automatically ensure schema on connection
if (isset($pdo) && $pdo instanceof PDO) {
    auto_ensure_all_schema_tables($pdo);
}
