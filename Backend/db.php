<?php
// Backend/db.php
// Connection between PHP and MySQL. Other files use:  require_once __DIR__ . '/db.php';  then $conn.
// Do not echo anything in this file: output here breaks redirects.

$DB_HOST = 'localhost';
$DB_USER = 'root';          // XAMPP default
$DB_PASS = '';              // XAMPP default (empty)
$DB_NAME = 'sponsor_wallet_db';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    exit('Database connection failed: ' . $e->getMessage());   // development only
}
