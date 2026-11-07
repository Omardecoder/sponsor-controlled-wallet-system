<?php
// Backend/submit_budget_proposal.php
// Saves a student's budget proposal and sends it to all of the student's funders (active sponsors).
// Each funder then commits a share of it (a % or an amount); when the commitments reach 100% the
// budget becomes active. If it stays below 100%, the student decides what to do (budget_action.php).
// Only one proposal can wait for funding at a time.

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
    back('error', 'Please use the budget form to submit a proposal.');
}

$studentId  = (int) $_SESSION['user_id'];
// This student's categories: the standard ones + their own (e.g. "Entertainment")
$stmt = $conn->prepare('SELECT id FROM wallets WHERE student_id = ? LIMIT 1');
$stmt->bind_param('i', $studentId);
$stmt->execute();
$myWallet = $stmt->get_result()->fetch_assoc();
$stmt->close();
$categories = budget_categories($myWallet ? (int) $myWallet['id'] : 0);
$input      = $_POST['alloc'] ?? [];

if (!is_array($input)) {
    back('error', 'Invalid budget form data.');
}

// Validate amounts and use integer cents for calculations.
$items = [];
$totalCents = 0;

foreach ($categories as $slug => $label) {
    $raw = str_replace(',', '', trim((string) ($input[$slug] ?? '0')));
    if ($raw === '') {
        $raw = '0';
    }
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $raw)) {
        back('error', 'Enter a valid amount for ' . $label . '.');
    }
    $amountCents = (int) round((float) $raw * 100);
    if ($amountCents > 0) {          // categories left at 0 are skipped
        $items[$slug] = $amountCents;
        $totalCents += $amountCents;
    }
}

if ($totalCents <= 0) {
    back('error', 'Enter an amount for at least one category.');
}

// A line that is in the approved budget can't be set below what has already been spent in it
if ($myWallet) {
    $wid  = (int) $myWallet['id'];
    $stmt = $conn->prepare('SELECT category, allocated, spent FROM budget_allocations WHERE wallet_id = ? AND allocated > 0');
    $stmt->bind_param('i', $wid);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $line) {
        $spentCents = (int) round((float) $line['spent'] * 100);
        if ($spentCents > 0 && ($items[$line['category']] ?? 0) < $spentCents) {
            back('error', ($categories[$line['category']] ?? 'A category') . ' can\'t be less than the KSh '
                . number_format($spentCents / 100, 2) . ' you have already spent in it.');
        }
    }
    $stmt->close();
}

// Someone has to be able to fund it
// (or the student is listed, so well-wishers can find the budget on the Students page)
$listed = is_listed($conn, $studentId);
if (!student_sponsors($conn, $studentId) && !$listed) {
    back('error', 'You have no active sponsor yet. Accept a sponsor\'s request, apply to a program, or list yourself for well-wishers on My Profile first.');
}

try {
    $conn->begin_transaction();

    // The student's wallet: this is the link to their sponsor.
    $stmt = $conn->prepare('SELECT id, status FROM wallets WHERE student_id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $wallet = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$wallet) {
        $conn->rollback();
        back('error', 'You are not linked to a sponsor yet. Ask your sponsor to add you using the email or phone number you registered with.');
    }
    if ($wallet['status'] !== 'active') {
        $conn->rollback();
        back('error', 'Your wallet is frozen. Contact your sponsor.');
    }

    $walletId = (int) $wallet['id'];

    // Only one proposal can wait for the sponsor at a time.
    $stmt = $conn->prepare("SELECT id FROM budget_proposals WHERE wallet_id = ? AND status = 'pending' LIMIT 1");
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $alreadyPending = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($alreadyPending) {
        $conn->rollback();
        back('error', 'You already have a budget waiting for funding. Wait for it, accept the funding you have, or withdraw it first.');
    }

    // Create the proposal as pending sponsor review.
    $stmt = $conn->prepare("INSERT INTO budget_proposals (wallet_id, status) VALUES (?, 'pending')");
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $proposalId = (int) $conn->insert_id;
    $stmt->close();

    // Save each proposed category amount.
    $stmt = $conn->prepare('INSERT INTO budget_proposal_items (proposal_id, category, proposed_amount) VALUES (?, ?, ?)');
    foreach ($items as $slug => $amountCents) {
        $amount = number_format($amountCents / 100, 2, '.', '');
        $stmt->bind_param('iss', $proposalId, $slug, $amount);
        $stmt->execute();
    }
    $stmt->close();

    $conn->commit();

} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('Submit budget proposal failed: ' . $e->getMessage());
    back('error', 'The proposal could not be saved. Please try again.');
}

// ---- Alert every active sponsor that a budget is waiting ----
$sponsorCount = count(student_sponsors($conn, $studentId));
notify_sponsors(
    $conn, $studentId, 'proposal_submitted',
    $_SESSION['full_name'] . ' submitted a budget',
    'KSh ' . number_format($totalCents / 100) . ' across ' . count($items) . ' categories. Choose how much of it you will fund'
        . ($sponsorCount > 1 ? ' (your ' . $sponsorCount . ' funders share it).' : '.'),
    'sponsor-proposals.php'
);
if ($sponsorCount === 0) {
    back('success', 'Your budget is published to well-wishers. It becomes active when it is 100% funded.');
}
back('success', 'Your budget was sent to your sponsor' . ($sponsorCount > 1 ? 's' : '') . ($listed ? ' and published to well-wishers' : '')
    . '. It becomes active when it is 100% funded.');