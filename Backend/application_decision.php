<?php
// Backend/application_decision.php
// An organization accepts or rejects a student's application to one of its programs.
// Accepting links the student to the organization straight away (the student asked for it by applying):
//   - an ACTIVE sponsorship is created; the program amount becomes the organization's LIMIT per budget
//     (it can commit up to that much of each budget the student sends),
//   - the student's wallet is created if they don't have one yet.
// From then on the organization works like any other sponsor (approves budgets, sends funds, sees spending).

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/programs.php';
require_once __DIR__ . '/sponsorship.php';
require_role('sponsor');

$programFilter = (int) ($_POST['return_program'] ?? 0);
function back(string $type, string $message): void
{
    global $programFilter;
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/org-applications.php' . ($programFilter ? '?program=' . $programFilter : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the Accept or Reject buttons.');
}

$orgId         = (int) $_SESSION['user_id'];
$orgName       = $_SESSION['full_name'];
$applicationId = (int) ($_POST['application_id'] ?? 0);
$decision      = $_POST['decision'] ?? '';
$note          = trim(preg_replace('/\s+/', ' ', $_POST['note'] ?? ''));

if (!in_array($decision, ['accept', 'reject'], true)) {
    back('error', 'Choose Accept or Reject.');
}
if (mb_strlen($note) > 500) {
    back('error', 'The message must be 500 characters or fewer.');
}
$noteValue = $note !== '' ? $note : null;

try {
    $conn->begin_transaction();

    // The application must be for one of THIS organization's programs and still undecided.
    // The program row is locked too, so two acceptances can't overfill the places.
    $stmt = $conn->prepare(
        "SELECT a.id, a.student_id, a.status, pr.id AS program_id, pr.title, pr.amount, pr.slots,
                u.full_name AS student_name
         FROM applications a
         JOIN programs pr ON pr.id = a.program_id
         JOIN users u ON u.id = a.student_id
         WHERE a.id = ? AND pr.org_id = ?
         FOR UPDATE"
    );
    $stmt->bind_param('ii', $applicationId, $orgId);
    $stmt->execute();
    $app = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$app) {
        $conn->rollback();
        back('error', 'That application was not found.');
    }
    if ($app['status'] !== 'submitted') {
        $conn->rollback();
        back('error', 'This application has already been decided or was withdrawn.');
    }
    $studentId = (int) $app['student_id'];
    $programId = (int) $app['program_id'];

    if ($decision === 'accept') {
        if (program_accepted($conn, $programId) >= (int) $app['slots']) {
            $conn->rollback();
            back('error', 'All ' . (int) $app['slots'] . ' places in this program are filled. Increase the places or reject the application.');
        }

        // Link the student to the organization (create or re-activate the sponsorship)
        $pledge  = (float) $app['amount'];
        $message = mb_substr('Program: ' . $app['title'], 0, 255);
        $stmt = $conn->prepare('SELECT id, status FROM sponsorships WHERE student_id = ? AND sponsor_id = ? FOR UPDATE');
        $stmt->bind_param('ii', $studentId, $orgId);
        $stmt->execute();
        $link = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($link && $link['status'] === 'active') {
            $conn->rollback();
            back('error', 'You already sponsor ' . $app['student_name'] . '.');
        }
        if ($link) {
            $linkId = (int) $link['id'];
            $stmt = $conn->prepare("UPDATE sponsorships SET status = 'active', pledge = ?, message = ?, responded_at = NOW() WHERE id = ?");
            $stmt->bind_param('dsi', $pledge, $message, $linkId);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO sponsorships (student_id, sponsor_id, pledge, status, message, responded_at) VALUES (?, ?, ?, 'active', ?, NOW())"
            );
            $stmt->bind_param('iids', $studentId, $orgId, $pledge, $message);
        }
        $stmt->execute();
        $stmt->close();

        // The student's one wallet: create it if this is their first sponsor
        $stmt = $conn->prepare('SELECT id FROM wallets WHERE student_id = ? FOR UPDATE');
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $hasWallet = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$hasWallet) {
            $stmt = $conn->prepare('INSERT INTO wallets (student_id, sponsor_id) VALUES (?, ?)');
            $stmt->bind_param('ii', $studentId, $orgId);
            $stmt->execute();
            $stmt->close();
        }
        $newStatus = 'accepted';
    } else {
        $newStatus = 'rejected';
    }

    $stmt = $conn->prepare('UPDATE applications SET status = ?, decision_note = ?, decided_at = NOW() WHERE id = ?');
    $stmt->bind_param('ssi', $newStatus, $noteValue, $applicationId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('Application decision failed: ' . $e->getMessage());
    back('error', 'Something went wrong. Nothing was changed. Please try again.');
}

// ---- Alerts ----
if ($decision === 'reject') {
    notify($conn, $studentId, 'application_rejected', 'Your application to ' . $app['title'] . ' was not successful',
        $note !== '' ? $orgName . ' says: "' . $note . '"' : 'You can apply to other programs on the Find Sponsorship page.',
        'student-programs.php#mine');
    back('success', 'Application from ' . $app['student_name'] . ' rejected.');
}

notify($conn, $studentId, 'application_accepted', $orgName . ' accepted your application',
    'You are in "' . $app['title'] . '". ' . $orgName . ' is now one of your sponsors and can fund up to KSh '
        . number_format((float) $app['amount']) . ' of each budget. Create your budget and send it.' . ($note !== '' ? ' Message: "' . $note . '"' : ''),
    'student-budget.php');
notify_sponsors($conn, $studentId, 'co_sponsor_joined', $orgName . ' is now also sponsoring ' . $app['student_name'],
    'Through the program "' . $app['title'] . '" (up to KSh ' . number_format((float) $app['amount']) . ' per budget). They can now fund a share of the budgets alongside you.',
    'sponsor-dashboard.php#students', null, $orgId);
back('success', $app['student_name'] . ' is accepted and now appears under My Students. You can fund up to KSh ' . number_format((float) $app['amount']) . ' of each of their budgets.');