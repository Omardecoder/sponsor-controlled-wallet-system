<?php
// Frontend/student-dashboard.php
// Student overview: balance, funds received, spending, approved budget, transactions.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_role('student');

$studentId = (int) $_SESSION['user_id'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---- The student's wallet and the sponsor linked to it ----
$stmt = $conn->prepare(
    'SELECT w.id, w.balance, w.status, s.full_name AS sponsor_name
     FROM wallets w
     JOIN users s ON s.id = w.sponsor_id
     WHERE w.student_id = ?
     LIMIT 1'
);
$stmt->bind_param('i', $studentId);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc();
$stmt->close();

$totals     = ['received' => 0, 'spent' => 0];
$budgetRows = [];
$proposal   = null;
$recent     = [];

// Which transactions to list: all, only funding, or only spending
$txFilter = in_array($_GET['tx'] ?? '', ['funding', 'spending'], true) ? $_GET['tx'] : 'all';

if ($wallet) {
    $walletId = (int) $wallet['id'];

    // Funds received and total spent
    $stmt = $conn->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN type = 'funding'  THEN amount END), 0) AS received,
            COALESCE(SUM(CASE WHEN type = 'spending' THEN amount END), 0) AS spent
         FROM transactions WHERE wallet_id = ?"
    );
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $totals = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Approved budget lines (filled in when the sponsor approves a proposal)
    $stmt = $conn->prepare(
        'SELECT category, allocated, spent
         FROM budget_allocations
         WHERE wallet_id = ?
         ORDER BY allocated DESC'
    );
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $budgetRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Latest budget proposal and the sponsor's decision
    $stmt = $conn->prepare(
        'SELECT p.id, p.status, p.sponsor_feedback, p.created_at,
                COALESCE(SUM(i.proposed_amount), 0) AS total
         FROM budget_proposals p
         LEFT JOIN budget_proposal_items i ON i.proposal_id = p.id
         WHERE p.wallet_id = ?
         GROUP BY p.id
         ORDER BY p.id DESC
         LIMIT 1'
    );
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $proposal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Recent transactions
    $sql = 'SELECT type, amount, balance_after, description, created_at
            FROM transactions WHERE wallet_id = ?'
         . ($txFilter !== 'all' ? ' AND type = ?' : '')
         . ' ORDER BY created_at DESC, id DESC LIMIT 15';
    $stmt = $conn->prepare($sql);
    if ($txFilter !== 'all') {
        $stmt->bind_param('is', $walletId, $txFilter);
    } else {
        $stmt->bind_param('i', $walletId);
    }
    $stmt->execute();
    $recent = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$budgetTotal    = array_sum(array_column($budgetRows, 'allocated'));
$categoryLabels = budget_categories();   // slug => label

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));
$firstName = $nameParts[0];

function ksh($v): string { return 'KSh ' . number_format((float) $v, 0); }
function ksh2($v): string { return number_format((float) $v, 2); }

