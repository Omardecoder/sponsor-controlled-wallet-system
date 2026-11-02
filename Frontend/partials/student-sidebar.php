<?php
// Frontend/partials/student-sidebar.php
// The student sidebar, shared by every student page.
//
// Before including it, a page sets which link is highlighted:
//     $active = 'dashboard';   // or 'alerts', 'budget', 'record', 'spending', 'funding', 'programs'
// It also sets $alertCount (unread alerts) for the page to use.

require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/../../Backend/notify.php';

$active = $active ?? '';
$alertCount = unread_alerts($conn, (int) $_SESSION['user_id']);

// [key, label, link, icon]. A null link = page not built yet ("Soon").
$sidebarLinks = [
    ['dashboard', 'Dashboard',        'student-dashboard.php',                        'dashboard'],
    ['alerts',    'Alerts',           'alerts.php',                                   'bell'],
    ['budget',    'My Budget',        'student-budget.php',                           'piggy'],
    ['record',    'Record Spending',  'record-spending.php',                          'pen'],
    ['spending',  'Spending History', 'record-spending.php?view=history#history',     'receipt'],
    ['funding',   'Funding History',  'student-dashboard.php?tx=funding#transactions',  'history'],
    ['programs',  'Find Sponsorship', 'student-programs.php',                         'award'],
];
?>
<aside class="sidebar">
  <div class="brand">
    <div class="brand-mark"><?= sw_icon('wallet') ?></div>
    <div>
      <div class="brand-name">SS-Wallet</div>
      <div class="brand-role">Student</div>
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
              <?php if ($key === 'alerts' && $alertCount > 0 && $active !== 'alerts'): ?><span class="count"><?= $alertCount ?></span><?php endif; ?>
            </a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <div class="sidebar-foot">
    <ul class="nav">
      <li><a href="../Backend/logout.php"><?= sw_icon('logout') ?>Log out</a></li>
    </ul>
  </div>
</aside>