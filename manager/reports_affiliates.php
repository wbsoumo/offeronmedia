<?php
/**
 * Manager Portal - Publisher Performance Reports
 */
if (!isset($_GET['tab'])) {
    $_GET['tab'] = 'affiliates';
}
require __DIR__ . '/reports.php';
