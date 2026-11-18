<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('affiliate');

$affiliateId   = auth_user_id();
$affiliateName = $_SESSION['user_name'] ?? 'Affiliate';
$success = $error = null;

/* -------------------------------------------------
   FETCH GLOBAL POSTBACK
-------------------------------------------------- */
$globalStmt = $pdo->prepare("SELECT * FROM affiliate_postbacks WHERE affiliate_id = ? AND (offer_id IS NULL OR offer_id = 0) LIMIT 1");
$globalStmt->execute([$affiliateId]);
$globalPB = $globalStmt->fetch(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   POST ACTIONS HANDLER
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_global';

    if ($action === 'save_global') {
        $url = trim($_POST['postback_url'] ?? '');
        $fireStatus = $_POST['fire_status'] ?? 'approved';

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid HTTP/HTTPS postback URL.";
        } else {
            if ($globalPB) {
                $up = $pdo->prepare("UPDATE affiliate_postbacks SET postback_url = ?, fire_status = ?, status = 'active', updated_at = NOW() WHERE id = ?");
                $up->execute([$url, $fireStatus, $globalPB['id']]);
            } else {
                $ins = $pdo->prepare("INSERT INTO affiliate_postbacks (affiliate_id, offer_id, postback_url, fire_status, status, created_at, updated_at) VALUES (?, NULL, ?, ?, 'active', NOW(), NOW())");
                $ins->execute([$affiliateId, $url, $fireStatus]);
            }
            $success = "Global S2S Postback configuration saved successfully!";
            
            // Reload fresh data
            $globalStmt->execute([$affiliateId]);
            $globalPB = $globalStmt->fetch(PDO::FETCH_ASSOC);
        }
    } elseif ($action === 'save_offer_postback') {
        $offerId = (int)($_POST['offer_id'] ?? 0);
        $url = trim($_POST['postback_url'] ?? '');
        $fireStatus = $_POST['fire_status'] ?? 'approved';

        if ($offerId <= 0) {
            $error = "Please select a valid campaign offer.";
        } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
            $error = "Please enter a valid HTTP/HTTPS postback URL.";
        } else {
            // Check existing offer postback
            $chk = $pdo->prepare("SELECT id FROM affiliate_postbacks WHERE affiliate_id = ? AND offer_id = ? LIMIT 1");
            $chk->execute([$affiliateId, $offerId]);
            $exist = $chk->fetch(PDO::FETCH_ASSOC);

            if ($exist) {
                $up = $pdo->prepare("UPDATE affiliate_postbacks SET postback_url = ?, fire_status = ?, status = 'active', updated_at = NOW() WHERE id = ?");
                $up->execute([$url, $fireStatus, $exist['id']]);
            } else {
                $ins = $pdo->prepare("INSERT INTO affiliate_postbacks (affiliate_id, offer_id, postback_url, fire_status, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'active', NOW(), NOW())");
                $ins->execute([$affiliateId, $offerId, $url, $fireStatus]);
            }

            // Sync with affiliate_offer_postbacks table if exists
            try {
                $pdo->prepare("
                    INSERT INTO affiliate_offer_postbacks (affiliate_id, offer_id, postback_url, fire_status, status)
                    VALUES (?, ?, ?, ?, 'active')
                    ON DUPLICATE KEY UPDATE postback_url = VALUES(postback_url), fire_status = VALUES(fire_status), status = 'active'
                ")->execute([$affiliateId, $offerId, $url, $fireStatus]);
            } catch (Exception $e) {}

            $success = "Offer-specific postback saved successfully!";
        }
    } elseif ($action === 'delete_offer_postback') {
        $pbid = (int)($_POST['postback_id'] ?? 0);
        if ($pbid > 0) {
            $pRow = $pdo->prepare("SELECT offer_id FROM affiliate_postbacks WHERE id = ? AND affiliate_id = ?");
            $pRow->execute([$pbid, $affiliateId]);
            $foundOfferId = $pRow->fetchColumn();

            $pdo->prepare("DELETE FROM affiliate_postbacks WHERE id = ? AND affiliate_id = ?")->execute([$pbid, $affiliateId]);
            if ($foundOfferId) {
                try {
                    $pdo->prepare("DELETE FROM affiliate_offer_postbacks WHERE affiliate_id = ? AND offer_id = ?")->execute([$affiliateId, $foundOfferId]);
                } catch (Exception $e) {}
            }
            $success = "Offer-specific postback removed successfully!";
        }
    }
}

