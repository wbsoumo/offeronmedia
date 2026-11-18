<?php
/**
 * Manager Portal - Campaign Performance Reports
 */
if (!isset($_GET['tab'])) {
    $_GET['tab'] = 'campaigns';
}
require __DIR__ . '/reports.php';
