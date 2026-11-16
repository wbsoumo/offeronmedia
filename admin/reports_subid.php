<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$adminUserId = auth_user_id();
$adminName   = $_SESSION['user_name'] ?? 'Admin';

// Permission check
if (auth_role() === 'manager' && !manager_can('view_affiliate_reports') && !manager_can('all_reports')) {
    die('<div style="font-family:sans-serif; text-align:center; padding:50px;"><h2>Access Denied</h2><p>You do not have permission to view Performance Reports.</p></div>');
}

/* ===============================
   REPORT TYPE / GROUPING (SubID, Campaign, Affiliate, Date)
================================ */
$reportType = $_GET['report_type'] ?? 'subid';
$groupBySub = $_GET['group_sub'] ?? 'all_subs'; // 'all_subs', 'sub1', 'sub2', 'sub3', 'sub4', 'sub5'

/* ===============================
   FILTERS
================================ */
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$search   = trim($_GET['search'] ?? '');
$filterOffer = isset($_GET['offer_id']) && $_GET['offer_id'] !== 'all' ? (int)$_GET['offer_id'] : 'all';
$filterAffiliate = isset($_GET['affiliate_id']) && $_GET['affiliate_id'] !== 'all' ? (int)$_GET['affiliate_id'] : 'all';
$filterManager = isset($_GET['manager_id']) && $_GET['manager_id'] !== 'all' ? (int)$_GET['manager_id'] : 'all';

// Simultaneous Multi-SubID Filters
$subFilter1 = trim($_GET['sub1'] ?? '');
$subFilter2 = trim($_GET['sub2'] ?? '');
$subFilter3 = trim($_GET['sub3'] ?? '');
$subFilter4 = trim($_GET['sub4'] ?? '');
$subFilter5 = trim($_GET['sub5'] ?? '');

// Multi-select Sub ID columns selector (All selected by default)
$defaultSubCols = ['sub1', 'sub2', 'sub3', 'sub4', 'sub5'];
$selectedSubCols = isset($_GET['sub_cols']) && is_array($_GET['sub_cols']) 
    ? array_intersect($_GET['sub_cols'], $defaultSubCols) 
    : $defaultSubCols;
if (empty($selectedSubCols)) {
    $selectedSubCols = $defaultSubCols;
}

$sortBy = $_GET['sort'] ?? 'payout_desc';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], [25, 50, 100, 250], true) ? (int)$_GET['per_page'] : 50;
$perPage = max(1, $perPage);

/* ===============================
   BUILD WHERE CLAUSE
================================ */
$where = ["1=1"];
$params = [];

if (auth_role() === 'manager' && !manager_can('all_reports')) {
    $where[] = "u.account_manager_id = :mgr_scope_id";
    $params['mgr_scope_id'] = $adminUserId;
}

if ($dateFrom && $dateTo) {
    $where[] = "DATE(cl.created_at) BETWEEN :dfrom AND :dto";
    $params['dfrom'] = $dateFrom;
    $params['dto']   = $dateTo;
}

if ($filterOffer !== 'all') {
    $where[] = "cl.offer_id = :oid";
    $params['oid'] = $filterOffer;
}

if ($filterAffiliate !== 'all') {
    $where[] = "cl.affiliate_id = :affid";
    $params['affid'] = $filterAffiliate;
}

if ($filterManager !== 'all') {
    if ($filterManager === 0 || $filterManager === 'unassigned') {
        $where[] = "u.account_manager_id IS NULL";
    } else {
        $where[] = "u.account_manager_id = :f_mid";
        $params['f_mid'] = $filterManager;
    }
}

