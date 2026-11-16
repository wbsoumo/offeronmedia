<?php
/**
 * Reports Offers (Redirect to Campaign Reports)
 */
define('APP_INIT', true);
$query = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: reports_campaigns.php' . $query);
exit;