$active = $txFilter === 'spending' ? 'spending' : ($txFilter === 'funding' ? 'funding' : 'dashboard');
$txTitle = ['all' => 'Recent transactions', 'funding' => 'Funding history', 'spending' => 'Spending history'][$txFilter];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Student Dashboard | SponsorWallet</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/partials/student-sidebar.php'; ?>

  <div class="content">
    <header class="topbar">
      <h1>Student Dashboard</h1>
      <div class="topbar-right">
        <div class="profile">
          <div class="avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></div>
          <div class="profile-text">
            <div class="profile-name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
            <div class="profile-role">Student</div>
          </div>
        </div>
      </div>
    </header>

    <main class="page">
      <!-- Welcome band -->
      <section class="hero">
        <div>
          <div class="hero-kicker">Student dashboard</div>
          <h2>Welcome back, <?= htmlspecialchars($firstName) ?>!</h2>
          <p>Manage your funds and stay on top of your budget.</p>
        </div>
        <?php if ($wallet): ?>
          <div class="hero-side">
            <span>Sponsored by</span>
            <strong><?= htmlspecialchars($wallet['sponsor_name']) ?></strong>
            <?php if ($wallet['status'] !== 'active'): ?><span class="pill red">Wallet frozen</span><?php endif; ?>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <?php if (!$wallet): ?>
        <section class="card">
          <div class="card-head"><h3>You're not linked to a sponsor yet</h3></div>
          <p class="muted">Ask your sponsor to add you from their dashboard using the email or phone number you registered with. Once they do, your wallet and budget tools appear here.</p>
        </section>

      <?php else: ?>

        <?php // Status of the latest budget proposal ?>
        <?php if ($proposal && $proposal['status'] === 'pending'): ?>
          <div class="notice">
            <span>Your budget proposal of <strong><?= ksh($proposal['total']) ?></strong> is waiting for <?= htmlspecialchars($wallet['sponsor_name']) ?> to approve it.</span>
            <a class="btn btn-accent" href="student-budget.php">View</a>
          </div>
        <?php elseif ($proposal && $proposal['status'] === 'changes_requested'): ?>
          <div class="notice">
            <span>
              <strong><?= htmlspecialchars($wallet['sponsor_name']) ?> asked for changes to your budget.</strong>
              <?php if ($proposal['sponsor_feedback']): ?><br><span class="muted">“<?= htmlspecialchars($proposal['sponsor_feedback']) ?>”</span><?php endif; ?>
            </span>
            <a class="btn btn-accent" href="student-budget.php">Update budget</a>
          </div>
        <?php endif; ?>

        <!-- Four summary cards -->
        <section class="stats" aria-label="Summary">
          <div class="card stat">
            <div class="stat-icon"><?= sw_icon('wallet') ?></div>
            <div class="stat-label">Available Balance</div>
            <div class="stat-value"><?= ksh($wallet['balance']) ?></div>
          </div>
          <div class="card stat">
            <div class="stat-icon"><?= sw_icon('trending') ?></div>
            <div class="stat-label">Total Spent</div>
            <div class="stat-value"><?= ksh($totals['spent']) ?></div>
          </div>
          <div class="card stat">
            <div class="stat-icon"><?= sw_icon('piggy') ?></div>
            <div class="stat-label">Approved Budget</div>
            <div class="stat-value"><?= $budgetRows ? ksh($budgetTotal) : '—' ?></div>
            <?php if (!$budgetRows): ?><div class="stat-note">No approved budget yet</div><?php endif; ?>
          </div>
          <div class="card stat">
            <div class="stat-icon"><?= sw_icon('received') ?></div>
            <div class="stat-label">Funds Received</div>
            <div class="stat-value"><?= ksh($totals['received']) ?></div>
          </div>
        </section>

        <div class="grid-2">
          <!-- My budget -->
          <section class="card">
            <div class="card-head">
              <h3>My budget</h3>
              <a href="student-budget.php">Manage</a>
            </div>
            <?php if (!$budgetRows): ?>
              <p class="empty">
                <?php if ($proposal && $proposal['status'] === 'pending'): ?>
                  Your budget lines will appear here once your sponsor approves your proposal.
                <?php else: ?>
                  You don't have an approved budget yet. Create one and send it to your sponsor.
                <?php endif; ?>
              </p>
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
                      <span><?= ksh($alloc - $spent) ?> left</span>
                    </div>
                    <div class="bar-track" role="img" aria-label="<?= $pct ?>% used">
                      <div class="bar-fill <?= $class ?>" style="width: <?= min(100, $pct) ?>%"></div>
                    </div>
                    <div class="bar-sub"><?= ksh($spent) ?> spent of <?= ksh($alloc) ?> (<?= $pct ?>%)</div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>

          <!-- Quick actions -->
          <section class="card">
            <div class="card-head"><h3>Quick actions</h3></div>
            <div class="actions-grid">
              <a class="action" href="student-budget.php"><?= sw_icon('piggy') ?>Create Budget</a>
              <a class="action" href="record-spending.php#history"><?= sw_icon('receipt') ?>Spending History</a>
              <a class="action" href="student-dashboard.php?tx=funding#transactions"><?= sw_icon('history') ?>Funding History</a>
                            <a class="action action-accent" href="record-spending.php"><?= sw_icon('pen') ?>Record Spending</a>
            </div>
          </section>
        </div>

        <!-- Transactions -->
        <section class="card" id="transactions">
          <div class="card-head">
            <h3><?= $txTitle ?></h3>
            <div class="tabs" role="tablist" aria-label="Filter transactions">
              <a href="student-dashboard.php#transactions" class="<?= $txFilter === 'all' ? 'on' : '' ?>">All</a>
              <a href="student-dashboard.php?tx=funding#transactions" class="<?= $txFilter === 'funding' ? 'on' : '' ?>">Funding</a>
              <a href="student-dashboard.php?tx=spending#transactions" class="<?= $txFilter === 'spending' ? 'on' : '' ?>">Spending</a>
            </div>
          </div>
          <?php if (!$recent): ?>
            <p class="empty">
              <?= $txFilter === 'spending' ? 'No spending recorded yet.' : ($txFilter === 'funding' ? 'No funds received yet.' : 'No transactions yet. Funds your sponsor sends will appear here.') ?>
            </p>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr><th>Date</th><th>Details</th><th>Type</th><th class="num">Amount (KSh)</th><th class="num">Balance (KSh)</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($recent as $t): $in = $t['type'] === 'funding'; ?>
                    <tr>
                      <td><?= htmlspecialchars(date('j M Y, H:i', strtotime($t['created_at']))) ?></td>
                      <td class="wrap"><?= htmlspecialchars($t['description'] ?? '') ?></td>
                      <td><span class="pill <?= $in ? 'green' : 'amber' ?>"><?= $in ? 'Received' : 'Spent' ?></span></td>
                      <td class="num <?= $in ? 'amount-in' : 'amount-out' ?>"><?= $in ? '+' : '−' ?><?= ksh2($t['amount']) ?></td>
                      <td class="num"><?= ksh2($t['balance_after']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </main>
  </div>
</div>
</body>
</html>