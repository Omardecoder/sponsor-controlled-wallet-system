<?php
// backend/login_process.php
// Step 9: receives the login form, checks the user, creates the session,
// and sends each role to its own dashboard.

session_start();
require_once __DIR__ . '/db.php';

// Where each role lands after a successful login.
// (Create these pages in the frontend folder as you build the dashboards.)
$dashboards = [
    'student' => '../Frontend/student-dashboard.php',
    'sponsor' => '../Frontend/sponsor-dashboard.php',
    'admin'   => '../Frontend/admin-dashboard.php',
];

// Send the user back to the login page with an error message
function fail(string $message, string $identifier = ''): void
{
    $_SESSION['login_error'] = $message;
    $_SESSION['login_old_identifier'] = $identifier;
    header('Location: ../Frontend/login.php');
    exit;
}

// Only accept form submissions (POST), not someone typing the URL
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../Frontend/login.php');
    exit;
}

$identifier = trim($_POST['identifier'] ?? '');
$password   = $_POST['password'] ?? '';

if ($identifier === '' || $password === '') {
    fail('Enter your email or phone number and your password.', $identifier);
}

// If it's a phone number, remove spaces and dashes so "0712 345 678" matches "0712345678"
$lookup = str_contains($identifier, '@') ? $identifier : preg_replace('/[\s\-]/', '', $identifier);

// Search the users table (prepared statement = safe from SQL injection)
$stmt = $conn->prepare('SELECT id, full_name, password, role FROM users WHERE email = ? OR phone = ? LIMIT 1');
$stmt->bind_param('ss', $lookup, $lookup);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Same message whether the account or the password is wrong,
// so attackers can't discover which emails/phones are registered.
if (!$user || !password_verify($password, $user['password'])) {
    fail('Incorrect email/phone or password.', $identifier);
}

// Success: create a fresh session
session_regenerate_id(true);
unset($_SESSION['login_error'], $_SESSION['login_old_identifier']);

$_SESSION['user_id']   = (int) $user['id'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role']      = $user['role'];

// Redirect by role
$target = $dashboards[$user['role']] ?? null;
if ($target === null) {
    session_destroy();
    fail('This account has no valid role. Contact support.', $identifier);
}

header('Location: ' . $target);
exit;