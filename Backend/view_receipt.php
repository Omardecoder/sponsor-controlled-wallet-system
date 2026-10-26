<?php
// Backend/view_receipt.php?id=EXPENSE_ID
// Shows a receipt only to: the student who recorded it, that student's sponsor, or an admin.

session_start();
require_once __DIR__ . '/db.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role   = $_SESSION['role'] ?? '';
if ($userId === 0) {
    header('Location: ../Frontend/login.php');
    exit;
}

$expenseId = (int) ($_GET['id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT e.receipt_file, e.receipt_name, e.receipt_type, w.student_id,
            EXISTS (SELECT 1 FROM sponsorships sp
                     WHERE sp.student_id = w.student_id AND sp.sponsor_id = ? AND sp.status = 'active') AS is_sponsor
     FROM expenses e
     JOIN wallets w ON w.id = e.wallet_id
     WHERE e.id = ?"
);
$stmt->bind_param('ii', $userId, $expenseId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$allowed = $row && (
    ($role === 'student' && (int) $row['student_id'] === $userId) ||
    ($role === 'sponsor' && (int) $row['is_sponsor'] === 1) ||   // any of the student's active sponsors
    $role === 'admin'
);

if (!$allowed || !$row['receipt_file']) {
    http_response_code(404);
    exit('Receipt not found.');
}

// Only a plain stored file name is accepted (no folders)
if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|pdf)$/', $row['receipt_file'])) {
    http_response_code(404);
    exit('Receipt not found.');
}
$path = __DIR__ . '/../Uploads/receipts/' . $row['receipt_file'];
if (!is_file($path)) {
    http_response_code(404);
    exit('The receipt file is missing.');
}

$downloadName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $row['receipt_name'] ?: $row['receipt_file']);

header('Content-Type: ' . $row['receipt_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;