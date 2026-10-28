<?php
/**
 * Authentication Core
 * PHP 7.1+
 * CLEAN & STABLE VERSION
 */

if (!defined('APP_INIT')) {
    die('Direct access not allowed');
}

/* =================================================
   SESSION INITIALIZATION (DO THIS ONCE, PROPERLY)
================================================= */

/**
 * Detect whether the current request is for the Admin console.
 * Checks SCRIPT_FILENAME, SCRIPT_NAME, PHP_SELF, and REQUEST_URI.
 */
function auth_is_admin_context(): bool
{
    // 1. Check executing script filesystem path
    $scriptFilename = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($scriptFilename !== '' && (strpos($scriptFilename, '/admin/') !== false || preg_match('#/admin/[^/]+$#', $scriptFilename))) {
        return true;
    }

    // 2. Check URL script name / PHP_SELF
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    if (strpos($scriptName, '/admin/') !== false) {
        return true;
    }

    $phpSelf = str_replace('\\', '/', $_SERVER['PHP_SELF'] ?? '');
    if (strpos($phpSelf, '/admin/') !== false) {
        return true;
    }

    // 3. Check full request URI
    $requestUri = str_replace('\\', '/', $_SERVER['REQUEST_URI'] ?? '');
    if (strpos($requestUri, '/admin/') !== false) {
        return true;
    }

    return false;
}

/**
 * Render floating top banner when an admin is impersonating a publisher/advertiser
 */
function auth_render_impersonation_banner($buffer)
{
    if (empty($_SESSION['is_impersonating']) || empty($_SESSION['auth'])) {
        return $buffer;
    }

    // Only inject in HTML pages
    if (stripos($buffer, '<body') === false) {
        return $buffer;
    }

    $userName  = htmlspecialchars($_SESSION['user_name'] ?? 'Publisher');
    $userEmail = htmlspecialchars($_SESSION['user_email'] ?? '');
    $userId    = (int)($_SESSION['auth']['user_id'] ?? 0);
    $roleName  = ucfirst($_SESSION['auth']['role'] ?? 'affiliate');

    $bannerHtml = '
    <div id="impersonation-notification-bar" style="background: linear-gradient(90deg, #0f172a 0%, #1e293b 60%, #0f172a 100%); color: #ffffff; padding: 10px 20px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; font-size: 13px; position: sticky; top: 0; z-index: 9999999; border-bottom: 2px solid #3b82f6; box-shadow: 0 4px 14px rgba(0,0,0,0.35); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <span style="background: #2563eb; color: #ffffff; font-weight: 700; font-size: 11px; text-transform: uppercase; padding: 3px 8px; border-radius: 4px; letter-spacing: 0.5px; display: inline-flex; align-items: center; gap: 4px;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Admin Impersonation
            </span>
            <span>
                Logged in as <strong>' . $userName . '</strong> (' . $roleName . ' #' . $userId . ($userEmail ? ' &middot; ' . $userEmail : '') . ')
            </span>
            <span style="color: #94a3b8; font-size: 12px;">
                &bull; Your Admin account session is safely active in your admin tab.
            </span>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <a href="/admin/publishers.php" target="_blank" style="background: rgba(255,255,255,0.08); color: #93c5fd; padding: 5px 12px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 12px; border: 1px solid rgba(147,197,253,0.3); transition: all 0.2s ease;">
                Go to Admin Panel &rarr;
            </a>
            <a href="/logout.php" style="background: #dc2626; color: #ffffff; padding: 5px 12px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 12px; border: 1px solid #b91c1c; transition: all 0.2s ease;">
                Exit Impersonation
            </a>
        </div>
    </div>';

    $pattern = '/(<body[^>]*>)/i';
    if (preg_match($pattern, $buffer)) {
        return preg_replace($pattern, '$1' . "\n" . $bannerHtml, $buffer, 1);
    }

    return $bannerHtml . $buffer;
}

if (php_sapi_name() !== 'cli') {
    // Detect HTTPS
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? null) == 443
    );

    // Force cookie params (shared hosting safe, entire domain)
    session_set_cookie_params(
        0,          // lifetime (session)
        '/',        // path (ENTIRE DOMAIN)
        '',         // domain (auto)
        $isHttps,   // secure
        true        // httponly
    );

    $isAdminReq = auth_is_admin_context();
    // Admin uses OFFERON_ADMIN_SESS so admin session is never overwritten by partner logins/impersonation
    $targetSessionName = $isAdminReq ? 'OFFERON_ADMIN_SESS' : 'OFFERON_PARTNER_SESS';

    if (session_status() === PHP_SESSION_NONE) {
        session_name($targetSessionName);
        session_start();

        // Smooth migration: if admin visits /admin/ and OFFERON_ADMIN_SESS is empty, check legacy PHPSESSID
        if ($isAdminReq && empty($_SESSION['auth']) && !empty($_COOKIE['PHPSESSID'])) {
            $legacyId = $_COOKIE['PHPSESSID'];
            session_write_close();

            session_name('PHPSESSID');
            session_id($legacyId);
            session_start();

            if (!empty($_SESSION['auth']) && (($_SESSION['auth']['role'] ?? '') === 'admin')) {
                $savedSession = $_SESSION;
                session_write_close();

                session_name('OFFERON_ADMIN_SESS');
                session_start();
                $_SESSION = $savedSession;
            } else {
                session_write_close();
                session_name('OFFERON_ADMIN_SESS');
                session_start();
            }
        }
    }

    // Render Impersonation Banner in partner portal if admin is impersonating
    if (!$isAdminReq && !empty($_SESSION['is_impersonating']) && !empty($_SESSION['auth'])) {
        ob_start('auth_render_impersonation_banner');
    }
}

