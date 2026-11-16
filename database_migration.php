<?php
/**
 * Database Migration Script
 * Affiliate Admin Panel & Management System Upgrade
 * Can be run from CLI or Web
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/app/config/database.php';

echo "=== Starting Database Migrations ===\n";

try {
    // 1. Roles table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `roles` (
            `role_id` INT PRIMARY KEY,
            `role_name` VARCHAR(50) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $roles = [
        1 => 'admin',
        2 => 'manager',
        3 => 'affiliate',
        4 => 'advertiser'
    ];
    $stmtRole = $pdo->prepare("INSERT IGNORE INTO `roles` (`role_id`, `role_name`) VALUES (?, ?)");
    foreach ($roles as $rId => $rName) {
        $stmtRole->execute([$rId, $rName]);
    }
    echo "✔ Roles table verified.\n";

    // 2. Users table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `user_id` INT AUTO_INCREMENT PRIMARY KEY,
            `role_id` INT NOT NULL,
            `name` VARCHAR(150) NOT NULL,
            `email` VARCHAR(150) NOT NULL UNIQUE,
            `password_hash` VARCHAR(255) NOT NULL,
            `mobile` VARCHAR(50) DEFAULT NULL,
            `company` VARCHAR(150) DEFAULT NULL,
            `telegram_id` VARCHAR(100) DEFAULT NULL,
            `skype` VARCHAR(100) DEFAULT NULL,
            `country` VARCHAR(100) DEFAULT NULL,
            `city` VARCHAR(100) DEFAULT NULL,
            `address` TEXT DEFAULT NULL,
            `zip` VARCHAR(20) DEFAULT NULL,
            `status` ENUM('active','pending','blocked','rejected') NOT NULL DEFAULT 'pending',
            `kyc_status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            `payout_enabled` TINYINT(1) NOT NULL DEFAULT 1,
            `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `account_manager_id` INT DEFAULT NULL,
            `api_key` VARCHAR(64) DEFAULT NULL,
            `last_login_ip` VARCHAR(45) DEFAULT NULL,
            `last_login_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_role` (`role_id`),
            INDEX `idx_status` (`status`),
            INDEX `idx_manager` (`account_manager_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✔ Users table verified.\n";

    // 3. Account Managers compatibility table
    $pdo->exec("
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
    ");
    echo "✔ Account Managers table verified.\n";

    // 4. Offers table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `offers` (
            `offer_id` INT AUTO_INCREMENT PRIMARY KEY,
            `advertiser_id` INT DEFAULT NULL,
            `offer_name` VARCHAR(255) NOT NULL,
            `offer_description` TEXT DEFAULT NULL,
            `offer_url` TEXT NOT NULL,
            `campaign_url` TEXT DEFAULT NULL,
            `category` VARCHAR(100) DEFAULT 'General',
            `payout` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `payout_type` VARCHAR(50) NOT NULL DEFAULT 'CPA',
            `revenue` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
            `status` VARCHAR(50) NOT NULL DEFAULT 'active',
            `visibility` VARCHAR(50) NOT NULL DEFAULT 'public',
            `geo` VARCHAR(255) DEFAULT 'Global',
            `country` VARCHAR(255) DEFAULT 'Global',
            `device_type` VARCHAR(100) DEFAULT 'All',
            `daily_cap` INT NOT NULL DEFAULT 0,
            `total_cap` INT NOT NULL DEFAULT 0,
            `start_date` DATETIME DEFAULT NULL,
            `end_date` DATETIME DEFAULT NULL,
            `postback_token` VARCHAR(100) DEFAULT NULL,
            `conversion_tracking` VARCHAR(50) DEFAULT 'postback',
            `terms_required` TINYINT(1) DEFAULT 0,
            `internal_note` TEXT DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_adv` (`advertiser_id`),
            INDEX `idx_status` (`status`),
            INDEX `idx_visibility` (`visibility`),
            INDEX `idx_currency` (`currency`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Ensure currency column exists in offers
    $cols = $pdo->query("SHOW COLUMNS FROM `offers` LIKE 'currency'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `offers` ADD COLUMN `currency` VARCHAR(10) NOT NULL DEFAULT 'USD' AFTER `revenue`");
        echo "✔ Added currency column to offers.\n";
    }
    echo "✔ Offers table verified.\n";

    // 5. Clicks table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `clicks` (
            `click_id` VARCHAR(64) PRIMARY KEY,
            `offer_id` INT NOT NULL,
            `affiliate_id` INT NOT NULL,
            `sub1` VARCHAR(255) DEFAULT NULL,
            `sub2` VARCHAR(255) DEFAULT NULL,
            `sub3` VARCHAR(255) DEFAULT NULL,
            `sub4` VARCHAR(255) DEFAULT NULL,
            `sub5` VARCHAR(255) DEFAULT NULL,
            `ip_address` VARBINARY(16) DEFAULT NULL,
            `user_agent` VARCHAR(255) DEFAULT NULL,
            `referer` VARCHAR(500) DEFAULT NULL,
            `device` VARCHAR(50) DEFAULT 'desktop',
            `country` VARCHAR(10) DEFAULT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_offer` (`offer_id`),
            INDEX `idx_affiliate` (`affiliate_id`),
            INDEX `idx_sub1` (`sub1`),
            INDEX `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✔ Clicks table verified.\n";

    // 6. Conversions table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `conversions` (
            `conversion_id` INT AUTO_INCREMENT PRIMARY KEY,
            `click_id` VARCHAR(64) NOT NULL,
            `offer_id` INT NOT NULL,
            `affiliate_id` INT NOT NULL,
            `advertiser_id` INT DEFAULT NULL,
            `payout` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `revenue` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `status` ENUM('approved','pending','rejected') NOT NULL DEFAULT 'pending',
            `transaction_id` VARCHAR(100) DEFAULT NULL,
            `source` VARCHAR(50) DEFAULT 'postback',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_click` (`click_id`),
            INDEX `idx_offer` (`offer_id`),
            INDEX `idx_aff` (`affiliate_id`),
            INDEX `idx_status` (`status`),
            INDEX `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✔ Conversions table verified.\n";

    // 7. Affiliate Offer Approval table
    $pdo->exec("
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
    ");

    $checkSusp = $pdo->query("SHOW COLUMNS FROM `affiliate_offer_approval` LIKE 'suspended_by_user_block'")->fetchAll();
    if (empty($checkSusp)) {
        $pdo->exec("ALTER TABLE `affiliate_offer_approval` ADD COLUMN `suspended_by_user_block` TINYINT(1) NOT NULL DEFAULT 0 AFTER `notes`");
        echo "✔ Added suspended_by_user_block to affiliate_offer_approval.\n";
    }
    echo "✔ Affiliate Offer Approval table verified.\n";

    // 8. Manager Permissions table
    $pdo->exec("
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
    ");

    $permColsNeeded = [
        'can_view_affiliates'        => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_create_affiliates'      => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_edit_affiliates'        => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_approve_affiliates'     => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_block_affiliates'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_export_affiliates'      => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_view_campaigns'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_assign_offers'          => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_edit_campaign_payouts'  => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_manage_campaign_access' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_view_affiliate_reports' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_view_advertiser_reports'=> 'TINYINT(1) NOT NULL DEFAULT 0',
        'can_view_campaign_reports'  => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_view_click_reports'     => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_view_all_reports'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'can_export_reports'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_manage_affiliates'      => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_link_tester'            => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_manage_postbacks'       => 'TINYINT(1) NOT NULL DEFAULT 1',
        'can_view_financials'        => 'TINYINT(1) NOT NULL DEFAULT 1'
    ];

    foreach ($permColsNeeded as $col => $typeDef) {
        $checkCol = $pdo->query("SHOW COLUMNS FROM `manager_permissions` LIKE '$col'")->fetchAll();
        if (empty($checkCol)) {
            $pdo->exec("ALTER TABLE `manager_permissions` ADD COLUMN `$col` $typeDef");
            echo "✔ Added column $col to manager_permissions.\n";
        }
    }
    echo "✔ Manager Permissions table verified.\n";

    // 9. Admin Logs / Audit Logs table
    $pdo->exec("
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
    ");
    echo "✔ Admin Logs table verified.\n";

    // 10. Password Resets
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `password_resets` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `email` VARCHAR(255) NOT NULL,
            `token` VARCHAR(100) NOT NULL UNIQUE,
            `expires_at` DATETIME NOT NULL,
            `used` TINYINT(1) DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_token` (`token`),
            INDEX `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✔ Password Resets table verified.\n";

    // 11. Affiliate Postbacks
    $pdo->exec("
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
    ");
    echo "✔ Affiliate Postbacks table verified.\n";

    // 12. Error logs table
    $pdo->exec("
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
    echo "✔ Error Logs table verified.\n";

    // 13. Affiliate Bank Details
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `affiliate_bank_details` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `affiliate_id` INT NOT NULL UNIQUE,
            `bank_name` VARCHAR(150) DEFAULT NULL,
            `account_holder` VARCHAR(150) DEFAULT NULL,
            `account_number` VARCHAR(100) DEFAULT NULL,
            `ifsc_code` VARCHAR(50) DEFAULT NULL,
            `upi_id` VARCHAR(100) DEFAULT NULL,
            `is_verified` TINYINT(1) DEFAULT 0,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_aff` (`affiliate_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "✔ Affiliate Bank Details table verified.\n";

    // 14. Check if demo/initial users exist
    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
    if ($userCount === 0) {
        echo "Creating initial seed users & test records...\n";
        $defaultPassword = password_hash('Admin@123456', PASSWORD_DEFAULT);

        // Insert Admin (ID 1)
        $pdo->prepare("
            INSERT INTO `users` (`user_id`, `role_id`, `name`, `email`, `password_hash`, `status`, `kyc_status`, `balance`, `created_at`)
            VALUES (1, 1, 'Super Admin', 'admin@offeronmedia.com', ?, 'active', 'approved', 0.00, NOW())
        ")->execute([$defaultPassword]);

        // Insert Account Managers (Role ID 2)
        $pdo->prepare("
            INSERT INTO `users` (`user_id`, `role_id`, `name`, `email`, `password_hash`, `mobile`, `telegram_id`, `status`, `kyc_status`, `created_at`)
            VALUES 
            (2, 2, 'Alex Carter (Manager)', 'alex@offeronmedia.com', ?, '+1 555-0101', '@alex_c', 'active', 'approved', NOW()),
            (3, 2, 'Sarah Jenkins (Manager)', 'sarah@offeronmedia.com', ?, '+1 555-0102', '@sarah_j', 'active', 'approved', NOW())
        ")->execute([$defaultPassword, $defaultPassword]);

        // Mirror in account_managers table
        $pdo->exec("
            INSERT IGNORE INTO `account_managers` (`id`, `user_id`, `name`, `email`, `telegram`, `status`)
            VALUES 
            (2, 2, 'Alex Carter (Manager)', 'alex@offeronmedia.com', '@alex_c', 'active'),
            (3, 3, 'Sarah Jenkins (Manager)', 'sarah@offeronmedia.com', '@sarah_j', 'active')
        ");

        // Insert Advertisers (Role ID 4)
        $pdo->prepare("
            INSERT INTO `users` (`user_id`, `role_id`, `name`, `email`, `password_hash`, `company`, `status`, `kyc_status`, `created_at`)
            VALUES 
            (4, 4, 'Apex Digital Advertisers', 'adv@apexdigital.com', ?, 'Apex Digital Inc', 'active', 'approved', NOW()),
            (5, 4, 'Global Media Group', 'adv@globalmedia.com', ?, 'Global Media LLC', 'active', 'approved', NOW())
        ")->execute([$defaultPassword, $defaultPassword]);

        // Insert Affiliates / Publishers (Role ID 3)
        $pdo->prepare("
            INSERT INTO `users` (`user_id`, `role_id`, `name`, `email`, `password_hash`, `company`, `status`, `kyc_status`, `account_manager_id`, `balance`, `created_at`)
            VALUES 
            (6, 3, 'John TrafficPro', 'john@trafficpro.com', ?, 'TrafficPro Media', 'active', 'approved', 2, 450.00, NOW()),
            (7, 3, 'Priya Marketing India', 'priya@indiapush.com', ?, 'IndiaPush Digital', 'active', 'approved', 2, 25000.00, NOW()),
            (8, 3, 'David LeadGen', 'david@leadgen.com', ?, 'LeadGen Masters', 'active', 'approved', 3, 120.00, NOW()),
            (9, 3, 'Blocked Publisher Demo', 'blocked@test.com', ?, 'Suspended Ops', 'blocked', 'rejected', 2, 0.00, NOW())
        ")->execute([$defaultPassword, $defaultPassword, $defaultPassword, $defaultPassword]);

        // Insert Offers with USD and INR currencies
        $pdo->exec("
            INSERT INTO `offers` 
            (`offer_id`, `advertiser_id`, `offer_name`, `offer_description`, `offer_url`, `campaign_url`, `category`, `payout`, `revenue`, `currency`, `status`, `visibility`, `geo`, `created_at`)
            VALUES 
            (101, 4, 'Finance Pro App Install [US/Global]', 'High converting mobile fintech app registration CPA.', 'https://tracking.advertiser.com/landing?click_id={click_id}&sub1={sub1}', 'https://offeronmedia.com/click.php?offer=101&aff={aff_id}', 'Finance', 4.50, 6.00, 'USD', 'active', 'public', 'US,CA,UK', NOW()),
            (102, 4, 'Crypto Wallet KYC Signup [Global]', 'Crypto wallet install and verified KYC signup.', 'https://tracking.advertiser.com/crypto?click_id={click_id}&sub_aff_id={sub1}', 'https://offeronmedia.com/click.php?offer=102&aff={aff_id}', 'Crypto', 15.00, 20.00, 'USD', 'active', 'public', 'Global', NOW()),
            (103, 5, 'Rummy Play India Direct APK [IN]', 'Indian top rummy gaming install with first deposit.', 'https://game.indianrummy.in/download?click_id={click_id}&aff_sub1={sub1}', 'https://offeronmedia.com/click.php?offer=103&aff={aff_id}', 'Gaming', 250.00, 350.00, 'INR', 'active', 'public', 'IN', NOW()),
            (104, 5, 'Bharat DMAT Account Open [IN]', 'Online demat account opening for Indian stock traders.', 'https://broker.indiastocks.com/signup?cid={click_id}&sub={sub1}', 'https://offeronmedia.com/click.php?offer=104&aff={aff_id}', 'Finance', 400.00, 550.00, 'INR', 'active', 'private', 'IN', NOW()),
            (105, 4, 'Old Deprecated Campaign (Archived)', 'Legacy archived offer that should not accept new traffic.', 'https://old.campaign.com/?id={click_id}', 'https://offeronmedia.com/click.php?offer=105&aff={aff_id}', 'E-Commerce', 2.00, 3.00, 'USD', 'archived', 'public', 'US', NOW())
        ");

        // Insert Approvals for Private Offers
        $pdo->exec("
            INSERT INTO `affiliate_offer_approval` (`affiliate_id`, `offer_id`, `status`, `approved_at`)
            VALUES 
            (6, 104, 'approved', NOW()),
            (7, 104, 'approved', NOW()),
            (8, 104, 'pending', NULL)
        ");

        // Insert Sample Tracking Clicks with SubIDs 1..5
        $pdo->exec("
            INSERT INTO `clicks` (`click_id`, `offer_id`, `affiliate_id`, `sub1`, `sub2`, `sub3`, `sub4`, `sub5`, `device`, `country`, `created_at`)
            VALUES 
            ('clk_usd_001_aaa111', 101, 6, 'google_ads', 'campaign_us', 'adgroup_fin', 'kw_loan', 'banner_top', 'mobile', 'US', DATE_SUB(NOW(), INTERVAL 2 DAY)),
            ('clk_usd_002_aaa222', 101, 6, 'google_ads', 'campaign_us', 'adgroup_fin', 'kw_loan', 'banner_sidebar', 'desktop', 'US', DATE_SUB(NOW(), INTERVAL 2 DAY)),
            ('clk_usd_003_aaa333', 101, 6, 'facebook', 'retargeting', 'audiences_25_45', 'img_blue', 'feed', 'mobile', 'CA', DATE_SUB(NOW(), INTERVAL 1 DAY)),
            ('clk_usd_004_aaa444', 102, 6, 'native_taboola', 'crypto_hub', 'headline_1', 'creative_v2', 'widget_99', 'desktop', 'UK', NOW()),
            ('clk_inr_005_bbb111', 103, 7, 'telegram_chan', 'rummy_vip', 'post_morning', 'btn_play', 'chan_77', 'mobile', 'IN', DATE_SUB(NOW(), INTERVAL 3 DAY)),
            ('clk_inr_006_bbb222', 103, 7, 'telegram_chan', 'rummy_vip', 'post_evening', 'btn_play', 'chan_77', 'mobile', 'IN', DATE_SUB(NOW(), INTERVAL 1 DAY)),
            ('clk_inr_007_bbb333', 104, 7, 'whatsapp_grp', 'stocks_daily', 'msg_buy', 'link_dmat', 'grp_12', 'mobile', 'IN', NOW()),
            ('clk_usd_008_ccc111', 101, 8, 'youtube_desc', 'video_budget', 'pinned_comm', 'rev_link', 'card', 'mobile', 'US', NOW())
        ");

        // Insert Conversions for some clicks
        $pdo->exec("
            INSERT INTO `conversions` (`click_id`, `offer_id`, `affiliate_id`, `advertiser_id`, `payout`, `revenue`, `status`, `transaction_id`, `source`, `created_at`)
            VALUES 
            ('clk_usd_001_aaa111', 101, 6, 4, 4.50, 6.00, 'approved', 'tx_usd_9901', 'postback', DATE_SUB(NOW(), INTERVAL 2 DAY)),
            ('clk_usd_003_aaa333', 101, 6, 4, 4.50, 6.00, 'approved', 'tx_usd_9902', 'postback', DATE_SUB(NOW(), INTERVAL 1 DAY)),
            ('clk_inr_005_bbb111', 103, 7, 5, 250.00, 350.00, 'approved', 'tx_inr_8801', 'postback', DATE_SUB(NOW(), INTERVAL 3 DAY)),
            ('clk_inr_007_bbb333', 104, 7, 5, 400.00, 550.00, 'approved', 'tx_inr_8802', 'postback', NOW())
        ");

        echo "✔ Initial seed records created successfully!\n";
    }

    echo "=== All Migrations Completed Successfully! ===\n";

} catch (Exception $e) {
    echo "❌ Migration Error: " . $e->getMessage() . "\n";
    exit(1);
}

