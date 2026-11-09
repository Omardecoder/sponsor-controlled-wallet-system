<?php
// Backend/password_reset.php
// Helpers for "Forgot password". A reset link carries a random token (64 hex characters).
// Only its SHA-256 hash is stored in password_resets, so a copy of the database can't be used to
// reset anyone's password. A token works once and expires 30 minutes after it is created.
// Usage:  require_once __DIR__ . '/password_reset.php';

const RESET_MINUTES      = 30;
const RESET_MAX_PER_HOUR = 3;   // reset emails per account per hour (stops someone flooding an inbox)

/** Creates a new token for the user (older unused ones stop working) and returns the plain token. */
function create_reset(mysqli $conn, int $userId, string $ip): string
{
    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);

    $stmt = $conn->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at, ip) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . RESET_MINUTES . ' MINUTE), ?)'
    );
    $stmt->bind_param('iss', $userId, $hash, $ip);
    $stmt->execute();
    $stmt->close();
    return $token;
}

/** The valid (unused, not expired) reset row for a plain token, with the user's details, or null. */
function find_reset(mysqli $conn, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $hash = hash('sha256', $token);
    try {
        $stmt = $conn->prepare(
            'SELECT r.id, r.user_id, u.full_name, u.email
             FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()'
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;   // table not created yet (Step 26 SQL not run)
    }
}

/** How many reset emails this user was sent in the last hour. */
function recent_resets(mysqli $conn, int $userId): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM password_resets WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $n = (int) $stmt->get_result()->fetch_assoc()['n'];
    $stmt->close();
    return $n;
}

/** The base address for links in emails: APP_URL from mail_config.php (never taken from the request). */
function app_url(): string
{
    return defined('APP_URL') ? rtrim(APP_URL, '/') : 'http://localhost/sponsor-controlled-wallet-system';
}