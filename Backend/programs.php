<?php
// Backend/programs.php
// Shared settings and helpers for organizations, sponsorship programs and applications.
//
// Flow: an ORGANIZATION (a sponsor account with sponsor_type = 'organization', verified by the admin)
// posts a program -> students apply with documents -> the organization accepts an application ->
// a sponsorship is created automatically (pledge = the program amount) and the student gets a wallet.
// Usage:  require_once __DIR__ . '/programs.php';

// Documents an organization can ask for. code => label
const DOC_TYPES = [
    'national_id'    => 'National ID or passport',
    'admission'      => 'Admission letter',
    'fee_structure'  => 'Fee structure',
    'transcript'     => 'Latest results / transcript',
    'recommendation' => 'Recommendation letter',
    'need'           => 'Proof of financial need',
];

// Uploaded documents: JPG, PNG or PDF, up to 5 MB each (checked by real content, not file name)
const DOC_MAX_BYTES = 5 * 1024 * 1024;
const DOC_MIME = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'application/pdf' => 'pdf',
];

/** Folder for application documents. Kept outside the public pages (Uploads/.htaccess denies access). */
function documents_dir(): string
{
    return __DIR__ . '/../Uploads/documents';
}

/** The sponsor's type and verification. Returns ['sponsor_type' => ..., 'org_verified' => 0/1] or null. */
function sponsor_profile(mysqli $conn, int $userId): ?array
{
    try {
        $stmt = $conn->prepare("SELECT sponsor_type, org_verified, about FROM users WHERE id = ? AND role = 'sponsor'");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;   // columns not added yet (Step 22a not run)
    }
}

function is_organization(mysqli $conn, int $userId): bool
{
    $p = sponsor_profile($conn, $userId);
    return $p && $p['sponsor_type'] === 'organization';
}

/** The required document codes of a program, as an array. */
function program_docs(string $requiredDocs): array
{
    return array_values(array_filter(explode(',', $requiredDocs), fn($c) => isset(DOC_TYPES[$c])));
}

/** How many students a program has already accepted. */
function program_accepted(mysqli $conn, int $programId): int
{
    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM applications WHERE program_id = ? AND status = 'accepted'");
    $stmt->bind_param('i', $programId);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n;
}