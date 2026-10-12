<?php
// Frontend/partials/student-sidebar.php
// The student sidebar, shared by every student page.
//
// Before including it, a page sets which link is highlighted:
//     $active = 'dashboard';   // or 'budget', 'spending', 'funding'

require_once __DIR__ . '/icons.php';

$active = $active ?? '';

// [key, label, link, icon]. A null link = page not built yet ("Soon").
$sidebarLinks = [
    ['dashboard', 'Dashboard',        'student-dashboard.php',                        'dashboard'],
    ['budget',    'My Budget',        'student-budget.php',                           'piggy'],
    ['record',    'Record Spending',  'record-spending.php',                          'pen'],
    ['spending',  'Spending History', 'record-spending.php?view=history#history',     'receipt'],
    ['funding',   'Funding History',  'student-dashboard.php?tx=funding#transactions',  'history'],
];
?>
<aside class="sidebar">
  <div class="brand">
    <div class="brand-mark"><?= sw_icon('wallet') ?></div>
    <div>
      <div class="brand-name">SponsorWallet</div>
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