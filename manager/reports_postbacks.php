<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('manager');

$managerId   = auth_user_id();
$managerName = $_SESSION['user_name'] ?? 'Account Manager';
$activeTab   = $_GET['tab'] ?? 'outgoing';

$search     = trim($_GET['search'] ?? '');
$dateFrom   = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo     = $_GET['to']   ?? date('Y-m-d');
$httpFilter = $_GET['http_code'] ?? 'all';
$filterOffer = isset($_GET['offer_id']) && $_GET['offer_id'] !== 'all' ? (int)$_GET['offer_id'] : 'all';
$filterAffiliate = isset($_GET['affiliate_id']) && $_GET['affiliate_id'] !== 'all' ? (int)$_GET['affiliate_id'] : 'all';

$offersList = $pdo->query("SELECT offer_id, offer_name FROM offers ORDER BY offer_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$affiliatesList = $pdo->query("SELECT user_id, name FROM users WHERE role_id = 3 AND account_manager_id = " . (int)$managerId . " ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* =====================================================
   1. FETCH OUTGOING PUBLISHER POSTBACK LOGS (Scoped to Manager)
===================================================== */
$outWhere = [
    "u.account_manager_id = :mgr_id",
    "DATE(apl.created_at) >= :dfrom",
    "DATE(apl.created_at) <= :dto"
];
$outParams = [
    ':mgr_id' => $managerId,
    ':dfrom'  => $dateFrom,
    ':dto'    => $dateTo
];

if ($filterOffer !== 'all') {
    $outWhere[] = "apl.offer_id = :oid";
    $outParams[':oid'] = $filterOffer;
}
if ($filterAffiliate !== 'all') {
    $outWhere[] = "apl.affiliate_id = :aid";
    $outParams[':aid'] = $filterAffiliate;
}

if ($search !== '') {
    $outWhere[] = "(u.name LIKE :s1 OR u.email LIKE :s2 OR o.offer_name LIKE :s3 OR apl.postback_url LIKE :s4)";
    $outParams[':s1'] = "%$search%";
    $outParams[':s2'] = "%$search%";
    $outParams[':s3'] = "%$search%";
    $outParams[':s4'] = "%$search%";
}

if ($httpFilter === '200') {
    $outWhere[] = "apl.http_code = 200";
} elseif ($httpFilter === 'errors') {
    $outWhere[] = "(apl.http_code != 200 OR apl.http_code IS NULL)";
}

$outWhereSql = 'WHERE ' . implode(' AND ', $outWhere);

$outStmt = $pdo->prepare("
    SELECT 
        apl.id,
        apl.affiliate_id,
        apl.offer_id,
        apl.conversion_id,
        apl.postback_url,
        apl.http_code,
        apl.response,
        apl.created_at,
        u.name AS publisher_name,
        u.email AS publisher_email,
        o.offer_name
    FROM affiliate_postback_logs apl
    INNER JOIN users u ON u.user_id = apl.affiliate_id
    LEFT JOIN offers o ON o.offer_id = apl.offer_id
    $outWhereSql
    ORDER BY apl.id DESC
    LIMIT 250
");
$outgoingLogs = [];
try {
    $outStmt->execute($outParams);
    $outgoingLogs = $outStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $outgoingLogs = [];
}

/* =====================================================
   2. FETCH INCOMING ADVERTISER POSTBACK LOGS (Scoped to Manager)
===================================================== */
$incomingLogs = [];
$pblCols = [];
try {
    $pblCols = $pdo->query("SHOW COLUMNS FROM `postback_logs`")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $pblCols = [];
}

$hasId        = in_array('id', $pblCols, true);
$hasClickId   = in_array('click_id', $pblCols, true);
$hasStatus    = in_array('status', $pblCols, true);
$hasRaw       = in_array('raw_request', $pblCols, true);
$hasIp        = in_array('ip_address', $pblCols, true);
$hasCreatedAt = in_array('created_at', $pblCols, true);

// Self-healing attempt if id column missing
if (!$hasId && !empty($pblCols)) {
    try {
        $pk = $pdo->query("SHOW KEYS FROM `postback_logs` WHERE Key_name = 'PRIMARY'")->fetch();
        if (empty($pk)) {
            $pdo->exec("ALTER TABLE `postback_logs` ADD COLUMN `id` INT AUTO_INCREMENT PRIMARY KEY FIRST");
            $hasId = true;
        } else {
            $pdo->exec("ALTER TABLE `postback_logs` ADD COLUMN `id` INT NOT NULL AUTO_INCREMENT UNIQUE FIRST");
            $hasId = true;
        }
    } catch (Exception $e) {
        $hasId = false;
    }
}

$inWhere = ["u.account_manager_id = :imgr_id"];
$inParams = [':imgr_id' => $managerId];

if ($hasCreatedAt) {
    $inWhere[] = "DATE(pl.created_at) >= :idfrom";
    $inWhere[] = "DATE(pl.created_at) <= :idto";
    $inParams[':idfrom'] = $dateFrom;
    $inParams[':idto']   = $dateTo;
}

if ($filterOffer !== 'all') {
    $inWhere[] = "c.offer_id = :in_oid";
    $inParams[':in_oid'] = $filterOffer;
}
if ($filterAffiliate !== 'all') {
    $inWhere[] = "c.affiliate_id = :in_aid";
    $inParams[':in_aid'] = $filterAffiliate;
}

if ($search !== '') {
    $searchConds = [];
    if ($hasClickId) {
        $searchConds[] = "pl.click_id LIKE :is1";
        $inParams[':is1'] = "%$search%";
    }
    if ($hasRaw) {
        $searchConds[] = "pl.raw_request LIKE :is2";
        $inParams[':is2'] = "%$search%";
    }
    $searchConds[] = "u.name LIKE :is3";
    $inParams[':is3'] = "%$search%";
    $inWhere[] = '(' . implode(' OR ', $searchConds) . ')';
}

$inWhereSql = 'WHERE ' . implode(' AND ', $inWhere);

$colIdSelect  = $hasId ? "pl.id" : "NULL AS id";
$colClickId   = $hasClickId ? "pl.click_id" : "NULL AS click_id";
$colStatus    = $hasStatus ? "pl.status" : "'success' AS status";
$colRaw       = $hasRaw ? "pl.raw_request" : "NULL AS raw_request";
$colIp        = $hasIp ? "pl.ip_address" : "NULL AS ip_address";
$colCreatedAt = $hasCreatedAt ? "pl.created_at" : "NOW() AS created_at";
$orderBy      = $hasId ? "ORDER BY pl.id DESC" : ($hasCreatedAt ? "ORDER BY pl.created_at DESC" : "");

try {
    $inStmt = $pdo->prepare("
        SELECT 
            $colIdSelect,
            $colClickId,
            $colStatus,
            $colRaw,
            $colIp,
            $colCreatedAt,
            c.offer_id,
            c.affiliate_id,
            u.name AS publisher_name,
            o.offer_name
        FROM postback_logs pl
        INNER JOIN clicks c ON c.click_id = pl.click_id
        INNER JOIN users u ON u.user_id = c.affiliate_id
        LEFT JOIN offers o ON o.offer_id = c.offer_id
        $inWhereSql
        $orderBy
        LIMIT 250
    ");
    $inStmt->execute($inParams);
    $incomingLogs = $inStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $incomingLogs = [];
}

// Counts
$totalOutgoing   = count($outgoingLogs);
$successOutgoing = count(array_filter($outgoingLogs, function($l) { return (int)($l['http_code'] ?? 0) === 200; }));
$failedOutgoing  = $totalOutgoing - $successOutgoing;
$totalIncoming   = count($incomingLogs);

/* ==========================================================================
   EXPORT HANDLER FOR POSTBACK LOGS (CSV & EXCEL)
   ========================================================================== */
if (isset($_GET['export']) && in_array(strtolower($_GET['export']), ['csv', 'excel'], true)) {
    $exportFormat = strtolower($_GET['export']);
    $dateStamp = date('Y-m-d_His');
    $filename = "manager_postbacks_{$activeTab}_{$dateStamp}" . ($exportFormat === 'excel' ? '.xls' : '.csv');

    while (ob_get_level()) {
        ob_end_clean();
    }

    if ($exportFormat === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        $out = fopen('php://output', 'w');
    }

    $cleanStr = function($val) {
        return str_replace(["\t", "\r", "\n"], ' ', (string)($val ?? ''));
    };

    if ($activeTab === 'outgoing') {
        $headers = ['Log ID', 'Timestamp', 'Publisher ID', 'Publisher Name', 'Publisher Email', 'Offer ID', 'Campaign Name', 'HTTP Code', 'Status', 'Fired Postback URL', 'Response Body'];
        if ($exportFormat === 'excel') {
            echo implode("\t", $headers) . "\n";
        } else {
            fputcsv($out, $headers, ',', '"', "\\");
        }
        foreach ($outgoingLogs as $log) {
            $code = (int)($log['http_code'] ?? 0);
            $status = ($code >= 200 && $code < 300) ? 'Success' : ($code === 0 ? 'Timeout/Failed' : 'Error ' . $code);
            $data = [
                $log['id'],
                $log['created_at'],
                $log['affiliate_id'],
                $log['publisher_name'],
                $log['publisher_email'] ?: '',
                $log['offer_id'],
                $log['offer_name'] ?: 'Campaign',
                $code,
                $status,
                $log['postback_url'],
                $log['response'] ?? ''
            ];
            if ($exportFormat === 'excel') {
                echo implode("\t", array_map($cleanStr, $data)) . "\n";
            } else {
                fputcsv($out, $data, ',', '"', "\\");
            }
        }
    } else {
        $headers = ['Log ID', 'Timestamp', 'Click ID', 'Publisher Name', 'Campaign Name', 'Status', 'IP Address', 'Raw Request'];
        if ($exportFormat === 'excel') {
            echo implode("\t", $headers) . "\n";
        } else {
            fputcsv($out, $headers, ',', '"', "\\");
        }
        foreach ($incomingLogs as $log) {
            $data = [
                $log['id'] ?? '-',
                $log['created_at'] ?? '-',
                $log['click_id'] ?? '-',
                $log['publisher_name'] ?? '-',
                $log['offer_name'] ?? '-',
                ucfirst($log['status'] ?? 'Received'),
                $log['ip_address'] ?? '-',
                $log['raw_request'] ?? ''
            ];
            if ($exportFormat === 'excel') {
                echo implode("\t", array_map($cleanStr, $data)) . "\n";
            } else {
                fputcsv($out, $data, ',', '"', "\\");
            }
        }
    }

    if ($exportFormat !== 'excel') {
        fclose($out);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Postback Delivery & Logs | Manager Portal</title>

    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">

    <style>
        .stat-card-custom {
            background: #fff;
            border-radius: 10px;
            padding: 16px 20px;
            border: 1px solid #e2e8f0;
            text-align: center;
        }
        .stat-val { font-size: 26px; font-weight: 800; }
        .stat-lbl { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; }
        .url-cell {
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-family: monospace;
            font-size: 12px;
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
            <li class="nav-item d-none d-sm-inline-block"><a href="reports.php" class="nav-link">Reports</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="reports_postbacks.php" class="nav-link active font-weight-bold">Postback Logs</a></li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <span class="nav-link text-muted"><i class="fas fa-user-tie mr-1 text-primary"></i> <?php echo htmlspecialchars($managerName); ?></span>
            </li>
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
                        <h1 class="m-0 font-weight-bold"><i class="fas fa-exchange-alt text-primary mr-2"></i>Postback Delivery Logs & Debugger</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="reports.php">Reports</a></li>
                            <li class="breadcrumb-item active">Postback Logs</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <!-- Stats Overview -->
                <div class="row mb-4">
                    <div class="col-md-3 col-6 mb-2">
                        <div class="stat-card-custom">
                            <div class="stat-val text-primary"><?php echo number_format($totalOutgoing); ?></div>
                            <div class="stat-lbl">Publisher S2S Postbacks</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-2">
                        <div class="stat-card-custom">
                            <div class="stat-val text-success"><?php echo number_format($successOutgoing); ?></div>
                            <div class="stat-lbl">Delivered (200 OK)</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-2">
                        <div class="stat-card-custom">
                            <div class="stat-val text-danger"><?php echo number_format($failedOutgoing); ?></div>
                            <div class="stat-lbl">Failed / Timeouts</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-2">
                        <div class="stat-card-custom">
                            <div class="stat-val text-info"><?php echo number_format($totalIncoming); ?></div>
                            <div class="stat-lbl">Incoming Conversions</div>
                        </div>
                    </div>
                </div>

                <!-- Search & Filters -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <!-- Quick Date Presets -->
                        <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 pb-2 border-bottom">
                            <div class="btn-group btn-group-sm mb-2 mb-md-0" role="group">
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['from' => date('Y-m-d'), 'to' => date('Y-m-d')])); ?>" class="btn btn-outline-secondary <?php echo ($dateFrom === date('Y-m-d') && $dateTo === date('Y-m-d')) ? 'active font-weight-bold' : ''; ?>">Today</a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['from' => date('Y-m-d', strtotime('-1 day')), 'to' => date('Y-m-d', strtotime('-1 day'))])); ?>" class="btn btn-outline-secondary <?php echo ($dateFrom === date('Y-m-d', strtotime('-1 day')) && $dateTo === date('Y-m-d', strtotime('-1 day'))) ? 'active font-weight-bold' : ''; ?>">Yesterday</a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['from' => date('Y-m-d', strtotime('-7 days')), 'to' => date('Y-m-d')])); ?>" class="btn btn-outline-secondary <?php echo ($dateFrom === date('Y-m-d', strtotime('-7 days')) && $dateTo === date('Y-m-d')) ? 'active font-weight-bold' : ''; ?>">Last 7 Days</a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['from' => date('Y-m-01'), 'to' => date('Y-m-d')])); ?>" class="btn btn-outline-secondary <?php echo ($dateFrom === date('Y-m-01') && $dateTo === date('Y-m-d')) ? 'active font-weight-bold' : ''; ?>">This Month</a>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['from' => date('Y-m-d', strtotime('-30 days')), 'to' => date('Y-m-d')])); ?>" class="btn btn-outline-secondary">Last 30 Days</a>
                            </div>
                            <div>
                                <span class="badge badge-light border text-muted px-2 py-1">
                                    <i class="fas fa-calendar-alt mr-1 text-primary"></i><?php echo $dateFrom ? date('M d, Y', strtotime($dateFrom)) : 'All'; ?> - <?php echo $dateTo ? date('M d, Y', strtotime($dateTo)) : 'Now'; ?>
                                </span>
                            </div>
                        </div>

                        <form method="get" class="row align-items-end">
                            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="font-weight-bold small text-muted text-uppercase">From Date</label>
                                <input type="date" name="from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateFrom); ?>">
                            </div>
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="font-weight-bold small text-muted text-uppercase">To Date</label>
                                <input type="date" name="to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($dateTo); ?>">
                            </div>
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="font-weight-bold small text-muted text-uppercase">Campaign Offer</label>
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
                                <label class="font-weight-bold small text-muted text-uppercase">Publisher</label>
                                <select name="affiliate_id" class="form-control form-control-sm">
                                    <option value="all">All Publishers</option>
                                    <?php foreach ($affiliatesList as $aff): ?>
                                        <option value="<?php echo $aff['user_id']; ?>" <?php echo (string)$filterAffiliate === (string)$aff['user_id'] ? 'selected' : ''; ?>>
                                            #<?php echo $aff['user_id']; ?> - <?php echo htmlspecialchars($aff['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="font-weight-bold small text-muted text-uppercase">Status</label>
                                <select name="http_code" class="form-control form-control-sm">
                                    <option value="all" <?php echo $httpFilter === 'all' ? 'selected' : ''; ?>>All Status Codes</option>
                                    <option value="200" <?php echo $httpFilter === '200' ? 'selected' : ''; ?>>HTTP 200 OK Only</option>
                                    <option value="errors" <?php echo $httpFilter === 'errors' ? 'selected' : ''; ?>>Errors & Timeouts Only</option>
                                </select>
                            </div>
                            <div class="col-lg-2 col-md-3 col-6 mb-2">
                                <label class="font-weight-bold small text-muted text-uppercase">Keyword Search</label>
                                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-lg-12 d-flex justify-content-end mt-2">
                                <button type="submit" class="btn btn-sm btn-primary font-weight-bold mr-2 px-3">
                                    <i class="fas fa-filter mr-1"></i> Apply Filter
                                </button>
                                <a href="?tab=<?php echo htmlspecialchars($activeTab); ?>" class="btn btn-sm btn-outline-secondary px-3" title="Reset Filters">
                                    <i class="fas fa-undo mr-1"></i> Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Navigation Tabs -->
                <ul class="nav nav-pills mb-3">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'outgoing' ? 'active font-weight-bold' : ''; ?>" href="?tab=outgoing&<?php echo http_build_query(['search' => $search, 'from' => $dateFrom, 'to' => $dateTo]); ?>">
                            <i class="fas fa-paper-plane mr-1"></i> Outgoing Publisher S2S Postbacks (<?php echo count($outgoingLogs); ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $activeTab === 'incoming' ? 'active font-weight-bold' : ''; ?>" href="?tab=incoming&<?php echo http_build_query(['search' => $search, 'from' => $dateFrom, 'to' => $dateTo]); ?>">
                            <i class="fas fa-inbox mr-1"></i> Incoming Advertiser Postbacks (<?php echo count($incomingLogs); ?>)
                        </a>
                    </li>
                </ul>

                <!-- TAB 1: OUTGOING S2S POSTBACKS -->
                <?php if ($activeTab === 'outgoing'): ?>
                <div class="card shadow-sm p-4" style="border-radius: 12px;">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                        <h5 class="font-weight-bold text-dark mb-2 mb-md-0"><i class="fas fa-paper-plane mr-2 text-primary"></i>Outgoing Publisher Postback Logs</h5>
                        <div>
                            <?php 
                            $pbParams = $_GET;
                            $pbParams['tab'] = 'outgoing';
                            $pbParams['export'] = 'csv';
                            $pbCsv = '?' . http_build_query($pbParams);
                            $pbParams['export'] = 'excel';
                            $pbXls = '?' . http_build_query($pbParams);
                            ?>
                            <a href="<?php echo htmlspecialchars($pbCsv); ?>" class="btn btn-sm btn-outline-success font-weight-bold mr-1">
                                <i class="fas fa-file-csv mr-1"></i> Download CSV
                            </a>
                            <a href="<?php echo htmlspecialchars($pbXls); ?>" class="btn btn-sm btn-outline-primary font-weight-bold">
                                <i class="fas fa-file-excel mr-1"></i> Download Excel
                            </a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mgrPostbackTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID / Time</th>
                                    <th>Publisher</th>
                                    <th>Campaign Offer</th>
                                    <th>HTTP Code</th>
                                    <th>Fired S2S URL</th>
                                    <th>Response Body</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($outgoingLogs as $log): 
                                    $code = (int)($log['http_code'] ?? 0);
                                    $isSuccess = ($code >= 200 && $code < 300);
                                    $badgeClass = $isSuccess ? 'success' : ($code === 0 ? 'secondary' : 'danger');
                                ?>
                                <tr>
                                    <td>
                                        <strong>#<?php echo $log['id']; ?></strong>
                                        <small class="d-block text-muted"><?php echo date('M d, H:i:s', strtotime($log['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <strong class="text-dark">#<?php echo $log['affiliate_id']; ?> - <?php echo htmlspecialchars($log['publisher_name']); ?></strong>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($log['publisher_email'] ?: ''); ?></small>
                                    </td>
                                    <td>
                                        <strong class="text-primary">#<?php echo $log['offer_id']; ?> - <?php echo htmlspecialchars($log['offer_name'] ?: 'Campaign'); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $badgeClass; ?> p-2 font-weight-bold">
                                            <?php echo $code > 0 ? "HTTP $code" : "Timeout / 0"; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="url-cell" title="<?php echo htmlspecialchars($log['postback_url']); ?>">
                                            <?php echo htmlspecialchars($log['postback_url']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="url-cell text-muted" title="<?php echo htmlspecialchars($log['response'] ?: 'No response'); ?>">
                                            <?php echo htmlspecialchars($log['response'] ? substr($log['response'], 0, 80) : 'None'); ?>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <button type="button" class="btn btn-sm btn-outline-info font-weight-bold btn-view-payload"
                                                data-url="<?php echo htmlspecialchars($log['postback_url'], ENT_QUOTES); ?>"
                                                data-code="<?php echo $code; ?>"
                                                data-response="<?php echo htmlspecialchars($log['response'] ?: '(Empty response)', ENT_QUOTES); ?>">
                                            <i class="fas fa-eye mr-1"></i> Inspect
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- TAB 2: INCOMING ADVERTISER POSTBACKS -->
                <?php if ($activeTab === 'incoming'): ?>
                <div class="card shadow-sm p-4" style="border-radius: 12px;">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                        <h5 class="font-weight-bold text-dark mb-2 mb-md-0"><i class="fas fa-inbox mr-2 text-primary"></i>Incoming Advertiser Postbacks</h5>
                        <div>
                            <?php 
                            $pbParams = $_GET;
                            $pbParams['tab'] = 'incoming';
                            $pbParams['export'] = 'csv';
                            $pbCsv = '?' . http_build_query($pbParams);
                            $pbParams['export'] = 'excel';
                            $pbXls = '?' . http_build_query($pbParams);
                            ?>
                            <a href="<?php echo htmlspecialchars($pbCsv); ?>" class="btn btn-sm btn-outline-success font-weight-bold mr-1">
                                <i class="fas fa-file-csv mr-1"></i> Download CSV
                            </a>
                            <a href="<?php echo htmlspecialchars($pbXls); ?>" class="btn btn-sm btn-outline-primary font-weight-bold">
                                <i class="fas fa-file-excel mr-1"></i> Download Excel
                            </a>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mgrPostbackTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID / Time</th>
                                    <th>Click ID</th>
                                    <th>Campaign Offer</th>
                                    <th>Publisher</th>
                                    <th>Status</th>
                                    <th>Remote IP</th>
                                    <th>Raw Request</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($incomingLogs as $inLog): 
                                    $st = strtolower($inLog['status'] ?? 'accepted');
                                    $stBadge = ($st === 'accepted' || $st === 'approved') ? 'success' : (($st === 'duplicate') ? 'warning' : 'danger');
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo !empty($inLog['id']) ? '#' . $inLog['id'] : 'LOG'; ?></strong>
                                        <small class="d-block text-muted"><?php echo !empty($inLog['created_at']) ? date('M d, H:i:s', strtotime($inLog['created_at'])) : '-'; ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge-secondary px-2 py-1 font-monospace"><?php echo htmlspecialchars(substr($inLog['click_id'] ?: '', 0, 16)); ?>...</span>
                                    </td>
                                    <td>
                                        <strong class="text-primary"><?php echo htmlspecialchars($inLog['offer_name'] ?: 'N/A'); ?></strong>
                                    </td>
                                    <td>
                                        <strong class="text-dark"><?php echo htmlspecialchars($inLog['publisher_name'] ?: 'N/A'); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $stBadge; ?> p-2 font-weight-bold"><?php echo ucfirst($st); ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo htmlspecialchars($inLog['ip_address'] ?: '-'); ?></small>
                                    </td>
                                    <td>
                                        <div class="url-cell" title="<?php echo htmlspecialchars($inLog['raw_request'] ?: ''); ?>">
                                            <?php echo htmlspecialchars($inLog['raw_request'] ?: '-'); ?>
                                        </div>
                                    </td>
                                    <td class="text-right">
                                        <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold btn-view-raw"
                                                data-raw="<?php echo htmlspecialchars($inLog['raw_request'] ?: 'N/A', ENT_QUOTES); ?>"
                                                data-click="<?php echo htmlspecialchars($inLog['click_id'] ?: '', ENT_QUOTES); ?>"
                                                data-ip="<?php echo htmlspecialchars($inLog['ip_address'] ?: '', ENT_QUOTES); ?>">
                                            <i class="fas fa-search mr-1"></i> Details
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- Payload Inspect Modal -->
    <div class="modal fade" id="payloadModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content" style="border-radius: 12px; overflow: hidden;">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-terminal mr-2"></i>Postback Delivery Details</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small text-uppercase text-muted">HTTP Response Status</label>
                        <div><span id="modalHttpBadge" class="badge p-2 font-weight-bold" style="font-size: 14px;"></span></div>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small text-uppercase text-muted">Fired S2S URL</label>
                        <textarea id="modalFiredUrl" class="form-control font-monospace" rows="3" readonly style="font-size: 12.5px; background: #fff;"></textarea>
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold small text-uppercase text-muted">Publisher Server Response Body</label>
                        <textarea id="modalResponseBody" class="form-control font-monospace" rows="5" readonly style="font-size: 12.5px; background: #fff;"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Raw Request Modal -->
    <div class="modal fade" id="rawModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <div class="modal-content" style="border-radius: 12px; overflow: hidden;">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-code mr-2"></i>Advertiser Postback Request Payload</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small text-uppercase text-muted">Click ID</label>
                        <input type="text" id="rawModalClick" class="form-control font-monospace font-weight-bold" readonly style="background: #fff;">
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small text-uppercase text-muted">Remote IP Address</label>
                        <input type="text" id="rawModalIp" class="form-control font-monospace" readonly style="background: #fff;">
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold small text-uppercase text-muted">Full Incoming Request Query String / URI</label>
                        <textarea id="rawModalPayload" class="form-control font-monospace" rows="4" readonly style="font-size: 12.5px; background: #fff;"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Close</button>
                </div>
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
    $('.mgrPostbackTable').DataTable({
        pageLength: 20,
        responsive: true,
        order: [[0, 'desc']]
    });

    $(document).on('click', '.btn-view-payload', function() {
        var url = $(this).data('url');
        var code = parseInt($(this).data('code')) || 0;
        var response = $(this).data('response');

        $('#modalFiredUrl').val(url);
        $('#modalResponseBody').val(response);

        var badge = $('#modalHttpBadge');
        if (code >= 200 && code < 300) {
            badge.removeClass().addClass('badge badge-success p-2 font-weight-bold').text('HTTP ' + code + ' OK');
        } else if (code > 0) {
            badge.removeClass().addClass('badge badge-danger p-2 font-weight-bold').text('HTTP ' + code + ' Error');
        } else {
            badge.removeClass().addClass('badge badge-secondary p-2 font-weight-bold').text('HTTP 0 - Connection Timeout');
        }

        $('#payloadModal').modal('show');
    });

    $(document).on('click', '.btn-view-raw', function() {
        var click = $(this).data('click');
        var ip = $(this).data('ip');
        var raw = $(this).data('raw');

        $('#rawModalClick').val(click);
        $('#rawModalIp').val(ip);
        $('#rawModalPayload').val(raw);

        $('#rawModal').modal('show');
    });
});
</script>
</body>
</html>