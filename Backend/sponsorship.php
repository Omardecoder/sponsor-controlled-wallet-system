<?php
// Backend/sponsorship.php
// Helpers for "a student can have several funders (sponsors), a sponsor can have several students".
//
// A sponsorship links ONE sponsor to ONE student. Status:
//   pending  - the sponsor asked; the student has not answered yet
//   active   - linked: the sponsor sees the wallet, funds budgets and sends money
//   declined - the student said no
//   ended    - finished (kept for the history)
// sponsorships.pledge is an optional LIMIT per budget (0 = no limit). Organizations get the
// program amount as their limit; individual sponsors have no limit.
//
// How a budget gets funded:
//   the student submits a budget -> each funder commits a share of it (a % or a fixed amount,
//   saved in proposal_reviews.commit_amount) -> when the commitments reach 100% the budget is active.
//   If it stays below 100%, the STUDENT decides: wait, accept the partial funding (and choose which
//   lines to cut), or cancel the proposal.
// Usage:  require_once __DIR__ . '/sponsorship.php';

require_once __DIR__ . '/notify.php';

// Sponsor modes (users.sponsor_type). 'individual' is shown as "Well-wisher".
const SPONSOR_TYPE_LABELS = [
    'family'       => 'Family / guardian',
    'individual'   => 'Well-wisher',
    'organization' => 'Organization',
];

// How a family sponsor is related to the student (sponsorships.relationship)
const RELATIONSHIPS = [
    'mother'   => 'Mother',
    'father'   => 'Father',
    'guardian' => 'Guardian',
    'sibling'  => 'Brother / sister',
    'relative' => 'Other relative',
];

const ANONYMOUS_LABEL = 'Anonymous well-wisher';

function sponsor_type_label(?string $type): string
{
    return SPONSOR_TYPE_LABELS[$type ?? 'individual'] ?? 'Sponsor';
}

/**
 * The name a STUDENT (or another funder) may see for a sponsor.
 * Anonymous well-wishers are never named; family sponsors show their relationship, e.g. "Jane Doe (Mother)".
 * $row needs full_name, and may have is_anonymous and relationship.
 */
function funder_label(array $row): string
{
    if (!empty($row['is_anonymous'])) {
        return ANONYMOUS_LABEL;
    }
    $rel = $row['relationship'] ?? null;
    return $row['full_name'] . ($rel && isset(RELATIONSHIPS[$rel]) ? ' (' . RELATIONSHIPS[$rel] . ')' : '');
}

