<?php
// Frontend/partials/sponsor-sidebar.php
// The sponsor sidebar, shared by every sponsor page.
//
// Before including it, a page sets which link is highlighted:
//     $active = 'dashboard';   // or 'alerts', 'students', 'send', 'proposals', 'funding', 'spending', 'reports',
//                              //    'programs', 'applications' (organizations only)
//
// It also sets $pendingCount (proposals waiting for this sponsor's decision), $alertCount (unread alerts)
// and loads sw_icon() from icons.php, so the page can use them after the include.

require_once __DIR__ . '/../../Backend/notify.php';

$active = $active ?? '';

// Proposals waiting for THIS sponsor's decision (shown as a badge):
// pending, on a student they actively sponsor, and not yet reviewed by them
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS n
     FROM budget_proposals p
     JOIN wallets w ON w.id = p.wallet_id
     JOIN sponsorships sp ON sp.student_id = w.student_id AND sp.sponsor_id = ? AND sp.status = 'active'
     LEFT JOIN proposal_reviews r ON r.proposal_id = p.id AND r.sponsor_id = sp.sponsor_id
     WHERE p.status = 'pending' AND r.id IS NULL"
);
$sidebarSponsorId = (int) $_SESSION['user_id'];
$stmt->bind_param('i', $sidebarSponsorId);
$stmt->execute();
$pendingCount = (int) $stmt->get_result()->fetch_assoc()['n'];
$stmt->close();
$alertCount = unread_alerts($conn, $sidebarSponsorId);

require_once __DIR__ . '/icons.php';   // sw_icon()

// Organizations (sponsor_type = 'organization') also get Programs and Applications
$sidebarIsOrg = false;
$newApplications = 0;
try {
    $stmt = $conn->prepare("SELECT sponsor_type FROM users WHERE id = ?");
    $stmt->bind_param('i', $sidebarSponsorId);
    $stmt->execute();
    $sidebarIsOrg = ($stmt->get_result()->fetch_assoc()['sponsor_type'] ?? '') === 'organization';
    $stmt->close();
    if ($sidebarIsOrg) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS n FROM applications a JOIN programs pr ON pr.id = a.program_id
             WHERE pr.org_id = ? AND a.status = 'submitted'"
        );
        $stmt->bind_param('i', $sidebarSponsorId);
        $stmt->execute();
        $newApplications = (int) $stmt->get_result()->fetch_assoc()['n'];
        $stmt->close();
    }
} catch (Throwable $e) {
    // Step 22a not run yet: behave like before
}

// [key, label, link, icon]. A null link = page not built yet ("Soon").
$sidebarLinks = [
    ['dashboard', 'Dashboard',        'sponsor-dashboard.php',              'dashboard'],
    ['alerts',    'Alerts',           'alerts.php',                         'bell'],
    ['students',  'My Students',      'sponsor-dashboard.php#students',     'students'],
    ...($sidebarIsOrg ? [
        ['programs',     'Programs',     'org-programs.php',     'award'],
        ['applications', 'Applications', 'org-applications.php', 'inbox'],
    ] : []),
    ['send',      'Send Funds',       'sponsor-dashboard.php#send-funds',   'send'],
    ['proposals', 'Budget Proposals', 'sponsor-proposals.php',              'proposals'],
    ['funding',   'Funding History',  'sponsor-dashboard.php#transactions', 'history'],
    ['spending',  'Spending History', 'sponsor-spending.php',               'receipt'],
    ['reports',   'Reports',          'sponsor-reports.php',                'reports'],
];
?>
<aside class="sidebar">
  <div class="brand">
    <div class="brand-mark"><?= sw_icon('wallet') ?></div>
    <div>
      <div class="brand-name">SponsorWallet</div>
      <div class="brand-role"><?= $sidebarIsOrg ? 'Organization' : 'Sponsor' ?></div>
    </div>
  </div>

  <nav aria-label="Main">
    <ul class="nav">
      <?php foreach ($sidebarLinks as [$key, $label, $href, $icon]): ?>
        <li>
          <?php if ($href === null): ?>
            <span class="soon"><?= sw_icon($icon) ?><?= $label ?><span class="tag">Soon</span></span>
          <?php else: ?>
            <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>>
              <?= sw_icon($icon) ?><?= $label ?>
              <?php $badge = ['proposals' => $pendingCount, 'applications' => $newApplications, 'alerts' => ($active === 'alerts' ? 0 : $alertCount)][$key] ?? 0; ?>
              <?php if ($badge > 0): ?><span class="count"><?= $badge ?></span><?php endif; ?>
            </a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <div class="sidebar-foot">
    <ul class="nav">
      <li><span class="soon"><?= sw_icon('profile') ?>Profile<span class="tag">Soon</span></span></li>
      <li><a href="../Backend/logout.php"><?= sw_icon('logout') ?>Log out</a></li>
    </ul>
  </div>
</aside>