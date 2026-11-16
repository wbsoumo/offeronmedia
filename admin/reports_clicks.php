<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['admin', 'manager']);

$adminUserId = auth_user_id();
$adminName   = $_SESSION['user_name'] ?? 'Admin';

// Check permissions if manager
if (auth_role() === 'manager' && !manager_can('click_reports')) {
    die('<div style="font-family:sans-serif; text-align:center; padding:50px;"><h2>Access Denied</h2><p>You do not have permission to view Click Reports.</p><a href="/manager/dashboard.php">Return to Dashboard</a></div>');
}

/* ===============================
   FILTERS & PARAMETERS
================================ */
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$search   = trim($_GET['search'] ?? '');
$filterOffer = isset($_GET['offer_id']) && $_GET['offer_id'] !== 'all' ? (int)$_GET['offer_id'] : 'all';
$filterAffiliate = isset($_GET['affiliate_id']) && $_GET['affiliate_id'] !== 'all' ? (int)$_GET['affiliate_id'] : 'all';
$filterManager = isset($_GET['manager_id']) && $_GET['manager_id'] !== 'all' ? (int)$_GET['manager_id'] : 'all';
$filterStatus = $_GET['conversion_status'] ?? 'all';
$filterDevice = $_GET['device'] ?? 'all';

// Specific SubID filters
$subFilter1 = trim($_GET['sub1'] ?? '');
$subFilter2 = trim($_GET['sub2'] ?? '');
$subFilter3 = trim($_GET['sub3'] ?? '');
$subFilter4 = trim($_GET['sub4'] ?? '');
$subFilter5 = trim($_GET['sub5'] ?? '');

// SubID Columns to Display (Multi-select filter, ALL selected by default)
$defaultSubCols = ['sub1', 'sub2', 'sub3', 'sub4', 'sub5'];
$selectedSubCols = isset($_GET['sub_cols']) && is_array($_GET['sub_cols']) 
    ? array_intersect($_GET['sub_cols'], $defaultSubCols) 
    : $defaultSubCols;
if (empty($selectedSubCols)) {
    $selectedSubCols = $defaultSubCols;
}

$sortBy = $_GET['sort'] ?? 'date_desc';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], [25, 50, 100, 250], true) ? (int)$_GET['per_page'] : 50;
$perPage = max(1, $perPage);

/* ===============================
   BUILD WHERE CLAUSE
================================ */
$where = ["1=1"];
$params = [];

// Manager Scope Check: If manager is viewing and doesn't have global reports permission, scope to their affiliates
if (auth_role() === 'manager' && !manager_can('all_reports')) {
    $where[] = "u.account_manager_id = :mgr_scope_id";
    $params['mgr_scope_id'] = $adminUserId;
}

if ($dateFrom && $dateTo) {
    $where[] = "DATE(c.created_at) BETWEEN :dfrom AND :dto";
    $params['dfrom'] = $dateFrom;
    $params['dto']   = $dateTo;
}

if ($search !== '') {
    $where[] = "(c.click_id LIKE :search OR u.name LIKE :search OR u.email LIKE :search OR o.offer_name LIKE :search OR c.sub1 LIKE :search OR c.sub2 LIKE :search OR c.sub3 LIKE :search OR c.sub4 LIKE :search OR c.sub5 LIKE :search OR INET6_NTOA(c.ip_address) LIKE :search)";
    $params['search'] = "%$search%";
}

if ($filterOffer !== 'all') {
    $where[] = "c.offer_id = :oid";
    $params['oid'] = $filterOffer;
}

