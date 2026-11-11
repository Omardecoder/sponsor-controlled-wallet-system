<?php
// Backend/check_setup.php
// Temporary checker: shows which folder is really running and which files / database tables are missing.
// Open it in the browser, then DELETE this file when everything is fixed.
ini_set('display_errors', '1');
error_reporting(E_ALL);

$files = [
    'db.php', 'auth.php', 'notify.php', 'sponsorship.php', 'family_link.php', 'family_action.php',
    'register_process.php', 'profile_action.php', 'sponsorship_action.php', 'student_docs.php',
    'student_document_action.php', 'view_student_document.php', 'mailer.php', 'password_reset.php',
    'programs.php', 'categories.php', 'auto_collect.php', 'auto_category.php', 'mpesa_funding.php',
    'lib/PHPMailer/PHPMailer.php',
];
header('Content-Type: text/plain; charset=utf-8');
$out = [];
// Print whatever we have even if db.php stops the script
register_shutdown_function(function () use (&$out) { echo implode("\n", $out), "\n"; });
$out[] = 'This page is running from: ' . __DIR__;
$out[] = '';
foreach ($files as $f) {
    $p = __DIR__ . '/' . $f;
    $out[] = (is_file($p) ? (filesize($p) > 50 ? 'OK      ' : 'EMPTY   ') : 'MISSING ') . $f;
}
$out[] = '';

// Does sponsorship.php have the relationship list?
if (is_file(__DIR__ . '/sponsorship.php')) {
    $src = file_get_contents(__DIR__ . '/sponsorship.php');
    $out[] = (str_contains($src, 'const RELATIONSHIPS') ? 'OK      ' : 'OLD     ') . 'sponsorship.php has the RELATIONSHIPS list';
}
foreach (['family_link.php' => 'function link_family', 'student_docs.php' => 'function student_documents'] as $f => $needle) {
    if (is_file(__DIR__ . '/' . $f)) {
        $out[] = (str_contains(file_get_contents(__DIR__ . '/' . $f), $needle) ? 'OK      ' : 'WRONG   ') . "$f has the right code";
    }
}
$out[] = '';

// Database: Step 30 tables / columns
try {
    $out[] = '(If nothing appears after this line, the database is not running: start MySQL in XAMPP.)';
    require __DIR__ . '/db.php';
    $checks = [
        "SHOW TABLES LIKE 'family_invites'"            => 'table family_invites (Step 30 SQL)',
        "SHOW TABLES LIKE 'student_documents'"         => 'table student_documents (Step 30 SQL)',
        "SHOW COLUMNS FROM sponsorships LIKE 'linked_by'" => 'column sponsorships.linked_by (Step 30 SQL)',
        "SHOW COLUMNS FROM users LIKE 'achievements'"  => 'column users.achievements (Step 30 SQL)',
        "SHOW COLUMNS FROM users LIKE 'auto_prompt'"   => 'column users.auto_prompt (Step 31 SQL)',
    ];
    foreach ($checks as $sql => $label) {
        $out[] = ($conn->query($sql)->num_rows ? 'OK      ' : 'MISSING ') . $label;
    }
} catch (Throwable $e) {
    $out[] = 'DATABASE ERROR: ' . $e->getMessage();
}

// Finally, load what register.php loads, to show the real error if there is one
$out[] = '';
try {
    require_once __DIR__ . '/family_link.php';
    $out[] = defined('RELATIONSHIPS') ? 'OK      register page can load its files' : 'PROBLEM RELATIONSHIPS is still not defined';
} catch (Throwable $e) {
    $out[] = 'ERROR   ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ' line ' . $e->getLine();
}

