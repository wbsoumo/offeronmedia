<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('manager');

$managerId   = auth_user_id();
$managerName = $_SESSION['user_name'] ?? 'Account Manager';
$success     = $error = null;
$grantedLinks = [];

/* =====================================================
   1. GRANT OFFER ACCESS TO PUBLISHER(S)
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_offer'])) {
    $publisherIds = $_POST['affiliate_ids'] ?? [];
    if (!is_array($publisherIds) && !empty($_POST['affiliate_id'])) {
        $publisherIds = [(int)$_POST['affiliate_id']];
    }
    $offerId      = (int)($_POST['offer_id'] ?? 0);
    $customPayout = isset($_POST['custom_payout']) && $_POST['custom_payout'] !== '' ? (float)$_POST['custom_payout'] : null;
    $dailyCap     = isset($_POST['daily_cap']) && $_POST['daily_cap'] !== '' ? (int)$_POST['daily_cap'] : null;
    $capType      = in_array($_POST['cap_type'] ?? '', ['conversions', 'clicks'], true) ? $_POST['cap_type'] : 'conversions';
    $notes        = trim($_POST['notes'] ?? '');

    if (empty($publisherIds) || !$offerId) {
        $error = 'Please select at least one Publisher and a Campaign Offer.';
    } else {
        try {
            // Verify offer
            $ofStmt = $pdo->prepare("SELECT offer_id, offer_name, payout, currency FROM offers WHERE offer_id = ? LIMIT 1");
            $ofStmt->execute([$offerId]);
            $targetOffer = $ofStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetOffer) {
                $error = 'Campaign offer not found.';
            } else {
                $aoaCols = $pdo->query("SHOW COLUMNS FROM affiliate_offer_approval")->fetchAll(PDO::FETCH_COLUMN);
                $grantedCount = 0;

                foreach ($publisherIds as $pid) {
                    $pid = (int)$pid;
                    if (!$pid) continue;

                    // Verify publisher belongs to this manager
                    $checkPub = $pdo->prepare("SELECT user_id, name, email FROM users WHERE user_id = ? AND role_id = 3 AND account_manager_id = ? LIMIT 1");
                    $checkPub->execute([$pid, $managerId]);
                    $uData = $checkPub->fetch(PDO::FETCH_ASSOC);
                    if (!$uData) continue;

                    // Check if permission already exists
                    $checkStmt = $pdo->prepare("SELECT id FROM affiliate_offer_approval WHERE affiliate_id = ? AND offer_id = ? LIMIT 1");
                    $checkStmt->execute([$pid, $offerId]);
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        $updFields = ["status = 'approved'", "approved_at = NOW()"];
                        $updParams = [':id' => $existing['id']];

                        if ($customPayout !== null) {
                            $updFields[] = "custom_payout = :payout";
                            $updParams[':payout'] = $customPayout;
                        }
                        if ($dailyCap !== null) {
                            $updFields[] = "daily_cap = :daily_cap";
                            $updParams[':daily_cap'] = $dailyCap;
                        }
                        if (in_array('cap_type', $aoaCols, true)) {
                            $updFields[] = "cap_type = :cap_type";
                            $updParams[':cap_type'] = $capType;
                        }
                        if ($notes !== '') {
                            $updFields[] = "notes = :notes";
                            $updParams[':notes'] = $notes;
                        }
                        if (in_array('suspended_by_user_block', $aoaCols, true)) {
                            $updFields[] = "suspended_by_user_block = 0";
                        }

                        $upd = $pdo->prepare("UPDATE affiliate_offer_approval SET " . implode(', ', $updFields) . " WHERE id = :id");
                        $upd->execute($updParams);
                    } else {
                        $insCols = ['affiliate_id', 'offer_id', 'status', 'approved_at', 'created_by'];
                        $insVals = [':aff_id', ':offer_id', "'approved'", 'NOW()', ':created_by'];
                        $insParams = [
                            ':aff_id'     => $pid,
                            ':offer_id'   => $offerId,
                            ':created_by' => $managerName
                        ];

                        if ($customPayout !== null) {
                            $insCols[] = 'custom_payout';
                            $insVals[] = ':custom_payout';
                            $insParams[':custom_payout'] = $customPayout;
                        }
                        if ($dailyCap !== null) {
                            $insCols[] = 'daily_cap';
                            $insVals[] = ':daily_cap';
                            $insParams[':daily_cap'] = $dailyCap;
                        }
                        if (in_array('cap_type', $aoaCols, true)) {
                            $insCols[] = 'cap_type';
                            $insVals[] = ':cap_type';
                            $insParams[':cap_type'] = $capType;
                        }
                        if ($notes !== '') {
                            $insCols[] = 'notes';
                            $insVals[] = ':notes';
                            $insParams[':notes'] = $notes;
                        }

                        $ins = $pdo->prepare("INSERT INTO affiliate_offer_approval (" . implode(', ', $insCols) . ") VALUES (" . implode(', ', $insVals) . ")");
                        $ins->execute($insParams);
                    }

                    $grantedCount++;
                    $trackingLink = "https://offeronmedia.com/click.php?aff_id={$pid}&offer_id={$offerId}";
                    $grantedLinks[] = [
                        'publisher_id'   => $pid,
                        'publisher_name' => $uData['name'],
                        'publisher_email'=> $uData['email'],
                        'offer_id'       => $offerId,
                        'offer_name'     => $targetOffer['offer_name'],
                        'tracking_link'  => $trackingLink
                    ];
                }

                $success = "Successfully granted access to {$grantedCount} publisher(s)! Tracking links are active and ready.";
            }
        } catch (Exception $e) {
            $error = 'Error granting offer access: ' . $e->getMessage();
        }
    }
}

/* =====================================================
   2. HANDLE STATUS UPDATE (APPROVE / REJECT PERMISSION)
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_access_status'])) {
    $approvalId   = (int)($_POST['approval_id'] ?? 0);
    $newStatus    = $_POST['status'] ?? '';
    $customPayout = isset($_POST['custom_payout']) && $_POST['custom_payout'] !== '' ? (float)$_POST['custom_payout'] : null;
    $dailyCap     = isset($_POST['daily_cap']) && $_POST['daily_cap'] !== '' ? (int)$_POST['daily_cap'] : null;
    $capType      = in_array($_POST['cap_type'] ?? '', ['conversions', 'clicks'], true) ? $_POST['cap_type'] : 'conversions';

    if ($approvalId > 0 && in_array($newStatus, ['approved', 'rejected'], true)) {
        $vStmt = $pdo->prepare("
            SELECT aoa.id, u.name as publisher_name, o.offer_name 
            FROM affiliate_offer_approval aoa
            JOIN users u ON u.user_id = aoa.affiliate_id
            JOIN offers o ON o.offer_id = aoa.offer_id
            WHERE aoa.id = ? AND u.account_manager_id = ?
            LIMIT 1
        ");
        $vStmt->execute([$approvalId, $managerId]);
        $appItem = $vStmt->fetch(PDO::FETCH_ASSOC);

        if ($appItem) {
            $updSets = ["status = ?", "approved_at = NOW()"];
            $updParams = [$newStatus];

            if ($customPayout !== null) {
                $updSets[] = "custom_payout = ?";
                $updParams[] = $customPayout;
            }
            if ($dailyCap !== null) {
                $updSets[] = "daily_cap = ?";
                $updParams[] = $dailyCap;
            }
            $updSets[] = "cap_type = ?";
            $updParams[] = $capType;

            $updParams[] = $approvalId;
            $updStmt = $pdo->prepare("UPDATE affiliate_offer_approval SET " . implode(', ', $updSets) . " WHERE id = ?");
            $updStmt->execute($updParams);

            $label = ($newStatus === 'approved') ? 'Approved' : 'Rejected / Revoked';
            $success = "Access for {$appItem['publisher_name']} on '{$appItem['offer_name']}' successfully {$label}!";
        } else {
            $error = "Permission record not found or publisher does not belong to your portfolio.";
        }
    }
}

/* =====================================================
   3. DATA FETCHING (SCOPED TO MANAGER'S PORTFOLIO)
===================================================== */
// Manager's active publishers
$myPublishersStmt = $pdo->prepare("
    SELECT user_id, name, email, company, status 
    FROM users 
    WHERE role_id = 3 AND account_manager_id = ? 
    ORDER BY name ASC
");
$myPublishersStmt->execute([$managerId]);
$myPublishers = $myPublishersStmt->fetchAll(PDO::FETCH_ASSOC);

// Available active campaigns
$offersStmt = $pdo->query("
    SELECT offer_id, offer_name, payout, currency, visibility, status 
    FROM offers 
    WHERE status != 'archived' 
    ORDER BY offer_name ASC
");
$offers = $offersStmt->fetchAll(PDO::FETCH_ASSOC);

// Pending offer applications for this manager
$pendingStmt = $pdo->prepare("
    SELECT 
        aoa.id,
        aoa.affiliate_id,
        aoa.offer_id,
        aoa.custom_payout,
        aoa.daily_cap,
        aoa.cap_type,
        aoa.notes,
        aoa.created_at,
        u.name as publisher_name,
        u.email as publisher_email,
        u.company as publisher_company,
        o.offer_name,
        o.payout as default_payout,
        o.currency
    FROM affiliate_offer_approval aoa
    JOIN users u ON u.user_id = aoa.affiliate_id
    JOIN offers o ON o.offer_id = aoa.offer_id
    WHERE aoa.status = 'pending' 
      AND u.account_manager_id = ?
    ORDER BY aoa.created_at ASC
");
$pendingStmt->execute([$managerId]);
$pendingRequests = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
$pendingOfferCount = count($pendingRequests);

// Complete access registry for this manager's publishers
$regStmt = $pdo->prepare("
    SELECT 
        aoa.id,
        aoa.affiliate_id,
        aoa.offer_id,
        aoa.status,
        aoa.custom_payout,
        aoa.daily_cap,
        aoa.cap_type,
        aoa.notes,
        aoa.approved_at,
        aoa.created_at,
        u.name as publisher_name,
        u.email as publisher_email,
        u.company as publisher_company,
        o.offer_name,
        o.payout as original_payout,
        o.currency
    FROM affiliate_offer_approval aoa
    JOIN users u ON u.user_id = aoa.affiliate_id
    JOIN offers o ON o.offer_id = aoa.offer_id
    WHERE u.account_manager_id = ?
    ORDER BY aoa.id DESC
");
$regStmt->execute([$managerId]);
$assignments = $regStmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = ['total' => count($assignments), 'approved' => 0, 'pending' => $pendingOfferCount, 'rejected' => 0];
foreach ($assignments as $as) {
    if ($as['status'] === 'approved') $stats['approved']++;
    elseif ($as['status'] === 'rejected') $stats['rejected']++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaign Access Rules & Approvals | Manager Console</title>

    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">

    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            --brand-color: #2563eb;
        }
        .manager-header-badge {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 4px 14px rgba(37,99,235,0.25);
        }
        .stat-card-custom {
            background: #fff;
            border-radius: 10px;
            padding: 16px 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            text-align: center;
        }
        .stat-card-custom .stat-val { font-size: 26px; font-weight: 800; color: #1e293b; }
        .stat-card-custom .stat-lbl { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; }
        .generated-link-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 4px solid #10b981;
            border-radius: 8px;
            padding: 14px;
            margin-bottom: 12px;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="campaigns.php" class="nav-link">Campaigns</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="campaign_access.php" class="nav-link active font-weight-bold">Offer Approvals</a></li>
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
                        <h1 class="m-0 font-weight-bold"><i class="fas fa-key text-primary mr-2"></i>Offer Access Rules & Approvals</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item"><a href="campaigns.php">Campaigns</a></li>
                            <li class="breadcrumb-item active">Offer Approvals</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <!-- Alert Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                </div>
                <?php endif; ?>

                <!-- Informational Header Banner -->
                <div class="manager-header-badge">
                    <div class="row align-items-center">
                        <div class="col-md-9">
                            <h4 class="font-weight-bold mb-1"><i class="fas fa-user-shield mr-2"></i>Publisher Access & Permission Center (Offer18 Standard)</h4>
                            <p class="mb-0 text-white-50">
                                Review pending publisher offer requests, grant private campaign access, override payout tiers, and configure individual daily caps (by conversions or clicks) for your assigned publishers.
                            </p>
                        </div>
                        <div class="col-md-3 text-md-right mt-3 mt-md-0">
                            <a href="campaigns.php" class="btn btn-light font-weight-bold">
                                <i class="fas fa-bullhorn mr-1 text-primary"></i> Browse All Offers
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Stats Overview Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 col-6 mb-2">
                        <div class="stat-card-custom">
                            <div class="stat-val text-primary"><?php echo number_format($stats['total']); ?></div>
                            <div class="stat-lbl">Total Permissions</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-2">
                        <div class="stat-card-custom">
                            <div class="stat-val text-success"><?php echo number_format($stats['approved']); ?></div>
                            <div class="stat-lbl">Approved Access</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-2">
                        <div class="stat-card-custom">
                            <div class="stat-val text-warning"><?php echo number_format($stats['pending']); ?></div>
                            <div class="stat-lbl">Pending Review</div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6 mb-2">
                        <div class="stat-card-custom">
                            <div class="stat-val text-danger"><?php echo number_format($stats['rejected']); ?></div>
                            <div class="stat-lbl">Blocked / Rejected</div>
                        </div>
                    </div>
                </div>

                <!-- Pending Offer Requests Queue -->
                <?php if (!empty($pendingRequests)): ?>
                <div class="card card-warning card-outline shadow-sm mb-4">
                    <div class="card-header bg-warning py-3">
                        <h5 class="card-title font-weight-bold mb-0 text-dark">
                            <i class="fas fa-clock mr-2"></i>Pending Offer Applications Queue (<?php echo count($pendingRequests); ?>)
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Publisher</th>
                                        <th>Campaign Offer</th>
                                        <th>Default Payout</th>
                                        <th>Custom Payout</th>
                                        <th>Daily Cap & Rule</th>
                                        <th>Applied Date</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pendingRequests as $pReq): ?>
                                    <tr>
                                        <td>
                                            <strong>#<?php echo $pReq['affiliate_id']; ?> - <?php echo htmlspecialchars($pReq['publisher_name']); ?></strong>
                                            <small class="d-block text-muted"><?php echo htmlspecialchars($pReq['publisher_email']); ?></small>
                                        </td>
                                        <td>
                                            <strong class="text-primary">#<?php echo $pReq['offer_id']; ?> - <?php echo htmlspecialchars($pReq['offer_name']); ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge badge-light border"><?php echo format_currency($pReq['default_payout'], $pReq['currency'] ?? 'USD'); ?></span>
                                        </td>
                                        <form method="post">
                                            <input type="hidden" name="update_access_status" value="1">
                                            <input type="hidden" name="approval_id" value="<?php echo $pReq['id']; ?>">
                                            <td>
                                                <input type="number" step="0.01" min="0" name="custom_payout" class="form-control form-control-sm" style="width: 110px;" placeholder="<?php echo number_format($pReq['default_payout'], 2); ?>" value="<?php echo $pReq['custom_payout'] ?? ''; ?>">
                                            </td>
                                            <td>
                                                <div class="input-group input-group-sm" style="width: 190px;">
                                                    <input type="number" step="1" min="1" name="daily_cap" class="form-control" placeholder="Cap Limit" value="<?php echo $pReq['daily_cap'] ?? ''; ?>">
                                                    <div class="input-group-append">
                                                        <select name="cap_type" class="custom-select form-control-sm">
                                                            <option value="conversions" <?php echo ($pReq['cap_type'] ?? '') === 'conversions' ? 'selected' : ''; ?>>Leads</option>
                                                            <option value="clicks" <?php echo ($pReq['cap_type'] ?? '') === 'clicks' ? 'selected' : ''; ?>>Clicks</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?php echo date('M d, H:i', strtotime($pReq['created_at'])); ?></small>
                                            </td>
                                            <td class="text-right">
                                                <button type="submit" name="status" value="approved" class="btn btn-sm btn-success font-weight-bold mr-1">
                                                    <i class="fas fa-check mr-1"></i> Approve
                                                </button>
                                                <button type="submit" name="status" value="rejected" class="btn btn-sm btn-outline-danger font-weight-bold" onclick="return confirm('Reject offer application for this publisher?')">
                                                    <i class="fas fa-times mr-1"></i> Reject
                                                </button>
                                            </td>
                                        </form>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Generated Tracking Links Notification Box -->
                <?php if (!empty($grantedLinks)): ?>
                <div class="card card-success card-outline shadow-sm p-4 mb-4">
                    <h5 class="font-weight-bold text-success mb-3"><i class="fas fa-link mr-2"></i>Generated Campaign Tracking Links (Ready to Share)</h5>
                    <?php foreach ($grantedLinks as $gIndex => $gLink): ?>
                        <div class="generated-link-box">
                            <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                                <div>
                                    <strong class="text-dark"><i class="fas fa-user mr-1 text-primary"></i><?php echo htmlspecialchars($gLink['publisher_name']); ?></strong>
                                    <small class="text-muted">(<?php echo htmlspecialchars($gLink['publisher_email']); ?>)</small>
                                </div>
                                <span class="badge badge-info p-2"><i class="fas fa-bullhorn mr-1"></i><?php echo htmlspecialchars($gLink['offer_name']); ?></span>
                            </div>
                            <div class="input-group">
                                <input type="text" id="mgrTrackUrl_<?php echo $gIndex; ?>" class="form-control font-weight-bold bg-white" value="<?php echo htmlspecialchars($gLink['tracking_link']); ?>" readonly>
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-primary font-weight-bold" onclick="copyMgrLink('mgrTrackUrl_<?php echo $gIndex; ?>')">
                                        <i class="fas fa-copy mr-1"></i> Copy Link
                                    </button>
                                    <a href="https://api.whatsapp.com/send?text=<?php echo urlencode("Hi, here is your exclusive tracking link for " . $gLink['offer_name'] . ": " . $gLink['tracking_link']); ?>" target="_blank" class="btn btn-success font-weight-bold">
                                        <i class="fab fa-whatsapp mr-1"></i> Share
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Grant Private Offer Access Form -->
                <div class="card shadow-sm p-4 mb-4" style="border-radius: 12px; border: 1px solid #e2e8f0;">
                    <h5 class="font-weight-bold text-primary mb-3"><i class="fas fa-key mr-2"></i>Grant Private Offer Access & Generate Tracking Links</h5>
                    <form method="post" id="mgrGrantForm">
                        <input type="hidden" name="assign_offer" value="1">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <label class="font-weight-bold mb-0">Select Publisher(s) <span class="text-danger">*</span></label>
                                        <div>
                                            <button type="button" class="btn btn-xs btn-outline-primary" id="btnMgrSelectAll">Select All</button>
                                            <button type="button" class="btn btn-xs btn-outline-secondary ml-1" id="btnMgrClearAll">Clear</button>
                                        </div>
                                    </div>
                                    <select name="affiliate_ids[]" id="mgrPublisherSelect" class="form-control select2" multiple="multiple" data-placeholder="Search and choose publisher(s)..." required>
                                        <?php foreach ($myPublishers as $pub): ?>
                                        <option value="<?php echo $pub['user_id']; ?>" data-name="<?php echo htmlspecialchars($pub['name'], ENT_QUOTES); ?>">
                                            #<?php echo $pub['user_id']; ?> - <?php echo htmlspecialchars($pub['name']); ?> (<?php echo htmlspecialchars($pub['email']); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                    <!-- Dynamic Tag Container -->
                                    <div class="mt-2 p-2 bg-light rounded border">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <small class="text-muted font-weight-bold text-uppercase">Selected Publishers (<span id="mgrPubCount">0</span>):</small>
                                        </div>
                                        <div id="mgrSelectedPubsContainer" class="d-flex flex-wrap" style="gap: 5px; min-height: 28px;">
                                            <span class="text-muted small font-italic" id="noMgrPubsMsg">No publishers selected yet.</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Select Campaign Offer <span class="text-danger">*</span></label>
                                    <select name="offer_id" id="mgrOfferSelect" class="form-control select2" data-placeholder="Choose Campaign Offer..." required>
                                        <option value=""></option>
                                        <?php 
                                            $preOfferId = (int)($_GET['offer_id'] ?? 0);
                                            foreach ($offers as $of): 
                                        ?>
                                        <option value="<?php echo $of['offer_id']; ?>" <?php echo ($preOfferId === (int)$of['offer_id']) ? 'selected' : ''; ?>>
                                            #<?php echo $of['offer_id']; ?> - <?php echo htmlspecialchars($of['offer_name']); ?> ($<?php echo number_format($of['payout'], 2); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label class="font-weight-bold">Custom Payout Override</label>
                                            <div class="input-group">
                                                <div class="input-group-prepend"><span class="input-group-text font-weight-bold">$</span></div>
                                                <input type="number" step="0.01" min="0" name="custom_payout" class="form-control font-weight-bold" placeholder="Default Payout">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group mb-3">
                                            <label class="font-weight-bold">Daily Cap & Rule</label>
                                            <div class="input-group">
                                                <input type="number" step="1" min="1" name="daily_cap" class="form-control font-weight-bold" placeholder="Unlimited">
                                                <div class="input-group-append">
                                                    <select name="cap_type" class="custom-select font-weight-bold" style="max-width: 140px;">
                                                        <option value="conversions">Leads / Day</option>
                                                        <option value="clicks">Clicks / Day</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Internal Note (Optional)</label>
                                    <input type="text" name="notes" class="form-control" placeholder="e.g. Approved for Facebook traffic only">
                                </div>

                                <button type="submit" class="btn btn-success btn-block font-weight-bold shadow-sm" style="height: 44px; border-radius: 8px; font-size: 15px;">
                                    <i class="fas fa-check-circle mr-1"></i> Grant Access & Generate Links
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Publisher Campaign Access & Links Registry -->
                <div class="card shadow-sm p-4 mb-4" style="border-radius: 12px; border: 1px solid #e2e8f0;">
                    <h5 class="font-weight-bold text-primary mb-3"><i class="fas fa-list-check mr-2"></i>Publisher Campaign Access & Links Registry</h5>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="mgrAccessDataTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Publisher</th>
                                    <th>Campaign Offer</th>
                                    <th>Payout Rate</th>
                                    <th>Daily Cap Rule</th>
                                    <th>Tracking Link</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $idx => $as): ?>
                                <?php $tblLink = "https://offeronmedia.com/click.php?aff_id={$as['affiliate_id']}&offer_id={$as['offer_id']}"; ?>
                                <tr>
                                    <td><strong>#<?php echo $as['id']; ?></strong></td>
                                    <td>
                                        <strong class="text-dark font-weight-bold"><?php echo htmlspecialchars($as['publisher_name']); ?></strong>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($as['publisher_email']); ?></small>
                                    </td>
                                    <td>
                                        <strong class="text-primary">#<?php echo $as['offer_id']; ?> - <?php echo htmlspecialchars($as['offer_name']); ?></strong>
                                    </td>
                                    <td>
                                        <strong class="text-success"><?php echo format_currency($as['custom_payout'] ?? $as['original_payout'], $as['currency']); ?></strong>
                                    </td>
                                    <td>
                                        <?php if (!empty($as['daily_cap']) && (int)$as['daily_cap'] > 0): ?>
                                            <?php if (($as['cap_type'] ?? '') === 'clicks'): ?>
                                                <span class="badge badge-info p-2"><i class="fas fa-mouse-pointer mr-1"></i><?php echo number_format($as['daily_cap']); ?> clicks/day</span>
                                            <?php else: ?>
                                                <span class="badge badge-primary p-2"><i class="fas fa-bullseye mr-1"></i><?php echo number_format($as['daily_cap']); ?> leads/day</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-light border text-muted">Unlimited</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm" style="min-width: 240px;">
                                            <input type="text" id="regUrl_<?php echo $idx; ?>" class="form-control text-xs" value="<?php echo $tblLink; ?>" readonly>
                                            <div class="input-group-append">
                                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="copyMgrLink('regUrl_<?php echo $idx; ?>')" title="Copy Link"><i class="fas fa-copy"></i></button>
                                                <a href="https://api.whatsapp.com/send?text=<?php echo urlencode("Tracking link for " . $as['offer_name'] . ": " . $tblLink); ?>" target="_blank" class="btn btn-outline-success btn-sm" title="Share Link"><i class="fab fa-whatsapp"></i></a>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        $stClass = 'secondary';
                                        if ($as['status'] === 'approved') $stClass = 'success';
                                        elseif ($as['status'] === 'pending') $stClass = 'warning';
                                        elseif ($as['status'] === 'rejected') $stClass = 'danger';
                                        ?>
                                        <span class="badge badge-<?php echo $stClass; ?> p-2"><?php echo ucfirst($as['status']); ?></span>
                                    </td>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="approval_id" value="<?php echo $as['id']; ?>">
                                            <input type="hidden" name="update_access_status" value="1">
                                            <?php if ($as['status'] !== 'approved'): ?>
                                            <button type="submit" name="status" value="approved" class="btn btn-sm btn-outline-success mr-1" title="Approve Access"><i class="fas fa-check"></i></button>
                                            <?php endif; ?>
                                            <?php if ($as['status'] !== 'rejected'): ?>
                                            <button type="submit" name="status" value="rejected" class="btn btn-sm btn-outline-danger" title="Reject / Revoke Access" onclick="return confirm('Revoke offer access for this publisher?')"><i class="fas fa-ban"></i></button>
                                            <?php endif; ?>
                                        </form>
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
        <div class="float-right d-none d-sm-inline"><strong>Manager Portal v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">Offer on Media</a>.</strong> All rights reserved.
    </footer>
</div>

<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
function copyMgrLink(elementId) {
    var copyText = document.getElementById(elementId);
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);
    alert("Tracking link copied to clipboard:\n" + copyText.value);
}

$(document).ready(function() {
    $('.select2').select2({
        theme: 'bootstrap4',
        width: '100%',
        allowClear: true
    });

    // Dynamic Manager Publisher Chips sync
    function updateMgrPublisherChips() {
        var selectedData = $('#mgrPublisherSelect').select2('data') || [];
        var count = selectedData.length;
        $('#mgrPubCount').text(count);

        var container = $('#mgrSelectedPubsContainer');
        if (count === 0) {
            container.html('<span class="text-muted small font-italic" id="noMgrPubsMsg">No publishers selected yet.</span>');
            return;
        }

        var html = '';
        selectedData.forEach(function(item) {
            html += '<span class="badge badge-primary p-2 d-inline-flex align-items-center" style="font-size: 12px; border-radius: 6px;">' +
                    $('<div>').text(item.text).html() +
                    '<button type="button" class="btn btn-xs text-white p-0 ml-2 btn-remove-mgr-tag" data-id="' + item.id + '" style="background:none; border:none; font-weight:bold; font-size:14px; line-height:1;">&times;</button>' +
                    '</span>';
        });
        container.html(html);
    }

    $('#mgrPublisherSelect').on('change', function() {
        updateMgrPublisherChips();
    });

    $(document).on('click', '.btn-remove-mgr-tag', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var currentVals = $('#mgrPublisherSelect').val() || [];
        var newVals = currentVals.filter(function(v) { return String(v) !== String(id); });
        $('#mgrPublisherSelect').val(newVals).trigger('change');
    });

    $('#btnMgrSelectAll').on('click', function() {
        var allIds = [];
        $('#mgrPublisherSelect option').each(function() {
            if ($(this).val()) allIds.push($(this).val());
        });
        $('#mgrPublisherSelect').val(allIds).trigger('change');
    });

    $('#btnMgrClearAll').on('click', function() {
        $('#mgrPublisherSelect').val([]).trigger('change');
    });

    $('#mgrAccessDataTable').DataTable({
        pageLength: 10,
        responsive: true,
        order: [[0, 'desc']]
    });
});
</script>
</body>
</html>