<?php
/**
 * Campaign Performance Reports - Admin Console
 * Comprehensive date-wise, advertiser-wise, manager-wise & device-wise campaign analytics.
 * Dual-currency aggregation (USD & INR) without mixing currencies.
 * Integrated with Quick Offer Assignment & Tracking Link Generator.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$adminUserId = (int)($_SESSION['user_id'] ?? 0);
$adminName = $_SESSION['user_name'] ?? 'Admin';
$userRole = auth_role();

// Check Manager specific permissions
if ($userRole === 'manager' && !manager_can('campaign_reports') && !manager_can('all_reports')) {
    // If not specifically granted, check basic view
}

/* ===============================
   FILTERS & PARAMETERS
================================ */
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$search   = trim($_GET['search'] ?? '');
$filterOffer = isset($_GET['offer_id']) && $_GET['offer_id'] !== 'all' ? (int)$_GET['offer_id'] : 'all';
$filterAdvertiser = isset($_GET['advertiser_id']) && $_GET['advertiser_id'] !== 'all' ? (int)$_GET['advertiser_id'] : 'all';
$filterManager = isset($_GET['manager_id']) && $_GET['manager_id'] !== 'all' ? $_GET['manager_id'] : 'all';
$filterCategory = $_GET['category'] ?? 'all';
$filterStatus = $_GET['status'] ?? 'all';
$filterDevice = $_GET['device'] ?? 'all';
$filterCountry = trim($_GET['country'] ?? '');
$sortBy = $_GET['sort'] ?? 'clicks_desc';

/* ===============================
   BUILD WHERE CLAUSE FOR CLICKS & CONVERSIONS
================================ */
$clkWhere = ["1=1"];
$clkParams = [];

$convWhere = ["1=1"];
$convParams = [];

// Date filters
if (!empty($dateFrom)) {
    $clkWhere[] = "DATE(c.created_at) >= :c_from";
    $clkParams[':c_from'] = $dateFrom;

    $convWhere[] = "DATE(cv.created_at) >= :cv_from";
    $convParams[':cv_from'] = $dateFrom;
}
if (!empty($dateTo)) {
    $clkWhere[] = "DATE(c.created_at) <= :c_to";
    $clkParams[':c_to'] = $dateTo;

    $convWhere[] = "DATE(cv.created_at) <= :cv_to";
    $convParams[':cv_to'] = $dateTo;
}

// Device filter for clicks
if ($filterDevice !== 'all') {
    $clkWhere[] = "c.device = :c_device";
    $clkParams[':c_device'] = $filterDevice;
}

// Country filter for clicks
if ($filterCountry !== '') {
    $clkWhere[] = "(c.country LIKE :c_country OR c.country = :c_country_exact)";
    $clkParams[':c_country'] = "%{$filterCountry}%";
    $clkParams[':c_country_exact'] = $filterCountry;
}

// Affiliate Manager Filter (traffic filtered by publisher's assigned manager)
if ($filterManager !== 'all') {
    if ($filterManager === 'unassigned') {
        $clkWhere[] = "(aff.account_manager_id IS NULL OR aff.account_manager_id = 0)";
        $convWhere[] = "(aff.account_manager_id IS NULL OR aff.account_manager_id = 0)";
    } else {
        $clkWhere[] = "aff.account_manager_id = :c_manager";
        $clkParams[':c_manager'] = (int)$filterManager;

        $convWhere[] = "aff.account_manager_id = :cv_manager";
        $convParams[':cv_manager'] = (int)$filterManager;
    }
}

// If Manager logged in without all_reports permission, scope traffic to their affiliates
if ($userRole === 'manager' && !manager_can('all_reports')) {
    $clkWhere[] = "aff.account_manager_id = :c_mgr_scope";
    $clkParams[':c_mgr_scope'] = $adminUserId;

    $convWhere[] = "aff.account_manager_id = :cv_mgr_scope";
    $convParams[':cv_mgr_scope'] = $adminUserId;
}

