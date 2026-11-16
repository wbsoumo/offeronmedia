<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminUserId = auth_user_id();
$adminName   = $_SESSION['user_name'] ?? 'Admin';
$success     = $error = null;

$managerId = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['manager_id']) ? (int)$_GET['manager_id'] : 0);

if ($managerId <= 0) {
    header("Location: account_managers.php");
    exit;
}

/* ===============================
   FETCH MANAGER RECORD
================================ */
$mgrStmt = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.mobile,
        u.company,
        u.telegram_id,
        u.skype,
        u.status,
        u.last_login_at,
        u.last_login_ip,
        u.created_at,
        u.updated_at
    FROM users u
    WHERE u.user_id = ? AND u.role_id = 2
    LIMIT 1
");
$mgrStmt->execute([$managerId]);
$manager = $mgrStmt->fetch(PDO::FETCH_ASSOC);

if (!$manager) {
    die('<div style="font-family:sans-serif; text-align:center; padding:50px;"><h2>Account Manager not found.</h2><p><a href="account_managers.php">Return to Account Managers List</a></p></div>');
}

/* ===============================
   HANDLE ACTIONS (STATUS, PERMS, ASSIGN)
================================ */
// Toggle status (Activate / Block)
if (isset($_GET['toggle_status'])) {
    $newStatus = ($manager['status'] === 'active') ? 'blocked' : 'active';
    $upd = $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ? AND role_id = 2");
    $upd->execute([$newStatus, $managerId]);

    admin_log_action(
        $newStatus === 'blocked' ? 'block_manager' : 'activate_manager',
        'manager',
        $managerId,
        $manager['name'],
        "Account Manager status toggled to {$newStatus}"
    );

    header("Location: manager_profile.php?id={$managerId}&success=" . urlencode("Manager status updated to " . ucfirst($newStatus)));
    exit;
}

// Update Permissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_permissions'])) {
    $submittedPerms = $_POST['perms'] ?? [];
    save_manager_permissions($managerId, $submittedPerms);
    admin_log_action('update_manager_permissions', 'manager', $managerId, $manager['name'], 'Updated granular permissions');
    $success = "Permissions for Account Manager {$manager['name']} updated successfully!";
}

// Assign Affiliate to this Manager
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_affiliate'])) {
    $targetAffId = (int)($_POST['affiliate_id'] ?? 0);
    if ($targetAffId > 0) {
        $chk = $pdo->prepare("SELECT user_id, name FROM users WHERE user_id = ? AND role_id = 3 LIMIT 1");
        $chk->execute([$targetAffId]);
        $affData = $chk->fetch(PDO::FETCH_ASSOC);

        if ($affData) {
            $pdo->prepare("UPDATE users SET account_manager_id = ?, updated_at = NOW() WHERE user_id = ? AND role_id = 3")
                ->execute([$managerId, $targetAffId]);

            admin_log_action('assign_affiliate_manager', 'affiliate', $targetAffId, $affData['name'], "Assigned to Account Manager #{$managerId} ({$manager['name']})");
            $success = "Affiliate #{$targetAffId} ({$affData['name']}) assigned to {$manager['name']} successfully!";
        } else {
            $error = "Affiliate account not found.";
        }
    }
}

// Unassign Affiliate
if (isset($_GET['unassign_affiliate'])) {
    $unaffId = (int)$_GET['unassign_affiliate'];
    if ($unaffId > 0) {
        $pdo->prepare("UPDATE users SET account_manager_id = NULL, updated_at = NOW() WHERE user_id = ? AND account_manager_id = ? AND role_id = 3")
            ->execute([$unaffId, $managerId]);
        admin_log_action('unassign_affiliate_manager', 'affiliate', $unaffId, '', "Unassigned from Account Manager #{$managerId}");
        header("Location: manager_profile.php?id={$managerId}&success=" . urlencode("Affiliate unassigned successfully"));
        exit;
    }
}

