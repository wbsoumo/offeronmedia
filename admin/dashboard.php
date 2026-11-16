<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminId   = auth_user_id();
$adminName = $_SESSION['user_name'] ?? 'Admin';

/* -------------------------------------------------
   1. OVERALL NETWORK SYSTEM STATS
-------------------------------------------------- */
$totalAffiliates  = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role_id = 3")->fetchColumn();
$totalAdvertisers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role_id = 4")->fetchColumn();
$totalOffers      = (int)$pdo->query("SELECT COUNT(*) FROM offers")->fetchColumn();
$totalClicks      = (int)$pdo->query("SELECT COUNT(*) FROM clicks")->fetchColumn();

// Conversion & Financial Stats
$convStats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
        IFNULL(SUM(CASE WHEN status = 'approved' THEN revenue ELSE 0 END), 0) AS total_revenue,
        IFNULL(SUM(CASE WHEN status = 'approved' THEN payout ELSE 0 END), 0) AS total_payout
    FROM conversions
")->fetch(PDO::FETCH_ASSOC);

$totalConversions = (int)($convStats['total'] ?? 0);
$approvedConversions = (int)($convStats['approved'] ?? 0);
$pendingConversions = (int)($convStats['pending'] ?? 0);
$totalRevenue = (float)($convStats['total_revenue'] ?? 0);
$totalPayout = (float)($convStats['total_payout'] ?? 0);
$netProfit = $totalRevenue - $totalPayout;
$networkCR = $totalClicks > 0 ? number_format(($approvedConversions / $totalClicks) * 100, 2) : '0.00';

// Currency breakdown to prevent mixing USD and INR
$currBreakdown = $pdo->query("
    SELECT 
        COALESCE(o.currency, 'USD') as currency,
        SUM(cv.revenue) as revenue,
        SUM(cv.payout) as payout
    FROM conversions cv
    JOIN offers o ON o.offer_id = cv.offer_id
    WHERE cv.status = 'approved'
    GROUP BY COALESCE(o.currency, 'USD')
")->fetchAll(PDO::FETCH_ASSOC);

$totalRevUSD = 0; $totalRevINR = 0;
$totalPayUSD = 0; $totalPayINR = 0;
foreach ($currBreakdown as $cb) {
    if ($cb['currency'] === 'INR') {
        $totalRevINR += (float)$cb['revenue'];
        $totalPayINR += (float)$cb['payout'];
    } else {
        $totalRevUSD += (float)$cb['revenue'];
        $totalPayUSD += (float)$cb['payout'];
    }
}
$netProfitUSD = $totalRevUSD - $totalPayUSD;
$netProfitINR = $totalRevINR - $totalPayINR;

/* -------------------------------------------------
   2. TODAY PERFORMANCE
-------------------------------------------------- */
$todayStmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT c.click_id) AS today_clicks,
        COUNT(DISTINCT cv.conversion_id) AS today_conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS today_revenue,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.payout ELSE 0 END), 0) AS today_payout
    FROM clicks c
    LEFT JOIN conversions cv ON cv.click_id = c.click_id AND DATE(cv.created_at) = CURDATE()
    WHERE DATE(c.created_at) = CURDATE()
");
$today = $todayStmt->fetch(PDO::FETCH_ASSOC);

$todayClicks = (int)($today['today_clicks'] ?? 0);
$todayConversions = (int)($today['today_conversions'] ?? 0);
$todayRevenue = (float)($today['today_revenue'] ?? 0);
$todayPayout = (float)($today['today_payout'] ?? 0);
$todayProfit = $todayRevenue - $todayPayout;

