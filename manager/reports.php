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

// Filter Parameters
$dateFrom = trim($_GET['from'] ?? date('Y-m-01'));
$dateTo   = trim($_GET['to'] ?? date('Y-m-d'));
$search   = trim($_GET['search'] ?? '');
$filterOffer = isset($_GET['offer_id']) && $_GET['offer_id'] !== 'all' ? (int)$_GET['offer_id'] : 'all';
$filterAffiliate = isset($_GET['affiliate_id']) && $_GET['affiliate_id'] !== 'all' ? (int)$_GET['affiliate_id'] : 'all';
$filterStatus = trim($_GET['status'] ?? 'all');
$filterDevice = trim($_GET['device'] ?? 'all');
$filterCategory = trim($_GET['category'] ?? 'all');
$filterAdvertiser = isset($_GET['advertiser_id']) && $_GET['advertiser_id'] !== 'all' ? (int)$_GET['advertiser_id'] : 'all';
$subFilter1 = trim($_GET['sub1'] ?? '');

// Helper to build URLs preserving or modifying filter params
function build_filter_url($overrides = []) {
    $current = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === '' || $v === null) {
            unset($current[$k]);
        } else {
            $current[$k] = $v;
        }
    }
    return '?' . http_build_query($current);
}

// Fetch Dropdown options for filters
$offersList = $pdo->query("SELECT offer_id, offer_name FROM offers ORDER BY offer_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$affScopeSql = !$canAllReports ? "WHERE role_id = 3 AND account_manager_id = " . (int)$managerId : "WHERE role_id = 3";
$affiliatesList = $pdo->query("SELECT user_id, name, company FROM users $affScopeSql ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$categoriesList = $pdo->query("SELECT DISTINCT category FROM offers WHERE category IS NOT NULL AND category != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

$advertisersList = $pdo->query("SELECT user_id, name, company FROM users WHERE role_id = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$affiliateReports = [];
$advertiserReports = [];
$campaignReports   = [];
$subIdReports      = [];
$clickReports      = [];

if ($hasAnyReportPermission) {
    // 1. Fetch Campaign Performance Reports
    $campWhere = ["aff.role_id = 3"];
    $campParams = [];
    if (!$canAllReports) {
        $campWhere[] = "aff.account_manager_id = :mid";
        $campParams[':mid'] = $managerId;
    }
    if ($dateFrom) {
        $campWhere[] = "DATE(c.created_at) >= :dfrom";
        $campParams[':dfrom'] = $dateFrom;
    }
    if ($dateTo) {
        $campWhere[] = "DATE(c.created_at) <= :dto";
        $campParams[':dto'] = $dateTo;
    }
    if ($filterOffer !== 'all') {
        $campWhere[] = "o.offer_id = :oid";
        $campParams[':oid'] = $filterOffer;
    }
    if ($filterAffiliate !== 'all') {
        $campWhere[] = "aff.user_id = :affid";
        $campParams[':affid'] = $filterAffiliate;
    }
    if ($filterCategory !== 'all') {
        $campWhere[] = "o.category = :cat";
        $campParams[':cat'] = $filterCategory;
    }
    if ($search !== '') {
        $campWhere[] = "(o.offer_name LIKE :s1 OR o.offer_id = :s_id)";
        $campParams[':s1'] = "%$search%";
        $campParams[':s_id'] = is_numeric($search) ? (int)$search : 0;
    }
    $campWhereSql = 'WHERE ' . implode(' AND ', $campWhere);

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
        FROM clicks c
        INNER JOIN users aff ON aff.user_id = c.affiliate_id
        INNER JOIN offers o ON o.offer_id = c.offer_id
        LEFT JOIN conversions cv ON cv.click_id = c.click_id
        $campWhereSql
        GROUP BY o.offer_id
        ORDER BY clicks DESC
    ");
    $stmtCamp->execute($campParams);
    $campaignReports = $stmtCamp->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch Affiliate / Publisher Reports
    $affWhere = ["u.role_id = 3"];
    $affParams = [];
    if (!$canAllReports) {
        $affWhere[] = "u.account_manager_id = :mid";
        $affParams[':mid'] = $managerId;
    }
    if ($dateFrom) {
        $affWhere[] = "DATE(c.created_at) >= :dfrom";
        $affParams[':dfrom'] = $dateFrom;
    }
    if ($dateTo) {
        $affWhere[] = "DATE(c.created_at) <= :dto";
        $affParams[':dto'] = $dateTo;
    }
    if ($filterOffer !== 'all') {
        $affWhere[] = "o.offer_id = :oid";
        $affParams[':oid'] = $filterOffer;
    }
    if ($filterAffiliate !== 'all') {
        $affWhere[] = "u.user_id = :affid";
        $affParams[':affid'] = $filterAffiliate;
    }
    if ($filterStatus !== 'all') {
        $affWhere[] = "u.status = :aff_status";
        $affParams[':aff_status'] = $filterStatus;
    }
    if ($search !== '') {
        $affWhere[] = "(u.name LIKE :s1 OR u.email LIKE :s2 OR u.company LIKE :s3 OR o.offer_name LIKE :s4)";
        $affParams[':s1'] = "%$search%";
        $affParams[':s2'] = "%$search%";
        $affParams[':s3'] = "%$search%";
        $affParams[':s4'] = "%$search%";
    }
    $affWhereSql = 'WHERE ' . implode(' AND ', $affWhere);

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
        $affWhereSql
        GROUP BY o.offer_id, u.user_id
        ORDER BY clicks DESC
    ");
    $stmtAff->execute($affParams);
    $affiliateReports = $stmtAff->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch Click Reports (Raw Click Logs scoped to manager)
    if ($activeTab === 'clicks' || $canClickReports) {
        $clkWhere = ["u.role_id = 3"];
        $clkParams = [];
        if (!$canAllReports) {
            $clkWhere[] = "u.account_manager_id = :mid";
            $clkParams[':mid'] = $managerId;
        }
        if ($dateFrom) {
            $clkWhere[] = "DATE(c.created_at) >= :dfrom";
            $clkParams[':dfrom'] = $dateFrom;
        }
        if ($dateTo) {
            $clkWhere[] = "DATE(c.created_at) <= :dto";
            $clkParams[':dto'] = $dateTo;
        }
        if ($filterOffer !== 'all') {
            $clkWhere[] = "o.offer_id = :oid";
            $clkParams[':oid'] = $filterOffer;
        }
        if ($filterAffiliate !== 'all') {
            $clkWhere[] = "u.user_id = :affid";
            $clkParams[':affid'] = $filterAffiliate;
        }
        if ($filterStatus === 'approved') {
            $clkWhere[] = "cv.status = 'approved'";
        } elseif ($filterStatus === 'pending') {
            $clkWhere[] = "cv.status = 'pending'";
        } elseif ($filterStatus === 'clicked') {
            $clkWhere[] = "(cv.status IS NULL OR cv.status = '')";
        }
        if ($filterDevice !== 'all') {
            $clkWhere[] = "c.device LIKE :device";
            $clkParams[':device'] = "%$filterDevice%";
        }
        if ($search !== '') {
            $clkWhere[] = "(c.click_id LIKE :s1 OR c.ip_address LIKE :s2 OR c.sub1 LIKE :s3 OR o.offer_name LIKE :s4 OR u.name LIKE :s5)";
            $clkParams[':s1'] = "%$search%";
            $clkParams[':s2'] = "%$search%";
            $clkParams[':s3'] = "%$search%";
            $clkParams[':s4'] = "%$search%";
            $clkParams[':s5'] = "%$search%";
        }
        $clkWhereSql = 'WHERE ' . implode(' AND ', $clkWhere);

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
            $clkWhereSql
            ORDER BY c.created_at DESC
            LIMIT 300
        ");
        $clicksQuery->execute($clkParams);
        $clickReports = $clicksQuery->fetchAll(PDO::FETCH_ASSOC);
    }

    // 4. Fetch SubID Reports (Breakdown of Sub1 through Sub5)
    if ($activeTab === 'subid' || $canAllReports) {
        $subWhere = ["aff.role_id = 3"];
        $subParams = [];
        if (!$canAllReports) {
            $subWhere[] = "aff.account_manager_id = :mid";
            $subParams[':mid'] = $managerId;
        }
        if ($dateFrom) {
            $subWhere[] = "DATE(c.created_at) >= :dfrom";
            $subParams[':dfrom'] = $dateFrom;
        }
        if ($dateTo) {
            $subWhere[] = "DATE(c.created_at) <= :dto";
            $subParams[':dto'] = $dateTo;
        }
        if ($filterOffer !== 'all') {
            $subWhere[] = "o.offer_id = :oid";
            $subParams[':oid'] = $filterOffer;
        }
        if ($filterAffiliate !== 'all') {
            $subWhere[] = "aff.user_id = :affid";
            $subParams[':affid'] = $filterAffiliate;
        }
        if ($subFilter1 !== '') {
            $subWhere[] = "c.sub1 LIKE :sub1_val";
            $subParams[':sub1_val'] = "%$subFilter1%";
        }
        if ($search !== '') {
            $subWhere[] = "(c.sub1 LIKE :s1 OR c.sub2 LIKE :s2 OR c.sub3 LIKE :s3 OR c.sub4 LIKE :s4 OR c.sub5 LIKE :s5 OR o.offer_name LIKE :s6 OR aff.name LIKE :s7)";
            $subParams[':s1'] = "%$search%";
            $subParams[':s2'] = "%$search%";
            $subParams[':s3'] = "%$search%";
            $subParams[':s4'] = "%$search%";
            $subParams[':s5'] = "%$search%";
            $subParams[':s6'] = "%$search%";
            $subParams[':s7'] = "%$search%";
        }
        $subWhereSql = 'WHERE ' . implode(' AND ', $subWhere);

        $stmtSub = $pdo->prepare("
            SELECT 
                c.sub1, c.sub2, c.sub3, c.sub4, c.sub5,
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
            $subWhereSql
            GROUP BY c.sub1, c.sub2, c.sub3, c.sub4, c.sub5, o.offer_id, aff.user_id
            ORDER BY clicks DESC
            LIMIT 300
        ");
        $stmtSub->execute($subParams);
        $subIdReports = $stmtSub->fetchAll(PDO::FETCH_ASSOC);
    }

    // 5. Fetch Advertiser Reports
    if ($canAdvReports) {
        $advWhere = ["aff.role_id = 3"];
        $advParams = [];
        if (!$canAllReports) {
            $advWhere[] = "aff.account_manager_id = :mid";
            $advParams[':mid'] = $managerId;
        }
        if ($dateFrom) {
            $advWhere[] = "DATE(c.created_at) >= :dfrom";
            $advParams[':dfrom'] = $dateFrom;
        }
        if ($dateTo) {
            $advWhere[] = "DATE(c.created_at) <= :dto";
            $advParams[':dto'] = $dateTo;
        }
        if ($filterAdvertiser !== 'all') {
            $advWhere[] = "adv.user_id = :advid";
            $advParams[':advid'] = $filterAdvertiser;
        }
        if ($search !== '') {
            $advWhere[] = "(adv.name LIKE :s1 OR adv.company LIKE :s2 OR o.offer_name LIKE :s3)";
            $advParams[':s1'] = "%$search%";
            $advParams[':s2'] = "%$search%";
            $advParams[':s3'] = "%$search%";
        }
        $advWhereSql = 'WHERE ' . implode(' AND ', $advWhere);

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
            $advWhereSql
            GROUP BY adv.user_id
            ORDER BY total_revenue DESC
        ");
        $stmtAdv->execute($advParams);
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
    <?php include __DIR__ . '/sidebar.php'; ?>

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
                        <a class="nav-link <?php echo $activeTab === 'campaigns' ? 'active font-weight-bold' : ''; ?>" href="<?php echo build_filter_url(['tab' => 'campaigns']); ?>">
                            <i class="fas fa-bullhorn mr-1"></i> Campaign Performance
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'affiliates' ? 'active font-weight-bold' : ''; ?>" href="<?php echo build_filter_url(['tab' => 'affiliates']); ?>">
                            <i class="fas fa-users mr-1"></i> Publishers Report
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'clicks' ? 'active font-weight-bold' : ''; ?>" href="<?php echo build_filter_url(['tab' => 'clicks']); ?>">
                            <i class="fas fa-mouse-pointer mr-1"></i> Click Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'subid' ? 'active font-weight-bold' : ''; ?>" href="<?php echo build_filter_url(['tab' => 'subid']); ?>">
                            <i class="fas fa-tags mr-1"></i> SubID Breakdown
                        </a>
                    </li>
                    <?php if ($canAdvReports): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'advertisers' ? 'active font-weight-bold' : ''; ?>" href="<?php echo build_filter_url(['tab' => 'advertisers']); ?>">
                            <i class="fas fa-briefcase mr-1"></i> Advertisers Report
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="reports_postbacks.php">
                            <i class="fas fa-exchange-alt mr-1"></i> Postback Logs
                        </a>
                    </li>
                </ul>

                <!-- Comprehensive Filter Bar -->
                <div class="card card-custom p-3 mb-4">
                    <form method="get" id="reportFilterForm">
                        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">

                        <!-- Quick Date Presets -->
                        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 pb-2 border-bottom">
                            <div class="btn-group btn-group-sm mb-2 mb-md-0" role="group">
                                <a href="<?php echo build_filter_url(['from' => date('Y-m-d'), 'to' => date('Y-m-d')]); ?>" class="btn btn-outline-secondary <?php echo ($dateFrom === date('Y-m-d') && $dateTo === date('Y-m-d')) ? 'active font-weight-bold' : ''; ?>">Today</a>
                                <a href="<?php echo build_filter_url(['from' => date('Y-m-d', strtotime('-1 day')), 'to' => date('Y-m-d', strtotime('-1 day'))]); ?>" class="btn btn-outline-secondary <?php echo ($dateFrom === date('Y-m-d', strtotime('-1 day')) && $dateTo === date('Y-m-d', strtotime('-1 day'))) ? 'active font-weight-bold' : ''; ?>">Yesterday</a>
                                <a href="<?php echo build_filter_url(['from' => date('Y-m-d', strtotime('-7 days')), 'to' => date('Y-m-d')]); ?>" class="btn btn-outline-secondary <?php echo ($dateFrom === date('Y-m-d', strtotime('-7 days')) && $dateTo === date('Y-m-d')) ? 'active font-weight-bold' : ''; ?>">Last 7 Days</a>
                                <a href="<?php echo build_filter_url(['from' => date('Y-m-01'), 'to' => date('Y-m-d')]); ?>" class="btn btn-outline-secondary <?php echo ($dateFrom === date('Y-m-01') && $dateTo === date('Y-m-d')) ? 'active font-weight-bold' : ''; ?>">This Month</a>
                                <a href="<?php echo build_filter_url(['from' => date('Y-m-01', strtotime('first day of last month')), 'to' => date('Y-m-t', strtotime('last month'))]); ?>" class="btn btn-outline-secondary">Last Month</a>
                                <a href="<?php echo build_filter_url(['from' => '', 'to' => '']); ?>" class="btn btn-outline-secondary <?php echo (empty($dateFrom) && empty($dateTo)) ? 'active font-weight-bold' : ''; ?>">All Time</a>
                            </div>
                            <div>
                                <span class="badge badge-light border text-muted px-2 py-1">
                                    <i class="fas fa-calendar-alt mr-1 text-primary"></i><?php echo $dateFrom ? date('M d, Y', strtotime($dateFrom)) : 'All Time'; ?> - <?php echo $dateTo ? date('M d, Y', strtotime($dateTo)) : 'Now'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Filter Fields Row -->
                        <div class="row align-items-end">
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="small font-weight-bold text-muted text-uppercase">From Date</label>
                                <input type="date" name="from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom); ?>">
                            </div>
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="small font-weight-bold text-muted text-uppercase">To Date</label>
                                <input type="date" name="to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo); ?>">
                            </div>
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="small font-weight-bold text-muted text-uppercase">Campaign Offer</label>
                                <select name="offer_id" class="form-control form-control-sm">
                                    <option value="all">All Campaigns</option>
                                    <?php foreach ($offersList as $off): ?>
                                        <option value="<?php echo $off['offer_id']; ?>" <?php echo (string)$filterOffer === (string)$off['offer_id'] ? 'selected' : ''; ?>>
                                            #<?php echo $off['offer_id']; ?> - <?php echo htmlspecialchars($off['offer_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="small font-weight-bold text-muted text-uppercase">Publisher</label>
                                <select name="affiliate_id" class="form-control form-control-sm">
                                    <option value="all">All Publishers</option>
                                    <?php foreach ($affiliatesList as $aff): ?>
                                        <option value="<?php echo $aff['user_id']; ?>" <?php echo (string)$filterAffiliate === (string)$aff['user_id'] ? 'selected' : ''; ?>>
                                            #<?php echo $aff['user_id']; ?> - <?php echo htmlspecialchars($aff['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Contextual Filters by Tab -->
                            <?php if ($activeTab === 'clicks'): ?>
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="small font-weight-bold text-muted text-uppercase">Status</label>
                                <select name="status" class="form-control form-control-sm">
                                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Approved Leads</option>
                                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending Leads</option>
                                    <option value="clicked" <?php echo $filterStatus === 'clicked' ? 'selected' : ''; ?>>Clicked Only</option>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="small font-weight-bold text-muted text-uppercase">Device</label>
                                <select name="device" class="form-control form-control-sm">
                                    <option value="all" <?php echo $filterDevice === 'all' ? 'selected' : ''; ?>>All Devices</option>
                                    <option value="mobile" <?php echo $filterDevice === 'mobile' ? 'selected' : ''; ?>>Mobile</option>
                                    <option value="desktop" <?php echo $filterDevice === 'desktop' ? 'selected' : ''; ?>>Desktop</option>
                                    <option value="tablet" <?php echo $filterDevice === 'tablet' ? 'selected' : ''; ?>>Tablet</option>
                                </select>
                            </div>
                            <?php elseif ($activeTab === 'campaigns'): ?>
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="small font-weight-bold text-muted text-uppercase">Category</label>
                                <select name="category" class="form-control form-control-sm">
                                    <option value="all">All Categories</option>
                                    <?php foreach ($categoriesList as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filterCategory === $cat ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php elseif ($activeTab === 'affiliates'): ?>
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="small font-weight-bold text-muted text-uppercase">Account Status</label>
                                <select name="status" class="form-control form-control-sm">
                                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active Only</option>
                                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending Only</option>
                                    <option value="blocked" <?php echo $filterStatus === 'blocked' ? 'selected' : ''; ?>>Blocked</option>
                                </select>
                            </div>
                            <?php elseif ($activeTab === 'advertisers'): ?>
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="small font-weight-bold text-muted text-uppercase">Advertiser</label>
                                <select name="advertiser_id" class="form-control form-control-sm">
                                    <option value="all">All Advertisers</option>
                                    <?php foreach ($advertisersList as $adv): ?>
                                        <option value="<?php echo $adv['user_id']; ?>" <?php echo (string)$filterAdvertiser === (string)$adv['user_id'] ? 'selected' : ''; ?>>
                                            #<?php echo $adv['user_id']; ?> - <?php echo htmlspecialchars($adv['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="small font-weight-bold text-muted text-uppercase">Search Keyword</label>
                                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>

                            <div class="col-lg-2 col-md-3 col-12 mb-2 d-flex">
                                <button type="submit" class="btn btn-sm btn-primary btn-block font-weight-bold mr-1">
                                    <i class="fas fa-filter mr-1"></i> Apply Filter
                                </button>
                                <a href="?tab=<?php echo htmlspecialchars($activeTab); ?>" class="btn btn-sm btn-outline-secondary" title="Reset Filters">
                                    <i class="fas fa-undo"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

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
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-tags mr-2"></i>SubID (sub1 - sub5) Analytics Report</h4>
                        <a href="reports_subid.php" class="btn btn-sm btn-outline-warning font-weight-bold">
                            <i class="fas fa-external-link-alt mr-1"></i> Open Advanced SubID Page & Export
                        </a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle reportTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Campaign Offer</th>
                                    <th>Publisher</th>
                                    <th>Sub ID 1</th>
                                    <th>Sub ID 2</th>
                                    <th>Sub ID 3</th>
                                    <th>Sub ID 4</th>
                                    <th>Sub ID 5</th>
                                    <th class="text-center">Clicks</th>
                                    <th class="text-center">Conversions</th>
                                    <th class="text-center">CR %</th>
                                    <th class="text-right">Total Payout</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subIdReports as $sub): 
                                    $cr = !empty($sub['clicks']) ? round(($sub['conversions'] / $sub['clicks']) * 100, 2) : 0;
                                ?>
                                <tr>
                                    <td><strong>#<?php echo (int)$sub['offer_id']; ?></strong> ~ <?php echo htmlspecialchars($sub['offer_name']); ?></td>
                                    <td><strong class="text-dark"><?php echo htmlspecialchars($sub['affiliate_name']); ?></strong></td>
                                    <td><span class="badge badge-info px-2 py-1 font-monospace" style="font-size: 11.5px;"><?php echo htmlspecialchars($sub['sub1'] ?: '-'); ?></span></td>
                                    <td><span class="badge badge-light border px-2 py-1 font-monospace" style="font-size: 11.5px;"><?php echo htmlspecialchars($sub['sub2'] ?: '-'); ?></span></td>
                                    <td><span class="badge badge-light border px-2 py-1 font-monospace" style="font-size: 11.5px;"><?php echo htmlspecialchars($sub['sub3'] ?: '-'); ?></span></td>
                                    <td><span class="badge badge-light border px-2 py-1 font-monospace" style="font-size: 11.5px;"><?php echo htmlspecialchars($sub['sub4'] ?: '-'); ?></span></td>
                                    <td><span class="badge badge-light border px-2 py-1 font-monospace" style="font-size: 11.5px;"><?php echo htmlspecialchars($sub['sub5'] ?: '-'); ?></span></td>
                                    <td class="text-center font-weight-bold"><?php echo number_format((int)$sub['clicks']); ?></td>
                                    <td class="text-center font-weight-bold text-success"><?php echo number_format((int)$sub['conversions']); ?></td>
                                    <td class="text-center font-weight-bold text-muted"><?php echo $cr; ?>%</td>
                                    <td class="text-right font-weight-bold text-primary"><?php echo format_currency((float)$sub['total_payout'], $sub['currency'] ?? 'USD'); ?></td>
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