// Update Profile Details
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $mobile   = trim($_POST['mobile'] ?? '');
    $telegram = trim($_POST['telegram_id'] ?? '');
    $skype    = trim($_POST['skype'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$name || !$email) {
        $error = "Name and Email are required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please provide a valid email address.";
    } else {
        // Check email uniqueness
        $emailChk = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1");
        $emailChk->execute([$email, $managerId]);
        if ($emailChk->fetch()) {
            $error = "This email is already in use by another user.";
        } else {
            if (!empty($password)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $uStmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, mobile = ?, telegram_id = ?, skype = ?, password_hash = ?, updated_at = NOW() WHERE user_id = ? AND role_id = 2");
                $uStmt->execute([$name, $email, $mobile, $telegram, $skype, $hash, $managerId]);
            } else {
                $uStmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, mobile = ?, telegram_id = ?, skype = ?, updated_at = NOW() WHERE user_id = ? AND role_id = 2");
                $uStmt->execute([$name, $email, $mobile, $telegram, $skype, $managerId]);
            }

            // Sync account_managers table if row exists
            $pdo->prepare("UPDATE account_managers SET name = ?, email = ?, telegram = ?, skype = ? WHERE user_id = ? OR id = ?")
                ->execute([$name, $email, $telegram, $skype, $managerId, $managerId]);

            admin_log_action('update_manager_profile', 'manager', $managerId, $name, 'Updated profile details');
            $success = "Account Manager profile updated successfully!";

            // Refresh manager data
            $mgrStmt->execute([$managerId]);
            $manager = $mgrStmt->fetch(PDO::FETCH_ASSOC);
        }
    }
}

if (!empty($_GET['success'])) {
    $success = $_GET['success'];
}

/* ===============================
   DATE & CAMPAIGN FILTERS
================================ */
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$filterCampaign = isset($_GET['campaign_id']) && $_GET['campaign_id'] !== 'all' ? (int)$_GET['campaign_id'] : 'all';
$filterAffStatus = $_GET['aff_status'] ?? 'all';

$filterWhereClicks = ["u.account_manager_id = :mid", "u.role_id = 3"];
$filterParamsClicks = ['mid' => $managerId];

if ($dateFrom && $dateTo) {
    $filterWhereClicks[] = "DATE(c.created_at) BETWEEN :dfrom AND :dto";
    $filterParamsClicks['dfrom'] = $dateFrom;
    $filterParamsClicks['dto']   = $dateTo;
}
if ($filterCampaign !== 'all') {
    $filterWhereClicks[] = "c.offer_id = :cid";
    $filterParamsClicks['cid'] = $filterCampaign;
}

$whereSqlClicks = "WHERE " . implode(' AND ', $filterWhereClicks);

/* ===============================
   AGGREGATED PERFORMANCE STATS
================================ */
// Total Assigned Publishers Breakdown
$affStatsStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_affiliates,
        SUM(status = 'active')   AS active_affiliates,
        SUM(status = 'pending')  AS pending_affiliates,
        SUM(status = 'blocked')  AS blocked_affiliates,
        COALESCE(SUM(balance), 0) AS total_balance
    FROM users
    WHERE role_id = 3 AND account_manager_id = ?
");
$affStatsStmt->execute([$managerId]);
$affSummary = $affStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_affiliates' => 0,
    'active_affiliates' => 0,
    'pending_affiliates' => 0,
    'blocked_affiliates' => 0,
    'total_balance' => 0.00
];

