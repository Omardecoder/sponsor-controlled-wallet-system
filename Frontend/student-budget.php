<?php
// Frontend/student-budget.php
// Student: plan a budget across categories and send it to their funders (sponsors).
// Each funder commits a share (a % or an amount); at 100% the budget is active.
// While it waits, the student can accept the funding committed so far (choosing which lines to cut)
// or withdraw it. Posts to submit_budget_proposal.php and budget_action.php.
// Posts to ../Backend/submit_budget_proposal.php (fields: alloc[slug]).
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_once __DIR__ . '/../Backend/sponsorship.php';
require_role('student');

$studentId = (int) $_SESSION['user_id'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// The student's wallet and sponsors
$stmt = $conn->prepare('SELECT id, balance, status FROM wallets WHERE student_id = ? LIMIT 1');
$stmt->bind_param('i', $studentId);
$stmt->execute();
$wallet = $stmt->get_result()->fetch_assoc();
$stmt->close();

$sponsors = student_sponsors($conn, $studentId);
if (!$sponsors) {
    $wallet = null;   // no accepted sponsor yet: nothing to plan for
}
$funding = null;   // funding status of a budget that is waiting

$categories = [];                    // code => name (the student's own categories)
$approved   = [];                    // slug => ['allocated' => .., 'spent' => ..]
$proposal   = null;                  // latest proposal
$proposed   = [];                    // slug => amount in the latest proposal

if ($wallet) {
    $walletId = (int) $wallet['id'];
    $categories = budget_categories($walletId);   // the categories this student created

    // Current approved budget
    $stmt = $conn->prepare('SELECT category, allocated, spent FROM budget_allocations WHERE wallet_id = ?');
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $approved[$row['category']] = $row;
    }
    $stmt->close();

    // Latest proposal, its amounts, and who asked for changes
    $stmt = $conn->prepare(
        'SELECT p.id, p.status, p.sponsor_feedback, p.created_at,
                CASE WHEN EXISTS (SELECT 1 FROM sponsorships sa JOIN wallets wa ON wa.student_id = sa.student_id
                                 WHERE wa.id = p.wallet_id AND sa.sponsor_id = p.reviewed_by AND sa.is_anonymous = 1)
                     THEN \'An anonymous well-wisher\' ELSE r.full_name END AS reviewer_name
         FROM budget_proposals p LEFT JOIN users r ON r.id = p.reviewed_by
         WHERE p.wallet_id = ? ORDER BY p.id DESC LIMIT 1'
    );
    $stmt->bind_param('i', $walletId);
    $stmt->execute();
    $proposal = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($proposal) {
        $pid = (int) $proposal['id'];
        $stmt = $conn->prepare('SELECT category, proposed_amount FROM budget_proposal_items WHERE proposal_id = ?');
        $stmt->bind_param('i', $pid);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $proposed[$row['category']] = (float) $row['proposed_amount'];
        }
        $stmt->close();
        if ($proposal['status'] === 'pending') {
            $funding = proposal_funding($conn, $pid, $studentId);
        }
    }
}

$balance     = $wallet ? (float) $wallet['balance'] : 0.0;
$isPending   = $proposal && $proposal['status'] === 'pending';
$needsChange = $proposal && $proposal['status'] === 'changes_requested';
$frozen      = $wallet && $wallet['status'] !== 'active';
$locked      = $isPending || $frozen;     // form can't be submitted right now

// What to show in the boxes:
//   waiting or changes requested -> the amounts from that proposal
//   otherwise                    -> the currently approved amounts (starting point for a new plan)
$start = [];
foreach ($categories as $slug => $label) {
    $start[$slug] = ($isPending || $needsChange)
        ? ($proposed[$slug] ?? 0)
        : (float) ($approved[$slug]['allocated'] ?? 0);
}
$startTotal = array_sum($start);

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));

function ksh($v): string { return 'KSh ' . number_format((float) $v, 2); }

