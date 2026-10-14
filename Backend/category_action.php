<?php
// Backend/category_action.php
// A student adds or removes their own budget categories (e.g. "Entertainment").
//   action = add     name = "Entertainment"
//   action = remove  id   = 7      (only if never used in a budget or an expense)

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/categories.php';
require_role('student');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/student-budget.php#categories');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the form to manage categories.');
}

$studentId = (int) $_SESSION['user_id'];
$action    = $_POST['action'] ?? '';

// The student's wallet
$stmt = $conn->prepare('SELECT id FROM wallets WHERE student_id = ? LIMIT 1');
$stmt->bind_param('i', $studentId);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$wallet) {
    back('error', 'You need to be linked to a sponsor before adding categories.');
}
$walletId = (int) $wallet['id'];

// ---------------- Add ----------------
if ($action === 'add') {
    // Tidy the name: single spaces, first letter capital
    $name = trim(preg_replace('/\s+/', ' ', $_POST['name'] ?? ''));
    $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);

    if (mb_strlen($name) < 2 || mb_strlen($name) > 30) {
        back('error', 'A category name must be 2 to 30 characters.');
    }
    if (!preg_match("/^[\p{L}\p{N} &'\-]+$/u", $name)) {
        back('error', 'Use only letters, numbers, spaces, & \' and - in a category name.');
    }

    // No duplicates (ignoring capital letters), including the standard ones
    $existing = budget_categories($walletId);
    foreach ($existing as $label) {
        if (mb_strtolower($label) === mb_strtolower($name)) {
            back('error', '"' . $name . '" is already one of your categories.');
        }
    }
    $customCount = count($existing) - count(STANDARD_CATEGORIES);
    if ($customCount >= MAX_CUSTOM_CATEGORIES) {
        back('error', 'You can have at most ' . MAX_CUSTOM_CATEGORIES . ' categories of your own. Remove one you no longer use first.');
    }

    try {
        $stmt = $conn->prepare('INSERT INTO custom_categories (wallet_id, name) VALUES (?, ?)');
        $stmt->bind_param('is', $walletId, $name);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() === 1062) {
            back('error', '"' . $name . '" is already one of your categories.');
        }
        error_log('Add category failed: ' . $e->getMessage());
        back('error', 'Something went wrong. Please try again.');
    }
    back('success', '"' . $name . '" added. Enter an amount for it below and submit your budget.');
}

// ---------------- Remove ----------------
if ($action === 'remove') {
    $id   = (int) ($_POST['id'] ?? 0);
    $slug = 'c' . $id;

    $stmt = $conn->prepare('SELECT name FROM custom_categories WHERE id = ? AND wallet_id = ?');
    $stmt->bind_param('ii', $id, $walletId);
    $stmt->execute();
    $cat = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$cat) {
        back('error', 'That category was not found.');
    }

    // Keep history intact: refuse if it was ever used
    $checks = [
        'SELECT 1 FROM budget_proposal_items i JOIN budget_proposals p ON p.id = i.proposal_id WHERE p.wallet_id = ? AND i.category = ? LIMIT 1',
        'SELECT 1 FROM budget_allocations WHERE wallet_id = ? AND category = ? LIMIT 1',
        'SELECT 1 FROM expenses WHERE wallet_id = ? AND category = ? LIMIT 1',
    ];
    foreach ($checks as $sql) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('is', $walletId, $slug);
        $stmt->execute();
        $used = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($used) {
            back('error', '"' . $cat['name'] . '" has already been used in a budget or an expense, so it can\'t be removed. Just leave its amount at 0.');
        }
    }

    $stmt = $conn->prepare('DELETE FROM custom_categories WHERE id = ? AND wallet_id = ?');
    $stmt->bind_param('ii', $id, $walletId);
    $stmt->execute();
    $stmt->close();
    back('success', '"' . $cat['name'] . '" removed.');
}

back('error', 'Unknown action.');