$clkWhereSql = implode(' AND ', $clkWhere);
$convWhereSql = implode(' AND ', $convWhere);

/* ===============================
   BUILD WHERE CLAUSE FOR OFFERS
================================ */
$offerWhere = ["1=1"];
$offerParams = [];

if ($filterOffer !== 'all') {
    $offerWhere[] = "o.offer_id = :o_id";
    $offerParams[':o_id'] = $filterOffer;
}

if ($filterAdvertiser !== 'all') {
    $offerWhere[] = "o.advertiser_id = :o_adv";
    $offerParams[':o_adv'] = $filterAdvertiser;
}

if ($filterCategory !== 'all') {
    $offerWhere[] = "o.category = :o_cat";
    $offerParams[':o_cat'] = $filterCategory;
}

if ($filterStatus !== 'all') {
    $offerWhere[] = "o.status = :o_status";
    $offerParams[':o_status'] = $filterStatus;
}

if ($filterCountry !== '') {
    $offerWhere[] = "(o.country LIKE :o_country OR o.geo LIKE :o_country)";
    $offerParams[':o_country'] = "%{$filterCountry}%";
}

if ($filterDevice !== 'all') {
    $offerWhere[] = "(o.device_type = :o_device OR o.device_type = 'all' OR o.device_type IS NULL OR o.device_type = '')";
    $offerParams[':o_device'] = $filterDevice;
}

if ($search !== '') {
    $offerWhere[] = "(o.offer_name LIKE :s1 OR o.offer_id = :search_exact OR u.name LIKE :s2 OR u.company LIKE :s3 OR u.email LIKE :s4)";
    $offerParams[':s1'] = "%{$search}%";
    $offerParams[':s2'] = "%{$search}%";
    $offerParams[':s3'] = "%{$search}%";
    $offerParams[':s4'] = "%{$search}%";
    $offerParams[':search_exact'] = is_numeric($search) ? (int)$search : 0;
}

$offerWhereSql = implode(' AND ', $offerWhere);

// Sort Order mapping
$sortSqlMap = [
    'clicks_desc'     => 'total_clicks DESC, approved_conversions DESC',
    'clicks_asc'      => 'total_clicks ASC, approved_conversions ASC',
    'conv_desc'        => 'approved_conversions DESC, total_clicks DESC',
    'revenue_desc'     => 'total_revenue DESC, total_clicks DESC',
    'payout_desc'      => 'total_payout DESC, total_clicks DESC',
    'id_asc'           => 'o.offer_id ASC',
    'id_desc'          => 'o.offer_id DESC',
    'name_asc'         => 'o.offer_name ASC'
];
$orderBySql = $sortSqlMap[$sortBy] ?? 'total_clicks DESC, o.offer_id ASC';

/* ===============================
   PRIMARY QUERY
================================ */
$mainSql = "
    SELECT 
        o.offer_id,
        o.offer_name,
        o.status,
        o.payout,
        o.revenue AS base_revenue,
        COALESCE(o.currency, 'USD') AS currency,
        o.category,
        o.device_type,
        o.country,
        o.geo,
        u.user_id AS advertiser_id,
        u.name AS advertiser_name,
        u.email AS advertiser_email,
        u.company AS advertiser_company,
        COALESCE(clk.total_clicks, 0) AS total_clicks,
        COALESCE(clk.unique_clicks, 0) AS unique_clicks,
        COALESCE(conv.total_conversions, 0) AS total_conversions,
        COALESCE(conv.approved_conversions, 0) AS approved_conversions,
        COALESCE(conv.pending_conversions, 0) AS pending_conversions,
        COALESCE(conv.rejected_conversions, 0) AS rejected_conversions,
        COALESCE(conv.total_revenue, 0) AS total_revenue,
        COALESCE(conv.total_payout, 0) AS total_payout
    FROM offers o
    LEFT JOIN users u ON u.user_id = o.advertiser_id
    LEFT JOIN (
        SELECT 
            c.offer_id,
            COUNT(c.click_id) AS total_clicks,
            COUNT(DISTINCT c.ip_address) AS unique_clicks
        FROM clicks c
        LEFT JOIN users aff ON aff.user_id = c.affiliate_id
        WHERE $clkWhereSql
        GROUP BY c.offer_id
    ) clk ON clk.offer_id = o.offer_id
    LEFT JOIN (
        SELECT 
            cv.offer_id,
            COUNT(cv.conversion_id) AS total_conversions,
            SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
            SUM(CASE WHEN cv.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
            SUM(CASE WHEN cv.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_conversions,
            SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END) AS total_revenue,
            SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END) AS total_payout
        FROM conversions cv
        LEFT JOIN users aff ON aff.user_id = cv.affiliate_id
        WHERE $convWhereSql
        GROUP BY cv.offer_id
    ) conv ON conv.offer_id = o.offer_id
    WHERE $offerWhereSql
    ORDER BY $orderBySql
