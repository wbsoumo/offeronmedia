<?php
/**
 * Manager Portal - Advertiser Reports
 */
if (!isset($_GET['tab'])) {
    $_GET['tab'] = 'advertisers';
}
require __DIR__ . '/reports.php';
