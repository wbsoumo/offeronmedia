<?php
/**
 * Account Manager Impersonation Tool ("Login as Publisher")
 * Allows dedicated managers to login as their assigned publishers with strict boundary checks.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('manager');

$managerId   = auth_user_id();
$managerName = $_SESSION['user_name'] ?? 'Account Manager';
$targetId    = (int)($_GET['id'] ?? ($_GET['user_id'] ?? 0));

if ($targetId <= 0) {
    die('Invalid or missing publisher ID. <a href="publishers.php">Return to My Publishers</a>');
}

// Security Check: Target MUST be an affiliate (role_id = 3) AND assigned to this manager
$stmt = $pdo->prepare("
    SELECT u.user_id, u.name, u.email, u.status, r.role_name
    FROM users u
    INNER JOIN roles r ON r.role_id = u.role_id
    WHERE u.user_id = ? AND u.role_id = 3 AND u.account_manager_id = ?
    LIMIT 1
");
$stmt->execute([$targetId, $managerId]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    die('Access Denied: You can only impersonate publishers assigned to your manager account. <a href="publishers.php">Return</a>');
}

// Log audit trail
admin_log_action(
    'manager_impersonate',
    'affiliate',
    $targetId,
    $targetUser['name'],
    "Manager {$managerName} (#{$managerId}) logged into publisher account #{$targetId} ({$targetUser['name']})"
);

// Switch session safely to Partner Session
session_write_close();
$_SESSION = [];

session_name('OFFERON_PARTNER_SESS');
session_id(session_create_id());
session_start();
session_regenerate_id(true);

$_SESSION['auth'] = [
    'user_id'  => (int)$targetUser['user_id'],
    'role'     => 'affiliate',
    'login_at' => time()
];
$_SESSION['user_name']                 = $targetUser['name'] ?? 'Publisher';
$_SESSION['user_email']                = $targetUser['email'] ?? '';
$_SESSION['is_impersonating']          = true;
$_SESSION['impersonated_by_manager']   = true;
$_SESSION['impersonator_manager_id']   = $managerId;
$_SESSION['impersonator_manager_name'] = $managerName;

session_write_close();

header('Location: /affiliate/dashboard.php');
exit;