";

$stmt = $pdo->prepare($mainSql);
$allParams = array_merge($clkParams, $convParams, $offerParams);
$stmt->execute($allParams);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   CALCULATE DUAL-CURRENCY TOTALS
================================ */
$totalClicks = 0;
$uniqueClicks = 0;
$totalConvs = 0;
$approvedConvs = 0;
$pendingConvs = 0;
$rejectedConvs = 0;

$revUSD = 0.00; $revINR = 0.00;
$payUSD = 0.00; $payINR = 0.00;
$profitUSD = 0.00; $profitINR = 0.00;

foreach ($reports as $r) {
    $c = (int)$r['total_clicks'];
    $u = (int)$r['unique_clicks'];
    $cv = (int)$r['total_conversions'];
    $app = (int)$r['approved_conversions'];
    $pnd = (int)$r['pending_conversions'];
    $rej = (int)$r['rejected_conversions'];
    $rev = (float)$r['total_revenue'];
    $pay = (float)$r['total_payout'];
    $curr = strtoupper($r['currency'] ?: 'USD');

    $totalClicks += $c;
    $uniqueClicks += $u;
    $totalConvs += $cv;
    $approvedConvs += $app;
    $pendingConvs += $pnd;
    $rejectedConvs += $rej;

    if ($curr === 'INR') {
        $revINR += $rev;
        $payINR += $pay;
        $profitINR += ($rev - $pay);
    } else {
        $revUSD += $rev;
        $payUSD += $pay;
        $profitUSD += ($rev - $pay);
    }
}

$networkCR = $totalClicks > 0 ? number_format(($approvedConvs / $totalClicks) * 100, 2) : '0.00';

