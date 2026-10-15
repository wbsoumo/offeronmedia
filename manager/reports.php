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
$canAffReports = manager_can('affiliate_reports');
$canAdvReports = manager_can('advertiser_reports');
$canAllReports = manager_can('all_reports');
$hasAnyReportPermission = $canAffReports || $canAdvReports || $canAllReports;

// Determine active tab
$defaultTab = $canAffReports ? 'affiliates' : ($canAdvReports ? 'advertisers' : ($canAllReports ? 'all' : 'none'));
$activeTab  = $_GET['tab'] ?? $defaultTab;

// Validate active tab with permissions
if ($activeTab === 'affiliates' && !$canAffReports) $activeTab = $defaultTab;
if ($activeTab === 'advertisers' && !$canAdvReports) $activeTab = $defaultTab;
if ($activeTab === 'all' && !$canAllReports) $activeTab = $defaultTab;

$affiliateReports = [];
$advertiserReports = [];
$campaignReports   = [];
$subIdReports      = [];

if ($hasAnyReportPermission) {
    // 1. Fetch Affiliate Reports
    if ($canAffReports) {
        $stmt = $pdo->prepare("
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
            ORDER BY total_payout DESC
        ");
        $stmt->execute([$managerId]);
        $affiliateReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 2. Fetch Advertiser Reports
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

    // 3. Fetch All Reports (Campaigns & SubIDs)
    if ($canAllReports) {
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
            ORDER BY total_revenue DESC
        ");
        $stmtCamp->execute([$managerId]);
        $campaignReports = $stmtCamp->fetchAll(PDO::FETCH_ASSOC);

        $stmtSub = $pdo->prepare("
            SELECT 
                c.sub1,
                o.offer_id,
                o.offer_name,
                aff.name AS affiliate_name,
                COUNT(DISTINCT c.click_id) AS clicks,
                SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS conversions,
                IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_payout
            FROM users aff
            INNER JOIN clicks c ON c.affiliate_id = aff.user_id
            INNER JOIN offers o ON o.offer_id = c.offer_id
            LEFT JOIN conversions cv ON cv.click_id = c.click_id
            WHERE aff.role_id = 3 AND aff.account_manager_id = ? AND c.sub1 IS NOT NULL AND c.sub1 != ''
            GROUP BY c.sub1, o.offer_id, aff.user_id
            ORDER BY conversions DESC
            LIMIT 100
        ");
        $stmtSub->execute([$managerId]);
        $subIdReports = $stmtSub->fetchAll(PDO::FETCH_ASSOC);
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
                    <?php if ($hasAnyReportPermission): ?>
                    <li class="nav-item"><a href="reports.php" class="nav-link active"><i class="nav-icon fas fa-chart-bar"></i><p>Performance Reports</p></a></li>
                    <?php endif; ?>
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
                    <div class="col-sm-6"><h1 class="m-0 font-weight-bold">Performance Analytics Report</h1></div>
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
                    <?php if ($canAffReports): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'affiliates' ? 'active' : ''; ?>" href="?tab=affiliates">
                            <i class="fas fa-users mr-1"></i> Affiliate Reports
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if ($canAdvReports): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'advertisers' ? 'active' : ''; ?>" href="?tab=advertisers">
                            <i class="fas fa-briefcase mr-1"></i> Advertisers Report
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if ($canAllReports): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'all' ? 'active' : ''; ?>" href="?tab=all">
                            <i class="fas fa-layer-group mr-1"></i> All Reports (Campaigns & SubIDs)
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>

                <!-- Tab 1: Affiliate Reports -->
                <?php if ($activeTab === 'affiliates' && $canAffReports): ?>
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
                                    <td class="text-center font-weight-bold text-success">$<?php echo number_format((float)$r['total_revenue'], 2); ?></td>
                                    <td class="text-center font-weight-bold text-primary">$<?php echo number_format((float)$r['total_payout'], 2); ?></td>
                                    <td class="text-center font-weight-bold text-<?php echo $margin >= 0 ? 'success' : 'danger'; ?>">
                                        $<?php echo number_format($margin, 2); ?>
                                    </td>
                                    <td><span class="badge badge-light border font-weight-bold"><?php echo htmlspecialchars($currency); ?></span></td>
                                    <td><span class="badge badge-success p-2"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab 2: Advertiser Reports -->
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
                                    <td class="text-center font-weight-bold text-success">$<?php echo number_format((float)$adv['total_revenue'], 2); ?></td>
                                    <td class="text-center font-weight-bold text-primary">$<?php echo number_format((float)$adv['total_payout'], 2); ?></td>
                                    <td class="text-center font-weight-bold text-<?php echo $net >= 0 ? 'success' : 'danger'; ?>">
                                        $<?php echo number_format($net, 2); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab 3: All Reports (Campaign Summary & SubID Breakdown) -->
                <?php if ($activeTab === 'all' && $canAllReports): ?>
                <div class="card card-custom p-4 mb-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-bullhorn mr-2"></i>Comprehensive Campaign Report</h4>
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
                                    <td class="text-center font-weight-bold text-success">$<?php echo number_format((float)$camp['total_revenue'], 2); ?></td>
                                    <td class="text-center font-weight-bold text-primary">$<?php echo number_format((float)$camp['total_payout'], 2); ?></td>
                                    <td class="text-center font-weight-bold text-<?php echo $campNet >= 0 ? 'success' : 'danger'; ?>">
                                        $<?php echo number_format($campNet, 2); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-tags mr-2"></i>Sub-Affiliate (SubID) Analytics Report</h4>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle reportTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>SubID (sub1 / sub_aff_id)</th>
                                    <th>Campaign Offer</th>
                                    <th>Publisher</th>
                                    <th class="text-center">Clicks</th>
                                    <th class="text-center">Conversions</th>
                                    <th class="text-center">Payout</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subIdReports as $sub): ?>
                                <tr>
                                    <td><span class="badge badge-secondary px-2 py-1" style="font-family: monospace; font-size: 13px;"><?php echo htmlspecialchars($sub['sub1']); ?></span></td>
                                    <td>#<?php echo (int)$sub['offer_id']; ?> ~ <?php echo htmlspecialchars($sub['offer_name']); ?></td>
                                    <td><strong class="text-dark"><?php echo htmlspecialchars($sub['affiliate_name']); ?></strong></td>
                                    <td class="text-center font-weight-bold"><?php echo number_format((int)$sub['clicks']); ?></td>
                                    <td class="text-center font-weight-bold text-success"><?php echo number_format((int)$sub['conversions']); ?></td>
                                    <td class="text-center font-weight-bold text-primary">$<?php echo number_format((float)$sub['total_payout'], 2); ?></td>
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
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Offer on Media</a>.</strong> All rights reserved.
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
