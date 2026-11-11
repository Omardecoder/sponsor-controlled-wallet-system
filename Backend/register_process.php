<?php
// backend/register_process.php
// Receives the registration form, validates it, and saves a new user.
// Public registration can only create student or sponsor accounts (family, well-wisher or organization).
// An organization is verified later by the admin.

// Step 30: a student can tick "My family" and give a family member's email (linked or invited right
// after the account is created), and/or "Well-wishers" (reminded to fill in My Profile to be listed).
// A family member registering from an invitation link (invite=TOKEN) is linked to that student.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/family_link.php';

// admin is never allowed here. family / wellwisher / organization are all saved as role = 'sponsor'
// with sponsor_type = 'family' / 'individual' / 'organization'.
const ALLOWED_ROLES = ['student', 'family', 'wellwisher', 'organization'];
const SPONSOR_TYPES = ['family' => 'family', 'wellwisher' => 'individual', 'organization' => 'organization'];

function back_with_errors(array $errors, array $old): void
{
    $_SESSION['register_errors'] = $errors;
    unset($old['password'], $old['confirm_password']);   // never send passwords back
    $_SESSION['register_old'] = $old;
    header('Location: ../Frontend/register.php' . (!empty($old['invite']) ? '?invite=' . urlencode($old['invite']) : ''));
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
if ($role === 'sponsor') {
    $role = 'wellwisher';   // old form value
}

// Student: who will fund you
$fundFamily      = $role === 'student' && !empty($_POST['fund_family']);
$fundWellWishers = $role === 'student' && !empty($_POST['fund_wellwishers']);
$familyEmail     = strtolower(trim($_POST['family_email'] ?? ''));
$relationship    = $_POST['relationship'] ?? '';

// Invitation from a student (family members only)
$inviteToken = (string) ($_POST['invite'] ?? '');
$invite      = $inviteToken !== '' ? find_invite($conn, $inviteToken) : null;

$old = ['full_name' => $full_name, 'email' => $email, 'phone' => $phone_raw, 'role' => $role,
        'password' => '', 'confirm_password' => '', 'invite' => $invite ? $inviteToken : '',
        'fund_family' => $fundFamily ? '1' : '', 'fund_wellwishers' => $fundWellWishers ? '1' : '',
        'family_email' => $familyEmail, 'relationship' => $relationship];
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
    $errors[] = 'Choose Student, Family / guardian, Well-wisher or Organization.';
}

if ($role === 'student') {
    if (!$fundFamily && !$fundWellWishers) {
        $errors[] = 'Tell us who will fund you: your family, well-wishers or both.';
    }
    if ($fundFamily) {
        if (!filter_var($familyEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter your family member\'s email address.';
        } elseif ($familyEmail === $email) {
            $errors[] = 'Enter your family member\'s email, not your own.';
        }
        if (!isset(RELATIONSHIPS[$relationship])) {
            $errors[] = 'Choose how your family member is related to you.';
        }
    }
}
if ($inviteToken !== '' && !$invite) {
    $errors[] = 'This invitation link has expired or was cancelled. Remove it from the address bar to register normally.';
}
if ($invite) {
    if ($role !== 'family') {
        $errors[] = 'An invitation from a student is for a Family account.';
    }
    if ($email !== strtolower($invite['email'])) {
        $errors[] = 'Use the email the invitation was sent to (' . $invite['email'] . ') to be linked.';
    }
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

// Sponsors and organizations both use the sponsor role; sponsor_type tells them apart
$dbRole      = $role === 'student' ? 'student' : 'sponsor';
$sponsorType = SPONSOR_TYPES[$role] ?? null;

try {
    $stmt = $conn->prepare('INSERT INTO users (full_name, email, phone, password, role, sponsor_type) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('ssssss', $full_name, $email, $phone, $hash, $dbRole, $sponsorType);
    $stmt->execute();
    $newId = (int) $conn->insert_id;
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    // 1062 = duplicate entry (e.g. two people registering at the same moment)
    if ($e->getCode() === 1062) {
        back_with_errors(['An account with this email or phone number already exists.'], $old);
    }
    error_log('Register failed: ' . $e->getMessage());
    back_with_errors(['Something went wrong. Please try again.'], $old);
}

$message = $role === 'organization'
    ? 'Organization account created. You can log in and set up programs now; students will see them once the administrator verifies your organization.'
    : 'Account created. You can log in now.';

// Family member from an invitation: link to the student
if ($invite) {
    $studentName = accept_invite($conn, $invite, $newId, $full_name);
    if ($studentName !== null) {
        $message = 'Account created and linked to ' . $studentName . '. Log in to see their wallet and the budgets they send.';
    }
}

// Student: link the family member now (linked if they have a Family account, otherwise invited)
if ($role === 'student') {
    if ($fundFamily) {
        $r = link_family($conn, $newId, $full_name, $email, $familyEmail, $relationship);
        $message .= ' ' . $r['message'];
        if (!empty($r['invite_link'])) {
            $message .= ' ' . $r['invite_link'];
        } elseif ($r['type'] === 'error') {
            $message .= ' You can try again from My Profile.';
        }
    }
    if ($fundWellWishers) {
        $message .= ' To be found by well-wishers, open My Profile after you log in and tick "Show me to well-wishers".';
    }
}

$_SESSION['flash_success'] = $message;
header('Location: ../Frontend/login.php');
exit;