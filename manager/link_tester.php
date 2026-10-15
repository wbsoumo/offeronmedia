<?php
define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('manager');

if (!manager_can('link_tester')) {
    header('Location: dashboard.php?error=unauthorized_link_tester');
    exit;
}

$managerId   = auth_user_id();
$managerName = $_SESSION['user_name'] ?? 'Account Manager';

$url      = trim($_GET['url'] ?? '');
$deviceOS = $_GET['os'] ?? 'Android 10';
$country  = $_GET['country'] ?? 'India';

$testResults = null;
$error       = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['action']) && $_GET['action'] === 'test')) {
    $url      = trim($_POST['url'] ?? $_GET['url'] ?? '');
    $deviceOS = $_POST['os'] ?? $_GET['os'] ?? 'Android 10';
    $country  = $_POST['country'] ?? $_GET['country'] ?? 'India';

    if (empty($url)) {
        $error = 'Please enter a valid tracking link URL to test.';
    } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
        $error = 'Invalid URL format. Please provide a full URL (e.g. https://offeronmedia.com/click.php?offer_id=7&aff_id=4&sub1=test).';
    } else {
        $testResults = trace_redirect_chain($url, $deviceOS, $country);
    }
}

/**
 * Traces full HTTP redirect chain step-by-step
 */
