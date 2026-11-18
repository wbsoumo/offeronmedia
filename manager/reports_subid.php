<?php
/**
 * Manager Portal - SubID Performance Analytics Report
 * Displays all Sub IDs (sub1 through sub5) in separate distinct columns.
 * Scoped strictly to the logged-in manager's assigned publishers.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_any_role(['manager', 'admin']);

$managerId   = auth_user_id();
$managerName = $_SESSION['user_name'] ?? 'Account Manager';
$canAllScope = manager_can('all_reports') || auth_role() === 'admin';
$canFinancials = manager_can('can_view_financials') || manager_can('view_financials') || auth_role() === 'admin';

/* ===============================
   FILTERS & PARAMETERS
================================ */
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$search   = trim($_GET['search'] ?? '');
$filterOffer = isset($_GET['offer_id']) && $_GET['offer_id'] !== 'all' ? (int)$_GET['offer_id'] : 'all';
$filterAffiliate = isset($_GET['affiliate_id']) && $_GET['affiliate_id'] !== 'all' ? (int)$_GET['affiliate_id'] : 'all';

// Simultaneous Multi-SubID Filters
$subFilter1 = trim($_GET['sub1'] ?? '');
$subFilter2 = trim($_GET['sub2'] ?? '');
$subFilter3 = trim($_GET['sub3'] ?? '');
$subFilter4 = trim($_GET['sub4'] ?? '');
$subFilter5 = trim($_GET['sub5'] ?? '');

$sortBy = $_GET['sort'] ?? 'payout_desc';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], [25, 50, 100, 250], true) ? (int)$_GET['per_page'] : 50;
$perPage = max(1, $perPage);

/* ===============================
   BUILD WHERE CLAUSE
================================ */
$where = ["aff.role_id = 3"];
$params = [];

if (!$canAllScope) {
    $where[] = "aff.account_manager_id = :mgr_id";
    $params[':mgr_id'] = $managerId;
}

if ($dateFrom && $dateTo) {
    $where[] = "DATE(c.created_at) BETWEEN :dfrom AND :dto";
    $params[':dfrom'] = $dateFrom;
    $params[':dto']   = $dateTo;
}

if ($filterOffer !== 'all') {
    $where[] = "c.offer_id = :oid";
    $params[':oid'] = $filterOffer;
}

if ($filterAffiliate !== 'all') {
    $where[] = "c.affiliate_id = :affid";
    $params[':affid'] = $filterAffiliate;
}

if ($subFilter1 !== '') {
    $where[] = "c.sub1 LIKE :sub1_val";
    $params[':sub1_val'] = "%$subFilter1%";
}
if ($subFilter2 !== '') {
    $where[] = "c.sub2 LIKE :sub2_val";
    $params[':sub2_val'] = "%$subFilter2%";
}
if ($subFilter3 !== '') {
    $where[] = "c.sub3 LIKE :sub3_val";
    $params[':sub3_val'] = "%$subFilter3%";
}
if ($subFilter4 !== '') {
    $where[] = "c.sub4 LIKE :sub4_val";
    $params[':sub4_val'] = "%$subFilter4%";
}
if ($subFilter5 !== '') {
    $where[] = "c.sub5 LIKE :sub5_val";
    $params[':sub5_val'] = "%$subFilter5%";
}

if ($search !== '') {
    $where[] = "(c.sub1 LIKE :s1 OR c.sub2 LIKE :s2 OR c.sub3 LIKE :s3 OR c.sub4 LIKE :s4 OR c.sub5 LIKE :s5 OR o.offer_name LIKE :s6 OR aff.name LIKE :s7)";
    for ($si = 1; $si <= 7; $si++) {
        $params[":s{$si}"] = "%$search%";
    }
}

$whereSql = "WHERE " . implode(' AND ', $where);

