<?php
// Frontend/record-spending.php
// Student: record an expense against an approved budget line (optional receipt),
// and see spending history. Posts to ../Backend/record_expense.php.
// Step 32: the budget line is suggested while the student types the description (same word list
// as Backend/auto_category.php). "Choose for me" lets the server pick it.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_once __DIR__ . '/../Backend/auto_category.php';
require_role('student');

$studentId = (int) $_SESSION['user_id'];

$flash = $_SESSION['flash'] ?? null;
$old   = $_SESSION['expense_old'] ?? [];
unset($_SESSION['flash'], $_SESSION['expense_old']);

$labels = budget_categories();   // slug => label

$stmt = $conn->prepare(
    'SELECT w.id, w.balance, w.status
     FROM wallets w
     WHERE w.student_id = ? LIMIT 1'
);
$stmt->bind_param('i', $studentId);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc();
$stmt->close();

$lines    = [];   // approved budget lines
$expenses = [];   // history

// Optional filter for the history: ?cat=food
$catFilter = array_key_exists($_GET['cat'] ?? '', $labels) ? $_GET['cat'] : '';

if ($wallet) {
    $walletId = (int) $wallet['id'];

    $stmt = $conn->prepare(
        'SELECT category, allocated, spent FROM budget_allocations
         WHERE wallet_id = ? AND allocated > 0 ORDER BY allocated DESC'
    );
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $sql = 'SELECT id, category, description, amount, expense_date, receipt_file, receipt_name, created_at
            FROM expenses WHERE wallet_id = ?' . ($catFilter ? ' AND category = ?' : '') . '
            ORDER BY expense_date DESC, id DESC LIMIT 50';
    $stmt = $conn->prepare($sql);
    if ($catFilter) {
        $stmt->bind_param('is', $walletId, $catFilter);
    } else {
        $stmt->bind_param('i', $walletId);
    }
    $stmt->execute();
    $expenses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$canRecord = $wallet && $wallet['status'] === 'active' && $lines;

// Words that point to each budget line (for the suggestion while typing)
$wordMap = [];
if ($lines) {
    $lineNames = [];
    foreach ($lines as $l) {
        $lineNames[$l['category']] = $labels[$l['category']] ?? $l['category'];
    }
    $wordMap = category_word_map($conn, (int) $wallet['id'], $lineNames);
}
$old += ['description' => '', 'amount' => '', 'category' => '', 'expense_date' => date('Y-m-d')];

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));

function ksh($v): string { return 'KSh ' . number_format((float) $v, 2); }
function ksh0($v): string { return 'KSh ' . number_format((float) $v, 0); }

