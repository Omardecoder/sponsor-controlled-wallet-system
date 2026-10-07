<?php
// Backend/fund_wallet.php
// A sponsor adds money to a wallet they control.
// NOTE: this records the funding in the database only. Real money movement
// (M-Pesa, card, etc.) would be connected here later.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_role('sponsor');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/sponsor-dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the form to fund a wallet.');
}

$walletId = (int) ($_POST['wallet_id'] ?? 0);
$amountIn = str_replace(',', '', trim($_POST['amount'] ?? ''));
$note     = trim($_POST['note'] ?? '');
$sponsorId = (int) $_SESSION['user_id'];

// Amount must look like 500 or 500.50, and be above zero
if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amountIn) || (float) $amountIn <= 0) {
    back('error', 'Enter a valid amount greater than zero, for example 1500 or 1500.50.');
}
if ((float) $amountIn > 1000000) {
    back('error', 'The most you can add at one time is 1,000,000.');
}
if (mb_strlen($note) > 255) {
    back('error', 'The note must be 255 characters or fewer.');
}
$amount = number_format((float) $amountIn, 2, '.', '');
$description = $note !== '' ? $note : 'Sponsor funding';

try {
    // All steps succeed together, or none of them happen
    $conn->begin_transaction();

    // Lock this wallet row; it must belong to the logged-in sponsor
    $stmt = $conn->prepare('SELECT id, status FROM wallets WHERE id = ? AND sponsor_id = ? FOR UPDATE');
    $stmt->bind_param('ii', $walletId, $sponsorId);
    $stmt->execute();
    $wallet = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$wallet) {
        $conn->rollback();
        back('error', 'That wallet was not found.');
    }
    if ($wallet['status'] !== 'active') {
        $conn->rollback();
        back('error', 'This wallet is frozen and cannot be funded.');
    }

    // Add the money
    $stmt = $conn->prepare('UPDATE wallets SET balance = balance + ? WHERE id = ?');
    $stmt->bind_param('si', $amount, $walletId);
    $stmt->execute();
    $stmt->close();

    // Read the new balance
    $stmt = $conn->prepare('SELECT balance FROM wallets WHERE id = ?');
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $newBalance = $stmt->get_result()->fetch_assoc()['balance'];
    $stmt->close();

    // Record it in the history
    $stmt = $conn->prepare("INSERT INTO transactions (wallet_id, type, amount, balance_after, description, created_by) VALUES (?, 'funding', ?, ?, ?, ?)");
    $stmt->bind_param('isssi', $walletId, $amount, $newBalance, $description, $sponsorId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (mysqli_sql_exception $e) {
    $conn->rollback();
    error_log('Fund wallet failed: ' . $e->getMessage());
    back('error', 'Something went wrong. No money was added. Please try again.');
}

back('success', 'Added ' . number_format((float) $amount, 2) . ' to the wallet. New balance: ' . number_format((float) $newBalance, 2) . '.');
