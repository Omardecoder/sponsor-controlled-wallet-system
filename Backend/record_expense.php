<?php
// Backend/record_expense.php
// A student records an expense against an approved budget line, with an optional receipt.
//
// In one database transaction it:
//   1. takes the amount off the wallet balance
//   2. adds a 'spending' row to transactions
//   3. adds the amount to that category's "spent" in budget_allocations
//   4. saves the expense (and the receipt file name) in expenses
// The expense is refused if it is more than what is left in the category or in the wallet.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/categories.php';
require_role('student');

const RECEIPT_MAX_BYTES = 5 * 1024 * 1024;   // 5 MB
const RECEIPT_TYPES = [                       // real file type => extension we save with
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'application/pdf' => 'pdf',
];

function back(string $type, string $message, array $old = []): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    if ($old) {
        $_SESSION['expense_old'] = $old;      // keep what the student typed after an error
    }
    header('Location: ../Frontend/record-spending.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the form to record an expense.');
}

$studentId   = (int) $_SESSION['user_id'];
$description = trim($_POST['description'] ?? '');
$amountIn    = str_replace([',', ' '], '', trim($_POST['amount'] ?? ''));
$category    = trim($_POST['category'] ?? '');
$dateIn      = trim($_POST['expense_date'] ?? '');

$old = ['description' => $description, 'amount' => $amountIn, 'category' => $category, 'expense_date' => $dateIn];

// ---------- Check the form ----------
if ($description === '' || mb_strlen($description) > 255) {
    back('error', 'Describe the expense (up to 255 characters), for example "Lunch".', $old);
}
if (!preg_match('/^\d{1,9}(\.\d{1,2})?$/', $amountIn) || (float) $amountIn <= 0) {
    back('error', 'Enter a valid amount greater than zero, for example 350 or 350.50.', $old);
}
if (!array_key_exists($category, budget_categories())) {
    back('error', 'Choose a budget line.', $old);
}
$date = DateTime::createFromFormat('!Y-m-d', $dateIn);
if (!$date || $date->format('Y-m-d') !== $dateIn) {
    back('error', 'Choose the date of the expense.', $old);
}
$today = new DateTime('today');
if ($date > $today) {
    back('error', 'The date cannot be in the future.', $old);
}
if ($date < (clone $today)->modify('-1 year')) {
    back('error', 'The date cannot be more than a year ago.', $old);
}

$amountCents = (int) round((float) $amountIn * 100);
$amount      = number_format($amountCents / 100, 2, '.', '');
$expenseDate = $date->format('Y-m-d');

// ---------- Check the receipt (optional) ----------
$receipt = null;   // ['tmp' => ..., 'ext' => ..., 'type' => ..., 'name' => ...]
$file = $_FILES['receipt'] ?? null;
if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || $file['size'] > RECEIPT_MAX_BYTES) {
        back('error', 'The receipt is too large. The limit is 5 MB.', $old);
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        back('error', 'The receipt could not be uploaded. Please try again.', $old);
    }
    // Check the real content, not just the file name
    $realType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(RECEIPT_TYPES[$realType])) {
        back('error', 'The receipt must be a JPG, PNG or PDF file.', $old);
    }
    $receipt = [
        'tmp'  => $file['tmp_name'],
        'ext'  => RECEIPT_TYPES[$realType],
        'type' => $realType,
        'name' => mb_substr(basename($file['name']), 0, 255),
    ];
}

// Receipts are stored outside the public pages and served only through view_receipt.php
$receiptDir = __DIR__ . '/../Uploads/receipts';
$savedPath  = null;

