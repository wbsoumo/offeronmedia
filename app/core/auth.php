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
 * Require login & verify user account status
 */
function require_auth()
{
    if (!auth_check()) {
        $loginUrl = auth_is_admin_context() ? '/admin/login.php' : '/login.php';
        header('Location: ' . $loginUrl);
        exit;
    }

    // Verify user account status from database
    global $pdo;
    if ($pdo && !empty($_SESSION['auth']['user_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT status FROM users WHERE user_id = ? LIMIT 1");
            $stmt->execute([(int)$_SESSION['auth']['user_id']]);
            $status = $stmt->fetchColumn();

            if ($status !== 'active' && empty($_SESSION['is_impersonating'])) {
                // If user is blocked, force logout
                auth_logout();
                $loginUrl = auth_is_admin_context() ? '/admin/login.php?error=Account+is+blocked+or+inactive' : '/login.php?error=Account+is+blocked+or+inactive';
                header('Location: ' . $loginUrl);
                exit;
            }
        } catch (Exception $e) {
            // Silently continue if query fails
        }
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
 * Definition of available manager permissions (categorized)
 */
function get_manager_permission_definitions()
{
    return [
        // Publishers / Affiliates Module
        'can_view_affiliates' => [
            'category' => 'Affiliates Management',
            'label'    => 'View Assigned Affiliates',
            'desc'     => 'Allow manager to view affiliate lists, profiles, and basic contact info.',
            'default'  => 1
        ],
        'can_create_affiliates' => [
            'category' => 'Affiliates Management',
            'label'    => 'Register / Add Affiliates',
            'desc'     => 'Allow manager to create and onboard new affiliate accounts directly.',
            'default'  => 1
        ],
        'can_edit_affiliates' => [
            'category' => 'Affiliates Management',
            'label'    => 'Edit Affiliate Profile & Banking',
            'desc'     => 'Allow manager to edit affiliate contact information and payment details.',
            'default'  => 1
        ],
        'can_approve_affiliates' => [
            'category' => 'Affiliates Management',
            'label'    => 'Approve Pending Affiliates',
            'desc'     => 'Allow manager to review and approve newly registered pending affiliates.',
            'default'  => 1
        ],
        'can_block_affiliates' => [
            'category' => 'Affiliates Management',
            'label'    => 'Block / Suspend Affiliates',
            'desc'     => 'Allow manager to block affiliates and automatically revoke offer access.',
            'default'  => 1
        ],
        'can_export_affiliates' => [
            'category' => 'Affiliates Management',
            'label'    => 'Export Affiliates Data',
            'desc'     => 'Allow manager to download CSV / Excel rosters of assigned publishers.',
            'default'  => 1
        ],

        // Campaigns & Offers Module
        'can_view_campaigns' => [
            'category' => 'Campaigns & Offers',
            'label'    => 'View Available Campaigns',
            'desc'     => 'Allow manager to browse campaigns catalog and tracking links.',
            'default'  => 1
        ],
        'can_assign_offers' => [
            'category' => 'Campaigns & Offers',
            'label'    => 'Assign Offers to Affiliates',
            'desc'     => 'Allow manager to assign private and active offers to their publishers.',
            'default'  => 1
        ],
        'can_edit_campaign_payouts' => [
            'category' => 'Campaigns & Offers',
            'label'    => 'Custom Payouts & Cap Control',
            'desc'     => 'Allow manager to assign custom payout rates or caps for specific affiliates.',
            'default'  => 1
        ],
        'can_manage_campaign_access' => [
            'category' => 'Campaigns & Offers',
            'label'    => 'Approve / Reject Offer Requests',
            'desc'     => 'Allow manager to approve or decline affiliate requests for private offers.',
            'default'  => 1
        ],

        // Analytics & Reporting Module
        'can_view_affiliate_reports' => [
            'category' => 'Analytics & Reports',
            'label'    => 'Check Affiliate Performance Reports',
            'desc'     => 'Allow manager to view performance analytics grouped by their publishers.',
            'default'  => 1
        ],
        'can_view_advertiser_reports' => [
            'category' => 'Analytics & Reports',
            'label'    => 'Check Advertisers Reports',
            'desc'     => 'Allow manager to view performance reports aggregated by advertiser.',
            'default'  => 0
        ],
        'can_view_campaign_reports' => [
            'category' => 'Analytics & Reports',
            'label'    => 'Check Campaign Reports',
            'desc'     => 'Allow manager to view aggregate campaign-wise performance stats.',
            'default'  => 1
        ],
        'can_view_click_reports' => [
            'category' => 'Analytics & Reports',
            'label'    => 'Check Detailed Click & SubID Reports',
            'desc'     => 'Allow manager to inspect raw tracking click logs, SubID parameters & IP details.',
            'default'  => 1
        ],
        'can_view_all_reports' => [
            'category' => 'Analytics & Reports',
            'label'    => 'Global Reports Access',
            'desc'     => 'Allow manager to view all system reports without manager-level scoping.',
            'default'  => 0
        ],
        'can_export_reports' => [
            'category' => 'Analytics & Reports',
            'label'    => 'Export Reports to CSV / Excel',
            'desc'     => 'Allow manager to export performance data, click logs, and lead rosters.',
            'default'  => 1
        ],

        // Financial & Margins
        'can_view_financials' => [
            'category' => 'Financial Details',
            'label'    => 'View Gross Revenue & Profit Margins',
            'desc'     => 'Allow manager to see advertiser revenue rates and net profit figures.',
            'default'  => 1
        ],

        // Technical & Testing Tools
        'can_manage_postbacks' => [
            'category' => 'Technical & Tools',
            'label'    => 'Configure Postbacks',
            'desc'     => 'Allow manager to configure global & campaign postbacks for their affiliates.',
            'default'  => 1
        ],
        'can_link_tester' => [
            'category' => 'Technical & Tools',
            'label'    => 'Link Tester Simulator',
            'desc'     => 'Allow manager to simulate tracking redirects and macro replacements.',
            'default'  => 1
        ]
    ];
}

/**
 * Ensure manager_permissions table exists with all granular columns
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

        // Safely check and add any missing columns
        $defs = get_manager_permission_definitions();
        foreach ($defs as $key => $meta) {
            $chk = $pdo->query("SHOW COLUMNS FROM `manager_permissions` LIKE '$key'")->fetchAll();
            if (empty($chk)) {
                $defVal = (int)$meta['default'];
                $pdo->exec("ALTER TABLE `manager_permissions` ADD COLUMN `$key` TINYINT(1) NOT NULL DEFAULT $defVal");
            }
        }

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
        $fields[] = "`$key`";
        $values[] = $val;
        $updates[] = "`$key` = VALUES(`$key`)";
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
        // Affiliates
        'can_view_affiliates'         => 'can_view_affiliates',
        'can_affiliates'              => 'can_view_affiliates',
        'can_create_affiliates'       => 'can_create_affiliates',
        'can_edit_affiliates'         => 'can_edit_affiliates',
        'can_approve_affiliates'      => 'can_approve_affiliates',
        'can_block_affiliates'        => 'can_block_affiliates',
        'can_manage_affiliates'       => 'can_approve_affiliates',
        'can_export_affiliates'       => 'can_export_affiliates',

        // Campaigns
        'can_view_campaigns'          => 'can_view_campaigns',
        'can_campaigns'               => 'can_view_campaigns',
        'can_assign_offers'           => 'can_assign_offers',
        'can_assign'                  => 'can_assign_offers',
        'can_edit_campaign_payouts'   => 'can_edit_campaign_payouts',
        'can_manage_campaign_access'  => 'can_manage_campaign_access',

        // Reports
        'can_affiliate_reports'       => 'can_view_affiliate_reports',
        'can_view_affiliate_reports'  => 'can_view_affiliate_reports',
        'can_advertiser_reports'      => 'can_view_advertiser_reports',
        'can_view_advertiser_reports' => 'can_view_advertiser_reports',
        'can_campaign_reports'        => 'can_view_campaign_reports',
        'can_view_campaign_reports'   => 'can_view_campaign_reports',
        'can_click_reports'           => 'can_view_click_reports',
        'can_view_click_reports'      => 'can_view_click_reports',
        'can_all_reports'             => 'can_view_all_reports',
        'can_view_all_reports'        => 'can_view_all_reports',
        'can_export_reports'          => 'can_export_reports',
        'can_export'                  => 'can_export_reports',

        // Financials
        'can_view_financials'         => 'can_view_financials',
        'can_financials'              => 'can_view_financials',

        // Tools
        'can_link_tester'             => 'can_link_tester',
        'can_manage_postbacks'        => 'can_manage_postbacks',
        'can_postback'                => 'can_manage_postbacks',
    ];
    $normalizedKey = $aliasMap[$key] ?? $key;

    $perms = get_manager_permissions($mId);
    return !empty($perms[$normalizedKey]);
}

/* =================================================
   ADMIN AUDIT LOGGING HELPER
================================================= */

/**
 * Record administrative or security event in admin_logs
 */
function admin_log_action($action, $targetType = null, $targetId = null, $targetName = null, $details = null)
{
    global $pdo;
    if (!$pdo) return false;

    try {
        $adminId   = auth_user_id() ?: 0;
        $adminName = $_SESSION['user_name'] ?? 'System';
        $clientIp  = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $pdo->prepare("
            INSERT INTO admin_logs (admin_id, admin_name, action, target_type, target_id, target_name, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        return $stmt->execute([
            $adminId,
            $adminName,
            $action,
            $targetType,
            $targetId,
            $targetName,
            $details,
            $clientIp
        ]);
    } catch (Exception $e) {
        error_log('admin_log_action error: ' . $e->getMessage());
        return false;
    }
}

/* =================================================
   USER BANNING & OFFER ACCESS CONTROL (REQ #5)
================================================= */

/**
 * Block / Ban an affiliate and automatically suspend all their active offers
 */
function affiliate_block($affiliateId, $reason = '')
{
    global $pdo;
    $affiliateId = (int)$affiliateId;
    if ($affiliateId <= 0 || !$pdo) return false;

    try {
        $pdo->beginTransaction();

        // 1. Fetch user name for logging
        $uStmt = $pdo->prepare("SELECT name, email, status FROM users WHERE user_id = ? AND role_id = 3 LIMIT 1");
        $uStmt->execute([$affiliateId]);
        $user = $uStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $pdo->rollBack();
            return false;
        }

        // 2. Set user status to blocked
        $pdo->prepare("UPDATE users SET status = 'blocked', updated_at = NOW() WHERE user_id = ? AND role_id = 3")
            ->execute([$affiliateId]);

        // 3. Automatically disable all approved offers assigned to this affiliate
        // Mark suspended_by_user_block = 1 so we can restore exact assignments on unblock
        $pdo->prepare("
            UPDATE affiliate_offer_approval 
            SET status = 'blocked', suspended_by_user_block = 1 
            WHERE affiliate_id = ? AND status = 'approved'
        ")->execute([$affiliateId]);

        // 4. Log admin audit
        admin_log_action(
            'block_affiliate',
            'affiliate',
            $affiliateId,
            $user['name'] ?? '',
            "Affiliate blocked. All active offer tracking links disabled. Reason: " . ($reason ?: 'Administrative Block')
        );

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('affiliate_block error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Unblock / Activate an affiliate and restore previous offer assignments
 */
function affiliate_unblock($affiliateId)
{
    global $pdo;
    $affiliateId = (int)$affiliateId;
    if ($affiliateId <= 0 || !$pdo) return false;

    try {
        $pdo->beginTransaction();

        // 1. Fetch user name for logging
        $uStmt = $pdo->prepare("SELECT name, email, status FROM users WHERE user_id = ? AND role_id = 3 LIMIT 1");
        $uStmt->execute([$affiliateId]);
        $user = $uStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $pdo->rollBack();
            return false;
        }

        // 2. Set user status to active
        $pdo->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE user_id = ? AND role_id = 3")
            ->execute([$affiliateId]);

        // 3. Restore offers that were suspended due to the user ban
        $pdo->prepare("
            UPDATE affiliate_offer_approval 
            SET status = 'approved', suspended_by_user_block = 0 
            WHERE affiliate_id = ? AND suspended_by_user_block = 1
        ")->execute([$affiliateId]);

        // 4. Log admin audit
        admin_log_action(
            'unblock_affiliate',
            'affiliate',
            $affiliateId,
            $user['name'] ?? '',
            "Affiliate unblocked. Eligible offer assignments and tracking restored."
        );

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('affiliate_unblock error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Bulk block affiliates
 */
function affiliates_bulk_block(array $affiliateIds, $reason = '')
{
    $count = 0;
    foreach ($affiliateIds as $id) {
        if (affiliate_block((int)$id, $reason)) {
            $count++;
        }
    }
    return $count;
}

/**
 * Bulk unblock affiliates
 */
function affiliates_bulk_unblock(array $affiliateIds)
{
    $count = 0;
    foreach ($affiliateIds as $id) {
        if (affiliate_unblock((int)$id)) {
            $count++;
        }
    }
    return $count;
}

/* =================================================
   CURRENCY DISPLAY & CALCULATION HELPERS (REQ #4)
================================================= */

/**
 * Get currency symbol ($ or ₹)
 */
function get_currency_symbol($currency = 'USD')
{
    return strtoupper((string)$currency) === 'INR' ? '₹' : '$';
}

/**
 * Format currency with proper symbol
 */
function format_currency($amount, $currency = 'USD', $decimals = 2)
{
    $sym = get_currency_symbol($currency);
    $amt = number_format((float)$amount, $decimals);
    return $sym . $amt;
}

/**
 * Format multi-currency dual breakdown (e.g. $100.00 | ₹5,000.00)
 */
function format_dual_currency($usdAmount, $inrAmount, $decimals = 2)
{
    $usd = (float)$usdAmount;
    $inr = (float)$inrAmount;

    if ($usd > 0 && $inr > 0) {
        return '$' . number_format($usd, $decimals) . ' <span class="text-muted">/</span> ₹' . number_format($inr, $decimals);
    } elseif ($inr > 0) {
        return '₹' . number_format($inr, $decimals);
    } else {
        return '$' . number_format($usd, $decimals);
    }
}

