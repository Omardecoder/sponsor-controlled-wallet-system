<?php
// Backend/family_link.php
// The STUDENT links a family member by email (no request for the parent to send).
//   - The email belongs to a Family account  -> linked straight away (active). The family member gets an
//     alert and can remove the link if it is not them.
//   - No account with that email yet         -> an invitation email with a sign-up link (14 days).
//     When they register from that link, they are linked automatically.
//   - The email belongs to another kind of account (student, well-wisher, organization) -> refused.
// Only the SHA-256 hash of an invitation token is stored (family_invites.token_hash).
// Usage:  require_once __DIR__ . '/family_link.php';

require_once __DIR__ . '/sponsorship.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/password_reset.php';   // app_url()

const INVITE_DAYS          = 14;
const INVITE_MAX_PER_DAY   = 5;    // invitations a student can send per day
const FAMILY_MAX_LINKS     = 6;    // family members per student

/** Gives the student a wallet if they have none yet (first sponsor = $sponsorId). Call inside a transaction. */
function ensure_wallet(mysqli $conn, int $studentId, ?int $sponsorId): void
{
    $stmt = $conn->prepare('SELECT id FROM wallets WHERE student_id = ? FOR UPDATE');
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $has = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$has) {
        $stmt = $conn->prepare('INSERT INTO wallets (student_id, sponsor_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $studentId, $sponsorId);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Makes an ACTIVE family sponsorship that the student created (linked_by = 'student').
 * Returns 'linked', 'already' (already active) or 'removed' (this person removed or declined the link before).
 */
function make_family_link(mysqli $conn, int $studentId, int $familyId, string $relationship): string
{
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT id, status FROM sponsorships WHERE student_id = ? AND sponsor_id = ? FOR UPDATE');
        $stmt->bind_param('ii', $studentId, $familyId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing && $existing['status'] === 'active') {
            $conn->rollback();
            return 'already';
        }
        if ($existing && $existing['status'] === 'declined') {
            $conn->rollback();
            return 'removed';
        }

        if ($existing) {
            // pending (they had asked you) or ended: make it active
            $id = (int) $existing['id'];
            $stmt = $conn->prepare(
                "UPDATE sponsorships SET status = 'active', relationship = ?, is_anonymous = 0, linked_by = 'student',
                        responded_at = NOW() WHERE id = ?"
            );
            $stmt->bind_param('si', $relationship, $id);
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO sponsorships (student_id, sponsor_id, pledge, status, relationship, is_anonymous, linked_by, responded_at)
                 VALUES (?, ?, 0, 'active', ?, 0, 'student', NOW())"
            );
            $stmt->bind_param('iis', $studentId, $familyId, $relationship);
        }
        $stmt->execute();
        $stmt->close();

        ensure_wallet($conn, $studentId, $familyId);
        $conn->commit();
        return 'linked';
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        throw $e;
    }
}

/** Number of family members linked (active) or invited (open invitation) for this student. */
function family_count(mysqli $conn, int $studentId): int
{
    $stmt = $conn->prepare(
        "SELECT (SELECT COUNT(*) FROM sponsorships sp JOIN users u ON u.id = sp.sponsor_id
                  WHERE sp.student_id = ? AND sp.status = 'active' AND u.sponsor_type = 'family')
              + (SELECT COUNT(*) FROM family_invites WHERE student_id = ? AND status = 'open' AND expires_at > NOW()) AS n"
    );
    $stmt->bind_param('ii', $studentId, $studentId);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n;
}

/**
 * The student links a family member by email. Never throws.
 * Returns ['type' => 'success'|'error', 'message' => '...', 'invite_link' => string|null].
 * invite_link is filled when the invitation could not be emailed, so the student can send it themselves.
 */
