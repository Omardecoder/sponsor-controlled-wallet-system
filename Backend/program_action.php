<?php
// Backend/program_action.php
// Organization actions on the Programs page:
//   action = about  -> save the short "about us" text students see
//   action = create -> post a new sponsorship program
//   action = close / reopen -> stop or restart taking applications

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/programs.php';
require_role('sponsor');

function back(string $type, string $message, array $old = []): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    if ($old) {
        $_SESSION['program_old'] = $old;
    }
    header('Location: ../Frontend/org-programs.php');
    exit;
}

$orgId = (int) $_SESSION['user_id'];
if (!is_organization($conn, $orgId)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Only organization accounts can post sponsorship programs.'];
    header('Location: ../Frontend/sponsor-dashboard.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the forms on the Programs page.');
}

$action = $_POST['action'] ?? '';

// ======================= About the organization =======================
if ($action === 'about') {
    $about = trim(preg_replace('/\s+/', ' ', $_POST['about'] ?? ''));
    if (mb_strlen($about) > 500) {
        back('error', 'Keep the description to 500 characters or fewer.');
    }
    $aboutValue = $about !== '' ? $about : null;
    $stmt = $conn->prepare('UPDATE users SET about = ? WHERE id = ?');
    $stmt->bind_param('si', $aboutValue, $orgId);
    $stmt->execute();
    $stmt->close();
    back('success', 'Your organization description is saved.');
}

// ======================= Create a program =======================
if ($action === 'create') {
    $title       = trim(preg_replace('/\s+/', ' ', $_POST['title'] ?? ''));
    $description = trim($_POST['description'] ?? '');
    $amountIn    = str_replace([',', ' '], '', trim($_POST['amount'] ?? ''));
    $slotsIn     = trim($_POST['slots'] ?? '');
    $deadlineIn  = trim($_POST['deadline'] ?? '');
    $docsIn      = $_POST['docs'] ?? [];

    $old = ['title' => $title, 'description' => $description, 'amount' => $amountIn, 'slots' => $slotsIn,
            'deadline' => $deadlineIn, 'docs' => is_array($docsIn) ? $docsIn : []];

    if (mb_strlen($title) < 3 || mb_strlen($title) > 120) {
        back('error', 'Give the program a title of 3 to 120 characters.', $old);
    }
    if (mb_strlen($description) < 10 || mb_strlen($description) > 2000) {
        back('error', 'Describe the program and who can apply (10 to 2000 characters).', $old);
    }
    if (!preg_match('/^\d{1,8}$/', $amountIn) || (int) $amountIn < 1 || (int) $amountIn > 10000000) {
        back('error', 'Enter the amount per student as a whole number of shillings, for example 60000.', $old);
    }
    if (!preg_match('/^\d{1,4}$/', $slotsIn) || (int) $slotsIn < 1 || (int) $slotsIn > 1000) {
        back('error', 'Enter how many students you can sponsor (1 to 1000).', $old);
    }
    $deadline = DateTime::createFromFormat('!Y-m-d', $deadlineIn);
    $today    = new DateTime('today');
    if (!$deadline || $deadline->format('Y-m-d') !== $deadlineIn || $deadline < $today || $deadline > (clone $today)->modify('+2 years')) {
        back('error', 'Choose a closing date between today and two years from now.', $old);
    }
    if (!is_array($docsIn)) {
        $docsIn = [];
    }
    $docs = array_values(array_intersect(array_keys(DOC_TYPES), $docsIn));   // only known codes, in a fixed order

    $amount   = (int) $amountIn;
    $slots    = (int) $slotsIn;
    $deadline = $deadline->format('Y-m-d');
    $docList  = implode(',', $docs);

    $stmt = $conn->prepare(
        'INSERT INTO programs (org_id, title, description, amount, slots, deadline, required_docs) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issiiss', $orgId, $title, $description, $amount, $slots, $deadline, $docList);
    $stmt->execute();
    $stmt->close();

    $verified = (int) (sponsor_profile($conn, $orgId)['org_verified'] ?? 0) === 1;
    back('success', 'Program "' . $title . '" is posted.' . ($verified ? ' Students can apply now.' : ' Students will see it once the administrator verifies your organization.'));
}

// ======================= Close / reopen =======================
if ($action === 'close' || $action === 'reopen') {
    $programId = (int) ($_POST['program_id'] ?? 0);
    $newStatus = $action === 'close' ? 'closed' : 'open';
    $stmt = $conn->prepare('UPDATE programs SET status = ? WHERE id = ? AND org_id = ?');
    $stmt->bind_param('sii', $newStatus, $programId, $orgId);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$changed) {
        back('error', 'That program was not found, or it is already ' . $newStatus . '.');
    }
    back('success', $action === 'close' ? 'The program is closed to new applications.' : 'The program is open for applications again.');
}

back('error', 'Unknown action.');