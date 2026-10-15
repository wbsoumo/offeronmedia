<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('manager');

$managerId = auth_user_id();
$success = $error = null;

/* ===============================
   HANDLE PUBLISHER STATUS CHANGE
================================ */
if (!empty($_GET['action']) && !empty($_GET['id'])) {
    if (!manager_can('manage_affiliates')) {
        $error = "Permission Denied: You do not have permission to manage affiliate account statuses.";
    } else {
        $pubId = (int)$_GET['id'];
        $act   = $_GET['action'];
        $validActions = ['approve', 'reject', 'block', 'activate'];

        if (in_array($act, $validActions, true)) {
            // Validate publisher belongs to this manager
            $check = $pdo->prepare("SELECT user_id, name FROM users WHERE user_id = ? AND role_id = 3 AND account_manager_id = ? LIMIT 1");
            $check->execute([$pubId, $managerId]);
            $pubData = $check->fetch(PDO::FETCH_ASSOC);

            if ($pubData) {
                $newStatus = ($act === 'approve' || $act === 'activate') ? 'active' : ($act === 'reject' ? 'rejected' : 'blocked');
                $upd = $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ? AND account_manager_id = ?");
                $upd->execute([$newStatus, $pubId, $managerId]);
                $success = "Publisher #{$pubId} (" . htmlspecialchars($pubData['name']) . ") status updated to " . ucfirst($newStatus) . " successfully!";
            } else {
                $error = "Publisher not found or not assigned to your manager account.";
            }
        }
    }
}

