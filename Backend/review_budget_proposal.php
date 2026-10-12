<?php
// Backend/review_budget_proposal.php
// A sponsor reviews a budget proposal from one of THEIR students.
//
// decision = approve -> proposal becomes "approved" and its amounts are copied into budget_allocations.
//                       If the sponsor entered a top-up amount, an M-Pesa prompt is then sent to their
//                       phone; the wallet is credited only when Safaricom confirms (mpesa_callback.php).
// decision = changes -> proposal becomes "changes_requested" with the sponsor's feedback.
//
// The approval itself happens in one database transaction: all of it succeeds, or none of it does.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mpesa_funding.php';
require_role('sponsor');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/sponsor-proposals.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the form to review a proposal.');
}

$sponsorId  = (int) $_SESSION['user_id'];
$proposalId = (int) ($_POST['proposal_id'] ?? 0);
$decision   = $_POST['decision'] ?? '';
$feedback   = trim($_POST['feedback'] ?? '');
$fundRaw    = str_replace([',', ' '], '', trim($_POST['fund_amount'] ?? '0'));
$phoneIn    = $_POST['phone'] ?? '';

if (!in_array($decision, ['approve', 'changes'], true)) {
    back('error', 'Choose Approve or Request changes.');
}
if ($decision === 'changes' && $feedback === '') {
    back('error', 'Tell the student what to change before sending it back.');
}
if (mb_strlen($feedback) > 1000) {
    back('error', 'Feedback must be 1000 characters or fewer.');
}

// M-Pesa top-up when approving (0 = approve without topping up). Whole shillings only.
$fundAmount = 0;
$phone      = null;
if ($decision === 'approve') {
    if ($fundRaw === '') {
        $fundRaw = '0';
    }
    if (!preg_match('/^\d{1,7}$/', $fundRaw)) {
        back('error', 'Enter the top-up as a whole amount in shillings, for example 1500. Use 0 to approve without topping up.');
    }
    $fundAmount = (int) $fundRaw;
    if ($fundAmount > 250000) {
        back('error', 'M-Pesa allows at most KSh 250,000 per transaction.');
    }
    if ($fundAmount > 0) {
        $phone = mpesa_phone($phoneIn);
        if ($phone === null) {
            back('error', 'Enter a valid Safaricom number to pay the top-up from, for example 0712 345 678.');
        }
    }
}

try {
    $conn->begin_transaction();

    // The proposal must be on a wallet this sponsor controls. Lock it.
    $stmt = $conn->prepare(
        'SELECT p.id, p.status, w.id AS wallet_id, w.status AS wallet_status, u.full_name AS student_name
         FROM budget_proposals p
         JOIN wallets w ON w.id = p.wallet_id
         JOIN users u   ON u.id = w.student_id
         WHERE p.id = ? AND w.sponsor_id = ?
         FOR UPDATE'
    );
    $stmt->bind_param('ii', $proposalId, $sponsorId);
    $stmt->execute();
    $proposal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$proposal) {
        $conn->rollback();
        back('error', 'That proposal was not found.');
    }
    if ($proposal['status'] !== 'pending') {
        $conn->rollback();
        back('error', 'This proposal has already been reviewed.');
    }

    $walletId   = (int) $proposal['wallet_id'];
    $newStatus  = $decision === 'approve' ? 'approved' : 'changes_requested';

    if ($decision === 'approve') {
        // 1. Read the proposed amounts
        $stmt = $conn->prepare('SELECT category, proposed_amount FROM budget_proposal_items WHERE proposal_id = ?');
        $stmt->bind_param('i', $proposalId);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // 2. Copy them into budget_allocations (one row per wallet + category).
        //    If the category already exists, its allocated amount is replaced; "spent" is kept.
        $stmt = $conn->prepare(
            'INSERT INTO budget_allocations (wallet_id, category, allocated, spent, updated_at)
             VALUES (?, ?, ?, 0, NOW())
             ON DUPLICATE KEY UPDATE allocated = VALUES(allocated), updated_at = NOW()'
        );
        $approvedCategories = [];
        foreach ($items as $it) {
            if ((float) $it['proposed_amount'] <= 0) {
                continue;
            }
            $stmt->bind_param('iss', $walletId, $it['category'], $it['proposed_amount']);
            $stmt->execute();
            $approvedCategories[] = $it['category'];
        }
        $stmt->close();

        // 3. Categories from an older budget that are NOT in this one:
        //    remove them if nothing was spent, otherwise keep the history with 0 allocated.
        $stmt = $conn->prepare('SELECT id, category, spent FROM budget_allocations WHERE wallet_id = ?');
        $stmt->bind_param('i', $walletId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($existing as $row) {
            if (in_array($row['category'], $approvedCategories, true)) {
                continue;
            }
            $sql = (float) $row['spent'] > 0
                ? 'UPDATE budget_allocations SET allocated = 0, updated_at = NOW() WHERE id = ?'
                : 'DELETE FROM budget_allocations WHERE id = ?';
            $stmt = $conn->prepare($sql);
            $rowId = (int) $row['id'];
            $stmt->bind_param('i', $rowId);
            $stmt->execute();
            $stmt->close();
        }

        if ($fundAmount > 0 && $proposal['wallet_status'] !== 'active') {
            $conn->rollback();
            back('error', 'This wallet is frozen, so it can\'t be topped up. Approve with 0, or unfreeze the wallet first.');
        }
    }

    // 4. Record the decision
    $feedbackValue = $feedback !== '' ? $feedback : null;
    $stmt = $conn->prepare(
        'UPDATE budget_proposals
         SET status = ?, sponsor_feedback = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->bind_param('ssii', $newStatus, $feedbackValue, $sponsorId, $proposalId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('Review budget proposal failed: ' . $e->getMessage());
    back('error', 'Something went wrong. Nothing was changed. Please try again.');
}

if ($newStatus === 'changes_requested') {
    back('success', 'Sent back to ' . $proposal['student_name'] . ' with your feedback.');
}

// Approved. Now start the M-Pesa top-up, if one was requested.
if ($fundAmount > 0) {
    try {
        $paymentId = mpesa_start_funding(
            $conn, $sponsorId, $walletId, $fundAmount, $phone,
            'Top-up for approved budget #' . $proposalId, $proposalId
        );
    } catch (Throwable $e) {
        back('error', 'The budget from ' . $proposal['student_name'] . ' is approved, but the M-Pesa top-up could not start: '
            . $e->getMessage() . ' You can send the money from the dashboard instead.');
    }
    header('Location: ../Frontend/mpesa-wait.php?id=' . $paymentId);
    exit;
}

back('success', 'Approved the budget from ' . $proposal['student_name'] . '.');