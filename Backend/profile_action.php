<?php
// Backend/profile_action.php
// Saves the student's public profile (from student-profile.php) and whether they are listed
// on the Students page for well-wishers. Step 30: also the achievements list (one per line).
// A listed student gets a wallet straight away (if they have none), so they can plan and submit
// a budget before any sponsor is linked.

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/sponsorship.php';
require_once __DIR__ . '/student_docs.php';
require_role('student');

function back(string $type, string $message, ?array $old = null): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    if ($old !== null) {
        $_SESSION['profile_old'] = $old;
    }
    header('Location: ../Frontend/student-profile.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the form to save your profile.');
}

$studentId   = (int) $_SESSION['user_id'];
$institution = trim(preg_replace('/\s+/', ' ', $_POST['institution'] ?? ''));
$course      = trim(preg_replace('/\s+/', ' ', $_POST['course'] ?? ''));
$yearRaw     = $_POST['year_of_study'] ?? '';
$story       = trim(str_replace("\r\n", "\n", $_POST['story'] ?? ''));
$listed      = !empty($_POST['listed']) ? 1 : 0;
$achList     = achievements_list($_POST['achievements'] ?? '');

$old = ['institution' => $institution, 'course' => $course, 'year_of_study' => $yearRaw, 'story' => $story,
        'achievements' => implode("\n", $achList), 'listed' => $listed];

if (mb_strlen($institution) > 120 || mb_strlen($course) > 120) {
    back('error', 'Institution and course must be 120 characters or fewer.', $old);
}
if (mb_strlen($story) > 600) {
    back('error', 'Your story must be 600 characters or fewer.', $old);
}
if (count($achList) > ACHIEVEMENTS_MAX) {
    back('error', 'List up to ' . ACHIEVEMENTS_MAX . ' achievements, one per line.', $old);
}
foreach ($achList as $a) {
    if (mb_strlen($a) > ACHIEVEMENT_MAX_LEN) {
        back('error', 'Keep each achievement to ' . ACHIEVEMENT_MAX_LEN . ' characters or fewer.', $old);
    }
}
$year = null;
if ($yearRaw !== '') {
    if (!ctype_digit((string) $yearRaw) || (int) $yearRaw < 1 || (int) $yearRaw > 6) {
        back('error', 'Choose your year of study.', $old);
    }
    $year = (int) $yearRaw;
}

// To be listed, the profile must be complete: well-wishers decide from it
if ($listed) {
    $missing = [];
    if ($institution === '') { $missing[] = 'your university or college'; }
    if ($course === '')      { $missing[] = 'your course'; }
    if ($year === null)      { $missing[] = 'your year of study'; }
    if (mb_strlen($story) < 50) { $missing[] = 'a story of at least 50 characters'; }
    if ($missing) {
        back('error', 'To be listed for well-wishers, add ' . implode(', ', $missing) . '.', $old);
    }
}

$institutionValue = $institution !== '' ? $institution : null;
$courseValue      = $course !== '' ? $course : null;
$storyValue       = $story !== '' ? $story : null;
$achValue         = $achList ? implode("\n", $achList) : null;

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("UPDATE users SET institution = ?, course = ?, year_of_study = ?, story = ?, achievements = ?, listed = ?
                            WHERE id = ? AND role = 'student'");
    $stmt->bind_param('ssissii', $institutionValue, $courseValue, $year, $storyValue, $achValue, $listed, $studentId);
    $stmt->execute();
    $stmt->close();

    // A listed student needs a wallet to plan a budget, even before any sponsor is linked
    $createdWallet = false;
    if ($listed) {
        $stmt = $conn->prepare('SELECT id FROM wallets WHERE student_id = ? FOR UPDATE');
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $hasWallet = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$hasWallet) {
            $stmt = $conn->prepare('INSERT INTO wallets (student_id, sponsor_id) VALUES (?, NULL)');
            $stmt->bind_param('i', $studentId);
            $stmt->execute();
            $stmt->close();
            $createdWallet = true;
        }
    }

    $conn->commit();
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    error_log('Save profile failed: ' . $e->getMessage());
    back('error', 'Something went wrong. Nothing was changed. If this keeps happening, ask the administrator to run the Step 30 database update.', $old);
}

if ($listed) {
    back('success', 'Saved. You are listed for well-wishers.'
        . ($createdWallet ? ' Your wallet is ready: create your budget on My Budget so they can fund it.' : ' Make sure you have a budget submitted on My Budget so they can fund it.'));
}
back('success', 'Profile saved. You are not listed for well-wishers.');