/* ===============================
   CSV & EXCEL EXPORT HANDLER
================================ */
if (isset($_GET['export'])) {
    $exportFormat = strtolower($_GET['export']);
    $filename = "campaign_performance_report_" . date('Y-m-d_His') . ($exportFormat === 'excel' ? '.xls' : '.csv');

    if ($exportFormat === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "Campaign ID\tCampaign Name\tStatus\tCurrency\tCategory\tDevice\tGeo/Country\tAdvertiser Name\tAdvertiser Company\tAdvertiser Email\tTotal Clicks\tUnique Clicks\tApproved Leads\tPending Leads\tRejected Leads\tCR %\tGross Revenue\tPublisher Payout\tNet Profit\n";
        foreach ($reports as $r) {
            $c = (int)$r['total_clicks'];
            $app = (int)$r['approved_conversions'];
            $cr = $c > 0 ? number_format(($app / $c) * 100, 2) . '%' : '0.00%';
            $rev = (float)$r['total_revenue'];
            $pay = (float)$r['total_payout'];
            $prof = $rev - $pay;
            $curr = $r['currency'] ?: 'USD';

            echo implode("\t", [
                $r['offer_id'],
                str_replace("\t", ' ', $r['offer_name']),
                ucfirst($r['status']),
                $curr,
                $r['category'] ?: 'General',
                $r['device_type'] ?: 'All',
                $r['country'] ?: ($r['geo'] ?: 'Global'),
                str_replace("\t", ' ', $r['advertiser_name'] ?: 'N/A'),
                str_replace("\t", ' ', $r['advertiser_company'] ?: 'N/A'),
                $r['advertiser_email'] ?: 'N/A',
                $c,
                (int)$r['unique_clicks'],
                $app,
                (int)$r['pending_conversions'],
                (int)$r['rejected_conversions'],
                $cr,
                number_format($rev, 2),
                number_format($pay, 2),
                number_format($prof, 2)
            ]) . "\n";
        }
        exit;
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Campaign ID', 'Campaign Name', 'Status', 'Currency', 'Category', 'Device', 'Geo/Country', 'Advertiser Name', 'Advertiser Company', 'Advertiser Email', 'Total Clicks', 'Unique Clicks', 'Approved Leads', 'Pending Leads', 'Rejected Leads', 'CR %', 'Gross Revenue', 'Publisher Payout', 'Net Profit']);
        foreach ($reports as $r) {
            $c = (int)$r['total_clicks'];
            $app = (int)$r['approved_conversions'];
            $cr = $c > 0 ? number_format(($app / $c) * 100, 2) . '%' : '0.00%';
            $rev = (float)$r['total_revenue'];
            $pay = (float)$r['total_payout'];
            $prof = $rev - $pay;
            $curr = $r['currency'] ?: 'USD';

            fputcsv($out, [
                $r['offer_id'],
                $r['offer_name'],
                ucfirst($r['status']),
                $curr,
                $r['category'] ?: 'General',
                $r['device_type'] ?: 'All',
                $r['country'] ?: ($r['geo'] ?: 'Global'),
                $r['advertiser_name'] ?: 'N/A',
                $r['advertiser_company'] ?: 'N/A',
                $r['advertiser_email'] ?: 'N/A',
                $c,
                (int)$r['unique_clicks'],
                $app,
                (int)$r['pending_conversions'],
                (int)$r['rejected_conversions'],
                $cr,
                number_format($rev, 2),
                number_format($pay, 2),
                number_format($prof, 2)
            ]);
        }
        fclose($out);
        exit;
    }
}