/* =================================================
   DATABASE
================================================= */

require_once __DIR__ . '/../config/database.php';

/* =================================================
   AUTH FUNCTIONS
================================================= */

/**
 * Perform login (email + password)
 */
function auth_login($email, $password, $remember = false, $requiredRole = null)
{
    global $pdo;

    $stmt = $pdo->prepare("
        SELECT 
            u.user_id,
            u.name,
            u.email,
            u.password_hash,
            u.status,
            r.role_name
        FROM users u
        INNER JOIN roles r ON r.role_id = u.role_id
        WHERE u.email = :email
        LIMIT 1
    ");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['success' => false, 'error' => 'Invalid credentials'];
    }

    if ($user['status'] !== 'active') {
        return ['success' => false, 'error' => 'Account not active'];
    }

    if ($requiredRole && strtolower($user['role_name']) !== strtolower($requiredRole)) {
        return ['success' => false, 'error' => 'Invalid account type selected for this user. Please select ' . ucfirst($user['role_name']) . '.'];
    }

    // Prevent session fixation
    session_regenerate_id(true);

    // Store auth state (SINGLE SOURCE OF TRUTH)
    $_SESSION['auth'] = [
        'user_id'  => (int)$user['user_id'],
        'role'     => $user['role_name'],
        'login_at' => time()
    ];
    $_SESSION['user_name']  = $user['name'] ?? 'User';
    $_SESSION['user_email'] = $user['email'] ?? '';

    // Update last login
    $upd = $pdo->prepare("
        UPDATE users
        SET last_login_ip = :ip,
            last_login_at = NOW()
        WHERE user_id = :uid
    ");
    $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $upd->execute([
        'ip'  => $clientIp,
        'uid' => $user['user_id']
    ]);

    return ['success' => true, 'role' => $user['role_name']];
}

/**
 * Logout
 */
function auth_logout()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessName = session_name();
        $_SESSION = [];
        session_destroy();

        $isHttps = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) == 443
        );
        setcookie($sessName, '', time() - 3600, '/', '', $isHttps, true);
    }
}

/**
 * Is user logged in?
 */
function auth_check()
{
    return isset($_SESSION['auth']['user_id']);
}

/**
 * Get logged-in user ID
 */
function auth_user_id()
{
    return auth_check() ? (int)$_SESSION['auth']['user_id'] : null;
}

/**
 * Get logged-in role
 */
function auth_role()
{
    return auth_check() ? $_SESSION['auth']['role'] : null;
}

/**
 * Require login
 */
function require_auth()
{
    if (!auth_check()) {
        $loginUrl = auth_is_admin_context() ? '/admin/login.php' : '/login.php';
        header('Location: ' . $loginUrl);
        exit;
    }
}

/**
 * Require specific role
 */
function require_role($role)
{
    require_auth();

    if ($_SESSION['auth']['role'] !== $role) {
        $loginUrl = auth_is_admin_context() ? '/admin/login.php' : '/login.php';
        header('Location: ' . $loginUrl);
        exit;
    }
}

/**
 * Require any role from list
 */
function require_any_role(array $roles)
{
    require_auth();

    if (!in_array($_SESSION['auth']['role'], $roles, true)) {
        $loginUrl = auth_is_admin_context() ? '/admin/login.php' : '/login.php';
        header('Location: ' . $loginUrl);
        exit;
    }
}

/* =================================================
   MANAGER GRANULAR PERMISSIONS SYSTEM
================================================= */

/**
 * Definition of available manager permissions
 */
function get_manager_permission_definitions()
{
    return [
        'can_assign_offers' => [
            'label' => 'Assign Offers to Affiliates',
            'desc'  => 'Allow manager to assign private and active offers to their publishers.',
            'default' => 1
        ],
        'can_view_affiliate_reports' => [
            'label' => 'Check Affiliate Reports',
            'desc'  => 'Allow manager to view performance analytics grouped by their publishers.',
            'default' => 1
        ],
        'can_view_advertiser_reports' => [
            'label' => 'Check Advertisers Report',
            'desc'  => 'Allow manager to view performance reports aggregated by advertiser.',
            'default' => 0
        ],
        'can_view_all_reports' => [
            'label' => 'Check All Types of Reports',
            'desc'  => 'Allow manager to view campaign reports, SubID/sub_aff_id reports & conversion logs.',
            'default' => 0
        ],
        'can_manage_affiliates' => [
            'label' => 'Approve, Reject, or Block Affiliates',
            'desc'  => 'Allow manager to approve pending registrations, reject, or block their affiliates.',
            'default' => 1
        ],
        'can_link_tester' => [
            'label' => 'Link Tester Tool',
            'desc'  => 'Allow manager to access the link testing simulator to verify redirect chains.',
            'default' => 1
        ],
        'can_manage_postbacks' => [
            'label' => 'Affiliate-wise Postback Configuration',
            'desc'  => 'Allow manager to configure and manage global/offer postback URLs for their affiliates.',
            'default' => 1
        ]
    ];
}

