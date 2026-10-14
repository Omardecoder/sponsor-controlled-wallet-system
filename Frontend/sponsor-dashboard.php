<?php
// Frontend/sponsor-dashboard.php
// Sponsor overview: totals, budget usage, recent transactions, send funds, students.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_role('sponsor');

$sponsorId = (int) $_SESSION['user_id'];

// One-time message from create_wallet.php / fund_wallet.php
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---- This sponsor's students (one wallet each) ----
$stmt = $conn->prepare(
    'SELECT w.id, w.balance, w.status, u.full_name, u.email, u.phone
     FROM wallets w
     JOIN users u ON u.id = w.student_id
     WHERE w.sponsor_id = ?
     ORDER BY u.full_name'
);
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$wallets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---- Summary figures ----
$stmt = $conn->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN t.type = 'funding'  THEN t.amount END), 0) AS funded,
        COALESCE(SUM(CASE WHEN t.type = 'spending' THEN t.amount END), 0) AS spent,
        COALESCE(SUM(t.created_at >= NOW() - INTERVAL 30 DAY), 0)         AS recent_count
     FROM transactions t
     JOIN wallets w ON w.id = t.wallet_id
     WHERE w.sponsor_id = ?"
);
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ---- Budget overview: approved allocations vs spending, per category ----
$stmt = $conn->prepare(
    'SELECT a.category, SUM(a.allocated) AS allocated, SUM(a.spent) AS spent
     FROM budget_allocations a
     JOIN wallets w ON w.id = a.wallet_id
     WHERE w.sponsor_id = ?
     GROUP BY a.category
     ORDER BY allocated DESC'
);
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$budgetRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$categoryLabels = budget_categories();   // slug => label

// ---- Recent transactions ----
$stmt = $conn->prepare(
    'SELECT t.type, t.amount, t.description, t.created_at, u.full_name
     FROM transactions t
     JOIN wallets w ON w.id = t.wallet_id
     JOIN users u   ON u.id = w.student_id
     WHERE w.sponsor_id = ?
     ORDER BY t.created_at DESC, t.id DESC
     LIMIT 8'
);
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// The sponsor's own phone, used as the default M-Pesa number
$stmt = $conn->prepare('SELECT phone FROM users WHERE id = ?');
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$sponsorPhone = (string) ($stmt->get_result()->fetch_assoc()['phone'] ?? '');
$stmt->close();

// Initials for the profile circle, e.g. "Amina Wanjiru" -> "AW"
$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));
$firstName = $nameParts[0];

function kes($v): string { return 'KES ' . number_format((float) $v, 0); }
function kes2($v): string { return number_format((float) $v, 2); }