function trace_redirect_chain($initialUrl, $os, $country) {
    $chain = [];
    $currentUrl = $initialUrl;
    $maxRedirects = 10;
    $step = 1;

    $userAgents = [
        'Android 10' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
        'Android 14' => 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
        'iOS 17'     => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
        'Windows 11' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    ];
    $ua = $userAgents[$os] ?? $userAgents['Android 10'];

    while ($step <= $maxRedirects && !empty($currentUrl)) {
        $parsed = parse_url($currentUrl);
        $domain = $parsed['host'] ?? 'unknown';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $currentUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_USERAGENT, $ua);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = null;

        if ($response !== false) {
            if (preg_match('/^Location:\s*(.*)$/mi', $response, $matches)) {
                $redirectUrl = trim($matches[1]);
                if (!preg_match('~^https?://~i', $redirectUrl)) {
                    $scheme = $parsed['scheme'] ?? 'https';
                    $redirectUrl = $scheme . '://' . $domain . '/' . ltrim($redirectUrl, '/');
                }
            }
        }
        curl_close($ch);

        $chain[] = [
            'step'         => $step,
            'domain'       => $domain,
            'url'          => $currentUrl,
            'http_code'    => $httpCode,
            'redirect_to'  => $redirectUrl
        ];

        if (!empty($redirectUrl) && in_array($httpCode, [301, 302, 303, 307, 308])) {
            $currentUrl = $redirectUrl;
            $step++;
        } else {
            break;
        }
    }

    return $chain;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link Tester | Manager Portal</title>
    
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            border-radius: 12px;
            padding: 25px 30px;
            color: #ffffff;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(2, 132, 199, 0.25);
        }

        .result-card {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 25px;
            margin-top: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
        }

        .result-table td, .result-table th {
            font-size: 14px;
            vertical-align: middle;
            word-break: break-all;
        }

        .share-box {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 12px 15px;
            font-family: monospace;
            font-size: 13px;
            color: #334155;
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
            <li class="nav-item d-none d-sm-inline-block"><a href="link_tester.php" class="nav-link active">Link Tester</a></li>
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
                    <li class="nav-item"><a href="campaigns.php" class="nav-link"><i class="nav-icon fas fa-bullhorn"></i><p>Campaigns</p></a></li>
                    <?php if (manager_can('affiliate_reports') || manager_can('advertiser_reports') || manager_can('all_reports')): ?>
                    <li class="nav-item"><a href="reports.php" class="nav-link"><i class="nav-icon fas fa-chart-bar"></i><p>Performance Reports</p></a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a href="link_tester.php" class="nav-link active"><i class="nav-icon fas fa-vial"></i><p>Link Tester</p></a></li>
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
                    <div class="col-sm-6"><h1 class="m-0 font-weight-bold"><i class="fas fa-vial text-primary mr-2"></i>Link Tester</h1></div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Link Tester</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="content">
            <div class="container-fluid">

                <!-- Hero Banner -->
                <div class="hero-banner">
                    <h3 class="font-weight-bold mb-1"><i class="fas fa-route mr-2"></i>S2S Tracking Link Redirect Tracer</h3>
                    <p class="mb-0 text-white-50">Test campaign tracking links, inspect intermediate HTTP 302 redirects, simulate mobile/desktop user agents, and verify final landing page parameters.</p>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Form Card -->
                    <div class="col-lg-8">
                        <div class="card card-custom p-4">
                            <h4 class="font-weight-bold text-dark mb-3"><i class="fas fa-link text-primary mr-2"></i>Test Tracking URL</h4>

                            <form method="post" action="link_tester.php">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold text-muted small">Tracking URL *</label>
                                    <input type="url" name="url" class="form-control form-control-lg" placeholder="https://offeronmedia.com/click.php?offer_id=7&aff_id=4&sub1=test" value="<?php echo htmlspecialchars($url); ?>" required>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 form-group mb-3">
                                        <label class="font-weight-bold text-muted small">OS / Device User-Agent</label>
                                        <select name="os" class="form-control">
                                            <option value="Android 10" <?php echo ($deviceOS === 'Android 10') ? 'selected' : ''; ?>>Android 10 (Mobile)</option>
                                            <option value="Android 14" <?php echo ($deviceOS === 'Android 14') ? 'selected' : ''; ?>>Android 14 (Mobile)</option>
                                            <option value="iOS 17" <?php echo ($deviceOS === 'iOS 17') ? 'selected' : ''; ?>>iPhone / iOS 17</option>
                                            <option value="Windows 11" <?php echo ($deviceOS === 'Windows 11') ? 'selected' : ''; ?>>Windows 11 (Desktop)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 form-group mb-3">
                                        <label class="font-weight-bold text-muted small">Target Country</label>
                                        <select name="country" class="form-control">
                                            <option value="India" <?php echo ($country === 'India') ? 'selected' : ''; ?>>India (IN)</option>
                                            <option value="United States" <?php echo ($country === 'United States') ? 'selected' : ''; ?>>United States (US)</option>
                                            <option value="United Kingdom" <?php echo ($country === 'United Kingdom') ? 'selected' : ''; ?>>United Kingdom (UK)</option>
                                            <option value="Global" <?php echo ($country === 'Global') ? 'selected' : ''; ?>>Global / Any</option>
                                        </select>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary font-weight-bold px-4 py-2"><i class="fas fa-play mr-2"></i> Start Trace Test</button>
                            </form>
                        </div>
                    </div>

                    <!-- Usage info card -->
                    <div class="col-lg-4">
                        <div class="card card-custom p-4">
                            <h5 class="font-weight-bold text-dark mb-3"><i class="fas fa-info-circle text-info mr-2"></i>Testing Parameters</h5>
                            <p class="text-muted small mb-2">Simulate real clicks from your publishers without recording fake conversions or corrupting statistics.</p>
                            <div class="p-3 bg-light rounded text-center">
                                <h4 class="font-weight-bold text-success m-0"><i class="fas fa-check-circle mr-1"></i> S2S Verified</h4>
                                <span class="text-muted small">Real-time HTTP Redirect Chain Debugger</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TEST RESULTS -->
                <?php if ($testResults !== null): ?>
                <div class="result-card">
                    <h4 class="font-weight-bold text-dark mb-3"><i class="fas fa-stream text-success mr-2"></i>Redirect Path Result Trace</h4>

                    <div class="table-responsive">
                        <table class="table table-hover result-table align-middle">
                            <thead class="thead-light">
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th style="width: 200px;">Domain</th>
                                    <th>Redirect URL / Target Endpoint</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($testResults as $res): ?>
                                <tr>
                                    <td><span class="badge badge-secondary p-2"><?php echo $res['step']; ?></span></td>
                                    <td><strong class="text-dark"><i class="fas fa-globe text-primary mr-1"></i><?php echo htmlspecialchars($res['domain']); ?></strong></td>
                                    <td>
                                        <div class="text-dark font-weight-bold mb-1"><?php echo htmlspecialchars($res['url']); ?> <span class="badge badge-<?php echo ($res['http_code'] >= 200 && $res['http_code'] < 400) ? 'success' : 'danger'; ?> ml-1">(HTTP <?php echo $res['http_code']; ?>)</span></div>
                                        <?php if (!empty($res['redirect_to'])): ?>
                                            <div class="text-muted small"><i class="fas fa-long-arrow-alt-right text-info mr-1"></i>Redirects to: <span class="text-primary font-weight-bold"><?php echo htmlspecialchars($res['redirect_to']); ?></span></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

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
</body>
</html>