if ($filterAffiliate !== 'all') {
    $where[] = "c.affiliate_id = :affid";
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

if ($filterDevice !== 'all') {
    $where[] = "c.device = :dev";
    $params['dev'] = $filterDevice;
}

// SubID specific matching
if ($subFilter1 !== '') {
    $where[] = "c.sub1 LIKE :sub1_val";
    $params['sub1_val'] = "%$subFilter1%";
}
if ($subFilter2 !== '') {
    $where[] = "c.sub2 LIKE :sub2_val";
    $params['sub2_val'] = "%$subFilter2%";
}
if ($subFilter3 !== '') {
    $where[] = "c.sub3 LIKE :sub3_val";
    $params['sub3_val'] = "%$subFilter3%";
}
if ($subFilter4 !== '') {
    $where[] = "c.sub4 LIKE :sub4_val";
    $params['sub4_val'] = "%$subFilter4%";
}
if ($subFilter5 !== '') {
    $where[] = "c.sub5 LIKE :sub5_val";
    $params['sub5_val'] = "%$subFilter5%";
}

// Conversion Status Filter
if ($filterStatus === 'converted' || $filterStatus === 'approved') {
    $where[] = "cv.status = 'approved'";
} elseif ($filterStatus === 'pending') {
    $where[] = "cv.status = 'pending'";
} elseif ($filterStatus === 'rejected') {
    $where[] = "cv.status = 'rejected'";
} elseif ($filterStatus === 'unconverted') {
    $where[] = "cv.conversion_id IS NULL";
}

$whereSql = "WHERE " . implode(' AND ', $where);

// Sorting clause
$sortMap = [
    'date_desc' => 'c.created_at DESC',
    'date_asc'  => 'c.created_at ASC',
    'offer_asc' => 'o.offer_name ASC',
    'aff_asc'   => 'u.name ASC',
    'payout_desc' => 'cv.payout DESC'
];
$orderSql = $sortMap[$sortBy] ?? 'c.created_at DESC';

/* ===============================
   CSV / EXCEL EXPORT
================================ */
if (isset($_GET['export'])) {
    $exportFormat = strtolower($_GET['export']);
    $exportSql = "
        SELECT 
            c.click_id,
            c.created_at,
            o.offer_id,
            o.offer_name,
            o.currency,
            u.user_id AS affiliate_id,
            u.name AS affiliate_name,
            u.email AS affiliate_email,
            am.name AS manager_name,
            c.sub1, c.sub2, c.sub3, c.sub4, c.sub5,
            INET6_NTOA(c.ip_address) AS ip_address,
            c.country,
            c.device,
            c.referer,
            cv.conversion_id,
            cv.status AS conversion_status,
            cv.payout AS conversion_payout,
            cv.revenue AS conversion_revenue,
            cv.transaction_id
        FROM clicks c
        INNER JOIN offers o ON o.offer_id = c.offer_id
        INNER JOIN users u ON u.user_id = c.affiliate_id
        LEFT JOIN users am ON am.user_id = u.account_manager_id AND am.role_id = 2
        LEFT JOIN conversions cv ON cv.click_id = c.click_id
        $whereSql
        ORDER BY $orderSql
        LIMIT 10000
    ";

    $expStmt = $pdo->prepare($exportSql);
    $expStmt->execute($params);
    $rows = $expStmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = "click_reports_" . date('Y-m-d_His') . ($exportFormat === 'excel' ? '.xls' : '.csv');

    if ($exportFormat === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "Click ID\tTimestamp\tCampaign ID\tCampaign Name\tCurrency\tPublisher ID\tPublisher Name\tPublisher Email\tAffiliate Manager\tSub ID 1\tSub ID 2\tSub ID 3\tSub ID 4\tSub ID 5\tIP Address\tCountry\tDevice\tConversion Status\tPayout\tRevenue\tTransaction ID\n";
        foreach ($rows as $r) {
            echo implode("\t", [
                $r['click_id'],
                $r['created_at'],
                $r['offer_id'],
                str_replace("\t", ' ', $r['offer_name']),
                $r['currency'] ?: 'USD',
                $r['affiliate_id'],
                str_replace("\t", ' ', $r['affiliate_name']),
                $r['affiliate_email'],
                str_replace("\t", ' ', $r['manager_name'] ?: 'Unassigned'),
                str_replace("\t", ' ', $r['sub1'] ?? ''),
                str_replace("\t", ' ', $r['sub2'] ?? ''),
                str_replace("\t", ' ', $r['sub3'] ?? ''),
                str_replace("\t", ' ', $r['sub4'] ?? ''),
                str_replace("\t", ' ', $r['sub5'] ?? ''),
                $r['ip_address'],
                $r['country'] ?? '',
                $r['device'] ?? '',
                ucfirst($r['conversion_status'] ?: 'Unconverted'),
                number_format((float)($r['conversion_payout'] ?? 0), 2),
                number_format((float)($r['conversion_revenue'] ?? 0), 2),
                $r['transaction_id'] ?? ''
            ]) . "\n";
        }
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Click ID', 'Timestamp', 'Offer ID', 'Offer Name', 'Currency', 'Affiliate ID', 'Affiliate Name', 'Affiliate Email', 'Affiliate Manager', 'Sub ID 1', 'Sub ID 2', 'Sub ID 3', 'Sub ID 4', 'Sub ID 5', 'IP Address', 'Country', 'Device', 'Conversion Status', 'Payout', 'Revenue', 'Transaction ID']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['click_id'],
                $r['created_at'],
                $r['offer_id'],
                $r['offer_name'],
                $r['currency'] ?: 'USD',
                $r['affiliate_id'],
                $r['affiliate_name'],
                $r['affiliate_email'],
                $r['manager_name'] ?: 'Unassigned',
                $r['sub1'] ?? '',
                $r['sub2'] ?? '',
                $r['sub3'] ?? '',
                $r['sub4'] ?? '',
                $r['sub5'] ?? '',
                $r['ip_address'],
                $r['country'] ?? '',
                $r['device'] ?? '',
                ucfirst($r['conversion_status'] ?: 'Unconverted'),
                $r['conversion_payout'] ?? 0,
                $r['conversion_revenue'] ?? 0,
                $r['transaction_id'] ?? ''
            ]);
        }
        fclose($out);
    }
    exit;
}

