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

// Fetch Manager's Assigned Publishers
$pubStmt = $pdo->prepare("
    SELECT user_id, name, email, company 
    FROM users 
    WHERE role_id = 3 AND account_manager_id = ? AND status = 'active'
    ORDER BY name ASC
");
$pubStmt->execute([$managerId]);
$myPublishers = $pubStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle Offer Assignment to Publisher(s)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_offer'])) {
    if (!manager_can('assign_offers')) {
        $error = "Permission Denied: You do not have administrative authorization to assign offers to publishers.";
    } else {
        $offerId      = (int)($_POST['offer_id'] ?? 0);
        $publisherIds = $_POST['publisher_ids'] ?? [];
        $customPayout = isset($_POST['custom_payout']) && $_POST['custom_payout'] !== '' ? (float)$_POST['custom_payout'] : null;
        $dailyCap     = isset($_POST['daily_cap']) && $_POST['daily_cap'] !== '' ? (int)$_POST['daily_cap'] : null;
        $capType      = in_array($_POST['cap_type'] ?? '', ['conversions', 'clicks'], true) ? $_POST['cap_type'] : 'conversions';
        $notes        = trim($_POST['notes'] ?? '');

        if ($offerId <= 0 || empty($publisherIds)) {
            $error = "Please select a campaign offer and at least one publisher to assign.";
        } else {
            // Fetch target offer
            $ofStmt = $pdo->prepare("SELECT offer_id, offer_name, visibility FROM offers WHERE offer_id = ? LIMIT 1");
            $ofStmt->execute([$offerId]);
            $targetOffer = $ofStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetOffer) {
                $error = "Campaign offer not found.";
            } else {
                $assignedCount = 0;
                foreach ($publisherIds as $pubId) {
                    $pubId = (int)$pubId;
                    if ($pubId <= 0) continue;

                    // Validate that publisher belongs to this manager
                    $checkPub = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ? AND role_id = 3 AND account_manager_id = ? LIMIT 1");
                    $checkPub->execute([$pubId, $managerId]);
                    if (!$checkPub->fetch()) {
                        continue;
                    }

                    // Insert or update approval with custom payout, daily cap, and cap type
                    $chk = $pdo->prepare("SELECT id FROM affiliate_offer_approval WHERE affiliate_id = ? AND offer_id = ? LIMIT 1");
                    $chk->execute([$pubId, $offerId]);
                    $existing = $chk->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        $upd = $pdo->prepare("UPDATE affiliate_offer_approval SET status = 'approved', custom_payout = ?, daily_cap = ?, cap_type = ?, notes = ?, approved_at = NOW() WHERE id = ?");
                        $upd->execute([$customPayout, $dailyCap, $capType, $notes, $existing['id']]);
                    } else {
                        $ins = $pdo->prepare("
                            INSERT INTO affiliate_offer_approval 
                                (affiliate_id, offer_id, status, custom_payout, daily_cap, cap_type, notes, created_by, approved_at)
                            VALUES 
                                (?, ?, 'approved', ?, ?, ?, ?, ?, NOW())
                        ");
                        $ins->execute([$pubId, $offerId, $customPayout, $dailyCap, $capType, $notes, $managerName]);
                    }
                    $assignedCount++;
                }

                $success = "Successfully granted access to {$assignedCount} publisher(s)! This offer is now live in their offer list.";
            }
        }
    }
}

