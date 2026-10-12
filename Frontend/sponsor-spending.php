<?php
// Frontend/sponsor-spending.php
// Sponsor: see expenses recorded by your students, with their receipts.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_role('sponsor');

$sponsorId = (int) $_SESSION['user_id'];
$labels    = budget_categories();   // slug => label

// This sponsor's students, for the filter
$stmt = $conn->prepare(
    'SELECT w.id, u.full_name
     FROM wallets w JOIN users u ON u.id = w.student_id
     WHERE w.sponsor_id = ? ORDER BY u.full_name'
);
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$studentIds = array_map('intval', array_column($students, 'id'));

// Filters: ?wallet=ID&cat=slug&receipt=with|without
$walletFilter  = in_array((int) ($_GET['wallet'] ?? 0), $studentIds, true) ? (int) $_GET['wallet'] : 0;
$catFilter     = array_key_exists($_GET['cat'] ?? '', $labels) ? $_GET['cat'] : '';
$receiptFilter = in_array($_GET['receipt'] ?? '', ['with', 'without'], true) ? $_GET['receipt'] : '';

// Build the query with only the filters that are set
$where  = ['w.sponsor_id = ?'];
$types  = 'i';
$params = [$sponsorId];
if ($walletFilter) { $where[] = 'e.wallet_id = ?'; $types .= 'i'; $params[] = $walletFilter; }
if ($catFilter)    { $where[] = 'e.category = ?';  $types .= 's'; $params[] = $catFilter; }
if ($receiptFilter === 'with')    { $where[] = 'e.receipt_file IS NOT NULL'; }
if ($receiptFilter === 'without') { $where[] = 'e.receipt_file IS NULL'; }
$whereSql = implode(' AND ', $where);

// Summary for the current filter
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS n, COALESCE(SUM(e.amount), 0) AS total,
            COALESCE(SUM(e.receipt_file IS NOT NULL), 0) AS with_receipt
     FROM expenses e JOIN wallets w ON w.id = e.wallet_id
     WHERE $whereSql"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();
$stmt->close();

// The expenses
$stmt = $conn->prepare(
    "SELECT e.id, e.category, e.description, e.amount, e.expense_date, e.receipt_file, u.full_name
     FROM expenses e
     JOIN wallets w ON w.id = e.wallet_id
     JOIN users u   ON u.id = w.student_id
     WHERE $whereSql
     ORDER BY e.expense_date DESC, e.id DESC
     LIMIT 100"
);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$count       = (int) $summary['n'];
$withReceipt = (int) $summary['with_receipt'];
$receiptPct  = $count > 0 ? round($withReceipt / $count * 100) : 0;

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));

$active = 'spending';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Spending History | SponsorWallet</title>
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
      <h1>Spending History</h1>
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
          <h2>Student spending</h2>
          <p class="muted">Expenses your students recorded against their approved budgets, with any receipts they attached.</p>
        </div>
      </div>

      <!-- Filters -->
      <form method="get" class="card filters">
        <div>
          <label for="wallet">Student</label>
          <select id="wallet" name="wallet">
            <option value="">All students</option>
            <?php foreach ($students as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= $walletFilter === (int) $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="cat">Budget line</label>
          <select id="cat" name="cat">
            <option value="">All budget lines</option>
            <?php foreach ($labels as $slug => $label): ?>
              <option value="<?= htmlspecialchars($slug) ?>" <?= $catFilter === $slug ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="receipt">Receipt</label>
          <select id="receipt" name="receipt">
            <option value="">Any</option>
            <option value="with" <?= $receiptFilter === 'with' ? 'selected' : '' ?>>Attached</option>
            <option value="without" <?= $receiptFilter === 'without' ? 'selected' : '' ?>>Not attached</option>
          </select>
        </div>
        <div class="filter-actions">
          <button type="submit" class="btn">Apply</button>
          <?php if ($walletFilter || $catFilter || $receiptFilter): ?><a href="sponsor-spending.php" class="small">Clear</a><?php endif; ?>
        </div>
      </form>

      <!-- Summary -->
      <section class="stats stats-3" aria-label="Summary">
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('activity') ?></div>
          <div class="stat-label">Total spent</div>
          <div class="stat-value">KES <?= number_format((float) $summary['total'], 0) ?></div>
        </div>
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('receipt') ?></div>
          <div class="stat-label">Expenses recorded</div>
          <div class="stat-value"><?= $count ?></div>
        </div>
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('proposals') ?></div>
          <div class="stat-label">With a receipt</div>
          <div class="stat-value"><?= $withReceipt ?> <span class="stat-of">of <?= $count ?></span></div>
          <div class="stat-note"><?= $receiptPct ?>% of expenses</div>
        </div>
      </section>

      <!-- Table -->
      <section class="card">
        <div class="card-head"><h3>Expenses</h3><?php if ($count > 100): ?><span class="muted small">Showing the latest 100</span><?php endif; ?></div>
        <?php if (!$expenses): ?>
          <p class="empty"><?= ($walletFilter || $catFilter || $receiptFilter) ? 'No expenses match these filters.' : 'Your students have not recorded any spending yet.' ?></p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Date</th><th>Student</th><th>Description</th><th>Budget line</th><th>Receipt</th><th class="num">Amount (KES)</th></tr>
              </thead>
              <tbody>
                <?php foreach ($expenses as $e): ?>
                  <tr>
                    <td><?= htmlspecialchars(date('j M Y', strtotime($e['expense_date']))) ?></td>
                    <td><?= htmlspecialchars($e['full_name']) ?></td>
                    <td class="wrap"><?= htmlspecialchars($e['description']) ?></td>
                    <td><?= htmlspecialchars($labels[$e['category']] ?? $e['category']) ?></td>
                    <td>
                      <?php if ($e['receipt_file']): ?>
                        <a class="pill green" href="../Backend/view_receipt.php?id=<?= (int) $e['id'] ?>" target="_blank" rel="noopener">View receipt</a>
                      <?php else: ?>
                        <span class="pill grey">Not attached</span>
                      <?php endif; ?>
                    </td>
                    <td class="num amount-out">−<?= number_format((float) $e['amount'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="muted small" style="margin-top:10px;">These are expenses recorded by students. A receipt supports an expense but is not a verification of it.</p>
        <?php endif; ?>
      </section>
    </main>
  </div>
</div>
</body>
</html>