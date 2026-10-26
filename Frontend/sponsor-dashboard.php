<?php
// Frontend/sponsor-dashboard.php
// Sponsor overview: totals, budget usage, send funds, recent transactions, students and pledges.
// A student can have several sponsors: this page shows only the students THIS sponsor supports.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_once __DIR__ . '/../Backend/sponsorship.php';
require_role('sponsor');

$sponsorId = (int) $_SESSION['user_id'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---- My students: active sponsorships and requests still waiting for the student ----
$stmt = $conn->prepare(
    "SELECT sp.id AS sponsorship_id, sp.status AS link_status, sp.pledge, sp.created_at AS asked_at,
            u.id AS student_id, u.full_name, u.email, u.phone,
            w.id, w.balance, w.status,
            (SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
              WHERE t.wallet_id = w.id AND t.type = 'funding' AND t.created_by = sp.sponsor_id) AS my_funded,
            (SELECT GROUP_CONCAT(ou.full_name ORDER BY o.created_at SEPARATOR ', ')
               FROM sponsorships o JOIN users ou ON ou.id = o.sponsor_id
              WHERE o.student_id = sp.student_id AND o.status = 'active' AND o.sponsor_id <> sp.sponsor_id) AS co_sponsors
     FROM sponsorships sp
     JOIN users u ON u.id = sp.student_id
     LEFT JOIN wallets w ON w.student_id = sp.student_id
     WHERE sp.sponsor_id = ? AND sp.status IN ('active', 'pending')
     ORDER BY sp.status = 'pending', u.full_name"
);
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Students whose wallet this sponsor can fund (accepted, wallet exists)
$wallets = array_values(array_filter($students, fn($s) => $s['link_status'] === 'active' && $s['id'] !== null));

// ---- Summary figures ----
// Funds sent = what THIS sponsor sent. Spending = by the students this sponsor supports.
$stmt = $conn->prepare(
    "SELECT
        (SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
          WHERE t.type = 'funding' AND t.created_by = ?) AS funded,
        COALESCE(SUM(CASE WHEN t.type = 'spending' THEN t.amount END), 0) AS spent,
        COALESCE(SUM(t.created_at >= NOW() - INTERVAL 30 DAY), 0)         AS recent_count
     FROM transactions t
     JOIN wallets w ON w.id = t.wallet_id
     JOIN sponsorships sp ON sp.student_id = w.student_id AND sp.status = 'active'
     WHERE sp.sponsor_id = ?"
);
$stmt->bind_param('ii', $sponsorId, $sponsorId);
$stmt->execute();
$totals = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ---- Budget overview: approved allocations vs spending, per category ----
$stmt = $conn->prepare(
    "SELECT a.category, SUM(a.allocated) AS allocated, SUM(a.spent) AS spent
     FROM budget_allocations a
     JOIN wallets w ON w.id = a.wallet_id
     JOIN sponsorships sp ON sp.student_id = w.student_id AND sp.status = 'active'
     WHERE sp.sponsor_id = ?
     GROUP BY a.category
     ORDER BY allocated DESC"
);
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$budgetRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$categoryLabels = budget_categories();   // slug => label

// ---- Recent transactions on my students' wallets (from any of their sponsors) ----
$stmt = $conn->prepare(
    "SELECT t.type, t.amount, t.description, t.created_at, t.created_by, u.full_name, c.full_name AS by_name
     FROM transactions t
     JOIN wallets w ON w.id = t.wallet_id
     JOIN sponsorships sp ON sp.student_id = w.student_id AND sp.status = 'active'
     JOIN users u ON u.id = w.student_id
     JOIN users c ON c.id = t.created_by
     WHERE sp.sponsor_id = ?
     ORDER BY t.created_at DESC, t.id DESC
     LIMIT 8"
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
          <div class="stat-label">Funds You Sent</div>
          <div class="stat-value"><?= kes($totals['funded']) ?></div>
        </div>
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('students') ?></div>
          <div class="stat-label">Students Sponsored</div>
          <div class="stat-value"><?= count($wallets) ?></div>
          <?php $waiting = count($students) - count($wallets); if ($waiting > 0): ?><div class="stat-note"><?= $waiting ?> request<?= $waiting === 1 ? '' : 's' ?> waiting</div><?php endif; ?>
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
            <p class="empty">Once a student accepts your sponsorship, you can send them funds here.</p>
          <?php else: ?>
            <form action="../Backend/mpesa_fund.php" method="post" class="form-grid">
              <div>
                <label for="wallet_id">Student</label>
                <select id="wallet_id" name="wallet_id" required>
                  <option value="" selected disabled>Choose a student…</option>
                  <?php foreach ($wallets as $w):
                      $left = max(0, (float) $w['pledge'] - (float) $w['my_funded']);
                      $can  = $w['status'] === 'active' && $left >= 1;
                  ?>
                    <option value="<?= (int) $w['id'] ?>" <?= $can ? '' : 'disabled' ?>>
                      <?= htmlspecialchars($w['full_name']) ?> — <?= $w['status'] !== 'active' ? 'frozen' : 'KES ' . number_format($left) . ' left of your pledge' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="amount">Amount (KES)</label>
                <input type="number" id="amount" name="amount" min="1" max="250000" step="1" placeholder="e.g. 5000" required>
              </div>
              <div>
                <label for="phone">Pay from M-Pesa number</label>
                <input type="tel" id="phone" name="phone" required placeholder="0712 345 678" value="<?= htmlspecialchars($sponsorPhone) ?>">
              </div>
              <div>
                <label for="note">Note (optional)</label>
                <input type="text" id="note" name="note" maxlength="255" placeholder="e.g. Term 2 upkeep">
              </div>
              <button type="submit" class="btn btn-block"><?= sw_icon('send') ?>Pay with M-Pesa</button>
              <p class="muted small">You can send up to your pledge for each student. You'll get an M-Pesa prompt on this phone; the wallet is credited only after M-Pesa confirms.</p>
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
                <?php foreach ($recent as $t):
                    $in = $t['type'] === 'funding';
                    $by = $in ? ((int) $t['created_by'] === $sponsorId ? 'You' : $t['by_name']) : null;
                ?>
                  <tr>
                    <td><?= htmlspecialchars(date('j M Y, H:i', strtotime($t['created_at']))) ?></td>
                    <td><?= htmlspecialchars($t['full_name']) ?></td>
                    <td class="wrap"><?= htmlspecialchars($t['description'] ?? '') ?><?= $by ? '<br><span class="muted small">Sent by ' . htmlspecialchars($by) . '</span>' : '' ?></td>
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

        <form action="../Backend/sponsorship_action.php" method="post" class="sponsor-request">
          <input type="hidden" name="action" value="request">
          <div>
            <label for="student_identifier">Student's email or phone</label>
            <input type="text" id="student_identifier" name="student_identifier" placeholder="student@example.com or 0712 345 678" required>
          </div>
          <div>
            <label for="pledge">Your pledge (KES)</label>
            <input type="number" id="pledge" name="pledge" min="1" step="1" placeholder="e.g. 60000" required>
          </div>
          <div>
            <label for="message">Message (optional)</label>
            <input type="text" id="message" name="message" maxlength="255" placeholder="e.g. Your upkeep for this semester">
          </div>
          <button type="submit" class="btn">Send request</button>
        </form>
        <p class="muted small" style="margin-top:6px;">Your pledge is the most you will give this student. Their budget can't be more than the total of all their sponsors' pledges. The student must accept before you are linked.</p>

        <?php if (!$students): ?>
          <p class="empty" style="margin-top:14px;">No students yet.</p>
        <?php else: ?>
          <div class="table-wrap" style="margin-top:18px;">
            <table>
              <thead>
                <tr><th>Student</th><th>Status</th><th class="num">Your pledge (KES)</th><th class="num">You sent</th><th class="num">Balance</th><th>Pledge</th></tr>
              </thead>
              <tbody>
                <?php foreach ($students as $s): $isActive = $s['link_status'] === 'active'; ?>
                  <tr>
                    <td>
                      <strong><?= htmlspecialchars($s['full_name']) ?></strong>
                      <div class="muted small"><?= htmlspecialchars($s['email']) ?> · <?= htmlspecialchars($s['phone']) ?></div>
                      <?php if ($s['co_sponsors']): ?><div class="muted small">Also sponsored by <?= htmlspecialchars($s['co_sponsors']) ?></div><?php endif; ?>
                    </td>
                    <td>
                      <?php if (!$isActive): ?>
                        <span class="pill amber">Waiting for student</span>
                      <?php else: ?>
                        <span class="pill <?= $s['status'] === 'active' ? 'green' : 'red' ?>"><?= $s['status'] === 'active' ? 'Active' : 'Frozen' ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="num"><strong><?= kes2($s['pledge']) ?></strong></td>
                    <td class="num"><?= $isActive ? kes2($s['my_funded']) : '—' ?></td>
                    <td class="num"><?= $isActive && $s['id'] !== null ? kes2($s['balance']) : '—' ?></td>
                    <td>
                      <form action="../Backend/sponsorship_action.php" method="post" class="inline-action">
                        <input type="hidden" name="sponsorship_id" value="<?= (int) $s['sponsorship_id'] ?>">
                        <input type="hidden" name="action" value="pledge">
                        <input type="number" name="pledge" min="1" step="1" value="<?= (int) round((float) $s['pledge']) ?>" aria-label="New pledge for <?= htmlspecialchars($s['full_name']) ?>" class="pledge-input">
                        <button type="submit" class="btn btn-outline btn-sm">Save</button>
                      </form>
                      <?php if (!$isActive): ?>
                        <form action="../Backend/sponsorship_action.php" method="post" class="inline-action" onsubmit="return confirm('Cancel your request to <?= htmlspecialchars(addslashes($s['full_name'])) ?>?');">
                          <input type="hidden" name="sponsorship_id" value="<?= (int) $s['sponsorship_id'] ?>">
                          <input type="hidden" name="action" value="cancel">
                          <button type="submit" class="link-btn">Cancel request</button>
                        </form>
                      <?php endif; ?>
                    </td>
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