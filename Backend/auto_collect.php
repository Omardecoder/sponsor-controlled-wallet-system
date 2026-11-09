<?php
// Backend/auto_collect.php
// Step 31: when a budget becomes ACTIVE, ask every funder for what they still owe of their share
// with an M-Pesa prompt (STK Push) on their phone. Nobody has to open the dashboard and type amounts.
//
// For each funder of the budget (active sponsorship, commitment > 0):
//   still owed = their commitment - what they already sent since the budget was submitted
//                - M-Pesa payments for this budget (or plain Send Funds) still waiting, so nobody is asked twice
//   -> prompt for that amount (whole shillings, at most KSh 250,000) on their M-Pesa number
//      (users.mpesa_phone, else their registered phone), unless they turned automatic prompts off.
// Whatever happens, the funder gets an in-app alert: "check your phone" or "pay from the dashboard".
// The wallet is credited only when Safaricom confirms (mpesa_callback.php), as before.
// Usage:  require_once __DIR__ . '/auto_collect.php';  $ids = request_shares($conn, $proposalId);

require_once __DIR__ . '/sponsorship.php';

if (!defined('MPESA_READY')) {
    define('MPESA_READY', is_file(__DIR__ . '/mpesa_config.php'));
}
if (MPESA_READY) {
    require_once __DIR__ . '/mpesa_funding.php';
}

const STK_MAX = 250000;   // M-Pesa limit per payment

