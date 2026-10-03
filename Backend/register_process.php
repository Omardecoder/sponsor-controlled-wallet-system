<?php
// backend/register_process.php
// Receives the registration form, validates it, and saves a new user.
// Public registration can only create "student" or "sponsor" accounts.

session_start();
require_once __DIR__ . '/db.php';

const ALLOWED_ROLES = ['student', 'sponsor'];   // admin is never allowed here

function back_with_errors(array $errors, array $old): void
{
    $_SESSION['register_errors'] = $errors;
    unset($old['password'], $old['confirm_password']);   // never send passwords back
    $_SESSION['register_old'] = $old;
    header('Location: ../Frontend/register.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../Frontend/register.php');
    exit;
}

$full_name = trim($_POST['full_name'] ?? '');
$email     = strtolower(trim($_POST['email'] ?? ''));
$phone_raw = trim($_POST['phone'] ?? '');
$phone     = preg_replace('/[\s\-]/', '', $phone_raw);   // store without spaces/dashes
$password  = $_POST['password'] ?? '';
$confirm   = $_POST['confirm_password'] ?? '';
$role      = $_POST['role'] ?? '';

$old = ['full_name' => $full_name, 'email' => $email, 'phone' => $phone_raw, 'role' => $role,
        'password' => '', 'confirm_password' => ''];
$errors = [];

// ---- Validation ----
if ($full_name === '' || mb_strlen($full_name) > 100) {
    $errors[] = 'Enter your full name (up to 100 characters).';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    $errors[] = 'Enter a valid email address.';
}
if (!preg_match('/^\+?[0-9]{9,15}$/', $phone)) {
    $errors[] = 'Enter a valid phone number, for example 0712 345 678.';
}
if (strlen($password) < 8) {
    $errors[] = 'Your password must be at least 8 characters.';
}
if ($password !== $confirm) {
    $errors[] = 'The two passwords do not match.';
}
if (!in_array($role, ALLOWED_ROLES, true)) {
    $errors[] = 'Choose Student or Sponsor.';
}

// ---- Duplicate check (only if the basics are fine) ----
if (!$errors) {
    $stmt = $conn->prepare('SELECT email, phone FROM users WHERE email = ? OR phone = ?');
    $stmt->bind_param('ss', $email, $phone);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if ($row['email'] === $email) { $errors[] = 'An account with this email already exists.'; }
        if ($row['phone'] === $phone) { $errors[] = 'An account with this phone number already exists.'; }
    }
    $stmt->close();
    $errors = array_values(array_unique($errors));
}

if ($errors) {
    back_with_errors($errors, $old);
}

// ---- Save the user with a hashed password ----
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $conn->prepare('INSERT INTO users (full_name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('sssss', $full_name, $email, $phone, $hash, $role);
    $stmt->execute();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    // 1062 = duplicate entry (e.g. two people registering at the same moment)
    if ($e->getCode() === 1062) {
        back_with_errors(['An account with this email or phone number already exists.'], $old);
    }
    error_log('Register failed: ' . $e->getMessage());
    back_with_errors(['Something went wrong. Please try again.'], $old);
}

$_SESSION['flash_success'] = 'Account created. You can log in now.';
header('Location: ../Frontend/login.php');
exit;