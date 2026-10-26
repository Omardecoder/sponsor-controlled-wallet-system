<?php
// Backend/sponsorship_action.php
// Sponsor actions on sponsorships (from the "My students" card on the sponsor dashboard):
//   action = request -> ask a student to accept you as a sponsor, with a pledge (the most you will give)
//   action = pledge  -> change your pledge for a student
//   action = cancel  -> withdraw a request the student has not answered yet
// A student can have several sponsors; each one is a separate sponsorship.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sponsorship.php';
require_role('sponsor');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/sponsor-dashboard.php#students');
    exit;
}

/** Whole shillings, 1 to 10,000,000. Returns null if invalid. */
function read_pledge(string $raw): ?int
{
    $raw = str_replace([',', ' '], '', trim($raw));
    if (!preg_match('/^\d{1,8}$/', $raw) || (int) $raw < 1 || (int) $raw > 10000000) {
        return null;
    }
    return (int) $raw;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the forms on your dashboard.');
}

$sponsorId   = (int) $_SESSION['user_id'];
$sponsorName = $_SESSION['full_name'];
$action      = $_POST['action'] ?? '';

// ======================= Request =======================
if ($action === 'request') {
    $identifier = trim($_POST['student_identifier'] ?? '');
    $pledge     = read_pledge($_POST['pledge'] ?? '');
    $message    = trim(preg_replace('/\s+/', ' ', $_POST['message'] ?? ''));

    if ($identifier === '') {
        back('error', 'Enter the student\'s email or phone number.');
    }
    if ($pledge === null) {
        back('error', 'Enter your pledge as a whole amount in shillings, for example 50000.');
    }
    if (mb_strlen($message) > 255) {
        back('error', 'The message must be 255 characters or fewer.');
    }

    // Find the student. Phones may be saved as 07..., 2547... or +2547..., so try all three.
    if (str_contains($identifier, '@')) {
        $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE email = ? AND role = 'student' LIMIT 1");
        $email = strtolower($identifier);
        $stmt->bind_param('s', $email);
    } else {
        $digits = preg_replace('/\D/', '', $identifier);
        $local  = preg_match('/^254(\d{9})$/', $digits, $m) ? '0' . $m[1] : $digits;   // 07XXXXXXXX
        $intl   = preg_match('/^0(\d{9})$/', $local, $m) ? '254' . $m[1] : $digits;     // 2547XXXXXXXX
        $plus   = '+' . $intl;
        $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE phone IN (?, ?, ?) AND role = 'student' LIMIT 1");
        $stmt->bind_param('sss', $local, $intl, $plus);
    }
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student) {
        back('error', 'No student account matches that email or phone number. Ask them to register as a Student first.');
    }
    $studentId = (int) $student['id'];

    // Already linked?
    $stmt = $conn->prepare('SELECT id, status FROM sponsorships WHERE student_id = ? AND sponsor_id = ?');
    $stmt->bind_param('ii', $studentId, $sponsorId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $messageValue = $message !== '' ? $message : null;
    if ($existing && $existing['status'] === 'active') {
        back('error', 'You already sponsor ' . $student['full_name'] . '. Change your pledge in the list below.');
    }
    if ($existing && $existing['status'] === 'pending') {
        back('error', 'You already asked ' . $student['full_name'] . '. Wait for them to accept or decline.');
    }

    if ($existing) {
        // Declined or ended before: ask again
        $stmt = $conn->prepare(
            "UPDATE sponsorships SET status = 'pending', pledge = ?, message = ?, created_at = NOW(), responded_at = NULL WHERE id = ?"
        );
        $existingId = (int) $existing['id'];
        $stmt->bind_param('isi', $pledge, $messageValue, $existingId);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO sponsorships (student_id, sponsor_id, pledge, status, message) VALUES (?, ?, ?, 'pending', ?)"
        );
        $stmt->bind_param('iiis', $studentId, $sponsorId, $pledge, $messageValue);
    }
    $stmt->execute();
    $stmt->close();

    notify(
        $conn, $studentId, 'sponsorship_request',
        $sponsorName . ' wants to sponsor you',
        'Pledge: KSh ' . number_format($pledge) . '.' . ($message !== '' ? ' Message: "' . $message . '"' : '') . ' Accept or decline on your dashboard.',
        'student-dashboard.php#requests'
    );
    back('success', 'Request sent to ' . $student['full_name'] . '. They will appear as active once they accept.');
}

// The remaining actions work on one of this sponsor's own sponsorships
$sponsorshipId = (int) ($_POST['sponsorship_id'] ?? 0);
$stmt = $conn->prepare(
    'SELECT sp.id, sp.student_id, sp.pledge, sp.status, u.full_name AS student_name, w.id AS wallet_id
     FROM sponsorships sp
     JOIN users u ON u.id = sp.student_id
     LEFT JOIN wallets w ON w.student_id = sp.student_id
     WHERE sp.id = ? AND sp.sponsor_id = ?'
);
$stmt->bind_param('ii', $sponsorshipId, $sponsorId);
$stmt->execute();
$link = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$link) {
    back('error', 'That sponsorship was not found.');
}

// ======================= Change pledge =======================
if ($action === 'pledge') {
    $pledge = read_pledge($_POST['pledge'] ?? '');
    if ($pledge === null) {
        back('error', 'Enter your pledge as a whole amount in shillings, for example 50000.');
    }
    if (!in_array($link['status'], ['active', 'pending'], true)) {
        back('error', 'This sponsorship is no longer active.');
    }
    // It can't go below what you have already sent (or are sending right now)
    $used = $link['wallet_id'] ? sponsor_pledge_used($conn, $sponsorId, (int) $link['wallet_id']) : 0.0;
    if ($pledge < $used) {
        back('error', 'Your pledge can\'t be lower than the KSh ' . number_format($used) . ' you have already sent to ' . $link['student_name'] . '.');
    }

    $stmt = $conn->prepare('UPDATE sponsorships SET pledge = ? WHERE id = ?');
    $stmt->bind_param('ii', $pledge, $sponsorshipId);
    $stmt->execute();
    $stmt->close();

    if ($link['status'] === 'active') {
        notify(
            $conn, (int) $link['student_id'], 'pledge_changed',
            $sponsorName . ' changed their pledge',
            'New pledge: KSh ' . number_format($pledge) . ' (was KSh ' . number_format((float) $link['pledge']) . '). Your budget can now total up to KSh '
                . number_format(pledge_total($conn, (int) $link['student_id'])) . '.',
            'student-budget.php'
        );
    }
    back('success', 'Your pledge for ' . $link['student_name'] . ' is now KSh ' . number_format($pledge) . '.');
}

// ======================= Cancel a pending request =======================
if ($action === 'cancel') {
    if ($link['status'] !== 'pending') {
        back('error', 'Only a request that has not been answered can be cancelled.');
    }
    $stmt = $conn->prepare("DELETE FROM sponsorships WHERE id = ? AND status = 'pending'");
    $stmt->bind_param('i', $sponsorshipId);
    $stmt->execute();
    $stmt->close();
    back('success', 'Request to ' . $link['student_name'] . ' cancelled.');
}

back('error', 'Unknown action.');