/* ===============================
   HANDLE PUBLISHER POSTBACK SAVE
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_postback'])) {
    if (!manager_can('manage_postbacks')) {
        $error = "Permission Denied: You do not have permission to configure affiliate postbacks.";
    } else {
        $affId   = (int)($_POST['affiliate_id'] ?? 0);
        $url     = trim($_POST['postback_url'] ?? '');
        $status  = $_POST['status'] ?? 'active';
        $type    = $_POST['postback_type'] ?? 'global';
        $pName   = trim($_POST['postback_name'] ?? '');

        // Verify affiliate belongs to this manager
        $chk = $pdo->prepare("SELECT user_id, name FROM users WHERE user_id = ? AND role_id = 3 AND account_manager_id = ? LIMIT 1");
        $chk->execute([$affId, $managerId]);
        $targetPub = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$targetPub) {
            $error = "Publisher not found or not assigned to your account.";
        } elseif (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid HTTP/HTTPS postback URL.";
        } else {
            $pbChk = $pdo->prepare("SELECT id FROM affiliate_postbacks WHERE affiliate_id = ? LIMIT 1");
            $pbChk->execute([$affId]);
            $exist = $pbChk->fetch(PDO::FETCH_ASSOC);

            if ($exist) {
                $upd = $pdo->prepare("UPDATE affiliate_postbacks SET postback_url = ?, status = ?, postback_type = ?, name = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$url, $status, $type, $pName, $exist['id']]);
            } else {
                $ins = $pdo->prepare("INSERT INTO affiliate_postbacks (affiliate_id, postback_url, status, postback_type, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                $ins->execute([$affId, $url, $status, $type, $pName]);
            }
            $success = "Postback configuration saved for publisher #" . $affId . " (" . htmlspecialchars($targetPub['name']) . ")!";
        }
    }
}

$stmt = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.mobile,
        u.company,
        u.status,
        u.created_at,
        ap.id AS postback_id,
        ap.postback_url,
        ap.status AS postback_status,
        ap.postback_type,
        ap.name AS postback_name,
        COUNT(DISTINCT c.click_id) AS clicks,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_earned
    FROM users u
    LEFT JOIN affiliate_postbacks ap ON ap.affiliate_id = u.user_id
    LEFT JOIN clicks c ON c.affiliate_id = u.user_id
    LEFT JOIN conversions cv ON cv.click_id = c.click_id
    WHERE u.role_id = 3 AND u.account_manager_id = ?
    GROUP BY u.user_id
    ORDER BY total_earned DESC
");
$stmt->execute([$managerId]);
$publishers = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Publishers | Manager Portal</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <style>
        .card-custom { border-radius: 12px; border: none; box-shadow: 0 4px 18px rgba(0,0,0,0.06); background: #ffffff; }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="publishers.php" class="nav-link active">My Publishers</a></li>
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
                    <li class="nav-item"><a href="publishers.php" class="nav-link active"><i class="nav-icon fas fa-user-friends"></i><p>My Publishers</p></a></li>
                    <li class="nav-item"><a href="campaigns.php" class="nav-link"><i class="nav-icon fas fa-bullhorn"></i><p>Campaigns</p></a></li>
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
                    <div class="col-sm-6"><h1 class="m-0 font-weight-bold">Assigned Publishers Directory</h1></div>
                    <div class="col-sm-6 text-sm-right mt-2 mt-sm-0">
                        <button type="button" class="btn btn-primary font-weight-bold shadow-sm" data-toggle="modal" data-target="#invitePublisherModal">
                            <i class="fas fa-link mr-1"></i> My Publisher Signup Link
                        </button>
                    </div>
                </div>
            </div>
        </div>

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

                <div class="card card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-user-friends mr-2"></i>Assigned Publisher Accounts</h4>
                        <div>
                            <?php if (!manager_can('manage_affiliates')): ?>
                                <span class="badge badge-light border text-muted mr-2"><i class="fas fa-lock mr-1"></i> Status Edit Restricted</span>
                            <?php endif; ?>
                            <?php if (!manager_can('manage_postbacks')): ?>
                                <span class="badge badge-light border text-muted"><i class="fas fa-lock mr-1"></i> Postback Config Restricted</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="publishersTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Publisher Name</th>
                                    <th>Email</th>
                                    <th>Mobile</th>
                                    <th>Clicks</th>
                                    <th>Approved Leads</th>
                                    <th>Payout Earned</th>
                                    <th>Status</th>
                                    <th>Postback Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($publishers as $p): 
                                    $hasPostback = !empty($p['postback_url']);
                                    $statusBadgeClass = 'secondary';
                                    if ($p['status'] === 'active') $statusBadgeClass = 'success';
                                    elseif ($p['status'] === 'pending') $statusBadgeClass = 'warning';
                                    elseif ($p['status'] === 'blocked') $statusBadgeClass = 'danger';
                                    elseif ($p['status'] === 'rejected') $statusBadgeClass = 'dark';
                                ?>
                                <tr>
                                    <td><strong>#<?php echo $p['user_id']; ?></strong></td>
                                    <td>
                                        <strong class="text-dark"><?php echo htmlspecialchars($p['name']); ?></strong>
                                        <small class="text-muted d-block"><?php echo htmlspecialchars($p['company'] ?: 'Individual'); ?></small>
                                    </td>
                                    <td><small class="text-dark"><?php echo htmlspecialchars($p['email']); ?></small></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($p['mobile'] ?: 'N/A'); ?></small></td>
                                    <td><strong><?php echo number_format($p['clicks']); ?></strong></td>
                                    <td><strong class="text-success"><?php echo number_format($p['approved_conversions']); ?></strong></td>
                                    <td><strong class="text-success">$<?php echo number_format($p['total_earned'], 2); ?></strong></td>
                                    <td><span class="badge badge-<?php echo $statusBadgeClass; ?> p-2"><?php echo ucfirst($p['status']); ?></span></td>
                                    <td>
                                        <?php if ($hasPostback): ?>
                                            <span class="badge badge-success px-2 py-1" title="<?php echo htmlspecialchars($p['postback_url']); ?>">
                                                <i class="fas fa-check-circle mr-1"></i> <?php echo ucfirst($p['postback_status'] ?? 'Active'); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-light border text-muted px-2 py-1">Not Set</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <!-- Status Controls if Allowed -->
                                        <?php if (manager_can('manage_affiliates')): ?>
                                            <?php if ($p['status'] === 'pending'): ?>
                                                <a href="?action=approve&id=<?php echo $p['user_id']; ?>" class="btn btn-xs btn-success font-weight-bold mr-1" title="Approve Affiliate">
                                                    <i class="fas fa-check"></i> Approve
                                                </a>
                                                <a href="?action=reject&id=<?php echo $p['user_id']; ?>" class="btn btn-xs btn-outline-danger font-weight-bold mr-1" onclick="return confirm('Reject this affiliate registration?')" title="Reject Affiliate">
                                                    <i class="fas fa-times"></i> Reject
                                                </a>
                                            <?php elseif ($p['status'] === 'active'): ?>
                                                <a href="?action=block&id=<?php echo $p['user_id']; ?>" class="btn btn-xs btn-outline-danger font-weight-bold mr-1" onclick="return confirm('Block this publisher?')" title="Block Affiliate">
                                                    <i class="fas fa-ban"></i> Block
                                                </a>
                                            <?php else: ?>
                                                <a href="?action=activate&id=<?php echo $p['user_id']; ?>" class="btn btn-xs btn-outline-success font-weight-bold mr-1" title="Reactivate Affiliate">
                                                    <i class="fas fa-check-circle"></i> Activate
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <!-- Postback Modal Trigger if Allowed -->
                                        <?php if (manager_can('manage_postbacks')): ?>
                                            <button type="button" class="btn btn-xs btn-outline-primary font-weight-bold" data-toggle="modal" data-target="#postbackModal_<?php echo $p['user_id']; ?>" title="Configure Postback URL">
                                                <i class="fas fa-exchange-alt mr-1"></i> Postback
                                            </button>
                                        <?php endif; ?>

                                        <?php if (!manager_can('manage_affiliates') && !manager_can('manage_postbacks')): ?>
                                            <span class="text-muted small">Read-only</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>

                                <!-- Postback Modal for Publisher #<?php echo $p['user_id']; ?> -->
                                <?php if (manager_can('manage_postbacks')): ?>
                                <div class="modal fade" id="postbackModal_<?php echo $p['user_id']; ?>" tabindex="-1" role="dialog" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
                                        <div class="modal-content" style="border-radius: 12px; border: none; overflow: hidden;">
                                            <div class="modal-header bg-primary text-white">
                                                <h5 class="modal-title font-weight-bold">
                                                    <i class="fas fa-exchange-alt mr-2"></i>Postback Configuration: <?php echo htmlspecialchars($p['name']); ?> (#<?php echo $p['user_id']; ?>)
                                                </h5>
                                                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                                    <span>&times;</span>
                                                </button>
                                            </div>
                                            <form method="post">
                                                <input type="hidden" name="save_postback" value="1">
                                                <input type="hidden" name="affiliate_id" value="<?php echo $p['user_id']; ?>">
                                                <div class="modal-body p-4">
                                                    <div class="alert alert-light border mb-3">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <div>
                                                                <strong>Publisher:</strong> <?php echo htmlspecialchars($p['name']); ?> (<?php echo htmlspecialchars($p['email']); ?>)
                                                            </div>
                                                            <span class="badge badge-info p-1"><?php echo htmlspecialchars($p['company'] ?: 'Individual'); ?></span>
                                                        </div>
                                                    </div>

                                                    <div class="form-group mb-3">
                                                        <label class="font-weight-bold">Postback URL <span class="text-danger">*</span></label>
                                                        <textarea name="postback_url" class="form-control" rows="3" placeholder="https://tracking.partner.com/postback?clickid={click_id}&payout={payout}" required style="font-family: monospace; font-size: 13px;"><?php echo htmlspecialchars($p['postback_url'] ?? ''); ?></textarea>
                                                        <small class="form-text text-muted">Enter the server-to-server HTTP/HTTPS callback URL for conversion notifications.</small>
                                                    </div>

                                                    <div class="row">
                                                        <div class="col-md-6 form-group mb-3">
                                                            <label class="font-weight-bold">Postback Name / Memo</label>
                                                            <input type="text" name="postback_name" class="form-control" placeholder="e.g. Primary Server Callback" value="<?php echo htmlspecialchars($p['postback_name'] ?? ''); ?>">
                                                        </div>
                                                        <div class="col-md-3 form-group mb-3">
                                                            <label class="font-weight-bold">Type</label>
                                                            <select name="postback_type" class="form-control">
                                                                <option value="global" <?php echo ($p['postback_type'] ?? '') === 'global' ? 'selected' : ''; ?>>Global (All Offers)</option>
                                                                <option value="offer" <?php echo ($p['postback_type'] ?? '') === 'offer' ? 'selected' : ''; ?>>Specific Offer</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3 form-group mb-3">
                                                            <label class="font-weight-bold">Status</label>
                                                            <select name="status" class="form-control">
                                                                <option value="active" <?php echo ($p['postback_status'] ?? '') !== 'inactive' ? 'selected' : ''; ?>>Active</option>
                                                                <option value="inactive" <?php echo ($p['postback_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive / Paused</option>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <!-- Macro tokens helper -->
                                                    <div class="card bg-light border p-3 mt-2 mb-0">
                                                        <strong class="text-secondary small font-weight-bold text-uppercase mb-2 d-block">Supported URL Replacement Macros</strong>
                                                        <div class="d-flex flex-wrap" style="gap: 6px;">
                                                            <code>{click_id}</code>
                                                            <code>{payout}</code>
                                                            <code>{revenue}</code>
                                                            <code>{offer_id}</code>
                                                            <code>{aff_id}</code>
                                                            <code>{sub_aff_id}</code>
                                                            <code>{currency}</code>
                                                            <code>{status}</code>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer bg-light">
                                                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-primary font-weight-bold shadow-sm">
                                                        <i class="fas fa-save mr-1"></i> Save Postback Configuration
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Invite Publisher Modal -->
    <?php 
        $inviteUrl = "https://offeronmedia.com/register.php?role=affiliate&manager=" . (int)$managerId;
    ?>
    <div class="modal fade" id="invitePublisherModal" tabindex="-1" role="dialog" aria-labelledby="inviteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content" style="border-radius: 12px; border: none; overflow: hidden;">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title font-weight-bold" id="inviteModalLabel">
                        <i class="fas fa-user-plus mr-2"></i>My Publisher Signup Link
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small mb-3">
                        Share this personalized link with your publishers. When they register using this link, they will be <strong>automatically assigned</strong> to your manager portfolio without manual admin approval.
                    </p>

                    <label class="font-weight-bold small text-uppercase text-secondary">Your Personal Invite URL</label>
                    <div class="input-group mb-3">
                        <input type="text" id="modalInviteInput" class="form-control font-weight-bold" readonly value="<?php echo htmlspecialchars($inviteUrl); ?>" style="font-family: monospace; background: #f8fafc;">
                        <div class="input-group-append">
                            <button class="btn btn-primary font-weight-bold" type="button" onclick="copyModalInvite()" id="btnModalCopy">
                                <i class="fas fa-copy mr-1"></i> <span id="modalCopyText">Copy</span>
                            </button>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                        <span class="small font-weight-bold text-muted">Direct Share:</span>
                        <div>
                            <a href="https://api.whatsapp.com/send?text=<?php echo urlencode("Join our performance marketing network using my publisher invite link: " . $inviteUrl); ?>" target="_blank" class="btn btn-sm btn-success font-weight-bold mr-1">
                                <i class="fab fa-whatsapp mr-1"></i> WhatsApp
                            </a>
                            <a href="https://t.me/share/url?url=<?php echo urlencode($inviteUrl); ?>&text=<?php echo urlencode("Join our affiliate network with my manager invite link:"); ?>" target="_blank" class="btn btn-sm btn-info font-weight-bold">
                                <i class="fab fa-telegram-plane mr-1"></i> Telegram
                            </a>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Close</button>
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
    $('#publishersTable').DataTable({ pageLength: 10, responsive: true });
});

function copyModalInvite() {
    var copyText = document.getElementById("modalInviteInput");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(copyText.value).then(function() {
            showModalCopyFeedback();
        }).catch(function() {
            document.execCommand('copy');
            showModalCopyFeedback();
        });
    } else {
        document.execCommand('copy');
        showModalCopyFeedback();
    }
}

function showModalCopyFeedback() {
    var btnText = document.getElementById("modalCopyText");
    var btn = document.getElementById("btnModalCopy");
    btnText.innerText = "Copied!";
    btn.classList.remove('btn-primary');
    btn.classList.add('btn-success');
    setTimeout(function() {
        btnText.innerText = "Copy";
        btn.classList.remove('btn-success');
        btn.classList.add('btn-primary');
    }, 2500);
}
</script>
</body>
</html>
