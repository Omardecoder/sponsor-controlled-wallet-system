<?php
// Backend/admin_wallet_status.php
// An admin freezes or unfreezes a student's wallet.
// A frozen wallet can't be funded, spent from, or used for a new budget (those pages already check this).
// The student and all of their active sponsors get an alert.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/sponsorship.php';
require_role('admin');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/admin-dashboard.php#wallets');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the buttons to change a wallet.');
}

$walletId = (int) ($_POST['wallet_id'] ?? 0);
$action   = $_POST['action'] ?? '';
$reason   = trim($_POST['reason'] ?? '');

if (!in_array($action, ['freeze', 'unfreeze'], true)) {
    back('error', 'Unknown action.');
}
if ($action === 'freeze' && $reason === '') {
    back('error', 'Give a short reason for freezing. The student and sponsor will see it.');
}
if (mb_strlen($reason) > 200) {
    back('error', 'The reason must be 200 characters or fewer.');
}

$stmt = $conn->prepare(
    'SELECT w.id, w.status, w.student_id, u.full_name AS student_name
     FROM wallets w JOIN users u ON u.id = w.student_id WHERE w.id = ?'
);
$stmt->bind_param('i', $walletId);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$wallet) {
    back('error', 'That wallet was not found.');
}

$newStatus = $action === 'freeze' ? 'frozen' : 'active';
if ($wallet['status'] === $newStatus) {
    back('error', $wallet['student_name'] . '\'s wallet is already ' . $newStatus . '.');
}

$stmt = $conn->prepare('UPDATE wallets SET status = ? WHERE id = ?');
$stmt->bind_param('si', $newStatus, $walletId);
$stmt->execute();
$stmt->close();

// Tell the student and every active sponsor
if ($newStatus === 'frozen') {
    $body = 'Reason: ' . $reason . ' Funding, spending and new budgets are paused until it is unfrozen.';
    notify($conn, (int) $wallet['student_id'], 'wallet_frozen', 'Your wallet has been frozen', $body, 'student-dashboard.php');
    notify_sponsors($conn, (int) $wallet['student_id'], 'wallet_frozen', $wallet['student_name'] . '\'s wallet has been frozen', $body, 'sponsor-dashboard.php#students');
} else {
    $body = 'Funding, spending and budgets work normally again.';
    notify($conn, (int) $wallet['student_id'], 'wallet_unfrozen', 'Your wallet is active again', $body, 'student-dashboard.php');
    notify_sponsors($conn, (int) $wallet['student_id'], 'wallet_unfrozen', $wallet['student_name'] . '\'s wallet is active again', $body, 'sponsor-dashboard.php#students');
}

back('success', $wallet['student_name'] . '\'s wallet is now ' . $newStatus . '.');