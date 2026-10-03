<?php
// Frontend/student-dashboard.php
session_start();
require_once __DIR__ . '/../Backend/auth.php';
require_role('student');           // check login before touching the database
require_once __DIR__ . '/../Backend/db.php';

$studentId = (int) $_SESSION['user_id'];

// This student's wallet and the sponsor who controls it
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

// Last 10 transactions
$transactions = [];
if ($wallet) {
    $stmt = $conn->prepare(
        'SELECT type, amount, balance_after, description, created_at
         FROM transactions
         WHERE wallet_id = ?
         ORDER BY created_at DESC, id DESC
         LIMIT 10'
    );
    $stmt->bind_param('i', $wallet['id']);
    $stmt->execute();
    $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Student Dashboard | SponsorWallet</title>
  <style>
    :root { --forest:#0f3d3a; --forest-2:#17524d; --paper:#f1f5f0; --ink:#1b2a29; --muted:#5d6f6c; --line:#cfdad6; --saffron:#f2b134; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: "Segoe UI", Arial, sans-serif; background: var(--paper); color: var(--ink); line-height: 1.5; }
    header { background: var(--forest); color: #fff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
    header strong { font-size: 1.15rem; }
    header a { color: #fff; font-weight: 600; }
    main { max-width: 760px; margin: 0 auto; padding: 32px 20px 60px; display: grid; gap: 24px; }
    h1 { color: var(--forest); font-size: 1.6rem; }
    h2 { color: var(--forest); font-size: 1.15rem; margin-bottom: 12px; }
    .sub { color: var(--muted); }
    .card { background: #fff; border: 1.5px solid var(--line); border-radius: 14px; padding: 22px; }
    .balance-card { background: var(--forest); color: #fff; border: 0; }
    .balance-card .sub { color: #a9c4bf; }
    .big { font-size: 2.4rem; font-weight: 700; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--line); font-size: .92rem; }
    th { color: var(--muted); font-weight: 600; }
    td.num, th.num { text-align: right; }
    .in { color: #1d6b3a; font-weight: 600; }
    .out { color: #b3261e; font-weight: 600; }
    .table-wrap { overflow-x: auto; }
    .empty { color: var(--muted); }
    .badge { font-size: .75rem; padding: 2px 8px; border-radius: 99px; background: #fde7c0; color: var(--ink); margin-left: 6px; }
  </style>
</head>
<body>
  <header>
    <strong>SponsorWallet</strong>
    <span>Hello, <?= htmlspecialchars($_SESSION['full_name']) ?> &nbsp;|&nbsp; <a href="../Backend/logout.php">Log out</a></span>
  </header>

  <main>
    <h1>Student dashboard</h1>

    <?php if (!$wallet): ?>
      <section class="card">
        <h2>No wallet yet</h2>
        <p class="empty">Your sponsor needs to create a wallet for you. Give them the email or phone number you registered with.</p>
      </section>

    <?php else: ?>
      <section class="card balance-card">
        <div class="sub">Wallet balance
          <?php if ($wallet['status'] !== 'active'): ?><span class="badge"><?= htmlspecialchars($wallet['status']) ?></span><?php endif; ?>
        </div>
        <div class="big">KES <?= number_format((float) $wallet['balance'], 2) ?></div>
        <div class="sub">Sponsored by <?= htmlspecialchars($wallet['sponsor_name']) ?></div>
      </section>

      <section class="card">
        <h2>Recent activity</h2>
        <?php if (!$transactions): ?>
          <p class="empty">No transactions yet. Funds your sponsor adds will show up here.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Date</th><th>Details</th><th class="num">Amount</th><th class="num">Balance</th></tr>
              </thead>
              <tbody>
              <?php foreach ($transactions as $t): ?>
                <tr>
                  <td><?= htmlspecialchars(date('d M Y, H:i', strtotime($t['created_at']))) ?></td>
                  <td><?= htmlspecialchars($t['description'] ?? '') ?></td>
                  <td class="num <?= $t['type'] === 'funding' ? 'in' : 'out' ?>">
                    <?= $t['type'] === 'funding' ? '+' : '-' ?><?= number_format((float) $t['amount'], 2) ?>
                  </td>
                  <td class="num"><?= number_format((float) $t['balance_after'], 2) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>