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


                // Insert or update approval
                $chk = $pdo->prepare("SELECT id FROM affiliate_offer_approval WHERE affiliate_id = ? AND offer_id = ? LIMIT 1");
                $chk->execute([$pubId, $offerId]);
                $existing = $chk->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $upd = $pdo->prepare("UPDATE affiliate_offer_approval SET status = 'approved', custom_payout = ?, notes = ?, approved_at = NOW() WHERE id = ?");
                    $upd->execute([$customPayout, $notes, $existing['id']]);
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO affiliate_offer_approval 
                            (affiliate_id, offer_id, status, custom_payout, notes, created_by, approved_at)
                        VALUES 
                            (?, ?, 'approved', ?, ?, ?, NOW())
                    ");
                    $ins->execute([$pubId, $offerId, $customPayout, $notes, $managerName]);
                }
                $assignedCount++;
            }

            $success = "Successfully granted access to {$assignedCount} publisher(s)! This offer is now live in their offer list.";
        }
    }
    }
}


// Fetch All Campaigns (Public & Private) with Manager's Assigned Count
$offersStmt = $pdo->prepare("
    SELECT 
        o.offer_id,
        o.offer_name,
        o.category,
        o.payout_type,
        o.payout,
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
    GROUP BY o.offer_id
    ORDER BY o.created_at DESC
");
$offersStmt->execute([$managerId]);
$offers = $offersStmt->fetchAll(PDO::FETCH_ASSOC);
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
                    <li class="nav-item"><a href="campaigns.php" class="nav-link active"><i class="nav-icon fas fa-bullhorn"></i><p>Campaigns</p></a></li>
                    <?php if (manager_can('affiliate_reports') || manager_can('advertiser_reports') || manager_can('all_reports')): ?>
                    <li class="nav-item"><a href="reports.php" class="nav-link"><i class="nav-icon fas fa-chart-bar"></i><p>Performance Reports</p></a></li>
                    <?php endif; ?>
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
                                        <strong class="text-success">$<?php echo number_format($o['payout'], 2); ?></strong>
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
                                                class="btn btn-sm btn-primary font-weight-bold shadow-sm"
                                                onclick="openAssignModal(<?php echo $o['offer_id']; ?>, '<?php echo htmlspecialchars(addslashes($o['offer_name'])); ?>', '<?php echo $o['visibility']; ?>', <?php echo (float)$o['payout']; ?>)">
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
                                    <button type="button" class="btn btn-xs btn-link text-primary p-0" onclick="toggleSelectAllPublishers()">Select All</button>
                                </div>
                                <div class="pub-checkbox-list">
                                    <?php foreach ($myPublishers as $pub): ?>
                                    <div class="custom-control custom-checkbox mb-2">
                                        <input type="checkbox" name="publisher_ids[]" value="<?php echo $pub['user_id']; ?>" class="custom-control-input pub-checkbox" id="pub_<?php echo $pub['user_id']; ?>">
                                        <label class="custom-control-label font-weight-normal text-dark" for="pub_<?php echo $pub['user_id']; ?>">
                                            <strong>#<?php echo $pub['user_id']; ?> - <?php echo htmlspecialchars($pub['name']); ?></strong>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($pub['email']); ?> <?php echo $pub['company'] ? '('.htmlspecialchars($pub['company']).')' : ''; ?></small>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted">The selected publisher(s) will instantly see this campaign in their offer list.</small>
                            </div>

                            <div class="form-group mb-3">
                                <label class="font-weight-bold">Custom Payout Override (Optional)</label>
                                <div class="input-group">
                                    <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                    <input type="number" step="0.01" min="0" name="custom_payout" id="modalCustomPayout" class="form-control" placeholder="Leave empty for default payout">
                                </div>
                                <small class="text-muted">Overrides the default offer payout for the selected publishers.</small>
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
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Offer on Media</a>.</strong> All rights reserved.
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
    document.getElementById('modalOfferId').value = offerId;
    document.getElementById('modalOfferName').innerText = '#' + offerId + ' - ' + offerName;
    document.getElementById('modalCustomPayout').placeholder = 'Default: $' + defaultPayout.toFixed(2);
    
    var badgeContainer = document.getElementById('modalOfferVisibilityBadge');
    if (visibility.toLowerCase() === 'private') {
        badgeContainer.innerHTML = '<span class="visibility-badge visibility-private"><i class="fas fa-lock mr-1"></i> Private</span>';
    } else {
        badgeContainer.innerHTML = '<span class="visibility-badge visibility-public"><i class="fas fa-globe mr-1"></i> Public</span>';
    }

    // Uncheck all checkboxes by default
    var cbs = document.querySelectorAll('.pub-checkbox');
    cbs.forEach(function(cb) { cb.checked = false; });

    $('#assignOfferModal').modal('show');
}

var allSelected = false;
function toggleSelectAllPublishers() {
    allSelected = !allSelected;
    var cbs = document.querySelectorAll('.pub-checkbox');
    cbs.forEach(function(cb) { cb.checked = allSelected; });
}
</script>
</body>
</html>
