<?php
// Frontend/sponsor-reports.php
// Sponsor: financial report for a chosen period. Per-student and per-category totals,
// largest expenses, receipt coverage. Print / Save as PDF, or download as CSV (?format=csv).
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_role('sponsor');
// Only students this sponsor actively sponsors (a student can have several sponsors)

$sponsorId = (int) $_SESSION['user_id'];
$labels    = budget_categories();

// ---------- Period ----------
$presets = [
    'this_month' => ['This month',    date('Y-m-01'),                              date('Y-m-d')],
    'last_month' => ['Last month',    date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'last_3'     => ['Last 3 months', date('Y-m-d', strtotime('-3 months +1 day')), date('Y-m-d')],
    'this_year'  => ['This year',     date('Y-01-01'),                             date('Y-m-d')],
];
$preset = $_GET['period'] ?? 'this_month';

function valid_date(?string $d): ?string
{
    $dt = $d ? DateTime::createFromFormat('!Y-m-d', $d) : false;
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}

if ($preset === 'custom') {
    $from = valid_date($_GET['from'] ?? null) ?? date('Y-m-01');
    $to   = valid_date($_GET['to'] ?? null) ?? date('Y-m-d');
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $periodLabel = date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to));
} else {
    if (!isset($presets[$preset])) {
        $preset = 'this_month';
    }
    [$periodLabel, $from, $to] = $presets[$preset];
}
$fromTs = $from . ' 00:00:00';
$toTs   = $to . ' 23:59:59';

// ---------- Students (for the filter) ----------
$stmt = $conn->prepare(
    "SELECT w.id, u.full_name, w.balance FROM wallets w JOIN users u ON u.id = w.student_id
     WHERE EXISTS (SELECT 1 FROM sponsorships sx WHERE sx.student_id = w.student_id AND sx.sponsor_id = ? AND sx.status = 'active') ORDER BY u.full_name"
);
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$walletFilter = (int) ($_GET['wallet'] ?? 0);
if (!in_array($walletFilter, array_map('intval', array_column($students, 'id')), true)) {
    $walletFilter = 0;
}
$walletSql = $walletFilter ? ' AND w.id = ' . $walletFilter : '';   // safe: validated integer from the sponsor's own list

// ---------- Per student ----------
$stmt = $conn->prepare(
    "SELECT w.id, u.full_name, w.balance, w.status,
            (SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
              WHERE t.wallet_id = w.id AND t.type = 'funding' AND t.created_at BETWEEN ? AND ?) AS funded,
            (SELECT COALESCE(SUM(t.amount), 0) FROM transactions t
              WHERE t.wallet_id = w.id AND t.type = 'funding' AND t.created_by = ? AND t.created_at BETWEEN ? AND ?) AS my_funded,
            (SELECT COALESCE(SUM(e.amount), 0) FROM expenses e
              WHERE e.wallet_id = w.id AND e.expense_date BETWEEN ? AND ?) AS spent,
            (SELECT COUNT(*) FROM expenses e
              WHERE e.wallet_id = w.id AND e.expense_date BETWEEN ? AND ?) AS n_expenses,
            (SELECT COUNT(*) FROM expenses e
              WHERE e.wallet_id = w.id AND e.expense_date BETWEEN ? AND ? AND e.receipt_file IS NOT NULL) AS n_receipts
     FROM wallets w JOIN users u ON u.id = w.student_id
     WHERE EXISTS (SELECT 1 FROM sponsorships sx WHERE sx.student_id = w.student_id AND sx.sponsor_id = ? AND sx.status = 'active') $walletSql
     ORDER BY u.full_name"
);
$stmt->bind_param('ssissssssssi', $fromTs, $toTs, $sponsorId, $fromTs, $toTs, $from, $to, $from, $to, $from, $to, $sponsorId);
$stmt->execute();
$byStudent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---------- Per category (spent in period, compared with the current approved budget) ----------
$stmt = $conn->prepare(
    "SELECT e.category, SUM(e.amount) AS spent, COUNT(*) AS n
     FROM expenses e JOIN wallets w ON w.id = e.wallet_id
     WHERE EXISTS (SELECT 1 FROM sponsorships sx WHERE sx.student_id = w.student_id AND sx.sponsor_id = ? AND sx.status = 'active') $walletSql AND e.expense_date BETWEEN ? AND ?
     GROUP BY e.category ORDER BY spent DESC"
);
$stmt->bind_param('iss', $sponsorId, $from, $to);
$stmt->execute();
$byCategory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$stmt = $conn->prepare(
    "SELECT a.category, SUM(a.allocated) AS allocated
     FROM budget_allocations a JOIN wallets w ON w.id = a.wallet_id
     WHERE EXISTS (SELECT 1 FROM sponsorships sx WHERE sx.student_id = w.student_id AND sx.sponsor_id = ? AND sx.status = 'active') $walletSql GROUP BY a.category"
);
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$allocated = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'allocated', 'category');
$stmt->close();