/* ===============================
   FETCH DROPDOWN OPTIONS
================================ */
$allOffers = $pdo->query("SELECT offer_id, offer_name, currency FROM offers ORDER BY offer_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allAdvertisers = $pdo->query("SELECT user_id, name, company, email FROM users WHERE role_id = 4 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allManagers = $pdo->query("SELECT user_id, name FROM users WHERE role_id = 2 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allCategories = $pdo->query("SELECT DISTINCT category FROM offers WHERE category IS NOT NULL AND category != '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
$allCountries = $pdo->query("SELECT DISTINCT country FROM offers WHERE country IS NOT NULL AND country != '' UNION SELECT DISTINCT country FROM clicks WHERE country IS NOT NULL AND country != '' ORDER BY country ASC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaign Performance Report | Admin Console</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">

    <style>
        .card-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            background: #ffffff;
        }

        .stat-card-custom {
            border-radius: 12px;
            background: #ffffff;
            padding: 18px 14px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            text-align: center;
            margin-bottom: 15px;
            height: 100%;
        }

        .stat-card-custom .stat-number {
            font-size: 20px;
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

        .filter-panel {
            background: #f8fafc;
            border-radius: 12px;
            padding: 22px;
            border: 1px solid #e2e8f0;
            margin-bottom: 25px;
        }

        .preset-badge {
            cursor: pointer;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            background: #e2e8f0;
            color: #334155;
            transition: all 0.15s ease;
            text-decoration: none !important;
            display: inline-block;
            margin-right: 4px;
            margin-bottom: 5px;
        }
        .preset-badge:hover, .preset-badge.active {
            background: #0284c7;
            color: #ffffff;
        }

        .badge-currency-usd {
            background: #dbeafe;
            color: #1d4ed8;
            font-weight: 700;
        }
        .badge-currency-inr {
            background: #dcfce7;
            color: #15803d;
            font-weight: 700;
        }

        @media (max-width: 767.98px) {
            .stat-boxes-row > [class*="col-"] {
                flex: 0 0 50% !important;
                max-width: 50% !important;
                padding-left: 6px !important;
                padding-right: 6px !important;
            }
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="reports_campaigns.php" class="nav-link active">Campaign Reports</a></li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 font-weight-bold"><i class="fas fa-chart-line text-primary mr-2"></i>Campaign Performance Reports</h1>
                        <p class="text-muted small mb-0">Granular campaign metrics, date filtering, advertiser breakout & dual-currency earnings.</p>
                    </div>
                    <div class="col-sm-6 text-right">
                        <!-- Quick Assign & Export Actions -->
                        <?php 
                        $expQueryCsv = http_build_query(array_merge($_GET, ['export' => 'csv']));
                        $expQueryXls = http_build_query(array_merge($_GET, ['export' => 'excel']));
                        ?>
                        <button type="button" class="btn btn-primary font-weight-bold mr-2 open-assign-modal shadow-sm">
                            <i class="fas fa-link mr-1"></i> Assign Offer
                        </button>
                        <div class="btn-group">
                            <a href="?<?php echo $expQueryCsv; ?>" class="btn btn-outline-success font-weight-bold">
                                <i class="fas fa-file-csv mr-1"></i> Export CSV
                            </a>
                            <a href="?<?php echo $expQueryXls; ?>" class="btn btn-outline-primary font-weight-bold">
                                <i class="fas fa-file-excel mr-1"></i> Export Excel
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">

                <!-- Summary Stat Cards (Dual Currency USD & INR) -->
                <div class="row mb-3 stat-boxes-row">
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($totalClicks); ?></div>
                            <div class="stat-label">Total Clicks</div>
                            <small class="text-muted d-block mt-1"><?php echo number_format($uniqueClicks); ?> Unique IPs</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($approvedConvs); ?></div>
                            <div class="stat-label">Approved Leads</div>
                            <small class="text-muted d-block mt-1"><?php echo $networkCR; ?>% Conversion Rate</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning"><?php echo number_format($pendingConvs); ?> / <?php echo number_format($rejectedConvs); ?></div>
                            <div class="stat-label">Pending / Rejected</div>
                            <small class="text-muted d-block mt-1"><?php echo number_format($totalConvs); ?> Total Logged</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info" style="font-size: 18px;">
                                <?php echo format_dual_currency($revUSD, $revINR); ?>
                            </div>
                            <div class="stat-label">Gross Revenue</div>
                            <small class="text-muted d-block mt-1">Advertiser Billed</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-danger" style="font-size: 18px;">
                                <?php echo format_dual_currency($payUSD, $payINR); ?>
                            </div>
                            <div class="stat-label">Publisher Payout</div>
                            <small class="text-muted d-block mt-1">Commissions Due</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success" style="font-size: 18px;">
                                <?php echo format_dual_currency($profitUSD, $profitINR); ?>
                            </div>
                            <div class="stat-label">Network Profit</div>
                            <small class="text-muted d-block mt-1">Net Margin</small>
                        </div>
                    </div>
                </div>

                <!-- Comprehensive Filter Panel -->
                <div class="filter-panel card-custom">
                    <form method="get" id="campaignReportFilterForm">
                        
                        <!-- Quick Date Preset Badges -->
                        <div class="mb-3 d-flex flex-wrap align-items-center">
                            <span class="font-weight-bold text-muted small text-uppercase mr-2"><i class="fas fa-calendar-alt mr-1"></i> Quick Presets:</span>
                            <span class="preset-badge" onclick="setPreset('today')">Today</span>
                            <span class="preset-badge" onclick="setPreset('yesterday')">Yesterday</span>
                            <span class="preset-badge" onclick="setPreset('last7')">Last 7 Days</span>
                            <span class="preset-badge" onclick="setPreset('month')">This Month</span>
                            <span class="preset-badge" onclick="setPreset('lastmonth')">Last Month</span>
                            <span class="preset-badge" onclick="setPreset('all')">All Time</span>
                        </div>

                        <!-- Filter Fields Row 1 -->
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Date Range From</label>
                                <input type="date" name="from" id="filterFrom" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Date Range To</label>
                                <input type="date" name="to" id="filterTo" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Campaign / Offer</label>
                                <select name="offer_id" class="form-control select2">
                                    <option value="all">All Campaigns</option>
                                    <?php foreach ($allOffers as $of): ?>
                                    <option value="<?php echo $of['offer_id']; ?>" <?php echo $filterOffer == $of['offer_id'] ? 'selected' : ''; ?>>
                                        #<?php echo $of['offer_id']; ?> - <?php echo htmlspecialchars($of['offer_name']); ?> (<?php echo $of['currency'] ?: 'USD'; ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Advertiser</label>
                                <select name="advertiser_id" class="form-control select2">
                                    <option value="all">All Advertisers</option>
                                    <?php foreach ($allAdvertisers as $adv): ?>
                                    <option value="<?php echo $adv['user_id']; ?>" <?php echo $filterAdvertiser == $adv['user_id'] ? 'selected' : ''; ?>>
                                        #<?php echo $adv['user_id']; ?> - <?php echo htmlspecialchars($adv['name']); ?> <?php echo $adv['company'] ? '('.htmlspecialchars($adv['company']).')' : ''; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Filter Fields Row 2 -->
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Affiliate Manager</label>
                                <select name="manager_id" class="form-control select2">
                                    <option value="all">All Account Managers</option>
                                    <option value="unassigned" <?php echo $filterManager === 'unassigned' ? 'selected' : ''; ?>>Unassigned (No Manager)</option>
                                    <?php foreach ($allManagers as $mgr): ?>
                                    <option value="<?php echo $mgr['user_id']; ?>" <?php echo $filterManager == $mgr['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($mgr['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Category</label>
                                <select name="category" class="form-control select2">
                                    <option value="all">All Categories</option>
                                    <?php foreach ($allCategories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filterCategory === $cat ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Campaign Status</label>
                                <select name="status" class="form-control select2">
                                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="paused" <?php echo $filterStatus === 'paused' ? 'selected' : ''; ?>>Paused</option>
                                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending Review</option>
                                    <option value="archived" <?php echo $filterStatus === 'archived' ? 'selected' : ''; ?>>Archived</option>
                                </select>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Device Filter</label>
                                <select name="device" class="form-control select2">
                                    <option value="all" <?php echo $filterDevice === 'all' ? 'selected' : ''; ?>>All Devices</option>
                                    <option value="mobile" <?php echo $filterDevice === 'mobile' ? 'selected' : ''; ?>>Mobile</option>
                                    <option value="desktop" <?php echo $filterDevice === 'desktop' ? 'selected' : ''; ?>>Desktop</option>
                                    <option value="tablet" <?php echo $filterDevice === 'tablet' ? 'selected' : ''; ?>>Tablet</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Country / Geo</label>
                                <select name="country" class="form-control select2">
                                    <option value="">All Countries</option>
                                    <?php foreach ($allCountries as $cn): ?>
                                    <option value="<?php echo htmlspecialchars($cn); ?>" <?php echo $filterCountry === $cn ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cn); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Filter Fields Row 3 -->
                        <div class="row align-items-end">
                            <div class="col-md-6 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Quick Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Search Campaign Title, Offer ID, Advertiser Name, Company, Email..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Sort Table By</label>
                                <select name="sort" class="form-control select2">
                                    <option value="clicks_desc" <?php echo $sortBy === 'clicks_desc' ? 'selected' : ''; ?>>Clicks (High to Low)</option>
                                    <option value="clicks_asc" <?php echo $sortBy === 'clicks_asc' ? 'selected' : ''; ?>>Clicks (Low to High)</option>
                                    <option value="conv_desc" <?php echo $sortBy === 'conv_desc' ? 'selected' : ''; ?>>Approved Leads (High to Low)</option>
                                    <option value="revenue_desc" <?php echo $sortBy === 'revenue_desc' ? 'selected' : ''; ?>>Revenue (High to Low)</option>
                                    <option value="payout_desc" <?php echo $sortBy === 'payout_desc' ? 'selected' : ''; ?>>Payout (High to Low)</option>
                                    <option value="id_desc" <?php echo $sortBy === 'id_desc' ? 'selected' : ''; ?>>Campaign ID (Newest)</option>
                                    <option value="name_asc" <?php echo $sortBy === 'name_asc' ? 'selected' : ''; ?>>Campaign Name (A-Z)</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="d-flex">
                                    <button type="submit" class="btn btn-primary font-weight-bold flex-grow-1 mr-2 shadow-sm">
                                        <i class="fas fa-filter mr-1"></i> Apply Filters
                                    </button>
                                    <a href="reports_campaigns.php" class="btn btn-outline-secondary font-weight-bold">
                                        <i class="fas fa-redo"></i>
                                    </a>
                                </div>
                            </div>
                        </div>

                    </form>
                </div>

                <!-- Campaign Report Data Table -->
                <div class="card card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="font-weight-bold text-primary mb-0">
                            <i class="fas fa-bullhorn mr-2"></i>Performance Breakout by Campaign
                        </h4>
                        <span class="badge badge-light p-2 font-weight-bold border">
                            Showing <?php echo count($reports); ?> Campaign(s)
                        </span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="campaignReportTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Campaign Offer</th>
                                    <th>Advertiser</th>
                                    <th>Clicks</th>
                                    <th>Unique</th>
                                    <th>Approved Leads</th>
                                    <th>CR %</th>
                                    <th>Gross Revenue</th>
                                    <th>Publisher Payout</th>
                                    <th>Net Profit</th>
                                    <th style="min-width: 140px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $rep): 
                                    $clicks = (int)$rep['total_clicks'];
                                    $uClicks = (int)$rep['unique_clicks'];
                                    $approved = (int)$rep['approved_conversions'];
                                    $cr = $clicks > 0 ? number_format(($approved / $clicks) * 100, 2) : '0.00';
                                    $rev = (float)$rep['total_revenue'];
                                    $payout = (float)$rep['total_payout'];
                                    $profit = $rev - $payout;
                                    $currency = strtoupper($rep['currency'] ?: 'USD');
                                    $isArchived = ($rep['status'] === 'archived');
                                ?>
                                <tr style="<?php echo $isArchived ? 'opacity: 0.75;' : ''; ?>">
                                    <td>
                                        <a href="offer_details.php?id=<?php echo $rep['offer_id']; ?>" class="text-primary font-weight-bold text-decoration-none d-block">
                                            #<?php echo $rep['offer_id']; ?> - <?php echo htmlspecialchars($rep['offer_name']); ?>
                                        </a>
                                        <div class="d-flex flex-wrap mt-1" style="gap: 4px;">
                                            <span class="badge badge-<?php echo ($rep['status'] === 'active' || $rep['status'] === 'approved') ? 'success' : ($isArchived ? 'secondary' : 'warning'); ?>">
                                                <?php echo ucfirst($rep['status']); ?>
                                            </span>
                                            <span class="badge <?php echo ($currency === 'INR') ? 'badge-currency-inr' : 'badge-currency-usd'; ?>">
                                                <?php echo $currency; ?> (<?php echo ($currency === 'INR') ? '₹' : '$'; ?>)
                                            </span>
                                            <?php if ($rep['category']): ?>
                                            <span class="badge badge-light border text-muted"><?php echo htmlspecialchars($rep['category']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <strong class="text-dark d-block"><?php echo htmlspecialchars($rep['advertiser_name'] ?: 'N/A'); ?></strong>
                                        <?php if ($rep['advertiser_company']): ?>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($rep['advertiser_company']); ?></small>
                                        <?php endif; ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($rep['advertiser_email'] ?: ''); ?></small>
                                    </td>
                                    <td><strong><?php echo number_format($clicks); ?></strong></td>
                                    <td><span class="text-muted"><?php echo number_format($uClicks); ?></span></td>
                                    <td><strong class="text-success"><?php echo number_format($approved); ?></strong></td>
                                    <td><span class="badge badge-info p-2"><?php echo $cr; ?>%</span></td>
                                    <td><strong class="text-dark"><?php echo format_currency($rev, $currency); ?></strong></td>
                                    <td><strong class="text-danger"><?php echo format_currency($payout, $currency); ?></strong></td>
                                    <td><strong class="text-success font-weight-bold"><?php echo format_currency($profit, $currency); ?></strong></td>
                                    <td>
                                        <div class="btn-group">
                                            <?php if (!$isArchived): ?>
                                            <button type="button" 
                                                    class="btn btn-sm btn-outline-success font-weight-bold open-assign-modal" 
                                                    data-offer-id="<?php echo $rep['offer_id']; ?>"
                                                    data-offer-name="<?php echo htmlspecialchars($rep['offer_name'], ENT_QUOTES); ?>"
                                                    data-payout="<?php echo htmlspecialchars($rep['payout']); ?>"
                                                    data-currency="<?php echo htmlspecialchars($currency); ?>"
                                                    title="Assign Offer & Generate Link">
                                                <i class="fas fa-link mr-1"></i> Assign
                                            </button>
                                            <?php endif; ?>
                                            <a href="offer_details.php?id=<?php echo $rep['offer_id']; ?>" class="btn btn-sm btn-outline-info" title="View Offer Specs">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="offer_edit.php?id=<?php echo $rep['offer_id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit Offer">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Admin Panel v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">Offer on Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap4',
        width: '100%'
    });

    $('#campaignReportTable').DataTable({
        pageLength: 25,
        responsive: true,
        order: [[2, 'desc']]
    });
});

// Quick date presets
function setPreset(period) {
    var today = new Date();
    var fromDate, toDate;
    
    function formatDate(d) {
        var month = '' + (d.getMonth() + 1),
            day = '' + d.getDate(),
            year = d.getFullYear();
        if (month.length < 2) month = '0' + month;
        if (day.length < 2) day = '0' + day;
        return [year, month, day].join('-');
    }

    if (period === 'today') {
        fromDate = formatDate(today);
        toDate = formatDate(today);
    } else if (period === 'yesterday') {
        var y = new Date();
        y.setDate(today.getDate() - 1);
        fromDate = formatDate(y);
        toDate = formatDate(y);
    } else if (period === 'last7') {
        var d7 = new Date();
        d7.setDate(today.getDate() - 6);
        fromDate = formatDate(d7);
        toDate = formatDate(today);
    } else if (period === 'month') {
        var firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
        fromDate = formatDate(firstDay);
        toDate = formatDate(today);
    } else if (period === 'lastmonth') {
        var firstDayLM = new Date(today.getFullYear(), today.getMonth() - 1, 1);
        var lastDayLM = new Date(today.getFullYear(), today.getMonth(), 0);
        fromDate = formatDate(firstDayLM);
        toDate = formatDate(lastDayLM);
    } else if (period === 'all') {
        fromDate = '2020-01-01';
        toDate = formatDate(today);
    }

    $('#filterFrom').val(fromDate);
    $('#filterTo').val(toDate);
    $('#campaignReportFilterForm').submit();
}
</script>

<?php include __DIR__ . '/modal_assign_offer.php'; ?>
</body>
</html>
