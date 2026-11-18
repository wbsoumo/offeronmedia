<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('manager');

$managerId = auth_user_id();
$managerName = $_SESSION['user_name'] ?? 'Account Manager';
$success = $error = null;

/* ===============================
   HANDLE PUBLISHER STATUS CHANGE
================================ */
if (!empty($_GET['action']) && !empty($_GET['id'])) {
    $pubId = (int)$_GET['id'];
    $act   = $_GET['action'];

    // Verify publisher belongs to this manager
    $check = $pdo->prepare("SELECT user_id, name FROM users WHERE user_id = ? AND role_id = 3 AND account_manager_id = ? LIMIT 1");
    $check->execute([$pubId, $managerId]);
    $pubData = $check->fetch(PDO::FETCH_ASSOC);

    if (!$pubData) {
        $error = "Publisher not found or not assigned to your manager account.";
    } else {
        if ($act === 'approve') {
            $pdo->prepare("UPDATE users SET status = 'active', updated_at = NOW() WHERE user_id = ?")->execute([$pubId]);
            affiliate_unblock($pubId, 'Approved by Account Manager #' . $managerId);
            $success = "Publisher #{$pubId} (" . htmlspecialchars($pubData['name']) . ") approved and activated successfully!";
        } elseif ($act === 'reject') {
            affiliate_block($pubId, 'Rejected by Account Manager #' . $managerId);
            $pdo->prepare("UPDATE users SET status = 'rejected', updated_at = NOW() WHERE user_id = ?")->execute([$pubId]);
            $success = "Publisher #{$pubId} (" . htmlspecialchars($pubData['name']) . ") application rejected.";
        }
    }
}

/* ===============================
   FETCH PENDING PUBLISHERS
================================ */
$pendingStmt = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.mobile,
        u.company,
        u.telegram_id,
        u.created_at
    FROM users u
    WHERE u.role_id = 3 
      AND u.account_manager_id = ? 
      AND u.status = 'pending'
    ORDER BY u.created_at DESC
");
$pendingStmt->execute([$managerId]);
$pendingPublishers = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH MANAGER METRICS
================================ */
// Assigned Publishers count & revenue
$pubStatsStmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT u.user_id) AS total_assigned_publishers,
        COUNT(DISTINCT c.click_id) AS total_clicks,
        COUNT(DISTINCT cv.conversion_id) AS total_conversions,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS publisher_payouts,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS gross_revenue
    FROM users u
    LEFT JOIN clicks c ON c.affiliate_id = u.user_id
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    WHERE u.role_id = 3 AND u.account_manager_id = ?
");
$pubStatsStmt->execute([$managerId]);
$mStats = $pubStatsStmt->fetch(PDO::FETCH_ASSOC);

$totalPubs = (int)($mStats['total_assigned_publishers'] ?? 0);
$clicks = (int)($mStats['total_clicks'] ?? 0);
$approvedLeads = (int)($mStats['approved_conversions'] ?? 0);
$payouts = (float)($mStats['publisher_payouts'] ?? 0);
$revenue = (float)($mStats['gross_revenue'] ?? 0);
$cr = $clicks > 0 ? number_format(($approvedLeads / $clicks) * 100, 2) : '0.00';