$active = (($_GET['view'] ?? '') === 'history') ? 'spending' : 'record';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Record Spending | SS-Wallet</title>
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
      <h1>Record Spending</h1>
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
      <div class="page-head">
        <div>
          <h2>Record an expense</h2>
          <p class="muted">Log what you spent and which budget line it came from. Attaching a receipt is optional but helps your sponsor.</p>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <?php if (!$wallet): ?>
        <section class="card"><p class="empty">You're not linked to a sponsor yet, so there is nothing to record against.</p></section>

      <?php else: ?>
        <div class="grid-2">
          <!-- The form -->
          <section class="card">
            <div class="card-head"><h3>New expense</h3></div>

            <?php if (!$lines): ?>
              <p class="empty">You need an approved budget before you can record spending. <a href="student-budget.php">Create a budget</a> and send it to your sponsor.</p>
            <?php elseif ($wallet['status'] !== 'active'): ?>
              <p class="alert error">Your wallet is frozen, so you can't record spending. Contact your sponsor.</p>
            <?php else: ?>
              <form action="../Backend/record_expense.php" method="post" enctype="multipart/form-data" class="form-grid" id="expense-form">
                <div>
                  <label for="description">Expense description</label>
                  <input type="text" id="description" name="description" maxlength="255" required
                         placeholder="e.g. Lunch at the cafeteria" value="<?= htmlspecialchars($old['description']) ?>">
                </div>

                <div class="two-col">
                  <div>
                    <label for="amount">Amount (KSh)</label>
                    <input type="number" id="amount" name="amount" min="0.01" step="0.01" inputmode="decimal" required
                           placeholder="e.g. 350" value="<?= htmlspecialchars($old['amount']) ?>">
                  </div>
                  <div>
                    <label for="category">Budget line</label>
                    <select id="category" name="category">
                      <option value="">Choose for me (from the description)</option>
                      <?php foreach ($lines as $l): $left = (float) $l['allocated'] - (float) $l['spent']; ?>
                        <option value="<?= htmlspecialchars($l['category']) ?>" data-left="<?= $left ?>"
                                <?= $old['category'] === $l['category'] ? 'selected' : '' ?> <?= $left <= 0 ? 'disabled' : '' ?>>
                          <?= htmlspecialchars($labels[$l['category']] ?? $l['category']) ?> — <?= ksh0(max(0, $left)) ?> left
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <p class="cat-hint" id="cat-hint" aria-live="polite"></p>
                  </div>
                </div>

                <div>
                  <label for="expense_date">Date of expense</label>
                  <input type="date" id="expense_date" name="expense_date" required
                         max="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d', strtotime('-1 year')) ?>"
                         value="<?= htmlspecialchars($old['expense_date']) ?>">
                </div>

                <label class="dropzone" for="receipt">
                  <?= sw_icon('receipt') ?>
                  <strong id="receipt-label">Attach receipt (optional)</strong>
                  <span class="muted small">JPG, PNG or PDF · up to 5 MB</span>
                  <input type="file" id="receipt" name="receipt" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
                </label>

                <p class="form-warn" id="warn" role="alert"></p>

                <button type="submit" class="btn btn-lg btn-block" id="save"><?= sw_icon('pen') ?>Save expense</button>
              </form>
            <?php endif; ?>
          </section>

          <!-- What's left -->
          <section class="card">
            <div class="card-head">
              <h3>What's left</h3>
              <span class="muted small">Wallet: <?= ksh0($wallet['balance']) ?></span>
            </div>
            <?php if (!$lines): ?>
              <p class="empty">No approved budget yet.</p>
            <?php else: ?>
              <div class="bars">
                <?php foreach ($lines as $l):
                    $alloc = (float) $l['allocated'];
                    $spent = (float) $l['spent'];
                    $pct   = $alloc > 0 ? round($spent / $alloc * 100) : 0;
                    $class = $pct > 100 ? 'over' : ($pct >= 80 ? 'high' : '');
                ?>
                  <div>
                    <div class="bar-top">
                      <b><?= htmlspecialchars($labels[$l['category']] ?? $l['category']) ?></b>
                      <span><?= ksh0($alloc - $spent) ?> left</span>
                    </div>
                    <div class="bar-track" role="img" aria-label="<?= $pct ?>% used">
                      <div class="bar-fill <?= $class ?>" style="width: <?= min(100, $pct) ?>%"></div>
                    </div>
                    <div class="bar-sub"><?= ksh0($spent) ?> spent of <?= ksh0($alloc) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        </div>

        <!-- Spending history -->
        <section class="card" id="history">
          <div class="card-head">
            <h3>Spending history</h3>
            <form method="get" action="record-spending.php#history" class="inline-filter">
            <input type="hidden" name="view" value="history">
              <label for="cat" class="sr-only">Filter by budget line</label>
              <select id="cat" name="cat" onchange="this.form.submit()">
                <option value="">All budget lines</option>
                <?php foreach (budget_categories((int) $wallet['id']) as $slug => $label): ?>
                  <option value="<?= htmlspecialchars($slug) ?>" <?= $catFilter === $slug ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>

          <?php if (!$expenses): ?>
            <p class="empty"><?= $catFilter ? 'No expenses in this budget line yet.' : 'No expenses recorded yet.' ?></p>
          <?php else: ?>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr><th>Date</th><th>Description</th><th>Budget line</th><th>Receipt</th><th class="num">Amount (KSh)</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($expenses as $e): ?>
                    <tr>
                      <td><?= htmlspecialchars(date('j M Y', strtotime($e['expense_date']))) ?></td>
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
            <p class="muted small" style="margin-top:10px;">These are expenses you recorded. A receipt supports an expense but is not a verification of it.</p>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </main>
  </div>
