<?php
// Frontend/partials/sponsor-sidebar.php
// The sponsor sidebar, shared by every sponsor page.
//
// Before including it, a page sets which link is highlighted:
//     $active = 'dashboard';   // or 'students', 'send', 'proposals', 'funding', 'spending'
//
// It also sets $pendingCount (proposals waiting for this sponsor)
// and the sw_icon() function, so the page can use both after the include.

$active = $active ?? '';

// Proposals waiting for this sponsor (shown as a badge)
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS n
     FROM budget_proposals p
     JOIN wallets w ON w.id = p.wallet_id
     WHERE w.sponsor_id = ? AND p.status = 'pending'"
);
$sidebarSponsorId = (int) $_SESSION['user_id'];
$stmt->bind_param('i', $sidebarSponsorId);
$stmt->execute();
$pendingCount = (int) $stmt->get_result()->fetch_assoc()['n'];
$stmt->close();

if (!function_exists('sw_icon')) {
    // Small line icons (from the Lucide icon set)
    function sw_icon(string $name): string
    {
        $paths = [
            'wallet'    => '<path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-9a1 1 0 0 0-1-1H5a2 2 0 0 1-2-2z"/><circle cx="16.5" cy="14.5" r="1.2" fill="currentColor"/>',
            'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
            'students'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'send'      => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
            'proposals' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
            'history'   => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
            'receipt'   => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
            'reports'   => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
            'profile'   => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
            'bell'      => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
            'activity'  => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            'plus'      => '<circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="M12 8v8"/>',
        ];
        return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
             . ($paths[$name] ?? '') . '</svg>';
    }
}

// [key, label, link, icon]. A null link = page not built yet ("Soon").
$sidebarLinks = [
    ['dashboard', 'Dashboard',        'sponsor-dashboard.php',              'dashboard'],
    ['students',  'My Students',      'sponsor-dashboard.php#students',     'students'],
    ['send',      'Send Funds',       'sponsor-dashboard.php#send-funds',   'send'],
    ['proposals', 'Budget Proposals', 'sponsor-proposals.php',              'proposals'],
    ['funding',   'Funding History',  'sponsor-dashboard.php#transactions', 'history'],
    ['spending',  'Spending History', 'sponsor-spending.php',               'receipt'],
    ['reports',   'Reports',          null,                                 'reports'],
];
?>
<aside class="sidebar">
  <div class="brand">
    <div class="brand-mark"><?= sw_icon('wallet') ?></div>
    <div>
      <div class="brand-name">SponsorWallet</div>
      <div class="brand-role">Sponsor</div>
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
              <?php if ($key === 'proposals' && $pendingCount > 0): ?><span class="count"><?= $pendingCount ?></span><?php endif; ?>
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