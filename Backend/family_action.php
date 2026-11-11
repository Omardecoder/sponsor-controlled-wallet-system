<?php
// Backend/family_action.php
// Student: link a family member by email, or cancel an invitation (from My Profile -> My family).
//   action = link          -> family_email + relationship (see family_link.php)
//   action = cancel_invite -> invite_id

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/family_link.php';
require_role('student');

function back(string $type, string $message, ?string $inviteLink = null): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message, 'invite_link' => $inviteLink];
    header('Location: ../Frontend/student-profile.php#family');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the form on My Profile.');
}

$studentId = (int) $_SESSION['user_id'];
$action    = $_POST['action'] ?? '';

if ($action === 'link') {
    $stmt = $conn->prepare('SELECT full_name, email FROM users WHERE id = ?');
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $me = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $r = link_family($conn, $studentId, $me['full_name'], $me['email'], $_POST['family_email'] ?? '', $_POST['relationship'] ?? '');
    back($r['type'], $r['message'], $r['invite_link']);
}

if ($action === 'cancel_invite') {
    $inviteId = (int) ($_POST['invite_id'] ?? 0);
    $stmt = $conn->prepare("UPDATE family_invites SET status = 'cancelled' WHERE id = ? AND student_id = ? AND status = 'open'");
    $stmt->bind_param('ii', $inviteId, $studentId);
    $stmt->execute();
    $done = $stmt->affected_rows > 0;
    $stmt->close();
    back($done ? 'success' : 'error', $done ? 'Invitation cancelled. The link in the email no longer works.' : 'That invitation was not found.');
}

back('error', 'Unknown action.');