<?php
// Backend/respond_sponsorship.php
// A student accepts or declines a sponsor's request (from the student dashboard).
// Accepting creates the student's wallet the first time; later sponsors share the same wallet.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sponsorship.php';
require_role('student');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/student-dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the Accept or Decline buttons.');
}

$studentId     = (int) $_SESSION['user_id'];
$studentName   = $_SESSION['full_name'];
$sponsorshipId = (int) ($_POST['sponsorship_id'] ?? 0);
$decision      = $_POST['decision'] ?? '';

if (!in_array($decision, ['accept', 'decline'], true)) {
    back('error', 'Choose Accept or Decline.');
}

try {
    $conn->begin_transaction();

    // The request must be addressed to this student and still unanswered
    $stmt = $conn->prepare(
        "SELECT sp.id, sp.sponsor_id, sp.pledge, u.full_name AS sponsor_name
         FROM sponsorships sp JOIN users u ON u.id = sp.sponsor_id
         WHERE sp.id = ? AND sp.student_id = ? AND sp.status = 'pending'
         FOR UPDATE"
    );
    $stmt->bind_param('ii', $sponsorshipId, $studentId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        $conn->rollback();
        back('error', 'That request was not found or has already been answered.');
    }
    $sponsorId = (int) $request['sponsor_id'];

    if ($decision === 'accept') {
        // The student's one wallet: create it with the first sponsor who is accepted
        $stmt = $conn->prepare('SELECT id FROM wallets WHERE student_id = ? FOR UPDATE');
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $wallet = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$wallet) {
            $stmt = $conn->prepare('INSERT INTO wallets (student_id, sponsor_id) VALUES (?, ?)');
            $stmt->bind_param('ii', $studentId, $sponsorId);
            $stmt->execute();
            $stmt->close();
        }
        $newStatus = 'active';
    } else {
        $newStatus = 'declined';
    }

    $stmt = $conn->prepare('UPDATE sponsorships SET status = ?, responded_at = NOW() WHERE id = ?');
    $stmt->bind_param('si', $newStatus, $sponsorshipId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('Respond to sponsorship failed: ' . $e->getMessage());
    back('error', 'Something went wrong. Please try again.');
}

// ---- Alerts ----
if ($decision === 'decline') {
    notify($conn, $sponsorId, 'sponsorship_declined', $studentName . ' declined your sponsorship request', '', 'sponsor-dashboard.php#students');
    back('success', 'You declined the request from ' . $request['sponsor_name'] . '.');
}

notify(
    $conn, $sponsorId, 'sponsorship_accepted',
    $studentName . ' accepted your sponsorship',
    'Your pledge: KSh ' . number_format((float) $request['pledge']) . '. You can now see their wallet, approve budgets and send funds.',
    'sponsor-dashboard.php#students'
);
notify_sponsors(
    $conn, $studentId, 'co_sponsor_joined',
    $request['sponsor_name'] . ' is now also sponsoring ' . $studentName,
    'Pledge: KSh ' . number_format((float) $request['pledge']) . '. Budgets now need approval from every sponsor.',
    'sponsor-dashboard.php#students', null, $sponsorId
);

back('success', $request['sponsor_name'] . ' is now one of your sponsors. Your budget can total up to KSh '
    . number_format(pledge_total($conn, $studentId)) . '.');