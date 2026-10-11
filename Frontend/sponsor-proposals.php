<?php
// Frontend/sponsor-proposals.php
// Sponsor: review budget proposals from your students. Approve (and fund) or request changes.
// Posts to ../Backend/review_budget_proposal.php.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_role('sponsor');

$sponsorId = (int) $_SESSION['user_id'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$labels = budget_categories();   // slug => label

// Proposals on wallets this sponsor controls (budget_proposals.wallet_id -> wallets.sponsor_id)
function load_proposals(mysqli $conn, int $sponsorId, bool $pending): array
{
    $sql = "SELECT p.id, p.status, p.created_at, p.reviewed_at, p.sponsor_feedback,
                   w.balance, w.status AS wallet_status,
                   u.full_name AS student_name, u.email, u.phone,
                   COALESCE(SUM(i.proposed_amount), 0) AS total
            FROM budget_proposals p
            JOIN wallets w ON w.id = p.wallet_id
            JOIN users u   ON u.id = w.student_id
            LEFT JOIN budget_proposal_items i ON i.proposal_id = p.id
            WHERE w.sponsor_id = ? AND " . ($pending ? "p.status = 'pending'" : "p.status <> 'pending'") . "
            GROUP BY p.id
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
          <h2>Waiting for your review (<?= count($proposals) ?>)</h2>
          <p class="muted">Approve a student's plan and add the money it needs, or send it back with feedback.</p>
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
          $shortfall = max(0, $total - $balance);
          $frozen    = $p['wallet_status'] !== 'active';
      ?>
        <section class="card proposal">
          <div class="card-head">
            <div>
              <h3><?= htmlspecialchars($p['student_name']) ?></h3>
              <div class="muted small"><?= htmlspecialchars($p['email']) ?> · sent <?= htmlspecialchars(date('j M Y, H:i', strtotime($p['created_at']))) ?></div>
            </div>
            <div class="stat-value">KES <?= number_format($total, 0) ?></div>
          </div>

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

            <form action="../Backend/review_budget_proposal.php" method="post" class="form-grid">
              <input type="hidden" name="proposal_id" value="<?= $pid ?>">

              <div class="money-box">
                <div><span>In the wallet now</span><b>KES <?= kes2($balance) ?></b></div>
                <div class="<?= $shortfall > 0 ? 'short' : '' ?>"><span>Still needed</span><b>KES <?= kes2($shortfall) ?></b></div>
              </div>

              <div>
                <label for="fund-<?= $pid ?>">Add to wallet when approving (KES)</label>
                <input type="number" id="fund-<?= $pid ?>" name="fund_amount" min="0" step="0.01"
                       value="<?= number_format($shortfall, 2, '.', '') ?>" <?= $frozen ? 'disabled' : '' ?>>
                <p class="muted small" style="margin-top:4px;">
                  <?= $frozen ? 'This wallet is frozen, so money can\'t be added.' : 'Pre-filled with what\'s still needed. Set 0 to approve without adding money.' ?>
                </p>
              </div>

              <div>
                <label for="feedback-<?= $pid ?>">Feedback for the student <span class="muted" style="font-weight:400;">(required for Request changes)</span></label>
                <textarea id="feedback-<?= $pid ?>" name="feedback" rows="3" maxlength="1000" placeholder="e.g. Please reduce Entertainment and move it to Food."></textarea>
              </div>

              <div class="row">
                <button type="submit" name="decision" value="approve" class="btn"
                        onclick="return confirm('Approve this budget and add the amount shown to the wallet?');">Approve</button>
                <button type="submit" name="decision" value="changes" class="btn btn-outline">Request changes</button>
              </div>
            </form>
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
              <thead><tr><th>Student</th><th>Reviewed</th><th>Decision</th><th>Feedback</th><th class="num">Total (KES)</th></tr></thead>
              <tbody>
                <?php foreach ($reviewed as $r): [$label, $color] = $statusPill[$r['status']] ?? [ucfirst($r['status']), 'amber']; ?>
                  <tr>
                    <td><?= htmlspecialchars($r['student_name']) ?></td>
                    <td><?= $r['reviewed_at'] ? htmlspecialchars(date('j M Y', strtotime($r['reviewed_at']))) : '' ?></td>
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