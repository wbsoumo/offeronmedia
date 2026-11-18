<?php
/**
 * Shared Manager Sidebar Navigation Partial
 * Unifies all navigation and analytics report tabs in the side menu.
 */
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$currentTab  = $_GET['tab'] ?? '';
$managerUserId = auth_user_id();
$managerDisplayName = $_SESSION['user_name'] ?? 'Account Manager';

// Auto-fetch pending offer approval count if not defined
if (!isset($pendingOfferCount) && isset($pdo) && $managerUserId > 0) {
    try {
        $pocStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM affiliate_offer_approval aoa
            INNER JOIN users u ON u.user_id = aoa.affiliate_id
            WHERE aoa.status = 'pending' AND u.account_manager_id = ?
        ");
        $pocStmt->execute([$managerUserId]);
        $pendingOfferCount = (int)$pocStmt->fetchColumn();
    } catch (Exception $e) {
        $pendingOfferCount = 0;
    }
}
?>
<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <a href="dashboard.php" class="brand-link text-center">
        <span class="brand-text font-weight-light" style="font-size: 1.35rem;">
            <i class="fas fa-user-tie text-info mr-2"></i><strong>Manager Portal</strong>
        </span>
    </a>

    <!-- Sidebar -->
    <div class="sidebar">
        <!-- Sidebar Menu -->
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                
                <!-- Main Navigation Header -->
                <li class="nav-header font-weight-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px; color: #a4b7cd; padding: 10px 15px 4px;">MANAGEMENT</li>

                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Dashboard</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="publishers.php" class="nav-link <?php echo ($currentPage === 'publishers.php' || $currentPage === 'affiliate_details.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-users"></i>
                        <p>My Publishers</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="campaigns.php" class="nav-link <?php echo ($currentPage === 'campaigns.php' || $currentPage === 'offer_details.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-bullhorn"></i>
                        <p>Campaigns</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="campaign_access.php" class="nav-link <?php echo ($currentPage === 'campaign_access.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-key"></i>
                        <p>
                            Offer Approvals
                            <?php if (!empty($pendingOfferCount) && $pendingOfferCount > 0): ?>
                                <span class="badge badge-warning right font-weight-bold"><?php echo $pendingOfferCount; ?></span>
                            <?php endif; ?>
                        </p>
                    </a>
                </li>

                <!-- Analytics & Reports Section: All tabs surfaced directly into side menu -->
                <li class="nav-header font-weight-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px; color: #a4b7cd; padding: 12px 15px 4px;">ANALYTICS & REPORTS</li>

                <li class="nav-item">
                    <a href="reports_clicks.php" class="nav-link <?php echo ($currentPage === 'reports_clicks.php' || ($currentPage === 'reports.php' && $currentTab === 'clicks')) ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-mouse-pointer text-info"></i>
                        <p>Click Reports</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="reports_campaigns.php" class="nav-link <?php echo ($currentPage === 'reports_campaigns.php' || ($currentPage === 'reports.php' && ($currentTab === 'campaigns' || empty($currentTab)))) ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-chart-bar text-primary"></i>
                        <p>Campaign Reports</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="reports_affiliates.php" class="nav-link <?php echo ($currentPage === 'reports_affiliates.php' || ($currentPage === 'reports.php' && $currentTab === 'affiliates')) ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-chart-line text-success"></i>
                        <p>Publisher Reports</p>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="reports_subid.php" class="nav-link <?php echo ($currentPage === 'reports_subid.php' || ($currentPage === 'reports.php' && $currentTab === 'subid')) ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-tags text-warning"></i>
                        <p>SubID Performance</p>
                    </a>
                </li>

                <?php if (manager_can('view_advertiser_reports') || manager_can('advertiser_reports') || manager_can('all_reports')): ?>
                <li class="nav-item">
                    <a href="reports_advertisers.php" class="nav-link <?php echo ($currentPage === 'reports_advertisers.php' || ($currentPage === 'reports.php' && $currentTab === 'advertisers')) ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-briefcase text-secondary"></i>
                        <p>Advertiser Reports</p>
                    </a>
                </li>
                <?php endif; ?>

                <li class="nav-item">
                    <a href="reports_postbacks.php" class="nav-link <?php echo ($currentPage === 'reports_postbacks.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-exchange-alt text-danger"></i>
                        <p>Postback Logs</p>
                    </a>
                </li>

                <!-- Tools & Account Section -->
                <li class="nav-header font-weight-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px; color: #a4b7cd; padding: 12px 15px 4px;">TOOLS & PROFILE</li>

                <?php if (manager_can('link_tester')): ?>
                <li class="nav-item">
                    <a href="link_tester.php" class="nav-link <?php echo ($currentPage === 'link_tester.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-vial"></i>
                        <p>Link Tester</p>
                    </a>
                </li>
                <?php endif; ?>

                <li class="nav-item">
                    <a href="profile.php" class="nav-link <?php echo ($currentPage === 'profile.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-user-cog"></i>
                        <p>My Profile</p>
                    </a>
                </li>

            </ul>
        </nav>
    </div>
</aside>