// ---------- All expenses in the period (largest first for the page, by date for the CSV) ----------
$stmt = $conn->prepare(
    "SELECT e.id, e.expense_date, e.category, e.description, e.amount, e.receipt_file, u.full_name
     FROM expenses e JOIN wallets w ON w.id = e.wallet_id JOIN users u ON u.id = w.student_id
     WHERE EXISTS (SELECT 1 FROM sponsorships sx WHERE sx.student_id = w.student_id AND sx.sponsor_id = ? AND sx.status = 'active') $walletSql AND e.expense_date BETWEEN ? AND ?
     ORDER BY e.expense_date, e.id"
);
$stmt->bind_param('iss', $sponsorId, $from, $to);
$stmt->execute();
$expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---------- Totals ----------
$totFunded   = array_sum(array_column($byStudent, 'funded'));      // from all sponsors
$totMine     = array_sum(array_column($byStudent, 'my_funded'));   // from this sponsor
$totSpent    = array_sum(array_column($byStudent, 'spent'));
$totExpenses = array_sum(array_column($byStudent, 'n_expenses'));
$totReceipts = array_sum(array_column($byStudent, 'n_receipts'));
$receiptPct  = $totExpenses ? round($totReceipts / $totExpenses * 100) : 0;
$totBalance  = array_sum(array_column($byStudent, 'balance'));

$catName = fn(string $slug) => $labels[$slug] ?? $slug;
$studentName = $walletFilter ? (array_column($students, 'full_name', 'id')[$walletFilter] ?? '') : 'All students';

// =================== CSV download ===================
if (($_GET['format'] ?? '') === 'csv') {
    $file = 'sponsorwallet-report-' . $from . '-to-' . $to . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // so Excel shows the text correctly

    fputcsv($out, ['SponsorWallet financial report']);
    fputcsv($out, ['Sponsor', $_SESSION['full_name']]);
    fputcsv($out, ['Students', $studentName]);
    fputcsv($out, ['Period', $from . ' to ' . $to]);
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);

    fputcsv($out, ['SUMMARY']);
    fputcsv($out, ['Funds sent by all sponsors (KES)', number_format($totFunded, 2, '.', '')]);
    fputcsv($out, ['Funds sent by you (KES)', number_format($totMine, 2, '.', '')]);
    fputcsv($out, ['Spent by students (KES)', number_format($totSpent, 2, '.', '')]);
    fputcsv($out, ['Expenses recorded', $totExpenses]);
    fputcsv($out, ['Expenses with a receipt', $totReceipts . ' (' . $receiptPct . '%)']);
    fputcsv($out, ['Current balance in wallets (KES)', number_format($totBalance, 2, '.', '')]);
    fputcsv($out, []);

    fputcsv($out, ['BY STUDENT']);
    fputcsv($out, ['Student', 'Funded by all sponsors (KES)', 'Funded by you (KES)', 'Spent (KES)', 'Expenses', 'With receipt', 'Current balance (KES)', 'Wallet']);
    foreach ($byStudent as $s) {
        fputcsv($out, [$s['full_name'], number_format((float) $s['funded'], 2, '.', ''), number_format((float) $s['my_funded'], 2, '.', ''), number_format((float) $s['spent'], 2, '.', ''),
                       $s['n_expenses'], $s['n_receipts'], number_format((float) $s['balance'], 2, '.', ''), $s['status']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['BY CATEGORY']);
    fputcsv($out, ['Category', 'Spent (KES)', 'Share of spending', 'Current approved budget (KES)', 'Expenses']);
    foreach ($byCategory as $c) {
        fputcsv($out, [$catName($c['category']), number_format((float) $c['spent'], 2, '.', ''),
                       ($totSpent > 0 ? round($c['spent'] / $totSpent * 100) : 0) . '%',
                       number_format((float) ($allocated[$c['category']] ?? 0), 2, '.', ''), $c['n']]);
    }
    fputcsv($out, []);

    fputcsv($out, ['ALL EXPENSES']);
    fputcsv($out, ['Date', 'Student', 'Category', 'Description', 'Amount (KES)', 'Receipt']);
    foreach ($expenses as $e) {
        fputcsv($out, [$e['expense_date'], $e['full_name'], $catName($e['category']), $e['description'],
                       number_format((float) $e['amount'], 2, '.', ''), $e['receipt_file'] ? 'Yes' : 'No']);
    }
    fclose($out);
    exit;
}

// =================== Page ===================
$largest = $expenses;
usort($largest, fn($a, $b) => $b['amount'] <=> $a['amount']);
$largest = array_slice($largest, 0, 5);

$query = $_GET;
$query['format'] = 'csv';
$csvLink = 'sponsor-reports.php?' . http_build_query($query);

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));

