<?php
// Backend/student_docs.php
// Supporting documents and achievements on a student's profile, shown to well-wishers who browse
// listed students and to the student's own sponsors.
// Files use the same safe rules as program applications (programs.php): JPG, PNG or PDF checked by
// their real content, max 5 MB, random file names in Uploads/documents (the browser can't open that
// folder directly; files are shown through view_student_document.php after a permission check).
// Usage:  require_once __DIR__ . '/student_docs.php';

require_once __DIR__ . '/programs.php';   // DOC_MAX_BYTES, DOC_MIME, documents_dir()

const STUDENT_DOC_TYPES = [
    'fee_structure' => 'Fee structure / fee statement',
    'admission'     => 'Admission letter',
    'transcript'    => 'Latest results / transcript',
    'certificate'   => 'Award or certificate',
    'recommendation'=> 'Recommendation letter',
    'need'          => 'Proof of financial need',
    'other'         => 'Other',
];
const STUDENT_DOC_MAX      = 6;    // documents per student
const ACHIEVEMENTS_MAX     = 8;    // lines
const ACHIEVEMENT_MAX_LEN  = 90;   // characters per line

/** The student's documents (newest first). Empty if the Step 30 SQL has not been run. */
function student_documents(mysqli $conn, int $studentId): array
{
    try {
        $stmt = $conn->prepare('SELECT id, doc_type, original_name, mime, created_at FROM student_documents WHERE student_id = ? ORDER BY created_at DESC, id DESC');
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/** Achievements text (one per line) -> clean list. */
function achievements_list(?string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', (string) $text) as $line) {
        $line = trim(preg_replace('/\s+/', ' ', ltrim($line, "-•* \t")));
        if ($line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/** Achievements of one student (list). */
function student_achievements(mysqli $conn, int $studentId): array
{
    try {
        $stmt = $conn->prepare('SELECT achievements FROM users WHERE id = ?');
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return achievements_list($row['achievements'] ?? null);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * May this user open this student's documents?
 * The student, the admin, an ACTIVE sponsor of the student, or a well-wisher while the student is listed.
 */
function can_view_student_docs(mysqli $conn, int $userId, string $role, int $studentId): bool
{
    if ($role === 'admin') {
        return true;
    }
    if ($role === 'student') {
        return $userId === $studentId;
    }
    if ($role !== 'sponsor') {
        return false;
    }
    $stmt = $conn->prepare("SELECT 1 FROM sponsorships WHERE student_id = ? AND sponsor_id = ? AND status = 'active'");
    $stmt->bind_param('ii', $studentId, $userId);
    $stmt->execute();
    $isSponsor = (bool) $stmt->get_result()->fetch_row();
    $stmt->close();
    if ($isSponsor) {
        return true;
    }
    $stmt = $conn->prepare("SELECT sponsor_type FROM users WHERE id = ? AND role = 'sponsor'");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $type = $stmt->get_result()->fetch_assoc()['sponsor_type'] ?? null;
    $stmt->close();
    if (($type ?? 'individual') !== 'individual') {
        return false;
    }
    $stmt = $conn->prepare("SELECT listed FROM users WHERE id = ? AND role = 'student'");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $listed = (int) ($stmt->get_result()->fetch_assoc()['listed'] ?? 0);
    $stmt->close();
    return $listed === 1;
}