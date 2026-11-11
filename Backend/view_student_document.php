<?php
// Backend/view_student_document.php?id=DOCUMENT_ID
// Shows a document from a student's profile. Allowed: the student, the admin, the student's active
// sponsors, and well-wishers while the student is listed. Everyone else gets "not found".

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/student_docs.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role   = $_SESSION['role'] ?? '';
if ($userId === 0) {
    header('Location: ../Frontend/login.php');
    exit;
}

$docId = (int) ($_GET['id'] ?? 0);
$row = null;
try {
    $stmt = $conn->prepare('SELECT student_id, file, original_name, mime FROM student_documents WHERE id = ?');
    $stmt->bind_param('i', $docId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $e) {
    $row = null;
}

if (!$row || !can_view_student_docs($conn, $userId, $role, (int) $row['student_id'])
    || !preg_match('/^[a-f0-9]{32}\.(jpg|png|pdf)$/', $row['file'])) {
    http_response_code(404);
    exit('Document not found.');
}

$path = documents_dir() . '/' . $row['file'];
if (!is_file($path)) {
    http_response_code(404);
    exit('The document file is missing.');
}

$downloadName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $row['original_name'] ?: $row['file']);
header('Content-Type: ' . $row['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);