<?php
// Frontend/student-dashboard.php
// Student overview: sponsor requests, funders and their shares, balance, budget, transactions.
// A student can have several sponsors; they all pay into this one wallet.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_once __DIR__ . '/../Backend/sponsorship.php';
require_role('student');

$studentId = (int) $_SESSION['user_id'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---- The student's wallet (created when they accept their first sponsor) ----
$stmt = $conn->prepare('SELECT id, balance, status FROM wallets WHERE student_id = ? LIMIT 1');
$stmt->bind_param('i', $studentId);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ---- Sponsors: accepted ones, and requests waiting for an answer ----
$allLinks  = student_sponsors($conn, $studentId, false);
$sponsors  = array_values(array_filter($allLinks, fn($s) => $s['status'] === 'active'));
$requests  = array_values(array_filter($allLinks, fn($s) => $s['status'] === 'pending'));
$sponsorNames = implode(' & ', array_column($sponsors, 'full_name'));

$totals     = ['received' => 0, 'spent' => 0];
$funding    = null;   // funding status of a budget that is waiting
$shares     = [];     // sponsor_id => that funder's part of the current budget
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

    // Latest budget proposal, who asked for changes, and who has approved so far
    $stmt = $conn->prepare(
        'SELECT p.id, p.status, p.sponsor_feedback, p.created_at,
                CASE WHEN EXISTS (SELECT 1 FROM sponsorships sa JOIN wallets wa ON wa.student_id = sa.student_id
                                 WHERE wa.id = p.wallet_id AND sa.sponsor_id = p.reviewed_by AND sa.is_anonymous = 1)
                     THEN \'An anonymous well-wisher\' ELSE r.full_name END AS reviewer_name,
                (SELECT COALESCE(SUM(i.proposed_amount), 0) FROM budget_proposal_items i WHERE i.proposal_id = p.id) AS total
         FROM budget_proposals p
         LEFT JOIN users r ON r.id = p.reviewed_by
         WHERE p.wallet_id = ?
         ORDER BY p.id DESC
         LIMIT 1'
    );
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $proposal = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($proposal && $proposal['status'] === 'pending') {
        $funding = proposal_funding($conn, (int) $proposal['id'], $studentId);
    }

    // Recent transactions
    $sql = "SELECT t.type, t.amount, t.balance_after, t.description, t.created_at,
                   CASE WHEN EXISTS (SELECT 1 FROM sponsorships sa WHERE sa.sponsor_id = t.created_by
                                      AND sa.student_id = u2.student_id AND sa.is_anonymous = 1)
                        THEN 'Anonymous well-wisher' ELSE u.full_name END AS by_name
            FROM transactions t JOIN users u ON u.id = t.created_by JOIN wallets u2 ON u2.id = t.wallet_id
            WHERE t.wallet_id = ?"
         . ($txFilter !== 'all' ? ' AND t.type = ?' : '')
         . ' ORDER BY t.created_at DESC, t.id DESC LIMIT 15';
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
  <title>Student Dashboard | SS-Wallet</title>
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
        <?php if ($wallet && $sponsors): ?>
          <div class="hero-side">
            <span>Sponsored by</span>
            <strong><?= htmlspecialchars($sponsorNames) ?></strong>
            <?php if ($wallet['status'] !== 'active'): ?><span class="pill red">Wallet frozen</span><?php endif; ?>
          </div>
        <?php endif; ?>
      </section>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <?php if ($requests): ?>
        <!-- Sponsorship requests waiting for an answer -->
        <section class="card requests" id="requests">
          <div class="card-head"><h3>Sponsorship request<?= count($requests) === 1 ? '' : 's' ?></h3></div>
          <?php foreach ($requests as $r): ?>
            <div class="request-row">
              <div>
                <strong><?= htmlspecialchars($r['full_name']) ?></strong> wants to sponsor you<?= (float) $r['pledge'] > 0 ? ' with up to <strong>' . ksh($r['pledge']) . '</strong> per budget' : '' ?>.
                <?php if ($r['message']): ?><div class="muted">“<?= htmlspecialchars($r['message']) ?>”</div><?php endif; ?>
                <?php if (!empty($r['is_anonymous'])): ?><div class="muted small">This well-wisher has chosen to stay anonymous. The administrator knows who they are.</div><?php endif; ?>
                <div class="muted small">If you accept, they can see your wallet and spending, and can fund a share of your budgets.</div>
              </div>
              <div class="row">
                <form action="../Backend/respond_sponsorship.php" method="post" class="inline-action">
                  <input type="hidden" name="sponsorship_id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="decision" value="accept">
                  <button type="submit" class="btn">Accept</button>
                </form>
                <form action="../Backend/respond_sponsorship.php" method="post" class="inline-action"
                      onsubmit="return confirm('Decline the request from <?= htmlspecialchars(addslashes($r['full_name'])) ?>?');">
                  <input type="hidden" name="sponsorship_id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="decision" value="decline">
                  <button type="submit" class="btn btn-outline">Decline</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>

      <?php if (!$wallet || !$sponsors): ?>
        <section class="card">
          <div class="card-head"><h3>You're not linked to a sponsor yet</h3></div>
          <p class="muted">Ask a sponsor to add you from their dashboard using the email or phone number you registered with, or <a href="student-programs.php">apply to a sponsorship program</a> from an organization. Once you have a sponsor, your wallet and budget tools appear here.</p>
        </section>

      <?php else: ?>

        <?php // Status of the latest budget proposal ?>
        <?php if ($proposal && $proposal['status'] === 'pending'): ?>
          <div class="notice">
            <span>Your budget of <strong><?= ksh($proposal['total']) ?></strong> is <strong><?= $funding['percent'] ?>% funded</strong>
              (<?= ksh($funding['committed']) ?> committed, <?= ksh($funding['remaining']) ?> still uncovered).
              <br><span class="muted">You can wait for the rest, or start with what is funded on your budget page.</span></span>
            <a class="btn btn-accent" href="student-budget.php">View</a>
          </div>
        <?php elseif ($proposal && $proposal['status'] === 'changes_requested'): ?>
          <div class="notice">
            <span>
              <strong><?= htmlspecialchars($proposal['reviewer_name'] ?? 'Your sponsor') ?> asked for changes to your budget.</strong>
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

        <!-- My funders and their part of the current budget -->
        <section class="card" id="sponsors">
          <div class="card-head">
            <h3>My funders</h3>
            <span class="muted small">Their part of your current budget</span>
          </div>
          <div class="bars">
            <?php foreach ($sponsors as $i => $s):
                $share = sponsor_current_share($conn, (int) $s['sponsor_id'], (int) $wallet['id']);
                $pct   = $share && $share['committed'] > 0 ? min(100, round($share['sent'] / $share['committed'] * 100)) : 0;
            ?>
              <div>
                <div class="bar-top">
                  <b>Funder <?= $i + 1 ?>: <?= htmlspecialchars($s['full_name']) ?></b>
                  <span>
                    <?php if ($share): ?>
                      <?= ksh($share['sent']) ?> sent of <?= ksh($share['committed']) ?> (<?= $share['total'] > 0 ? round($share['committed'] / $share['total'] * 100, 1) : 0 ?>% of the budget)
                    <?php else: ?>
                      No share of your current budget yet
                    <?php endif; ?>
                  </span>
                </div>
                <div class="bar-track" role="img" aria-label="<?= $pct ?>% of their share sent">
                  <div class="bar-fill" style="width: <?= $pct ?>%"></div>
                </div>
                <div class="bar-sub"><?= ksh($s['funded']) ?> sent to you in total<?= (float) $s['pledge'] > 0 ? ' · limit ' . ksh($s['pledge']) . ' per budget' : '' ?></div>
              </div>
            <?php endforeach; ?>
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
                  Your budget lines will appear here once your sponsors approve your proposal.
                <?php else: ?>
                  You don't have an approved budget yet. Create one and send it to your sponsors.
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
              <?= $txFilter === 'spending' ? 'No spending recorded yet.' : ($txFilter === 'funding' ? 'No funds received yet.' : 'No transactions yet. Funds your sponsors send will appear here.') ?>
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
                      <td class="wrap"><?= htmlspecialchars($t['description'] ?? '') ?><?= $in ? '<br><span class="muted small">From ' . htmlspecialchars($t['by_name']) . '</span>' : '' ?></td>
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