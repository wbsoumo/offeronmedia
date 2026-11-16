<?php
/**
 * Shared Admin Sidebar Navigation Partial
 * Keeps side menu options 100% identical and synchronized across all admin pages.
 */
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$currentStatus = $_GET['status'] ?? '';
$adminUserName = $_SESSION['user_name'] ?? 'Admin';
?>
<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <a href="dashboard.php" class="brand-link text-center">
        <span class="brand-text font-weight-light" style="font-size: 1.35rem;">
            <i class="fas fa-crown text-warning mr-2"></i><strong>Admin Console</strong>
        </span>
    </a>

    <div class="sidebar">
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?php echo ($currentPage === 'dashboard.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Dashboard</p>
                    </a>
                </li>

                <li class="nav-header">CAMPAIGNS & OFFERS</li>
                <li class="nav-item">
                    <a href="offers.php" class="nav-link <?php echo (($currentPage === 'offers.php' || $currentPage === 'campaigns.php' || $currentPage === 'offer_details.php' || $currentPage === 'offer_edit.php') && $currentStatus !== 'archived') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-bullhorn"></i>
                        <p>Manage Campaigns</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="archived_offers.php" class="nav-link <?php echo ($currentPage === 'archived_offers.php' || ($currentPage === 'offers.php' && $currentStatus === 'archived')) ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-archive"></i>
                        <p>Archived Offers</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="create_campaign.php" class="nav-link <?php echo ($currentPage === 'create_campaign.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-plus-circle"></i>
                        <p>Create Campaign</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="campaign_access.php" class="nav-link <?php echo ($currentPage === 'campaign_access.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-key"></i>
                        <p>Offer Approval Rules</p>
                    </a>
                </li>

                <li class="nav-header">USER MANAGEMENT</li>
                <li class="nav-item">
                    <a href="users.php" class="nav-link <?php echo ($currentPage === 'users.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-users"></i>
                        <p>All System Users</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="publishers.php" class="nav-link <?php echo ($currentPage === 'publishers.php' || $currentPage === 'affiliate_details.php' || $currentPage === 'create_publisher.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-user-friends"></i>
                        <p>Publishers / Affiliates</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="advertisers.php" class="nav-link <?php echo ($currentPage === 'advertisers.php' || $currentPage === 'advertiser_edit.php' || $currentPage === 'create_advertiser.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-briefcase"></i>
                        <p>Advertisers</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="account_managers.php" class="nav-link <?php echo ($currentPage === 'account_managers.php' || $currentPage === 'manager_profile.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-user-tie"></i>
                        <p>Account Managers</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="pending_kyc.php" class="nav-link <?php echo ($currentPage === 'pending_kyc.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-id-card"></i>
                        <p>Pending KYC Approvals</p>
                    </a>
                </li>

                <li class="nav-header">ANALYTICS & REPORTS</li>
                <li class="nav-item">
                    <a href="reports_clicks.php" class="nav-link <?php echo ($currentPage === 'reports_clicks.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-mouse-pointer"></i>
                        <p>Click Reports</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="reports_campaigns.php" class="nav-link <?php echo ($currentPage === 'reports_campaigns.php' || $currentPage === 'reports_offers.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-chart-bar"></i>
                        <p>Campaign Reports</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="reports_affiliates.php" class="nav-link <?php echo ($currentPage === 'reports_affiliates.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-chart-line"></i>
                        <p>Affiliate Reports</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="reports_advertisers.php" class="nav-link <?php echo ($currentPage === 'reports_advertisers.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-chart-pie"></i>
                        <p>Advertiser Reports</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="reports_subid.php" class="nav-link <?php echo ($currentPage === 'reports_subid.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-list"></i>
                        <p>SubID Performance</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="fraud_dashboard.php" class="nav-link <?php echo ($currentPage === 'fraud_dashboard.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-shield-alt"></i>
                        <p>Anti-Fraud Security</p>
                    </a>
                </li>

                <li class="nav-header">TOOLS & TESTING</li>
                <li class="nav-item">
                    <a href="link_tester.php" class="nav-link <?php echo ($currentPage === 'link_tester.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-vial"></i>
                        <p>Link Tester</p>
                    </a>
                </li>

                <li class="nav-header">SYSTEM & POSTBACKS</li>
                <li class="nav-item">
                    <a href="publisher_postbacks.php" class="nav-link <?php echo ($currentPage === 'publisher_postbacks.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-code"></i>
                        <p>Global Postbacks Log</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="settings.php" class="nav-link <?php echo ($currentPage === 'settings.php') ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-cogs"></i>
                        <p>System Settings</p>
                    </a>
                </li>
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