</div>

<script>
  // Friendly checks while typing. The server checks everything again.
  const form = document.getElementById('expense-form');
  if (form) {
    const amount = document.getElementById('amount');
    const cat    = document.getElementById('category');
    const file   = document.getElementById('receipt');
    const label  = document.getElementById('receipt-label');
    const warn   = document.getElementById('warn');
    const save   = document.getElementById('save');
    const MAX    = 5 * 1024 * 1024;

    function check() {
      let msg = '';
      const opt  = cat.options[cat.selectedIndex];
      const left = opt && opt.dataset.left ? parseFloat(opt.dataset.left) : null;
      const v    = parseFloat(amount.value) || 0;
      if (left !== null && v > left) {
        msg = 'Only KSh ' + left.toLocaleString('en-KE', { minimumFractionDigits: 2 }) + ' is left in this budget line.';
      }
      if (file.files[0] && file.files[0].size > MAX) {
        msg = 'The receipt is larger than 5 MB.';
      }
      warn.textContent = msg;
      save.disabled = msg !== '';
    }
    file.addEventListener('change', () => {
      label.textContent = file.files[0] ? 'Receipt: ' + file.files[0].name : 'Attach receipt (optional)';
      check();
    });
    amount.addEventListener('input', check);
    cat.addEventListener('change', check);
    check();

    // ---- Step 32: suggest the budget line from the description ----
    const WORDS = <?= json_encode($wordMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const STOP  = <?= json_encode(CATEGORY_STOPWORDS) ?>;
    const desc  = document.getElementById('description');
    const hint  = document.getElementById('cat-hint');
    let chosenByHand = cat.value !== '';
    let autoSet = false;

    function tokens(text) {
      return text.toLowerCase().split(/[^a-z0-9]+/).filter(w => w.length >= 3 && !STOP.includes(w) && !/^\d+$/.test(w));
    }
    function suggest(text) {
      const score = {}, why = {};
      for (const t of tokens(text)) {
        for (const m of WORDS) {
          if (t === m.w || (m.w.length >= 4 && t.startsWith(m.w))) {
            score[m.c] = (score[m.c] || 0) + m.n;
            if (!why[m.c] || m.n > why[m.c].n) why[m.c] = { word: t, n: m.n };
          }
        }
      }
      let best = null;
      for (const c in score) if (best === null || score[c] > score[best]) best = c;
      return best === null ? null : { code: best, word: why[best].word, learned: why[best].n === 3 };
    }
    function optionFor(code) { return [...cat.options].find(o => o.value === code); }
    function nameOf(opt) { return opt.textContent.split('—')[0].trim(); }

    function update() {
      if (chosenByHand) { hint.textContent = ''; return; }
      const s = desc.value.trim() ? suggest(desc.value) : null;
      const opt = s ? optionFor(s.code) : null;
      if (!opt) {
        if (autoSet) { cat.value = ''; autoSet = false; }
        hint.textContent = desc.value.trim() ? 'No match yet: choose the budget line yourself, or keep typing.' : '';
        hint.className = 'cat-hint';
        check();
        return;
      }
      if (opt.disabled) {
        if (autoSet) { cat.value = ''; autoSet = false; }
        hint.textContent = 'Looks like ' + nameOf(opt) + ', but nothing is left in it.';
        hint.className = 'cat-hint warn';
      } else {
        cat.value = s.code; autoSet = true;
        hint.textContent = '✓ ' + nameOf(opt) + ', picked from "' + s.word + '"' + (s.learned ? ' (you used it before)' : '') + '. Change it if it is wrong.';
        hint.className = 'cat-hint ok';
      }
      check();
    }
    desc.addEventListener('input', update);
    cat.addEventListener('change', () => { chosenByHand = cat.value !== ''; autoSet = false; if (chosenByHand) hint.textContent = ''; else update(); });
    update();
  }
</script>
</body>
</html>