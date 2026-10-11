<?php
// Backend/save_budget.php
// A student splits their wallet balance into category envelopes.
// Rule: money still set aside in categories (allocated - spent) can never exceed the wallet balance.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/categories.php';
require_role('student');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/student-budget.php');
    exit;
}

function to_cents(string $s): int
{
    return (int) round((float) $s * 100);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the form to save your budget.');
}

$studentId  = (int) $_SESSION['user_id'];
$categories = budget_categories();
$input      = $_POST['alloc'] ?? [];
if (!is_array($input)) {
    back('error', 'Invalid form data.');
}

// ---- Validate every amount (blank = 0) ----
$newCents = [];
foreach ($categories as $slug => $label) {
    $raw = str_replace(',', '', trim((string) ($input[$slug] ?? '')));
    if ($raw === '') { $raw = '0'; }
    if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $raw)) {
        back('error', $label . ': enter a valid amount, for example 2500 or 2500.50.');
    }
    $newCents[$slug] = to_cents($raw);
}

try {
    $conn->begin_transaction();

    // Lock this student's wallet
    $stmt = $conn->prepare('SELECT id, balance, status FROM wallets WHERE student_id = ? FOR UPDATE');
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
        back('error', 'Your wallet is frozen, so the budget cannot be changed.');
    }
    $walletId = (int) $wallet['id'];

    // Existing envelopes (to know how much is already spent in each)
    $spentCents = [];
    $stmt = $conn->prepare('SELECT category, spent FROM budget_allocations WHERE wallet_id = ? FOR UPDATE');
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $spentCents[$row['category']] = to_cents($row['spent']);
    }
    $stmt->close();

    // A category can't be set lower than what is already spent from it
    $committed = 0;
    foreach ($categories as $slug => $label) {
        $spent = $spentCents[$slug] ?? 0;
        if ($newCents[$slug] < $spent) {
            $conn->rollback();
            back('error', $label . ': you have already spent ' . number_format($spent / 100, 2) . ' from this category, so it cannot be set lower than that.');
        }
        $committed += $newCents[$slug] - $spent;
    }

    $balance = to_cents($wallet['balance']);
    if ($committed > $balance) {
        $conn->rollback();
        back('error', 'You allocated ' . number_format($committed / 100, 2) . ' but your wallet only has ' . number_format($balance / 100, 2) . ' available. Reduce some categories.');
    }

    // Save (insert or update each category)
    $stmt = $conn->prepare(
        'INSERT INTO budget_allocations (wallet_id, category, allocated) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE allocated = VALUES(allocated)'
    );
    foreach ($categories as $slug => $label) {
        $amount = number_format($newCents[$slug] / 100, 2, '.', '');
        $stmt->bind_param('iss', $walletId, $slug, $amount);
        $stmt->execute();
    }
    $stmt->close();

    $conn->commit();
} catch (mysqli_sql_exception $e) {
    $conn->rollback();
    error_log('Save budget failed: ' . $e->getMessage());
    back('error', 'Something went wrong. Your budget was not saved. Please try again.');
}

$left = $balance - $committed;
back('success', 'Budget saved. Unallocated: KES ' . number_format($left / 100, 2) . '.');