/**
 * Ensure manager_permissions table exists in database
 */
function ensure_manager_permissions_table()
{
    global $pdo;
    static $ensured = false;
    if ($ensured || !$pdo) return;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `manager_permissions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `manager_id` INT NOT NULL UNIQUE,
                `can_assign_offers` TINYINT(1) NOT NULL DEFAULT 1,
                `can_view_affiliate_reports` TINYINT(1) NOT NULL DEFAULT 1,
                `can_view_advertiser_reports` TINYINT(1) NOT NULL DEFAULT 0,
                `can_view_all_reports` TINYINT(1) NOT NULL DEFAULT 0,
                `can_manage_affiliates` TINYINT(1) NOT NULL DEFAULT 1,
                `can_link_tester` TINYINT(1) NOT NULL DEFAULT 1,
                `can_manage_postbacks` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_manager_id` (`manager_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
        $ensured = true;
    } catch (Exception $e) {
        error_log('ensure_manager_permissions_table warning: ' . $e->getMessage());
    }
}

/**
 * Get all permissions for a manager
 */
function get_manager_permissions($managerId)
{
    global $pdo;
    $managerId = (int)$managerId;
    $defs = get_manager_permission_definitions();
    $defaults = [];
    foreach ($defs as $key => $meta) {
        $defaults[$key] = (bool)$meta['default'];
    }

    if ($managerId <= 0 || !$pdo) {
        return $defaults;
    }

    try {
        ensure_manager_permissions_table();
        $stmt = $pdo->prepare("SELECT * FROM manager_permissions WHERE manager_id = ? LIMIT 1");
        $stmt->execute([$managerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $perms = [];
            foreach ($defs as $key => $meta) {
                $perms[$key] = isset($row[$key]) ? (bool)$row[$key] : (bool)$meta['default'];
            }
            return $perms;
        }
    } catch (Exception $e) {
        error_log('get_manager_permissions error: ' . $e->getMessage());
    }

    return $defaults;
}

/**
 * Save permissions for a manager
 */
function save_manager_permissions($managerId, array $perms)
{
    global $pdo;
    $managerId = (int)$managerId;
    if ($managerId <= 0 || !$pdo) return false;

    ensure_manager_permissions_table();
    $defs = get_manager_permission_definitions();
    $fields = [];
    $values = [];
    $updates = [];

    foreach ($defs as $key => $meta) {
        $val = !empty($perms[$key]) ? 1 : 0;
        $fields[] = $key;
        $values[] = $val;
        $updates[] = "{$key} = VALUES({$key})";
    }

    $colNames = implode(', ', $fields);
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $updateClause = implode(', ', $updates);

    $sql = "
        INSERT INTO manager_permissions (manager_id, {$colNames}, updated_at)
        VALUES (?, {$placeholders}, NOW())
        ON DUPLICATE KEY UPDATE {$updateClause}, updated_at = NOW()
    ";

    $stmt = $pdo->prepare($sql);
    $params = array_merge([$managerId], $values);
    return $stmt->execute($params);
}

/**
 * Check if the current manager (or specified manager) has a specific permission
 */
function manager_can($permission, $managerId = null)
{
    // Admins have all permissions unrestricted
    if (auth_role() === 'admin') {
        return true;
    }

    $mId = $managerId ?: auth_user_id();
    if (!$mId) {
        return false;
    }

    // Normalize permission key
    $key = strpos($permission, 'can_') === 0 ? $permission : 'can_' . $permission;
    $aliasMap = [
        'can_assign_offers'           => 'can_assign_offers',
        'can_affiliate_reports'       => 'can_view_affiliate_reports',
        'can_view_affiliate_reports'  => 'can_view_affiliate_reports',
        'can_advertiser_reports'      => 'can_view_advertiser_reports',
        'can_view_advertiser_reports' => 'can_view_advertiser_reports',
        'can_all_reports'             => 'can_view_all_reports',
        'can_view_all_reports'        => 'can_view_all_reports',
        'can_manage_affiliates'       => 'can_manage_affiliates',
        'can_link_tester'             => 'can_link_tester',
        'can_manage_postbacks'        => 'can_manage_postbacks',
        'can_postback'                => 'can_manage_postbacks',
    ];
    $normalizedKey = $aliasMap[$key] ?? $key;

    $perms = get_manager_permissions($mId);
    return !empty($perms[$normalizedKey]);
}