/* -------------------------------------------------
   FETCH APPROVED OFFERS & OFFER-SPECIFIC POSTBACKS
-------------------------------------------------- */
$appOffersStmt = $pdo->prepare("
    SELECT o.offer_id, o.offer_name, o.payout, o.currency
    FROM offers o
    INNER JOIN affiliate_offer_approval aoa ON aoa.offer_id = o.offer_id
    WHERE aoa.affiliate_id = ? AND aoa.status = 'approved' AND o.status = 'active'
    ORDER BY o.offer_name ASC
");
$appOffersStmt->execute([$affiliateId]);
$approvedOffers = $appOffersStmt->fetchAll(PDO::FETCH_ASSOC);

$offerPBsStmt = $pdo->prepare("
    SELECT ap.*, o.offer_name, o.currency
    FROM affiliate_postbacks ap
    INNER JOIN offers o ON o.offer_id = ap.offer_id
    WHERE ap.affiliate_id = ? AND ap.offer_id IS NOT NULL AND ap.offer_id > 0
    ORDER BY ap.id DESC
");
$offerPBsStmt->execute([$affiliateId]);
$offerPostbacks = $offerPBsStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Global S2S Postback Settings | Affiliate Portal</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css">

    <style>
        /* Select2 bootstrap4 theme fixes */
        .select2-container--bootstrap4 .select2-selection--single {
            height: calc(1.5em + .75rem + 2px) !important;
            border: 1px solid #ced4da;
            border-radius: .25rem;
        }
        .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered {
            line-height: calc(1.5em + .75rem) !important;
            padding-left: .75rem;
        }
        .select2-container {
            z-index: 1060 !important;
        }
        .select2-dropdown {
            z-index: 1065 !important;
        }

        .card-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            background: #ffffff;
        }

        .macro-badge {
            background: #f1f5f9;
            color: #0f172a;
            font-family: monospace;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 13px;
            border: 1px solid #cbd5e1;
            margin-right: 5px;
            margin-bottom: 8px;
            display: inline-block;
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
            <li class="nav-item d-none d-sm-inline-block"><a href="postback.php" class="nav-link active">Postback Settings</a></li>
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
                <i class="fas fa-rocket mr-2"></i><strong>Offer on Media</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-tachometer-alt"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>

                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-gift"></i>
                            <p>All Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="approved_offers.php" class="nav-link">
                            <i class="nav-icon fas fa-check-circle"></i>
                            <p>My Approved Offers</p>
                        </a>
                    </li>

                    <li class="nav-header">ANALYTICS & LOGS</li>
                    <li class="nav-item">
                        <a href="clicks.php" class="nav-link">
                            <i class="nav-icon fas fa-mouse-pointer"></i>
                            <p>Click Logs</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Performance & Conversions</p>
                        </a>
                    </li>

                    <li class="nav-header">TOOLS & POSTBACKS</li>
                    <li class="nav-item">
                        <a href="link-builder.php" class="nav-link">
                            <i class="nav-icon fas fa-link"></i>
                            <p>Link Builder</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link active">
                            <i class="nav-icon fas fa-code"></i>
                            <p>Postback Settings</p>
                        </a>
                    </li>

                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user-cog"></i>
                            <p>Profile & Payments</p>
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
                    <div class="col-sm-6"><h1 class="m-0 font-weight-bold">Global S2S Postback Configuration</h1></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Postback Settings</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <?php if ($success): ?><div class="alert alert-success font-weight-bold"><i class="fas fa-check-circle mr-2"></i><?php echo $success; ?></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger font-weight-bold"><i class="fas fa-exclamation-triangle mr-2"></i><?php echo $error; ?></div><?php endif; ?>

                <div class="row">
                    <!-- Global Postback Configuration Form -->
                    <div class="col-lg-7">
                        <div class="card card-custom p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-globe mr-2"></i>Global S2S Postback (All Campaigns)</h4>
                                <?php if (!empty($globalPB['postback_url'])): ?>
                                    <span class="badge badge-success px-3 py-2"><i class="fas fa-check-circle mr-1"></i> Active</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary px-3 py-2">Not Configured</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-muted small">This default postback automatically fires for every campaign unless you set a custom offer-specific postback below.</p>
                            
                            <form method="post">
                                <input type="hidden" name="action" value="save_global">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold small">Global S2S Postback URL *</label>
                                    <input type="url" name="postback_url" class="form-control" placeholder="https://yourdomain.com/postback?click_id={click_id}&payout={payout}" required value="<?php echo htmlspecialchars($globalPB['postback_url'] ?? ''); ?>">
                                    <small class="text-muted">Enter your tracking software S2S callback URL (Voluum, Binom, RedTrack, BeMob, etc.).</small>
                                </div>

                                <div class="form-group mb-4">
                                    <label class="font-weight-bold small">Conversion Trigger Condition</label>
                                    <select name="fire_status" class="form-control">
                                        <option value="approved" <?php echo (($globalPB['fire_status'] ?? 'approved') === 'approved') ? 'selected' : ''; ?>>Fire on Approved Conversions Only (Recommended)</option>
                                        <option value="all" <?php echo (($globalPB['fire_status'] ?? '') === 'all') ? 'selected' : ''; ?>>Fire on All Conversions (Approved + Pending)</option>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-primary btn-block font-weight-bold py-2"><i class="fas fa-save mr-1"></i> Save Global Postback</button>
                            </form>
                        </div>

                        <!-- Offer-Specific Postbacks Card -->
                        <div class="card card-custom p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h4 class="font-weight-bold text-dark mb-0"><i class="fas fa-bullseye text-warning mr-2"></i>Offer-Specific Postbacks</h4>
                                    <small class="text-muted">Overrides your Global Postback for specific individual campaigns.</small>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold" data-toggle="modal" data-target="#addOfferPbModal">
                                    <i class="fas fa-plus mr-1"></i> Add Offer Postback
                                </button>
                            </div>

                            <?php if (empty($offerPostbacks)): ?>
                                <div class="text-center py-4 bg-light rounded text-muted">
                                    <i class="fas fa-satellite-dish fa-2x mb-2 text-secondary"></i>
                                    <p class="mb-0 small">No offer-specific postbacks configured. All your traffic uses your <strong>Global Postback</strong> above.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Campaign Offer</th>
                                                <th>Postback URL</th>
                                                <th>Trigger</th>
                                                <th class="text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($offerPostbacks as $opb): ?>
                                            <tr>
                                                <td>
                                                    <strong class="text-primary">#<?php echo $opb['offer_id']; ?> - <?php echo htmlspecialchars($opb['offer_name']); ?></strong>
                                                </td>
                                                <td>
                                                    <code class="small d-block text-truncate" style="max-width: 250px;" title="<?php echo htmlspecialchars($opb['postback_url']); ?>">
                                                        <?php echo htmlspecialchars($opb['postback_url']); ?>
                                                    </code>
                                                </td>
                                                <td>
                                                    <span class="badge badge-light border"><?php echo ucfirst($opb['fire_status'] ?? 'approved'); ?></span>
                                                </td>
                                                <td class="text-right">
                                                    <form method="post" style="display:inline-block;" onsubmit="return confirm('Remove this offer-specific postback override?');">
                                                        <input type="hidden" name="action" value="delete_offer_postback">
                                                        <input type="hidden" name="postback_id" value="<?php echo $opb['id']; ?>">
                                                        <button type="submit" class="btn btn-xs btn-outline-danger" title="Delete Override">
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
                    </div>

                    <!-- Macro Reference Guide -->
                    <div class="col-lg-5">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-info mb-3"><i class="fas fa-code mr-2"></i>Supported Macro Tokens</h4>
                            <p class="text-muted small">Insert these dynamic placeholder tokens into your postback URL. When a conversion occurs, they are automatically replaced with actual tracking values:</p>

                            <div class="mb-2"><span class="macro-badge">{click_id}</span> <small class="text-dark font-weight-bold">Unique Click Tracking ID</small></div>
                            <div class="mb-2"><span class="macro-badge">{offer_id}</span> <small class="text-dark font-weight-bold">Campaign Offer ID</small></div>
                            <div class="mb-2"><span class="macro-badge">{payout}</span> <small class="text-dark font-weight-bold">Earned Payout Amount ($)</small></div>
                            <div class="mb-2"><span class="macro-badge">{status}</span> <small class="text-dark font-weight-bold">Conversion Status (approved)</small></div>
                            <div class="mb-2"><span class="macro-badge">{sub1}</span> <small class="text-dark font-weight-bold">Custom SubID 1 Parameter (Your Click ID)</small></div>
                            <div class="mb-2"><span class="macro-badge">{sub2}</span> <small class="text-dark font-weight-bold">Custom SubID 2 Parameter</small></div>
                            <div class="mb-2"><span class="macro-badge">{sub3}</span> <small class="text-dark font-weight-bold">Custom SubID 3 Parameter</small></div>
                            <div class="mb-2"><span class="macro-badge">{sub4}</span> <small class="text-dark font-weight-bold">Custom SubID 4 Parameter</small></div>
                            <div class="mb-2"><span class="macro-badge">{sub5}</span> <small class="text-dark font-weight-bold">Custom SubID 5 Parameter</small></div>
                            <div class="mb-2"><span class="macro-badge">{conversion_id}</span> <small class="text-dark font-weight-bold">Platform Conversion Record ID</small></div>
                            <div class="mb-2"><span class="macro-badge">{transaction_id}</span> <small class="text-dark font-weight-bold">Advertiser Conversion ID</small></div>

                            <hr class="my-3">
                            <h5 class="font-weight-bold text-dark small"><i class="fas fa-lightbulb text-warning mr-1"></i> Example Tracker Postback URL:</h5>
                            <code class="d-block p-2 bg-light rounded text-primary small">
                                https://track.tracker.com/postback?cid={sub1}&payout={payout}&status={status}
                            </code>
                            <small class="text-muted d-block mt-2">
                                <strong>Pro-Tip:</strong> If you pass your tracker's click ID into tracking link parameter <code>&sub1=YOUR_ID</code>, receive it back via <code>{sub1}</code> in your postback URL!
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Modal: Add Offer-Specific Postback -->
                <div class="modal fade" id="addOfferPbModal" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered" role="document">
                        <div class="modal-content" style="border-radius: 12px;">
                            <form method="post">
                                <input type="hidden" name="action" value="save_offer_postback">
                                <div class="modal-header bg-primary text-white">
                                    <h5 class="modal-title font-weight-bold"><i class="fas fa-plus-circle mr-2"></i>Configure Offer-Specific Postback</h5>
                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                </div>
                                <div class="modal-body p-4">
                                    <div class="form-group mb-3">
                                        <label class="font-weight-bold small">Select Approved Campaign Offer *</label>
                                        <select name="offer_id" id="affOfferSelect" class="form-control select2" required style="width: 100%;">
                                            <option value="">-- Choose Campaign Offer --</option>
                                            <?php foreach ($approvedOffers as $ao): ?>
                                                <option value="<?php echo $ao['offer_id']; ?>">
                                                    #<?php echo $ao['offer_id']; ?> - <?php echo htmlspecialchars($ao['offer_name']); ?> (<?php echo ($ao['currency'] ?? 'USD') . ' ' . number_format($ao['payout'], 2); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label class="font-weight-bold small">Offer S2S Postback URL *</label>
                                        <input type="url" name="postback_url" class="form-control" placeholder="https://yourtracker.com/postback?click_id={sub1}&payout={payout}" required>
                                    </div>
                                    <div class="form-group mb-0">
                                        <label class="font-weight-bold small">Conversion Trigger</label>
                                        <select name="fire_status" class="form-control">
                                            <option value="approved">Fire on Approved Conversions Only (Recommended)</option>
                                            <option value="all">Fire on All Conversions (Approved + Pending)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary font-weight-bold"><i class="fas fa-save mr-1"></i> Save Offer Postback</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Affiliate Portal v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">Offer on Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    $('#affOfferSelect').select2({
        theme: 'bootstrap4',
        width: '100%',
        dropdownParent: $('#addOfferPbModal'),
        placeholder: '-- Choose Campaign Offer --'
    });

    $('[data-target="#addOfferPbModal"]').on('click', function() {
        $('#affOfferSelect').val('').trigger('change');
    });
});
</script>
</body>
</html>