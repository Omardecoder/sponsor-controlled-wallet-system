<?php
// Backend/review_budget_proposal.php
// A funder (sponsor) responds to a student's budget proposal. A student can have several funders.
//
// decision = commit  -> the funder commits a share of the budget: a percentage (e.g. 50%) or a
//                       fixed amount (e.g. KSh 50,000). Saved in proposal_reviews.commit_amount.
//                       Commitments can never add up to more than the budget.
//                       When the commitments reach 100%, the budget becomes active
//                       (its amounts are copied into budget_allocations).
//                       Optionally the funder pays their share now through M-Pesa; the wallet is
//                       credited only when Safaricom confirms (mpesa_callback.php).
// decision = changes -> the proposal goes back to the student ("changes_requested") with feedback.
//                       One funder asking for changes is enough.
// If the budget stays below 100%, the student decides what to do (see budget_action.php).

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sponsorship.php';
require_role('sponsor');

// M-Pesa is loaded only when this computer has an mpesa_config.php file.
// Without it, committing (without paying now) and requesting changes still work.
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
    back('error', 'Use the form to respond to a budget.');
}

$sponsorId   = (int) $_SESSION['user_id'];
$sponsorName = $_SESSION['full_name'];
$proposalId  = (int) ($_POST['proposal_id'] ?? 0);
$decision    = $_POST['decision'] ?? '';
$feedback    = trim($_POST['feedback'] ?? '');
$commitType  = ($_POST['commit_type'] ?? 'percent') === 'amount' ? 'amount' : 'percent';
$commitRaw   = str_replace([',', ' ', '%'], '', trim($_POST['commit_value'] ?? ''));
$payNow      = ($_POST['pay_now'] ?? '') === '1';
$phoneIn     = $_POST['phone'] ?? '';

if (!in_array($decision, ['commit', 'changes'], true)) {
    back('error', 'Choose Fund this budget or Request changes.');
}
if ($decision === 'changes' && $feedback === '') {
    back('error', 'Tell the student what to change before sending it back.');
}
if (mb_strlen($feedback) > 1000) {
    back('error', 'Feedback must be 1000 characters or fewer.');
}
if ($decision === 'commit') {
    $pattern = $commitType === 'percent' ? '/^\d{1,3}(\.\d{1,2})?$/' : '/^\d{1,9}(\.\d{1,2})?$/';
    if (!preg_match($pattern, $commitRaw) || (float) $commitRaw <= 0) {
        back('error', $commitType === 'percent'
            ? 'Enter the share you will fund as a percentage, for example 50.'
            : 'Enter the amount you will fund in shillings, for example 50000.');
    }
    if ($commitType === 'percent' && (float) $commitRaw > 100) {
        back('error', 'A share cannot be more than 100%.');
    }
}

$phone = null;
if ($decision === 'commit' && $payNow) {
    if (!MPESA_READY) {
        back('error', 'M-Pesa is not set up on this computer. Untick "Pay my share now" and send the money later.');
    }
    $phone = mpesa_phone($phoneIn);
    if ($phone === null) {
        back('error', 'Enter a valid Safaricom number to pay from, for example 0712 345 678.');
    }
}

