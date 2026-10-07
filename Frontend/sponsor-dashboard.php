<?php
// Frontend/sponsor-dashboard.php
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_role('sponsor');

$sponsorId = (int) $_SESSION['user_id'];

// One-time message from create_wallet.php / fund_wallet.php
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// This sponsor's wallets, with the student's details
$stmt = $conn->prepare(
    'SELECT w.id, w.balance, w.status, u.full_name, u.email, u.phone
     FROM wallets w
     JOIN users u ON u.id = w.student_id
     WHERE w.sponsor_id = ?
     ORDER BY w.created_at DESC'
);
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$wallets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total = array_sum(array_column($wallets, 'balance'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sponsor Dashboard | SponsorWallet</title>
  <style>
    :root { --forest:#0f3d3a; --forest-2:#17524d; --paper:#f1f5f0; --ink:#1b2a29; --muted:#5d6f6c; --line:#cfdad6; --saffron:#f2b134; --danger:#b3261e; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: "Segoe UI", Arial, sans-serif; background: var(--paper); color: var(--ink); line-height: 1.5; }
    header { background: var(--forest); color: #fff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
    header strong { font-size: 1.15rem; }
    header a { color: #fff; font-weight: 600; }
    main { max-width: 900px; margin: 0 auto; padding: 32px 20px 60px; display: grid; gap: 24px; }
    h1 { color: var(--forest); font-size: 1.6rem; }
    h2 { color: var(--forest); font-size: 1.15rem; margin-bottom: 12px; }
    .sub { color: var(--muted); }
    .card { background: #fff; border: 1.5px solid var(--line); border-radius: 14px; padding: 22px; }
    .summary { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 8px; }
    .big { font-size: 2rem; font-weight: 700; color: var(--forest); }
    .alert { padding: 12px 14px; border-radius: 10px; font-weight: 500; }
    .alert.error { background: #fbe9e7; border: 1.5px solid #e8b4ae; color: var(--danger); }
    .alert.success { background: #e4f3ea; border: 1.5px solid #a9d3b9; color: #1d6b3a; }
    label { display: block; font-weight: 600; font-size: .9rem; margin-bottom: 4px; }
    input[type=text], input[type=number] { width: 100%; font: inherit; padding: 11px 12px; border: 1.5px solid var(--line); border-radius: 10px; }
    input:focus { outline: none; border-color: var(--forest); box-shadow: 0 0 0 3px rgba(242,177,52,.45); }
    .row { display: flex; gap: 12px; align-items: end; flex-wrap: wrap; }
    .row > div { flex: 1; min-width: 160px; }
    button { font: inherit; font-weight: 600; background: var(--forest); color: #fff; border: 0; border-radius: 10px; padding: 11px 18px; cursor: pointer; }
    button:hover { background: var(--forest-2); }
    button:focus-visible { outline: 3px solid var(--saffron); outline-offset: 2px; }
    .wallet { border-top: 1px solid var(--line); padding-top: 18px; margin-top: 18px; }
    .wallet:first-of-type { border-top: 0; padding-top: 0; margin-top: 0; }
    .wallet-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; }
    .wallet-head strong { font-size: 1.05rem; }
    .contact { color: var(--muted); font-size: .88rem; }
    .balance { font-size: 1.4rem; font-weight: 700; color: var(--forest); }
    .badge { font-size: .75rem; padding: 2px 8px; border-radius: 99px; background: #fde7c0; margin-left: 6px; }
    .empty { color: var(--muted); }
  </style>
</head>
<body>
  <header>
    <strong>SponsorWallet</strong>
    <span>Hello, <?= htmlspecialchars($_SESSION['full_name']) ?> &nbsp;|&nbsp; <a href="../Backend/logout.php">Log out</a></span>
  </header>

  <main>
    <div>
      <h1>Sponsor dashboard</h1>
      <p class="sub">Create wallets for students and add money to them.</p>
    </div>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status">
        <?= htmlspecialchars($flash['message']) ?>
      </div>
    <?php endif; ?>

    <section class="card summary">
      <div>
        <div class="sub">Total across your wallets</div>
        <div class="big">KES <?= number_format((float) $total, 2) ?></div>
      </div>
      <div class="sub"><?= count($wallets) ?> wallet<?= count($wallets) === 1 ? '' : 's' ?></div>
    </section>

    <section class="card">
      <h2>Create a wallet for a student</h2>
      <form action="../Backend/create_wallet.php" method="post">
        <div class="row">
          <div>
            <label for="student_identifier">Student's email or phone number</label>
            <input type="text" id="student_identifier" name="student_identifier" placeholder="student@example.com or 0712 345 678" required>
          </div>
          <button type="submit">Create wallet</button>
        </div>
      </form>
      <p class="sub" style="margin-top:8px;font-size:.85rem;">The student must already have a Student account.</p>
    </section>

    <section class="card">
      <h2>Your wallets</h2>
      <?php if (!$wallets): ?>
        <p class="empty">No wallets yet. Create one above to get started.</p>
      <?php endif; ?>

      <?php foreach ($wallets as $w): ?>
        <div class="wallet">
          <div class="wallet-head">
            <div>
              <strong><?= htmlspecialchars($w['full_name']) ?></strong>
              <?php if ($w['status'] !== 'active'): ?><span class="badge"><?= htmlspecialchars($w['status']) ?></span><?php endif; ?>
              <div class="contact"><?= htmlspecialchars($w['email']) ?> &middot; <?= htmlspecialchars($w['phone']) ?></div>
            </div>
            <div class="balance">KES <?= number_format((float) $w['balance'], 2) ?></div>
          </div>

          <form action="../Backend/fund_wallet.php" method="post">
            <input type="hidden" name="wallet_id" value="<?= (int) $w['id'] ?>">
            <div class="row">
              <div>
                <label for="amount-<?= (int) $w['id'] ?>">Amount (KES)</label>
                <input type="number" id="amount-<?= (int) $w['id'] ?>" name="amount" min="1" step="0.01" placeholder="1500" required>
              </div>
              <div>
                <label for="note-<?= (int) $w['id'] ?>">Note (optional)</label>
                <input type="text" id="note-<?= (int) $w['id'] ?>" name="note" maxlength="255" placeholder="e.g. Term 2 fees">
              </div>
              <button type="submit">Add funds</button>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </section>
  </main>
</body>
</html>
