<?php
/**
 * Pending Offer Applications & Requests
 * Redirects directly to Offer Approvals Pending Queue
 */
define('APP_INIT', true);
header('Location: campaign_access.php#pending-queue');
exit;
