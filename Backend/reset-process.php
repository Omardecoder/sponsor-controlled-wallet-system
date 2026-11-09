<?php
// Backend/reset_process.php
// Saves the new password chosen on reset-password.php, if the token is still valid.
// The token is then used up, every other reset link of the account stops working,
// and the user gets an alert and an email saying the password was changed.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/password_reset.php';
require_once __DIR__ . '/notify.php';

$token    = (string) ($_POST['token'] ?? '');
$password = $_POST['password'] ?? '';
$confirm  = $_POST['confirm_password'] ?? '';

function back_to_form(string $token, string $message): void
{
    $_SESSION['reset_error'] = $message;
    $_SESSION['reset_token'] = $token;   // kept in the session, not in the address bar
    header('Location: ../Frontend/reset-password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../Frontend/login.php');
    exit;
}
if (strlen($password) < 8) {
    back_to_form($token, 'Your password must be at least 8 characters.');
}
if ($password !== $confirm) {
    back_to_form($token, 'The two passwords do not match.');
}

try {
    $conn->begin_transaction();
    $reset = find_reset($conn, $token);
    if (!$reset) {
        $conn->rollback();
        back_to_form($token, 'This reset link is invalid or has expired.');
    }
    $userId = (int) $reset['user_id'];

    // Lock the token row so it can't be used twice at the same moment
    $stmt = $conn->prepare('SELECT id FROM password_resets WHERE id = ? AND used_at IS NULL FOR UPDATE');
    $resetId = (int) $reset['id'];
    $stmt->bind_param('i', $resetId);
    $stmt->execute();
    $still = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$still) {
        $conn->rollback();
        back_to_form($token, 'This reset link has already been used.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
    $stmt->bind_param('si', $hash, $userId);
    $stmt->execute();
    $stmt->close();

    // Use up this token and any other open one for the account
    $stmt = $conn->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('Reset password failed: ' . $e->getMessage());
    back_to_form($token, 'Something went wrong. Your password was not changed. Please try again.');
}

notify($conn, $userId, 'password_changed', 'Your password was changed',
    'Your password was reset with an email link on ' . date('j M Y, H:i') . '. If this was not you, reset it again at once and tell the administrator.');
send_mail($reset['email'], $reset['full_name'], 'Your SS-Wallet password was changed',
    mail_layout('Your password was changed', [
        'The password of your SS-Wallet account was just changed with a reset link.',
        'If this was not you, use "Forgot password?" on the login page straight away and tell the administrator.',
    ]),
    "The password of your SS-Wallet account was just changed. If this was not you, reset it again and tell the administrator.");

// Log out anyone currently logged in with this browser session
$_SESSION = [];
session_regenerate_id(true);
$_SESSION['flash_success'] = 'Your password was changed. Log in with your new password.';
header('Location: ../Frontend/login.php');
exit;