// Click & Conversion Performance Metrics (Currency Aware)
$kpiSql = "
    SELECT 
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN cv.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
        SUM(CASE WHEN cv.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_conversions,
        
        -- USD Metrics
        SUM(CASE WHEN cv.status = 'approved' AND (o.currency IS NULL OR o.currency = 'USD') THEN cv.revenue ELSE 0 END) AS revenue_usd,
        SUM(CASE WHEN cv.status = 'approved' AND (o.currency IS NULL OR o.currency = 'USD') THEN cv.payout ELSE 0 END) AS payout_usd,

        -- INR Metrics
        SUM(CASE WHEN cv.status = 'approved' AND o.currency = 'INR' THEN cv.revenue ELSE 0 END) AS revenue_inr,
        SUM(CASE WHEN cv.status = 'approved' AND o.currency = 'INR' THEN cv.payout ELSE 0 END) AS payout_inr

    FROM users u
    INNER JOIN clicks c ON c.affiliate_id = u.user_id
    LEFT JOIN offers o ON o.offer_id = c.offer_id
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    $whereSqlClicks
";

$kpiStmt = $pdo->prepare($kpiSql);
$kpiStmt->execute($filterParamsClicks);
$kpis = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalClicks = (int)($kpis['total_clicks'] ?? 0);
$approvedLeads = (int)($kpis['approved_conversions'] ?? 0);
$cr = $totalClicks > 0 ? ($approvedLeads / $totalClicks) * 100 : 0.00;

$revUSD = (float)($kpis['revenue_usd'] ?? 0);
$payUSD = (float)($kpis['payout_usd'] ?? 0);
$profitUSD = $revUSD - $payUSD;

$revINR = (float)($kpis['revenue_inr'] ?? 0);
$payINR = (float)($kpis['payout_inr'] ?? 0);
$profitINR = $revINR - $payINR;

/* ===============================
   FETCH ASSIGNED AFFILIATES LIST
================================ */
$affWhere = ["u.role_id = 3", "u.account_manager_id = :mid"];
$affParams = ['mid' => $managerId];

if ($filterAffStatus !== 'all') {
    $affWhere[] = "u.status = :status";
    $affParams['status'] = $filterAffStatus;
}
$affWhereSql = "WHERE " . implode(' AND ', $affWhere);

$assignedAffiliates = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.company,
        u.mobile,
        u.telegram_id,
        u.status,
        u.balance,
        u.created_at,
        COUNT(DISTINCT c.click_id) AS clicks,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN cv.status = 'approved' AND (o.currency IS NULL OR o.currency = 'USD') THEN cv.payout ELSE 0 END) AS earned_usd,
        SUM(CASE WHEN cv.status = 'approved' AND o.currency = 'INR' THEN cv.payout ELSE 0 END) AS earned_inr
    FROM users u
    LEFT JOIN clicks c ON c.affiliate_id = u.user_id
    LEFT JOIN offers o ON o.offer_id = c.offer_id
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    $affWhereSql
    GROUP BY u.user_id
    ORDER BY u.created_at DESC
