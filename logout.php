<?php
/**
 * Global Logout
 * PHP 7.1+
 */

define('APP_INIT', true);

require_once __DIR__ . '/app/core/auth.php';

$isAdminLogout = false;
if (isset($_GET['scope']) && $_GET['scope'] === 'admin') {
    $isAdminLogout = true;
} elseif (isset($_GET['scope']) && $_GET['scope'] === 'all') {
    $isAdminLogout = 'all';
} elseif (strpos($_SERVER['HTTP_REFERER'] ?? '', '/admin/') !== false) {
    $isAdminLogout = true;
}

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? null) == 443
);

if ($isAdminLogout === true) {
    // Terminate Admin session
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_name('OFFERON_ADMIN_SESS');
    session_start();
    $_SESSION = [];
    session_destroy();
    setcookie('OFFERON_ADMIN_SESS', '', time() - 3600, '/', '', $isHttps, true);

    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Location: /admin/login.php?logout=1");
    exit;
} elseif ($isAdminLogout === 'all') {
    // Terminate both sessions
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_name('OFFERON_ADMIN_SESS');
    session_start();
    $_SESSION = [];
    session_destroy();
    setcookie('OFFERON_ADMIN_SESS', '', time() - 3600, '/', '', $isHttps, true);

    session_name('OFFERON_PARTNER_SESS');
    session_start();
    $_SESSION = [];
    session_destroy();
    setcookie('OFFERON_PARTNER_SESS', '', time() - 3600, '/', '', $isHttps, true);

    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Location: /login.php?logout=1");
    exit;
} else {
    // Partner logout (Affiliate, Advertiser, Manager, or Impersonation exit)
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_name('OFFERON_PARTNER_SESS');
    session_start();
    $_SESSION = [];
    session_destroy();
    setcookie('OFFERON_PARTNER_SESS', '', time() - 3600, '/', '', $isHttps, true);

    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Location: /login.php?logout=1");
    exit;
}