// Multi-SubID simultaneous filtering
if ($subFilter1 !== '') {
    $where[] = "cl.sub1 LIKE :sub1_val";
    $params['sub1_val'] = "%$subFilter1%";
}
if ($subFilter2 !== '') {
    $where[] = "cl.sub2 LIKE :sub2_val";
    $params['sub2_val'] = "%$subFilter2%";
}
if ($subFilter3 !== '') {
    $where[] = "cl.sub3 LIKE :sub3_val";
    $params['sub3_val'] = "%$subFilter3%";
}
if ($subFilter4 !== '') {
    $where[] = "cl.sub4 LIKE :sub4_val";
    $params['sub4_val'] = "%$subFilter4%";
}
if ($subFilter5 !== '') {
    $where[] = "cl.sub5 LIKE :sub5_val";
    $params['sub5_val'] = "%$subFilter5%";
}

if ($search !== '') {
    $where[] = "(cl.sub1 LIKE :search OR cl.sub2 LIKE :search OR cl.sub3 LIKE :search OR cl.sub4 LIKE :search OR cl.sub5 LIKE :search OR o.offer_name LIKE :search OR u.name LIKE :search)";
    $params['search'] = "%$search%";
}

$whereSql = "WHERE " . implode(' AND ', $where);

/* ===============================
   GROUP BY & SELECT LOGIC BASED ON REPORT TYPE
================================ */
switch ($reportType) {
    case 'campaign':
        $selectFields = "
            o.offer_id AS group_id,
            o.offer_name AS group_title,
            o.currency,
            'campaign' AS report_mode
        ";
        $groupBy = "o.offer_id";
        break;

    case 'affiliate':
        $selectFields = "
            u.user_id AS group_id,
            u.name AS group_title,
            u.email AS group_subtitle,
            u.account_manager_id,
            am.name AS manager_name,
            'affiliate' AS report_mode
        ";
        $groupBy = "u.user_id";
        break;

    case 'date':
        $selectFields = "
            DATE(cl.created_at) AS group_id,
            DATE(cl.created_at) AS group_title,
            'date' AS report_mode
        ";
        $groupBy = "DATE(cl.created_at)";
        break;

    case 'subid':
    default:
        $reportType = 'subid';
        if ($groupBySub === 'sub1') {
            $selectFields = "cl.sub1 AS group_title, 'sub1' AS sub_type";
            $groupBy = "cl.sub1";
        } elseif ($groupBySub === 'sub2') {
            $selectFields = "cl.sub2 AS group_title, 'sub2' AS sub_type";
            $groupBy = "cl.sub2";
        } elseif ($groupBySub === 'sub3') {
            $selectFields = "cl.sub3 AS group_title, 'sub3' AS sub_type";
            $groupBy = "cl.sub3";
        } elseif ($groupBySub === 'sub4') {
            $selectFields = "cl.sub4 AS group_title, 'sub4' AS sub_type";
            $groupBy = "cl.sub4";
        } elseif ($groupBySub === 'sub5') {
            $selectFields = "cl.sub5 AS group_title, 'sub5' AS sub_type";
            $groupBy = "cl.sub5";
        } else {
            $selectFields = "
                cl.sub1, cl.sub2, cl.sub3, cl.sub4, cl.sub5,
                o.offer_name, o.offer_id,
                u.name AS affiliate_name, u.user_id AS affiliate_id,
                u.account_manager_id, am.name AS manager_name,
                o.currency
            ";
            $groupBy = "cl.sub1, cl.sub2, cl.sub3, cl.sub4, cl.sub5, cl.offer_id, cl.affiliate_id";
        }
        break;
}

$sortClause = "total_payout DESC";
if ($sortBy === 'clicks_desc') $sortClause = "total_clicks DESC";
elseif ($sortBy === 'conv_desc') $sortClause = "approved_conversions DESC";
elseif ($sortBy === 'revenue_desc') $sortClause = "total_revenue DESC";
elseif ($sortBy === 'profit_desc') $sortClause = "net_profit DESC";