$active = 'budget';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Budget | SS-Wallet</title>
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
      <h1>My Budget</h1>
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
          <h2>Plan my budget</h2>
          <p class="muted">Decide how much goes to each category, then send it to your sponsors. Each one chooses how much of it to fund; at 100% your budget is active.</p>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <?php if (!$wallet): ?>
        <section class="card">
          <div class="card-head"><h3>You're not linked to a sponsor yet</h3></div>
          <p class="muted">Ask a sponsor to add you from their dashboard using the email or phone number you registered with, then accept their request on your dashboard.</p>
        </section>

      <?php else: ?>

        <?php if ($isPending): ?>
          <!-- Funding progress of the budget that is waiting -->
          <section class="card funding-card">
            <div class="card-head">
              <h3>Your budget is <?= $funding['percent'] ?>% funded</h3>
              <span class="muted small">Sent <?= htmlspecialchars(date('j M Y, H:i', strtotime($proposal['created_at']))) ?></span>
            </div>
            <div class="bar-top"><b>KSh <?= number_format($funding['committed'], 2) ?> of <?= number_format($funding['total'], 2) ?></b><span>KSh <?= number_format($funding['remaining'], 2) ?> still uncovered</span></div>
            <div class="bar-track" role="img" aria-label="<?= $funding['percent'] ?>% funded"><div class="bar-fill" style="width: <?= $funding['percent'] ?>%"></div></div>
            <div class="table-wrap" style="margin-top:12px;">
              <table>
                <thead><tr><th>Funder</th><th class="num">Will fund</th><th class="num">Share</th><th>Note</th></tr></thead>
                <tbody>
                  <?php foreach ($funding['sponsors'] as $i => $f): ?>
                    <tr>
                      <td>Funder <?= $i + 1 ?>: <strong><?= htmlspecialchars($f['full_name']) ?></strong></td>
                      <td class="num"><?= $f['commit_amount'] > 0 ? ksh($f['commit_amount']) : '<span class="muted">not yet</span>' ?></td>
                      <td class="num"><?= $f['commit_amount'] > 0 ? $f['share'] . '%' : '—' ?></td>
                      <td class="wrap muted"><?= htmlspecialchars($f['feedback'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="row" style="margin-top:14px;">
              <?php if ($funding['committed'] > 0 && !$frozen): ?>
                <a class="btn" href="#accept-partial"><?= sw_icon('pen') ?>Start with <?= $funding['percent'] ?>% funding</a>
              <?php endif; ?>
              <form action="../Backend/budget_action.php" method="post" class="inline-action"
                    onsubmit="return confirm('Withdraw this budget? Your sponsors\' commitments to it will be cancelled.');">
                <input type="hidden" name="action" value="withdraw">
                <input type="hidden" name="proposal_id" value="<?= (int) $proposal['id'] ?>">
                <button type="submit" class="btn btn-outline">Withdraw this budget</button>
              </form>
            </div>
            <p class="muted small">You can wait for the rest, start now with what is funded (you choose which lines to reduce), or withdraw it and send a different budget.</p>
          </section>

          <?php if ($funding['committed'] > 0 && !$frozen):
              $factor = $funding['total'] > 0 ? $funding['committed'] / $funding['total'] : 0;
          ?>
            <!-- Accept partial funding: the student decides which lines to reduce -->
            <section class="card" id="accept-partial">
              <div class="card-head">
                <h3>Start with the funding you have</h3>
                <span class="muted small">Lines must add up to KSh <?= number_format($funding['committed'], 2) ?> or less</span>
              </div>
              <form action="../Backend/budget_action.php" method="post" id="partial-form" data-max="<?= htmlspecialchars((string) $funding['committed']) ?>">
                <input type="hidden" name="action" value="accept_partial">
                <input type="hidden" name="proposal_id" value="<?= (int) $proposal['id'] ?>">
                <div class="cat-list">
                  <?php foreach ($proposed as $slug => $amt):
                      $alreadySpent = (float) ($approved[$slug]['spent'] ?? 0);
                      // every line reduced by the same %, but never below what is already spent
                      $suggested = min($amt, max($alreadySpent, floor($amt * $factor * 100) / 100));
                  ?>
                    <div class="cat-row">
                      <div>
                        <label for="p-<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($categories[$slug] ?? (budget_categories()[$slug] ?? $slug)) ?></label>
                        <div class="muted small">You asked for <?= ksh($amt) ?><?= $alreadySpent > 0 ? ' · already spent ' . ksh($alreadySpent) . ' (the minimum)' : '' ?></div>
                      </div>
                      <div class="money-input">
                        <span>KSh</span>
                        <input type="number" class="p-alloc" id="p-<?= htmlspecialchars($slug) ?>" name="alloc[<?= htmlspecialchars($slug) ?>]"
                               min="<?= htmlspecialchars((string) min($amt, $alreadySpent)) ?>" max="<?= htmlspecialchars((string) $amt) ?>" step="0.01" value="<?= htmlspecialchars(number_format($suggested, 2, '.', '')) ?>">
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <p class="muted" id="partial-total"></p>
                <button type="submit" class="btn" id="partial-save"
                        onclick="return confirm('Start your budget with these amounts?');">Start my budget with these amounts</button>
              </form>
            </section>
          <?php endif; ?>
        <?php elseif ($needsChange): ?>
          <div class="notice">
            <span><strong><?= htmlspecialchars($proposal['reviewer_name'] ?? 'Your sponsor') ?> asked for changes.</strong>
              <?php if ($proposal['sponsor_feedback']): ?><br><span class="muted">“<?= htmlspecialchars($proposal['sponsor_feedback']) ?>”</span><?php endif; ?>
              <br>Adjust the amounts below and submit again.</span>
          </div>
        <?php elseif ($frozen): ?>
          <div class="alert error">Your wallet is frozen, so you can't submit a budget. Contact your sponsor.</div>
        <?php endif; ?>

        <!-- Live figures -->
        <section class="stats" aria-label="Budget figures">
          <div class="card stat">
            <div class="stat-label">Sponsors</div>
            <div class="stat-value"><?= count($sponsors) ?></div>
            <div class="stat-note">Each chooses how much of your budget to fund</div>
          </div>
          <div class="card stat">
            <div class="stat-label">Wallet balance</div>
            <div class="stat-value"><?= ksh($balance) ?></div>
          </div>
          <div class="card stat">
            <div class="stat-label">Budget total</div>
            <div class="stat-value" id="total"><?= ksh($startTotal) ?></div>
          </div>
          <div class="card stat">
            <div class="stat-label" id="gap-label">Left over after budget</div>
            <div class="stat-value" id="gap"><?= ksh($balance - $startTotal) ?></div>
            <div class="stat-note" id="gap-note"></div>
          </div>
        </section>

        <!-- Your categories: the student decides which ones they need -->
        <section class="card" id="categories">
          <div class="card-head">
            <h3>Your categories</h3>
            <span class="muted small"><?= count($categories) ?> of <?= MAX_CUSTOM_CATEGORIES ?> used</span>
          </div>
          <?php
            $haveNames   = array_map('mb_strtolower', $categories);
            $suggestions = array_filter(SUGGESTED_CATEGORIES, fn($n) => !in_array(mb_strtolower($n), $haveNames, true));
          ?>
          <?php if ($suggestions && count($categories) < MAX_CUSTOM_CATEGORIES): ?>
            <p class="muted small" style="margin-top:0;">Quick add:</p>
            <div class="chips">
              <?php foreach ($suggestions as $name): ?>
                <form action="../Backend/category_action.php" method="post" class="inline-action">
                  <input type="hidden" name="action" value="add">
                  <input type="hidden" name="name" value="<?= htmlspecialchars($name) ?>">
                  <button type="submit" class="chip"><?= sw_icon('plus') ?><?= htmlspecialchars($name) ?></button>
                </form>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form action="../Backend/category_action.php" method="post" class="row" style="margin-top:12px;">
            <input type="hidden" name="action" value="add">
            <div>
              <label for="new-cat">Or type your own</label>
              <input type="text" id="new-cat" name="name" minlength="2" maxlength="30" required placeholder="e.g. Church offering, Gym, Hostel deposit">
            </div>
            <button type="submit" class="btn btn-outline"><?= sw_icon('plus') ?>Add category</button>
          </form>
        </section>

        <!-- The form -->
        <section class="card">
          <div class="card-head">
            <h3>Amount for each category</h3>
            <span class="muted small">Leave a category at 0 to skip it</span>
          </div>

          <?php if (!$categories): ?>
            <p class="empty">You have no categories yet. Add the ones you need above, for example Rent, Food and Transport, then enter an amount for each.</p>
          <?php else: ?>
          <form id="budget-form" action="../Backend/submit_budget_proposal.php" method="post"
                data-balance="<?= htmlspecialchars((string) $balance) ?>">
            <div class="cat-list">
              <?php foreach ($categories as $slug => $label):
                  $spent = (float) ($approved[$slug]['spent'] ?? 0);
                  $now   = (float) ($approved[$slug]['allocated'] ?? 0);
                  $val   = $start[$slug];
              ?>
                <div class="cat-row">
                  <div>
                    <div class="cat-name">
                      <label for="alloc-<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($label) ?></label>
                      <?php if ($now <= 0 && $spent <= 0 && !$locked): ?>
                        <button type="submit" form="remove-<?= htmlspecialchars($slug) ?>" class="link-btn"
                                onclick="return confirm('Remove the category <?= htmlspecialchars(addslashes($label)) ?>?');">Remove</button>
                      <?php endif; ?>
                    </div>
                    <div class="muted small">
                      <?= $now > 0 ? 'Approved now: ' . ksh($now) : 'Not in your approved budget' ?>
                      <?= $spent > 0 ? ' · already spent ' . ksh($spent) : '' ?>
                    </div>
                  </div>
                  <div class="money-input">
                    <span>KSh</span>
                    <input type="number" class="alloc" id="alloc-<?= htmlspecialchars($slug) ?>" name="alloc[<?= htmlspecialchars($slug) ?>]"
                           min="0" step="0.01" placeholder="0" inputmode="decimal"
                           data-spent="<?= htmlspecialchars((string) ($now > 0 ? $spent : 0)) ?>"
                           value="<?= $val > 0 ? htmlspecialchars(number_format($val, 2, '.', '')) : '' ?>"
                           <?= $locked ? 'disabled' : '' ?>>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <p class="form-warn" id="warn" role="alert"></p>

            <?php if (!$locked): ?>
              <button type="submit" class="btn btn-lg" id="save"
                      onclick="return confirm('Send this budget to your sponsor<?= count($sponsors) > 1 ? 's' : '' ?> for approval?');">
                <?= sw_icon('send') ?>Submit to sponsor<?= count($sponsors) > 1 ? 's' : '' ?>
              </button>
            <?php endif; ?>
          </form>
          <?php endif; ?>

          <?php // One tiny form per category, used by its "Remove" button (forms can't be nested) ?>
          <?php foreach ($categories as $slug => $label): if (!is_custom_category($slug)) continue; ?>
            <form id="remove-<?= htmlspecialchars($slug) ?>" action="../Backend/category_action.php" method="post" hidden>
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="id" value="<?= (int) substr($slug, 1) ?>">
            </form>
          <?php endforeach; ?>
        </section>
      <?php endif; ?>
    </main>
  </div>
</div>

<script>
  // Live totals. The server checks everything again when you submit.
  const form = document.getElementById('budget-form');
  if (form) {
    const balance = parseFloat(form.dataset.balance) || 0;
    const inputs  = form.querySelectorAll('.alloc');
    const totalEl = document.getElementById('total');
    const gapEl   = document.getElementById('gap');
    const gapLbl  = document.getElementById('gap-label');
    const gapNote = document.getElementById('gap-note');
    const warn    = document.getElementById('warn');
    const save    = document.getElementById('save');
    const fmt = n => 'KSh ' + n.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    function update() {
      let total = 0, belowSpent = false;
      inputs.forEach(i => {
        const v = parseFloat(i.value) || 0;
        const s = parseFloat(i.dataset.spent) || 0;
        if (s > 0 && v < s) belowSpent = true;
        total += v;
      });
      total = Math.round(total * 100) / 100;
      totalEl.textContent = fmt(total);

      const gap = Math.round((balance - total) * 100) / 100;
      if (gap >= 0) {
        gapLbl.textContent = 'Left over after budget';
        gapEl.textContent = fmt(gap);
        gapEl.classList.remove('warn-text');
        gapNote.textContent = '';
      } else {
        gapLbl.textContent = 'Sponsor would add';
        gapEl.textContent = fmt(-gap);
        gapEl.classList.add('warn-text');
        gapNote.textContent = 'More than your balance. Your sponsors send the rest when they fund it.';
      }

      warn.textContent = !save ? '' : belowSpent ? 'A category cannot be lower than what you have already spent in it.'
                       : total <= 0 ? 'Enter an amount for at least one category.' : '';
      if (save) save.disabled = belowSpent || total <= 0;
    }
    inputs.forEach(i => i.addEventListener('input', update));
    update();
  }

  // "Start with the funding you have": the lines can't add up to more than what is funded
  const partial = document.getElementById('partial-form');
  if (partial) {
    const max   = parseFloat(partial.dataset.max) || 0;
    const boxes = partial.querySelectorAll('.p-alloc');
    const out   = document.getElementById('partial-total');
    const btn   = document.getElementById('partial-save');
    const fmt2  = n => 'KSh ' + n.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function check() {
      let t = 0;
      boxes.forEach(b => t += parseFloat(b.value) || 0);
      t = Math.round(t * 100) / 100;
      const over = t > max + 0.001;
      out.textContent = 'Total: ' + fmt2(t) + ' of ' + fmt2(max) + ' funded' + (over ? ' (too much, lower some lines)' : '');
      out.className = over ? 'form-warn' : 'muted';
      btn.disabled = over || t <= 0;
    }
    boxes.forEach(b => b.addEventListener('input', check));
    check();
  }
</script>
</body>
</html>