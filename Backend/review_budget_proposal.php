<?php
// Backend/review_budget_proposal.php
// One of the student's sponsors reviews a budget proposal. A student can have several sponsors,
// and EVERY active sponsor must approve before the budget takes effect.
//
// decision = approve -> this sponsor's approval is recorded (proposal_reviews).
//                       When the last sponsor approves, the proposal becomes "approved" and its
//                       amounts are copied into budget_allocations.
//                       If this sponsor entered a top-up, an M-Pesa prompt is sent to their phone
//                       (up to what is left of their pledge); the wallet is credited only when
//                       Safaricom confirms (mpesa_callback.php).
// decision = changes -> the proposal goes back to the student ("changes_requested") with feedback.
//                       One sponsor asking for changes is enough.
//
// Everything that changes the budget happens in one database transaction.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sponsorship.php';
require_role('sponsor');

// M-Pesa is loaded only when this computer has an mpesa_config.php file.
// Without it, approving (with no top-up) and requesting changes still work.
define('MPESA_READY', is_file(__DIR__ . '/mpesa_config.php'));
if (MPESA_READY) {
    require_once __DIR__ . '/mpesa_funding.php';
}

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/sponsor-proposals.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the form to review a proposal.');
}

$sponsorId   = (int) $_SESSION['user_id'];
$sponsorName = $_SESSION['full_name'];
$proposalId  = (int) ($_POST['proposal_id'] ?? 0);
$decision    = $_POST['decision'] ?? '';
$feedback    = trim($_POST['feedback'] ?? '');
$fundRaw     = str_replace([',', ' '], '', trim($_POST['fund_amount'] ?? '0'));
$phoneIn     = $_POST['phone'] ?? '';

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
        if (!MPESA_READY) {
            back('error', 'M-Pesa is not set up on this computer. Enter 0 as the top-up to approve without sending money.');
        }
        $phone = mpesa_phone($phoneIn);
        if ($phone === null) {
            back('error', 'Enter a valid Safaricom number to pay the top-up from, for example 0712 345 678.');
        }
    }
}

$complete = false;   // did this approval complete the set?
try {
    $conn->begin_transaction();

    // The proposal must belong to a student this sponsor ACTIVELY sponsors. Lock it,
    // so two sponsors deciding at the same moment are handled one after the other.
    $stmt = $conn->prepare(
        "SELECT p.id, p.status, w.id AS wallet_id, w.status AS wallet_status, w.student_id,
                u.full_name AS student_name, sp.pledge
         FROM budget_proposals p
         JOIN wallets w ON w.id = p.wallet_id
         JOIN users u   ON u.id = w.student_id
         JOIN sponsorships sp ON sp.student_id = w.student_id AND sp.sponsor_id = ? AND sp.status = 'active'
         WHERE p.id = ?
         FOR UPDATE"
    );
    $stmt->bind_param('ii', $sponsorId, $proposalId);
    $stmt->execute();
    $proposal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$proposal) {
        $conn->rollback();
        back('error', 'That proposal was not found.');
    }
    if ($proposal['status'] !== 'pending') {
        $conn->rollback();
        back('error', 'This proposal has already been decided.');
    }

    $walletId  = (int) $proposal['wallet_id'];
    $studentId = (int) $proposal['student_id'];

    // Each sponsor decides once per proposal
    $stmt = $conn->prepare('SELECT decision FROM proposal_reviews WHERE proposal_id = ? AND sponsor_id = ?');
    $stmt->bind_param('ii', $proposalId, $sponsorId);
    $stmt->execute();
    $mine = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($mine) {
        $conn->rollback();
        back('error', 'You have already approved this proposal. It is waiting for the other sponsors.');
    }

    // A top-up must fit in what is left of this sponsor's pledge
    if ($fundAmount > 0) {
        if ($proposal['wallet_status'] !== 'active') {
            $conn->rollback();
            back('error', 'This wallet is frozen, so it can\'t be topped up. Approve with 0, or unfreeze the wallet first.');
        }
        $left = (float) $proposal['pledge'] - sponsor_pledge_used($conn, $sponsorId, $walletId);
        if ($fundAmount > $left) {
            $conn->rollback();
            back('error', 'The top-up is more than what is left of your pledge (KSh ' . number_format(max(0, $left))
                . '). Lower it, approve with 0, or raise your pledge on the dashboard first.');
        }
    }

    // 1. Record this sponsor's decision
    $reviewDecision = $decision === 'approve' ? 'approved' : 'changes';
    $feedbackValue  = $feedback !== '' ? $feedback : null;
    $stmt = $conn->prepare('INSERT INTO proposal_reviews (proposal_id, sponsor_id, decision, feedback) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('iiss', $proposalId, $sponsorId, $reviewDecision, $feedbackValue);
    $stmt->execute();
    $stmt->close();

    if ($decision === 'changes') {
        // 2a. One sponsor asking for changes sends it back to the student
        $stmt = $conn->prepare(
            "UPDATE budget_proposals
             SET status = 'changes_requested', sponsor_feedback = ?, reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ?"
        );
        $stmt->bind_param('sii', $feedbackValue, $sponsorId, $proposalId);
        $stmt->execute();
        $stmt->close();
    } else {
        // 2b. Approved by everyone now?
        $state    = proposal_approvals($conn, $proposalId, $studentId);
        $complete = $state['complete'];

        if ($complete) {
            // Copy the amounts into budget_allocations (one row per wallet + category).
            // If the category already exists, its allocated amount is replaced; "spent" is kept.
            $stmt = $conn->prepare('SELECT category, proposed_amount FROM budget_proposal_items WHERE proposal_id = ?');
            $stmt->bind_param('i', $proposalId);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

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

            // Categories from an older budget that are NOT in this one:
            // remove them if nothing was spent, otherwise keep the history with 0 allocated.
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

            $stmt = $conn->prepare(
                "UPDATE budget_proposals
                 SET status = 'approved', sponsor_feedback = ?, reviewed_by = ?, reviewed_at = NOW()
                 WHERE id = ?"
            );
            $stmt->bind_param('sii', $feedbackValue, $sponsorId, $proposalId);
            $stmt->execute();
            $stmt->close();
        }
    }

    $conn->commit();

} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('Review budget proposal failed: ' . $e->getMessage());
    back('error', 'Something went wrong. Nothing was changed. Please try again.');
}

