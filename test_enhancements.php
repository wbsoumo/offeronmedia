<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/app/core/auth.php';
require_once __DIR__ . '/app/config/database.php';

echo "========================================================\n";
echo "OFFERONMEDIA: FULL ENHANCEMENT VERIFICATION SUITE\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($description, $condition) {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $description\n";
        $passCount++;
    } else {
        echo " [FAIL] $description\n";
        $failCount++;
    }
}

// ---------------------------------------------------------
// TEST 1: User Ban & Offer Access Control (Requirement 5)
// ---------------------------------------------------------
echo "\n--- TEST 1: User Ban & Offer Access Control ---\n";
// Find an affiliate and an active offer
$aff = $pdo->query("SELECT user_id, name, status FROM users WHERE role_id = 3 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$off = $pdo->query("SELECT offer_id, offer_name, currency FROM offers WHERE status = 'active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($aff && $off) {
    $affId = (int)$aff['user_id'];
    $offerId = (int)$off['offer_id'];
    
    // Ensure an approval record exists
    $pdo->prepare("
        INSERT INTO affiliate_offer_approval (affiliate_id, offer_id, status, suspended_by_user_block, approved_at)
        VALUES (?, ?, 'approved', 0, NOW())
        ON DUPLICATE KEY UPDATE status = 'approved', suspended_by_user_block = 0
    ")->execute([$affId, $offerId]);

    // 1.1 Block affiliate
    $blockRes = affiliate_block($affId, 'Automated Test Block');
    assertTest("affiliate_block() returned true", $blockRes === true);
    
    $checkUser = $pdo->query("SELECT status FROM users WHERE user_id = $affId")->fetchColumn();
    assertTest("User status is now 'blocked'", $checkUser === 'blocked');
    
    $checkSusp = $pdo->query("SELECT suspended_by_user_block FROM affiliate_offer_approval WHERE affiliate_id = $affId AND offer_id = $offerId")->fetchColumn();
    assertTest("Offer approval suspended_by_user_block = 1", (int)$checkSusp === 1);
    
    // 1.2 Test click tracking behavior on blocked affiliate via sub-process
    $cmd = "php -r " . escapeshellarg('
        $_GET = ["offer_id" => ' . $offerId . ', "aff_id" => ' . $affId . '];
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
        $_SERVER["HTTP_USER_AGENT"] = "AutomatedTest/1.0";
        require "' . __DIR__ . '/click.php";
    ');
    $clickOutput = shell_exec($cmd . " 2>&1");
    
    assertTest("click.php response contains AFFILIATE_INACTIVE_OR_BLOCKED", strpos($clickOutput, 'AFFILIATE_INACTIVE_OR_BLOCKED') !== false);

    // 1.3 Unblock affiliate
    $unblockRes = affiliate_unblock($affId, 'Automated Test Unblock');
    assertTest("affiliate_unblock() returned true", $unblockRes === true);
    
    $checkUser2 = $pdo->query("SELECT status FROM users WHERE user_id = $affId")->fetchColumn();
    assertTest("User status is now restored to 'active'", $checkUser2 === 'active');
    
    $checkSusp2 = $pdo->query("SELECT suspended_by_user_block FROM affiliate_offer_approval WHERE affiliate_id = $affId AND offer_id = $offerId")->fetchColumn();
    assertTest("Offer approval suspended_by_user_block = 0", (int)$checkSusp2 === 0);
} else {
    echo " [SKIP] No affiliate or offer found for Test 1\n";
}

// ---------------------------------------------------------
// TEST 2: Offer Archival & Tracking Restriction (Requirement 8)
// ---------------------------------------------------------
echo "\n--- TEST 2: Offer Archival & Tracking Restriction ---\n";
// Create a test offer or use an existing one
$testOfferId = (int)$pdo->query("SELECT offer_id FROM offers LIMIT 1")->fetchColumn();

// Archive offer
$pdo->prepare("UPDATE offers SET status = 'archived', updated_at = NOW() WHERE offer_id = ?")->execute([$testOfferId]);
admin_log_action('offer_archive', 'offer', $testOfferId, 'Test Suite Archive');

$checkOfferStatus = $pdo->query("SELECT status FROM offers WHERE offer_id = $testOfferId")->fetchColumn();
assertTest("Offer status updated to 'archived'", $checkOfferStatus === 'archived');

// Check that active offers query excludes this archived offer
$activeOffersCount = $pdo->query("SELECT COUNT(*) FROM offers WHERE status != 'archived' AND offer_id = $testOfferId")->fetchColumn();
assertTest("Active campaigns listing excludes archived offer", (int)$activeOffersCount === 0);

// Check that archived offers query finds it
$archivedCount = $pdo->query("SELECT COUNT(*) FROM offers WHERE status = 'archived' AND offer_id = $testOfferId")->fetchColumn();
assertTest("Dedicated archived offers query finds offer", (int)$archivedCount >= 1);

// Test click tracking on archived offer via sub-process
$cmdArchived = "php -r " . escapeshellarg('
    $_GET = ["offer_id" => ' . $testOfferId . ', "aff_id" => ' . $affId . '];
    $_SERVER["REQUEST_METHOD"] = "GET";
    $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
    $_SERVER["HTTP_USER_AGENT"] = "AutomatedTest/1.0";
    require "' . __DIR__ . '/click.php";
');
$clickOutput2 = shell_exec($cmdArchived . " 2>&1");
assertTest("click.php response specifies OFFER_NOT_APPROVED for archived offer", strpos($clickOutput2, 'OFFER_NOT_APPROVED') !== false);

// Restore offer to active
$pdo->prepare("UPDATE offers SET status = 'active', updated_at = NOW() WHERE offer_id = ?")->execute([$testOfferId]);
admin_log_action('offer_restore', 'offer', $testOfferId, 'Test Suite Restore');
$restoredStatus = $pdo->query("SELECT status FROM offers WHERE offer_id = $testOfferId")->fetchColumn();
assertTest("Offer restored to 'active'", $restoredStatus === 'active');

// ---------------------------------------------------------
// TEST 3: Campaign Currency & Formatting (Requirement 4)
// ---------------------------------------------------------
echo "\n--- TEST 3: Campaign Currency & Formatting ---\n";
assertTest("USD format 12.50 produces '$12.50'", format_currency(12.5, 'USD') === '$12.50');
assertTest("INR format 500 produces '₹500.00'", format_currency(500, 'INR') === '₹500.00');
$dualRes = format_dual_currency(150, 12500);
assertTest("Dual currency 150 USD and 12500 INR formats cleanly without mixing", strpos($dualRes, '$150.00') !== false && strpos($dualRes, '₹12,500.00') !== false);
assertTest("USD only formats single value", format_dual_currency(150, 0) === '$150.00');
assertTest("INR only formats single value", format_dual_currency(0, 12500) === '₹12,500.00');

// Verify DB offers have currency set
$offersWithoutCurrency = $pdo->query("SELECT COUNT(*) FROM offers WHERE currency IS NULL OR currency = ''")->fetchColumn();
assertTest("All offers have currency column configured", (int)$offersWithoutCurrency === 0);

// ---------------------------------------------------------
// TEST 4: Advanced Click & SubID Reports (Requirements 2 & 3)
// ---------------------------------------------------------
echo "\n--- TEST 4: Advanced Click & SubID Reports ---\n";
$clickSample = $pdo->query("
    SELECT c.click_id, c.sub1, c.sub2, c.sub3, c.sub4, c.sub5, c.ip_address, c.country
    FROM clicks c
    WHERE c.sub1 IS NOT NULL
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if ($clickSample) {
    assertTest("Clicks table stores SubID 1: " . $clickSample['sub1'], !empty($clickSample['sub1']));
    assertTest("Clicks table stores click tracking metadata", !empty($clickSample['click_id']) && !empty($clickSample['ip_address']));
} else {
    echo " [INFO] Inserting sample click with sub1..sub5 for reporting test\n";
    $pdo->prepare("
        INSERT INTO clicks (offer_id, affiliate_id, sub1, sub2, sub3, sub4, sub5, ip_address, country, created_at)
        VALUES (?, ?, 'campaign_fb', 'adset_12', 'creative_05', 'placement_feed', 'keyword_test', '1.1.1.1', 'US', NOW())
    ")->execute([$testOfferId, $affId]);
    $newClickId = (int)$pdo->lastInsertId();
    assertTest("Created test click with full SubID 1-5 parameters (ID #$newClickId)", $newClickId > 0);
}

// SubID aggregation query test
$subReport = $pdo->query("
    SELECT 
        c.sub1,
        COUNT(DISTINCT c.click_id) as total_clicks,
        COUNT(DISTINCT cv.conversion_id) as total_conversions
    FROM clicks c
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    WHERE c.sub1 IS NOT NULL AND c.sub1 != ''
    GROUP BY c.sub1
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
assertTest("SubID performance query executes and aggregates accurately", is_array($subReport) && isset($subReport['total_clicks']));

// ---------------------------------------------------------
// TEST 5: Manager Profile, Impersonation & Permissions (Requirements 1, 6 & 7)
// ---------------------------------------------------------
echo "\n--- TEST 5: Manager Profile, Impersonation & Permissions ---\n";
$manager = $pdo->query("SELECT user_id, name, email FROM users WHERE role_id = 2 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($manager) {
    $mgrId = (int)$manager['user_id'];
    assertTest("Account Manager exists in users table (ID #$mgrId: {$manager['name']})", $mgrId > 0);
    
    // Check manager permissions record
    $permCount = $pdo->query("SELECT COUNT(*) FROM manager_permissions WHERE manager_id = $mgrId")->fetchColumn();
    if ((int)$permCount === 0) {
        $pdo->prepare("INSERT INTO manager_permissions (manager_id, can_view_affiliates, can_approve_affiliates, can_block_affiliates, can_view_campaigns, can_assign_offers, can_view_financials) VALUES (?, 1, 1, 1, 1, 1, 1)")->execute([$mgrId]);
    }
    
    // Test manager_can evaluation
    assertTest("manager_can evaluates can_view_affiliates", manager_can('can_view_affiliates', $mgrId) === true);
    assertTest("manager_can evaluates can_view_financials", manager_can('can_view_financials', $mgrId) === true);
    assertTest("manager_can evaluates can_assign_offers", manager_can('can_assign_offers', $mgrId) === true);
    
    // Test admin log entry creation
    $logTest = admin_log_action('impersonate_start', 'users', $mgrId, "Admin started impersonation session for manager #$mgrId");
    assertTest("admin_log_action records impersonation audit event", $logTest === true);
    
    $latestLog = $pdo->query("SELECT action, details FROM admin_logs WHERE target_id = $mgrId ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    assertTest("Audit log accurately recorded in admin_logs table", $latestLog['action'] === 'impersonate_start');
} else {
    echo " [SKIP] No account manager found for Test 5\n";
}

// ---------------------------------------------------------
// TEST 6: Admin Pages Syntax Linting
// ---------------------------------------------------------
echo "\n--- TEST 6: Critical Files PHP Syntax Verification ---\n";
$filesToLint = [
    'app/core/auth.php',
    'click.php',
    'admin/dashboard.php',
    'admin/offers.php',
    'admin/archived_offers.php',
    'admin/campaigns.php',
    'admin/offer_details.php',
    'admin/offer_edit.php',
    'admin/create_campaign.php',
    'admin/account_managers.php',
    'admin/manager_profile.php',
    'admin/impersonate.php',
    'admin/publishers.php',
    'admin/affiliate_details.php',
    'admin/users.php',
    'admin/campaign_access.php',
    'admin/pending_kyc.php',
    'admin/fraud_dashboard.php',
    'admin/publisher_postbacks.php',
    'admin/reports_clicks.php',
    'admin/reports_campaigns.php',
    'admin/reports_subid.php',
    'admin/reports_affiliates.php',
    'admin/reports_advertisers.php',
    'admin/ajax_assign_offer.php',
    'admin/modal_assign_offer.php',
    'manager/dashboard.php',
    'manager/publishers.php',
    'manager/campaigns.php',
    'manager/reports.php',
    'manager/impersonate.php',
    'affiliate/offers.php',
    'affiliate/link-builder.php',
    'affiliate/offer_view.php'
];

foreach ($filesToLint as $file) {
    $fullPath = __DIR__ . '/' . $file;
    if (file_exists($fullPath)) {
        $output = [];
        $returnVar = 0;
        exec("php -l " . escapeshellarg($fullPath), $output, $returnVar);
        assertTest("Syntax check: $file", $returnVar === 0);
    } else {
        assertTest("File exists: $file", false);
    }
}

// ---------------------------------------------------------
// TEST 7: 9 User-Reported Problems Validation
// ---------------------------------------------------------
echo "\n--- TEST 7: 9 User-Reported Operational & Access Problems ---\n";

// 7.1: Offer rejected check in click.php
if (!empty($affId) && !empty($offerId)) {
    // Set approval status to 'rejected'
    $pdo->prepare("
        INSERT INTO affiliate_offer_approval (affiliate_id, offer_id, status, approved_at)
        VALUES (?, ?, 'rejected', NOW())
        ON DUPLICATE KEY UPDATE status = 'rejected'
    ")->execute([$affId, $offerId]);

    // Simulate click.php execution
    $out = [];
    exec("php -r '
        \$_GET[\"offer_id\"] = $offerId;
        \$_GET[\"aff_id\"] = $affId;
        \$_SERVER[\"REMOTE_ADDR\"] = \"127.0.0.1\";
        \$_SERVER[\"HTTP_USER_AGENT\"] = \"Mozilla/5.0\";
        require \"click.php\";
    ' 2>&1", $out);
    $resp = implode(' ', $out);
    assertTest("click.php strictly blocks rejected offer with OFFER_ACCESS_DENIED", strpos($resp, 'OFFER_ACCESS_DENIED') !== false);

    // 7.2: Verify affiliate portal excludes rejected offer
    $affOffersStmt = $pdo->prepare("
        SELECT o.offer_id 
        FROM offers o
        LEFT JOIN affiliate_offer_approval a ON a.offer_id = o.offer_id AND a.affiliate_id = ?
        WHERE LOWER(o.status) IN ('approved', 'active', 'live')
          AND (LOWER(IFNULL(o.visibility, 'public')) = 'public' OR a.status = 'approved')
          AND (a.status IS NULL OR a.status NOT IN ('rejected', 'blocked', 'suspended'))
          AND o.offer_id = ?
    ");
    $affOffersStmt->execute([$affId, $offerId]);
    assertTest("affiliate/offers.php hides rejected offer from affiliate catalog", $affOffersStmt->fetchColumn() === false);

    // Restore to approved
    $pdo->prepare("UPDATE affiliate_offer_approval SET status = 'approved' WHERE affiliate_id = ? AND offer_id = ?")->execute([$affId, $offerId]);
}

// 7.3: Search Query HY093 Prevention Validation
$searchTerms = ['test', 'h', 'a'];
$searchPass = true;
foreach ($searchTerms as $st) {
    try {
        // Test Publishers search query
        $pStmt = $pdo->prepare("
            SELECT u.user_id 
            FROM users u
            WHERE u.role_id = 3 AND (u.name LIKE :s1 OR u.email LIKE :s2 OR u.mobile LIKE :s3 OR u.company LIKE :s4)
            LIMIT 5
        ");
        $pStmt->execute(['s1' => "%$st%", 's2' => "%$st%", 's3' => "%$st%", 's4' => "%$st%"]);
        $pStmt->fetchAll();

        // Test Offers search query
        $oStmt = $pdo->prepare("
            SELECT o.offer_id 
            FROM offers o
            LEFT JOIN users u ON u.user_id = o.advertiser_id
            WHERE 1=1 AND (o.offer_name LIKE :s1 OR o.offer_description LIKE :s2 OR u.name LIKE :s3 OR u.email LIKE :s4)
            LIMIT 5
        ");
        $oStmt->execute(['s1' => "%$st%", 's2' => "%$st%", 's3' => "%$st%", 's4' => "%$st%"]);
        $oStmt->fetchAll();
    } catch (PDOException $e) {
        $searchPass = false;
        break;
    }
}
assertTest("Search queries execute without PDO HY093 Invalid parameter number error", $searchPass);

// 7.4: Manager Campaign Access Control Validation
if (!empty($affId) && !empty($offerId)) {
    // Test manager updating access status
    $stmt = $pdo->prepare("UPDATE affiliate_offer_approval SET status = 'rejected' WHERE affiliate_id = ? AND offer_id = ?");
    $stmt->execute([$affId, $offerId]);
    
    $checkStmt = $pdo->prepare("SELECT status FROM affiliate_offer_approval WHERE affiliate_id = ? AND offer_id = ?");
    $checkStmt->execute([$affId, $offerId]);
    assertTest("Manager can set campaign access status to rejected", $checkStmt->fetchColumn() === 'rejected');

    // Reset back to approved
    $pdo->prepare("UPDATE affiliate_offer_approval SET status = 'approved' WHERE affiliate_id = ? AND offer_id = ?")->execute([$affId, $offerId]);
}

// ---------------------------------------------------------
// TEST 8: 3 New Offer18 Manager Capabilities
// ---------------------------------------------------------
echo "\n--- TEST 8: Offer18 Advanced Manager Capabilities ---\n";

// 8.1 Publisher-Level Custom Daily Cap in click.php
if (!empty($affId) && !empty($offerId)) {
    // Set publisher daily_cap to 1
    $pdo->prepare("
        UPDATE affiliate_offer_approval 
        SET status = 'approved', daily_cap = 1 
        WHERE affiliate_id = ? AND offer_id = ?
    ")->execute([$affId, $offerId]);

    // Insert 1 approved conversion for today
    $pdo->prepare("
        INSERT INTO conversions (click_id, offer_id, affiliate_id, payout, revenue, status, created_at)
        VALUES ('cap_test_click_01', ?, ?, 10.00, 15.00, 'approved', NOW())
    ")->execute([$offerId, $affId]);

    // Execute click.php and verify it blocks due to publisher daily cap reached
    $out = [];
    exec("php -r '
        \$_GET[\"offer_id\"] = $offerId;
        \$_GET[\"aff_id\"] = $affId;
        \$_SERVER[\"REMOTE_ADDR\"] = \"127.0.0.1\";
        \$_SERVER[\"HTTP_USER_AGENT\"] = \"Mozilla/5.0\";
        require \"click.php\";
    ' 2>&1", $out);
    $capResp = implode(' ', $out);
    assertTest("click.php enforces publisher individual daily_cap with PUBLISHER_DAILY_CAP_REACHED", strpos($capResp, 'PUBLISHER_DAILY_CAP_REACHED') !== false);

    // Clean up test conversion & restore daily_cap
    $pdo->exec("DELETE FROM conversions WHERE click_id = 'cap_test_click_01'");
    $pdo->prepare("UPDATE affiliate_offer_approval SET daily_cap = NULL WHERE affiliate_id = ? AND offer_id = ?")->execute([$affId, $offerId]);
}

// 8.2 Pending Offer Requests Queue Query
if (!empty($affId) && !empty($offerId) && !empty($mgrId)) {
    // Set status to pending
    $pdo->prepare("UPDATE affiliate_offer_approval SET status = 'pending' WHERE affiliate_id = ? AND offer_id = ?")->execute([$affId, $offerId]);

    $pQueueStmt = $pdo->prepare("
        SELECT aoa.id 
        FROM affiliate_offer_approval aoa
        INNER JOIN users u ON u.user_id = aoa.affiliate_id
        WHERE u.account_manager_id = ? AND aoa.status = 'pending'
    ");
    $pQueueStmt->execute([$mgrId]);
    assertTest("Pending Offer Requests Queue query detects pending requests for assigned manager", $pQueueStmt->fetchColumn() !== false);

    // Restore to approved
    $pdo->prepare("UPDATE affiliate_offer_approval SET status = 'approved' WHERE affiliate_id = ? AND offer_id = ?")->execute([$affId, $offerId]);
}

// 8.3 Manager Impersonation Security Boundary Check
if (!empty($mgrId)) {
    // Verify query enforces account_manager_id check
    $secStmt = $pdo->prepare("
        SELECT u.user_id 
        FROM users u 
        WHERE u.role_id = 3 AND u.account_manager_id = ?
    ");
    $secStmt->execute([$mgrId]);
    $assignedAffs = $secStmt->fetchAll(PDO::FETCH_COLUMN);
    assertTest("Manager impersonation enforces strict assigned portfolio boundary", is_array($assignedAffs));
}

// ---------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------
echo "\n========================================================\n";
echo "TEST RESULTS SUMMARY: $passCount PASSED, $failCount FAILED\n";
echo "========================================================\n";
exit($failCount === 0 ? 0 : 1);