function link_family(mysqli $conn, int $studentId, string $studentName, string $studentEmail,
                     string $familyEmail, string $relationship): array
{
    $familyEmail = strtolower(trim($familyEmail));
    $fail = fn(string $m) => ['type' => 'error', 'message' => $m, 'invite_link' => null];

    if (!filter_var($familyEmail, FILTER_VALIDATE_EMAIL) || strlen($familyEmail) > 150) {
        return $fail('Enter your family member\'s email address.');
    }
    if ($familyEmail === strtolower($studentEmail)) {
        return $fail('Enter your family member\'s email, not your own.');
    }
    if (!isset(RELATIONSHIPS[$relationship])) {
        return $fail('Choose how this person is related to you.');
    }
    $relLabel = strtolower(RELATIONSHIPS[$relationship]);

    try {
        if (family_count($conn, $studentId) >= FAMILY_MAX_LINKS) {
            return $fail('You can link up to ' . FAMILY_MAX_LINKS . ' family members.');
        }

        $stmt = $conn->prepare('SELECT id, full_name, role, sponsor_type FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $familyEmail);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // ---- Already has an account ----
        if ($user) {
            if ($user['role'] !== 'sponsor' || $user['sponsor_type'] !== 'family') {
                return $fail('That email belongs to an SS-Wallet account that is not a Family account, so it can\'t be linked as family.');
            }
            $familyId = (int) $user['id'];
            $result   = make_family_link($conn, $studentId, $familyId, $relationship);
            if ($result === 'already') {
                return $fail($user['full_name'] . ' is already linked to you.');
            }
            if ($result === 'removed') {
                return $fail('This person removed or declined the link before. They can send you a request from their dashboard instead.');
            }
            notify(
                $conn, $familyId, 'family_linked',
                $studentName . ' linked you as their ' . $relLabel,
                'You can now see their wallet and fund the budgets they send. Not you, or not related? Remove the link on your dashboard.',
                'sponsor-dashboard.php#students'
            );
            return ['type' => 'success', 'message' => $user['full_name'] . ' is now linked as your ' . $relLabel . '. They can fund the budgets you send.', 'invite_link' => null];
        }

        // ---- No account yet: invitation ----
        $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM family_invites WHERE student_id = ? AND created_at > NOW() - INTERVAL 1 DAY');
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $today = (int) $stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
        if ($today >= INVITE_MAX_PER_DAY) {
            return $fail('You have sent ' . INVITE_MAX_PER_DAY . ' invitations today. Try again tomorrow.');
        }

        // A new invitation to the same email replaces the old one
        $stmt = $conn->prepare("UPDATE family_invites SET status = 'cancelled' WHERE student_id = ? AND email = ? AND status = 'open'");
        $stmt->bind_param('is', $studentId, $familyEmail);
        $stmt->execute();
        $stmt->close();

        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $stmt = $conn->prepare(
            'INSERT INTO family_invites (student_id, email, relationship, token_hash, expires_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . INVITE_DAYS . ' DAY))'
        );
        $stmt->bind_param('isss', $studentId, $familyEmail, $relationship, $hash);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('link_family failed: ' . $e->getMessage());
        return $fail('Something went wrong. Nothing was changed. Please try again.');
    }

    $link  = app_url() . '/Frontend/register.php?invite=' . $token;
    $first = htmlspecialchars(preg_split('/\s+/', trim($studentName))[0]);
    $html  = mail_layout($studentName . ' invited you to SS-Wallet', [
        'Hello,',
        '<strong>' . htmlspecialchars($studentName) . '</strong> added you as their <strong>' . htmlspecialchars($relLabel) . '</strong> on SS-Wallet, a wallet where family and sponsors fund a student\'s budget and can see how the money is spent.',
        'Create your Family account with this email address and you will be linked to ' . $first . ' automatically. The link works for ' . INVITE_DAYS . ' days.',
        'If you don\'t know ' . $first . ', ignore this email.',
    ], 'Create my account', $link);
    $text = "$studentName added you as their $relLabel on SS-Wallet.\n\nCreate your Family account here (valid " . INVITE_DAYS . " days):\n$link\n\nIf you don't know them, ignore this email.";
    $sent = send_mail($familyEmail, '', $studentName . ' invited you to SS-Wallet', $html, $text);

    return $sent
        ? ['type' => 'success', 'message' => 'Invitation emailed to ' . $familyEmail . '. When they create their account from it, they are linked to you automatically.', 'invite_link' => null]
        : ['type' => 'success', 'message' => 'Invitation created, but the email could not be sent. Send them this link yourself (by WhatsApp or SMS):', 'invite_link' => $link];
}

/** The open, unexpired invitation for a plain token (with the student's name), or null. */
function find_invite(mysqli $conn, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    try {
        $stmt = $conn->prepare(
            "SELECT i.id, i.student_id, i.email, i.relationship, u.full_name AS student_name
             FROM family_invites i JOIN users u ON u.id = i.student_id
             WHERE i.token_hash = ? AND i.status = 'open' AND i.expires_at > NOW()"
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;   // Step 30 SQL not run yet
    }
}

/** After a family member registers from an invitation: link them and close the invitation. Returns the student's name or null. */
function accept_invite(mysqli $conn, array $invite, int $familyId, string $familyName): ?string
{
    try {
        $result = make_family_link($conn, (int) $invite['student_id'], $familyId, $invite['relationship']);
        $id = (int) $invite['id'];
        $stmt = $conn->prepare("UPDATE family_invites SET status = 'accepted', accepted_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        if ($result !== 'linked') {
            return null;
        }
        notify(
            $conn, (int) $invite['student_id'], 'family_joined',
            $familyName . ' joined and is now linked as your ' . strtolower(RELATIONSHIPS[$invite['relationship']] ?? 'family'),
            'They can fund the budgets you send.',
            'student-profile.php#family'
        );
        return $invite['student_name'];
    } catch (Throwable $e) {
        error_log('accept_invite failed: ' . $e->getMessage());
        return null;
    }
}

/** Family members linked to the student, and open invitations, for the My Profile page. */
function student_family(mysqli $conn, int $studentId): array
{
    try {
        $stmt = $conn->prepare(
            "SELECT sp.id, u.full_name, u.email, sp.relationship, sp.linked_by, sp.responded_at
             FROM sponsorships sp JOIN users u ON u.id = sp.sponsor_id
             WHERE sp.student_id = ? AND sp.status = 'active' AND u.sponsor_type = 'family'
             ORDER BY u.full_name"
        );
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $linked = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $conn->prepare(
            "SELECT id, email, relationship, created_at, expires_at FROM family_invites
             WHERE student_id = ? AND status = 'open' AND expires_at > NOW() ORDER BY created_at DESC"
        );
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $invites = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return ['ready' => true, 'linked' => $linked, 'invites' => $invites];
    } catch (Throwable $e) {
        return ['ready' => false, 'linked' => [], 'invites' => []];
    }
}