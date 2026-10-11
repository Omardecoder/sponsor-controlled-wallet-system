<?php
// Frontend/sponsor-proposals.php
// Sponsor: see budget proposals from students whose wallet you control.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_role('sponsor');

$sponsorId = (int) $_SESSION['user_id'];
$labels = budget_categories();   // slug => label

// The link: budget_proposals.wallet_id -> wallets.sponsor_id
$stmt = $conn->prepare(
    "SELECT p.id, p.status, p.created_at, w.balance,
            u.full_name AS student_name, u.email, u.phone,
            COALESCE(SUM(i.proposed_amount), 0) AS total
     FROM budget_proposals p
     JOIN wallets w ON w.id = p.wallet_id
     JOIN users u   ON u.id = w.student_id
     LEFT JOIN budget_proposal_items i ON i.proposal_id = p.id
     WHERE w.sponsor_id = ? AND p.status = 'pending'
     GROUP BY p.id
     ORDER BY p.created_at ASC"
);
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$proposals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Category lines for each proposal
$items = [];
$stmt = $conn->prepare('SELECT category, proposed_amount FROM budget_proposal_items WHERE proposal_id = ? ORDER BY id');
foreach ($proposals as $p) {
    $pid = (int) $p['id'];
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $items[$pid] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Budget Proposals | SponsorWallet</title>
  <style>
    :root { --forest:#0f3d3a; --paper:#f1f5f0; --ink:#1b2a29; --muted:#5d6f6c; --line:#cfdad6; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: "Segoe UI", Arial, sans-serif; background: var(--paper); color: var(--ink); line-height: 1.5; }
    header { background: var(--forest); color: #fff; padding: 16px 24px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
    header a { color: #fff; font-weight: 600; }
    main { max-width: 860px; margin: 0 auto; padding: 32px 20px; display: grid; gap: 20px; }
    h1 { color: var(--forest); }
    .card { background: #fff; border: 1.5px solid var(--line); border-radius: 14px; padding: 20px; }
    .head { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .muted { color: var(--muted); font-size: .9rem; }
    .total { font-size: 1.4rem; font-weight: 700; color: var(--forest); }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    td, th { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--line); }
    .num { text-align: right; }
  </style>
</head>
<body>
  <header>
    <strong>SponsorWallet</strong>
    <span><a href="sponsor-dashboard.php">Dashboard</a> | <a href="../Backend/logout.php">Log out</a></span>
  </header>
  <main>
    <h1>Budget proposals (<?= count($proposals) ?>)</h1>

    <?php if (!$proposals): ?>
      <div class="card muted">No proposals waiting. When one of your students submits a budget, it appears here.</div>
    <?php endif; ?>

    <?php foreach ($proposals as $p): $pid = (int) $p['id']; ?>
      <div class="card">
        <div class="head">
          <div>
            <strong><?= htmlspecialchars($p['student_name']) ?></strong>
            <div class="muted"><?= htmlspecialchars($p['email']) ?> · sent <?= htmlspecialchars(date('j M Y, H:i', strtotime($p['created_at']))) ?></div>
          </div>
          <div class="total">KES <?= number_format((float) $p['total'], 2) ?></div>
        </div>
        <table>
          <tr><th>Category</th><th class="num">Amount (KES)</th></tr>
          <?php foreach ($items[$pid] as $it): ?>
            <tr>
              <td><?= htmlspecialchars($labels[$it['category']] ?? $it['category']) ?></td>
              <td class="num"><?= number_format((float) $it['proposed_amount'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
        <p class="muted" style="margin-top:10px;">Wallet balance now: KES <?= number_format((float) $p['balance'], 2) ?></p>
      </div>
    <?php endforeach; ?>
  </main>
</body>
</html>