");
$assignedAffiliates->execute($affParams);
$affiliatesList = $assignedAffiliates->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH CAMPAIGN PERFORMANCE LIST FOR THIS MANAGER
================================ */
$campStats = $pdo->prepare("
    SELECT 
        o.offer_id,
        o.offer_name,
        o.currency,
        o.status AS offer_status,
        COUNT(DISTINCT c.click_id) AS clicks,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS total_revenue,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_payout
    FROM clicks c
    INNER JOIN users u ON u.user_id = c.affiliate_id AND u.account_manager_id = :mid
    INNER JOIN offers o ON o.offer_id = c.offer_id
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    WHERE DATE(c.created_at) BETWEEN :dfrom AND :dto
    GROUP BY o.offer_id
    ORDER BY conversions DESC, clicks DESC
");
$campStats->execute(['mid' => $managerId, 'dfrom' => $dateFrom, 'dto' => $dateTo]);
$campaignReports = $campStats->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH UNASSIGNED OR OTHER AFFILIATES FOR ASSIGNMENT MODAL
================================ */
$unassignedAffs = $pdo->query("
    SELECT user_id, name, email, company, account_manager_id 
    FROM users 
    WHERE role_id = 3 AND (account_manager_id IS NULL OR account_manager_id != $managerId)
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   AVAILABLE OFFERS FOR FILTER
================================ */
$allOffers = $pdo->query("SELECT offer_id, offer_name FROM offers ORDER BY offer_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Manager Permissions
$permDefs = get_manager_permission_definitions();
$mPerms   = get_manager_permissions($managerId);

// Activity Logs
$logStmt = $pdo->prepare("
    SELECT * FROM admin_logs 
    WHERE (target_type = 'manager' AND target_id = ?) 
       OR (admin_id = ?)
    ORDER BY created_at DESC 
    LIMIT 20
");
$logStmt->execute([$managerId, $managerId]);
$activityLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($manager['name']); ?> - Affiliate Manager Profile | Admin Console</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">

    <style>
        .profile-card-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: #ffffff;
            border-radius: 12px;
            padding: 25px 30px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        .manager-avatar-large {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(59,130,246,0.4);
            border: 3px solid rgba(255,255,255,0.2);
        }
        .card-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 3px 16px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            background: #ffffff;
        }
        .stat-card-custom {
            border-radius: 12px;
            background: #ffffff;
            padding: 18px 15px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            text-align: center;
            margin-bottom: 15px;
            transition: all 0.2s ease;
        }
        .stat-card-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.06);
        }
        .stat-card-custom .stat-number {
            font-size: 22px;
            font-weight: 800;
            color: #1e293b;
        }
        .stat-card-custom .stat-label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        .nav-tabs-custom {
            border-bottom: 2px solid #e2e8f0;
        }
        .nav-tabs-custom .nav-link {
            font-weight: 700;
            color: #64748b;
            border: none;
            padding: 12px 20px;
            font-size: 14px;
        }
        .nav-tabs-custom .nav-link.active {
            color: #2563eb;
            border-bottom: 3px solid #2563eb;
            background: transparent;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="account_managers.php" class="nav-link">Account Managers</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="#" class="nav-link active"><?php echo htmlspecialchars($manager['name']); ?></a></li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.4rem;">
                <i class="fas fa-crown mr-2"></i><strong>Admin Console</strong>
            </span>
        </a>
        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="nav-icon fas fa-tachometer-alt"></i><p>Dashboard</p></a></li>
                    <li class="nav-header">CAMPAIGNS & OFFERS</li>
                    <li class="nav-item"><a href="campaigns.php" class="nav-link"><i class="nav-icon fas fa-bullhorn"></i><p>Manage Campaigns</p></a></li>
                    <li class="nav-item"><a href="offers.php" class="nav-link"><i class="nav-icon fas fa-tags"></i><p>Manage Offers</p></a></li>
                    <li class="nav-item"><a href="offers.php?status=archived" class="nav-link"><i class="nav-icon fas fa-archive"></i><p>Archived Offers</p></a></li>
                    <li class="nav-header">USER MANAGEMENT</li>
                    <li class="nav-item"><a href="publishers.php" class="nav-link"><i class="nav-icon fas fa-user-friends"></i><p>Publishers / Affiliates</p></a></li>
                    <li class="nav-item"><a href="advertisers.php" class="nav-link"><i class="nav-icon fas fa-briefcase"></i><p>Advertisers</p></a></li>
                    <li class="nav-item"><a href="account_managers.php" class="nav-link active"><i class="nav-icon fas fa-user-tie"></i><p>Account Managers</p></a></li>
                    <li class="nav-header">ANALYTICS & REPORTS</li>
                    <li class="nav-item"><a href="reports_clicks.php" class="nav-link"><i class="nav-icon fas fa-mouse-pointer"></i><p>Click Reports</p></a></li>
                    <li class="nav-item"><a href="reports_affiliates.php" class="nav-link"><i class="nav-icon fas fa-chart-line"></i><p>Affiliate Reports</p></a></li>
                    <li class="nav-item"><a href="reports_campaigns.php" class="nav-link"><i class="nav-icon fas fa-chart-bar"></i><p>Campaign Reports</p></a></li>
                    <li class="nav-item"><a href="reports_subid.php" class="nav-link"><i class="nav-icon fas fa-list"></i><p>SubID Performance</p></a></li>
                </ul>
            </nav>
        </div>
    </aside>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 font-weight-bold">Affiliate Manager Profile</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="account_managers.php">Account Managers</a></li>
                            <li class="breadcrumb-item active"><?php echo htmlspecialchars($manager['name']); ?></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <!-- Alert Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <!-- Manager Top Profile Banner -->
                <div class="profile-card-header">
                    <div class="d-flex flex-wrap justify-content-between align-items-center">
                        <div class="d-flex align-items-center mb-3 mb-md-0">
                            <div class="manager-avatar-large mr-4">
                                <?php echo strtoupper(substr($manager['name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div class="d-flex align-items-center">
                                    <h2 class="font-weight-bold mb-1 mr-3"><?php echo htmlspecialchars($manager['name']); ?></h2>
                                    <span class="badge badge-<?php echo $manager['status'] === 'active' ? 'success' : 'danger'; ?> px-3 py-1 font-weight-bold" style="font-size: 13px;">
                                        <i class="fas fa-<?php echo $manager['status'] === 'active' ? 'check-circle' : 'ban'; ?> mr-1"></i>
                                        <?php echo ucfirst($manager['status']); ?>
                                    </span>
                                </div>
                                <div class="text-white-50 small mt-1">
                                    <span class="mr-3"><i class="fas fa-id-badge mr-1"></i> ID: #<?php echo $manager['user_id']; ?></span>
                                    <span class="mr-3"><i class="fas fa-envelope mr-1"></i> <?php echo htmlspecialchars($manager['email']); ?></span>
                                    <?php if ($manager['mobile']): ?>
                                    <span class="mr-3"><i class="fas fa-phone mr-1"></i> <?php echo htmlspecialchars($manager['mobile']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($manager['telegram_id']): ?>
                                    <span class="mr-3"><i class="fab fa-telegram mr-1"></i> <?php echo htmlspecialchars($manager['telegram_id']); ?></span>
                                    <?php endif; ?>
                                    <span><i class="far fa-calendar-alt mr-1"></i> Joined: <?php echo date('M d, Y', strtotime($manager['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Header Action Buttons -->
                        <div class="d-flex flex-wrap gap-2">
                            <!-- Impersonation: Login as Account Manager in new tab -->
                            <a href="impersonate.php?id=<?php echo $manager['user_id']; ?>&target=manager" 
                               target="_blank" 
                               class="btn btn-warning font-weight-bold shadow-sm mr-2"
                               data-toggle="tooltip" 
                               title="Open Account Manager dashboard in a secure new browser session">
                                <i class="fas fa-user-secret mr-1"></i> Login as Account Manager
                            </a>

                            <button type="button" class="btn btn-outline-light font-weight-bold mr-2" data-toggle="modal" data-target="#editProfileModal">
                                <i class="fas fa-edit mr-1"></i> Edit Profile
                            </button>

                            <?php if ($manager['status'] === 'active'): ?>
                            <a href="?id=<?php echo $manager['user_id']; ?>&toggle_status=1" 
                               class="btn btn-outline-danger font-weight-bold"
                               onclick="return confirm('Are you sure you want to block this Account Manager? They will be barred from logging into the manager portal.');">
                                <i class="fas fa-ban mr-1"></i> Block Manager
                            </a>
                            <?php else: ?>
                            <a href="?id=<?php echo $manager['user_id']; ?>&toggle_status=1" 
                               class="btn btn-outline-success font-weight-bold"
                               onclick="return confirm('Re-activate this Account Manager account?');">
                                <i class="fas fa-check-circle mr-1"></i> Unblock / Activate
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Comprehensive Filters Filter Bar -->
                <div class="card card-custom p-3 mb-4">
                    <form method="get" class="row align-items-end">
                        <input type="hidden" name="id" value="<?php echo $managerId; ?>">
                        <div class="col-md-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Date Range From</label>
                            <input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Date Range To</label>
                            <input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="font-weight-bold small text-muted text-uppercase">Filter by Campaign</label>
                            <select name="campaign_id" class="form-control select2">
                                <option value="all">All Campaigns</option>
                                <?php foreach ($allOffers as $c): ?>
                                <option value="<?php echo $c['offer_id']; ?>" <?php echo $filterCampaign == $c['offer_id'] ? 'selected' : ''; ?>>
                                    #<?php echo $c['offer_id']; ?> - <?php echo htmlspecialchars($c['offer_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex">
                                <button type="submit" class="btn btn-primary font-weight-bold btn-block mr-2">
                                    <i class="fas fa-filter mr-1"></i> Apply Filters
                                </button>
                                <a href="manager_profile.php?id=<?php echo $managerId; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-redo"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- KPI Performance Cards (Responsive Grid) -->
                <div class="row mb-3">
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($affSummary['total_affiliates']); ?></div>
                            <div class="stat-label">Total Affiliates</div>
                            <small class="text-muted d-block mt-1">
                                <span class="text-success"><?php echo (int)$affSummary['active_affiliates']; ?> Active</span> &bull; 
                                <span class="text-danger"><?php echo (int)$affSummary['blocked_affiliates']; ?> Blocked</span>
                            </small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-indigo" style="color:#4f46e5;"><?php echo number_format($totalClicks); ?></div>
                            <div class="stat-label">Generated Clicks</div>
                            <small class="text-muted d-block mt-1">During selected period</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($approvedLeads); ?></div>
                            <div class="stat-label">Approved Leads</div>
                            <small class="text-muted d-block mt-1"><?php echo number_format($cr, 2); ?>% Conversion Rate</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info" style="font-size: 19px;">
                                <?php echo format_dual_currency($revUSD, $revINR); ?>
                            </div>
                            <div class="stat-label">Gross Revenue</div>
                            <small class="text-muted d-block mt-1">USD & INR Breakdown</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning" style="font-size: 19px;">
                                <?php echo format_dual_currency($payUSD, $payINR); ?>
                            </div>
                            <div class="stat-label">Affiliate Payouts</div>
                            <small class="text-muted d-block mt-1">Commissions Paid</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success" style="font-size: 19px;">
                                <?php echo format_dual_currency($profitUSD, $profitINR); ?>
                            </div>
                            <div class="stat-label">Net Profit</div>
                            <small class="text-muted d-block mt-1">Network Earnings</small>
                        </div>
                    </div>
                </div>

                <!-- Navigation Tabs -->
                <div class="card card-custom">
                    <div class="card-header p-0">
                        <ul class="nav nav-tabs nav-tabs-custom px-3" id="profileTabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" id="affiliates-tab" data-toggle="tab" href="#tab-affiliates" role="tab">
                                    <i class="fas fa-user-friends mr-2"></i>Assigned Affiliates (<?php echo count($affiliatesList); ?>)
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="campaigns-tab" data-toggle="tab" href="#tab-campaigns" role="tab">
                                    <i class="fas fa-bullhorn mr-2"></i>Campaign Performance (<?php echo count($campaignReports); ?>)
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="permissions-tab" data-toggle="tab" href="#tab-permissions" role="tab">
                                    <i class="fas fa-shield-alt mr-2"></i>Manager Permissions
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="activity-tab" data-toggle="tab" href="#tab-activity" role="tab">
                                    <i class="fas fa-history mr-2"></i>Activity History
                                </a>
                            </li>
                        </ul>
                    </div>

                    <div class="card-body p-4">
                        <div class="tab-content">

                            <!-- TAB 1: ASSIGNED AFFILIATES -->
                            <div class="tab-pane fade show active" id="tab-affiliates" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="font-weight-bold text-dark mb-0">Affiliates Managed by <?php echo htmlspecialchars($manager['name']); ?></h5>
                                    <button type="button" class="btn btn-primary btn-sm font-weight-bold shadow-sm" data-toggle="modal" data-target="#assignAffModal">
                                        <i class="fas fa-user-plus mr-1"></i> Assign Another Affiliate
                                    </button>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-hover align-middle" id="affTable">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>ID</th>
                                                <th>Affiliate Name</th>
                                                <th>Company</th>
                                                <th>Contact</th>
                                                <th>Status</th>
                                                <th>Clicks</th>
                                                <th>Approved Leads</th>
                                                <th>Earnings</th>
                                                <th>Balance</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($affiliatesList as $aff): 
                                                $statusClass = $aff['status'] === 'active' ? 'success' : ($aff['status'] === 'blocked' ? 'danger' : 'warning');
                                            ?>
                                            <tr>
                                                <td><strong>#<?php echo $aff['user_id']; ?></strong></td>
                                                <td>
                                                    <a href="affiliate_details.php?id=<?php echo $aff['user_id']; ?>" class="font-weight-bold text-primary">
                                                        <?php echo htmlspecialchars($aff['name']); ?>
                                                    </a>
                                                    <small class="text-muted d-block"><?php echo htmlspecialchars($aff['email']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($aff['company'] ?: 'Individual'); ?></td>
                                                <td>
                                                    <small>
                                                        <?php if ($aff['telegram_id']): ?><span class="badge badge-light border"><i class="fab fa-telegram text-info"></i> <?php echo htmlspecialchars($aff['telegram_id']); ?></span><br><?php endif; ?>
                                                        <?php if ($aff['mobile']): ?><span class="text-muted"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($aff['mobile']); ?></span><?php endif; ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo $statusClass; ?> px-2 py-1">
                                                        <?php echo ucfirst($aff['status']); ?>
                                                    </span>
                                                </td>
                                                <td><strong><?php echo number_format($aff['clicks']); ?></strong></td>
                                                <td><span class="badge badge-success px-2 py-1"><?php echo number_format($aff['approved_conversions']); ?></span></td>
                                                <td>
                                                    <strong><?php echo format_dual_currency($aff['earned_usd'], $aff['earned_inr']); ?></strong>
                                                </td>
                                                <td class="font-weight-bold text-dark">$<?php echo number_format($aff['balance'], 2); ?></td>
                                                <td>
                                                    <a href="affiliate_details.php?id=<?php echo $aff['user_id']; ?>" class="btn btn-xs btn-outline-primary" data-toggle="tooltip" title="View Full Affiliate Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="?id=<?php echo $managerId; ?>&unassign_affiliate=<?php echo $aff['user_id']; ?>" 
                                                       class="btn btn-xs btn-outline-danger" 
                                                       data-toggle="tooltip" 
                                                       title="Unassign from this manager"
                                                       onclick="return confirm('Remove this affiliate from <?php echo htmlspecialchars($manager['name']); ?>?');">
                                                        <i class="fas fa-user-minus"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- TAB 2: CAMPAIGN PERFORMANCE -->
                            <div class="tab-pane fade" id="tab-campaigns" role="tabpanel">
                                <h5 class="font-weight-bold text-dark mb-3">Campaigns Generated by this Manager's Publishers</h5>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle" id="campTable">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Campaign ID</th>
                                                <th>Offer Name</th>
                                                <th>Currency</th>
                                                <th>Clicks</th>
                                                <th>Approved Conversions</th>
                                                <th>CR %</th>
                                                <th>Revenue</th>
                                                <th>Payout</th>
                                                <th>Net Profit</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($campaignReports as $camp): 
                                                $campCr = $camp['clicks'] > 0 ? ($camp['conversions'] / $camp['clicks']) * 100 : 0;
                                                $campProfit = $camp['total_revenue'] - $camp['total_payout'];
                                                $cur = $camp['currency'] ?: 'USD';
                                            ?>
                                            <tr>
                                                <td><strong>#<?php echo $camp['offer_id']; ?></strong></td>
                                                <td>
                                                    <a href="offer_details.php?id=<?php echo $camp['offer_id']; ?>" class="font-weight-bold text-primary">
                                                        <?php echo htmlspecialchars($camp['offer_name']); ?>
                                                    </a>
                                                </td>
                                                <td><span class="badge badge-light border font-weight-bold"><?php echo htmlspecialchars($cur); ?></span></td>
                                                <td><?php echo number_format($camp['clicks']); ?></td>
                                                <td><span class="badge badge-success px-2 py-1"><?php echo number_format($camp['conversions']); ?></span></td>
                                                <td><?php echo number_format($campCr, 2); ?>%</td>
                                                <td class="text-success font-weight-bold"><?php echo format_currency($camp['total_revenue'], $cur); ?></td>
                                                <td class="text-warning font-weight-bold"><?php echo format_currency($camp['total_payout'], $cur); ?></td>
                                                <td class="text-primary font-weight-bold"><?php echo format_currency($campProfit, $cur); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- TAB 3: GRANULAR PERMISSIONS -->
                            <div class="tab-pane fade" id="tab-permissions" role="tabpanel">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div>
                                        <h5 class="font-weight-bold text-dark mb-0">Manager Role & Module Permissions</h5>
                                        <small class="text-muted">Admins have complete control over what this Account Manager can view, create, edit, block, or export.</small>
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-xs btn-outline-primary font-weight-bold mr-1" onclick="toggleAllPerms(true)">Select All</button>
                                        <button type="button" class="btn btn-xs btn-outline-secondary font-weight-bold" onclick="toggleAllPerms(false)">Clear All</button>
                                    </div>
                                </div>

                                <form method="post">
                                    <input type="hidden" name="save_permissions" value="1">
                                    
                                    <?php 
                                    // Group permissions by category
                                    $groupedPerms = [];
                                    foreach ($permDefs as $pKey => $pDef) {
                                        $cat = $pDef['category'] ?? 'General';
                                        $groupedPerms[$cat][$pKey] = $pDef;
                                    }

                                    foreach ($groupedPerms as $catName => $pList): 
                                    ?>
                                    <div class="card card-custom border mb-4">
                                        <div class="card-header bg-light py-2 px-3">
                                            <h6 class="font-weight-bold mb-0 text-primary"><i class="fas fa-folder-open mr-2"></i><?php echo htmlspecialchars($catName); ?></h6>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="list-group list-group-flush">
                                                <?php foreach ($pList as $pKey => $pDef): 
                                                    $isChecked = !empty($mPerms[$pKey]);
                                                ?>
                                                <div class="list-group-item d-flex justify-content-between align-items-center py-3 px-3">
                                                    <div class="pr-3">
                                                        <strong class="d-block text-dark"><?php echo htmlspecialchars($pDef['label']); ?></strong>
                                                        <small class="text-muted"><?php echo htmlspecialchars($pDef['desc']); ?></small>
                                                    </div>
                                                    <div class="custom-control custom-switch custom-switch-lg">
                                                        <input type="checkbox" class="custom-control-input perm-switch" id="sw_<?php echo $pKey; ?>" name="perms[<?php echo $pKey; ?>]" value="1" <?php echo $isChecked ? 'checked' : ''; ?>>
                                                        <label class="custom-control-label" for="sw_<?php echo $pKey; ?>"></label>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>

                                    <button type="submit" class="btn btn-primary font-weight-bold px-4 py-2 shadow-sm">
                                        <i class="fas fa-save mr-1"></i> Save Updated Permissions
                                    </button>
                                </form>
                            </div>

                            <!-- TAB 4: ACTIVITY LOGS -->
                            <div class="tab-pane fade" id="tab-activity" role="tabpanel">
                                <h5 class="font-weight-bold text-dark mb-3">Recent Security & Activity History</h5>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Timestamp</th>
                                                <th>Admin / Actor</th>
                                                <th>Action</th>
                                                <th>Details</th>
                                                <th>IP Address</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($activityLogs)): ?>
                                            <tr><td colspan="5" class="text-center text-muted py-4">No logged activity records yet.</td></tr>
                                            <?php else: ?>
                                            <?php foreach ($activityLogs as $log): ?>
                                            <tr>
                                                <td><small class="text-muted"><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></small></td>
                                                <td><strong><?php echo htmlspecialchars($log['admin_name'] ?: 'System'); ?></strong></td>
                                                <td><span class="badge badge-light border font-weight-bold"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                                <td><?php echo htmlspecialchars($log['details'] ?: 'N/A'); ?></td>
                                                <td><code><?php echo htmlspecialchars($log['ip_address']); ?></code></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Edit Profile Modal -->
    <div class="modal fade" id="editProfileModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content" style="border-radius: 12px; border:none;">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-user-edit mr-2"></i>Edit Manager Profile</h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <form method="post">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="modal-body p-4">
                        <div class="form-group mb-3">
                            <label class="font-weight-bold">Manager Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($manager['name']); ?>" required>
                        </div>
                        <div class="form-group mb-3">
                            <label class="font-weight-bold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($manager['email']); ?>" required>
                        </div>
                        <div class="form-group mb-3">
                            <label class="font-weight-bold">Mobile / Phone</label>
                            <input type="text" name="mobile" class="form-control" value="<?php echo htmlspecialchars($manager['mobile'] ?? ''); ?>">
                        </div>
                        <div class="form-group mb-3">
                            <label class="font-weight-bold">Telegram Username</label>
                            <input type="text" name="telegram_id" class="form-control" value="<?php echo htmlspecialchars($manager['telegram_id'] ?? ''); ?>" placeholder="@username">
                        </div>
                        <div class="form-group mb-3">
                            <label class="font-weight-bold">Skype ID</label>
                            <input type="text" name="skype" class="form-control" value="<?php echo htmlspecialchars($manager['skype'] ?? ''); ?>">
                        </div>
                        <div class="form-group mb-0">
                            <label class="font-weight-bold">Reset Password <small class="text-muted">(Leave empty to keep current password)</small></label>
                            <input type="password" name="password" class="form-control" placeholder="Enter new password if changing">
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary font-weight-bold">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assign Affiliate Modal -->
    <div class="modal fade" id="assignAffModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content" style="border-radius: 12px; border:none;">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-user-plus mr-2"></i>Assign Affiliate to <?php echo htmlspecialchars($manager['name']); ?></h5>
                    <button type="button" class="close text-white" data-dismiss="modal"><span>&times;</span></button>
                </div>
                <form method="post">
                    <input type="hidden" name="assign_affiliate" value="1">
                    <div class="modal-body p-4">
                        <div class="form-group mb-0">
                            <label class="font-weight-bold mb-2">Select Affiliate to Assign:</label>
                            <select name="affiliate_id" class="form-control select2" required style="width: 100%;">
                                <option value="">Choose an affiliate...</option>
                                <?php foreach ($unassignedAffs as $uAff): ?>
                                <option value="<?php echo $uAff['user_id']; ?>">
                                    #<?php echo $uAff['user_id']; ?> - <?php echo htmlspecialchars($uAff['name']); ?> (<?php echo htmlspecialchars($uAff['email']); ?>)
                                    <?php echo !empty($uAff['account_manager_id']) ? ' [Currently Assigned to #' . $uAff['account_manager_id'] . ']' : ' [Unassigned]'; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success font-weight-bold">Assign Now</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Admin Panel v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Offer on Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    $('.select2').select2({ theme: 'bootstrap4', width: '100%' });
    $('[data-toggle="tooltip"]').tooltip();
    $('#affTable').DataTable({ pageLength: 10, responsive: true });
    $('#campTable').DataTable({ pageLength: 10, responsive: true });
});

function toggleAllPerms(state) {
    $('.perm-switch').prop('checked', state);
}
</script>
</body>
</html>