try {
    $conn->begin_transaction();

    // The student's wallet (locked so two expenses can't spend the same money)
    $stmt = $conn->prepare('SELECT id, balance, status FROM wallets WHERE student_id = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $wallet = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$wallet) {
        $conn->rollback();
        back('error', 'You are not linked to a sponsor yet.');
    }
    if ($wallet['status'] !== 'active') {
        $conn->rollback();
        back('error', 'Your wallet is frozen, so you cannot record spending. Contact your sponsor.', $old);
    }
    $walletId = (int) $wallet['id'];

    if ((int) round((float) $wallet['balance'] * 100) < $amountCents) {
        $conn->rollback();
        back('error', 'Your wallet balance (KSh ' . number_format((float) $wallet['balance'], 2) . ') is not enough for this expense.', $old);
    }

    // The approved budget line (locked too)
    $stmt = $conn->prepare('SELECT id, allocated, spent FROM budget_allocations WHERE wallet_id = ? AND category = ? FOR UPDATE');
    $stmt->bind_param('is', $walletId, $category);
    $stmt->execute();
    $line = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$line || (float) $line['allocated'] <= 0) {
        $conn->rollback();
        back('error', 'This budget line is not in your approved budget.', $old);
    }
    $leftCents = (int) round(((float) $line['allocated'] - (float) $line['spent']) * 100);
    if ($amountCents > $leftCents) {
        $conn->rollback();
        back('error', 'Only KSh ' . number_format(max(0, $leftCents) / 100, 2) . ' is left in ' . budget_categories()[$category]
            . '. Ask your sponsor to approve a bigger budget if you need more.', $old);
    }

    // Save the receipt file (inside the transaction so we can undo on failure)
    $storedName = null;
    if ($receipt) {
        if (!is_dir($receiptDir) && !mkdir($receiptDir, 0775, true)) {
            throw new RuntimeException('Cannot create the receipts folder');
        }
        // Block direct web access to the folder (Apache)
        $htaccess = $receiptDir . '/../.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Require all denied\n");
        }
        $storedName = bin2hex(random_bytes(16)) . '.' . $receipt['ext'];
        $savedPath  = $receiptDir . '/' . $storedName;
        if (!move_uploaded_file($receipt['tmp'], $savedPath)) {
            $savedPath = null;
            throw new RuntimeException('Cannot save the receipt file');
        }
    }

    // 1. Take the money off the wallet
    $stmt = $conn->prepare('UPDATE wallets SET balance = balance - ? WHERE id = ?');
    $stmt->bind_param('si', $amount, $walletId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare('SELECT balance FROM wallets WHERE id = ?');
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $newBalance = $stmt->get_result()->fetch_assoc()['balance'];
    $stmt->close();

    // 2. The money history
    $txDescription = mb_substr(budget_categories()[$category] . ': ' . $description, 0, 255);
    $stmt = $conn->prepare(
        "INSERT INTO transactions (wallet_id, type, amount, balance_after, description, created_by)
         VALUES (?, 'spending', ?, ?, ?, ?)"
    );
    $stmt->bind_param('isssi', $walletId, $amount, $newBalance, $txDescription, $studentId);
    $stmt->execute();
    $transactionId = (int) $conn->insert_id;
    $stmt->close();

    // 3. Budget line progress
    $lineId = (int) $line['id'];
    $stmt = $conn->prepare('UPDATE budget_allocations SET spent = spent + ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('si', $amount, $lineId);
    $stmt->execute();
    $stmt->close();

    // 4. The expense itself
    $receiptName = $receipt['name'] ?? null;
    $receiptType = $receipt['type'] ?? null;
    $stmt = $conn->prepare(
        'INSERT INTO expenses (wallet_id, transaction_id, category, description, amount, expense_date, receipt_file, receipt_name, receipt_type)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iisssssss', $walletId, $transactionId, $category, $description, $amount, $expenseDate, $storedName, $receiptName, $receiptType);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    if ($savedPath && is_file($savedPath)) {
        unlink($savedPath);   // don't keep a receipt for an expense that wasn't saved
    }
    error_log('Record expense failed: ' . $e->getMessage());
    back('error', 'Something went wrong. The expense was not recorded. Please try again.', $old);
}

// ---- Alerts: budget line limits and missing receipts (to the student and EVERY active sponsor) ----
require_once __DIR__ . '/sponsorship.php';
$who = ['full_name' => $_SESSION['full_name']];

$lineName    = budget_categories()[$category];
$allocated   = (float) $line['allocated'];
$spentBefore = (float) $line['spent'];
$spentAfter  = $spentBefore + (float) $amount;
$usedText    = 'KSh ' . number_format($spentAfter) . ' of KSh ' . number_format($allocated) . ' spent.';
// The key includes the allocated amount, so a newly approved budget can trigger the alert again
$keyBase     = $walletId . ':' . $category . ':' . (int) $allocated;

if ($allocated > 0 && $spentAfter >= $allocated) {
    notify($conn, $studentId, 'budget_100', "You've used all of your $lineName budget", $usedText, 'record-spending.php', 'b100:' . $keyBase);
    notify_sponsors($conn, $studentId, 'budget_100', $who['full_name'] . " has used all of $lineName", $usedText, 'sponsor-spending.php', 'b100:' . $keyBase);
} elseif ($allocated > 0 && $spentAfter / $allocated >= 0.8 && $spentBefore / $allocated < 0.8) {
    $pct = (int) floor($spentAfter / $allocated * 100);
    notify($conn, $studentId, 'budget_80', "You've used $pct% of your $lineName budget", $usedText, 'record-spending.php', 'b80:' . $keyBase);
    notify_sponsors($conn, $studentId, 'budget_80', $who['full_name'] . " has used $pct% of $lineName", $usedText, 'sponsor-spending.php', 'b80:' . $keyBase);
}

// Large expense with no receipt: tell the sponsor
const LARGE_EXPENSE_KSH = 5000;
if (!$receipt && (float) $amount >= LARGE_EXPENSE_KSH) {
    notify_sponsors(
        $conn, $studentId, 'receipt_missing',
        'Large expense without a receipt',
        $who['full_name'] . ' recorded KSh ' . number_format((float) $amount) . " for $lineName (\"$description\") with no receipt.",
        'sponsor-spending.php'
    );
}

back('success', 'Recorded KSh ' . number_format((float) $amount, 2) . ' for ' . budget_categories()[$category]
    . ($receipt ? ' with a receipt' : '') . '. New balance: KSh ' . number_format((float) $newBalance, 2) . '.');