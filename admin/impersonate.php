<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

// Ensure token or user_id parameter is supplied
$token = $_GET['token'] ?? '';
$targetUserId = 0;

if (!empty($token) && isset($_SESSION['impersonate_tokens'][$token])) {
    $targetData = $_SESSION['impersonate_tokens'][$token];
    unset($_SESSION['impersonate_tokens'][$token]); // Single-use token
    if (time() - $targetData['created_at'] <= 120) {
        $targetUserId = (int)$targetData['user_id'];
    } else {
        die('Impersonation token expired. Please return to the Admin Panel and try again.');
    }
} elseif (!empty($_GET['id']) || !empty($_GET['user_id'])) {
    $targetUserId = (int)($_GET['id'] ?? $_GET['user_id']);
}

if ($targetUserId <= 0) {
    die('Invalid or missing account ID. Please return to the Admin Panel.');
}

// Preserve current admin identity from active admin session
$adminUserId = auth_user_id();
$adminName   = $_SESSION['user_name'] ?? 'Administrator';

// Fetch target user from DB
$stmt = $pdo->prepare("
    SELECT u.user_id, u.name, u.email, u.status, r.role_name
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    WHERE u.user_id = ? LIMIT 1
");
$stmt->execute([$targetUserId]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    die('User account not found.');
}

// CRITICAL FIX: Preserve Admin session! DO NOT destroy or overwrite it!
// 1. Flush & close admin session without destroying it
session_write_close();

// 2. Clear in-memory $_SESSION so admin data does not leak into partner session
$_SESSION = [];

// 3. Switch to Partner session for the impersonated tab
session_name('OFFERON_PARTNER_SESS');
session_id(session_create_id());
session_start();

// 4. Regenerate ID on partner session for safety
session_regenerate_id(true);

// 4. Populate partner session with target user credentials & impersonation metadata
$_SESSION['auth'] = [
    'user_id'  => (int)$targetUser['user_id'],
    'role'     => $targetUser['role_name'],
    'login_at' => time()
];
$_SESSION['user_name']               = $targetUser['name'] ?? 'Publisher';
$_SESSION['user_email']              = $targetUser['email'] ?? '';
$_SESSION['is_impersonating']        = true;
$_SESSION['impersonated_by_admin']   = true;
$_SESSION['impersonator_admin_id']   = $adminUserId;
$_SESSION['impersonator_admin_name'] = $adminName;

// Save partner session
session_write_close();

// 5. Redirect to target portal based on role
if ($targetUser['role_name'] === 'affiliate') {
    header('Location: /affiliate/dashboard.php');
} elseif ($targetUser['role_name'] === 'advertiser') {
    header('Location: /advertiser/dashboard.php');
} else {
    header('Location: /dashboard.php');
}
exit;
