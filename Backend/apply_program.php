<?php
// Backend/apply_program.php
// Student actions on the Find Sponsorship page:
//   action = apply    -> apply to a program with a short motivation and the documents it asks for
//   action = withdraw -> withdraw an application that has not been decided yet
// Documents are checked by their real content (JPG, PNG or PDF, max 5 MB each) and stored with random
// names in Uploads/documents, which the browser cannot open directly (see view_document.php).

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/programs.php';
require_once __DIR__ . '/notify.php';
require_role('student');

function back(string $type, string $message, string $anchor = ''): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/student-programs.php' . $anchor);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the forms on the Find Sponsorship page.');
}

$studentId   = (int) $_SESSION['user_id'];
$studentName = $_SESSION['full_name'];
$action      = $_POST['action'] ?? '';

// ======================= Withdraw =======================
if ($action === 'withdraw') {
    $applicationId = (int) ($_POST['application_id'] ?? 0);
    $stmt = $conn->prepare(
        "UPDATE applications SET status = 'withdrawn', decided_at = NOW()
         WHERE id = ? AND student_id = ? AND status = 'submitted'"
    );
    $stmt->bind_param('ii', $applicationId, $studentId);
    $stmt->execute();
    $done = $stmt->affected_rows > 0;
    $stmt->close();
    back($done ? 'success' : 'error', $done ? 'Your application was withdrawn.' : 'Only an application that has not been decided can be withdrawn.', '#mine');
}

if ($action !== 'apply') {
    back('error', 'Unknown action.');
}

// ======================= Apply =======================
$programId  = (int) ($_POST['program_id'] ?? 0);
$motivation = trim($_POST['motivation'] ?? '');

// The program must be visible to students: open, before its deadline, verified organization, places left
$stmt = $conn->prepare(
    "SELECT pr.id, pr.org_id, pr.title, pr.required_docs, pr.slots, pr.deadline, pr.status,
            o.full_name AS org_name, o.org_verified
     FROM programs pr JOIN users o ON o.id = pr.org_id
     WHERE pr.id = ?"
);
$stmt->bind_param('i', $programId);
$stmt->execute();
$program = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$program || (int) $program['org_verified'] !== 1) {
    back('error', 'That program was not found.');
}
if ($program['status'] !== 'open' || $program['deadline'] < date('Y-m-d')) {
    back('error', 'This program is no longer taking applications.');
}
if (program_accepted($conn, $programId) >= (int) $program['slots']) {
    back('error', 'All places in this program have been filled.');
}

// Already sponsored by this organization?
$stmt = $conn->prepare("SELECT 1 FROM sponsorships WHERE student_id = ? AND sponsor_id = ? AND status = 'active'");
$orgId = (int) $program['org_id'];
$stmt->bind_param('ii', $studentId, $orgId);
$stmt->execute();
$alreadySponsored = (bool) $stmt->get_result()->fetch_row();
$stmt->close();
if ($alreadySponsored) {
    back('error', $program['org_name'] . ' already sponsors you.');
}

// Applied before?
$stmt = $conn->prepare('SELECT id, status FROM applications WHERE program_id = ? AND student_id = ?');
$stmt->bind_param('ii', $programId, $studentId);
$stmt->execute();
$previous = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($previous && $previous['status'] !== 'withdrawn') {
    back('error', 'You have already applied to this program.', '#mine');
}

if (mb_strlen($motivation) < 30 || mb_strlen($motivation) > 2000) {
    back('error', 'Tell the organization why you need this sponsorship (30 to 2000 characters).', '#program-' . $programId);
}

// Check every required document before saving anything
$required = program_docs($program['required_docs']);
$files    = $_FILES['docs'] ?? null;
$uploads  = [];   // code => ['tmp', 'ext', 'type', 'name']
foreach ($required as $code) {
    $label = DOC_TYPES[$code];
    $error = $files['error'][$code] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        back('error', 'Attach your ' . $label . '.', '#program-' . $programId);
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || ($files['size'][$code] ?? 0) > DOC_MAX_BYTES) {
        back('error', 'Your ' . $label . ' is too large. The limit is 5 MB per document.', '#program-' . $programId);
    }
    $tmp = $files['tmp_name'][$code] ?? '';
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
        back('error', 'Your ' . $label . ' could not be uploaded. Please try again.', '#program-' . $programId);
    }
    $realType = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!isset(DOC_MIME[$realType])) {
        back('error', 'Your ' . $label . ' must be a JPG, PNG or PDF file.', '#program-' . $programId);
    }
    $uploads[$code] = ['tmp' => $tmp, 'ext' => DOC_MIME[$realType], 'type' => $realType,
                       'name' => mb_substr(basename($files['name'][$code]), 0, 255)];
}

// Save the files, then the application, in one go. If anything fails, remove the saved files.
$dir = documents_dir();
if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    back('error', 'Documents cannot be saved right now. Please tell the administrator.');
}
$saved = [];   // full paths written so far
try {
    foreach ($uploads as $code => &$u) {
        $u['file'] = bin2hex(random_bytes(16)) . '.' . $u['ext'];
        if (!move_uploaded_file($u['tmp'], $dir . '/' . $u['file'])) {
            throw new RuntimeException('Could not store ' . $code);
        }
        $saved[] = $dir . '/' . $u['file'];
    }
    unset($u);

    $conn->begin_transaction();

    if ($previous) {
        // Re-applying after withdrawing: reuse the row, replace its documents
        $applicationId = (int) $previous['id'];
        $stmt = $conn->prepare("UPDATE applications SET status = 'submitted', motivation = ?, decision_note = NULL, decided_at = NULL, created_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $motivation, $applicationId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare('SELECT file FROM application_documents WHERE application_id = ?');
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $oldFiles = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'file');
        $stmt->close();
        $stmt = $conn->prepare('DELETE FROM application_documents WHERE application_id = ?');
        $stmt->bind_param('i', $applicationId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare('INSERT INTO applications (program_id, student_id, motivation) VALUES (?, ?, ?)');
        $stmt->bind_param('iis', $programId, $studentId, $motivation);
        $stmt->execute();
        $applicationId = (int) $conn->insert_id;
        $stmt->close();
        $oldFiles = [];
    }

    $stmt = $conn->prepare(
        'INSERT INTO application_documents (application_id, doc_type, file, original_name, mime) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($uploads as $code => $u) {
        $stmt->bind_param('issss', $applicationId, $code, $u['file'], $u['name'], $u['type']);
        $stmt->execute();
    }
    $stmt->close();

    $conn->commit();

    foreach ($oldFiles as $f) {                   // old documents of a withdrawn application
        if (preg_match('/^[a-f0-9]{32}\.(jpg|png|pdf)$/', $f) && is_file($dir . '/' . $f)) {
            unlink($dir . '/' . $f);
        }
    }
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $ignored) {}
    foreach ($saved as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    error_log('Apply to program failed: ' . $e->getMessage());
    back('error', 'Your application could not be saved. Please try again.', '#program-' . $programId);
}

notify(
    $conn, $orgId, 'application_received',
    $studentName . ' applied to ' . $program['title'],
    count($uploads) . ' document' . (count($uploads) === 1 ? '' : 's') . ' attached. Review it on your Applications page.',
    'org-applications.php?program=' . $programId
);
back('success', 'Your application to "' . $program['title'] . '" was sent to ' . $program['org_name'] . '. You will get an alert when they decide.', '#mine');