/* ===============================
   EXPORT TO CSV / EXCEL
================================ */
if (isset($_GET['export'])) {
    $exportFormat = strtolower($_GET['export']);
    $expSql = "
        SELECT 
            $selectFields,
            COUNT(DISTINCT cl.click_id) AS total_clicks,
            COUNT(DISTINCT c.conversion_id) AS total_conversions,
            SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
            SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
            SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_conversions,
            IFNULL(SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END), 0) AS total_revenue,
            IFNULL(SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END), 0) AS total_payout,
            (IFNULL(SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END), 0) - IFNULL(SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END), 0)) AS net_profit
        FROM clicks cl
        INNER JOIN offers o ON o.offer_id = cl.offer_id
        INNER JOIN users u ON u.user_id = cl.affiliate_id
        LEFT JOIN users am ON am.user_id = u.account_manager_id AND am.role_id = 2
        LEFT JOIN conversions c ON c.click_id = cl.click_id
        $whereSql
        GROUP BY $groupBy
        ORDER BY $sortClause
        LIMIT 5000
    ";
    $expStmt = $pdo->prepare($expSql);
    $expStmt->execute($params);
    $expRows = $expStmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = "performance_report_{$reportType}_" . date('Y-m-d') . ($exportFormat === 'excel' ? '.xls' : '.csv');

    if ($exportFormat === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "Dimension\tSub ID 1\tSub ID 2\tSub ID 3\tSub ID 4\tSub ID 5\tClicks\tTotal Leads\tApproved Leads\tPending Leads\tRejected Leads\tGross Revenue\tAffiliate Payout\tNet Profit\tCR %\n";
        foreach ($expRows as $r) {
            $rClicks = (int)$r['total_clicks'];
            $rConv   = (int)$r['approved_conversions'];
            $rCr     = $rClicks > 0 ? number_format(($rConv / $rClicks) * 100, 2) : '0.00';
            echo implode("\t", [
                $r['group_title'] ?? ($r['offer_name'] ?? 'SubID Report'),
                $r['sub1'] ?? '',
                $r['sub2'] ?? '',
                $r['sub3'] ?? '',
                $r['sub4'] ?? '',
                $r['sub5'] ?? '',
                $rClicks,
                $r['total_conversions'],
                $rConv,
                $r['pending_conversions'],
                $r['rejected_conversions'],
                number_format((float)$r['total_revenue'], 2),
                number_format((float)$r['total_payout'], 2),
                number_format((float)$r['net_profit'], 2),
                $rCr . '%'
            ]) . "\n";
        }
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Dimension', 'Sub ID 1', 'Sub ID 2', 'Sub ID 3', 'Sub ID 4', 'Sub ID 5', 'Clicks', 'Total Leads', 'Approved Leads', 'Pending Leads', 'Rejected Leads', 'Gross Revenue', 'Affiliate Payout', 'Net Profit', 'CR %']);
        foreach ($expRows as $r) {
            $rClicks = (int)$r['total_clicks'];
            $rConv   = (int)$r['approved_conversions'];
            $rCr     = $rClicks > 0 ? number_format(($rConv / $rClicks) * 100, 2) : '0.00';
            fputcsv($out, [
                $r['group_title'] ?? ($r['offer_name'] ?? 'SubID Report'),
                $r['sub1'] ?? '',
                $r['sub2'] ?? '',
                $r['sub3'] ?? '',
                $r['sub4'] ?? '',
                $r['sub5'] ?? '',
                $rClicks,
                $r['total_conversions'],
                $rConv,
                $r['pending_conversions'],
                $r['rejected_conversions'],
                $r['total_revenue'],
                $r['total_payout'],
                $r['net_profit'],
                $rCr . '%'
            ]);
        }
        fclose($out);
    }
    exit;
}

