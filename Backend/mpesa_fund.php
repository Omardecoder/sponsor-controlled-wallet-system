<?php
// Backend/mpesa_fund.php
// A sponsor funds a student's wallet with M-Pesa (STK Push) from the dashboard's Send Funds form.
// This only STARTS the payment. The wallet is credited by mpesa_callback.php when Safaricom confirms.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mpesa_funding.php';
require_once __DIR__ . '/sponsorship.php';
require_role('sponsor');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/sponsor-dashboard.php#send-funds');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the form to send funds.');
}

$sponsorId = (int) $_SESSION['user_id'];
$walletId  = (int) ($_POST['wallet_id'] ?? 0);
$amountIn  = str_replace([',', ' '], '', trim($_POST['amount'] ?? ''));
$phone     = mpesa_phone($_POST['phone'] ?? '');
$note      = trim($_POST['note'] ?? '');

// M-Pesa only accepts whole shillings
if (!preg_match('/^\d{1,7}$/', $amountIn) || (int) $amountIn < 1) {
    back('error', 'Enter a whole amount in shillings, for example 1500 (M-Pesa does not accept cents).');
}
$amount = (int) $amountIn;
if ($amount > 250000) {
    back('error', 'M-Pesa allows at most KSh 250,000 per transaction.');
}
if ($phone === null) {
    back('error', 'Enter a valid Safaricom number, for example 0712 345 678.');
}
if (mb_strlen($note) > 255) {
    back('error', 'The note must be 255 characters or fewer.');
}

// The wallet must belong to a student this sponsor actively sponsors, and be active
$wallet = sponsor_wallet($conn, $sponsorId, $walletId);

if (!$wallet) {
    back('error', 'Choose a student to fund.');
}
if ($wallet['status'] !== 'active') {
    back('error', 'This wallet is frozen and cannot be funded.');
}

// A sponsor can send up to their pledge for this student
$left = (float) $wallet['pledge'] - sponsor_pledge_used($conn, $sponsorId, $walletId);
if ($amount > $left) {
    back('error', 'That is more than what is left of your pledge for ' . $wallet['student_name'] . ' (KSh ' . number_format(max(0, $left))
        . '). Raise your pledge under My Students first, or send a smaller amount.');
}

try {
    $paymentId = mpesa_start_funding($conn, $sponsorId, $walletId, $amount, $phone, $note !== '' ? $note : null);
} catch (Throwable $e) {
    back('error', 'Could not start the M-Pesa payment: ' . $e->getMessage());
}

header('Location: ../Frontend/mpesa-wait.php?id=' . $paymentId);
exit;