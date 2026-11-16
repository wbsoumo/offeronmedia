<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

/* ===============================
   HANDLE RESTORE ACTIONS
================================ */
if (isset($_GET['action']) && isset($_GET['id'])) {
    $offerId = (int)$_GET['id'];
    $action = $_GET['action'];
    
    if ($action === 'restore') {
        $stmt = $pdo->prepare("UPDATE offers SET status = 'paused', updated_at = NOW() WHERE offer_id = ? AND status = 'archived'");
        $stmt->execute([$offerId]);
        if ($stmt->rowCount() > 0) {
            admin_log_action('offer_restore', 'offer', $offerId, 'Campaign restored from archive to paused status');
            $success = "Campaign #{$offerId} restored successfully to Paused status.";
        } else {
            $error = "Campaign not found or was not archived.";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $action = $_POST['bulk_action'];
    $selectedOffers = $_POST['selected_offers'] ?? [];
    
    if (empty($selectedOffers)) {
        $error = 'Please select at least one campaign';
    } elseif ($action === 'restore') {
        $placeholders = implode(',', array_fill(0, count($selectedOffers), '?'));
        $stmt = $pdo->prepare("UPDATE offers SET status = 'paused', updated_at = NOW() WHERE offer_id IN ($placeholders) AND status = 'archived'");
        $stmt->execute($selectedOffers);
        $count = $stmt->rowCount();
        admin_log_action('offers_bulk_restore', 'offers', null, "Restored $count campaigns to paused");
        $success = "{$count} campaign(s) restored successfully to Paused status.";
    }
}

/* ===============================
   FETCH ARCHIVED OFFERS
================================ */
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = ["o.status = 'archived'"];
$params = [];

if ($search !== '') {
    $where[] = "(o.offer_name LIKE :search OR o.offer_id = :exact_id OR u.name LIKE :search OR u.email LIKE :search)";
    $params['search'] = "%$search%";
    $params['exact_id'] = is_numeric($search) ? (int)$search : 0;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// Count total
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM offers o
    LEFT JOIN users u ON u.user_id = o.advertiser_id
    $whereSql
");
$countStmt->execute($params);
$totalOffers = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalOffers / $perPage));
$offset = ($page - 1) * $perPage;

// Fetch records with historical stats
$stmt = $pdo->prepare("
    SELECT 
        o.offer_id,
        o.offer_name,
        o.offer_description,
        o.payout,
        o.payout_type,
        o.currency,
        o.revenue,
        o.status,
        o.category,
        o.created_at,
        o.updated_at,
        u.name AS advertiser_name,
        u.email AS advertiser_email,
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS earned_revenue,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS paid_payout
    FROM offers o
    LEFT JOIN users u ON u.user_id = o.advertiser_id
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    $whereSql
    GROUP BY o.offer_id
    ORDER BY o.updated_at DESC
    LIMIT :offset, :per_page
");

foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $perPage, PDO::PARAM_INT);
$stmt->execute();
$archivedOffers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Overall archived totals
$statsStmt = $pdo->query("
    SELECT 
        COUNT(*) as total_archived,
        (SELECT COUNT(*) FROM clicks c JOIN offers o ON o.offer_id = c.offer_id WHERE o.status = 'archived') as archived_clicks,
        (SELECT COUNT(*) FROM conversions cv JOIN offers o ON o.offer_id = cv.offer_id WHERE o.status = 'archived') as archived_conversions
    FROM offers 
    WHERE status = 'archived'
");
$archiveStats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Archived Offers | Admin Console</title>
    
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            --archive-gradient: linear-gradient(135deg, #475569 0%, #1e293b 100%);
        }
        .archive-banner {
            background: var(--archive-gradient);
            color: white;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 18px;
            border: 1px solid #e2e8f0;
            text-align: center;
        }
        .stat-number { font-size: 26px; font-weight: 700; color: #1e293b; }
        .stat-label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .table-custom thead th {
            background: #f1f5f9;
            color: #334155;
            font-size: 13px;
            font-weight: 700;
            border-bottom: 2px solid #cbd5e1;
        }
        .badge-archived {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 5px 10px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
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
            <li class="nav-item d-none d-sm-inline-block">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="offers.php" class="nav-link">Campaigns</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="archived_offers.php" class="nav-link active">Archived Offers</a>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <span class="nav-link text-muted"><i class="fas fa-user-shield mr-1"></i> <?php echo htmlspecialchars($adminName); ?></span>
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
                        <h1 class="m-0 font-weight-bold"><i class="fas fa-archive text-secondary mr-2"></i>Archived Campaigns & Soft Deletion</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="offers.php">Campaigns</a></li>
                            <li class="breadcrumb-item active">Archived Offers</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <!-- Informational Banner -->
                <div class="archive-banner">
                    <div class="row align-items-center">
                        <div class="col-md-9">
                            <h3 class="font-weight-bold mb-2"><i class="fas fa-shield-alt mr-2"></i>Campaign Archival & Historical Preservation</h3>
                            <p class="mb-0 text-white-50">
                                Soft-deleted campaigns are preserved here. Tracking links for archived campaigns are immediately disabled to prevent invalid clicks (HTTP 403). All historical clicks, conversions, payouts, and revenue logs remain 100% intact. Restoring a campaign moves it to <strong>Paused</strong> status for safe reactivation.
                            </p>
                        </div>
                        <div class="col-md-3 text-md-right mt-3 mt-md-0">
                            <a href="offers.php" class="btn btn-light font-weight-bold">
                                <i class="fas fa-arrow-left mr-1"></i> Active Campaigns
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
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

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="stat-number text-secondary"><?php echo number_format($archiveStats['total_archived'] ?? 0); ?></div>
                            <div class="stat-label">Archived Campaigns</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="stat-number text-info"><?php echo number_format($archiveStats['archived_clicks'] ?? 0); ?></div>
                            <div class="stat-label">Historical Clicks Preserved</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card">
                            <div class="stat-number text-success"><?php echo number_format($archiveStats['archived_conversions'] ?? 0); ?></div>
                            <div class="stat-label">Historical Conversions Preserved</div>
                        </div>
                    </div>
                </div>

                <!-- Search and Filters -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <form method="get" class="row">
                            <div class="col-md-9 mb-2 mb-md-0">
                                <div class="input-group">
                                    <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search"></i></span></div>
                                    <input type="text" name="search" class="form-control" placeholder="Search by campaign title, ID, or advertiser..." value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-filter mr-1"></i> Filter Archive</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Archived Offers Table -->
                <form method="post" id="archiveBulkForm">
                    <div class="card shadow-sm">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0 font-weight-bold text-dark">
                                <i class="fas fa-archive mr-2 text-secondary"></i>Archived Campaigns List (<?php echo $totalOffers; ?>)
                            </h5>
                            <div class="card-tools d-flex align-items-center">
                                <select name="bulk_action" class="form-control form-control-sm mr-2" style="width: auto;">
                                    <option value="">Bulk Actions</option>
                                    <option value="restore">Restore Selected</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-success font-weight-bold" onclick="return confirm('Restore all selected campaigns to Paused status?')">
                                    <i class="fas fa-undo mr-1"></i> Apply
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($archivedOffers)): ?>
                                <div class="p-5 text-center text-muted">
                                    <i class="fas fa-archive fa-3x mb-3 text-secondary"></i>
                                    <h5>No Archived Campaigns Found</h5>
                                    <p class="small">No offers match the archive criteria.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover table-custom mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width: 40px;"><input type="checkbox" id="checkAllArchived"></th>
                                                <th>Campaign Details</th>
                                                <th>Advertiser</th>
                                                <th>Configured Pricing</th>
                                                <th>Status</th>
                                                <th>Historical Clicks</th>
                                                <th>Approved Conversions</th>
                                                <th>Archived Date</th>
                                                <th class="text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($archivedOffers as $ao): ?>
                                            <tr>
                                                <td><input type="checkbox" name="selected_offers[]" value="<?php echo $ao['offer_id']; ?>" class="archive-checkbox"></td>
                                                <td>
                                                    <strong>#<?php echo $ao['offer_id']; ?> - <?php echo htmlspecialchars($ao['offer_name']); ?></strong>
                                                    <small class="d-block text-muted">Category: <?php echo htmlspecialchars($ao['category'] ?: 'General'); ?></small>
                                                </td>
                                                <td>
                                                    <div><?php echo htmlspecialchars($ao['advertiser_name'] ?: 'N/A'); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($ao['advertiser_email'] ?: ''); ?></small>
                                                </td>
                                                <td>
                                                    <strong class="text-success"><?php echo format_currency($ao['payout'], $ao['currency'] ?? 'USD'); ?></strong>
                                                    <small class="d-block text-muted">Rev: <?php echo format_currency($ao['revenue'], $ao['currency'] ?? 'USD'); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge-archived"><i class="fas fa-box-archive mr-1"></i> Archived</span>
                                                </td>
                                                <td><strong><?php echo number_format($ao['total_clicks']); ?></strong></td>
                                                <td><strong class="text-success"><?php echo number_format($ao['approved_conversions']); ?></strong></td>
                                                <td><small class="text-muted"><?php echo date('M d, Y', strtotime($ao['updated_at'] ?: $ao['created_at'])); ?></small></td>
                                                <td class="text-right">
                                                    <a href="offer_details.php?id=<?php echo $ao['offer_id']; ?>" class="btn btn-sm btn-outline-info mr-1" title="View Historical Details"><i class="fas fa-eye"></i></a>
                                                    <a href="?action=restore&id=<?php echo $ao['offer_id']; ?>" class="btn btn-sm btn-success font-weight-bold" title="Restore Campaign" onclick="return confirm('Restore campaign #<?php echo $ao['offer_id']; ?> to Paused status?')">
                                                        <i class="fas fa-undo mr-1"></i> Restore
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($totalPages > 1): ?>
                        <div class="card-footer bg-white d-flex justify-content-center">
                            <ul class="pagination pagination-sm m-0">
                                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <li class="page-item <?php echo $p == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>"><?php echo $p; ?></a>
                                </li>
                                <?php endfor; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$('#checkAllArchived').on('change', function() {
    $('.archive-checkbox').prop('checked', $(this).prop('checked'));
});
</script>
</body>
</html>