/** The sponsor's automatic-prompt setting and M-Pesa number. Works before the Step 31 SQL is run. */
function sponsor_pay_settings(mysqli $conn, int $sponsorId): array
{
    try {
        $stmt = $conn->prepare('SELECT phone, auto_prompt, mpesa_phone FROM users WHERE id = ?');
        $stmt->bind_param('i', $sponsorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return ['ready' => true, 'auto' => (int) ($row['auto_prompt'] ?? 1) === 1,
                'phone' => $row['mpesa_phone'] ?: ($row['phone'] ?? ''), 'mpesa_phone' => $row['mpesa_phone'] ?? null,
                'registered' => $row['phone'] ?? ''];
    } catch (Throwable $e) {
        $stmt = $conn->prepare('SELECT phone FROM users WHERE id = ?');
        $stmt->bind_param('i', $sponsorId);
        $stmt->execute();
        $phone = $stmt->get_result()->fetch_assoc()['phone'] ?? '';
        $stmt->close();
        return ['ready' => false, 'auto' => true, 'phone' => $phone, 'mpesa_phone' => null, 'registered' => $phone];
    }
}

/**
 * Sends the prompts for an ACTIVE budget. Call AFTER the transaction that activated it is committed.
 * $skip = sponsor ids not to prompt (e.g. the funder who already chose "Pay my share now").
 * Returns [sponsorId => paymentId] for the prompts that were sent.
 */
function request_shares(mysqli $conn, int $proposalId, array $skip = []): array
{
    $sent = [];
    try {
        $stmt = $conn->prepare(
            "SELECT p.id, p.created_at, p.wallet_id, w.status AS wallet_status, w.student_id, u.full_name AS student_name
             FROM budget_proposals p JOIN wallets w ON w.id = p.wallet_id JOIN users u ON u.id = w.student_id
             WHERE p.id = ? AND p.status = 'approved'"
        );
        $stmt->bind_param('i', $proposalId);
        $stmt->execute();
        $budget = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$budget) {
            return [];
        }
        $walletId    = (int) $budget['wallet_id'];
        $studentName = $budget['student_name'];

        $stmt = $conn->prepare(
            "SELECT r.sponsor_id, r.commit_amount
             FROM proposal_reviews r
             JOIN sponsorships sp ON sp.sponsor_id = r.sponsor_id AND sp.student_id = ? AND sp.status = 'active'
             WHERE r.proposal_id = ? AND r.decision = 'approved' AND r.commit_amount > 0"
        );
        $studentId = (int) $budget['student_id'];
        $stmt->bind_param('ii', $studentId, $proposalId);
        $stmt->execute();
        $funders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Throwable $e) {
        error_log('request_shares: ' . $e->getMessage());
        return [];
    }

    foreach ($funders as $f) {
        $sponsorId = (int) $f['sponsor_id'];
        if (in_array($sponsorId, $skip, true)) {
            continue;
        }

        // Still owed = commitment - sent since the budget was submitted - payments still waiting
        $stmt = $conn->prepare(
            "SELECT
               (SELECT COALESCE(SUM(amount), 0) FROM transactions
                 WHERE wallet_id = ? AND type = 'funding' AND created_by = ? AND created_at >= ?) AS sent,
               (SELECT COALESCE(SUM(amount), 0) FROM mpesa_payments
                 WHERE wallet_id = ? AND sponsor_id = ? AND status = 'pending' AND created_at > NOW() - INTERVAL 10 MINUTE
                   AND (proposal_id = ? OR proposal_id IS NULL)) AS waiting"
        );
        $stmt->bind_param('iisiii', $walletId, $sponsorId, $budget['created_at'], $walletId, $sponsorId, $proposalId);
        $stmt->execute();
        $m = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $owed = round((float) $f['commit_amount'] - (float) $m['sent'] - (float) $m['waiting'], 2);
        if ($owed < 1) {
            continue;
        }
        $amount = (int) min(ceil($owed), STK_MAX);
        $owedText = 'KSh ' . number_format($owed, 2);
        $link = 'sponsor-dashboard.php#send-funds';

        $settings = sponsor_pay_settings($conn, $sponsorId);
        $phone    = MPESA_READY ? mpesa_phone((string) $settings['phone']) : null;

        // Prompt turned off, M-Pesa not set up on this server, frozen wallet or no valid number: just remind
        $reason = null;
        if (!$settings['auto']) {
            $reason = '';
        } elseif (!MPESA_READY) {
            $reason = ' (automatic M-Pesa prompts are not set up on this server)';
        } elseif ($budget['wallet_status'] !== 'active') {
            $reason = ' (the wallet is frozen right now)';
        } elseif ($phone === null) {
            $reason = ' (your phone number is not a valid M-Pesa number; set it under Payment settings)';
        }
        if ($reason !== null) {
            notify($conn, $sponsorId, 'share_due', $studentName . '\'s budget is active: please send your share',
                'You still owe ' . $owedText . ' of your share. Pay it from Send Funds on your dashboard' . $reason . '.',
                $link, 'share_due_' . $proposalId);
            continue;
        }

        try {
            $paymentId = mpesa_start_funding($conn, $sponsorId, $walletId, $amount, $phone,
                'Auto: share of budget #' . $proposalId, $proposalId);
            $sent[$sponsorId] = $paymentId;
            $local  = '0' . substr($phone, 3);                       // 2547... -> 07...
            $masked = substr($local, 0, 4) . ' *** ' . substr($local, -3);
            notify($conn, $sponsorId, 'share_prompt', 'Check your phone: M-Pesa prompt for KSh ' . number_format($amount),
                $studentName . '\'s budget is active. We sent an M-Pesa prompt to ' . $masked . ' for your share'
                    . ($owed > STK_MAX ? ' (the first KSh ' . number_format(STK_MAX) . '; send the rest from your dashboard)' : '')
                    . '. Enter your PIN to pay. Missed it? Pay from Send Funds on your dashboard.',
                'mpesa-wait.php?id=' . $paymentId, 'share_prompt_' . $proposalId);
        } catch (Throwable $e) {
            notify($conn, $sponsorId, 'share_due', $studentName . '\'s budget is active: please send your share',
                'We could not send the M-Pesa prompt (' . mb_substr($e->getMessage(), 0, 120) . '). You still owe '
                    . $owedText . '. Pay it from Send Funds on your dashboard.',
                $link, 'share_due_' . $proposalId);
        }
    }
    return $sent;
}