// ---- Alerts ----
$studentName = $proposal['student_name'];
$note        = $feedback !== '' ? ' Note: "' . $feedback . '"' : '';
$topupText   = $fundAmount > 0 ? ' A top-up of KSh ' . number_format($fundAmount) . ' from ' . $sponsorName . ' is on the way via M-Pesa.' : '';

if ($decision === 'changes') {
    notify($conn, $studentId, 'budget_changes', 'Changes requested on your budget',
        $sponsorName . ' says: "' . $feedback . '"', 'student-budget.php');
    notify_sponsors($conn, $studentId, 'budget_changes', $sponsorName . ' asked ' . $studentName . ' to change their budget',
        '"' . $feedback . '"', 'sponsor-proposals.php', null, $sponsorId);
    back('success', 'Sent back to ' . $studentName . ' with your feedback.');
}

if ($complete) {
    notify($conn, $studentId, 'budget_approved', 'Your budget was approved',
        'All your sponsors approved your budget. ' . $sponsorName . ' gave the final approval.' . $topupText . $note, 'student-dashboard.php');
    notify_sponsors($conn, $studentId, 'budget_approved', $studentName . '\'s budget is approved',
        'Every sponsor has approved it. ' . $sponsorName . ' gave the final approval.', 'sponsor-proposals.php', null, $sponsorId);
    $doneMessage = 'Approved. Every sponsor has now approved ' . $studentName . '\'s budget, so it is active.';
} else {
    $waiting = array_column(array_filter($state['sponsors'], fn($s) => $s['decision'] !== 'approved'), 'full_name');
    notify($conn, $studentId, 'budget_partly_approved', $sponsorName . ' approved your budget',
        'Still waiting for ' . implode(', ', $waiting) . '.' . $topupText . $note, 'student-budget.php');
    $doneMessage = 'Your approval is recorded. ' . $studentName . '\'s budget is still waiting for ' . implode(', ', $waiting) . '.';
}

// Start this sponsor's M-Pesa top-up, if one was requested
if ($fundAmount > 0) {
    try {
        $paymentId = mpesa_start_funding(
            $conn, $sponsorId, $walletId, $fundAmount, $phone,
            'Top-up for budget #' . $proposalId, $proposalId
        );
    } catch (Throwable $e) {
        back('error', $doneMessage . ' But the M-Pesa top-up could not start: '
            . $e->getMessage() . ' You can send the money from the dashboard instead.');
    }
    header('Location: ../Frontend/mpesa-wait.php?id=' . $paymentId);
    exit;
}

back('success', $doneMessage);