/* -------------------------------------------------
   3. TOP REVENUE CAMPAIGN OFFERS
-------------------------------------------------- */
$topOffers = $pdo->query("
    SELECT 
        o.offer_id,
        o.offer_name,
        o.category,
        o.payout,
        COUNT(DISTINCT c.click_id) AS clicks,
        SUM(CASE WHEN cv.status = 'approved' THEN 1 ELSE 0 END) AS conversions,
        IFNULL(SUM(CASE WHEN cv.status = 'approved' THEN cv.revenue ELSE 0 END), 0) AS gross_revenue
    FROM offers o
    LEFT JOIN clicks c ON c.offer_id = o.offer_id
    LEFT JOIN conversions cv ON cv.offer_id = o.offer_id
    GROUP BY o.offer_id
    ORDER BY gross_revenue DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* -------------------------------------------------
   4. RECENT SYSTEM USERS REGISTERED
-------------------------------------------------- */
$recentUsers = $pdo->query("
    SELECT user_id, name, email, role_id, created_at
    FROM users
    ORDER BY created_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Executive Dashboard | GVS Offer on Media</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">

    <style>
        .card-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            background: #ffffff;
        }

        .hero-banner {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-radius: 12px;
            padding: 25px 30px;
            color: #ffffff;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
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
            <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#"><i class="fas fa-bars"></i></a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link active">Dashboard</a></li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link text-danger font-weight-bold" href="../logout.php?scope=admin"><i class="fas fa-sign-out-alt mr-1"></i> Logout</a>
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
                    <div class="col-sm-6"><h1 class="m-0 font-weight-bold">Executive Admin Dashboard</h1></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Dashboard</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <!-- Hero Executive Banner -->
                <div class="hero-banner">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <h2 class="font-weight-bold mb-1"><i class="fas fa-crown text-warning mr-2"></i>Network Control Room - Welcome, <?php echo htmlspecialchars($adminName); ?>!</h2>
                            <p class="mb-0 text-white-50">Overseeing live affiliate traffic, advertiser budgets, net profit margins, and anti-fraud security.</p>
                        </div>
                        <div class="mt-3 mt-md-0">
                            <a href="create_campaign.php" class="btn btn-primary font-weight-bold mr-1"><i class="fas fa-plus-circle mr-1"></i> New Campaign</a>
                            <a href="pending_kyc.php" class="btn btn-warning font-weight-bold mr-1"><i class="fas fa-id-card mr-1"></i> KYC Approvals</a>
                            <a href="settings.php" class="btn btn-light font-weight-bold"><i class="fas fa-cogs mr-1"></i> Settings</a>
                        </div>
                    </div>
                </div>

                <!-- 2x2 Mobile Stat Grid (Financials & Traffic) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success" style="font-size: 1.25rem;"><?php echo format_dual_currency($netProfitUSD, $netProfitINR); ?></div>
                            <div class="stat-label">Net Network Profit</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary" style="font-size: 1.25rem;"><?php echo format_dual_currency($totalRevUSD, $totalRevINR); ?></div>
                            <div class="stat-label">Gross Revenue</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($totalClicks); ?></div>
                            <div class="stat-label">Network Traffic Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($approvedConversions); ?></div>
                            <div class="stat-label">Approved Conversions</div>
                        </div>
                    </div>
                </div>

                <!-- System Users & Today Stats Row -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-dark"><?php echo number_format($totalAffiliates); ?></div>
                            <div class="stat-label">Active Publishers</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-dark"><?php echo number_format($totalAdvertisers); ?></div>
                            <div class="stat-label">Active Advertisers</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-info"><?php echo number_format($todayClicks); ?></div>
                            <div class="stat-label">Today's Traffic Clicks</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success">$<?php echo number_format($todayProfit, 2); ?></div>
                            <div class="stat-label">Today's Net Profit</div>
                        </div>
                    </div>
                </div>

                <!-- Top Campaigns & System Users Row -->
                <div class="row">
                    <!-- Top Revenue Campaigns -->
                    <div class="col-lg-7">
                        <div class="card card-custom p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-trophy mr-2"></i>Top Revenue Campaigns</h4>
                                <a href="campaigns.php" class="btn btn-sm btn-outline-primary">Manage All</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Campaign</th>
                                            <th>Category</th>
                                            <th>Clicks</th>
                                            <th>Leads</th>
                                            <th>Gross Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($topOffers)): ?>
                                            <tr><td colspan="5" class="text-center text-muted py-3">No campaign offers created.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($topOffers as $to): ?>
                                            <tr>
                                                <td>
                                                    <a href="offer_edit.php?id=<?php echo $to['offer_id']; ?>" class="text-primary font-weight-bold">
                                                        #<?php echo $to['offer_id']; ?> - <?php echo htmlspecialchars($to['offer_name']); ?>
                                                    </a>
                                                </td>
                                                <td><span class="badge badge-info p-2"><?php echo htmlspecialchars($to['category'] ?: 'General'); ?></span></td>
                                                <td><strong><?php echo number_format($to['clicks']); ?></strong></td>
                                                <td><strong class="text-success"><?php echo number_format($to['conversions']); ?></strong></td>
                                                <td><strong class="text-success">$<?php echo number_format($to['gross_revenue'], 2); ?></strong></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Registered Users -->
                    <div class="col-lg-5">
                        <div class="card card-custom p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="font-weight-bold text-primary mb-0"><i class="fas fa-user-plus mr-2"></i>Recent System Users</h4>
                                <a href="users.php" class="btn btn-sm btn-outline-primary">View All</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>User</th>
                                            <th>Role</th>
                                            <th>Joined</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentUsers as $ru): ?>
                                        <tr>
                                            <td>
                                                <strong class="text-dark d-block"><?php echo htmlspecialchars($ru['name']); ?></strong>
                                                <small class="text-muted"><?php echo htmlspecialchars($ru['email']); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($ru['role_id'] == 3): ?>
                                                    <span class="badge badge-success">Publisher</span>
                                                <?php elseif ($ru['role_id'] == 4): ?>
                                                    <span class="badge badge-info">Advertiser</span>
                                                <?php else: ?>
                                                    <span class="badge badge-dark">Admin</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><small class="text-muted"><?php echo date('M d H:i', strtotime($ru['created_at'])); ?></small></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Admin Console v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Offer on Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
</body>
</html>