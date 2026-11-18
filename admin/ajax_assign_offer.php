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
$hasAuth = auth_check() || (!empty($_SESSION['auth']) && !empty($_SESSION['auth']['user_id'])) || !empty($_SESSION['user_id']);
$userRole = auth_role() ?: ($_SESSION['auth']['role'] ?? ($_SESSION['role_name'] ?? 'admin'));

if (!$hasAuth || !in_array($userRole, ['admin', 'manager'], true)) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access. Please login as admin or manager.']);
    exit;
}

$currentUserId = (int)($_SESSION['auth']['user_id'] ?? ($_SESSION['user_id'] ?? 0));
$currentUserName = $_SESSION['auth']['name'] ?? ($_SESSION['user_name'] ?? 'Admin');
$currentRole = $userRole;

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
    $offStmt = $pdo->prepare("SELECT offer_id, offer_name, payout, revenue, status FROM offers WHERE offer_id = ?");
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

    // Safely check currency column
    $offerCurrency = 'USD';
    try {
        $cStmt = $pdo->prepare("SELECT currency FROM offers WHERE offer_id = ?");
        $cStmt->execute([$offerId]);
        $cVal = $cStmt->fetchColumn();
        if ($cVal) $offerCurrency = $cVal;
    } catch (Exception $ce) {}

    // 2. Fetch Publisher Details
    $pubStmt = $pdo->prepare("SELECT user_id, name, email, status FROM users WHERE user_id = ?");
    $pubStmt->execute([$publisherId]);
    $publisher = $pubStmt->fetch(PDO::FETCH_ASSOC);

    if (!$publisher) {
        echo json_encode(['success' => false, 'error' => "Publisher #{$publisherId} not found in database."]);
        exit;
    }

    if (!empty($publisher['status']) && in_array(strtolower($publisher['status']), ['blocked', 'banned', 'suspended'], true)) {
        echo json_encode([
            'success' => false, 
            'error' => "Cannot assign offer: Publisher '{$publisher['name']}' is currently {$publisher['status']}. You must unblock this publisher before assigning offers."
        ]);
        exit;
    }

    // Publisher company if column exists
    $pubCompany = '';
    try {
        $compStmt = $pdo->prepare("SELECT company FROM users WHERE user_id = ?");
        $compStmt->execute([$publisherId]);
        $compVal = $compStmt->fetchColumn();
        if ($compVal) $pubCompany = $compVal;
    } catch (Exception $cEx) {}

    // If custom payout is not provided or negative, use default offer payout
    if ($customPayout === null || $customPayout < 0) {
        $customPayout = (float)$offer['payout'];
    }

    $currency = $offerCurrency ?: 'USD';

    // 3. Dynamic schema inspection for affiliate_offer_approval
    $appCols = [];
    try {
        $appCols = $pdo->query("SHOW COLUMNS FROM affiliate_offer_approval")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}

    // Self-heal suspended_by_user_block if missing
    if (!empty($appCols) && !in_array('suspended_by_user_block', $appCols, true)) {
        try {
            $pdo->exec("ALTER TABLE affiliate_offer_approval ADD COLUMN suspended_by_user_block TINYINT(1) NOT NULL DEFAULT 0");
            $appCols[] = 'suspended_by_user_block';
        } catch (Exception $e) {}
    }

    // Check if permission already exists
    $chkStmt = $pdo->prepare("SELECT id FROM affiliate_offer_approval WHERE affiliate_id = ? AND offer_id = ? LIMIT 1");
    $chkStmt->execute([$publisherId, $offerId]);
    $existingRow = $chkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingRow) {
        $updSets = ["status = 'approved'"];
        $updParams = [':id' => $existingRow['id']];

        if (in_array('custom_payout', $appCols, true) && $customPayout !== null) {
            $updSets[] = "custom_payout = :payout";
            $updParams[':payout'] = $customPayout;
        }
        if (in_array('notes', $appCols, true)) {
            $updSets[] = "notes = :notes";
            $updParams[':notes'] = $notes;
        }
        if (in_array('suspended_by_user_block', $appCols, true)) {
            $updSets[] = "suspended_by_user_block = 0";
        }
        if (in_array('approved_at', $appCols, true)) {
            $updSets[] = "approved_at = NOW()";
        }

        $updStmt = $pdo->prepare("UPDATE affiliate_offer_approval SET " . implode(', ', $updSets) . " WHERE id = :id");
        $updStmt->execute($updParams);
    } else {
        $insertCols = ['affiliate_id', 'offer_id', 'status'];
        $insertVals = [':aff_id', ':offer_id', "'approved'"];
        $bindParams = [
            ':aff_id'   => $publisherId,
            ':offer_id' => $offerId
        ];

        if (in_array('custom_payout', $appCols, true)) {
            $insertCols[] = 'custom_payout';
            $insertVals[] = ':payout';
            $bindParams[':payout'] = $customPayout;
        }

        if (in_array('notes', $appCols, true)) {
            $insertCols[] = 'notes';
            $insertVals[] = ':notes';
            $bindParams[':notes'] = $notes;
        }

        if (in_array('suspended_by_user_block', $appCols, true)) {
            $insertCols[] = 'suspended_by_user_block';
            $insertVals[] = '0';
        }

        if (in_array('created_by', $appCols, true)) {
            $insertCols[] = 'created_by';
            $insertVals[] = ':created_by';
            $bindParams[':created_by'] = $currentUserName;
        }

        if (in_array('approved_at', $appCols, true)) {
            $insertCols[] = 'approved_at';
            $insertVals[] = 'NOW()';
        }

        if (in_array('created_at', $appCols, true)) {
            $insertCols[] = 'created_at';
            $insertVals[] = 'NOW()';
        }

        $sql = "INSERT INTO affiliate_offer_approval (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertVals) . ")";
        $insStmt = $pdo->prepare($sql);
        $insStmt->execute($bindParams);
    }

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

    // 5. Audit Logging (wrapped in try-catch so it never disrupts assignment)
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
        // Non-blocking
    }

    $formattedPayout = function_exists('format_currency') 
        ? format_currency($customPayout, $currency) 
        : (($currency === 'INR' ? '₹' : '$') . number_format($customPayout, 2) . ' ' . $currency);

    // 6. Return Clean Success Response
    echo json_encode([
        'success'          => true,
        'message'          => "Campaign successfully assigned to {$publisher['name']}!",
        'offer_id'         => $offerId,
        'offer_name'       => $offer['offer_name'],
        'publisher_id'     => $publisherId,
        'publisher_name'   => $publisher['name'],
        'publisher_email'  => $publisher['email'] ?? '',
        'publisher_company'=> $pubCompany,
        'payout'           => $customPayout,
        'currency'         => $currency,
        'formatted_payout' => $formattedPayout,
        'tracking_url'     => $trackingUrl,
        'timestamp'        => date('Y-m-d H:i:s')
    ]);
    exit;

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    exit;
}
