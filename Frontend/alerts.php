<?php
// Frontend/alerts.php
// Alerts for the logged-in user (sponsor or student).
// New (unread) alerts are highlighted, then marked as read once this page has been shown.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/notify.php';
require_once __DIR__ . '/partials/icons.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role   = $_SESSION['role'] ?? '';
if ($userId === 0 || !in_array($role, ['sponsor', 'student'], true)) {
    header('Location: login.php');
    exit;
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Latest 50 alerts
$stmt = $conn->prepare(
    'SELECT id, type, title, body, link, is_read, created_at
     FROM notifications WHERE user_id = ?
     ORDER BY created_at DESC, id DESC LIMIT 50'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$alerts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Only allow links to our own pages (e.g. "student-budget.php" or "record-spending.php?view=history#history")
function safe_link(?string $link): ?string
{
    return ($link && preg_match('/^[a-z0-9\-]+\.php([?#][A-Za-z0-9=&_#\-]*)?$/', $link)) ? $link : null;
}

// Icon and colour for each kind of alert
function alert_style(string $type): array
{
    return match (true) {
        str_starts_with($type, 'proposal')     => ['proposals', 'tone-info'],
        $type === 'budget_approved'            => ['proposals', 'tone-good'],
        $type === 'budget_partly_approved'     => ['proposals', 'tone-info'],
        $type === 'sponsorship_declined'       => ['students',  'tone-warn'],
        str_starts_with($type, 'sponsorship'),
        $type === 'co_sponsor_joined',
        $type === 'pledge_changed'             => ['students',  'tone-info'],
        $type === 'budget_changes'             => ['pen',       'tone-warn'],
        str_starts_with($type, 'funds')        => ['received',  'tone-good'],
        $type === 'payment_failed'             => ['send',      'tone-bad'],
        $type === 'budget_100'                 => ['piggy',     'tone-bad'],
        str_starts_with($type, 'budget')       => ['piggy',     'tone-warn'],
        str_starts_with($type, 'receipt')      => ['receipt',   'tone-warn'],
        default                                => ['bell',      'tone-info'],
    };
}

/** "5 min ago", "3 h ago", "12 Oct 2026, 09:40" */
function when(string $ts): string
{
    $diff = time() - strtotime($ts);
    if ($diff < 60)        return 'just now';
    if ($diff < 3600)      return floor($diff / 60) . ' min ago';
    if ($diff < 86400)     return floor($diff / 3600) . ' h ago';
    return date('j M Y, H:i', strtotime($ts));
}

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));

$active = 'alerts';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Alerts | SponsorWallet</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . ($role === 'sponsor' ? '/partials/sponsor-sidebar.php' : '/partials/student-sidebar.php'); ?>

  <div class="content">
    <header class="topbar">
      <h1>Alerts</h1>
      <div class="topbar-right">
        <div class="profile">
          <div class="avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></div>
          <div class="profile-text">
            <div class="profile-name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
            <div class="profile-role"><?= $role === 'sponsor' ? 'Sponsor' : 'Student' ?></div>
          </div>
        </div>
      </div>
    </header>

    <main class="page">
      <div class="page-head">
        <div>
          <h2>Alerts</h2>
          <p class="muted"><?= $alertCount > 0 ? $alertCount . ' new since your last visit.' : 'You\'re all caught up.' ?></p>
        </div>
      </div>

      <section class="card">
        <?php if (!$alerts): ?>
          <p class="empty">No alerts yet. You'll see updates here, such as budget decisions, payments and budget limits.</p>
        <?php else: ?>
          <ul class="alert-list">
            <?php foreach ($alerts as $a):
                [$icon, $tone] = alert_style($a['type']);
                $link = safe_link($a['link']);
            ?>
              <li class="alert-item <?= $a['is_read'] ? '' : 'unread' ?>">
                <span class="alert-icon <?= $tone ?>"><?= sw_icon($icon) ?></span>
                <div class="alert-text">
                  <div class="alert-title">
                    <?php if ($link): ?><a href="<?= htmlspecialchars($link) ?>"><?= htmlspecialchars($a['title']) ?></a><?php else: ?><?= htmlspecialchars($a['title']) ?><?php endif; ?>
                    <?php if (!$a['is_read']): ?><span class="pill amber">New</span><?php endif; ?>
                  </div>
                  <?php if ($a['body']): ?><div class="muted small"><?= htmlspecialchars($a['body']) ?></div><?php endif; ?>
                </div>
                <time class="muted small" datetime="<?= htmlspecialchars($a['created_at']) ?>"><?= htmlspecialchars(when($a['created_at'])) ?></time>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    </main>
  </div>
</div>
</body>
</html>
<?php
// The user has now seen these alerts: mark them as read (after the page is shown, so "New" is visible once)
$stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->close();