// Sorting
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
            c.sub1, c.sub2, c.sub3, c.sub4, c.sub5,
            o.offer_name, o.offer_id, o.currency,
            aff.name AS affiliate_name, aff.user_id AS affiliate_id,
            COUNT(DISTINCT c.click_id) AS total_clicks,
            COUNT(DISTINCT cv.conversion_id) AS total_conversions,
            SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
            SUM(CASE WHEN cv.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
            SUM(CASE WHEN cv.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_conversions,
            IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS total_revenue,
            IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_payout,
            (IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) - IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0)) AS net_profit
        FROM clicks c
        INNER JOIN offers o ON o.offer_id = c.offer_id
        INNER JOIN users aff ON aff.user_id = c.affiliate_id
        LEFT JOIN conversions cv ON cv.click_id = c.click_id
        $whereSql
        GROUP BY c.sub1, c.sub2, c.sub3, c.sub4, c.sub5, c.offer_id, c.affiliate_id
        ORDER BY $sortClause
        LIMIT 5000
    ";
    $expStmt = $pdo->prepare($expSql);
    $expStmt->execute($params);
    $expRows = $expStmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = "manager_subid_report_" . date('Y-m-d') . ($exportFormat === 'excel' ? '.xls' : '.csv');

    if ($exportFormat === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "Campaign Offer\tPublisher\tSub ID 1\tSub ID 2\tSub ID 3\tSub ID 4\tSub ID 5\tClicks\tTotal Leads\tApproved Leads\tPending Leads\tRejected Leads\tTotal Payout\tGross Revenue\tNet Profit\tCR %\n";
        foreach ($expRows as $r) {
            $rClicks = (int)$r['total_clicks'];
            $rConv   = (int)$r['approved_conversions'];
            $rCr     = $rClicks > 0 ? number_format(($rConv / $rClicks) * 100, 2) : '0.00';
            echo implode("\t", [
                "#" . $r['offer_id'] . " - " . str_replace("\t", " ", $r['offer_name']),
                str_replace("\t", " ", $r['affiliate_name']),
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
                number_format((float)$r['total_payout'], 2),
                number_format((float)$r['total_revenue'], 2),
                number_format((float)$r['net_profit'], 2),
                $rCr . '%'
            ]) . "\n";
        }
        exit;
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Campaign Offer', 'Publisher', 'Sub ID 1', 'Sub ID 2', 'Sub ID 3', 'Sub ID 4', 'Sub ID 5', 'Clicks', 'Total Leads', 'Approved Leads', 'Pending Leads', 'Rejected Leads', 'Total Payout', 'Gross Revenue', 'Net Profit', 'CR %']);
        foreach ($expRows as $r) {
            $rClicks = (int)$r['total_clicks'];
            $rConv   = (int)$r['approved_conversions'];
            $rCr     = $rClicks > 0 ? number_format(($rConv / $rClicks) * 100, 2) : '0.00';
            fputcsv($out, [
                "#" . $r['offer_id'] . " - " . $r['offer_name'],
                $r['affiliate_name'],
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
                number_format((float)$r['total_payout'], 2),
                number_format((float)$r['total_revenue'], 2),
                number_format((float)$r['net_profit'], 2),
                $rCr . '%'
            ]);
        }
        fclose($out);
        exit;
    }
}

/* ===============================
   PAGINATION & AGGREGATE STATS
================================ */
$countSql = "
    SELECT COUNT(*) FROM (
        SELECT 1
        FROM clicks c
        INNER JOIN offers o ON o.offer_id = c.offer_id
        INNER JOIN users aff ON aff.user_id = c.affiliate_id
        LEFT JOIN conversions cv ON cv.click_id = c.click_id
        $whereSql
        GROUP BY c.sub1, c.sub2, c.sub3, c.sub4, c.sub5, c.offer_id, c.affiliate_id
    ) AS total_count_table
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

// Overall Aggregated Totals
$totalsSql = "
    SELECT 
        COUNT(DISTINCT c.click_id) AS grand_clicks,
        COUNT(DISTINCT cv.conversion_id) AS grand_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS grand_approved,
        SUM(CASE WHEN cv.status = 'pending' THEN 1 ELSE 0 END) AS grand_pending,
        SUM(CASE WHEN cv.status = 'rejected' THEN 1 ELSE 0 END) AS grand_rejected,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS grand_revenue,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS grand_payout
    FROM clicks c
    INNER JOIN offers o ON o.offer_id = c.offer_id
    INNER JOIN users aff ON aff.user_id = c.affiliate_id
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    $whereSql
";
$totStmt = $pdo->prepare($totalsSql);
$totStmt->execute($params);
$grandTotals = $totStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$grandClicks   = (int)($grandTotals['grand_clicks'] ?? 0);
$grandApproved = (int)($grandTotals['grand_approved'] ?? 0);
$grandPending  = (int)($grandTotals['grand_pending'] ?? 0);
$grandRevenue  = (float)($grandTotals['grand_revenue'] ?? 0);
$grandPayout   = (float)($grandTotals['grand_payout'] ?? 0);
$grandProfit   = $grandRevenue - $grandPayout;
$grandCr       = $grandClicks > 0 ? ($grandApproved / $grandClicks) * 100 : 0.00;

