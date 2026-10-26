<?php
// Backend/sponsorship.php
// Helpers for "a student can have several sponsors, a sponsor can have several students".
//
// A sponsorship links ONE sponsor to ONE student and stores the pledge
// (the most that sponsor promises to give). Status:
//   pending  - the sponsor asked; the student has not answered yet
//   active   - the student accepted: the sponsor sees the wallet, approves budgets and sends money
//   declined - the student said no
//   ended    - finished (kept for the history)
//
// The student still has ONE wallet. All active sponsors pay into it and all of them can see it.
// Usage:  require_once __DIR__ . '/sponsorship.php';

require_once __DIR__ . '/notify.php';

/**
 * The student's sponsors with their pledge and how much each has actually sent.
 * $activeOnly = false also returns pending/declined/ended ones (for history screens).
 */
function student_sponsors(mysqli $conn, int $studentId, bool $activeOnly = true): array
{
    $sql = "SELECT sp.id, sp.sponsor_id, sp.pledge, sp.status, sp.message, sp.created_at,
                   u.full_name, u.email, u.phone,
                   (SELECT COALESCE(SUM(t.amount), 0)
                      FROM transactions t JOIN wallets w ON w.id = t.wallet_id
                     WHERE w.student_id = sp.student_id AND t.type = 'funding' AND t.created_by = sp.sponsor_id) AS funded
            FROM sponsorships sp
            JOIN users u ON u.id = sp.sponsor_id
            WHERE sp.student_id = ?" . ($activeOnly ? " AND sp.status = 'active'" : '') . "
            ORDER BY sp.created_at, sp.id";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Total of all active pledges for a student = the most their budget can be. */
function pledge_total(mysqli $conn, int $studentId): float
{
    $stmt = $conn->prepare("SELECT COALESCE(SUM(pledge), 0) AS total FROM sponsorships WHERE student_id = ? AND status = 'active'");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $total = (float) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $total;
}

/**
 * The wallet, if this sponsor ACTIVELY sponsors its student. Returns null otherwise.
 * Includes the student's name and this sponsor's pledge.
 */
function sponsor_wallet(mysqli $conn, int $sponsorId, int $walletId): ?array
{
    $stmt = $conn->prepare(
        "SELECT w.id, w.student_id, w.balance, w.status, u.full_name AS student_name, sp.pledge
         FROM wallets w
         JOIN users u ON u.id = w.student_id
         JOIN sponsorships sp ON sp.student_id = w.student_id AND sp.sponsor_id = ? AND sp.status = 'active'
         WHERE w.id = ?"
    );
    $stmt->bind_param('ii', $sponsorId, $walletId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * How much of this sponsor's pledge is already used on this wallet:
 * confirmed M-Pesa funding + payments still waiting for confirmation (last 30 minutes).
 */
function sponsor_pledge_used(mysqli $conn, int $sponsorId, int $walletId): float
{
    $stmt = $conn->prepare(
        "SELECT
           (SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE wallet_id = ? AND type = 'funding' AND created_by = ?)
         + (SELECT COALESCE(SUM(amount), 0) FROM mpesa_payments
             WHERE wallet_id = ? AND sponsor_id = ? AND status = 'pending' AND created_at >= NOW() - INTERVAL 30 MINUTE) AS used"
    );
    $stmt->bind_param('iiii', $walletId, $sponsorId, $walletId, $sponsorId);
    $stmt->execute();
    $used = (float) $stmt->get_result()->fetch_assoc()['used'];
    $stmt->close();
    return $used;
}

/** Send the same alert to every active sponsor of a student (optionally skipping one). */
function notify_sponsors(mysqli $conn, int $studentId, string $type, string $title, string $body = '',
                         ?string $link = null, ?string $dedupeKey = null, ?int $exceptSponsorId = null): void
{
    try {
        foreach (student_sponsors($conn, $studentId) as $s) {
            if ((int) $s['sponsor_id'] === $exceptSponsorId) {
                continue;
            }
            notify($conn, (int) $s['sponsor_id'], $type, $title, $body, $link, $dedupeKey);
        }
    } catch (Throwable $e) {
        error_log('notify_sponsors() failed: ' . $e->getMessage());   // alerts never break the real action
    }
}

/**
 * Who has approved a budget proposal so far.
 * Returns ['sponsors' => [...active sponsors, each with 'decision' (approved/changes/null)],
 *          'approved' => n, 'needed' => n, 'complete' => bool]
 */
function proposal_approvals(mysqli $conn, int $proposalId, int $studentId): array
{
    $stmt = $conn->prepare(
        "SELECT sp.sponsor_id, u.full_name, r.decision, r.feedback, r.created_at AS decided_at
         FROM sponsorships sp
         JOIN users u ON u.id = sp.sponsor_id
         LEFT JOIN proposal_reviews r ON r.proposal_id = ? AND r.sponsor_id = sp.sponsor_id
         WHERE sp.student_id = ? AND sp.status = 'active'
         ORDER BY sp.created_at, sp.id"
    );
    $stmt->bind_param('ii', $proposalId, $studentId);
    $stmt->execute();
    $sponsors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $approved = count(array_filter($sponsors, fn($s) => $s['decision'] === 'approved'));
    return [
        'sponsors' => $sponsors,
        'approved' => $approved,
        'needed'   => count($sponsors),
        'complete' => $sponsors && $approved === count($sponsors),
    ];
}