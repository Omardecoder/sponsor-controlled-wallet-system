<?php
// Backend/admin_org_status.php
// The admin verifies (or un-verifies) an organization account.
// Only verified organizations' programs are shown to students.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notify.php';
require_role('admin');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/admin-dashboard.php#organizations');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the buttons to change an organization.');
}

$orgId  = (int) ($_POST['org_id'] ?? 0);
$action = $_POST['action'] ?? '';
if (!in_array($action, ['verify', 'unverify'], true)) {
    back('error', 'Unknown action.');
}

$stmt = $conn->prepare("SELECT id, full_name FROM users WHERE id = ? AND role = 'sponsor' AND sponsor_type = 'organization'");
$stmt->bind_param('i', $orgId);
$stmt->execute();
$org = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$org) {
    back('error', 'That organization was not found.');
}

$verified = $action === 'verify' ? 1 : 0;
$stmt = $conn->prepare('UPDATE users SET org_verified = ? WHERE id = ?');
$stmt->bind_param('ii', $verified, $orgId);
$stmt->execute();
$stmt->close();

if ($verified) {
    notify($conn, $orgId, 'org_verified', 'Your organization is verified',
        'Students can now see and apply to your sponsorship programs.', 'org-programs.php');
    back('success', $org['full_name'] . ' is now verified. Its programs are visible to students.');
}
notify($conn, $orgId, 'org_unverified', 'Your organization verification was removed',
    'Your programs are hidden from students until the administrator verifies you again.', 'org-programs.php');
back('success', $org['full_name'] . ' is no longer verified. Its programs are hidden from students.');