$complete = false;
$myAmount = 0.0;
try {
    $conn->begin_transaction();

    // The proposal must belong to a student this sponsor ACTIVELY sponsors. Lock it, so two funders
    // committing at the same moment are handled one after the other (no going over 100%).
    $stmt = $conn->prepare(
        "SELECT p.id, p.status, w.id AS wallet_id, w.status AS wallet_status, w.student_id,
                u.full_name AS student_name, sp.pledge AS limit_amount
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
        back('error', 'This budget has already been decided.');
    }
    $walletId  = (int) $proposal['wallet_id'];
    $studentId = (int) $proposal['student_id'];

    // Each funder responds once per proposal
    $stmt = $conn->prepare('SELECT decision FROM proposal_reviews WHERE proposal_id = ? AND sponsor_id = ?');
    $stmt->bind_param('ii', $proposalId, $sponsorId);
    $stmt->execute();
    $mine = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($mine) {
        $conn->rollback();
        back('error', 'You have already responded to this budget.');
    }

    $funding = proposal_funding($conn, $proposalId, $studentId);

    if ($decision === 'commit') {
        // Turn the % or amount into shillings, and check it fits
        $myAmount = $commitType === 'percent'
            ? round($funding['total'] * (float) $commitRaw / 100, 2)
            : round((float) $commitRaw, 2);
        if ($myAmount > $funding['remaining'] + 0.005) {
            $conn->rollback();
            back('error', 'Only KSh ' . number_format($funding['remaining'], 2) . ' ('
                . ($funding['total'] > 0 ? round($funding['remaining'] / $funding['total'] * 100, 1) : 0)
                . '%) of this budget is still uncovered. Commit that much or less.');
        }
        $myAmount = min($myAmount, $funding['remaining']);
        $limit = (float) $proposal['limit_amount'];   // organizations: the program amount
        if ($limit > 0 && $myAmount > $limit + 0.005) {
            $conn->rollback();
            back('error', 'Your limit for ' . $proposal['student_name'] . ' is KSh ' . number_format($limit) . ' per budget. Commit that much or less.');
        }
        if ($payNow && $proposal['wallet_status'] !== 'active') {
            $conn->rollback();
            back('error', 'This wallet is frozen, so it can\'t be funded now. Untick "Pay my share now".');
        }
        if ($payNow && ceil($myAmount) > 250000) {
            $conn->rollback();
            back('error', 'M-Pesa allows at most KSh 250,000 per payment. Untick "Pay my share now" and send it in parts from the dashboard.');
        }

        $feedbackValue = $feedback !== '' ? $feedback : null;
        $amountStr = number_format($myAmount, 2, '.', '');
        $stmt = $conn->prepare(
            "INSERT INTO proposal_reviews (proposal_id, sponsor_id, decision, commit_amount, feedback) VALUES (?, ?, 'approved', ?, ?)"
        );
        $stmt->bind_param('iiss', $proposalId, $sponsorId, $amountStr, $feedbackValue);
        $stmt->execute();
        $stmt->close();

        // Fully covered now? Then the budget becomes active.
        $funding  = proposal_funding($conn, $proposalId, $studentId);
        $complete = $funding['complete'];
        if ($complete) {
            activate_budget($conn, $proposalId, $walletId, $sponsorId, $feedbackValue);
        }
    } else {
        // One funder asking for changes sends it back to the student
        $feedbackValue = $feedback;
        $stmt = $conn->prepare("INSERT INTO proposal_reviews (proposal_id, sponsor_id, decision, feedback) VALUES (?, ?, 'changes', ?)");
        $stmt->bind_param('iis', $proposalId, $sponsorId, $feedbackValue);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare(
            "UPDATE budget_proposals SET status = 'changes_requested', sponsor_feedback = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?"
        );
        $stmt->bind_param('sii', $feedbackValue, $sponsorId, $proposalId);
        $stmt->execute();
        $stmt->close();
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

if ($decision === 'changes') {
    notify($conn, $studentId, 'budget_changes', 'Changes requested on your budget',
        $sponsorName . ' says: "' . $feedback . '"', 'student-budget.php');
    notify_sponsors($conn, $studentId, 'budget_changes', $sponsorName . ' asked ' . $studentName . ' to change their budget',
        '"' . $feedback . '"', 'sponsor-proposals.php', null, $sponsorId);
    back('success', 'Sent back to ' . $studentName . ' with your feedback.');
}

$share = $funding['total'] > 0 ? round($myAmount / $funding['total'] * 100, 1) : 0;
$mine  = 'KSh ' . number_format($myAmount, 2) . ' (' . $share . '%)';
if ($complete) {
    notify($conn, $studentId, 'budget_approved', 'Your budget is fully funded',
        $sponsorName . ' committed ' . $mine . ', so your budget is now 100% covered and active.' . $note, 'student-dashboard.php');
    notify_sponsors($conn, $studentId, 'budget_approved', $studentName . '\'s budget is fully funded',
        $sponsorName . ' committed the last ' . $mine . '. The budget is active.', 'sponsor-proposals.php', null, $sponsorId);
    $doneMessage = 'You committed ' . $mine . '. ' . $studentName . '\'s budget is now fully funded and active.';
} else {
    notify($conn, $studentId, 'budget_partly_approved', $sponsorName . ' will fund ' . $mine . ' of your budget',
        'Your budget is now ' . $funding['percent'] . '% funded. KSh ' . number_format($funding['remaining'], 2)
            . ' is still uncovered. You can wait for your other sponsors or accept the funding you have.' . $note,
        'student-budget.php');
    $doneMessage = 'You committed ' . $mine . '. ' . $studentName . '\'s budget is ' . $funding['percent'] . '% funded so far.';
}

// Pay the share now through M-Pesa, if asked
if ($payNow) {
    try {
        $paymentId = mpesa_start_funding(
            $conn, $sponsorId, $walletId, (int) ceil($myAmount), $phone,
            'Share of budget #' . $proposalId, $proposalId
        );
    } catch (Throwable $e) {
        back('error', $doneMessage . ' But the M-Pesa payment could not start: '
            . $e->getMessage() . ' You can send the money from the dashboard instead.');
    }
    header('Location: ../Frontend/mpesa-wait.php?id=' . $paymentId);
    exit;
}

back('success', $doneMessage);