// Handle Offer Status Update (Approve / Reject Access with optional Payout & Cap)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_access_status'])) {
    $approvalId   = (int)($_POST['approval_id'] ?? 0);
    $newStatus    = $_POST['status'] ?? '';
    $customPayout = isset($_POST['custom_payout']) && $_POST['custom_payout'] !== '' ? (float)$_POST['custom_payout'] : null;
    $dailyCap     = isset($_POST['daily_cap']) && $_POST['daily_cap'] !== '' ? (int)$_POST['daily_cap'] : null;
    $capType      = in_array($_POST['cap_type'] ?? '', ['conversions', 'clicks'], true) ? $_POST['cap_type'] : 'conversions';

    if ($approvalId > 0 && in_array($newStatus, ['approved', 'rejected'], true)) {
        // Validate that this approval record belongs to a publisher assigned to this manager
        $vStmt = $pdo->prepare("
            SELECT aoa.id, u.name as publisher_name, o.offer_name, o.payout as default_payout 
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

            $actionLabel = ($newStatus === 'approved') ? 'Approved' : 'Rejected / Revoked';
            $success = "Access for {$appItem['publisher_name']} on '{$appItem['offer_name']}' successfully {$actionLabel}!";
        } else {
            $error = "Access record not found or publisher is not in your assigned account portfolio.";
        }
    }
}

