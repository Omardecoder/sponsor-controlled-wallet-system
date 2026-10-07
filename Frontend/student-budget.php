<?php
// Frontend/student-budget.php
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_role('student');

$studentId = (int) $_SESSION['user_id'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$stmt = $conn->prepare('SELECT id, balance, status FROM wallets WHERE student_id = ? LIMIT 1');
$stmt->bind_param('i', $studentId);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc();
$stmt->close();

$categories = budget_categories();
$alloc = [];
$committed = 0.0;
if ($wallet) {
    $stmt = $conn->prepare('SELECT category, allocated, spent FROM budget_allocations WHERE wallet_id = ?');
    $stmt->bind_param('i', $wallet['id']);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $alloc[$row['category']] = $row;
        $committed += (float) $row['allocated'] - (float) $row['spent'];
    }
    $stmt->close();
}
$balance = $wallet ? (float) $wallet['balance'] : 0.0;
$unallocated = $balance - $committed;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Plan my budget | SponsorWallet</title>
  <style>
    :root { --forest:#0f3d3a; --forest-2:#17524d; --paper:#f1f5f0; --ink:#1b2a29; --muted:#5d6f6c; --line:#cfdad6; --saffron:#f2b134; --danger:#b3261e; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: "Segoe UI", Arial, sans-serif; background: var(--paper); color: var(--ink); line-height: 1.5; }
    header { background: var(--forest); color: #fff; padding: 16px 24px; display: flex; justify-content: space-between; align-items: center; }
    header a { color: #fff; font-weight: 600; }
    main { max-width: 640px; margin: 0 auto; padding: 32px 20px 60px; display: grid; gap: 22px; }
    h1 { color: var(--forest); font-size: 1.6rem; }
    h2 { color: var(--forest); font-size: 1.15rem; margin-bottom: 12px; }
    .sub { color: var(--muted); }
    .card { background: #fff; border: 1.5px solid var(--line); border-radius: 14px; padding: 22px; }
    .alert { padding: 12px 14px; border-radius: 10px; font-weight: 500; }
    .alert.error { background: #fbe9e7; border: 1.5px solid #e8b4ae; color: var(--danger); }
    .alert.success { background: #e4f3ea; border: 1.5px solid #a9d3b9; color: #1d6b3a; }
    .figures { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .figures .num { font-size: 1.5rem; font-weight: 700; color: var(--forest); }
    .figures .num.bad { color: var(--danger); }
    .field { margin-bottom: 14px; }
    label { display: block; font-weight: 600; font-size: .92rem; margin-bottom: 4px; }
    .note { font-weight: 400; color: var(--muted); font-size: .82rem; }
    input[type=number] { width: 100%; font: inherit; padding: 11px 12px; border: 1.5px solid var(--line); border-radius: 10px; }
    input:focus { outline: none; border-color: var(--forest); box-shadow: 0 0 0 3px rgba(242,177,52,.45); }
    button { font: inherit; font-weight: 600; background: var(--forest); color: #fff; border: 0; border-radius: 10px; padding: 13px 18px; cursor: pointer; width: 100%; }
    button:hover { background: var(--forest-2); }
    button:disabled { background: #9db3af; cursor: not-allowed; }
    .warn { color: var(--danger); font-size: .9rem; margin: 6px 0 12px; min-height: 1.2em; }
    a { color: var(--forest); font-weight: 600; }
  </style>
</head>
<body>
  <header>
    <strong>SponsorWallet</strong>
    <span>Hello, <?= htmlspecialchars($_SESSION['full_name']) ?> &nbsp;|&nbsp; <a href="../Backend/logout.php">Log out</a></span>
  </header>

  <main>
    <div>
      <h1>Plan my budget</h1>
      <p class="sub"><a href="student-dashboard.php">&larr; Back to dashboard</a></p>
    </div>

    <?php if ($flash): ?>
      <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status">
        <?= htmlspecialchars($flash['message']) ?>
      </div>
    <?php endif; ?>

    <?php if (!$wallet): ?>
      <section class="card">
        <h2>No wallet yet</h2>
        <p class="sub">Your sponsor needs to create a wallet for you before you can plan a budget.</p>
      </section>

    <?php else: ?>
      <section class="card figures">
        <div>
          <div class="sub">Wallet balance</div>
          <div class="num">KES <?= number_format($balance, 2) ?></div>
        </div>
        <div>
          <div class="sub">Not yet allocated</div>
          <div class="num" id="unalloc">KES <?= number_format($unallocated, 2) ?></div>
        </div>
      </section>

      <section class="card">
        <h2>Set aside money for each category</h2>
        <form action="../Backend/save_budget.php" method="post" id="budget-form"
              data-balance="<?= htmlspecialchars((string) $balance) ?>">
          <?php foreach ($categories as $slug => $label):
                $a = (float) ($alloc[$slug]['allocated'] ?? 0);
                $s = (float) ($alloc[$slug]['spent'] ?? 0); ?>
            <div class="field">
              <label for="alloc-<?= $slug ?>"><?= htmlspecialchars($label) ?>
                <?php if ($s > 0): ?><span class="note">(already spent <?= number_format($s, 2) ?>)</span><?php endif; ?>
              </label>
              <input type="number" class="alloc" id="alloc-<?= $slug ?>" name="alloc[<?= $slug ?>]"
                     min="<?= htmlspecialchars((string) $s) ?>" step="0.01" placeholder="0.00"
                     data-spent="<?= htmlspecialchars((string) $s) ?>"
                     value="<?= $a > 0 ? htmlspecialchars(number_format($a, 2, '.', '')) : '' ?>">
            </div>
          <?php endforeach; ?>

          <div class="warn" id="warn" role="alert"></div>
          <button type="submit" id="save"<?= $wallet['status'] !== 'active' ? ' disabled' : '' ?>>Save budget</button>
          <?php if ($wallet['status'] !== 'active'): ?>
            <p class="warn">Your wallet is frozen, so the budget cannot be changed.</p>
          <?php endif; ?>
        </form>
      </section>
    <?php endif; ?>
  </main>

  <script>
    const form = document.getElementById('budget-form');
    if (form) {
      const balance = parseFloat(form.dataset.balance) || 0;
      const inputs = form.querySelectorAll('.alloc');
      const unalloc = document.getElementById('unalloc');
      const warn = document.getElementById('warn');
      const save = document.getElementById('save');
      const fmt = n => n.toLocaleString('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2});

      function update() {
        let committed = 0, belowSpent = false;
        inputs.forEach(i => {
          const v = parseFloat(i.value) || 0, s = parseFloat(i.dataset.spent) || 0;
          if (v < s) belowSpent = true;
          committed += v - s;
        });
        const left = Math.round((balance - committed) * 100) / 100;
        unalloc.textContent = 'KES ' + fmt(left);
        unalloc.classList.toggle('bad', left < 0);
        warn.textContent = left < 0 ? 'You have allocated more than your balance.'
                         : belowSpent ? 'A category cannot be lower than what you already spent.' : '';
        if (!save.dataset.frozen) save.disabled = left < 0 || belowSpent;
      }
      if (save.disabled) save.dataset.frozen = '1';
      inputs.forEach(i => i.addEventListener('input', update));
      update();
    }
  </script>
</body>
</html>