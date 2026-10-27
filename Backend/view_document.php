<?php
// Backend/view_document.php?id=DOCUMENT_ID
// Shows an application document. Allowed: the student who uploaded it, the organization
// that runs the program, and the admin. Everyone else gets "not found".

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/programs.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role   = $_SESSION['role'] ?? '';
if ($userId === 0) {
    header('Location: ../Frontend/login.php');
    exit;
}

$docId = (int) ($_GET['id'] ?? 0);
$stmt = $conn->prepare(
    'SELECT d.file, d.original_name, d.mime, a.student_id, pr.org_id
     FROM application_documents d
     JOIN applications a ON a.id = d.application_id
     JOIN programs pr    ON pr.id = a.program_id
     WHERE d.id = ?'
);
$stmt->bind_param('i', $docId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$allowed = $row && (
    ($role === 'student' && (int) $row['student_id'] === $userId) ||
    ($role === 'sponsor' && (int) $row['org_id'] === $userId) ||
    $role === 'admin'
);
if (!$allowed || !preg_match('/^[a-f0-9]{32}\.(jpg|png|pdf)$/', $row['file'])) {
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