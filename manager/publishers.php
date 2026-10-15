<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('manager');

$managerId = auth_user_id();

$stmt = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.mobile,
        u.company,
        u.status,
        u.created_at,
        COUNT(DISTINCT c.click_id) AS clicks,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS approved_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS total_earned
    FROM users u
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
                    <li class="nav-item"><a href="reports.php" class="nav-link"><i class="nav-icon fas fa-chart-bar"></i><p>Performance Reports</p></a></li>
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
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-user-friends mr-2"></i>Assigned Publisher Accounts</h4>
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
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($publishers as $p): ?>
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
                                    <td><span class="badge badge-<?php echo $p['status'] === 'active' ? 'success' : 'secondary'; ?> p-2"><?php echo ucfirst($p['status']); ?></span></td>
                                </tr>
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