/* ===============================
   OVERALL KPI STATS (CURRENCY AWARE)
================================ */
$kpiSql = "
    SELECT 
        COUNT(DISTINCT cl.click_id) AS total_clicks,
        COUNT(DISTINCT c.conversion_id) AS total_conversions,
        SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
        SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_conversions,

        -- USD Totals
        SUM(CASE WHEN c.status = 'approved' AND (o.currency IS NULL OR o.currency = 'USD') THEN c.revenue ELSE 0 END) AS revenue_usd,
        SUM(CASE WHEN c.status = 'approved' AND (o.currency IS NULL OR o.currency = 'USD') THEN c.payout ELSE 0 END) AS payout_usd,

        -- INR Totals
        SUM(CASE WHEN c.status = 'approved' AND o.currency = 'INR' THEN c.revenue ELSE 0 END) AS revenue_inr,
        SUM(CASE WHEN c.status = 'approved' AND o.currency = 'INR' THEN c.payout ELSE 0 END) AS payout_inr

    FROM clicks cl
    INNER JOIN offers o ON o.offer_id = cl.offer_id
    INNER JOIN users u ON u.user_id = cl.affiliate_id
    LEFT JOIN users am ON am.user_id = u.account_manager_id AND am.role_id = 2
    LEFT JOIN conversions c ON c.click_id = cl.click_id
    $whereSql
";
$kpiStmt = $pdo->prepare($kpiSql);
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalClicks = (int)($kpi['total_clicks'] ?? 0);
$approvedConvs = (int)($kpi['approved_conversions'] ?? 0);
$pendingConvs = (int)($kpi['pending_conversions'] ?? 0);
$rejectedConvs = (int)($kpi['rejected_conversions'] ?? 0);
$cr = $totalClicks > 0 ? ($approvedConvs / $totalClicks) * 100 : 0.00;

$revUSD = (float)($kpi['revenue_usd'] ?? 0);
$payUSD = (float)($kpi['payout_usd'] ?? 0);
$profitUSD = $revUSD - $payUSD;

$revINR = (float)($kpi['revenue_inr'] ?? 0);
$payINR = (float)($kpi['payout_inr'] ?? 0);
$profitINR = $revINR - $payINR;

/* ===============================
   MAIN GROUPED REPORT QUERY
================================ */
$mainSql = "
    SELECT 
        $selectFields,
        COUNT(DISTINCT cl.click_id) AS total_clicks,
        COUNT(DISTINCT c.conversion_id) AS total_conversions,
        SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
        SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_conversions,
        IFNULL(SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END), 0) AS total_revenue,
        IFNULL(SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END), 0) AS total_payout,
        (IFNULL(SUM(CASE WHEN c.status = 'approved' THEN c.revenue ELSE 0 END), 0) - IFNULL(SUM(CASE WHEN c.status = 'approved' THEN c.payout ELSE 0 END), 0)) AS net_profit
    FROM clicks cl
    INNER JOIN offers o ON o.offer_id = cl.offer_id
    INNER JOIN users u ON u.user_id = cl.affiliate_id
    LEFT JOIN users am ON am.user_id = u.account_manager_id AND am.role_id = 2
    LEFT JOIN conversions c ON c.click_id = cl.click_id
    $whereSql
    GROUP BY $groupBy
    ORDER BY $sortClause
    LIMIT 250
";

$mainStmt = $pdo->prepare($mainSql);
$mainStmt->execute($params);
$reportRows = $mainStmt->fetchAll(PDO::FETCH_ASSOC);

