<?php
// Backend/notify.php
// Creates in-app alerts. Usage:
//   require_once __DIR__ . '/notify.php';
//   notify($conn, $userId, 'funds_received', 'KSh 500 received', 'From Ali via M-Pesa', 'student-dashboard.php');
//
// $dedupeKey (optional): the same user never gets two alerts with the same key
// (used for "budget line 80% used" so it is sent once, not after every expense).
// An alert must never break the real action, so errors are only logged.

function notify(mysqli $conn, int $userId, string $type, string $title, string $body = '', ?string $link = null, ?string $dedupeKey = null): void
{
    try {
        $title = mb_substr($title, 0, 150);
        $body  = $body !== '' ? mb_substr($body, 0, 500) : null;
        $stmt  = $conn->prepare(
            'INSERT IGNORE INTO notifications (user_id, type, title, body, link, dedupe_key) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssss', $userId, $type, $title, $body, $link, $dedupeKey);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('notify() failed: ' . $e->getMessage());
    }
}

/** Number of unread alerts for the sidebar badge. */
function unread_alerts(mysqli $conn, int $userId): int
{
    try {
        $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $n = (int) $stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
        return $n;
    } catch (Throwable $e) {
        return 0;   // e.g. table not created yet
    }
}