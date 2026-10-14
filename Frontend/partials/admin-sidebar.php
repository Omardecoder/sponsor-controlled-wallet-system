<?php
// Frontend/partials/admin-sidebar.php
// The admin sidebar. The admin area is one page with sections, so the links jump to them.

require_once __DIR__ . '/icons.php';
$active = $active ?? 'overview';

$sidebarLinks = [
    ['overview', 'Overview',         'admin-dashboard.php',           'dashboard'],
    ['users',    'Users',            'admin-dashboard.php#users',     'students'],
    ['wallets',  'Wallets',          'admin-dashboard.php#wallets',   'wallet'],
    ['payments', 'M-Pesa Payments',  'admin-dashboard.php#payments',  'send'],
];
?>
<aside class="sidebar">
  <div class="brand">
    <div class="brand-mark"><?= sw_icon('wallet') ?></div>
    <div>
      <div class="brand-name">SponsorWallet</div>
      <div class="brand-role">Administrator</div>
    </div>
  </div>

  <nav aria-label="Main">
    <ul class="nav">
      <?php foreach ($sidebarLinks as [$key, $label, $href, $icon]): ?>
        <li>
          <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>>
            <?= sw_icon($icon) ?><?= $label ?>
          </a>
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