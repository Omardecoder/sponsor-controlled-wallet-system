<?php
// Backend/mpesa_funding.php
// One shared function that starts an M-Pesa payment into a student's wallet.
// Used by: mpesa_fund.php (Send Funds) and review_budget_proposal.php (approve + top up).
// The wallet is credited later, only by mpesa_callback.php, when Safaricom confirms.

require_once __DIR__ . '/mpesa.php';

/**
 * Saves a pending payment and sends the STK prompt.
 * Returns the new mpesa_payments id. Throws RuntimeException with a readable message on failure.
 */
function mpesa_start_funding(mysqli $conn, int $sponsorId, int $walletId, int $amount, string $phone254, ?string $note, ?int $proposalId = null): int
{
    $amountStr = number_format($amount, 2, '.', '');

    $stmt = $conn->prepare(
        'INSERT INTO mpesa_payments (wallet_id, sponsor_id, proposal_id, amount, phone, note) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iiisss', $walletId, $sponsorId, $proposalId, $amountStr, $phone254, $note);
    $stmt->execute();
    $paymentId = (int) $conn->insert_id;
    $stmt->close();

    try {
        $reply = mpesa_stk_push($phone254, $amount, 'SW' . $walletId, $proposalId ? 'Budget top-up' : 'Wallet fund');
    } catch (Throwable $e) {
        $msg = mb_substr($e->getMessage(), 0, 255);
        $stmt = $conn->prepare("UPDATE mpesa_payments SET status = 'failed', result_desc = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $msg, $paymentId);
        $stmt->execute();
        $stmt->close();
        error_log('STK push failed: ' . $e->getMessage());
        throw new RuntimeException($e->getMessage());
    }

    $stmt = $conn->prepare(
        'UPDATE mpesa_payments SET merchant_request_id = ?, checkout_request_id = ?, updated_at = NOW() WHERE id = ?'
    );
    $stmt->bind_param('ssi', $reply['MerchantRequestID'], $reply['CheckoutRequestID'], $paymentId);
    $stmt->execute();
    $stmt->close();

    return $paymentId;
}