// Fetch Pending Offer Applications Queue (Offer18 Feature)
$pendingOffersStmt = $pdo->prepare("
    SELECT 
        aoa.id,
        aoa.affiliate_id,
        aoa.offer_id,
        aoa.status,
        aoa.custom_payout,
        aoa.daily_cap,
        aoa.notes,
        aoa.created_at,
        u.name AS publisher_name,
        u.email AS publisher_email,
        u.company AS publisher_company,
        u.mobile AS publisher_mobile,
        o.offer_name,
        o.payout AS default_payout,
        o.currency,
        o.visibility,
        o.daily_cap AS global_daily_cap
    FROM affiliate_offer_approval aoa
    INNER JOIN users u ON u.user_id = aoa.affiliate_id
    INNER JOIN offers o ON o.offer_id = aoa.offer_id
    WHERE u.account_manager_id = ? AND aoa.status = 'pending'
      AND LOWER(TRIM(o.status)) != 'archived'
    ORDER BY aoa.created_at DESC
");
$pendingOffersStmt->execute([$managerId]);
$pendingOfferRequests = $pendingOffersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Campaigns (Public & Private) with Manager's Assigned Count
$offersStmt = $pdo->prepare("
    SELECT 
        o.offer_id,
        o.offer_name,
        o.category,
        o.payout_type,
        o.payout,
        o.currency,
        o.visibility,
        o.status,
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        (
            SELECT COUNT(*) 
            FROM affiliate_offer_approval aoa 
            INNER JOIN users pu ON pu.user_id = aoa.affiliate_id
            WHERE aoa.offer_id = o.offer_id 
              AND aoa.status = 'approved' 
              AND pu.account_manager_id = ?
        ) AS my_assigned_count
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    WHERE LOWER(TRIM(o.status)) != 'archived'
    GROUP BY o.offer_id
    ORDER BY o.created_at DESC
");
$offersStmt->execute([$managerId]);
$offers = $offersStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch My Publishers' Campaign Permissions Registry
$assignmentsStmt = $pdo->prepare("
    SELECT 
        aoa.id,
        aoa.affiliate_id,
        aoa.offer_id,
        aoa.status,
        aoa.custom_payout,
        aoa.daily_cap,
        aoa.notes,
        aoa.approved_at,
        aoa.created_at,
        u.name AS affiliate_name,
        u.email AS affiliate_email,
        u.company AS affiliate_company,
        o.offer_name,
        o.payout AS original_payout,
        o.currency,
        o.visibility
    FROM affiliate_offer_approval aoa
    INNER JOIN users u ON u.user_id = aoa.affiliate_id
    INNER JOIN offers o ON o.offer_id = aoa.offer_id
    WHERE u.account_manager_id = ?
    ORDER BY aoa.id DESC
");
$assignmentsStmt->execute([$managerId]);
$managerAssignments = $assignmentsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaigns Directory | Manager Portal</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <style>
        .card-custom { border-radius: 12px; border: none; box-shadow: 0 4px 18px rgba(0,0,0,0.06); background: #ffffff; }
        .visibility-badge { padding: 5px 10px; border-radius: 20px; font-weight: 700; font-size: 11px; text-transform: uppercase; display: inline-flex; align-items: center; }
        .visibility-public { background: #dcfce7; color: #15803d; }
        .visibility-private { background: #fef3c7; color: #b45309; }
        .pub-checkbox-list { max-height: 200px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; background: #f8fafc; }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="campaigns.php" class="nav-link active">Campaigns</a></li>
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
                    <li class="nav-item">
                        <a href="campaigns.php" class="nav-link active">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="campaign_access.php" class="nav-link">
                            <i class="nav-icon fas fa-key"></i>
                            <p>
                                Offer Approvals
                                <?php if (!empty($pendingOfferRequests)): ?>
                                    <span class="badge badge-warning right font-weight-bold"><?php echo count($pendingOfferRequests); ?></span>
                                <?php endif; ?>
                            </p>
                        </a>
                    </li>
                    <?php if (manager_can('affiliate_reports') || manager_can('advertiser_reports') || manager_can('all_reports')): ?>
                    <li class="nav-item"><a href="reports.php" class="nav-link"><i class="nav-icon fas fa-chart-bar"></i><p>Performance Reports</p></a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a href="reports_postbacks.php" class="nav-link"><i class="nav-icon fas fa-exchange-alt"></i><p>Postback Logs</p></a></li>
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
                    <div class="col-sm-6"><h1 class="m-0 font-weight-bold">Live Network Campaigns</h1></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Campaigns</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    </div>
                <?php endif; ?>

                <!-- Pending Offer Requests Queue (Offer18 Feature) -->
                <?php if (!empty($pendingOfferRequests)): ?>
                <div class="card card-custom p-4 mb-4 border border-warning shadow-sm" style="background: #fffdf5;">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                        <div>
                            <h4 class="font-weight-bold text-warning mb-1">
                                <i class="fas fa-inbox mr-2"></i>Pending Offer Requests Queue
                                <span class="badge badge-warning text-dark ml-2"><?php echo count($pendingOfferRequests); ?> Pending Applications</span>
                            </h4>
                            <p class="small text-muted mb-0">Affiliates who have applied for private campaigns and are awaiting your authorization.</p>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle bg-white rounded shadow-sm">
                            <thead class="thead-light">
                                <tr>
                                    <th>Application ID</th>
                                    <th>Publisher</th>
                                    <th>Campaign Offer</th>
                                    <th>Default Payout</th>
                                    <th>Custom Payout Override</th>
                                    <th>Daily Cap Limit</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingOfferRequests as $por): ?>
                                <tr>
                                    <td><strong>#<?php echo $por['id']; ?></strong></td>
                                    <td>
                                        <strong class="text-dark"><?php echo htmlspecialchars($por['publisher_name']); ?></strong>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($por['publisher_email']); ?> <?php echo $por['publisher_company'] ? '• ' . htmlspecialchars($por['publisher_company']) : ''; ?></small>
                                    </td>
                                    <td>
                                        <strong class="text-primary">#<?php echo $por['offer_id']; ?> - <?php echo htmlspecialchars($por['offer_name']); ?></strong>
                                        <small class="d-block text-muted">Applied: <?php echo date('M d, Y h:i A', strtotime($por['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <strong class="text-success"><?php echo format_currency($por['default_payout'], $por['currency'] ?? 'USD'); ?></strong>
                                    </td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="update_access_status" value="1">
                                        <input type="hidden" name="approval_id" value="<?php echo $por['id']; ?>">
                                        <td>
                                            <div class="input-group input-group-sm" style="max-width: 140px;">
                                                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                                <input type="number" step="0.01" min="0" name="custom_payout" class="form-control font-weight-bold" value="<?php echo htmlspecialchars($por['custom_payout'] ?? $por['default_payout']); ?>">
                                            </div>
                                        </td>
                                        <td>
                                            <div class="input-group input-group-sm" style="max-width: 140px;">
                                                <input type="number" step="1" min="1" name="daily_cap" class="form-control" placeholder="Unlimited" value="<?php echo htmlspecialchars($por['daily_cap'] ?? ''); ?>">
                                                <div class="input-group-append"><span class="input-group-text text-xs">/day</span></div>
                                            </div>
                                        </td>
                                        <td class="text-right" style="white-space: nowrap;">
                                            <button type="submit" name="status" value="approved" class="btn btn-sm btn-success font-weight-bold mr-1" title="Approve Request & Activate Link">
                                                <i class="fas fa-check mr-1"></i> Approve
                                            </button>
                                            <button type="submit" name="status" value="rejected" class="btn btn-sm btn-outline-danger font-weight-bold" onclick="return confirm('Reject this offer request?');" title="Reject Request">
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
                <?php endif; ?>

                <div class="card card-custom p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                        <h4 class="font-weight-bold text-primary mb-2 mb-md-0">
                            <i class="fas fa-bullhorn mr-2"></i>Campaign Offers Catalog
                        </h4>
                        <div class="small text-muted">
                            <span class="mr-3"><i class="fas fa-globe text-success mr-1"></i> Public: All Publishers</span>
                            <span><i class="fas fa-lock text-warning mr-1"></i> Private: Assigned Only</span>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="campaignsTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Campaign Name</th>
                                    <th>Category</th>
                                    <th>Visibility</th>
                                    <th>Payout</th>
                                    <th>My Assigned Publishers</th>
                                    <th>Clicks / Leads</th>
                                    <th>Status</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($offers as $o): 
                                    $isPrivate = strtolower($o['visibility'] ?? 'public') === 'private';
                                    $assignedCount = (int)($o['my_assigned_count'] ?? 0);
                                ?>
                                <tr>
                                    <td><strong>#<?php echo $o['offer_id']; ?></strong></td>
                                    <td>
                                        <strong class="text-dark"><?php echo htmlspecialchars($o['offer_name']); ?></strong>
                                    </td>
                                    <td><span class="badge badge-info p-2"><?php echo htmlspecialchars($o['category'] ?: 'General'); ?></span></td>
                                    <td>
                                        <?php if ($isPrivate): ?>
                                            <span class="visibility-badge visibility-private" title="Restricted - Only explicitly assigned publishers can view">
                                                <i class="fas fa-lock mr-1"></i> Private
                                            </span>
                                        <?php else: ?>
                                            <span class="visibility-badge visibility-public" title="Public - Visible to all publishers">
                                                <i class="fas fa-globe mr-1"></i> Public
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong class="text-success"><?php echo format_currency($o['payout'], $o['currency'] ?? 'USD'); ?></strong>
                                        <small class="text-muted d-block"><?php echo strtoupper($o['payout_type'] ?: 'CPA'); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($assignedCount > 0): ?>
                                            <span class="badge badge-success p-2 font-weight-bold">
                                                <i class="fas fa-user-check mr-1"></i> <?php echo $assignedCount; ?> Publisher(s)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-light border text-muted p-2">
                                                0 Assigned
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><small class="text-muted">Clicks:</small> <strong><?php echo number_format($o['total_clicks']); ?></strong></div>
                                        <div><small class="text-muted">Leads:</small> <strong class="text-success"><?php echo number_format($o['total_conversions']); ?></strong></div>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo ($o['status'] === 'active' || $o['status'] === 'approved') ? 'success' : 'secondary'; ?> p-2">
                                            <?php echo ucfirst($o['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <?php if (manager_can('assign_offers')): ?>
                                        <button type="button" 
                                                class="btn btn-sm btn-primary font-weight-bold shadow-sm btn-open-assign"
                                                data-offer-id="<?php echo (int)$o['offer_id']; ?>"
                                                data-offer-name="<?php echo htmlspecialchars($o['offer_name'], ENT_QUOTES); ?>"
                                                data-visibility="<?php echo htmlspecialchars($o['visibility'] ?? 'public', ENT_QUOTES); ?>"
                                                data-payout="<?php echo (float)($o['payout'] ?? 0); ?>"
                                                onclick="openAssignModal(<?php echo (int)$o['offer_id']; ?>, <?php echo json_encode($o['offer_name']); ?>, <?php echo json_encode($o['visibility'] ?? 'public'); ?>, <?php echo (float)($o['payout'] ?? 0); ?>)">
                                            <i class="fas fa-user-plus mr-1"></i> Assign
                                        </button>
                                        <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-light border text-muted" disabled title="Assignment permission disabled by Administrator">
                                            <i class="fas fa-lock mr-1"></i> Locked
                                        </button>
                                        <?php endif; ?>
                                    </td>


                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- My Publishers Campaign Access & Permissions Registry -->
                <div class="card card-custom p-4 mt-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                        <h4 class="font-weight-bold text-primary mb-2 mb-md-0">
                            <i class="fas fa-key mr-2"></i>My Publishers Campaign Access & Permissions Registry
                        </h4>
                        <span class="small text-muted">
                            <i class="fas fa-shield-alt text-success mr-1"></i> Full control to Approve or Reject campaign access for your assigned publishers
                        </span>
                    </div>

                    <?php if (empty($managerAssignments)): ?>
                        <div class="alert alert-light border text-center py-4 mb-0">
                            <i class="fas fa-folder-open fa-2x text-muted mb-2 d-block"></i>
                            <span class="text-muted font-weight-bold">No campaign permissions or requests registered for your publishers yet.</span>
                            <p class="small text-muted mb-0">When you assign campaigns to publishers or they apply for private offers, their access records will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" id="managerAccessTable">
                                <thead class="thead-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Publisher</th>
                                        <th>Campaign Offer</th>
                                        <th>Payout Rate</th>
                                        <th>Daily Cap</th>
                                        <th>Live Tracking Link</th>
                                        <th>Status</th>
                                        <th class="text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($managerAssignments as $aIdx => $ma): ?>
                                    <?php 
                                        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || 
                                                    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'https';
                                        $host = $_SERVER['HTTP_HOST'] ?? 'offeronmedia.com';
                                        $mgrTrackLink = "{$protocol}://{$host}/click.php?aff_id={$ma['affiliate_id']}&offer_id={$ma['offer_id']}"; 
                                        
                                        $badgeColor = 'secondary';
                                        if ($ma['status'] === 'approved') $badgeColor = 'success';
                                        elseif ($ma['status'] === 'pending') $badgeColor = 'warning';
                                        elseif ($ma['status'] === 'rejected') $badgeColor = 'danger';
                                    ?>
                                    <tr>
                                        <td><strong>#<?php echo $ma['id']; ?></strong></td>
                                        <td>
                                            <strong class="text-dark"><?php echo htmlspecialchars($ma['affiliate_name']); ?></strong>
                                            <small class="d-block text-muted"><?php echo htmlspecialchars($ma['affiliate_email']); ?></small>
                                        </td>
                                        <td>
                                            <strong class="text-primary">#<?php echo $ma['offer_id']; ?> - <?php echo htmlspecialchars($ma['offer_name']); ?></strong>
                                            <div>
                                                <?php if ($ma['visibility'] === 'private'): ?>
                                                    <span class="badge badge-warning text-dark"><i class="fas fa-lock mr-1"></i>Private</span>
                                                <?php else: ?>
                                                    <span class="badge badge-success"><i class="fas fa-globe mr-1"></i>Public</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong class="text-success"><?php echo format_currency($ma['custom_payout'] ?? $ma['original_payout'], $ma['currency'] ?? 'USD'); ?></strong>
                                        </td>
                                        <td>
                                            <?php if (!empty($ma['daily_cap'])): ?>
                                                <span class="badge badge-info px-2 py-1 font-weight-bold" title="Individual Publisher Daily Cap"><i class="fas fa-tachometer-alt mr-1"></i><?php echo number_format($ma['daily_cap']); ?> leads/day</span>
                                            <?php else: ?>
                                                <span class="badge badge-light border text-muted px-2 py-1" title="Inherits offer global daily cap">Global Cap</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="input-group input-group-sm" style="min-width: 220px;">
                                                <input type="text" id="mgrUrl_<?php echo $aIdx; ?>" class="form-control text-xs" value="<?php echo $mgrTrackLink; ?>" readonly>
                                                <div class="input-group-append">
                                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="copyMgrLink('mgrUrl_<?php echo $aIdx; ?>')" title="Copy Link"><i class="fas fa-copy"></i></button>
                                                    <a href="https://api.whatsapp.com/send?text=<?php echo urlencode("Tracking link for " . $ma['offer_name'] . ": " . $mgrTrackLink); ?>" target="_blank" class="btn btn-outline-success btn-sm" title="Share via WhatsApp"><i class="fab fa-whatsapp"></i></a>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $badgeColor; ?> p-2 font-weight-bold">
                                                <?php echo ucfirst($ma['status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-right" style="white-space: nowrap;">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="update_access_status" value="1">
                                                <input type="hidden" name="approval_id" value="<?php echo $ma['id']; ?>">
                                                
                                                <?php if ($ma['status'] !== 'approved'): ?>
                                                <button type="submit" name="status" value="approved" class="btn btn-xs btn-success font-weight-bold mr-1" title="Approve Campaign Access">
                                                    <i class="fas fa-check mr-1"></i> Approve
                                                </button>
                                                <?php endif; ?>

                                                <?php if ($ma['status'] !== 'rejected'): ?>
                                                <button type="submit" name="status" value="rejected" class="btn btn-xs btn-outline-danger font-weight-bold" title="Reject / Revoke Campaign Access" onclick="return confirm('Reject access for this publisher? Live link clicks will immediately be blocked.');">
                                                    <i class="fas fa-ban mr-1"></i> Reject
                                                </button>
                                                <?php endif; ?>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Assign Offer Modal -->
    <div class="modal fade" id="assignOfferModal" tabindex="-1" role="dialog" aria-labelledby="assignModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content" style="border-radius: 14px; border: none; overflow: hidden;">
                <form action="campaigns.php" method="POST">
                    <input type="hidden" name="assign_offer" value="1">
                    <input type="hidden" name="offer_id" id="modalOfferId" value="">

                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title font-weight-bold" id="assignModalLabel">
                            <i class="fas fa-shield-alt mr-2"></i>Assign Campaign to Publisher
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>

                    <div class="modal-body p-4">
                        <div class="mb-3 p-3 bg-light rounded border">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <small class="text-muted text-uppercase font-weight-bold">Selected Offer</small>
                                    <h5 class="font-weight-bold mb-0 text-dark" id="modalOfferName">-</h5>
                                </div>
                                <div id="modalOfferVisibilityBadge"></div>
                            </div>
                        </div>

                        <?php if (empty($myPublishers)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                You currently have no assigned publishers. Share your <strong>Publisher Signup Link</strong> from your dashboard to onboard partners.
                            </div>
                        <?php else: ?>
                            <div class="form-group mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="font-weight-bold mb-0">Select Publisher(s) <span class="text-danger">*</span></label>
                                    <div>
                                        <button type="button" class="btn btn-xs btn-outline-primary mr-1" id="btnSelectAllPubs">Select All</button>
                                        <button type="button" class="btn btn-xs btn-outline-secondary" id="btnClearAllPubs">Clear Selection</button>
                                    </div>
                                </div>
                                
                                <!-- Live Search Bar -->
                                <div class="mb-2">
                                    <div class="input-group input-group-sm">
                                        <div class="input-group-prepend"><span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span></div>
                                        <input type="text" id="pubSearchInput" class="form-control" placeholder="Search by name, ID, email, or company...">
                                        <div class="input-group-append">
                                            <button type="button" class="btn btn-outline-secondary" id="btnClearPubSearch" title="Clear search"><i class="fas fa-times"></i></button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Scrollable Publisher Checkbox List -->
                                <div class="pub-checkbox-list border rounded p-2" style="max-height: 200px; overflow-y: auto; background: #ffffff;">
                                    <?php foreach ($myPublishers as $pub): ?>
                                    <div class="custom-control custom-checkbox mb-2 pub-item-wrapper" 
                                         data-id="<?php echo $pub['user_id']; ?>" 
                                         data-name="<?php echo htmlspecialchars($pub['name'], ENT_QUOTES); ?>"
                                         data-search="<?php echo strtolower(htmlspecialchars($pub['user_id'] . ' ' . $pub['name'] . ' ' . $pub['email'] . ' ' . ($pub['company'] ?? ''))); ?>">
                                        <input type="checkbox" name="publisher_ids[]" value="<?php echo $pub['user_id']; ?>" 
                                               class="custom-control-input pub-checkbox" id="pub_<?php echo $pub['user_id']; ?>"
                                               data-id="<?php echo $pub['user_id']; ?>"
                                               data-name="<?php echo htmlspecialchars($pub['name'], ENT_QUOTES); ?>">
                                        <label class="custom-control-label font-weight-normal text-dark cursor-pointer" for="pub_<?php echo $pub['user_id']; ?>" style="cursor: pointer;">
                                            <strong>#<?php echo $pub['user_id']; ?> - <?php echo htmlspecialchars($pub['name']); ?></strong>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($pub['email']); ?> <?php echo $pub['company'] ? '('.htmlspecialchars($pub['company']).')' : ''; ?></small>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Selected Publishers Tags Container -->
                                <div class="mt-2 p-2 bg-light rounded border">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="text-muted font-weight-bold text-uppercase">Selected Publishers (<span id="selectedPubCount">0</span>):</small>
                                    </div>
                                    <div id="selectedPublishersContainer" class="d-flex flex-wrap" style="gap: 5px; min-height: 28px;">
                                        <span class="text-muted small font-italic" id="noPubsSelectedMsg">No publishers selected yet.</span>
                                    </div>
                                </div>
                                <small class="text-muted">The selected publisher(s) will instantly see this campaign in their offer list.</small>
                            </div>

                            <div class="form-group mb-3">
                                <label class="font-weight-bold">Custom Payout Override (Optional)</label>
                                <div class="input-group">
                                    <div class="input-group-prepend"><span class="input-group-text font-weight-bold">$</span></div>
                                    <input type="number" step="0.01" min="0" name="custom_payout" id="modalCustomPayout" class="form-control font-weight-bold" placeholder="Leave empty for default payout">
                                </div>
                                <small class="text-muted">Overrides the default offer payout for the selected publishers.</small>
                            </div>

                            <div class="form-group mb-3">
                                <label class="font-weight-bold">Publisher Daily Cap & Rule (Optional)</label>
                                <div class="input-group">
                                    <input type="number" step="1" min="1" name="daily_cap" id="modalDailyCap" class="form-control" placeholder="Leave empty for unlimited">
                                    <div class="input-group-append">
                                        <select name="cap_type" id="modalCapType" class="custom-select" style="max-width: 180px; font-weight: 600;">
                                            <option value="conversions">Conversions / Leads</option>
                                            <option value="clicks">Clicks / Traffic</option>
                                        </select>
                                    </div>
                                </div>
                                <small class="text-muted">Choose whether daily limit applies to <strong>Conversions</strong> or <strong>Clicks</strong> (Offer18 Rule).</small>
                            </div>

                            <div class="form-group mb-0">
                                <label class="font-weight-bold">Internal Note (Optional)</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="e.g. VIP rate, traffic approved on Facebook only"></textarea>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                        <?php if (!empty($myPublishers)): ?>
                        <button type="submit" class="btn btn-success font-weight-bold px-4">
                            <i class="fas fa-check mr-1"></i> Grant Access
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
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
    $('#campaignsTable').DataTable({ 
        pageLength: 10, 
        responsive: true,
        order: [[0, 'desc']]
    });
});

function openAssignModal(offerId, offerName, visibility, defaultPayout) {
    var offerIdElem = document.getElementById('modalOfferId');
    if (offerIdElem) offerIdElem.value = offerId;

    var offerNameElem = document.getElementById('modalOfferName');
    if (offerNameElem) offerNameElem.innerText = '#' + offerId + ' - ' + offerName;

    var payoutElem = document.getElementById('modalCustomPayout');
    if (payoutElem) {
        var num = parseFloat(defaultPayout) || 0;
        payoutElem.placeholder = 'Default: $' + num.toFixed(2);
        payoutElem.value = '';
    }
    
    var badgeContainer = document.getElementById('modalOfferVisibilityBadge');
    if (badgeContainer) {
        if (visibility && String(visibility).toLowerCase() === 'private') {
            badgeContainer.innerHTML = '<span class="visibility-badge visibility-private"><i class="fas fa-lock mr-1"></i> Private</span>';
        } else {
            badgeContainer.innerHTML = '<span class="visibility-badge visibility-public"><i class="fas fa-globe mr-1"></i> Public</span>';
        }
    }

    // Uncheck all checkboxes and reset tags
    $('.pub-checkbox').prop('checked', false);
    $('#pubSearchInput').val('');
    $('.pub-item-wrapper').show();
    updateSelectedPublishersTags();

    $('#assignOfferModal').modal('show');
}

// Live search for publishers
$('#pubSearchInput').on('input', function() {
    var term = $(this).val().toLowerCase().trim();
    $('.pub-item-wrapper').each(function() {
        var searchTxt = $(this).data('search') || '';
        if (term === '' || searchTxt.indexOf(term) !== -1) {
            $(this).show();
        } else {
            $(this).hide();
        }
    });
});

$('#btnClearPubSearch').on('click', function() {
    $('#pubSearchInput').val('').trigger('input');
});

// Update dynamic tag chips when checkboxes change
function updateSelectedPublishersTags() {
    var container = $('#selectedPublishersContainer');
    var checkedBoxes = $('.pub-checkbox:checked');
    var count = checkedBoxes.length;

    $('#selectedPubCount').text(count);

    if (count === 0) {
        container.html('<span class="text-muted small font-italic" id="noPubsSelectedMsg">No publishers selected yet.</span>');
        return;
    }

    var html = '';
    checkedBoxes.each(function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        html += '<span class="badge badge-primary p-2 d-inline-flex align-items-center" style="font-size: 12px; border-radius: 6px;">' +
                '#' + id + ' - ' + $('<div>').text(name).html() +
                '<button type="button" class="btn btn-xs text-white p-0 ml-2 btn-remove-tag" data-id="' + id + '" style="background:none; border:none; font-weight:bold; font-size:14px; line-height:1;">&times;</button>' +
                '</span>';
    });
    container.html(html);
}

$(document).on('change', '.pub-checkbox', function() {
    updateSelectedPublishersTags();
});

// Remove publisher tag on click of 'x'
$(document).on('click', '.btn-remove-tag', function(e) {
    e.preventDefault();
    var id = $(this).data('id');
    $('#pub_' + id).prop('checked', false).trigger('change');
});

// Select all visible publishers
$('#btnSelectAllPubs').on('click', function() {
    $('.pub-item-wrapper:visible .pub-checkbox').prop('checked', true);
    updateSelectedPublishersTags();
});

// Clear all selected publishers
$('#btnClearAllPubs').on('click', function() {
    $('.pub-checkbox').prop('checked', false);
    updateSelectedPublishersTags();
});

$(document).on('click', '.btn-open-assign', function(e) {
    e.preventDefault();
    var offerId    = $(this).data('offer-id');
    var offerName  = $(this).data('offer-name');
    var visibility = $(this).data('visibility');
    var payout     = $(this).data('payout');
    openAssignModal(offerId, offerName, visibility, payout);
});

var allSelected = false;
function toggleSelectAllPublishers() {
    allSelected = !allSelected;
    var cbs = document.querySelectorAll('.pub-checkbox');
    cbs.forEach(function(cb) { cb.checked = allSelected; });
}

function copyMgrLink(elementId) {
    var copyText = document.getElementById(elementId);
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);
    alert("Tracking link copied to clipboard:\n" + copyText.value);
}

$(document).ready(function() {
    if ($('#managerAccessTable').length) {
        $('#managerAccessTable').DataTable({
            pageLength: 10,
            responsive: true,
            order: [[0, 'desc']]
        });
    }
});
</script>

</body>
</html>
