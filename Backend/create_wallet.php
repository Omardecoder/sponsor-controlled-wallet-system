<?php
// Backend/create_wallet.php
// A sponsor creates a wallet for a student, found by the student's email or phone.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_role('sponsor');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/sponsor-dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the form to create a wallet.');
}

$identifier = trim($_POST['student_identifier'] ?? '');
if ($identifier === '') {
    back('error', 'Enter the student\'s email or phone number.');
}

// Same normalising as login: phone numbers are stored without spaces or dashes
$lookup = str_contains($identifier, '@')
    ? strtolower($identifier)
    : preg_replace('/[\s\-]/', '', $identifier);

// Find a STUDENT with that email or phone
$stmt = $conn->prepare("SELECT id, full_name FROM users WHERE (email = ? OR phone = ?) AND role = 'student' LIMIT 1");
$stmt->bind_param('ss', $lookup, $lookup);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    back('error', 'No student account matches that email or phone number. Ask them to register as a Student first.');
}

$sponsorId = (int) $_SESSION['user_id'];

try {
    $stmt = $conn->prepare('INSERT INTO wallets (student_id, sponsor_id) VALUES (?, ?)');
    $stmt->bind_param('ii', $student['id'], $sponsorId);
    $stmt->execute();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1062) {   // duplicate: this student already has a wallet
        back('error', $student['full_name'] . ' already has a wallet.');
    }
    error_log('Create wallet failed: ' . $e->getMessage());
    back('error', 'Something went wrong. Please try again.');
}

back('success', 'Wallet created for ' . $student['full_name'] . '. You can fund it now.');
