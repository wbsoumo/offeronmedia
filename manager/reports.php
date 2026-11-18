<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('manager');

$managerId   = auth_user_id();
$managerName = $_SESSION['user_name'] ?? 'Account Manager';

// Permission checks for reporting
$canAffReports = manager_can('affiliate_reports') || manager_can('can_view_affiliates');
$canCampReports = manager_can('campaign_reports') || manager_can('can_view_campaigns');
$canClickReports = manager_can('click_reports');
$canAdvReports = manager_can('advertiser_reports');
$canAllReports = manager_can('all_reports');
$canViewFinancials = manager_can('can_view_financials');
$hasAnyReportPermission = $canAffReports || $canCampReports || $canClickReports || $canAdvReports || $canAllReports;

// Determine active tab
$defaultTab = 'campaigns';
$activeTab  = $_GET['tab'] ?? $defaultTab;

$affiliateReports = [];
$advertiserReports = [];
$campaignReports   = [];
$subIdReports      = [];
$clickReports      = [];

if ($hasAnyReportPermission) {
    // 1. Fetch Campaign Performance Reports
    $stmtCamp = $pdo->prepare("
        SELECT 
            o.offer_id,
            o.offer_name,
            o.category,
            o.currency,
            COUNT(DISTINCT c.click_id) AS clicks,
            SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS conversions,
            IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS total_revenue,
            IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_payout
        FROM users aff
        INNER JOIN clicks c ON c.affiliate_id = aff.user_id
        INNER JOIN offers o ON o.offer_id = c.offer_id
        LEFT JOIN conversions cv ON cv.click_id = c.click_id
        WHERE aff.role_id = 3 AND aff.account_manager_id = ?
        GROUP BY o.offer_id
        ORDER BY clicks DESC
    ");
    $stmtCamp->execute([$managerId]);
    $campaignReports = $stmtCamp->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch Affiliate / Publisher Reports
    $stmtAff = $pdo->prepare("
        SELECT 
            o.offer_id,
            o.offer_name,
            o.currency,
            u.user_id AS affiliate_id,
            u.name AS affiliate_name,
            u.company AS affiliate_company,
            u.status AS affiliate_status,
            COUNT(DISTINCT c.click_id) AS clicks,
            SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
            IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_payout,
            IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS total_revenue
        FROM users u
        INNER JOIN clicks c ON c.affiliate_id = u.user_id
        INNER JOIN offers o ON o.offer_id = c.offer_id
        LEFT JOIN conversions cv ON cv.click_id = c.click_id
        WHERE u.role_id = 3 AND u.account_manager_id = ?
        GROUP BY o.offer_id, u.user_id
        ORDER BY clicks DESC
    ");
    $stmtAff->execute([$managerId]);
    $affiliateReports = $stmtAff->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch Click Reports (Raw Click Logs scoped to manager)
    if ($activeTab === 'clicks' || $canClickReports) {
        $clicksQuery = $pdo->prepare("
            SELECT 
                c.click_id,
                c.created_at,
                c.ip_address,
                c.country,
                c.device,
                c.sub1, c.sub2, c.sub3, c.sub4, c.sub5,
                o.offer_id,
                o.offer_name,
                o.currency,
                u.user_id as affiliate_id,
                u.name as affiliate_name,
                cv.status as conversion_status,
                cv.payout as conversion_payout
            FROM clicks c
            INNER JOIN users u ON u.user_id = c.affiliate_id
            INNER JOIN offers o ON o.offer_id = c.offer_id
            LEFT JOIN conversions cv ON cv.click_id = c.click_id
            WHERE u.role_id = 3 AND u.account_manager_id = ?
            ORDER BY c.created_at DESC
            LIMIT 250
        ");
        $clicksQuery->execute([$managerId]);
        $clickReports = $clicksQuery->fetchAll(PDO::FETCH_ASSOC);
    }

    // 4. Fetch SubID Reports (Breakdown of Sub1 through Sub5)
    if ($activeTab === 'subid' || $canAllReports) {
        $stmtSub = $pdo->prepare("
            SELECT 
                c.sub1, c.sub2, c.sub3,
                o.offer_id,
                o.offer_name,
                o.currency,
                aff.name AS affiliate_name,
                COUNT(DISTINCT c.click_id) AS clicks,
                SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS conversions,
                IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_payout
            FROM users aff
            INNER JOIN clicks c ON c.affiliate_id = aff.user_id
            INNER JOIN offers o ON o.offer_id = c.offer_id
            LEFT JOIN conversions cv ON cv.click_id = c.click_id
            WHERE aff.role_id = 3 AND aff.account_manager_id = ? 
              AND ((c.sub1 IS NOT NULL AND c.sub1 != '') OR (c.sub2 IS NOT NULL AND c.sub2 != ''))
            GROUP BY c.sub1, c.sub2, o.offer_id, aff.user_id
            ORDER BY clicks DESC
            LIMIT 150
        ");
        $stmtSub->execute([$managerId]);
        $subIdReports = $stmtSub->fetchAll(PDO::FETCH_ASSOC);
    }

    // 5. Fetch Advertiser Reports
    if ($canAdvReports) {
        $stmtAdv = $pdo->prepare("
            SELECT 
                IFNULL(adv.user_id, 0) AS advertiser_id,
                IFNULL(adv.name, 'Direct / Network') AS advertiser_name,
                IFNULL(adv.company, 'In-House') AS advertiser_company,
                COUNT(DISTINCT o.offer_id) AS total_offers,
                COUNT(DISTINCT c.click_id) AS clicks,
                SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS conversions,
                IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS total_revenue,
                IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_payout
            FROM users aff
            INNER JOIN clicks c ON c.affiliate_id = aff.user_id
            INNER JOIN offers o ON o.offer_id = c.offer_id
            LEFT JOIN users adv ON adv.user_id = o.advertiser_id
            LEFT JOIN conversions cv ON cv.click_id = c.click_id
            WHERE aff.role_id = 3 AND aff.account_manager_id = ?
            GROUP BY adv.user_id
            ORDER BY total_revenue DESC
        ");
        $stmtAdv->execute([$managerId]);
        $advertiserReports = $stmtAdv->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Performance Reports | Manager Portal</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <style>
        .card-custom { border-radius: 12px; border: none; box-shadow: 0 4px 18px rgba(0,0,0,0.06); background: #ffffff; }
        .nav-pills .nav-link.active { background-color: #007bff; border-radius: 8px; font-weight: 700; }
        .nav-pills .nav-link { color: #495057; font-weight: 600; padding: 10px 18px; margin-right: 6px; }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="reports.php" class="nav-link active">Performance Reports</a></li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item"><a class="nav-link text-danger font-weight-bold" href="../logout.php"><i class="fas fa-sign-out-alt mr-1"></i> Logout</a></li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.4rem;">
                <i class="fas fa-user-tie mr-2"></i><strong>Manager</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item"><a href="dashboard.php" class="nav-link"><i class="nav-icon fas fa-chart-line"></i><p>Dashboard</p></a></li>
                    <li class="nav-item"><a href="publishers.php" class="nav-link"><i class="nav-icon fas fa-user-friends"></i><p>My Publishers</p></a></li>
                    <li class="nav-item"><a href="campaigns.php" class="nav-link"><i class="nav-icon fas fa-bullhorn"></i><p>Campaigns</p></a></li>
                    <li class="nav-item"><a href="campaign_access.php" class="nav-link"><i class="nav-icon fas fa-key"></i><p>Offer Approvals</p></a></li>
                    <li class="nav-item"><a href="reports.php" class="nav-link active"><i class="nav-icon fas fa-chart-bar"></i><p>Performance Reports</p></a></li>
                    <li class="nav-item"><a href="reports_postbacks.php" class="nav-link"><i class="nav-icon fas fa-exchange-alt"></i><p>Postback Logs</p></a></li>
                    <?php if (manager_can('link_tester')): ?>
                    <li class="nav-item"><a href="link_tester.php" class="nav-link"><i class="nav-icon fas fa-vial"></i><p>Link Tester</p></a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a href="profile.php" class="nav-link"><i class="nav-icon fas fa-user-cog"></i><p>My Profile</p></a></li>
                </ul>
            </nav>
        </div>
    </aside>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6"><h1 class="m-0 font-weight-bold">Performance Analytics & Reports</h1></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Performance Reports</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <?php if (!$hasAnyReportPermission): ?>
                <div class="card card-custom p-5 text-center">
                    <div class="my-4">
                        <i class="fas fa-lock fa-3x text-muted mb-3"></i>
                        <h4 class="font-weight-bold text-dark">Reporting Access Restricted</h4>
                        <p class="text-muted">You do not currently have administrative authorization to access performance analytics.<br>Please reach out to your administrator to enable report permissions for your account.</p>
                        <a href="dashboard.php" class="btn btn-primary font-weight-bold mt-2"><i class="fas fa-arrow-left mr-1"></i> Return to Dashboard</a>
                    </div>
                </div>
                <?php else: ?>

                <!-- Report Type Navigation Tabs -->
                <ul class="nav nav-pills mb-3" id="reportTabs">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'campaigns' ? 'active' : ''; ?>" href="?tab=campaigns">
                            <i class="fas fa-bullhorn mr-1"></i> Campaign Performance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'affiliates' ? 'active' : ''; ?>" href="?tab=affiliates">
                            <i class="fas fa-users mr-1"></i> Publishers Report
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'clicks' ? 'active' : ''; ?>" href="?tab=clicks">
                            <i class="fas fa-mouse-pointer mr-1"></i> Click Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'subid' ? 'active' : ''; ?>" href="?tab=subid">
                            <i class="fas fa-tags mr-1"></i> SubID Breakdown
                        </a>
                    </li>
                    <?php if ($canAdvReports): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'advertisers' ? 'active' : ''; ?>" href="?tab=advertisers">
                            <i class="fas fa-briefcase mr-1"></i> Advertisers Report
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>

                <!-- Tab 1: Campaign Performance -->
                <?php if ($activeTab === 'campaigns'): ?>
                <div class="card card-custom p-4 mb-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-bullhorn mr-2"></i>Campaign Performance Report</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle reportTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Offer ID</th>
                                    <th>Campaign Name</th>
                                    <th>Category</th>
                                    <th class="text-center">Clicks</th>
                                    <th class="text-center">Conversions</th>
                                    <th class="text-center">CR %</th>
                                    <th class="text-center">Total Revenue</th>
                                    <th class="text-center">Total Payout</th>
                                    <th class="text-center">Net Margin</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($campaignReports as $camp): 
                                    $cr = !empty($camp['clicks']) ? round(($camp['conversions'] / $camp['clicks']) * 100, 2) : 0;
                                    $campNet = (float)$camp['total_revenue'] - (float)$camp['total_payout'];
                                ?>
                                <tr>
                                    <td><strong>#<?php echo (int)$camp['offer_id']; ?></strong></td>
                                    <td><strong class="text-dark"><?php echo htmlspecialchars($camp['offer_name']); ?></strong></td>
                                    <td><span class="badge badge-light border"><?php echo htmlspecialchars($camp['category'] ?: 'General'); ?></span></td>
                                    <td class="text-center font-weight-bold"><?php echo number_format((int)$camp['clicks']); ?></td>
                                    <td class="text-center font-weight-bold text-success"><?php echo number_format((int)$camp['conversions']); ?></td>
                                    <td class="text-center font-weight-bold text-muted"><?php echo $cr; ?>%</td>
                                    <?php if ($canViewFinancials): ?>
                                    <td class="text-center font-weight-bold text-success"><?php echo format_currency((float)$camp['total_revenue'], $camp['currency'] ?? 'USD'); ?></td>
                                    <td class="text-center font-weight-bold text-primary"><?php echo format_currency((float)$camp['total_payout'], $camp['currency'] ?? 'USD'); ?></td>
                                    <td class="text-center font-weight-bold text-<?php echo $campNet >= 0 ? 'success' : 'danger'; ?>">
                                        <?php echo format_currency($campNet, $camp['currency'] ?? 'USD'); ?>
                                    </td>
                                    <?php else: ?>
                                    <td class="text-center text-muted"><span class="badge badge-light border"><i class="fas fa-lock mr-1"></i>Hidden</span></td>
                                    <td class="text-center text-muted"><span class="badge badge-light border"><i class="fas fa-lock mr-1"></i>Hidden</span></td>
                                    <td class="text-center text-muted"><span class="badge badge-light border"><i class="fas fa-lock mr-1"></i>Hidden</span></td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab 2: Affiliate Reports -->
                <?php if ($activeTab === 'affiliates'): ?>
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-chart-line mr-2"></i>Assigned Publishers Breakdown</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle reportTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Offer</th>
                                    <th>Affiliate / Publisher</th>
                                    <th class="text-center">Clicks</th>
                                    <th class="text-center">Conversions</th>
                                    <th class="text-center">Advertiser Price</th>
                                    <th class="text-center">Affiliate Payout</th>
                                    <th class="text-center">Margin / Profit</th>
                                    <th>Currency</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($affiliateReports as $r): 
                                    $currency = !empty($r['currency']) ? strtoupper($r['currency']) : 'USD';
                                    $statusLabel = ucfirst($r['affiliate_status'] ?? 'Approved');
                                    $margin = (float)$r['total_revenue'] - (float)$r['total_payout'];
                                ?>
                                <tr>
                                    <td>
                                        <a href="campaigns.php" class="text-primary font-weight-bold">
                                            #<?php echo (int)$r['offer_id']; ?> ~ <?php echo htmlspecialchars($r['offer_name']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <strong class="text-dark">#<?php echo (int)$r['affiliate_id']; ?> ~ <?php echo htmlspecialchars($r['affiliate_name']); ?></strong>
                                        <?php if (!empty($r['affiliate_company'])): ?>
                                            <span class="text-muted small d-block"><?php echo htmlspecialchars($r['affiliate_company']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center font-weight-bold text-dark"><?php echo number_format((int)$r['clicks']); ?></td>
                                    <td class="text-center font-weight-bold text-dark"><?php echo number_format((int)$r['approved_conversions']); ?></td>
                                    <?php if ($canViewFinancials): ?>
                                    <td class="text-center font-weight-bold text-success"><?php echo format_currency((float)$r['total_revenue'], $currency); ?></td>
                                    <td class="text-center font-weight-bold text-primary"><?php echo format_currency((float)$r['total_payout'], $currency); ?></td>
                                    <td class="text-center font-weight-bold text-<?php echo $margin >= 0 ? 'success' : 'danger'; ?>">
                                        <?php echo format_currency($margin, $currency); ?>
                                    </td>
                                    <?php else: ?>
                                    <td class="text-center text-muted"><span class="badge badge-light border"><i class="fas fa-lock mr-1"></i>Hidden</span></td>
                                    <td class="text-center text-muted"><span class="badge badge-light border"><i class="fas fa-lock mr-1"></i>Hidden</span></td>
                                    <td class="text-center text-muted"><span class="badge badge-light border"><i class="fas fa-lock mr-1"></i>Hidden</span></td>
                                    <?php endif; ?>
                                    <td><span class="badge badge-light border font-weight-bold"><?php echo htmlspecialchars($currency); ?></span></td>
                                    <td><span class="badge badge-success p-2"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab 3: Click Reports -->
                <?php if ($activeTab === 'clicks'): ?>
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-mouse-pointer mr-2"></i>Live Click Reports (Recent Traffic)</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle reportTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Click ID / Time</th>
                                    <th>Publisher</th>
                                    <th>Campaign Offer</th>
                                    <th>Sub IDs</th>
                                    <th>IP / Country</th>
                                    <th>Device</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clickReports as $cRow): ?>
                                <tr>
                                    <td>
                                        <span class="badge badge-secondary px-2 py-1 font-monospace" style="font-size: 11px;"><?php echo htmlspecialchars(substr($cRow['click_id'], 0, 16)); ?>...</span>
                                        <small class="d-block text-muted mt-1"><?php echo date('M d, H:i:s', strtotime($cRow['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <strong class="text-dark">#<?php echo $cRow['affiliate_id']; ?> - <?php echo htmlspecialchars($cRow['affiliate_name']); ?></strong>
                                    </td>
                                    <td>
                                        <strong class="text-primary">#<?php echo $cRow['offer_id']; ?> - <?php echo htmlspecialchars($cRow['offer_name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if (!empty($cRow['sub1'])): ?><span class="badge badge-light border mr-1">s1: <?php echo htmlspecialchars($cRow['sub1']); ?></span><?php endif; ?>
                                        <?php if (!empty($cRow['sub2'])): ?><span class="badge badge-light border mr-1">s2: <?php echo htmlspecialchars($cRow['sub2']); ?></span><?php endif; ?>
                                        <?php if (!empty($cRow['sub3'])): ?><span class="badge badge-light border">s3: <?php echo htmlspecialchars($cRow['sub3']); ?></span><?php endif; ?>
                                        <?php if (empty($cRow['sub1']) && empty($cRow['sub2']) && empty($cRow['sub3'])): ?><span class="text-muted small">-</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-info mr-1"><?php echo htmlspecialchars($cRow['country'] ?: 'Global'); ?></span>
                                        <small class="text-muted"><?php echo htmlspecialchars($cRow['ip_address'] ?: '-'); ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo htmlspecialchars($cRow['device'] ?: 'All'); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($cRow['conversion_status'] === 'approved'): ?>
                                            <span class="badge badge-success p-2"><i class="fas fa-check-circle mr-1"></i>Approved (<?php echo format_currency((float)$cRow['conversion_payout'], $cRow['currency'] ?? 'USD'); ?>)</span>
                                        <?php elseif ($cRow['conversion_status'] === 'pending'): ?>
                                            <span class="badge badge-warning p-2"><i class="fas fa-clock mr-1"></i>Pending</span>
                                        <?php else: ?>
                                            <span class="badge badge-light border text-muted">Clicked</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab 4: SubID Breakdown Report -->
                <?php if ($activeTab === 'subid'): ?>
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-tags mr-2"></i>SubID (sub1 - sub5) Analytics Report</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle reportTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Sub ID 1</th>
                                    <th>Sub ID 2</th>
                                    <th>Campaign Offer</th>
                                    <th>Publisher</th>
                                    <th class="text-center">Clicks</th>
                                    <th class="text-center">Conversions</th>
                                    <th class="text-center">CR %</th>
                                    <th class="text-center">Total Payout</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subIdReports as $sub): 
                                    $cr = !empty($sub['clicks']) ? round(($sub['conversions'] / $sub['clicks']) * 100, 2) : 0;
                                ?>
                                <tr>
                                    <td><span class="badge badge-secondary px-2 py-1" style="font-family: monospace; font-size: 13px;"><?php echo htmlspecialchars($sub['sub1'] ?: '-'); ?></span></td>
                                    <td><span class="badge badge-light border px-2 py-1" style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($sub['sub2'] ?: '-'); ?></span></td>
                                    <td>#<?php echo (int)$sub['offer_id']; ?> ~ <?php echo htmlspecialchars($sub['offer_name']); ?></td>
                                    <td><strong class="text-dark"><?php echo htmlspecialchars($sub['affiliate_name']); ?></strong></td>
                                    <td class="text-center font-weight-bold"><?php echo number_format((int)$sub['clicks']); ?></td>
                                    <td class="text-center font-weight-bold text-success"><?php echo number_format((int)$sub['conversions']); ?></td>
                                    <td class="text-center font-weight-bold text-muted"><?php echo $cr; ?>%</td>
                                    <td class="text-center font-weight-bold text-primary"><?php echo format_currency((float)$sub['total_payout'], $sub['currency'] ?? 'USD'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab 5: Advertiser Reports -->
                <?php if ($activeTab === 'advertisers' && $canAdvReports): ?>
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-briefcase mr-2"></i>Advertiser Performance Report</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle reportTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Advertiser ID</th>
                                    <th>Advertiser / Company</th>
                                    <th class="text-center">Live Campaigns</th>
                                    <th class="text-center">Gross Clicks</th>
                                    <th class="text-center">Conversions</th>
                                    <th class="text-center">Advertiser Revenue</th>
                                    <th class="text-center">Publisher Payout</th>
                                    <th class="text-center">Net Margin</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($advertiserReports as $adv): 
                                    $net = (float)$adv['total_revenue'] - (float)$adv['total_payout'];
                                ?>
                                <tr>
                                    <td><strong>#<?php echo (int)$adv['advertiser_id']; ?></strong></td>
                                    <td>
                                        <strong class="text-dark"><?php echo htmlspecialchars($adv['advertiser_name']); ?></strong>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($adv['advertiser_company']); ?></small>
                                    </td>
                                    <td class="text-center"><span class="badge badge-info p-2"><?php echo number_format((int)$adv['total_offers']); ?> Offers</span></td>
                                    <td class="text-center font-weight-bold"><?php echo number_format((int)$adv['clicks']); ?></td>
                                    <td class="text-center font-weight-bold text-success"><?php echo number_format((int)$adv['conversions']); ?></td>
                                    <?php if ($canViewFinancials): ?>
                                    <td class="text-center font-weight-bold text-success">$<?php echo number_format((float)$adv['total_revenue'], 2); ?></td>
                                    <td class="text-center font-weight-bold text-primary">$<?php echo number_format((float)$adv['total_payout'], 2); ?></td>
                                    <td class="text-center font-weight-bold text-<?php echo $net >= 0 ? 'success' : 'danger'; ?>">
                                        $<?php echo number_format($net, 2); ?>
                                    </td>
                                    <?php else: ?>
                                    <td class="text-center text-muted"><span class="badge badge-light border"><i class="fas fa-lock mr-1"></i>Hidden</span></td>
                                    <td class="text-center text-muted"><span class="badge badge-light border"><i class="fas fa-lock mr-1"></i>Hidden</span></td>
                                    <td class="text-center text-muted"><span class="badge badge-light border"><i class="fas fa-lock mr-1"></i>Hidden</span></td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php endif; ?>

            </div>
        </div>
    </div>

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Manager Portal v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">Offer on Media</a>.</strong> All rights reserved.
    </footer>
</div>

<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script>
$(document).ready(function() {
    $('.reportTable').DataTable({ pageLength: 10, responsive: true });
});
</script>
</body>
</html>
