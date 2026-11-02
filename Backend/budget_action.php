<?php
// Backend/budget_action.php
// The student decides what happens to a budget that is waiting for funding:
//   action = accept_partial -> start with the funding committed so far. The student sets the
//                              amount for each line (the total can't be more than what is committed);
//                              each funder's commitment shrinks in the same proportion if needed,
//                              and the budget becomes active.
//   action = withdraw       -> cancel the proposal (e.g. to submit a different budget).

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/categories.php';
require_once __DIR__ . '/sponsorship.php';
require_role('student');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/student-budget.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the buttons on your budget page.');
}

$studentId   = (int) $_SESSION['user_id'];
$studentName = $_SESSION['full_name'];
$action      = $_POST['action'] ?? '';
$proposalId  = (int) ($_POST['proposal_id'] ?? 0);

if (!in_array($action, ['accept_partial', 'withdraw'], true)) {
    back('error', 'Unknown action.');
}

try {
    $conn->begin_transaction();

    // The student's own proposal, still waiting for funding. Locked so funders can't commit at the same moment.
    $stmt = $conn->prepare(
        "SELECT p.id, p.status, w.id AS wallet_id, w.status AS wallet_status
         FROM budget_proposals p JOIN wallets w ON w.id = p.wallet_id
         WHERE p.id = ? AND w.student_id = ?
         FOR UPDATE"
    );
    $stmt->bind_param('ii', $proposalId, $studentId);
    $stmt->execute();
    $proposal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$proposal || $proposal['status'] !== 'pending') {
        $conn->rollback();
        back('error', 'That budget is no longer waiting for funding.');
    }
    $walletId = (int) $proposal['wallet_id'];
    $funding  = proposal_funding($conn, $proposalId, $studentId);

    // ======================= Withdraw =======================
    if ($action === 'withdraw') {
        $stmt = $conn->prepare("UPDATE budget_proposals SET status = 'withdrawn', reviewed_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $proposalId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();

        notify_sponsors($conn, $studentId, 'budget_withdrawn', $studentName . ' withdrew a budget proposal',
            'The KSh ' . number_format($funding['total']) . ' budget was cancelled by the student. Any commitment you made to it no longer applies.',
            'sponsor-proposals.php');
        back('success', 'Your budget proposal was withdrawn. You can now create a new one.');
    }

    // ======================= Accept partial funding =======================
    if ($proposal['wallet_status'] !== 'active') {
        $conn->rollback();
        back('error', 'Your wallet is frozen, so a budget can\'t start now.');
    }
    if ($funding['committed'] <= 0) {
        $conn->rollback();
        back('error', 'No funder has committed to this budget yet, so there is nothing to start with.');
    }

    // Read the new line amounts (only categories that were in the proposal)
    $stmt = $conn->prepare('SELECT category, proposed_amount FROM budget_proposal_items WHERE proposal_id = ?');
    $stmt->bind_param('i', $proposalId);
    $stmt->execute();
    $original = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'proposed_amount', 'category');
    $stmt->close();

    // What has already been spent in each line (a line can't be set below that)
    $stmt = $conn->prepare('SELECT category, spent FROM budget_allocations WHERE wallet_id = ?');
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $spentBy = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'spent', 'category');
    $stmt->close();

    $input = $_POST['alloc'] ?? [];
    if (!is_array($input)) {
        $input = [];
    }
    $newCents   = [];
    $totalCents = 0;
    foreach ($original as $slug => $was) {
        $raw = str_replace(',', '', trim((string) ($input[$slug] ?? '0')));
        if ($raw === '') {
            $raw = '0';
        }
        if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $raw)) {
            $conn->rollback();
            back('error', 'Enter a valid amount for ' . (budget_categories()[$slug] ?? $slug) . '.');
        }
        $cents = (int) round((float) $raw * 100);
        if ($cents > (int) round((float) $was * 100)) {
            $conn->rollback();
            back('error', (budget_categories()[$slug] ?? $slug) . ' can\'t be more than in your original budget.');
        }
        $spentCents = (int) round((float) ($spentBy[$slug] ?? 0) * 100);
        if ($cents < $spentCents) {
            $conn->rollback();
            back('error', (budget_categories()[$slug] ?? $slug) . ' can\'t be less than the KSh ' . number_format($spentCents / 100, 2) . ' you have already spent in it.');
        }
        $newCents[$slug] = $cents;
        $totalCents     += $cents;
    }
    $committedCents = (int) round($funding['committed'] * 100);
    if ($totalCents <= 0) {
        $conn->rollback();
        back('error', 'Keep an amount in at least one line.');
    }
    if ($totalCents > $committedCents) {
        $conn->rollback();
        back('error', 'Your lines add up to KSh ' . number_format($totalCents / 100, 2) . ', but only KSh '
            . number_format($committedCents / 100, 2) . ' is funded. Lower some lines.');
    }

    // Save the reduced lines
    $stmt = $conn->prepare('UPDATE budget_proposal_items SET proposed_amount = ? WHERE proposal_id = ? AND category = ?');
    foreach ($newCents as $slug => $cents) {
        $amount = number_format($cents / 100, 2, '.', '');
        $stmt->bind_param('sis', $amount, $proposalId, $slug);
        $stmt->execute();
    }
    $stmt->close();

    // If the student kept less than what is funded, every funder's commitment shrinks in proportion
    if ($totalCents < $committedCents) {
        $factor = $totalCents / $committedCents;
        $stmt = $conn->prepare(
            "UPDATE proposal_reviews SET commit_amount = ROUND(commit_amount * ?, 2) WHERE proposal_id = ? AND decision = 'approved'"
        );
        $stmt->bind_param('di', $factor, $proposalId);
        $stmt->execute();
        $stmt->close();
    }

    $note = 'Started with partial funding: KSh ' . number_format($totalCents / 100) . ' of the KSh '
          . number_format($funding['total']) . ' requested (' . $funding['percent'] . '% was funded).';
    activate_budget($conn, $proposalId, $walletId, null, $note);

    $conn->commit();
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('Budget action failed: ' . $e->getMessage());
    back('error', 'Something went wrong. Nothing was changed. Please try again.');
}

notify_sponsors($conn, $studentId, 'budget_approved', $studentName . ' started their budget with partial funding',
    $note . ' Your part may have been adjusted; see Budget Proposals.', 'sponsor-proposals.php');
back('success', 'Your budget is active with KSh ' . number_format($totalCents / 100, 2) . '. Record your spending against it as usual.');