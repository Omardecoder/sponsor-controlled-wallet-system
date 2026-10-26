<?php
// Frontend/sponsor-proposals.php
// Sponsor: review budget proposals from your students. Approve (and fund) or request changes.
// A student can have several sponsors: every one of them must approve. Each sponsor sees who
// has approved so far, and can top up only from what is left of their own pledge.
// Posts to ../Backend/review_budget_proposal.php.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_once __DIR__ . '/../Backend/sponsorship.php';
require_role('sponsor');

$sponsorId = (int) $_SESSION['user_id'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$labels = budget_categories();   // slug => label

// Proposals from students this sponsor actively sponsors
function load_proposals(mysqli $conn, int $sponsorId, bool $pending): array
{
    $sql = "SELECT p.id, p.status, p.created_at, p.reviewed_at, p.reviewed_by, p.sponsor_feedback,
                   w.id AS wallet_id, w.student_id, w.balance, w.status AS wallet_status,
                   u.full_name AS student_name, u.email, u.phone,
                   sp.pledge, rv.full_name AS reviewer_name,
                   (SELECT COALESCE(SUM(i.proposed_amount), 0) FROM budget_proposal_items i WHERE i.proposal_id = p.id) AS total
            FROM budget_proposals p
            JOIN wallets w ON w.id = p.wallet_id
            JOIN users u   ON u.id = w.student_id
            JOIN sponsorships sp ON sp.student_id = w.student_id AND sp.sponsor_id = ? AND sp.status = 'active'
            LEFT JOIN users rv ON rv.id = p.reviewed_by
            WHERE " . ($pending ? "p.status = 'pending'" : "p.status <> 'pending'") . "
            ORDER BY " . ($pending ? 'p.created_at ASC, p.id ASC' : 'p.reviewed_at DESC, p.id DESC LIMIT 10');
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $sponsorId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$proposals = load_proposals($conn, $sponsorId, true);
$reviewed  = load_proposals($conn, $sponsorId, false);

// For each pending proposal: who has approved, and what is left of this sponsor's pledge
$approvals = [];
$myLeft    = [];
$pledgeSum = [];
foreach ($proposals as $p) {
    $pid = (int) $p['id'];
    $approvals[$pid] = proposal_approvals($conn, $pid, (int) $p['student_id']);
    $myLeft[$pid]    = max(0, (float) $p['pledge'] - sponsor_pledge_used($conn, $sponsorId, (int) $p['wallet_id']));
    $pledgeSum[$pid] = pledge_total($conn, (int) $p['student_id']);
}
$waitingForMe = count(array_filter($proposals, function ($p) use ($approvals, $sponsorId) {
    foreach ($approvals[(int) $p['id']]['sponsors'] as $s) {
        if ((int) $s['sponsor_id'] === $sponsorId) {
            return $s['decision'] === null;
        }
    }
    return false;
}));

// Category lines for each pending proposal
$items = [];
$stmt = $conn->prepare('SELECT category, proposed_amount FROM budget_proposal_items WHERE proposal_id = ? ORDER BY id');
foreach ($proposals as $p) {
    $pid = (int) $p['id'];
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $items[$pid] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// The sponsor's own phone, used as the default M-Pesa number for top-ups
$stmt = $conn->prepare('SELECT phone FROM users WHERE id = ?');
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$sponsorPhone = (string) ($stmt->get_result()->fetch_assoc()['phone'] ?? '');
$stmt->close();

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));

function kes2($v): string { return number_format((float) $v, 2); }

$statusPill = [
    'approved'          => ['Approved', 'green'],
    'changes_requested' => ['Changes requested', 'amber'],
];

$active = 'proposals';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Budget Proposals | SponsorWallet</title>
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
      <h1>Budget Proposals</h1>
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
          <h2>Waiting for your review (<?= $waitingForMe ?>)</h2>
          <p class="muted">Approve a student's plan and add your share of the money, or send it back with feedback. When a student has several sponsors, every sponsor must approve.</p>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <?php if (!$proposals): ?>
        <section class="card"><p class="empty">No proposals waiting. When one of your students submits a budget, it appears here.</p></section>
      <?php endif; ?>

      <?php foreach ($proposals as $p):
          $pid       = (int) $p['id'];
          $total     = (float) $p['total'];
          $balance   = (float) $p['balance'];
          $shortfall = (int) ceil(max(0, $total - $balance));   // whole shillings for M-Pesa
          $frozen    = $p['wallet_status'] !== 'active';
          $state     = $approvals[$pid];
          $multi     = $state['needed'] > 1;
          $myShare   = $pledgeSum[$pid] > 0 ? (float) $p['pledge'] / $pledgeSum[$pid] : 1;
          $suggest   = (int) min(floor($myLeft[$pid]), ceil($shortfall * $myShare));   // my share of what is missing
          $decided   = false;
          foreach ($state['sponsors'] as $s) {
              if ((int) $s['sponsor_id'] === $sponsorId && $s['decision'] !== null) {
                  $decided = true;
              }
          }
      ?>
        <section class="card proposal">
          <div class="card-head">
            <div>
              <h3><?= htmlspecialchars($p['student_name']) ?></h3>
              <div class="muted small"><?= htmlspecialchars($p['email']) ?> · sent <?= htmlspecialchars(date('j M Y, H:i', strtotime($p['created_at']))) ?></div>
            </div>
            <div class="stat-value">KES <?= number_format($total, 0) ?></div>
          </div>

          <?php if ($multi): ?>
            <div class="approval-strip">
              <span class="muted small"><?= $state['approved'] ?> of <?= $state['needed'] ?> sponsors approved:</span>
              <?php foreach ($state['sponsors'] as $s): ?>
                <span class="pill <?= $s['decision'] === 'approved' ? 'green' : 'amber' ?>">
                  <?= (int) $s['sponsor_id'] === $sponsorId ? 'You' : htmlspecialchars($s['full_name']) ?>: <?= $s['decision'] === 'approved' ? 'approved' : 'waiting' ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="proposal-body">
            <div class="table-wrap">
              <table>
                <thead><tr><th>Category</th><th class="num">Amount (KES)</th></tr></thead>
                <tbody>
                  <?php foreach ($items[$pid] as $it): ?>
                    <tr>
                      <td><?= htmlspecialchars($labels[$it['category']] ?? $it['category']) ?></td>
                      <td class="num"><?= kes2($it['proposed_amount']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="total-row"><td>Total</td><td class="num"><?= kes2($total) ?></td></tr>
                </tbody>
              </table>
            </div>

            <?php if ($decided): ?>
              <div class="form-grid">
                <div class="alert success">You approved this budget. It becomes active when the other sponsor<?= $state['needed'] - $state['approved'] === 1 ? '' : 's' ?> approve<?= $state['needed'] - $state['approved'] === 1 ? 's' : '' ?>.</div>
                <div class="money-box">
                  <div><span>In the wallet now</span><b>KES <?= kes2($balance) ?></b></div>
                  <div><span>Left of your pledge</span><b>KES <?= kes2($myLeft[$pid]) ?></b></div>
                </div>
              </div>
            <?php else: ?>
            <form action="../Backend/review_budget_proposal.php" method="post" class="form-grid">
              <input type="hidden" name="proposal_id" value="<?= $pid ?>">

              <div class="money-box">
                <div><span>In the wallet now</span><b>KES <?= kes2($balance) ?></b></div>
                <div class="<?= $shortfall > 0 ? 'short' : '' ?>"><span>Still needed</span><b>KES <?= kes2($shortfall) ?></b></div>
                <div><span>Left of your pledge</span><b>KES <?= kes2($myLeft[$pid]) ?></b></div>
              </div>

              <div class="two-col">
                <div>
                  <label for="fund-<?= $pid ?>">M-Pesa top-up (KES)</label>
                  <input type="number" id="fund-<?= $pid ?>" name="fund_amount" min="0" max="<?= (int) min(250000, floor($myLeft[$pid])) ?>" step="1"
                  value="<?= max(0, $suggest) ?>" <?= $frozen ? 'disabled' : '' ?>>
                </div>
                <div>
                  <label for="phone-<?= $pid ?>">Pay from number</label>
                  <input type="tel" id="phone-<?= $pid ?>" name="phone" placeholder="0712 345 678"
                  value="<?= htmlspecialchars($sponsorPhone) ?>" <?= $frozen ? 'disabled' : '' ?>>
                </div>
              </div>
              <p class="muted small" style="margin-top:-6px;">
                <?= $frozen ? 'This wallet is frozen, so it can\'t be topped up.'
                    : ($multi ? 'Pre-filled with your share of what\'s still needed (your pledge is ' . round($myShare * 100) . '% of all pledges). '
                              : 'Pre-filled with what\'s still needed. ')
                      . 'If above 0, you\'ll get an M-Pesa prompt after approving. Set 0 to approve without topping up.' ?>
              </p>

              <div>
                <label for="feedback-<?= $pid ?>">Feedback for the student <span class="muted" style="font-weight:400;">(required for Request changes)</span></label>
                <textarea id="feedback-<?= $pid ?>" name="feedback" rows="3" maxlength="1000" placeholder="e.g. Please reduce Entertainment and move it to Food."></textarea>
              </div>

              <div class="row">
                <button type="submit" name="decision" value="approve" class="btn"
                        onclick="return confirm('Approve this budget? If a top-up amount is entered, an M-Pesa prompt will be sent to your phone.');">Approve</button>
                <button type="submit" name="decision" value="changes" class="btn btn-outline">Request changes</button>
              </div>
            </form>
            <?php endif; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <section class="card">
        <div class="card-head"><h3>Recently reviewed</h3></div>
        <?php if (!$reviewed): ?>
          <p class="empty">Nothing reviewed yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Student</th><th>Decided</th><th>Decision</th><th>Feedback</th><th class="num">Total (KES)</th></tr></thead>
              <tbody>
                <?php foreach ($reviewed as $r): [$label, $color] = $statusPill[$r['status']] ?? [ucfirst($r['status']), 'amber']; ?>
                  <tr>
                    <td><?= htmlspecialchars($r['student_name']) ?></td>
                    <td><?= $r['reviewed_at'] ? htmlspecialchars(date('j M Y', strtotime($r['reviewed_at']))) : '' ?>
                      <?php if ($r['reviewer_name']): ?><div class="muted small">by <?= (int) $r['reviewed_by'] === $sponsorId ? 'you' : htmlspecialchars($r['reviewer_name']) ?></div><?php endif; ?></td>
                    <td><span class="pill <?= $color ?>"><?= $label ?></span></td>
                    <td class="wrap muted"><?= htmlspecialchars($r['sponsor_feedback'] ?? '') ?></td>
                    <td class="num"><?= kes2($r['total']) ?></td>
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