/* ===============================
   AGGREGATED SUMMARY STATS
================================ */
$statsSql = "
    SELECT 
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT INET6_NTOA(c.ip_address)) AS unique_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN cv.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
        SUM(CASE WHEN cv.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_conversions,

        -- USD Totals
        SUM(CASE WHEN cv.status = 'approved' AND (o.currency IS NULL OR o.currency = 'USD') THEN cv.revenue ELSE 0 END) AS revenue_usd,
        SUM(CASE WHEN cv.status = 'approved' AND (o.currency IS NULL OR o.currency = 'USD') THEN cv.payout ELSE 0 END) AS payout_usd,

        -- INR Totals
        SUM(CASE WHEN cv.status = 'approved' AND o.currency = 'INR' THEN cv.revenue ELSE 0 END) AS revenue_inr,
        SUM(CASE WHEN cv.status = 'approved' AND o.currency = 'INR' THEN cv.payout ELSE 0 END) AS payout_inr

    FROM clicks c
    INNER JOIN offers o ON o.offer_id = c.offer_id
    INNER JOIN users u ON u.user_id = c.affiliate_id
    LEFT JOIN users am ON am.user_id = u.account_manager_id AND am.role_id = 2
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    $whereSql
";

$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute($params);
$kpi = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalClicks = (int)($kpi['total_clicks'] ?? 0);
$uniqueClicks = (int)($kpi['unique_clicks'] ?? 0);
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
   PAGINATED QUERY FOR TABLE
================================ */
$countSql = "
    SELECT COUNT(DISTINCT c.click_id)
    FROM clicks c
    INNER JOIN offers o ON o.offer_id = c.offer_id
    INNER JOIN users u ON u.user_id = c.affiliate_id
    LEFT JOIN users am ON am.user_id = u.account_manager_id AND am.role_id = 2
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    $whereSql
";
$cntStmt = $pdo->prepare($countSql);
$cntStmt->execute($params);
$totalRecords = (int)$cntStmt->fetchColumn();

$totalPages = max(1, ceil($totalRecords / $perPage));
$offset = ($page - 1) * $perPage;

$querySql = "
    SELECT 
        c.click_id,
        c.created_at,
        o.offer_id,
        o.offer_name,
        o.currency,
        u.user_id AS affiliate_id,
        u.name AS affiliate_name,
        u.email AS affiliate_email,
        u.company AS affiliate_company,
        u.account_manager_id,
        am.name AS manager_name,
        c.sub1, c.sub2, c.sub3, c.sub4, c.sub5,
        INET6_NTOA(c.ip_address) AS ip_address,
        c.country,
        c.device,
        c.referer,
        c.user_agent,
        cv.conversion_id,
        cv.status AS conversion_status,
        cv.payout AS conversion_payout,
        cv.revenue AS conversion_revenue,
        cv.transaction_id
    FROM clicks c
    INNER JOIN offers o ON o.offer_id = c.offer_id
    INNER JOIN users u ON u.user_id = c.affiliate_id
    LEFT JOIN users am ON am.user_id = u.account_manager_id AND am.role_id = 2
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    $whereSql
    ORDER BY $orderSql
    LIMIT :offset, :per_page
";

$qStmt = $pdo->prepare($querySql);
foreach ($params as $k => $v) {
    $qStmt->bindValue($k, $v);
}
$qStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$qStmt->bindValue(':per_page', $perPage, PDO::PARAM_INT);
$qStmt->execute();
$clicks = $qStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   DROPDOWN LISTS
================================ */
$allOffers = $pdo->query("SELECT offer_id, offer_name, currency FROM offers ORDER BY offer_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allAffiliates = $pdo->query("SELECT user_id, name, email FROM users WHERE role_id = 3 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allManagers = $pdo->query("SELECT user_id, name FROM users WHERE role_id = 2 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Advanced Click Reports | Admin Console</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
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
        .click-id-badge {
            font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 11.5px;
            background: #f1f5f9;
            color: #0f172a;
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
            display: inline-block;
            word-break: break-all;
            user-select: all;
            cursor: pointer;
            line-height: 1.3;
        }
        .click-id-badge:hover {
            background: #e2e8f0;
            border-color: #94a3b8;
        }
        .subid-tag {
            font-size: 11px;
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 6px;
            border-radius: 4px;
            border: 1px solid #bae6fd;
            display: inline-block;
            max-width: 130px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .filter-panel {
            background: #f8fafc;
            border-radius: 10px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            margin-bottom: 25px;
        }
        .sub-col-checkboxes label {
            margin-right: 15px;
            font-size: 13px;
            cursor: pointer;
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
            <li class="nav-item d-none d-sm-inline-block"><a href="#" class="nav-link active">Advanced Click Reports</a></li>
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
                    <li class="nav-item"><a href="reports_clicks.php" class="nav-link active"><i class="nav-icon fas fa-mouse-pointer"></i><p>Click Reports</p></a></li>
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
                        <h1 class="m-0 font-weight-bold"><i class="fas fa-mouse-pointer text-primary mr-2"></i>Advanced Click Reports</h1>
                        <p class="text-muted small mb-0">Inspect granular date-wise click transactions, SubID tracking parameters & affiliate manager associations.</p>
                    </div>
                    <div class="col-sm-6 text-right">
                        <!-- Export Actions -->
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

                <!-- Summary Stat Cards -->
                <div class="row mb-3">
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
                            <small class="text-muted d-block mt-1"><?php echo number_format($cr, 2); ?>% Conversion Rate</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning"><?php echo number_format($pendingConvs); ?> / <?php echo number_format($rejectedConvs); ?></div>
                            <div class="stat-label">Pending / Rejected</div>
                            <small class="text-muted d-block mt-1">Under verification</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info" style="font-size: 19px;">
                                <?php echo format_dual_currency($revUSD, $revINR); ?>
                            </div>
                            <div class="stat-label">Gross Revenue</div>
                            <small class="text-muted d-block mt-1">USD & INR</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning" style="font-size: 19px;">
                                <?php echo format_dual_currency($payUSD, $payINR); ?>
                            </div>
                            <div class="stat-label">Publisher Payout</div>
                            <small class="text-muted d-block mt-1">Commissions Due</small>
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success" style="font-size: 19px;">
                                <?php echo format_dual_currency($profitUSD, $profitINR); ?>
                            </div>
                            <div class="stat-label">Network Profit</div>
                            <small class="text-muted d-block mt-1">Net Margin</small>
                        </div>
                    </div>
                </div>

                <!-- Comprehensive Multi-Select Filter Panel -->
                <div class="filter-panel card-custom p-4">
                    <form method="get">
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
                                <label class="font-weight-bold small text-muted text-uppercase">Publisher / Affiliate</label>
                                <select name="affiliate_id" class="form-control select2">
                                    <option value="all">All Publishers</option>
                                    <?php foreach ($allAffiliates as $af): ?>
                                    <option value="<?php echo $af['user_id']; ?>" <?php echo $filterAffiliate == $af['user_id'] ? 'selected' : ''; ?>>
                                        #<?php echo $af['user_id']; ?> - <?php echo htmlspecialchars($af['name']); ?> (<?php echo htmlspecialchars($af['email']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Affiliate Manager</label>
                                <select name="manager_id" class="form-control select2">
                                    <option value="all">All Account Managers</option>
                                    <option value="unassigned" <?php echo $filterManager === 'unassigned' ? 'selected' : ''; ?>>Unassigned (No Manager)</option>
                                    <?php foreach ($allManagers as $am): ?>
                                    <option value="<?php echo $am['user_id']; ?>" <?php echo $filterManager == $am['user_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($am['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Conversion Status</label>
                                <select name="conversion_status" class="form-control select2">
                                    <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Clicks (Converted & Unconverted)</option>
                                    <option value="approved" <?php echo $filterStatus === 'approved' ? 'selected' : ''; ?>>Approved Leads Only</option>
                                    <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending Leads Only</option>
                                    <option value="rejected" <?php echo $filterStatus === 'rejected' ? 'selected' : ''; ?>>Rejected Leads Only</option>
                                    <option value="unconverted" <?php echo $filterStatus === 'unconverted' ? 'selected' : ''; ?>>Unconverted Clicks Only</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Device Type</label>
                                <select name="device" class="form-control select2">
                                    <option value="all" <?php echo $filterDevice === 'all' ? 'selected' : ''; ?>>All Devices</option>
                                    <option value="mobile" <?php echo $filterDevice === 'mobile' ? 'selected' : ''; ?>>Mobile</option>
                                    <option value="desktop" <?php echo $filterDevice === 'desktop' ? 'selected' : ''; ?>>Desktop</option>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="font-weight-bold small text-muted text-uppercase">Quick Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Search Click ID, SubID, IP, Publisher..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                        </div>

                        <!-- SubID Multi-Select & Search Row (Requirement 2) -->
                        <div class="bg-white p-3 rounded border mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
                                <div>
                                    <label class="font-weight-bold text-dark mb-0"><i class="fas fa-tags text-primary mr-1"></i> Sub ID Columns to Display (All selected by default):</label>
                                    <small class="text-muted d-block">Select or deselect individual Sub IDs to customize your report layout.</small>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-xs btn-outline-primary mr-1" onclick="toggleSubCols(true)">Select All</button>
                                    <button type="button" class="btn btn-xs btn-outline-secondary" onclick="toggleSubCols(false)">Clear All</button>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap sub-col-checkboxes mb-3">
                                <?php for ($s = 1; $s <= 5; $s++): 
                                    $colKey = "sub{$s}";
                                    $isChecked = in_array($colKey, $selectedSubCols, true);
                                ?>
                                <div class="custom-control custom-checkbox mr-4">
                                    <input type="checkbox" class="custom-control-input sub-col-cb" id="cb_<?php echo $colKey; ?>" name="sub_cols[]" value="<?php echo $colKey; ?>" <?php echo $isChecked ? 'checked' : ''; ?>>
                                    <label class="custom-control-label font-weight-bold" for="cb_<?php echo $colKey; ?>">Sub ID <?php echo $s; ?></label>
                                </div>
                                <?php endfor; ?>
                            </div>

                            <!-- Specific SubID Value Filters -->
                            <div class="row">
                                <div class="col-md-2 col-6 mb-2">
                                    <input type="text" name="sub1" class="form-control form-control-sm" placeholder="Filter Sub ID 1" value="<?php echo htmlspecialchars($subFilter1); ?>">
                                </div>
                                <div class="col-md-2 col-6 mb-2">
                                    <input type="text" name="sub2" class="form-control form-control-sm" placeholder="Filter Sub ID 2" value="<?php echo htmlspecialchars($subFilter2); ?>">
                                </div>
                                <div class="col-md-2 col-6 mb-2">
                                    <input type="text" name="sub3" class="form-control form-control-sm" placeholder="Filter Sub ID 3" value="<?php echo htmlspecialchars($subFilter3); ?>">
                                </div>
                                <div class="col-md-2 col-6 mb-2">
                                    <input type="text" name="sub4" class="form-control form-control-sm" placeholder="Filter Sub ID 4" value="<?php echo htmlspecialchars($subFilter4); ?>">
                                </div>
                                <div class="col-md-2 col-6 mb-2">
                                    <input type="text" name="sub5" class="form-control form-control-sm" placeholder="Filter Sub ID 5" value="<?php echo htmlspecialchars($subFilter5); ?>">
                                </div>
                                <div class="col-md-2 col-12 mb-2">
                                    <select name="sort" class="form-control form-control-sm">
                                        <option value="date_desc" <?php echo $sortBy === 'date_desc' ? 'selected' : ''; ?>>Date (Newest)</option>
                                        <option value="date_asc" <?php echo $sortBy === 'date_asc' ? 'selected' : ''; ?>>Date (Oldest)</option>
                                        <option value="payout_desc" <?php echo $sortBy === 'payout_desc' ? 'selected' : ''; ?>>Payout (High to Low)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted small">Showing <strong><?php echo number_format($totalRecords); ?></strong> total tracking records found</span>
                            </div>
                            <div>
                                <button type="submit" class="btn btn-primary font-weight-bold px-4 shadow-sm mr-2">
                                    <i class="fas fa-filter mr-1"></i> Generate Report
                                </button>
                                <a href="reports_clicks.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-redo mr-1"></i> Reset All
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Click Logs Data Table -->
                <div class="card card-custom p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="clicksTable" style="font-size: 13px;">
                            <thead class="thead-light">
                                <tr>
                                    <th>Click ID / Timestamp</th>
                                    <th>Campaign</th>
                                    <th>Affiliate / Publisher</th>
                                    <th>Affiliate Manager</th>
                                    <?php if (in_array('sub1', $selectedSubCols, true)): ?><th>Sub ID 1</th><?php endif; ?>
                                    <?php if (in_array('sub2', $selectedSubCols, true)): ?><th>Sub ID 2</th><?php endif; ?>
                                    <?php if (in_array('sub3', $selectedSubCols, true)): ?><th>Sub ID 3</th><?php endif; ?>
                                    <?php if (in_array('sub4', $selectedSubCols, true)): ?><th>Sub ID 4</th><?php endif; ?>
                                    <?php if (in_array('sub5', $selectedSubCols, true)): ?><th>Sub ID 5</th><?php endif; ?>
                                    <th>Status & Value</th>
                                    <th>Device / Country / IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($clicks)): ?>
                                <tr>
                                    <td colspan="12" class="text-center text-muted py-5">
                                        <i class="fas fa-search fa-2x mb-2 d-block"></i>
                                        No tracking clicks found matching the selected filter criteria.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($clicks as $c): 
                                    $isConverted = !empty($c['conversion_id']);
                                    $cStatus = strtolower($c['conversion_status'] ?? 'none');
                                    $cur = $c['currency'] ?: 'USD';
                                ?>
                                <tr>
                                    <td>
                                        <span class="click-id-badge" title="Click to copy full Click ID" onclick="copyToClipboard('<?php echo htmlspecialchars($c['click_id']); ?>')">
                                            <i class="fas fa-fingerprint text-primary mr-1"></i><?php echo htmlspecialchars($c['click_id']); ?>
                                        </span>
                                        <small class="text-muted d-block mt-1">
                                            <i class="far fa-clock mr-1"></i><?php echo date('M d, Y H:i:s', strtotime($c['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <a href="offer_details.php?id=<?php echo $c['offer_id']; ?>" class="font-weight-bold text-dark">
                                            #<?php echo $c['offer_id']; ?> - <?php echo htmlspecialchars($c['offer_name']); ?>
                                        </a>
                                        <span class="badge badge-light border ml-1"><?php echo htmlspecialchars($cur); ?></span>
                                    </td>
                                    <td>
                                        <a href="affiliate_details.php?id=<?php echo $c['affiliate_id']; ?>" class="font-weight-bold text-primary">
                                            <?php echo htmlspecialchars($c['affiliate_name']); ?>
                                        </a>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($c['affiliate_email']); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($c['account_manager_id']) && !empty($c['manager_name'])): ?>
                                            <a href="manager_profile.php?id=<?php echo $c['account_manager_id']; ?>" class="badge badge-light border text-primary" data-toggle="tooltip" title="Click to view Affiliate Manager Profile">
                                                <i class="fas fa-user-tie text-primary mr-1"></i><?php echo htmlspecialchars($c['manager_name']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="badge badge-secondary">Unassigned</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Dynamic Sub ID Columns -->
                                    <?php if (in_array('sub1', $selectedSubCols, true)): ?>
                                    <td>
                                        <?php if (!empty($c['sub1'])): ?>
                                            <span class="subid-tag" title="<?php echo htmlspecialchars($c['sub1']); ?>"><?php echo htmlspecialchars($c['sub1']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>

                                    <?php if (in_array('sub2', $selectedSubCols, true)): ?>
                                    <td>
                                        <?php if (!empty($c['sub2'])): ?>
                                            <span class="subid-tag" title="<?php echo htmlspecialchars($c['sub2']); ?>"><?php echo htmlspecialchars($c['sub2']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>

                                    <?php if (in_array('sub3', $selectedSubCols, true)): ?>
                                    <td>
                                        <?php if (!empty($c['sub3'])): ?>
                                            <span class="subid-tag" title="<?php echo htmlspecialchars($c['sub3']); ?>"><?php echo htmlspecialchars($c['sub3']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>

                                    <?php if (in_array('sub4', $selectedSubCols, true)): ?>
                                    <td>
                                        <?php if (!empty($c['sub4'])): ?>
                                            <span class="subid-tag" title="<?php echo htmlspecialchars($c['sub4']); ?>"><?php echo htmlspecialchars($c['sub4']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>

                                    <?php if (in_array('sub5', $selectedSubCols, true)): ?>
                                    <td>
                                        <?php if (!empty($c['sub5'])): ?>
                                            <span class="subid-tag" title="<?php echo htmlspecialchars($c['sub5']); ?>"><?php echo htmlspecialchars($c['sub5']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>

                                    <td>
                                        <?php if ($cStatus === 'approved'): ?>
                                            <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i>Approved Lead</span>
                                            <div class="small font-weight-bold text-success mt-1">Payout: <?php echo format_currency($c['conversion_payout'], $cur); ?></div>
                                        <?php elseif ($cStatus === 'pending'): ?>
                                            <span class="badge badge-warning px-2 py-1"><i class="fas fa-clock mr-1"></i>Pending</span>
                                        <?php elseif ($cStatus === 'rejected'): ?>
                                            <span class="badge badge-danger px-2 py-1"><i class="fas fa-times-circle mr-1"></i>Rejected</span>
                                        <?php else: ?>
                                            <span class="badge badge-secondary px-2 py-1">Click Only</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div>
                                            <span class="badge badge-light border">
                                                <i class="fas fa-<?php echo $c['device'] === 'mobile' ? 'mobile-alt' : 'desktop'; ?> mr-1"></i>
                                                <?php echo ucfirst($c['device']); ?>
                                            </span>
                                            <?php if (!empty($c['country'])): ?>
                                            <span class="badge badge-info ml-1"><?php echo htmlspecialchars($c['country']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted d-block mt-1 font-monospace"><?php echo htmlspecialchars($c['ip_address']); ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center mt-4 flex-wrap">
                        <div class="text-muted small">
                            Showing page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo number_format($totalRecords); ?> total records)
                        </div>
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">&laquo; First</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Prev</a>
                            </li>
                            <?php endif; ?>

                            <?php 
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for ($p = $startPage; $p <= $endPage; $p++): 
                            ?>
                            <li class="page-item <?php echo $p == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"><?php echo $p; ?></a>
                            </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>">Last &raquo;</a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
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

<script>
$(document).ready(function() {
    $('.select2').select2({ theme: 'bootstrap4', width: '100%' });
    $('[data-toggle="tooltip"]').tooltip();
});

function toggleSubCols(state) {
    $('.sub-col-cb').prop('checked', state);
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        alert('Click ID copied: ' + text);
    });
}
</script>
</body>
</html>

