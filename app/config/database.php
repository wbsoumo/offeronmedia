<?php
/**
 * Database connection (PHP 7.1 compatible)
 */

if (!defined('APP_INIT')) {
    die('Direct access not allowed');
}

$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'connectpaw_offeronmedia';
$DB_USER = getenv('DB_USER') ?: 'connectpaw_pawan';
$DB_PASS = getenv('DB_PASS') !== false ? getenv('DB_PASS') : ']OiEKD9*FSQJPaaa';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Fallback to alternate production database credentials
    try {
        $pdo = new PDO(
            "mysql:host=localhost;dbname=zktddbzk_offeronmedia;charset=utf8mb4",
            'zktddbzk_azhar',
            'Soumojit1234@',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e2) {
        error_log('DB Connection failed: ' . $e->getMessage());
        die('Database connection error');
    }
}