// Dual currency payout breakdown
$payStmt = $pdo->prepare("
    SELECT 
        COALESCE(o.currency, 'USD') as currency,
        SUM(cv.payout) as payout
    FROM users u
    JOIN conversions cv ON cv.affiliate_id = u.user_id AND cv.status = 'approved'
    JOIN offers o ON o.offer_id = cv.offer_id
    WHERE u.role_id = 3 AND u.account_manager_id = ?
    GROUP BY COALESCE(o.currency, 'USD')
");
$payStmt->execute([$managerId]);
$payBreakdown = $payStmt->fetchAll(PDO::FETCH_ASSOC);

$managerPayoutUSD = 0; $managerPayoutINR = 0;
foreach ($payBreakdown as $pb) {
    if ($pb['currency'] === 'INR') {
        $managerPayoutINR += (float)$pb['payout'];
    } else {
        $managerPayoutUSD += (float)$pb['payout'];
    }
}
$canViewFinancials = manager_can('can_view_financials');

/* ===============================
   FETCH ASSIGNED PUBLISHERS LIST
================================ */
$pubsListStmt = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.company,
        u.status,
        u.balance,
        COUNT(DISTINCT c.click_id) AS clicks,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_earned
    FROM users u
    LEFT JOIN clicks c ON c.affiliate_id = u.user_id
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    WHERE u.role_id = 3 AND u.account_manager_id = ?
    GROUP BY u.user_id
    ORDER BY FIELD(u.status, 'pending') DESC, total_earned DESC, u.created_at DESC
");
$pubsListStmt->execute([$managerId]);
$assignedPublishers = $pubsListStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account Manager Dashboard | GVS Offer on Media</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
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
            padding: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            text-align: center;
            margin-bottom: 15px;
        }

        .stat-card-custom .stat-number {
            font-size: 24px;
            font-weight: 800;
            color: #1e293b;
        }

        .stat-card-custom .stat-label {
            font-size: 11px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
        }

        .hero-banner {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border-radius: 12px;
            padding: 25px 30px;
            color: #ffffff;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(37, 99, 235, 0.2);
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
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link active">Dashboard</a></li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link text-danger font-weight-bold" href="../logout.php"><i class="fas fa-sign-out-alt mr-1"></i> Logout</a>
            </li>
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
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link active">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="publishers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-friends"></i>
                            <p>My Publishers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Campaigns</p>
                        </a>
                    </li>
                    <?php if (manager_can('affiliate_reports') || manager_can('advertiser_reports') || manager_can('all_reports')): ?>
                    <li class="nav-item">
                        <a href="reports.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Performance Reports</p>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (manager_can('link_tester')): ?>
                    <li class="nav-item">
                        <a href="link_tester.php" class="nav-link">
                            <i class="nav-icon fas fa-vial"></i>
                            <p>Link Tester</p>
                        </a>
                    </li>
                    <?php endif; ?>

                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user-cog"></i>
                            <p>My Profile</p>
                        </a>
                    </li>
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
                        <h1 class="m-0 font-weight-bold">Account Manager Portal</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Dashboard</li>
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
                        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                    </div>
                <?php endif; ?>

                <!-- Hero Banner -->
                <div class="hero-banner">
                    <h2 class="font-weight-bold mb-1"><i class="fas fa-handshake mr-2"></i>Welcome back, <?php echo htmlspecialchars($managerName); ?>!</h2>
                    <p class="mb-0 text-white-50">Manage your assigned publishers, track conversion performance, and inspect campaign reports.</p>
                </div>

                <!-- Dedicated Affiliate Invite Link Widget -->
                <?php 
                    $inviteUrl = "https://offeronmedia.com/register.php?role=affiliate&manager=" . (int)$managerId;
                ?>
                <div class="card card-custom p-4 mb-4" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff; border: 1px solid #334155;">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
                        <div>
                            <h4 class="font-weight-bold mb-1 text-white">
                                <i class="fas fa-link text-primary mr-2"></i>Your Dedicated Publisher Signup Link
                            </h4>
                            <p class="text-white-50 mb-0 small">
                                Share this exclusive link with publishers. When they register using your link, they are automatically assigned to your account portfolio.
                            </p>
                        </div>
                        <span class="badge badge-success px-3 py-2 mt-2 mt-md-0 font-weight-bold" style="font-size: 0.85rem;">
                            <i class="fas fa-magic mr-1"></i> Auto-Assign Active
                        </span>
                    </div>

                    <div class="input-group input-group-lg mt-3">
                        <input type="text" id="managerInviteUrl" class="form-control font-weight-bold bg-dark text-info border-secondary" readonly value="<?php echo htmlspecialchars($inviteUrl); ?>" style="font-family: monospace; font-size: 0.95rem;">
                        <div class="input-group-append">
                            <button class="btn btn-primary px-4 font-weight-bold" type="button" onclick="copyInviteLink()" id="btnCopyInvite">
                                <i class="fas fa-copy mr-1"></i> <span id="copyBtnText">Copy Link</span>
                            </button>
                            <a href="https://api.whatsapp.com/send?text=<?php echo urlencode("Join India's top performance marketing network using my publisher invite link: " . $inviteUrl); ?>" target="_blank" class="btn btn-success font-weight-bold px-3" title="Share via WhatsApp">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <a href="https://t.me/share/url?url=<?php echo urlencode($inviteUrl); ?>&text=<?php echo urlencode("Join our affiliate network with my manager invite link:"); ?>" target="_blank" class="btn btn-info font-weight-bold px-3" title="Share via Telegram">
                                <i class="fab fa-telegram-plane"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Pending Affiliate Approvals Card -->
                <?php if (!empty($pendingPublishers)): ?>
                <div class="card card-custom p-4 mb-4 border border-warning" style="background: #fffbeb;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="font-weight-bold text-warning mb-0">
                            <i class="fas fa-user-clock mr-2"></i>Pending Publisher Approvals 
                            <span class="badge badge-warning text-dark ml-2"><?php echo count($pendingPublishers); ?> Awaiting Review</span>
                        </h4>
                        <span class="small text-muted">Publishers registered through your manager link requiring your approval</span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle bg-white rounded shadow-sm">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Publisher Name</th>
                                    <th>Email</th>
                                    <th>Mobile / Telegram</th>
                                    <th>Registered</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingPublishers as $pen): ?>
                                <tr>
                                    <td><strong>#<?php echo $pen['user_id']; ?></strong></td>
                                    <td>
                                        <strong class="text-dark"><?php echo htmlspecialchars($pen['name']); ?></strong>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($pen['company'] ?: 'Individual'); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($pen['email']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($pen['mobile'] ?: 'N/A'); ?>
                                        <?php if ($pen['telegram_id']): ?>
                                            <span class="badge badge-light border text-info ml-1"><i class="fab fa-telegram-plane"></i> <?php echo htmlspecialchars($pen['telegram_id']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small class="text-muted"><?php echo date('M d, Y h:i A', strtotime($pen['created_at'])); ?></small></td>
                                    <td class="text-right" style="white-space: nowrap;">
                                        <a href="?action=approve&id=<?php echo $pen['user_id']; ?>" class="btn btn-sm btn-success font-weight-bold mr-1">
                                            <i class="fas fa-check mr-1"></i> Approve
                                        </a>
                                        <a href="?action=reject&id=<?php echo $pen['user_id']; ?>" class="btn btn-sm btn-outline-danger font-weight-bold" onclick="return confirm('Reject this publisher registration?');">
                                            <i class="fas fa-times mr-1"></i> Reject
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 2x2 Mobile Stat Grid -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($totalPubs); ?></div>
                            <div class="stat-label">Assigned Publishers</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($clicks); ?></div>
                            <div class="stat-label">Total Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($approvedLeads); ?></div>
                            <div class="stat-label">Approved Leads</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <?php if ($canViewFinancials): ?>
                                <div class="stat-number text-success" style="font-size: 1.15rem;"><?php echo format_dual_currency($managerPayoutUSD, $managerPayoutINR); ?></div>
                            <?php else: ?>
                                <div class="stat-number text-muted"><span class="badge badge-light border"><i class="fas fa-lock mr-1"></i>Restricted</span></div>
                            <?php endif; ?>
                            <div class="stat-label">Publisher Payouts</div>
                        </div>
                    </div>
                </div>

                <!-- Assigned Publishers Catalog -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-users mr-2"></i>My Assigned Publishers Directory</h4>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="managerPubsTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>Publisher & Company</th>
                                    <th>Email</th>
                                    <th>Clicks</th>
                                    <th>Approved Leads</th>
                                    <th>Payout Earned</th>
                                    <th>Status</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignedPublishers as $ap): ?>
                                <tr>
                                    <td>
                                        <strong class="text-dark"><?php echo htmlspecialchars($ap['name']); ?></strong>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($ap['company'] ?: 'Individual'); ?></small>
                                    </td>
                                    <td><small class="text-dark"><?php echo htmlspecialchars($ap['email']); ?></small></td>
                                    <td><strong><?php echo number_format($ap['clicks']); ?></strong></td>
                                    <td><strong class="text-success"><?php echo number_format($ap['approved_conversions']); ?></strong></td>
                                    <td>
                                        <?php if ($canViewFinancials): ?>
                                            <strong class="text-success">$<?php echo number_format($ap['total_earned'], 2); ?></strong>
                                        <?php else: ?>
                                            <span class="badge badge-light text-muted border"><i class="fas fa-lock mr-1"></i>Hidden</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $stClass = 'secondary';
                                            if ($ap['status'] === 'active') $stClass = 'success';
                                            elseif ($ap['status'] === 'pending') $stClass = 'warning';
                                            elseif ($ap['status'] === 'rejected' || $ap['status'] === 'blocked') $stClass = 'danger';
                                        ?>
                                        <span class="badge badge-<?php echo $stClass; ?> p-2"><?php echo ucfirst($ap['status']); ?></span>
                                    </td>
                                    <td class="text-right" style="white-space: nowrap;">
                                        <?php if ($ap['status'] === 'pending'): ?>
                                            <a href="?action=approve&id=<?php echo $ap['user_id']; ?>" class="btn btn-xs btn-success font-weight-bold mr-1" title="Approve Publisher">
                                                <i class="fas fa-check mr-1"></i> Approve
                                            </a>
                                            <a href="?action=reject&id=<?php echo $ap['user_id']; ?>" class="btn btn-xs btn-outline-danger font-weight-bold" onclick="return confirm('Reject this publisher registration?');" title="Reject Publisher">
                                                <i class="fas fa-times mr-1"></i> Reject
                                            </a>
                                        <?php else: ?>
                                            <a href="publishers.php" class="btn btn-xs btn-outline-primary font-weight-bold">
                                                <i class="fas fa-cog mr-1"></i> Manage
                                            </a>
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
    $('#managerPubsTable').DataTable({
        pageLength: 10,
        responsive: true
    });
});

function copyInviteLink() {
    var copyText = document.getElementById("managerInviteUrl");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(copyText.value).then(function() {
            showCopyFeedback();
        }).catch(function() {
            document.execCommand('copy');
            showCopyFeedback();
        });
    } else {
        document.execCommand('copy');
        showCopyFeedback();
    }
}

function showCopyFeedback() {
    var btnText = document.getElementById("copyBtnText");
    var btn = document.getElementById("btnCopyInvite");
    btnText.innerText = "Copied!";
    btn.classList.remove('btn-primary');
    btn.classList.add('btn-success');
    setTimeout(function() {
        btnText.innerText = "Copy Link";
        btn.classList.remove('btn-success');
        btn.classList.add('btn-primary');
    }, 2500);
}
</script>
</body>
</html>