// Fetch Paginated SubID Records
$dataSql = "
    SELECT 
        c.sub1, c.sub2, c.sub3, c.sub4, c.sub5,
        o.offer_name, o.offer_id, o.currency,
        aff.name AS affiliate_name, aff.user_id AS affiliate_id,
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        SUM(CASE WHEN cv.status = 'pending' THEN 1 ELSE 0 END) AS pending_conversions,
        SUM(CASE WHEN cv.status = 'rejected' THEN 1 ELSE 0 END) AS rejected_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS total_revenue,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_payout,
        (IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) - IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0)) AS net_profit
    FROM clicks c
    INNER JOIN offers o ON o.offer_id = c.offer_id
    INNER JOIN users aff ON aff.user_id = c.affiliate_id
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    $whereSql
    GROUP BY c.sub1, c.sub2, c.sub3, c.sub4, c.sub5, c.offer_id, c.affiliate_id
    ORDER BY $sortClause
    LIMIT :offset, :per_page
";
$stmt = $pdo->prepare($dataSql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', (int)$perPage, PDO::PARAM_INT);
$stmt->execute();
$reportRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH FILTER DROPDOWN OPTIONS
================================ */
$offersList = $pdo->query("SELECT offer_id, offer_name FROM offers ORDER BY offer_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$affScopeSql = !$canAllScope ? "WHERE role_id = 3 AND account_manager_id = " . (int)$managerId : "WHERE role_id = 3";
$affiliatesList = $pdo->query("SELECT user_id, name FROM users $affScopeSql ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SubID Performance Analytics | Manager Portal</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">

    <style>
        .brand-link { background: #1f2d3d; }
        .main-sidebar { background: #222d32; }
        .card-custom {
            border-radius: 10px;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            background: #ffffff;
        }
        .stat-card-custom {
            border-radius: 10px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 16px 20px;
            background: #fff;
            margin-bottom: 15px;
            transition: transform 0.2s;
        }
        .stat-card-custom:hover { transform: translateY(-2px); }
        .stat-title { font-size: 11px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; color: #8898aa; }
        .stat-value { font-size: 24px; font-weight: 800; color: #32325d; margin-top: 4px; }
        .subid-tag {
            font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 11.5px;
            padding: 3px 8px;
            border-radius: 6px;
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #e2e8f0;
            display: inline-block;
            max-width: 130px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .subid-tag.empty {
            background: transparent;
            border: 1px dashed #cbd5e1;
            color: #94a3b8;
        }
        .table thead th {
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 700;
            color: #475569;
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
            vertical-align: middle;
        }
        .table td { vertical-align: middle; font-size: 13px; }
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
            <li class="nav-item d-none d-sm-inline-block"><a href="reports.php" class="nav-link">Performance Reports</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="reports_subid.php" class="nav-link active font-weight-bold">SubID Analytics</a></li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <span class="nav-link text-muted"><i class="fas fa-user-tie text-info mr-1"></i> <?php echo htmlspecialchars($managerName); ?></span>
            </li>
            <li class="nav-item">
                <a class="nav-link text-danger" href="../logout.php" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
            </li>
        </ul>
    </nav>

    <!-- Sidebar Partial -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 font-weight-bold text-dark">
                            <i class="fas fa-tags text-warning mr-2"></i>SubID Wise Performance Report
                        </h1>
                        <small class="text-muted">Breakdown of traffic and leads with each Sub ID (sub1 to sub5) in distinct columns.</small>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="reports.php">Reports</a></li>
                            <li class="breadcrumb-item active">SubID Performance</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <!-- KPI Metric Cards -->
                <div class="row">
                    <div class="col-lg-3 col-6">
                        <div class="stat-card-custom border-left border-info" style="border-left-width: 4px !important;">
                            <div class="stat-title"><i class="fas fa-mouse-pointer mr-1"></i> Total Traffic Clicks</div>
                            <div class="stat-value text-info"><?php echo number_format($grandClicks); ?></div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-6">
                        <div class="stat-card-custom border-left border-success" style="border-left-width: 4px !important;">
                            <div class="stat-title"><i class="fas fa-check-circle mr-1"></i> Approved Leads</div>
                            <div class="stat-value text-success"><?php echo number_format($grandApproved); ?></div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-6">
                        <div class="stat-card-custom border-left border-primary" style="border-left-width: 4px !important;">
                            <div class="stat-title"><i class="fas fa-percentage mr-1"></i> Average CR %</div>
                            <div class="stat-value text-primary"><?php echo number_format($grandCr, 2); ?>%</div>
                        </div>
                    </div>
                    <div class="col-lg-2 col-6">
                        <div class="stat-card-custom border-left border-warning" style="border-left-width: 4px !important;">
                            <div class="stat-title"><i class="fas fa-wallet mr-1"></i> Publisher Payout</div>
                            <div class="stat-value text-warning"><?php echo format_currency($grandPayout, 'USD'); ?></div>
                        </div>
                    </div>
                    <?php if ($canFinancials): ?>
                    <div class="col-lg-2 col-12">
                        <div class="stat-card-custom border-left border-purple" style="border-left-width: 4px !important; border-left-color: #6f42c1 !important;">
                            <div class="stat-title"><i class="fas fa-chart-line mr-1"></i> Net Margin</div>
                            <div class="stat-value text-purple" style="color: #6f42c1;"><?php echo format_currency($grandProfit, 'USD'); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Filters & Search Form -->
                <div class="card card-custom p-3">
                    <form method="get" action="reports_subid.php" id="filterForm">
                        <div class="row">
                            <div class="col-md-2 col-sm-6 mb-2">
                                <label class="small font-weight-bold">From Date</label>
                                <input type="date" name="from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom); ?>">
                            </div>
                            <div class="col-md-2 col-sm-6 mb-2">
                                <label class="small font-weight-bold">To Date</label>
                                <input type="date" name="to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo); ?>">
                            </div>
                            <div class="col-md-2 col-sm-6 mb-2">
                                <label class="small font-weight-bold">Campaign Offer</label>
                                <select name="offer_id" class="form-control form-control-sm">
                                    <option value="all">All Campaigns</option>
                                    <?php foreach ($offersList as $off): ?>
                                        <option value="<?php echo $off['offer_id']; ?>" <?php echo $filterOffer == $off['offer_id'] ? 'selected' : ''; ?>>
                                            #<?php echo $off['offer_id']; ?> - <?php echo htmlspecialchars($off['offer_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 col-sm-6 mb-2">
                                <label class="small font-weight-bold">Publisher</label>
                                <select name="affiliate_id" class="form-control form-control-sm">
                                    <option value="all">All Publishers</option>
                                    <?php foreach ($affiliatesList as $aff): ?>
                                        <option value="<?php echo $aff['user_id']; ?>" <?php echo $filterAffiliate == $aff['user_id'] ? 'selected' : ''; ?>>
                                            #<?php echo $aff['user_id']; ?> - <?php echo htmlspecialchars($aff['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 col-sm-6 mb-2">
                                <label class="small font-weight-bold">Sort By</label>
                                <select name="sort" class="form-control form-control-sm">
                                    <option value="payout_desc" <?php echo $sortBy === 'payout_desc' ? 'selected' : ''; ?>>Payout (High to Low)</option>
                                    <option value="clicks_desc" <?php echo $sortBy === 'clicks_desc' ? 'selected' : ''; ?>>Clicks (High to Low)</option>
                                    <option value="conv_desc" <?php echo $sortBy === 'conv_desc' ? 'selected' : ''; ?>>Conversions (High to Low)</option>
                                    <option value="revenue_desc" <?php echo $sortBy === 'revenue_desc' ? 'selected' : ''; ?>>Revenue (High to Low)</option>
                                </select>
                            </div>
                            <div class="col-md-2 col-sm-6 mb-2">
                                <label class="small font-weight-bold">Per Page</label>
                                <select name="per_page" class="form-control form-control-sm">
                                    <option value="25" <?php echo $perPage === 25 ? 'selected' : ''; ?>>25 records</option>
                                    <option value="50" <?php echo $perPage === 50 ? 'selected' : ''; ?>>50 records</option>
                                    <option value="100" <?php echo $perPage === 100 ? 'selected' : ''; ?>>100 records</option>
                                    <option value="250" <?php echo $perPage === 250 ? 'selected' : ''; ?>>250 records</option>
                                </select>
                            </div>
                        </div>

                        <!-- SubID Granular Search Row -->
                        <div class="row pt-2 border-top">
                            <div class="col-md-2 col-sm-4 col-6 mb-2">
                                <label class="small font-weight-bold text-muted">Sub ID 1</label>
                                <input type="text" name="sub1" class="form-control form-control-sm" placeholder="Filter sub1" value="<?php echo htmlspecialchars($subFilter1); ?>">
                            </div>
                            <div class="col-md-2 col-sm-4 col-6 mb-2">
                                <label class="small font-weight-bold text-muted">Sub ID 2</label>
                                <input type="text" name="sub2" class="form-control form-control-sm" placeholder="Filter sub2" value="<?php echo htmlspecialchars($subFilter2); ?>">
                            </div>
                            <div class="col-md-2 col-sm-4 col-6 mb-2">
                                <label class="small font-weight-bold text-muted">Sub ID 3</label>
                                <input type="text" name="sub3" class="form-control form-control-sm" placeholder="Filter sub3" value="<?php echo htmlspecialchars($subFilter3); ?>">
                            </div>
                            <div class="col-md-2 col-sm-4 col-6 mb-2">
                                <label class="small font-weight-bold text-muted">Sub ID 4</label>
                                <input type="text" name="sub4" class="form-control form-control-sm" placeholder="Filter sub4" value="<?php echo htmlspecialchars($subFilter4); ?>">
                            </div>
                            <div class="col-md-2 col-sm-4 col-6 mb-2">
                                <label class="small font-weight-bold text-muted">Sub ID 5</label>
                                <input type="text" name="sub5" class="form-control form-control-sm" placeholder="Filter sub5" value="<?php echo htmlspecialchars($subFilter5); ?>">
                            </div>
                            <div class="col-md-2 col-sm-4 col-12 mb-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-sm btn-primary btn-block font-weight-bold mr-1">
                                    <i class="fas fa-filter mr-1"></i> Filter
                                </button>
                                <a href="reports_subid.php" class="btn btn-sm btn-outline-secondary" title="Reset Filters">
                                    <i class="fas fa-undo"></i>
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Report Table Card -->
                <div class="card card-custom p-0">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                        <h5 class="card-title font-weight-bold m-0 text-dark">
                            <i class="fas fa-table mr-2 text-warning"></i>SubID Report Table 
                            <span class="badge badge-light border ml-2"><?php echo number_format($totalRows); ?> Total Entries</span>
                        </h5>
                        <div>
                            <?php 
                            $qParams = $_GET;
                            $qParams['export'] = 'csv';
                            $csvUrl = '?' . http_build_query($qParams);
                            $qParams['export'] = 'excel';
                            $xlsUrl = '?' . http_build_query($qParams);
                            ?>
                            <a href="<?php echo htmlspecialchars($csvUrl); ?>" class="btn btn-sm btn-outline-success font-weight-bold mr-1">
                                <i class="fas fa-file-csv mr-1"></i> Export CSV
                            </a>
                            <a href="<?php echo htmlspecialchars($xlsUrl); ?>" class="btn btn-sm btn-outline-primary font-weight-bold">
                                <i class="fas fa-file-excel mr-1"></i> Export Excel
                            </a>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Campaign Offer</th>
                                    <th>Publisher</th>
                                    <th>Sub ID 1</th>
                                    <th>Sub ID 2</th>
                                    <th>Sub ID 3</th>
                                    <th>Sub ID 4</th>
                                    <th>Sub ID 5</th>
                                    <th class="text-center">Clicks</th>
                                    <th class="text-center">Approved</th>
                                    <th class="text-center">Pending</th>
                                    <th class="text-center">CR %</th>
                                    <th class="text-right">Payout</th>
                                    <?php if ($canFinancials): ?>
                                    <th class="text-right">Revenue</th>
                                    <th class="text-right">Net Profit</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($reportRows)): ?>
                                <tr>
                                    <td colspan="<?php echo $canFinancials ? '14' : '12'; ?>" class="text-center text-muted py-5">
                                        <i class="fas fa-tags fa-3x mb-3 text-secondary d-block"></i>
                                        <h5 class="font-weight-bold">No SubID records found</h5>
                                        <p class="small text-muted mb-0">Try adjusting your date range or filters above.</p>
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($reportRows as $row): 
                                    $rClicks = (int)$row['total_clicks'];
                                    $rConv   = (int)$row['approved_conversions'];
                                    $rPend   = (int)$row['pending_conversions'];
                                    $rCr     = $rClicks > 0 ? round(($rConv / $rClicks) * 100, 2) : 0;
                                    $cur     = $row['currency'] ?? 'USD';
                                ?>
                                <tr>
                                    <td>
                                        <a href="campaigns.php?search=<?php echo urlencode($row['offer_name']); ?>" class="font-weight-bold text-dark">
                                            #<?php echo (int)$row['offer_id']; ?> - <?php echo htmlspecialchars($row['offer_name']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <strong class="text-primary"><?php echo htmlspecialchars($row['affiliate_name']); ?></strong>
                                        <small class="d-block text-muted">ID: #<?php echo (int)$row['affiliate_id']; ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['sub1'])): ?>
                                            <span class="subid-tag font-weight-bold" title="<?php echo htmlspecialchars($row['sub1']); ?>"><?php echo htmlspecialchars($row['sub1']); ?></span>
                                        <?php else: ?>
                                            <span class="subid-tag empty">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['sub2'])): ?>
                                            <span class="subid-tag font-weight-bold" title="<?php echo htmlspecialchars($row['sub2']); ?>"><?php echo htmlspecialchars($row['sub2']); ?></span>
                                        <?php else: ?>
                                            <span class="subid-tag empty">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['sub3'])): ?>
                                            <span class="subid-tag font-weight-bold" title="<?php echo htmlspecialchars($row['sub3']); ?>"><?php echo htmlspecialchars($row['sub3']); ?></span>
                                        <?php else: ?>
                                            <span class="subid-tag empty">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['sub4'])): ?>
                                            <span class="subid-tag font-weight-bold" title="<?php echo htmlspecialchars($row['sub4']); ?>"><?php echo htmlspecialchars($row['sub4']); ?></span>
                                        <?php else: ?>
                                            <span class="subid-tag empty">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['sub5'])): ?>
                                            <span class="subid-tag font-weight-bold" title="<?php echo htmlspecialchars($row['sub5']); ?>"><?php echo htmlspecialchars($row['sub5']); ?></span>
                                        <?php else: ?>
                                            <span class="subid-tag empty">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center font-weight-bold"><?php echo number_format($rClicks); ?></td>
                                    <td class="text-center font-weight-bold text-success"><?php echo number_format($rConv); ?></td>
                                    <td class="text-center text-muted"><?php echo number_format($rPend); ?></td>
                                    <td class="text-center font-weight-bold text-info"><?php echo $rCr; ?>%</td>
                                    <td class="text-right font-weight-bold text-dark"><?php echo format_currency((float)$row['total_payout'], $cur); ?></td>
                                    <?php if ($canFinancials): ?>
                                    <td class="text-right font-weight-bold text-primary"><?php echo format_currency((float)$row['total_revenue'], $cur); ?></td>
                                    <td class="text-right font-weight-bold text-success"><?php echo format_currency((float)$row['net_profit'], $cur); ?></td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="card-footer bg-white d-flex justify-content-between align-items-center py-3">
                        <small class="text-muted">
                            Showing page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo number_format($totalRows); ?> total records)
                        </small>
                        <ul class="pagination pagination-sm m-0">
                            <?php 
                            $qP = $_GET;
                            $maxLinks = 7;
                            $startP = max(1, $page - 3);
                            $endP   = min($totalPages, $startP + $maxLinks - 1);
                            if ($endP - $startP < $maxLinks - 1) {
                                $startP = max(1, $endP - $maxLinks + 1);
                            }
                            ?>
                            <?php if ($page > 1): ?>
                                <?php $qP['page'] = $page - 1; ?>
                                <li class="page-item"><a class="page-link" href="?<?php echo http_build_query($qP); ?>">&laquo;</a></li>
                            <?php endif; ?>

                            <?php for ($p = $startP; $p <= $endP; $p++): ?>
                                <?php $qP['page'] = $p; ?>
                                <li class="page-item <?php echo $p == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query($qP); ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <?php $qP['page'] = $page + 1; ?>
                                <li class="page-item"><a class="page-link" href="?<?php echo http_build_query($qP); ?>">&raquo;</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Manager Portal v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">Offer on Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
</body>
</html>
