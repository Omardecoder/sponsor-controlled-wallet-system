<?php
// Backend/mpesa_fund.php
// A sponsor funds a student's wallet with M-Pesa (STK Push).
// This only STARTS the payment: a prompt goes to the sponsor's phone.
// The wallet is credited later, in mpesa_callback.php, and only if Safaricom confirms the payment.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mpesa.php';
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

// The wallet must be this sponsor's and active
$stmt = $conn->prepare(
    'SELECT w.id, w.status, u.full_name FROM wallets w JOIN users u ON u.id = w.student_id
     WHERE w.id = ? AND w.sponsor_id = ?'
);
$stmt->bind_param('ii', $walletId, $sponsorId);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$wallet) {
    back('error', 'That wallet was not found.');
}
if ($wallet['status'] !== 'active') {
    back('error', 'This wallet is frozen and cannot be funded.');
}

// 1. Save the payment as "pending" before asking Safaricom
$noteValue = $note !== '' ? $note : null;
$amountStr = number_format($amount, 2, '.', '');
$stmt = $conn->prepare(
    'INSERT INTO mpesa_payments (wallet_id, sponsor_id, amount, phone, note) VALUES (?, ?, ?, ?, ?)'
);
$stmt->bind_param('iisss', $walletId, $sponsorId, $amountStr, $phone, $noteValue);
$stmt->execute();
$paymentId = (int) $conn->insert_id;
$stmt->close();

// 2. Send the prompt to the phone
try {
    $reply = mpesa_stk_push($phone, $amount, 'SW' . $walletId, 'Wallet fund');
} catch (Throwable $e) {
    $msg = mb_substr($e->getMessage(), 0, 255);
    $stmt = $conn->prepare("UPDATE mpesa_payments SET status = 'failed', result_desc = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('si', $msg, $paymentId);
    $stmt->execute();
    $stmt->close();
    error_log('STK push failed: ' . $e->getMessage());
    back('error', 'Could not start the M-Pesa payment: ' . $e->getMessage());
}

// 3. Remember Safaricom's reference so the callback can find this payment
$stmt = $conn->prepare(
    'UPDATE mpesa_payments SET merchant_request_id = ?, checkout_request_id = ?, updated_at = NOW() WHERE id = ?'
);
$stmt->bind_param('ssi', $reply['MerchantRequestID'], $reply['CheckoutRequestID'], $paymentId);
$stmt->execute();
$stmt->close();

header('Location: ../Frontend/mpesa-wait.php?id=' . $paymentId);
exit;