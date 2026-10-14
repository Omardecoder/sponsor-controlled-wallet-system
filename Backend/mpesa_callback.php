<?php
// Backend/mpesa_callback.php
// Safaricom calls this page with the result of an STK Push.
// On success it credits the wallet ONCE and records a funding transaction with the M-Pesa receipt code.
// No login here: Safaricom is the caller. A secret token in the URL keeps out fake callers.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mpesa_config.php';
require_once __DIR__ . '/notify.php';

header('Content-Type: application/json');

function reply_ok(): void
{
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

if (!hash_equals(MPESA_CALLBACK_TOKEN, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Forbidden']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
$cb   = $data['Body']['stkCallback'] ?? null;
if (!$cb || empty($cb['CheckoutRequestID'])) {
    error_log('M-Pesa callback: unreadable body: ' . mb_substr((string) $raw, 0, 500));
    reply_ok();   // tell Safaricom we received it, so it doesn't keep retrying
}

$checkoutId = (string) $cb['CheckoutRequestID'];
$resultCode = (string) ($cb['ResultCode'] ?? '');
$resultDesc = mb_substr((string) ($cb['ResultDesc'] ?? ''), 0, 255);

/** Student id and name for a wallet (for alerts). */
function wallet_student(mysqli $conn, int $walletId): ?array
{
    try {
        $stmt = $conn->prepare('SELECT u.id, u.full_name FROM wallets w JOIN users u ON u.id = w.student_id WHERE w.id = ?');
        $stmt->bind_param('i', $walletId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;   // an alert problem must never affect the payment
    }
}

// Pull the details Safaricom sends on success
$meta = [];
foreach ($cb['CallbackMetadata']['Item'] ?? [] as $item) {
    if (isset($item['Name'])) {
        $meta[$item['Name']] = $item['Value'] ?? null;
    }
}
$receipt = isset($meta['MpesaReceiptNumber']) ? mb_substr((string) $meta['MpesaReceiptNumber'], 0, 20) : null;

try {
    $conn->begin_transaction();

    // Find and lock the payment
    $stmt = $conn->prepare('SELECT id, wallet_id, sponsor_id, amount, note, status FROM mpesa_payments WHERE checkout_request_id = ? FOR UPDATE');
    $stmt->bind_param('s', $checkoutId);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payment || $payment['status'] !== 'pending') {
        // Unknown, or already handled (Safaricom may send the same callback twice)
        $conn->rollback();
        reply_ok();
    }
    $paymentId = (int) $payment['id'];

    if ($resultCode !== '0') {
        // Cancelled, wrong PIN, not enough money, timed out...
        $stmt = $conn->prepare("UPDATE mpesa_payments SET status = 'failed', result_code = ?, result_desc = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('ssi', $resultCode, $resultDesc, $paymentId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();

        // Alert the sponsor that it didn't go through
        $student = wallet_student($conn, (int) $payment['wallet_id']);
        notify(
            $conn, (int) $payment['sponsor_id'], 'payment_failed',
            'M-Pesa payment not completed',
            'KSh ' . number_format((float) $payment['amount']) . ($student ? ' to ' . $student['full_name'] : '') . ': ' . $resultDesc . ' No money was added.',
            'sponsor-dashboard.php#send-funds'
        );
        reply_ok();
    }

    // Paid. Credit the amount WE asked for (log if Safaricom reports a different one).
    $amount = $payment['amount'];
    if (isset($meta['Amount']) && abs((float) $meta['Amount'] - (float) $amount) > 0.001) {
        error_log("M-Pesa callback: amount mismatch for payment $paymentId (expected $amount, got {$meta['Amount']})");
    }
    $walletId = (int) $payment['wallet_id'];

    $stmt = $conn->prepare('SELECT id FROM wallets WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('UPDATE wallets SET balance = balance + ? WHERE id = ?');
    $stmt->bind_param('si', $amount, $walletId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('SELECT balance FROM wallets WHERE id = ?');
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $newBalance = $stmt->get_result()->fetch_assoc()['balance'];
    $stmt->close();

    $description = mb_substr('M-Pesa ' . ($receipt ?? '') . ($payment['note'] ? ' · ' . $payment['note'] : ''), 0, 255);
    $sponsorId = (int) $payment['sponsor_id'];
    $stmt = $conn->prepare(
        "INSERT INTO transactions (wallet_id, type, amount, balance_after, description, created_by)
         VALUES (?, 'funding', ?, ?, ?, ?)"
    );
    $stmt->bind_param('isssi', $walletId, $amount, $newBalance, $description, $sponsorId);
    $stmt->execute();
    $transactionId = (int) $conn->insert_id;
    $stmt->close();

    $stmt = $conn->prepare(
        "UPDATE mpesa_payments
         SET status = 'success', result_code = ?, result_desc = ?, mpesa_receipt = ?, transaction_id = ?, updated_at = NOW()
         WHERE id = ?"
    );
    $stmt->bind_param('sssii', $resultCode, $resultDesc, $receipt, $transactionId, $paymentId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    // Alerts: the student got money, the sponsor's payment is confirmed
    $student = wallet_student($conn, $walletId);
    if ($student) {
        notify(
            $conn, (int) $student['id'], 'funds_received',
            'KSh ' . number_format((float) $amount) . ' added to your wallet',
            'Sent by your sponsor via M-Pesa' . ($receipt ? ' (' . $receipt . ')' : '') . '. New balance: KSh ' . number_format((float) $newBalance, 2) . '.',
            'student-dashboard.php?tx=funding#transactions'
        );
    }
    notify(
        $conn, $sponsorId, 'funds_confirmed',
        'Payment confirmed: KSh ' . number_format((float) $amount) . ($student ? ' to ' . $student['full_name'] : ''),
        'M-Pesa receipt ' . ($receipt ?? '—') . '.',
        'sponsor-dashboard.php#transactions'
    );

} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('M-Pesa callback failed: ' . $e->getMessage());
    http_response_code(500);   // Safaricom will try again later
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Error']);
    exit;
}

reply_ok();