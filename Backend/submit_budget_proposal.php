
<?php
// Backend/submit_budget_proposal.php
// Saves a student's budget proposal for sponsor review.

session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/categories.php';

require_role('student');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];

    header('Location: ../Frontend/student-budget.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Please use the budget form to submit a proposal.');
}

$studentId = (int) $_SESSION['user_id'];
$categories = budget_categories();
$input = $_POST['alloc'] ?? [];

if (!is_array($input)) {
    back('error', 'Invalid budget form data.');
}

// Validate amounts and use integer cents for calculations.
$items = [];
$totalCents = 0;

foreach ($categories as $slug => $label) {
    $raw = str_replace(
        ',',
        '',
        trim((string) ($input[$slug] ?? '0'))
    );

    if ($raw === '') {
        $raw = '0';
    }

    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $raw)) {
        back('error', 'Enter a valid amount for ' . $label . '.');
    }

    $amountCents = (int) round((float) $raw * 100);

    $items[$slug] = $amountCents;
    $totalCents += $amountCents;
}

if ($totalCents <= 0) {
    back('error', 'Enter an amount for at least one category.');
}

try {
    $conn->begin_transaction();

    // Confirm that the logged-in student has an active wallet.
    $stmt = $conn->prepare(
        'SELECT id, balance, status
         FROM wallets
         WHERE student_id = ?
         LIMIT 1
         FOR UPDATE'
    );

    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $wallet = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$wallet) {
        $conn->rollback();
        back('error', 'You do not have a wallet yet. Ask your sponsor to create one.');
    }

    if ($wallet['status'] !== 'active') {
        $conn->rollback();
        back('error', 'Your wallet is frozen. Contact your sponsor.');
    }

    if ($totalCents > (int) round((float) $wallet['balance'] * 100)) {
        $conn->rollback();
        back('error', 'Your proposed budget cannot exceed your current wallet balance.');
    }

    // Create the proposal as pending sponsor review.
    $walletId = (int) $wallet['id'];

    $stmt = $conn->prepare(
        "INSERT INTO budget_proposals (wallet_id, status)
         VALUES (?, 'pending')"
    );

    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $proposalId = (int) $conn->insert_id;
    $stmt->close();

    // Save each proposed category amount.
    $stmt = $conn->prepare(
        'INSERT INTO budget_proposal_items
            (proposal_id, category, proposed_amount)
         VALUES (?, ?, ?)'
    );

    foreach ($items as $slug => $amountCents) {
        $amount = number_format($amountCents / 100, 2, '.', '');

        $stmt->bind_param('iss', $proposalId, $slug, $amount);
        $stmt->execute();
    }

    $stmt->close();

    $conn->commit();

} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        // The transaction may already have been rolled back.
    }

    error_log('Submit budget proposal failed: ' . $e->getMessage());

    back('error', 'The proposal could not be saved. Please try again.');
}

back('success', 'Your budget proposal has been submitted for sponsor review.');
