<?php
// backend/db.php
// Step 8: the connection between PHP and MySQL (sponsor_wallet_db).
// Any backend file that needs the database does:  require_once __DIR__ . '/db.php';
// and then uses the $conn variable.

$DB_HOST = 'localhost';
$DB_USER = 'root';          // XAMPP/WAMP default. Use a dedicated user in production.
$DB_PASS = '';              // XAMPP/WAMP default is empty. Set yours if you changed it.
$DB_NAME = 'sponsor_wallet_db';

// Make MySQL errors throw exceptions so we can catch them
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    // Log the real reason privately, show a generic message to the user
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Sorry, the system is temporarily unavailable. Please try again later.');
}