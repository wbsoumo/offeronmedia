<?php
/**
 * Manager Portal - Click Reports
 */
if (!isset($_GET['tab'])) {
    $_GET['tab'] = 'clicks';
}
require __DIR__ . '/reports.php';
