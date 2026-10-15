<?php
/**
 * Click Tracking Endpoint
 * PHP 7.1+
 * NO SESSION
 * NO AUTH
 */

declare(strict_types=1);

define('APP_INIT', true);
require_once __DIR__ . '/app/config/database.php';

/* -------------------------------------------------
   1. Validate required params
-------------------------------------------------- */
$offerId     = isset($_GET['offer_id']) ? (int)$_GET['offer_id'] : (isset($_GET['offer']) ? (int)$_GET['offer'] : 0);
$affiliateId = isset($_GET['aff_id'])   ? (int)$_GET['aff_id']   : (isset($_GET['aff'])   ? (int)$_GET['aff']   : (isset($_GET['affiliate_id']) ? (int)$_GET['affiliate_id'] : 0));

if ($offerId <= 0 || $affiliateId <= 0) {
    http_response_code(400);
    exit('INVALID_TRACKING_LINK');
}

/* -------------------------------------------------
   2. Capture ALL incoming params (copy)
-------------------------------------------------- */
$incomingParams = $_GET;

/* Extract sub params safely for DB (supports sub_aff_id, sub_aff, sub1, aff_sub, p1) */
$sub1 = $incomingParams['sub_aff_id'] 
     ?? $incomingParams['sub_aff'] 
     ?? $incomingParams['sub1'] 
     ?? $incomingParams['aff_sub1'] 
     ?? $incomingParams['aff_sub'] 
     ?? $incomingParams['p1'] 
     ?? null;

$sub2 = $incomingParams['sub2'] ?? $incomingParams['aff_sub2'] ?? $incomingParams['p2'] ?? null;
$sub3 = $incomingParams['sub3'] ?? $incomingParams['aff_sub3'] ?? $incomingParams['p3'] ?? null;
$sub4 = $incomingParams['sub4'] ?? $incomingParams['aff_sub4'] ?? $incomingParams['p4'] ?? null;
$sub5 = $incomingParams['sub5'] ?? $incomingParams['aff_sub5'] ?? $incomingParams['p5'] ?? null;

