<?php
/**
 * AJAX Handler: Assign Offer to Publisher & Generate Tracking Link
 * Supports Admin & Account Managers with proper validation and audit logging.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure user is authenticated as admin or manager
if (!is_logged_in() || !in_array(auth_role(), ['admin', 'manager'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access. Please login as admin or manager.']);
    exit;
}

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUserName = $_SESSION['user_name'] ?? 'Admin';
$currentRole = auth_role();

// Check Manager permissions if applicable
if ($currentRole === 'manager' && !manager_can('campaigns') && !manager_can('manage_campaigns')) {
    // If manager has custom permission restriction
    if (!manager_can('all_campaigns') && !manager_can('edit_campaigns') && !manager_can('create_campaigns')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied: Account Managers do not have permission to assign campaigns.']);
        exit;
    }
}

// Support both JSON input and standard form POST
$input = $_POST;
if (empty($input)) {
    $rawInput = file_get_contents('php://input');
    if ($rawInput) {
        $json = json_decode($rawInput, true);
        if (is_array($json)) {
            $input = $json;
        }
    }
}

$action = $input['action'] ?? 'assign';

/* ===============================
   ACTION: GET PUBLISHERS LIST
================================ */
if ($action === 'get_publishers') {
    try {
        $stmt = $pdo->prepare("
            SELECT user_id, name, email, company, status, account_manager_id
            FROM users 
            WHERE role_id = 3
            ORDER BY name ASC
        ");
        $stmt->execute();
        $publishers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'publishers' => $publishers]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to fetch publishers: ' . $e->getMessage()]);
        exit;
    }
}

/* ===============================
   ACTION: ASSIGN OFFER & GENERATE LINK
================================ */
$offerId = isset($input['offer_id']) ? (int)$input['offer_id'] : 0;
$publisherId = isset($input['publisher_id']) ? (int)$input['publisher_id'] : 0;
$customPayout = isset($input['custom_payout']) && $input['custom_payout'] !== '' ? (float)$input['custom_payout'] : null;
$sub1 = trim($input['sub1'] ?? '');
$sub2 = trim($input['sub2'] ?? '');
$sub3 = trim($input['sub3'] ?? '');
$notes = trim($input['notes'] ?? 'Assigned via Quick Link Generator');

if ($offerId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Please select a valid Campaign / Offer.']);
    exit;
}

if ($publisherId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Please select a valid Publisher / Affiliate.']);
    exit;
}

try {
    // 1. Fetch Offer Details
    $offStmt = $pdo->prepare("SELECT offer_id, offer_name, payout, revenue, currency, status FROM offers WHERE offer_id = ?");
    $offStmt->execute([$offerId]);
    $offer = $offStmt->fetch(PDO::FETCH_ASSOC);

    if (!$offer) {
        echo json_encode(['success' => false, 'error' => "Offer #{$offerId} not found in the database."]);
        exit;
    }

    if ($offer['status'] === 'archived') {
        echo json_encode(['success' => false, 'error' => "Offer #{$offerId} is currently archived. Please restore it before assigning."]);
        exit;
    }

    // 2. Fetch Publisher Details
    $pubStmt = $pdo->prepare("SELECT user_id, name, email, company, status, account_manager_id FROM users WHERE user_id = ? AND role_id = 3");
    $pubStmt->execute([$publisherId]);
    $publisher = $pubStmt->fetch(PDO::FETCH_ASSOC);

    if (!$publisher) {
        echo json_encode(['success' => false, 'error' => "Publisher #{$publisherId} not found or is not an active publisher."]);
        exit;
    }

    if (in_array(strtolower($publisher['status']), ['blocked', 'banned', 'suspended'], true)) {
        echo json_encode([
            'success' => false, 
            'error' => "Cannot assign offer: Publisher '{$publisher['name']}' is currently {$publisher['status']}. You must unblock this publisher before assigning offers."
        ]);
        exit;
    }

    // If custom payout is not provided or negative, use default offer payout
    if ($customPayout === null || $customPayout < 0) {
        $customPayout = (float)$offer['payout'];
    }

    $currency = $offer['currency'] ?: 'USD';

    // 3. Insert or Update affiliate_offer_approval record
    $insStmt = $pdo->prepare("
        INSERT INTO affiliate_offer_approval 
            (affiliate_id, offer_id, status, custom_payout, notes, suspended_by_user_block, approved_at, created_at)
        VALUES 
            (:aff_id, :offer_id, 'approved', :payout, :notes, 0, NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
            status = 'approved',
            custom_payout = VALUES(custom_payout),
            notes = VALUES(notes),
            suspended_by_user_block = 0,
            approved_at = NOW()
    ");

    $insStmt->execute([
        'aff_id'   => $publisherId,
        'offer_id' => $offerId,
        'payout'   => $customPayout,
        'notes'    => $notes
    ]);

    // 4. Build Live Tracking URL
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'offeronmedia.com';
    $baseUrl = "{$protocol}://{$host}/click.php";

    $queryParams = [
        'offer_id' => $offerId,
        'aff_id'   => $publisherId
    ];

    if ($sub1 !== '') {
        $queryParams['sub1'] = $sub1;
    }
    if ($sub2 !== '') {
        $queryParams['sub2'] = $sub2;
    }
    if ($sub3 !== '') {
        $queryParams['sub3'] = $sub3;
    }

    $trackingUrl = $baseUrl . '?' . http_build_query($queryParams);

    // 5. Audit Logging
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO admin_logs 
                (admin_id, admin_name, action, target_type, target_id, target_name, details, ip_address, created_at)
            VALUES 
                (:admin_id, :admin_name, 'assign_offer', 'offer', :target_id, :target_name, :details, :ip, NOW())
        ");
        $logDetails = json_encode([
            'offer_id'       => $offerId,
            'offer_name'     => $offer['offer_name'],
            'publisher_id'   => $publisherId,
            'publisher_name' => $publisher['name'],
            'custom_payout'  => $customPayout,
            'currency'       => $currency,
            'tracking_url'   => $trackingUrl
        ]);
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $logStmt->execute([
            'admin_id'    => $currentUserId,
            'admin_name'  => $currentUserName,
            'target_id'   => $offerId,
            'target_name' => $offer['offer_name'],
            'details'     => $logDetails,
            'ip'          => $clientIp
        ]);
    } catch (Exception $logEx) {
        // Non-blocking log failure
    }

    // 6. Return Clean Success Response
    echo json_encode([
        'success'          => true,
        'message'          => "Campaign successfully assigned to {$publisher['name']}!",
        'offer_id'         => $offerId,
        'offer_name'       => $offer['offer_name'],
        'publisher_id'     => $publisherId,
        'publisher_name'   => $publisher['name'],
        'publisher_email'  => $publisher['email'],
        'publisher_company'=> $publisher['company'] ?: '',
        'payout'           => $customPayout,
        'currency'         => $currency,
        'formatted_payout' => format_currency($customPayout, $currency),
        'tracking_url'     => $trackingUrl,
        'timestamp'        => date('Y-m-d H:i:s')
    ]);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
}