$allOffers = $pdo->query("SELECT offer_id, offer_name, currency FROM offers ORDER BY offer_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allAffiliates = $pdo->query("SELECT user_id, name, email FROM users WHERE role_id = 3 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allManagers = $pdo->query("SELECT user_id, name FROM users WHERE role_id = 2 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SubID & Performance Reports | Admin Console</title>
    
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
            padding: 16px 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            text-align: center;
            margin-bottom: 15px;
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
        .subid-tag {
            font-size: 11px;
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid #bae6fd;
            display: inline-block;
            max-width: 140px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
            <li class="nav-item d-none d-sm-inline-block"><a href="#" class="nav-link active">Performance Reports</a></li>
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
                    <li class="nav-item"><a href="account_managers.php" class="nav-link"><i class="nav-icon fas fa-user-tie"></i><p>Account Managers</p></a></li>
                    <li class="nav-header">ANALYTICS & REPORTS</li>
                    <li class="nav-item"><a href="reports_clicks.php" class="nav-link"><i class="nav-icon fas fa-mouse-pointer"></i><p>Click Reports</p></a></li>
                    <li class="nav-item"><a href="reports_affiliates.php" class="nav-link"><i class="nav-icon fas fa-chart-line"></i><p>Affiliate Reports</p></a></li>
                    <li class="nav-item"><a href="reports_campaigns.php" class="nav-link"><i class="nav-icon fas fa-chart-bar"></i><p>Campaign Reports</p></a></li>
                    <li class="nav-item"><a href="reports_subid.php" class="nav-link active"><i class="nav-icon fas fa-list"></i><p>SubID Performance</p></a></li>
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
                        <h1 class="m-0 font-weight-bold"><i class="fas fa-chart-line text-primary mr-2"></i>Performance & SubID Reports</h1>
                        <p class="text-muted small mb-0">Multi-dimensional analytics: SubID-wise, campaign-wise, affiliate-wise & date-wise performance tracking.</p>
                    </div>
                    <div class="col-sm-6 text-right">
                        <?php 
                        $expQueryCsv = http_build_query(array_merge($_GET, ['export' => 'csv']));
                        $expQueryXls = http_build_query(array_merge($_GET, ['export' => 'excel']));
                        ?>
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

        <div class="content">
            <div class="container-fluid">

                <!-- Summary KPI Cards -->
                <div class="row mb-3">
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($totalClicks); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($approvedConvs); ?></div>
                            <div class="stat-label">Approved Leads</div>
                            <small class="text-muted d-block mt-1"><?php echo number_format($cr, 2); ?>% CR</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning"><?php echo number_format($pendingConvs); ?> / <?php echo number_format($rejectedConvs); ?></div>
                            <div class="stat-label">Pending / Rejected</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info" style="font-size: 19px;">
                                <?php echo format_dual_currency($revUSD, $revINR); ?>
                            </div>
                            <div class="stat-label">Gross Revenue</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning" style="font-size: 19px;">
                                <?php echo format_dual_currency($payUSD, $payINR); ?>
                            </div>
                            <div class="stat-label">Publisher Payout</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success" style="font-size: 19px;">
                                <?php echo format_dual_currency($profitUSD, $profitINR); ?>
                            </div>
                            <div class="stat-label">Net Profit</div>
                        </div>
                    </div>
                </div>

                <!-- Report Grouping Tabs (Requirement 3) -->
                <div class="card card-custom mb-4">
                    <div class="card-header p-0">
                        <ul class="nav nav-tabs nav-tabs-custom px-3">
                            <li class="nav-item">
                                <a class="nav-link <?php echo $reportType === 'subid' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['report_type' => 'subid'])); ?>">
                                    <i class="fas fa-tags mr-2"></i>Sub ID-Wise Reporting
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $reportType === 'campaign' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['report_type' => 'campaign'])); ?>">
                                    <i class="fas fa-bullhorn mr-2"></i>Campaign-Wise Reporting
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $reportType === 'affiliate' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['report_type' => 'affiliate'])); ?>">
                                    <i class="fas fa-user-friends mr-2"></i>Affiliate-Wise Reporting
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $reportType === 'date' ? 'active' : ''; ?>" href="?<?php echo http_build_query(array_merge($_GET, ['report_type' => 'date'])); ?>">
                                    <i class="far fa-calendar-alt mr-2"></i>Date-Wise Reporting
                                </a>
                            </li>
                        </ul>
                    </div>

                    <div class="card-body p-4">
                        <!-- Filters Form -->
                        <form method="get">
                            <input type="hidden" name="report_type" value="<?php echo htmlspecialchars($reportType); ?>">
                            
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="font-weight-bold small text-muted text-uppercase">Date Range From</label>
                                    <input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="font-weight-bold small text-muted text-uppercase">Date Range To</label>
                                    <input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="font-weight-bold small text-muted text-uppercase">Campaign Offer</label>
                                    <select name="offer_id" class="form-control select2">
                                        <option value="all">All Campaigns</option>
                                        <?php foreach ($allOffers as $of): ?>
                                        <option value="<?php echo $of['offer_id']; ?>" <?php echo $filterOffer == $of['offer_id'] ? 'selected' : ''; ?>>
                                            #<?php echo $of['offer_id']; ?> - <?php echo htmlspecialchars($of['offer_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="font-weight-bold small text-muted text-uppercase">Publisher</label>
                                    <select name="affiliate_id" class="form-control select2">
                                        <option value="all">All Publishers</option>
                                        <?php foreach ($allAffiliates as $af): ?>
                                        <option value="<?php echo $af['user_id']; ?>" <?php echo $filterAffiliate == $af['user_id'] ? 'selected' : ''; ?>>
                                            #<?php echo $af['user_id']; ?> - <?php echo htmlspecialchars($af['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Simultaneous Sub ID Filter Row -->
                            <div class="bg-light p-3 rounded border mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
                                    <div>
                                        <label class="font-weight-bold text-dark mb-0"><i class="fas fa-filter text-primary mr-1"></i> Filter by Multiple Sub IDs Simultaneously:</label>
                                        <small class="text-muted d-block">Filter any combination of Sub IDs 1 to 5 concurrently.</small>
                                    </div>
                                    <?php if ($reportType === 'subid'): ?>
                                    <div>
                                        <label class="font-weight-bold small text-dark mr-2">Group By:</label>
                                        <select name="group_sub" class="custom-select custom-select-sm d-inline-block w-auto font-weight-bold">
                                            <option value="all_subs" <?php echo $groupBySub === 'all_subs' ? 'selected' : ''; ?>>All Sub IDs Combined</option>
                                            <option value="sub1" <?php echo $groupBySub === 'sub1' ? 'selected' : ''; ?>>Group by Sub ID 1</option>
                                            <option value="sub2" <?php echo $groupBySub === 'sub2' ? 'selected' : ''; ?>>Group by Sub ID 2</option>
                                            <option value="sub3" <?php echo $groupBySub === 'sub3' ? 'selected' : ''; ?>>Group by Sub ID 3</option>
                                            <option value="sub4" <?php echo $groupBySub === 'sub4' ? 'selected' : ''; ?>>Group by Sub ID 4</option>
                                            <option value="sub5" <?php echo $groupBySub === 'sub5' ? 'selected' : ''; ?>>Group by Sub ID 5</option>
                                        </select>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="row">
                                    <div class="col-md-2 col-6 mb-2">
                                        <input type="text" name="sub1" class="form-control form-control-sm" placeholder="Sub ID 1 filter" value="<?php echo htmlspecialchars($subFilter1); ?>">
                                    </div>
                                    <div class="col-md-2 col-6 mb-2">
                                        <input type="text" name="sub2" class="form-control form-control-sm" placeholder="Sub ID 2 filter" value="<?php echo htmlspecialchars($subFilter2); ?>">
                                    </div>
                                    <div class="col-md-2 col-6 mb-2">
                                        <input type="text" name="sub3" class="form-control form-control-sm" placeholder="Sub ID 3 filter" value="<?php echo htmlspecialchars($subFilter3); ?>">
                                    </div>
                                    <div class="col-md-2 col-6 mb-2">
                                        <input type="text" name="sub4" class="form-control form-control-sm" placeholder="Sub ID 4 filter" value="<?php echo htmlspecialchars($subFilter4); ?>">
                                    </div>
                                    <div class="col-md-2 col-6 mb-2">
                                        <input type="text" name="sub5" class="form-control form-control-sm" placeholder="Sub ID 5 filter" value="<?php echo htmlspecialchars($subFilter5); ?>">
                                    </div>
                                    <div class="col-md-2 col-12 mb-2">
                                        <select name="sort" class="form-control form-control-sm">
                                            <option value="payout_desc" <?php echo $sortBy === 'payout_desc' ? 'selected' : ''; ?>>Sort by Payout</option>
                                            <option value="revenue_desc" <?php echo $sortBy === 'revenue_desc' ? 'selected' : ''; ?>>Sort by Revenue</option>
                                            <option value="clicks_desc" <?php echo $sortBy === 'clicks_desc' ? 'selected' : ''; ?>>Sort by Clicks</option>
                                            <option value="conv_desc" <?php echo $sortBy === 'conv_desc' ? 'selected' : ''; ?>>Sort by Conversions</option>
                                            <option value="profit_desc" <?php echo $sortBy === 'profit_desc' ? 'selected' : ''; ?>>Sort by Profit</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary font-weight-bold px-4 shadow-sm mr-2">
                                    <i class="fas fa-filter mr-1"></i> Generate Performance Report
                                </button>
                                <a href="reports_subid.php?report_type=<?php echo htmlspecialchars($reportType); ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-redo mr-1"></i> Reset Filters
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Report Results Table -->
                <div class="card card-custom p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="reportTable" style="font-size: 13.5px;">
                            <thead class="thead-light">
                                <tr>
                                    <?php if ($reportType === 'subid' && $groupBySub === 'all_subs'): ?>
                                        <th>Campaign</th>
                                        <th>Publisher</th>
                                        <th>Affiliate Manager</th>
                                        <th>Sub ID 1</th>
                                        <th>Sub ID 2</th>
                                        <th>Sub ID 3</th>
                                        <th>Sub ID 4</th>
                                        <th>Sub ID 5</th>
                                    <?php elseif ($reportType === 'subid'): ?>
                                        <th><?php echo strtoupper($groupBySub); ?> Value</th>
                                    <?php elseif ($reportType === 'campaign'): ?>
                                        <th>Campaign Offer</th>
                                        <th>Currency</th>
                                    <?php elseif ($reportType === 'affiliate'): ?>
                                        <th>Publisher / Affiliate</th>
                                        <th>Affiliate Manager</th>
                                    <?php elseif ($reportType === 'date'): ?>
                                        <th>Tracking Date</th>
                                    <?php endif; ?>

                                    <th class="text-center">Clicks</th>
                                    <th class="text-center">Approved Leads</th>
                                    <th class="text-center">Pending / Rejected</th>
                                    <th class="text-center">CR %</th>
                                    <th class="text-right">Revenue</th>
                                    <th class="text-right">Payout</th>
                                    <th class="text-right">Net Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($reportRows)): ?>
                                <tr>
                                    <td colspan="15" class="text-center text-muted py-5">
                                        <i class="fas fa-chart-line fa-2x mb-2 d-block"></i>
                                        No performance records found for the selected parameters.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($reportRows as $row): 
                                    $rClicks = (int)$row['total_clicks'];
                                    $rApproved = (int)$row['approved_conversions'];
                                    $rPending = (int)$row['pending_conversions'];
                                    $rRejected = (int)$row['rejected_conversions'];
                                    $rCr = $rClicks > 0 ? ($rApproved / $rClicks) * 100 : 0.00;
                                    $rRev = (float)$row['total_revenue'];
                                    $rPay = (float)$row['total_payout'];
                                    $rProf = (float)$row['net_profit'];
                                    $cur = $row['currency'] ?? 'USD';
                                ?>
                                <tr>
                                    <?php if ($reportType === 'subid' && $groupBySub === 'all_subs'): ?>
                                        <td>
                                            <a href="offer_details.php?id=<?php echo $row['offer_id']; ?>" class="font-weight-bold text-dark">
                                                #<?php echo $row['offer_id']; ?> - <?php echo htmlspecialchars($row['offer_name']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <a href="affiliate_details.php?id=<?php echo $row['affiliate_id']; ?>" class="font-weight-bold text-primary">
                                                <?php echo htmlspecialchars($row['affiliate_name']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['account_manager_id']) && !empty($row['manager_name'])): ?>
                                                <a href="manager_profile.php?id=<?php echo $row['account_manager_id']; ?>" class="badge badge-light border text-primary" data-toggle="tooltip" title="Click to view Affiliate Manager Profile">
                                                    <i class="fas fa-user-tie text-primary mr-1"></i><?php echo htmlspecialchars($row['manager_name']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="subid-tag"><?php echo htmlspecialchars($row['sub1'] ?: '-'); ?></span></td>
                                        <td><span class="subid-tag"><?php echo htmlspecialchars($row['sub2'] ?: '-'); ?></span></td>
                                        <td><span class="subid-tag"><?php echo htmlspecialchars($row['sub3'] ?: '-'); ?></span></td>
                                        <td><span class="subid-tag"><?php echo htmlspecialchars($row['sub4'] ?: '-'); ?></span></td>
                                        <td><span class="subid-tag"><?php echo htmlspecialchars($row['sub5'] ?: '-'); ?></span></td>

                                    <?php elseif ($reportType === 'subid'): ?>
                                        <td><strong class="text-dark font-monospace"><?php echo htmlspecialchars($row['group_title'] ?: '(empty / not set)'); ?></strong></td>

                                    <?php elseif ($reportType === 'campaign'): ?>
                                        <td>
                                            <a href="offer_details.php?id=<?php echo $row['group_id']; ?>" class="font-weight-bold text-primary">
                                                #<?php echo $row['group_id']; ?> - <?php echo htmlspecialchars($row['group_title']); ?>
                                            </a>
                                        </td>
                                        <td><span class="badge badge-light border font-weight-bold"><?php echo htmlspecialchars($cur); ?></span></td>

                                    <?php elseif ($reportType === 'affiliate'): ?>
                                        <td>
                                            <a href="affiliate_details.php?id=<?php echo $row['group_id']; ?>" class="font-weight-bold text-primary">
                                                <?php echo htmlspecialchars($row['group_title']); ?>
                                            </a>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($row['group_subtitle'] ?? ''); ?></small>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['account_manager_id']) && !empty($row['manager_name'])): ?>
                                                <a href="manager_profile.php?id=<?php echo $row['account_manager_id']; ?>" class="badge badge-light border text-primary" data-toggle="tooltip" title="Click to view Affiliate Manager Profile">
                                                    <i class="fas fa-user-tie text-primary mr-1"></i><?php echo htmlspecialchars($row['manager_name']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Unassigned</span>
                                            <?php endif; ?>
                                        </td>

                                    <?php elseif ($reportType === 'date'): ?>
                                        <td><strong class="text-dark"><i class="far fa-calendar-alt mr-1 text-muted"></i><?php echo date('M d, Y (l)', strtotime($row['group_title'])); ?></strong></td>
                                    <?php endif; ?>

                                    <td class="text-center font-weight-bold text-dark"><?php echo number_format($rClicks); ?></td>
                                    <td class="text-center font-weight-bold text-success"><span class="badge badge-success px-2 py-1"><?php echo number_format($rApproved); ?></span></td>
                                    <td class="text-center font-weight-bold text-muted"><?php echo number_format($rPending); ?> / <?php echo number_format($rRejected); ?></td>
                                    <td class="text-center font-weight-bold text-primary"><?php echo number_format($rCr, 2); ?>%</td>
                                    <td class="text-right font-weight-bold text-success"><?php echo format_currency($rRev, $cur); ?></td>
                                    <td class="text-right font-weight-bold text-warning"><?php echo format_currency($rPay, $cur); ?></td>
                                    <td class="text-right font-weight-bold text-primary"><?php echo format_currency($rProf, $cur); ?></td>
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

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Admin Panel v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Offer on Media</a>.</strong> All rights reserved.
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
    $('.select2').select2({ theme: 'bootstrap4', width: '100%' });
    $('[data-toggle="tooltip"]').tooltip();
    $('#reportTable').DataTable({
        pageLength: 25,
        responsive: true,
        order: []
    });
});
</script>
</body>
</html>