$active = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sponsor Dashboard | SponsorWallet</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/partials/sponsor-sidebar.php'; ?>

  <div class="content">
    <header class="topbar">
      <h1>Sponsor Dashboard</h1>
      <div class="topbar-right">
                <a class="bell" href="alerts.php" title="Alerts" aria-label="Alerts: <?= $alertCount ?> new">
          <?= sw_icon('bell') ?>
          <?php if ($alertCount > 0): ?><span class="dot"><?= $alertCount ?></span><?php endif; ?>
        </a>
        <div class="profile">
          <div class="avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></div>
          <div class="profile-text">
            <div class="profile-name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
            <div class="profile-role">Sponsor</div>
          </div>
        </div>
      </div>
    </header>

    <main class="page">
      <div class="page-head">
        <div>
          <h2>Welcome back, <?= htmlspecialchars($firstName) ?>!</h2>
          <p class="muted">Here is your funding overview.</p>
        </div>
        <a class="btn btn-lg" href="#send-funds"><?= sw_icon('plus') ?>Send Funds</a>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <?php if ($pendingCount > 0): ?>
        <div class="notice">
          <span><strong><?= $pendingCount ?> budget proposal<?= $pendingCount === 1 ? '' : 's' ?></strong> waiting for your approval.</span>
          <a class="btn btn-accent" href="sponsor-proposals.php">Review now</a>
        </div>
      <?php endif; ?>

      <!-- Four summary cards -->
      <section class="stats" aria-label="Summary">
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('send') ?></div>
          <div class="stat-label">Total Funds Sent</div>
          <div class="stat-value"><?= kes($totals['funded']) ?></div>
        </div>
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('students') ?></div>
          <div class="stat-label">Students Sponsored</div>
          <div class="stat-value"><?= count($wallets) ?></div>
        </div>
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('activity') ?></div>
          <div class="stat-label">Student Spending</div>
          <div class="stat-value"><?= kes($totals['spent']) ?></div>
        </div>
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('history') ?></div>
          <div class="stat-label">Recent Transactions</div>
          <div class="stat-value"><?= (int) $totals['recent_count'] ?></div>
          <div class="stat-note">Last 30 days</div>
        </div>
      </section>

      <div class="grid-2">
        <!-- Budget overview -->
        <section class="card">
          <div class="card-head">
            <h3>Budget overview</h3>
            <span class="muted small">Spent of approved budget</span>
          </div>
          <?php if (!$budgetRows): ?>
            <p class="empty">No approved budgets yet. When you approve a student's budget proposal, its categories appear here.</p>
          <?php else: ?>
            <div class="bars">
              <?php foreach ($budgetRows as $b):
                  $alloc = (float) $b['allocated'];
                  $spent = (float) $b['spent'];
                  $pct   = $alloc > 0 ? round($spent / $alloc * 100) : 0;
                  $class = $pct > 100 ? 'over' : ($pct >= 80 ? 'high' : '');
              ?>
                <div>
                  <div class="bar-top">
                    <b><?= htmlspecialchars($categoryLabels[$b['category']] ?? $b['category']) ?></b>
                    <span><?= $pct ?>%</span>
                  </div>
                  <div class="bar-track" role="img" aria-label="<?= $pct ?>% used">
                    <div class="bar-fill <?= $class ?>" style="width: <?= min(100, $pct) ?>%"></div>
                  </div>
                  <div class="bar-sub"><?= kes($spent) ?> spent of <?= kes($alloc) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <!-- Send funds -->
        <section class="card" id="send-funds">
                    <div class="card-head"><h3>Send funds</h3><span class="mpesa-tag">M-PESA</span></div>
          <?php if (!$wallets): ?>
            <p class="empty">Add a student below before sending funds.</p>
          <?php else: ?>
                        <form action="../Backend/mpesa_fund.php" method="post" class="form-grid">
              <div>
                <label for="wallet_id">Student</label>
                <select id="wallet_id" name="wallet_id" required>
                <option value="" selected disabled>Choose a student…</option>
                  <?php foreach ($wallets as $w): ?>
                    <option value="<?= (int) $w['id'] ?>" <?= $w['status'] !== 'active' ? 'disabled' : '' ?>>
                      <?= htmlspecialchars($w['full_name']) ?> — balance KES <?= kes2($w['balance']) ?><?= $w['status'] !== 'active' ? ' (frozen)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="amount">Amount (KES)</label>
                <input type="number" id="amount" name="amount" min="1" step="0.01" placeholder="e.g. 5000" required>
              </div>
              <div>
                <label for="amount">Amount (KES)</label>
                <input type="number" id="amount" name="amount" min="1" max="250000" step="1" placeholder="e.g. 5000" required>
              </div>
              <div>
                <label for="phone">Pay from M-Pesa number</label>
                <input type="tel" id="phone" name="phone" required placeholder="0712 345 678" value="<?= htmlspecialchars($sponsorPhone) ?>">
              </div>
              <button type="submit" class="btn btn-block"><?= sw_icon('send') ?>Pay with M-Pesa</button>
              <p class="muted small">You'll get an M-Pesa prompt on this phone. The wallet is credited only after M-Pesa confirms the payment.</p>
            </form>
          <?php endif; ?>
        </section>
      </div>

      <!-- Recent transactions -->
      <section class="card" id="transactions">
        <div class="card-head"><h3>Recent transactions</h3></div>
        <?php if (!$recent): ?>
          <p class="empty">No transactions yet. Funds you send will appear here.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Date</th><th>Student</th><th>Details</th><th>Type</th><th class="num">Amount (KES)</th></tr>
              </thead>
              <tbody>
                <?php foreach ($recent as $t): $in = $t['type'] === 'funding'; ?>
                  <tr>
                    <td><?= htmlspecialchars(date('j M Y, H:i', strtotime($t['created_at']))) ?></td>
                    <td><?= htmlspecialchars($t['full_name']) ?></td>
                    <td class="wrap"><?= htmlspecialchars($t['description'] ?? '') ?></td>
                    <td><span class="pill <?= $in ? 'green' : 'amber' ?>"><?= $in ? 'Funding' : 'Spending' ?></span></td>
                    <td class="num <?= $in ? 'amount-in' : 'amount-out' ?>"><?= $in ? '+' : '−' ?><?= kes2($t['amount']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <!-- My students -->
      <section class="card" id="students">
        <div class="card-head"><h3>My students</h3></div>

        <form action="../Backend/create_wallet.php" method="post">
          <div class="row">
            <div>
              <label for="student_identifier">Add a student by email or phone number</label>
              <input type="text" id="student_identifier" name="student_identifier" placeholder="student@example.com or 0712 345 678" required>
            </div>
            <button type="submit" class="btn">Add student</button>
          </div>
          <p class="muted small" style="margin-top:6px;">The student must already have a Student account. This creates their wallet and links them to you.</p>
        </form>

        <?php if (!$wallets): ?>
          <p class="empty" style="margin-top:14px;">No students yet.</p>
        <?php else: ?>
          <div class="table-wrap" style="margin-top:18px;">
            <table>
              <thead>
                <tr><th>Student</th><th>Contact</th><th>Status</th><th class="num">Balance (KES)</th></tr>
              </thead>
              <tbody>
                <?php foreach ($wallets as $w): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($w['full_name']) ?></strong></td>
                    <td class="muted"><?= htmlspecialchars($w['email']) ?><br><?= htmlspecialchars($w['phone']) ?></td>
                    <td><span class="pill <?= $w['status'] === 'active' ? 'green' : 'red' ?>"><?= htmlspecialchars(ucfirst($w['status'])) ?></span></td>
                    <td class="num"><strong><?= kes2($w['balance']) ?></strong></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>
</div>
</body>
</html>