<?php
// Backend/pay_settings_action.php
// Sponsor: Payment settings on the dashboard (Step 31).
//   auto_prompt = 1 -> when a budget I fund becomes active, send me an M-Pesa prompt for my share
//   mpesa_phone     -> the number for those prompts (empty = my registered phone)

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_role('sponsor');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/sponsor-dashboard.php#pay-settings');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the Payment settings form.');
}

$sponsorId = (int) $_SESSION['user_id'];
$auto      = !empty($_POST['auto_prompt']) ? 1 : 0;
$raw       = trim($_POST['mpesa_phone'] ?? '');
$phone     = null;

if ($raw !== '') {
    // Same rule as M-Pesa: a Kenyan mobile number, saved as 2547XXXXXXXX / 2541XXXXXXXX
    $digits = preg_replace('/\D/', '', $raw);
    if (preg_match('/^0([17]\d{8})$/', $digits, $m) || preg_match('/^254([17]\d{8})$/', $digits, $m) || preg_match('/^([17]\d{8})$/', $digits, $m)) {
        $phone = '254' . $m[1];
    } else {
        back('error', 'Enter a valid Safaricom number, for example 0712 345 678, or leave it empty to use your registered number.');
    }
}

try {
    $stmt = $conn->prepare('UPDATE users SET auto_prompt = ?, mpesa_phone = ? WHERE id = ?');
    $stmt->bind_param('isi', $auto, $phone, $sponsorId);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    error_log('Save payment settings failed: ' . $e->getMessage());
    back('error', 'Payment settings are not set up yet. Ask the administrator to run the Step 31 database update.');
}

back('success', $auto
    ? 'Saved. When a budget you fund becomes active, you will get an M-Pesa prompt for your share' . ($phone ? ' on 0' . substr($phone, 3) . '.' : ' on your registered number.')
    : 'Saved. Automatic M-Pesa prompts are off; you will get a reminder alert instead and pay from Send Funds.');