function kes($v): string { return 'KES ' . number_format((float) $v, 0); }
function kes2($v): string { return number_format((float) $v, 2); }

$active = 'reports';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reports | SponsorWallet</title>
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
      <h1>Reports</h1>
      <div class="topbar-right">
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
          <h2>Financial report</h2>
          <p class="muted"><?= htmlspecialchars($studentName) ?> · <?= htmlspecialchars($periodLabel) ?>
            <span class="print-only"> · <?= htmlspecialchars(date('j M Y', strtotime($from))) ?> – <?= htmlspecialchars(date('j M Y', strtotime($to))) ?> · Sponsor: <?= htmlspecialchars($_SESSION['full_name']) ?></span></p>
        </div>
        <div class="row no-print">
          <button type="button" class="btn btn-outline" onclick="window.print()"><?= sw_icon('receipt') ?>Print / Save as PDF</button>
          <a class="btn" href="<?= htmlspecialchars($csvLink) ?>"><?= sw_icon('reports') ?>Download CSV</a>
        </div>
      </div>

      <!-- Filters -->
      <form method="get" class="card filters report-filters no-print" id="report-filters">
        <div>
          <label for="period">Period</label>
          <select id="period" name="period">
            <?php foreach ($presets as $key => [$lbl]): ?>
              <option value="<?= $key ?>" <?= $preset === $key ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
            <option value="custom" <?= $preset === 'custom' ? 'selected' : '' ?>>Custom dates…</option>
          </select>
        </div>
        <div class="custom-dates" <?= $preset === 'custom' ? '' : 'hidden' ?>>
          <div>
            <label for="from">From</label>
            <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>" max="<?= date('Y-m-d') ?>">
          </div>
          <div>
            <label for="to">To</label>
            <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>" max="<?= date('Y-m-d') ?>">
          </div>
        </div>
        <div>
          <label for="wallet">Student</label>
          <select id="wallet" name="wallet">
            <option value="">All students</option>
            <?php foreach ($students as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= $walletFilter === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-actions">
          <button type="submit" class="btn">Show report</button>
        </div>
      </form>

      <!-- Summary -->
      <section class="stats" aria-label="Summary">
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('send') ?></div>
          <div class="stat-label">Funds sent (all sponsors)</div>
          <div class="stat-value"><?= kes($totFunded) ?></div>
          <div class="stat-note">You sent <?= kes($totMine) ?></div>
        </div>
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('activity') ?></div>
          <div class="stat-label">Spent by students</div>
          <div class="stat-value"><?= kes($totSpent) ?></div>
          <div class="stat-note"><?= $totFunded > 0 ? round($totSpent / $totFunded * 100) . '% of funds sent' : '&nbsp;' ?></div>
        </div>
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('receipt') ?></div>
          <div class="stat-label">Expenses with receipt</div>
          <div class="stat-value"><?= $receiptPct ?>%</div>
          <div class="stat-note"><?= $totReceipts ?> of <?= $totExpenses ?> expenses</div>
        </div>
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('wallet') ?></div>
          <div class="stat-label">Balance now</div>
          <div class="stat-value"><?= kes($totBalance) ?></div>
          <div class="stat-note">Across <?= count($byStudent) ?> wallet<?= count($byStudent) === 1 ? '' : 's' ?></div>
        </div>
      </section>

      <div class="grid-2">
        <!-- Spending by category -->
        <section class="card">
          <div class="card-head">
            <h3>Spending by category</h3>
            <span class="muted small">Share of KES <?= number_format($totSpent) ?></span>
          </div>
          <?php if (!$byCategory): ?>
            <p class="empty">No spending recorded in this period.</p>
          <?php else: ?>
            <div class="bars">
              <?php foreach ($byCategory as $c):
                  $share = $totSpent > 0 ? round($c['spent'] / $totSpent * 100) : 0;
                  $alloc = (float) ($allocated[$c['category']] ?? 0);
              ?>
                <div>
                  <div class="bar-top"><b><?= htmlspecialchars($catName($c['category'])) ?></b><span><?= kes($c['spent']) ?> · <?= $share ?>%</span></div>
                  <div class="bar-track" role="img" aria-label="<?= $share ?>% of spending"><div class="bar-fill" style="width: <?= $share ?>%"></div></div>
                  <div class="bar-sub"><?= (int) $c['n'] ?> expense<?= (int) $c['n'] === 1 ? '' : 's' ?><?= $alloc > 0 ? ' · approved budget ' . kes($alloc) : ' · not in current budget' ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <!-- Largest expenses -->
        <section class="card">
          <div class="card-head"><h3>Largest expenses</h3></div>
          <?php if (!$largest): ?>
            <p class="empty">No expenses in this period.</p>
          <?php else: ?>
            <ul class="mini-list">
              <?php foreach ($largest as $e): ?>
                <li>
                  <div>
                    <strong><?= htmlspecialchars($e['description']) ?></strong>
                    <div class="muted small"><?= htmlspecialchars($e['full_name']) ?> · <?= htmlspecialchars($catName($e['category'])) ?> · <?= htmlspecialchars(date('j M', strtotime($e['expense_date']))) ?>
                      <?= $e['receipt_file'] ? '' : ' · <span class="warn-text">no receipt</span>' ?></div>
                  </div>
                  <span class="amount-out"><?= kes2($e['amount']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>
      </div>

      <!-- By student -->
      <section class="card">
        <div class="card-head"><h3>By student</h3></div>
        <?php if (!$byStudent): ?>
          <p class="empty">No students yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Student</th><th class="num">Funded</th><th class="num">You sent</th><th class="num">Spent</th><th class="num">Expenses</th><th class="num">With receipt</th><th class="num">Balance now</th></tr></thead>
              <tbody>
                <?php foreach ($byStudent as $s): $pct = $s['n_expenses'] ? round($s['n_receipts'] / $s['n_expenses'] * 100) : 0; ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($s['full_name']) ?></strong><?= $s['status'] !== 'active' ? ' <span class="pill red">Frozen</span>' : '' ?></td>
                    <td class="num"><?= kes2($s['funded']) ?></td>
                    <td class="num"><?= kes2($s['my_funded']) ?></td>
                    <td class="num"><?= kes2($s['spent']) ?></td>
                    <td class="num"><?= (int) $s['n_expenses'] ?></td>
                    <td class="num"><?= (int) $s['n_expenses'] ? $pct . '%' : '—' ?></td>
                    <td class="num"><strong><?= kes2($s['balance']) ?></strong></td>
                  </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                  <td>Total</td>
                  <td class="num"><?= kes2($totFunded) ?></td>
                  <td class="num"><?= kes2($totMine) ?></td>
                  <td class="num"><?= kes2($totSpent) ?></td>
                  <td class="num"><?= $totExpenses ?></td>
                  <td class="num"><?= $totExpenses ? $receiptPct . '%' : '—' ?></td>
                  <td class="num"><?= kes2($totBalance) ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <p class="muted small">Funds sent = M-Pesa payments confirmed in this period, from all of the student's sponsors ("You sent" = your share). Spent = expenses recorded by students with a date in this period. Balance is the current wallet balance.
        Generated <?= htmlspecialchars(date('j M Y, H:i')) ?>.</p>
    </main>
  </div>
</div>

<script>
  // Show the date boxes only for "Custom dates…"
  const period = document.getElementById('period');
  const dates  = document.querySelector('.custom-dates');
  if (period && dates) {
    period.addEventListener('change', () => { dates.hidden = period.value !== 'custom'; });
  }
</script>
</body>
</html>