/* -------------------------------------------------
   3. Fetch & validate offer
-------------------------------------------------- */
$offerStmt = $pdo->prepare("
    SELECT offer_id, offer_url, status, daily_cap, visibility
    FROM offers
    WHERE offer_id = :oid
    LIMIT 1
");
$offerStmt->execute(['oid' => $offerId]);
$offer = $offerStmt->fetch(PDO::FETCH_ASSOC);

if (!$offer) {
    http_response_code(404);
    exit('OFFER_NOT_FOUND');
}

if (!in_array(strtolower($offer['status']), ['approved', 'active', 'live'], true)) {
    http_response_code(403);
    exit('OFFER_NOT_APPROVED');
}

if (empty($offer['offer_url'])) {
    exit('OFFER_URL_MISSING');
}

/* -------------------------------------------------
   4. Check affiliate approval (if offer is non-public)
-------------------------------------------------- */
$visibility = strtolower($offer['visibility'] ?? 'public');
if ($visibility !== 'public') {
    $approvalStmt = $pdo->prepare("
        SELECT 1
        FROM affiliate_offer_approval
        WHERE affiliate_id = :aff
          AND offer_id = :oid
          AND status = 'approved'
        LIMIT 1
    ");
    $approvalStmt->execute([
        'aff' => $affiliateId,
        'oid' => $offerId
    ]);

    if (!$approvalStmt->fetchColumn()) {
        http_response_code(403);
        exit('AFFILIATE_NOT_APPROVED');
    }
}

/* -------------------------------------------------
   5. Daily cap check (Check conversions OR clicks depending on cap definition)
-------------------------------------------------- */
if (!empty($offer['daily_cap']) && (int)$offer['daily_cap'] > 0) {
    $capStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM conversions
        WHERE offer_id = :oid
          AND status = 'approved'
          AND DATE(created_at) = CURDATE()
    ");
    $capStmt->execute(['oid' => $offerId]);

    if ((int)$capStmt->fetchColumn() >= (int)$offer['daily_cap']) {
        exit('DAILY_CAP_REACHED');
    }
}

/* -------------------------------------------------
   6. Generate click_id
-------------------------------------------------- */
$clickId = bin2hex(random_bytes(16));

/* -------------------------------------------------
   7. Collect environment data
-------------------------------------------------- */
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$referer   = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500);

$device = preg_match('/mobile|android|iphone/i', $userAgent) ? 'mobile' : 'desktop';

/* -------------------------------------------------
   8. Insert click
-------------------------------------------------- */
$insert = $pdo->prepare("
    INSERT INTO clicks (
        click_id,
        offer_id,
        affiliate_id,
        sub1, sub2, sub3, sub4, sub5,
        ip_address,
        user_agent,
        referer,
        device,
        created_at
    ) VALUES (
        :click_id,
        :offer_id,
        :affiliate_id,
        :sub1, :sub2, :sub3, :sub4, :sub5,
        INET6_ATON(:ip),
        :ua,
        :ref,
        :device,
        NOW()
    )
");

$insert->execute([
    'click_id'     => $clickId,
    'offer_id'     => $offerId,
    'affiliate_id' => $affiliateId,
    'sub1'         => $sub1,
    'sub2'         => $sub2,
    'sub3'         => $sub3,
    'sub4'         => $sub4,
    'sub5'         => $sub5,
    'ip'           => $ipAddress,
    'ua'           => $userAgent,
    'ref'          => $referer,
    'device'       => $device
]);

/* -------------------------------------------------
   9. Build redirect URL (MACRO REPLACEMENT + FORWARDING)
-------------------------------------------------- */
$redirectUrl = $offer['offer_url'];

$gpId = $incomingParams['gp_id'] ?? ($incomingParams['gpid'] ?? ($incomingParams['GP_ID'] ?? ''));

// Standard tokens lookup (lowercase token without curly braces => value)
$macroValues = [
    'click_id'        => rawurlencode($clickId),
    'transaction_id'  => rawurlencode($clickId),
    'offer_id'        => rawurlencode((string)$offerId),
    'affiliate_id'    => rawurlencode((string)$affiliateId),
    'aff_id'          => rawurlencode((string)$affiliateId),
    'sub_aff_id'      => rawurlencode((string)($sub1 ?? '')),
    'sub_aff'         => rawurlencode((string)($sub1 ?? '')),
    'sub1'            => rawurlencode((string)($sub1 ?? '')),
    'sub2'            => rawurlencode((string)($sub2 ?? '')),
    'sub3'            => rawurlencode((string)($sub3 ?? '')),
    'sub4'            => rawurlencode((string)($sub4 ?? '')),
    'sub5'            => rawurlencode((string)($sub5 ?? '')),
    'aff_sub'         => rawurlencode((string)($sub1 ?? '')),
    'aff_sub1'        => rawurlencode((string)($sub1 ?? '')),
    'aff_sub2'        => rawurlencode((string)($sub2 ?? '')),
    'aff_sub3'        => rawurlencode((string)($sub3 ?? '')),
    'aff_sub4'        => rawurlencode((string)($sub4 ?? '')),
    'aff_sub5'        => rawurlencode((string)($sub5 ?? '')),
    'p1'              => rawurlencode((string)($sub1 ?? '')),
    'p2'              => rawurlencode((string)($sub2 ?? '')),
    'p3'              => rawurlencode((string)($sub3 ?? '')),
    'p4'              => rawurlencode((string)($sub4 ?? '')),
    'p5'              => rawurlencode((string)($sub5 ?? '')),
    'source'          => rawurlencode((string)($sub1 ?? '')),
    'ip_address'      => rawurlencode($ipAddress),
    'ip'              => rawurlencode($ipAddress),
    'country'         => rawurlencode($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''),
    'gaid'            => rawurlencode((string)($incomingParams['gaid'] ?? '')),
    'idfa'            => rawurlencode((string)($incomingParams['idfa'] ?? '')),
    'gp_id'           => rawurlencode((string)$gpId),
    'gpid'            => rawurlencode((string)$gpId)
];

// Perform case-insensitive macro replacements on offer URL
$redirectUrl = preg_replace_callback('/\{([a-zA-Z0-9_-]+)\}/', function ($matches) use ($macroValues, $incomingParams) {
    $tokenLower = strtolower($matches[1]);
    if (isset($macroValues[$tokenLower])) {
        return $macroValues[$tokenLower];
    }
    // Fallback: Check if token was passed as a query parameter in incoming request
    foreach ($incomingParams as $k => $v) {
        if (strtolower((string)$k) === $tokenLower) {
            return rawurlencode((string)$v);
        }
    }
    // If unknown macro or empty parameter, strip the token so {MACRO} doesn't leak into target URL
    return '';
}, $redirectUrl);

// Reserved internal parameters that should NEVER be appended to the destination URL
$reservedKeys = array_flip([
    'offer_id', 'offer',
    'aff_id', 'aff', 'affiliate_id',
    'sub_aff_id', 'sub_aff',
    'sub1', 'sub2', 'sub3', 'sub4', 'sub5',
    'aff_sub', 'aff_sub1', 'aff_sub2', 'aff_sub3', 'aff_sub4', 'aff_sub5',
    'p1', 'p2', 'p3', 'p4', 'p5',
    'source', 'gp_id', 'gpid', 'gaid', 'idfa'
]);

$forwardParams = [];
foreach ($incomingParams as $key => $val) {
    if (!isset($reservedKeys[strtolower((string)$key)])) {
        $forwardParams[$key] = $val;
    }
}

// Forward any genuine extra 3rd-party query params (e.g. custom UTM parameters) only if present
if (!empty($forwardParams)) {
    $queryString = http_build_query($forwardParams);
    $separator   = (strpos($redirectUrl, '?') === false) ? '?' : '&';
    $redirectUrl .= $separator . $queryString;
}

/* -------------------------------------------------
   10. Redirect
-------------------------------------------------- */
header('Location: ' . $redirectUrl, true, 302);
exit;
