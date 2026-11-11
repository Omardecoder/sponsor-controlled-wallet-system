<?php
// Backend/student_document_action.php
// Student: add or delete a supporting document on My Profile.
//   action = upload -> doc_type + file (JPG, PNG or PDF, max 5 MB, checked by real content)
//   action = delete -> document_id

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/student_docs.php';
require_role('student');

function back(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ../Frontend/student-profile.php#documents');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back('error', 'Use the form on My Profile.');
}

$studentId = (int) $_SESSION['user_id'];
$action    = $_POST['action'] ?? '';

// ======================= Delete =======================
if ($action === 'delete') {
    $docId = (int) ($_POST['document_id'] ?? 0);
    try {
        $stmt = $conn->prepare('SELECT file FROM student_documents WHERE id = ? AND student_id = ?');
        $stmt->bind_param('ii', $docId, $studentId);
        $stmt->execute();
        $doc = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$doc) {
            back('error', 'That document was not found.');
        }
        $stmt = $conn->prepare('DELETE FROM student_documents WHERE id = ? AND student_id = ?');
        $stmt->bind_param('ii', $docId, $studentId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('Delete student document failed: ' . $e->getMessage());
        back('error', 'Something went wrong. Please try again.');
    }
    $path = documents_dir() . '/' . $doc['file'];
    if (preg_match('/^[a-f0-9]{32}\.(jpg|png|pdf)$/', $doc['file']) && is_file($path)) {
        unlink($path);
    }
    back('success', 'Document deleted.');
}

if ($action !== 'upload') {
    back('error', 'Unknown action.');
}

// ======================= Upload =======================
$docType = $_POST['doc_type'] ?? '';
if (!isset(STUDENT_DOC_TYPES[$docType])) {
    back('error', 'Choose what kind of document this is.');
}
if (count(student_documents($conn, $studentId)) >= STUDENT_DOC_MAX) {
    back('error', 'You can have up to ' . STUDENT_DOC_MAX . ' documents. Delete one first.');
}

$f     = $_FILES['document'] ?? null;
$error = $f['error'] ?? UPLOAD_ERR_NO_FILE;
if ($error === UPLOAD_ERR_NO_FILE) {
    back('error', 'Choose a file to upload.');
}
if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || ($f['size'] ?? 0) > DOC_MAX_BYTES) {
    back('error', 'The file is too large. The limit is 5 MB.');
}
if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
    back('error', 'The file could not be uploaded. Please try again.');
}
$realType = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
if (!isset(DOC_MIME[$realType])) {
    back('error', 'The document must be a JPG, PNG or PDF file.');
}

$dir = documents_dir();
if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    back('error', 'Documents cannot be saved right now. Please tell the administrator.');
}
$file = bin2hex(random_bytes(16)) . '.' . DOC_MIME[$realType];
$name = mb_substr(basename((string) $f['name']), 0, 255);
if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $file)) {
    back('error', 'The file could not be saved. Please try again.');
}

try {
    $stmt = $conn->prepare('INSERT INTO student_documents (student_id, doc_type, file, original_name, mime) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issss', $studentId, $docType, $file, $name, $realType);
    $stmt->execute();
    $stmt->close();
} catch (Throwable $e) {
    if (is_file($dir . '/' . $file)) {
        unlink($dir . '/' . $file);
    }
    error_log('Upload student document failed: ' . $e->getMessage());
    back('error', 'The document could not be saved. Ask the administrator to run the Step 30 database update.');
}

back('success', STUDENT_DOC_TYPES[$docType] . ' uploaded. Well-wishers can open it from your profile while you are listed.');