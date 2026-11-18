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
   SAVE / UPDATE AFFILIATE POSTBACK
================================ */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (isset($_POST['save_postback'])) {
        $pbid = (int)($_POST['postback_id'] ?? 0);
        $affiliateId = (int)($_POST['affiliate_id'] ?? 0);
        $postbackUrl = trim($_POST['postback_url'] ?? '');
        $status = $_POST['status'] ?? 'active';
        $postbackType = $_POST['postback_type'] ?? 'global';
        $name = trim($_POST['postback_name'] ?? '');
        $fireStatus = $_POST['fire_status'] ?? 'approved';

        if (!$affiliateId || empty($postbackUrl)) {
            $error = 'Publisher and Postback URL are required';
        } elseif (!filter_var($postbackUrl, FILTER_VALIDATE_URL)) {
            $error = 'Please enter a valid HTTP/HTTPS Postback URL';
        } else {
            // Check if global postback record already exists by ID or by affiliate_id
            $existingPostback = null;
            if ($pbid > 0) {
                $checkStmt = $pdo->prepare("SELECT id FROM affiliate_postbacks WHERE id = ? LIMIT 1");
                $checkStmt->execute([$pbid]);
                $existingPostback = $checkStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$existingPostback) {
                $checkStmt = $pdo->prepare("SELECT id FROM affiliate_postbacks WHERE affiliate_id = ? AND (offer_id IS NULL OR offer_id = 0) LIMIT 1");
                $checkStmt->execute([$affiliateId]);
                $existingPostback = $checkStmt->fetch(PDO::FETCH_ASSOC);
            }

            if ($existingPostback) {
                // Update existing record
                $updateStmt = $pdo->prepare("
                    UPDATE affiliate_postbacks 
                    SET affiliate_id = :aid,
                        postback_url = :url,
                        fire_status = :fire_status,
                        status = :status,
                        postback_type = :type,
                        postback_name = :name,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    'aid'         => $affiliateId,
                    'url'         => $postbackUrl,
                    'fire_status' => $fireStatus,
                    'status'      => $status,
                    'type'        => $postbackType,
                    'name'        => $name,
                    'id'          => $existingPostback['id']
                ]);
            } else {
                // Insert new record
                $insertStmt = $pdo->prepare("
                    INSERT INTO affiliate_postbacks 
                        (affiliate_id, offer_id, postback_url, fire_status, status, postback_type, postback_name, created_at, updated_at)
                    VALUES 
                        (:aid, NULL, :url, :fire_status, :status, :type, :name, NOW(), NOW())
                ");
                $insertStmt->execute([
                    'aid'         => $affiliateId,
                    'url'         => $postbackUrl,
                    'fire_status' => $fireStatus,
                    'status'      => $status,
                    'type'        => $postbackType,
                    'name'        => $name
                ]);
            }

            $success = 'Global postback configuration saved successfully!';
        }
    } elseif (isset($_POST['delete_global_postback'])) {
        $pbid = (int)($_POST['postback_id'] ?? 0);
        if ($pbid > 0) {
            $pdo->prepare("DELETE FROM affiliate_postbacks WHERE id = ? AND (offer_id IS NULL OR offer_id = 0)")->execute([$pbid]);
            $success = 'Global postback configuration removed successfully!';
        }
    } elseif (isset($_POST['save_offer_postback'])) {
        $affiliateId = (int)($_POST['affiliate_id'] ?? 0);
        $offerId = (int)($_POST['offer_id'] ?? 0);
        $postbackUrl = trim($_POST['postback_url'] ?? '');
        $status = $_POST['status'] ?? 'active';
        $fireStatus = $_POST['fire_status'] ?? 'approved';

        if (!$affiliateId || !$offerId || empty($postbackUrl)) {
            $error = 'Publisher, Offer, and Postback URL are all required';
        } elseif (!filter_var($postbackUrl, FILTER_VALIDATE_URL)) {
            $error = 'Please enter a valid HTTP/HTTPS Postback URL';
        } else {
            $checkStmt = $pdo->prepare("SELECT id FROM affiliate_postbacks WHERE affiliate_id = ? AND offer_id = ? LIMIT 1");
            $checkStmt->execute([$affiliateId, $offerId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $up = $pdo->prepare("UPDATE affiliate_postbacks SET postback_url = ?, fire_status = ?, status = ?, updated_at = NOW() WHERE id = ?");
                $up->execute([$postbackUrl, $fireStatus, $status, $existing['id']]);
            } else {
                $ins = $pdo->prepare("INSERT INTO affiliate_postbacks (affiliate_id, offer_id, postback_url, fire_status, status, postback_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'offer', NOW(), NOW())");
                $ins->execute([$affiliateId, $offerId, $postbackUrl, $fireStatus, $status]);
            }

            // Sync with affiliate_offer_postbacks
            try {
                $pdo->prepare("
                    INSERT INTO affiliate_offer_postbacks (affiliate_id, offer_id, postback_url, fire_status, status)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE postback_url = VALUES(postback_url), fire_status = VALUES(fire_status), status = VALUES(status)
                ")->execute([$affiliateId, $offerId, $postbackUrl, $fireStatus, $status]);
            } catch (Exception $e) {}

            $success = 'Offer-specific postback override saved successfully!';
        }
    } elseif (isset($_POST['delete_offer_postback'])) {
        $pbid = (int)($_POST['postback_id'] ?? 0);
        if ($pbid > 0) {
            $pRow = $pdo->prepare("SELECT affiliate_id, offer_id FROM affiliate_postbacks WHERE id = ?");
            $pRow->execute([$pbid]);
            $row = $pRow->fetch(PDO::FETCH_ASSOC);

            $pdo->prepare("DELETE FROM affiliate_postbacks WHERE id = ?")->execute([$pbid]);
            if ($row) {
                try {
                    $pdo->prepare("DELETE FROM affiliate_offer_postbacks WHERE affiliate_id = ? AND offer_id = ?")->execute([$row['affiliate_id'], $row['offer_id']]);
                } catch (Exception $e) {}
            }
            $success = 'Offer-specific postback override deleted successfully!';
        }
    }
}

/* ===============================
   FETCH PUBLISHERS & CONFIGS
================================ */
// All active registered affiliates for selection dropdown
$allPublishers = $pdo->query("
    SELECT user_id, name, email, status 
    FROM users 
    WHERE role_id = 3 
    ORDER BY name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ONLY publishers who have configured global postback data
$configuredPostbacks = $pdo->query("
    SELECT 
        ap.id as postback_id,
        ap.affiliate_id,
        ap.postback_url,
        ap.fire_status,
        ap.status as postback_status,
        ap.postback_type,
        ap.postback_name,
        COALESCE(ap.updated_at, ap.created_at) as postback_updated,
        u.name as publisher_name,
        u.email as publisher_email,
        u.status as affiliate_status
    FROM affiliate_postbacks ap
    INNER JOIN users u ON u.user_id = ap.affiliate_id
    WHERE (ap.offer_id IS NULL OR ap.offer_id = 0)
      AND ap.postback_url IS NOT NULL 
      AND TRIM(ap.postback_url) != ''
    ORDER BY ap.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all active offers for offer-specific postbacks
$allOffers = $pdo->query("SELECT offer_id, offer_name, currency, payout FROM offers WHERE status = 'active' ORDER BY offer_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all offer-specific postbacks
$allOfferPostbacks = $pdo->query("
    SELECT ap.*, u.name as publisher_name, u.email as publisher_email, o.offer_name, o.currency
    FROM affiliate_postbacks ap
    INNER JOIN users u ON u.user_id = ap.affiliate_id
    INNER JOIN offers o ON o.offer_id = ap.offer_id
    WHERE ap.offer_id IS NOT NULL AND ap.offer_id > 0
    ORDER BY ap.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Totals
$stats = [
    'total_publishers' => count($allPublishers),
    'configured'       => count($configuredPostbacks),
    'active'           => count(array_filter($configuredPostbacks, function($p) { return ($p['postback_status'] ?? '') === 'active'; })),
    'offer_overrides'  => count($allOfferPostbacks)
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Publisher Postbacks Configuration | Admin Panel</title>
    
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
            padding: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            text-align: center;
        }

        .stat-card-custom .stat-number {
            font-size: 26px;
            font-weight: 800;
            color: #1e293b;
        }

        .stat-card-custom .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
        }

        .macro-tag {
            display: inline-block;
            background: #eef2ff;
            color: #4f46e5;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin: 2px;
            border: 1px solid #c7d2fe;
            cursor: pointer;
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
            <li class="nav-item d-none d-sm-inline-block"><a href="publishers.php" class="nav-link">Publishers</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="publisher_postbacks.php" class="nav-link active">Publisher Postbacks</a></li>
        </ul>
    </nav>

    <!-- Sidebar -->
        <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 font-weight-bold">Publisher Global Postbacks Setup</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="publishers.php">Publishers</a></li>
                            <li class="breadcrumb-item active">Publisher Postbacks</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
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

                <!-- Summary Stat Cards -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($stats['total_publishers']); ?></div>
                            <div class="stat-label">Total Publishers</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($stats['configured']); ?></div>
                            <div class="stat-label">Global Configured</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($stats['active']); ?></div>
                            <div class="stat-label">Active Triggers</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning"><?php echo number_format($stats['offer_overrides']); ?></div>
                            <div class="stat-label">Offer Overrides</div>
                        </div>
                    </div>
                </div>

                <!-- Macro Tokens Cheat Sheet -->
                <div class="card card-custom p-4">
                    <h5 class="font-weight-bold text-dark mb-2"><i class="fas fa-code text-primary mr-2"></i>Supported Publisher Postback URL Tokens:</h5>
                    <div>
                        <span class="macro-tag">{click_id}</span>
                        <span class="macro-tag">{payout}</span>
                        <span class="macro-tag">{status}</span>
                        <span class="macro-tag">{offer_id}</span>
                        <span class="macro-tag">{affiliate_id}</span>
                        <span class="macro-tag">{transaction_id}</span>
                        <span class="macro-tag">{sub1}</span>
                        <span class="macro-tag">{sub2}</span>
                        <span class="macro-tag">{sub3}</span>
                        <span class="macro-tag">{sub4}</span>
                        <span class="macro-tag">{sub5}</span>
                    </div>
                </div>

                <!-- Postback Management Tabs -->
                <div class="card card-custom p-4">
                    <ul class="nav nav-tabs mb-4 font-weight-bold" id="postbackTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="global-tab" data-toggle="tab" href="#tabGlobal" role="tab">
                                <i class="fas fa-globe mr-2"></i>Publisher Global Postbacks (<?php echo count($configuredPostbacks); ?>)
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="offers-tab" data-toggle="tab" href="#tabOffers" role="tab">
                                <i class="fas fa-bullseye mr-2"></i>Offer-Specific Overrides (<?php echo count($allOfferPostbacks); ?>)
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content" id="postbackTabsContent">
                        <!-- TAB 1: GLOBAL POSTBACKS DIRECTORY -->
                        <div class="tab-pane fade show active" id="tabGlobal" role="tabpanel">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-network-wired mr-2"></i>Publisher Global Postbacks</h4>
                                    <small class="text-muted">Active S2S Postbacks applied across all campaigns (Showing only configured publishers)</small>
                                </div>
                                <button type="button" class="btn btn-primary font-weight-bold shadow-sm" data-toggle="modal" data-target="#addGlobalPbModal" id="btnOpenAddGlobal">
                                    <i class="fas fa-plus-circle mr-1"></i> Add Global Postback
                                </button>
                            </div>

                            <?php if (empty($configuredPostbacks)): ?>
                                <div class="text-center py-5 bg-light rounded text-muted">
                                    <i class="fas fa-satellite-dish fa-3x mb-3 text-secondary"></i>
                                    <h5 class="font-weight-bold text-dark">No Global Postbacks Configured Yet</h5>
                                    <p class="mb-3 small">Click below to add a server-to-server global postback for any publisher.</p>
                                    <button type="button" class="btn btn-primary font-weight-bold shadow-sm" data-toggle="modal" data-target="#addGlobalPbModal">
                                        <i class="fas fa-plus-circle mr-1"></i> Add Global Postback
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle" id="postbacksTable">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>ID / Updated</th>
                                                <th>Publisher</th>
                                                <th>Label / Type</th>
                                                <th>Postback S2S URL</th>
                                                <th>Trigger</th>
                                                <th>Status</th>
                                                <th class="text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($configuredPostbacks as $pb): 
                                                $statusBadge = ($pb['postback_status'] === 'active') ? 'success' : 'secondary';
                                                $triggerText = (($pb['fire_status'] ?? 'approved') === 'all') ? 'All Conversions' : 'Approved Only';
                                                $pType = strtoupper($pb['postback_type'] ?? 'GLOBAL');
                                            ?>
                                            <tr>
                                                <td>
                                                    <strong>#<?php echo $pb['postback_id']; ?></strong>
                                                    <small class="d-block text-muted"><?php echo date('M d, Y H:i', strtotime($pb['postback_updated'])); ?></small>
                                                </td>
                                                <td>
                                                    <strong class="text-dark d-block">#<?php echo $pb['affiliate_id']; ?> - <?php echo htmlspecialchars($pb['publisher_name']); ?></strong>
                                                    <small class="text-muted d-block"><?php echo htmlspecialchars($pb['publisher_email']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge badge-info px-2 py-1"><?php echo htmlspecialchars($pType); ?></span>
                                                    <?php if (!empty($pb['postback_name'])): ?>
                                                        <small class="d-block text-muted mt-1 font-weight-bold"><?php echo htmlspecialchars($pb['postback_name']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <code class="small d-inline-block text-truncate mr-2 p-1 bg-light rounded" style="max-width: 320px;" title="<?php echo htmlspecialchars($pb['postback_url']); ?>">
                                                            <?php echo htmlspecialchars($pb['postback_url']); ?>
                                                        </code>
                                                        <button type="button" class="btn btn-xs btn-outline-secondary btn-copy-pb" data-url="<?php echo htmlspecialchars($pb['postback_url'], ENT_QUOTES); ?>" title="Copy Postback URL">
                                                            <i class="fas fa-copy"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-light border"><?php echo $triggerText; ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo $statusBadge; ?> p-2 font-weight-bold">
                                                        <?php echo ucfirst($pb['postback_status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-right">
                                                    <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold btn-edit-global mr-1"
                                                            data-id="<?php echo $pb['postback_id']; ?>"
                                                            data-aff="<?php echo $pb['affiliate_id']; ?>"
                                                            data-url="<?php echo htmlspecialchars($pb['postback_url'], ENT_QUOTES); ?>"
                                                            data-type="<?php echo htmlspecialchars($pb['postback_type'] ?? 'global', ENT_QUOTES); ?>"
                                                            data-name="<?php echo htmlspecialchars($pb['postback_name'] ?? '', ENT_QUOTES); ?>"
                                                            data-trigger="<?php echo htmlspecialchars($pb['fire_status'] ?? 'approved', ENT_QUOTES); ?>"
                                                            data-status="<?php echo htmlspecialchars($pb['postback_status'], ENT_QUOTES); ?>">
                                                        <i class="fas fa-edit mr-1"></i> Edit
                                                    </button>
                                                    <form method="post" style="display:inline-block;" onsubmit="return confirm('Remove this global postback configuration?');">
                                                        <input type="hidden" name="delete_global_postback" value="1">
                                                        <input type="hidden" name="postback_id" value="<?php echo $pb['postback_id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger font-weight-bold" title="Delete">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- TAB 2: OFFER-SPECIFIC OVERRIDES -->
                        <div class="tab-pane fade" id="tabOffers" role="tabpanel">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h4 class="font-weight-bold text-warning mb-0"><i class="fas fa-bullseye mr-2"></i>Offer-Specific Postback Overrides</h4>
                                    <small class="text-muted">Custom S2S Postbacks that take precedence over the Global Postback for specific campaigns</small>
                                </div>
                                <button type="button" class="btn btn-primary font-weight-bold shadow-sm" data-toggle="modal" data-target="#adminAddOfferPbModal">
                                    <i class="fas fa-plus mr-1"></i> Add Offer Override
                                </button>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover align-middle" id="offerPostbacksTable">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Publisher</th>
                                            <th>Campaign Offer</th>
                                            <th>Offer Postback URL</th>
                                            <th>Trigger</th>
                                            <th>Status</th>
                                            <th class="text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($allOfferPostbacks)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">
                                                No offer-specific postback overrides configured.
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($allOfferPostbacks as $opb): ?>
                                            <tr>
                                                <td>
                                                    <strong class="text-dark">#<?php echo $opb['affiliate_id']; ?> - <?php echo htmlspecialchars($opb['publisher_name']); ?></strong>
                                                    <small class="d-block text-muted"><?php echo htmlspecialchars($opb['publisher_email']); ?></small>
                                                </td>
                                                <td>
                                                    <strong class="text-primary">#<?php echo $opb['offer_id']; ?> - <?php echo htmlspecialchars($opb['offer_name']); ?></strong>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <code class="small d-inline-block text-truncate mr-2 p-1 bg-light rounded" style="max-width: 320px;" title="<?php echo htmlspecialchars($opb['postback_url']); ?>">
                                                            <?php echo htmlspecialchars($opb['postback_url']); ?>
                                                        </code>
                                                        <button type="button" class="btn btn-xs btn-outline-secondary btn-copy-pb" data-url="<?php echo htmlspecialchars($opb['postback_url'], ENT_QUOTES); ?>" title="Copy URL">
                                                            <i class="fas fa-copy"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge badge-light border"><?php echo ucfirst($opb['fire_status'] ?? 'approved'); ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge badge-<?php echo ($opb['status'] === 'active') ? 'success' : 'secondary'; ?> p-2 font-weight-bold">
                                                        <?php echo ucfirst($opb['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-right">
                                                    <form method="post" style="display:inline-block;" onsubmit="return confirm('Delete this offer postback override?');">
                                                        <input type="hidden" name="delete_offer_postback" value="1">
                                                        <input type="hidden" name="postback_id" value="<?php echo $opb['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger font-weight-bold">
                                                            <i class="fas fa-trash-alt mr-1"></i> Delete
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal: Add / Edit Global Postback -->
                <div class="modal fade" id="addGlobalPbModal" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered" role="document">
                        <div class="modal-content" style="border-radius: 12px;">
                            <form method="post" id="globalPbForm">
                                <input type="hidden" name="save_postback" value="1">
                                <input type="hidden" name="postback_id" id="modalPostbackId" value="">
                                <div class="modal-header bg-primary text-white">
                                    <h5 class="modal-title font-weight-bold" id="modalGlobalTitle"><i class="fas fa-globe mr-2"></i>Add Global S2S Postback</h5>
                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                </div>
                                <div class="modal-body p-4">
                                    <div class="form-group mb-3">
                                        <label class="font-weight-bold small">Select Publisher *</label>
                                        <select name="affiliate_id" id="modalAffiliateId" class="form-control" required>
                                            <option value="">-- Choose Publisher --</option>
                                            <?php foreach ($allPublishers as $pub): ?>
                                                <option value="<?php echo $pub['user_id']; ?>">
                                                    #<?php echo $pub['user_id']; ?> - <?php echo htmlspecialchars($pub['name']); ?> (<?php echo htmlspecialchars($pub['email']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label class="font-weight-bold small">Postback Label / Name (Optional)</label>
                                        <input type="text" name="postback_name" id="modalPostbackName" class="form-control" placeholder="e.g. Voluum / BeMob Global Tracker">
                                    </div>
                                    <div class="form-group mb-3">
                                        <label class="font-weight-bold small">Global Postback URL *</label>
                                        <input type="url" name="postback_url" id="modalPostbackUrl" class="form-control" placeholder="https://tracker.com/postback?cid={sub1}&payout={payout}" required>
                                        <small class="text-muted d-block mt-1">Available macros: <code>{click_id}</code>, <code>{sub1}</code>, <code>{sub2}</code>, <code>{payout}</code>, <code>{status}</code>, <code>{offer_id}</code></small>
                                    </div>
                                    <div class="form-row">
                                        <div class="col-md-4 mb-3">
                                            <label class="font-weight-bold small">Type</label>
                                            <select name="postback_type" id="modalPostbackType" class="form-control">
                                                <option value="global">Global S2S</option>
                                                <option value="hasoffers">HasOffers</option>
                                                <option value="cake">CAKE</option>
                                                <option value="custom">Custom</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="font-weight-bold small">Trigger</label>
                                            <select name="fire_status" id="modalFireStatus" class="form-control">
                                                <option value="approved">Approved Only</option>
                                                <option value="all">All Conversions</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="font-weight-bold small">Status</label>
                                            <select name="status" id="modalStatus" class="form-control">
                                                <option value="active">Active</option>
                                                <option value="inactive">Inactive</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary font-weight-bold"><i class="fas fa-save mr-1"></i> Save Postback</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Modal: Admin Add Offer Override -->
                <div class="modal fade" id="adminAddOfferPbModal" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered" role="document">
                        <div class="modal-content" style="border-radius: 12px;">
                            <form method="post">
                                <input type="hidden" name="save_offer_postback" value="1">
                                <div class="modal-header bg-primary text-white">
                                    <h5 class="modal-title font-weight-bold"><i class="fas fa-plus-circle mr-2"></i>Configure Offer Postback Override</h5>
                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                </div>
                                <div class="modal-body p-4">
                                    <div class="form-group mb-3">
                                        <label class="font-weight-bold small">Select Publisher *</label>
                                        <select name="affiliate_id" class="form-control" required>
                                            <option value="">-- Choose Publisher --</option>
                                            <?php foreach ($allPublishers as $pub): ?>
                                                <option value="<?php echo $pub['user_id']; ?>">
                                                    #<?php echo $pub['user_id']; ?> - <?php echo htmlspecialchars($pub['name']); ?> (<?php echo htmlspecialchars($pub['email']); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label class="font-weight-bold small">Select Campaign Offer *</label>
                                        <select name="offer_id" class="form-control" required>
                                            <option value="">-- Choose Campaign Offer --</option>
                                            <?php foreach ($allOffers as $ao): ?>
                                                <option value="<?php echo $ao['offer_id']; ?>">
                                                    #<?php echo $ao['offer_id']; ?> - <?php echo htmlspecialchars($ao['offer_name']); ?> (<?php echo ($ao['currency'] ?? 'USD') . ' ' . number_format($ao['payout'], 2); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label class="font-weight-bold small">Offer-Specific Postback URL *</label>
                                        <input type="url" name="postback_url" class="form-control" placeholder="https://tracker.com/postback?cid={sub1}&payout={payout}" required>
                                    </div>
                                    <div class="form-row">
                                        <div class="col-md-6 mb-3">
                                            <label class="font-weight-bold small">Conversion Trigger</label>
                                            <select name="fire_status" class="form-control">
                                                <option value="approved">Approved Only</option>
                                                <option value="all">All (Approved + Pending)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="font-weight-bold small">Status</label>
                                            <select name="status" class="form-control">
                                                <option value="active">Active</option>
                                                <option value="inactive">Inactive</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary font-weight-bold"><i class="fas fa-save mr-1"></i> Save Override</button>
                                </div>
                            </form>
                        </div>
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
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>

<script>
$(document).ready(function() {
    if ($('#postbacksTable tbody tr').length > 1 || $('#postbacksTable tbody tr td[colspan]').length === 0) {
        $('#postbacksTable').DataTable({
            pageLength: 10,
            responsive: true,
            order: [[0, 'desc']]
        });
    }
    if ($('#offerPostbacksTable tbody tr').length > 1 || $('#offerPostbacksTable tbody tr td[colspan]').length === 0) {
        $('#offerPostbacksTable').DataTable({
            pageLength: 10,
            responsive: true,
            order: [[0, 'desc']]
        });
    }

    // Reset Global Postback modal for new entry
    $('#btnOpenAddGlobal').on('click', function() {
        $('#globalPbForm')[0].reset();
        $('#modalPostbackId').val('');
        $('#modalGlobalTitle').html('<i class="fas fa-globe mr-2"></i>Add Global S2S Postback');
        $('#modalAffiliateId').val('');
    });

    // Populate Global Postback modal for editing
    $(document).on('click', '.btn-edit-global', function() {
        var id = $(this).data('id');
        var aff = $(this).data('aff');
        var url = $(this).data('url');
        var type = $(this).data('type') || 'global';
        var name = $(this).data('name') || '';
        var trigger = $(this).data('trigger') || 'approved';
        var status = $(this).data('status') || 'active';

        $('#modalPostbackId').val(id);
        $('#modalAffiliateId').val(aff);
        $('#modalPostbackUrl').val(url);
        $('#modalPostbackName').val(name);
        $('#modalPostbackType').val(type);
        $('#modalFireStatus').val(trigger);
        $('#modalStatus').val(status);
        $('#modalGlobalTitle').html('<i class="fas fa-edit mr-2"></i>Edit Global Postback #' + id);

        $('#addGlobalPbModal').modal('show');
    });

    // 1-Click Copy Postback URL
    $(document).on('click', '.btn-copy-pb', function() {
        var url = $(this).data('url');
        var $btn = $(this);
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function() {
                $btn.html('<i class="fas fa-check text-success"></i>');
                setTimeout(function() {
                    $btn.html('<i class="fas fa-copy"></i>');
                }, 1500);
            });
        }
    });
});
</script>
</body>
</html>