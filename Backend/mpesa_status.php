<?php
// Backend/mpesa_status.php?id=PAYMENT_ID
// The waiting page asks this every few seconds: "has the payment finished?"
// If the callback is late, it also asks Safaricom directly so failures (cancelled, wrong PIN) show quickly.
// The wallet is only ever credited by mpesa_callback.php.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mpesa.php';
require_role('sponsor');

header('Content-Type: application/json');
header('Cache-Control: no-store');

$sponsorId = (int) $_SESSION['user_id'];
$paymentId = (int) ($_GET['id'] ?? 0);

function load_payment(mysqli $conn, int $paymentId, int $sponsorId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, status, amount, result_code, result_desc, mpesa_receipt, checkout_request_id,
                TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
         FROM mpesa_payments WHERE id = ? AND sponsor_id = ?'
    );
    $stmt->bind_param('ii', $paymentId, $sponsorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

$p = load_payment($conn, $paymentId, $sponsorId);
if (!$p) {
    http_response_code(404);
    echo json_encode(['status' => 'unknown']);
    exit;
}

// Still pending after 20 seconds: ask Safaricom (only to detect failures quickly)
if ($p['status'] === 'pending' && $p['checkout_request_id'] && (int) $p['age'] >= 20) {
    try {
        $q    = mpesa_stk_query($p['checkout_request_id']);
        $code = isset($q['ResultCode']) ? (string) $q['ResultCode'] : null;
        if ($code !== null && $code !== '0') {
            $desc = mb_substr((string) ($q['ResultDesc'] ?? 'Payment failed'), 0, 255);
            $stmt = $conn->prepare("UPDATE mpesa_payments SET status = 'failed', result_code = ?, result_desc = ?, updated_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->bind_param('ssi', $code, $desc, $paymentId);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        // Network hiccup: just try again on the next check
    }
    // Note: we never mark a payment failed just because time passed. If the sponsor paid but the
    // callback is late, it stays "pending" and is credited when the callback arrives.
    $p = load_payment($conn, $paymentId, $sponsorId);
}

echo json_encode([
    'status'  => $p['status'],
    'age'     => (int) $p['age'],
    'amount'  => number_format((float) $p['amount'], 2),
    'receipt' => $p['mpesa_receipt'],
    'message' => $p['result_desc'],
]);