/** True if this sponsor is anonymous to this student. */
function is_anonymous_to(mysqli $conn, int $sponsorId, int $studentId): bool
{
    try {
        $stmt = $conn->prepare('SELECT is_anonymous FROM sponsorships WHERE sponsor_id = ? AND student_id = ?');
        $stmt->bind_param('ii', $sponsorId, $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row && (int) $row['is_anonymous'] === 1;
    } catch (Throwable $e) {
        return false;   // column not added yet (Step 28 SQL not run)
    }
}

/** The sponsor's name as the student may see it ("Anonymous well-wisher" when anonymous). */
function sponsor_name_for_student(mysqli $conn, int $sponsorId, int $studentId, string $realName): string
{
    return is_anonymous_to($conn, $sponsorId, $studentId) ? ANONYMOUS_LABEL : $realName;
}

/**
 * Hide an anonymous sponsor's identity in a row before it is shown:
 * full_name becomes the safe label, email and phone are removed. The real name is kept in real_name.
 */
function mask_funder(array $row): array
{
    $row['real_name'] = $row['full_name'];
    $row['full_name'] = funder_label($row);
    if (!empty($row['is_anonymous'])) {
        $row['email'] = '';
        $row['phone'] = '';
    }
    return $row;
}

/**
 * The student's sponsors, with how much each has sent in total.
 * $activeOnly = false also returns pending/declined/ended ones (for history screens).
 */
function student_sponsors(mysqli $conn, int $studentId, bool $activeOnly = true): array
{
    $sql = "SELECT sp.id, sp.sponsor_id, sp.pledge, sp.status, sp.message, sp.created_at,
                   sp.relationship, sp.is_anonymous, u.sponsor_type,
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
    return array_map('mask_funder', $rows);   // anonymous sponsors are never named
}

/**
 * The wallet, if this sponsor ACTIVELY sponsors its student. Returns null otherwise.
 * Includes the student's name and this sponsor's limit ("pledge", 0 = no limit).
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

/** Total of a budget proposal. */
function proposal_total(mysqli $conn, int $proposalId): float
{
    $stmt = $conn->prepare('SELECT COALESCE(SUM(proposed_amount), 0) AS total FROM budget_proposal_items WHERE proposal_id = ?');
    $stmt->bind_param('i', $proposalId);
    $stmt->execute();
    $total = (float) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    return $total;
}

/**
 * How far a budget proposal is funded.
 * Returns ['sponsors'  => active sponsors, each with decision (approved/changes/null), commit_amount, share (%)],
 *         'total'     => budget total, 'committed' => sum of commitments, 'remaining' => still uncovered,
 *         'percent'   => committed as % of total (0-100), 'complete' => fully covered]
 */
function proposal_funding(mysqli $conn, int $proposalId, int $studentId): array
{
    $stmt = $conn->prepare(
        "SELECT sp.sponsor_id, sp.pledge, sp.relationship, sp.is_anonymous, u.sponsor_type, u.full_name, r.decision, r.commit_amount, r.feedback, r.created_at AS decided_at
         FROM sponsorships sp
         JOIN users u ON u.id = sp.sponsor_id
         LEFT JOIN proposal_reviews r ON r.proposal_id = ? AND r.sponsor_id = sp.sponsor_id
         WHERE sp.student_id = ? AND sp.status = 'active'
         ORDER BY sp.created_at, sp.id"
    );
    $stmt->bind_param('ii', $proposalId, $studentId);
    $stmt->execute();
    $sponsors = array_map('mask_funder', $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    $total     = proposal_total($conn, $proposalId);
    $committed = 0.0;
    foreach ($sponsors as &$s) {
        $s['commit_amount'] = $s['decision'] === 'approved' ? (float) $s['commit_amount'] : 0.0;
        $s['share']         = $total > 0 ? round($s['commit_amount'] / $total * 100, 1) : 0;
        $committed         += $s['commit_amount'];
    }
    unset($s);
    $committed = round($committed, 2);

    return [
        'sponsors'  => $sponsors,
        'total'     => $total,
        'committed' => $committed,
        'remaining' => max(0, round($total - $committed, 2)),
        'percent'   => $total > 0 ? min(100, round($committed / $total * 100)) : 0,
        'complete'  => $total > 0 && $committed >= $total - 0.005,
    ];
}

/**
 * This sponsor's part of the student's CURRENT budget (the latest pending or approved proposal):
 * ['proposal_id', 'status', 'total', 'committed' (this sponsor), 'sent' (since that budget was submitted),
 *  'to_send' (still to send)] or null when there is no current budget or no commitment from this sponsor.
 */
function sponsor_current_share(mysqli $conn, int $sponsorId, int $walletId): ?array
{
    $stmt = $conn->prepare(
        "SELECT p.id, p.status, p.created_at, r.commit_amount
         FROM budget_proposals p
         LEFT JOIN proposal_reviews r ON r.proposal_id = p.id AND r.sponsor_id = ? AND r.decision = 'approved'
         WHERE p.wallet_id = ? AND p.status IN ('pending', 'approved')
         ORDER BY p.id DESC LIMIT 1"
    );
    $stmt->bind_param('ii', $sponsorId, $walletId);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$p || $p['commit_amount'] === null) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(amount), 0) AS sent FROM transactions
         WHERE wallet_id = ? AND type = 'funding' AND created_by = ? AND created_at >= ?"
    );
    $stmt->bind_param('iis', $walletId, $sponsorId, $p['created_at']);
    $stmt->execute();
    $sent = (float) $stmt->get_result()->fetch_assoc()['sent'];
    $stmt->close();

    $committed = (float) $p['commit_amount'];
    return [
        'proposal_id' => (int) $p['id'],
        'status'      => $p['status'],
        'total'       => proposal_total($conn, (int) $p['id']),
        'committed'   => $committed,
        'sent'        => $sent,
        'to_send'     => max(0, round($committed - $sent, 2)),
    ];
}

/**
 * Make a fully funded (or student-accepted) proposal the active budget:
 * copy its amounts into budget_allocations and mark it approved. Call inside a transaction.
 */
function activate_budget(mysqli $conn, int $proposalId, int $walletId, ?int $reviewerId, ?string $note): void
{
    $stmt = $conn->prepare('SELECT category, proposed_amount FROM budget_proposal_items WHERE proposal_id = ?');
    $stmt->bind_param('i', $proposalId);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // One row per wallet + category. If it already exists, its allocated amount is replaced; "spent" is kept.
    $stmt = $conn->prepare(
        'INSERT INTO budget_allocations (wallet_id, category, allocated, spent, updated_at)
         VALUES (?, ?, ?, 0, NOW())
         ON DUPLICATE KEY UPDATE allocated = VALUES(allocated), updated_at = NOW()'
    );
    $kept = [];
    foreach ($items as $it) {
        if ((float) $it['proposed_amount'] <= 0) {
            continue;
        }
        $stmt->bind_param('iss', $walletId, $it['category'], $it['proposed_amount']);
        $stmt->execute();
        $kept[] = $it['category'];
    }
    $stmt->close();

    // Lines from an older budget that are not in this one: remove them if nothing was spent,
    // otherwise keep the history with 0 allocated.
    $stmt = $conn->prepare('SELECT id, category, spent FROM budget_allocations WHERE wallet_id = ?');
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($existing as $row) {
        if (in_array($row['category'], $kept, true)) {
            continue;
        }
        $sql = (float) $row['spent'] > 0
            ? 'UPDATE budget_allocations SET allocated = 0, updated_at = NOW() WHERE id = ?'
            : 'DELETE FROM budget_allocations WHERE id = ?';
        $stmt = $conn->prepare($sql);
        $rowId = (int) $row['id'];
        $stmt->bind_param('i', $rowId);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $conn->prepare(
        "UPDATE budget_proposals SET status = 'approved', sponsor_feedback = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?"
    );
    $stmt->bind_param('sii', $note, $reviewerId, $proposalId);
    $stmt->execute();
    $stmt->close();
}