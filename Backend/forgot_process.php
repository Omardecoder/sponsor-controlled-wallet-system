<?php
// Backend/forgot_process.php
// "Forgot password": if the email belongs to an account, email it a one-time reset link (30 minutes).
// The answer on screen is ALWAYS the same, so this page can't be used to discover registered emails.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/password_reset.php';

function back(string $key, string $message, string $email = ''): void
{
    $_SESSION[$key] = $message;
    $_SESSION['forgot_old'] = $email;
    header('Location: ../Frontend/forgot-password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../Frontend/forgot-password.php');
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back('forgot_error', 'Enter the email address you registered with.', $email);
}
if (!MAIL_READY) {
    // Says nothing about the account: the server simply can't send email yet
    back('forgot_error', 'Password reset by email is not set up on this server yet. Ask the administrator.', $email);
}

$sameAnswer = 'If ' . $email . ' is registered, a reset link is on its way. Check your inbox and spam folder. The link works once, for 30 minutes.';

$stmt = $conn->prepare('SELECT id, full_name, email FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    back('forgot_success', $sameAnswer);
}

try {
    if (recent_resets($conn, (int) $user['id']) >= RESET_MAX_PER_HOUR) {
        error_log('Password reset: limit reached for user ' . $user['id']);
        back('forgot_success', $sameAnswer);   // same answer: no hint either way
    }
    $token = create_reset($conn, (int) $user['id'], substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45));
} catch (Throwable $e) {
    error_log('Password reset failed: ' . $e->getMessage());
    back('forgot_error', 'Something went wrong. Please try again.', $email);
}

$link = app_url() . '/Frontend/reset-password.php?token=' . $token;
$name = htmlspecialchars(preg_split('/\s+/', trim($user['full_name']))[0]);
$html = mail_layout('Reset your password', [
    'Hi ' . $name . ',',
    'Someone (hopefully you) asked to reset the password of your SS-Wallet account. Click the button to choose a new one. The link works once and expires in ' . RESET_MINUTES . ' minutes.',
    'If you did not ask for this, ignore this email. Your password stays the same.',
], 'Choose a new password', $link);
$text = "Hi $name,\n\nReset your SS-Wallet password here (valid " . RESET_MINUTES . " minutes, works once):\n$link\n\nIf you did not ask for this, ignore this email.";
send_mail($user['email'], $user['full_name'], 'Reset your SS-Wallet password', $html, $text);   // failures are logged

back('forgot_success', $sameAnswer);