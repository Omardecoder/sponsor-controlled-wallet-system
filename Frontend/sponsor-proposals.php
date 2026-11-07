<?php
// Frontend/sponsor-proposals.php
// Funder (sponsor): see students' budgets and choose how much of each to fund,
// as a percentage or a fixed amount, or send it back with feedback.
// The budget becomes active when the funders' commitments reach 100%.
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

$labels = budget_categories();   // code => name

// Proposals from students this sponsor actively sponsors
function load_proposals(mysqli $conn, int $sponsorId, bool $pending): array
{
    $sql = "SELECT p.id, p.status, p.created_at, p.reviewed_at, p.reviewed_by, p.sponsor_feedback,
                   w.id AS wallet_id, w.student_id, w.balance, w.status AS wallet_status,
                   u.full_name AS student_name, u.email, u.phone,
                   sp.pledge AS limit_amount,
                   CASE WHEN EXISTS (SELECT 1 FROM sponsorships sa WHERE sa.sponsor_id = p.reviewed_by
                                      AND sa.student_id = w.student_id AND sa.is_anonymous = 1)
                        THEN 'an anonymous well-wisher' ELSE rv.full_name END AS reviewer_name,
                   r.commit_amount AS my_commit,
                   (SELECT COALESCE(SUM(i.proposed_amount), 0) FROM budget_proposal_items i WHERE i.proposal_id = p.id) AS total
            FROM budget_proposals p
            JOIN wallets w ON w.id = p.wallet_id
            JOIN users u   ON u.id = w.student_id
            JOIN sponsorships sp ON sp.student_id = w.student_id AND sp.sponsor_id = ? AND sp.status = 'active'
            LEFT JOIN users rv ON rv.id = p.reviewed_by
            LEFT JOIN proposal_reviews r ON r.proposal_id = p.id AND r.sponsor_id = sp.sponsor_id AND r.decision = 'approved'
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

// Funding status of each waiting budget, and its lines
$funding = [];
$items   = [];
$stmt = $conn->prepare('SELECT category, proposed_amount FROM budget_proposal_items WHERE proposal_id = ? ORDER BY id');
foreach ($proposals as $p) {
    $pid = (int) $p['id'];
    $funding[$pid] = proposal_funding($conn, $pid, (int) $p['student_id']);
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $items[$pid] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();
$waitingForMe = count(array_filter($proposals, fn($p) => $p['my_commit'] === null));

// The sponsor's own phone, used as the default M-Pesa number
$stmt = $conn->prepare('SELECT phone FROM users WHERE id = ?');
$stmt->bind_param('i', $sponsorId);
$stmt->execute();
$sponsorPhone = (string) ($stmt->get_result()->fetch_assoc()['phone'] ?? '');
$stmt->close();

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));

function kes2($v): string { return number_format((float) $v, 2); }

$statusPill = [
    'approved'          => ['Active', 'green'],
    'changes_requested' => ['Changes requested', 'amber'],
    'withdrawn'         => ['Withdrawn by student', 'grey'],
];

$active = 'proposals';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Budget Proposals | SS-Wallet</title>
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
          <h2>Waiting for your decision (<?= $waitingForMe ?>)</h2>
          <p class="muted">Choose how much of each budget you will fund, as a percentage or a fixed amount. A budget becomes active when its funders have covered 100% of it.</p>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <?php if (!$proposals): ?>
        <section class="card"><p class="empty">No budgets waiting. When one of your students submits a budget, it appears here.</p></section>
      <?php endif; ?>

      <?php foreach ($proposals as $p):
          $pid    = (int) $p['id'];
          $f      = $funding[$pid];
          $total  = $f['total'];
          $frozen = $p['wallet_status'] !== 'active';
          $limit  = (float) $p['limit_amount'];
          $maxNow = $limit > 0 ? min($f['remaining'], $limit) : $f['remaining'];   // the most I can commit
          $maxPct = $total > 0 ? floor($maxNow / $total * 10000) / 100 : 0;
      ?>
        <section class="card proposal">
          <div class="card-head">
            <div>
              <h3><?= htmlspecialchars($p['student_name']) ?></h3>
              <div class="muted small"><?= htmlspecialchars($p['email']) ?> · sent <?= htmlspecialchars(date('j M Y, H:i', strtotime($p['created_at']))) ?></div>
            </div>
            <div class="stat-value">KES <?= number_format($total, 0) ?></div>
          </div>

          <!-- Funding progress -->
          <div class="funding">
            <div class="bar-top"><b><?= $f['percent'] ?>% funded</b><span>KES <?= number_format($f['committed']) ?> of <?= number_format($total) ?> · KES <?= number_format($f['remaining']) ?> still uncovered</span></div>
            <div class="bar-track" role="img" aria-label="<?= $f['percent'] ?>% funded"><div class="bar-fill" style="width: <?= $f['percent'] ?>%"></div></div>
            <div class="funders">
              <?php foreach ($f['sponsors'] as $s): $me = (int) $s['sponsor_id'] === $sponsorId; ?>
                <span class="pill <?= $s['commit_amount'] > 0 ? 'green' : 'grey' ?>">
                  <?= $me ? 'You' : htmlspecialchars($s['full_name']) ?>:
                  <?= $s['commit_amount'] > 0 ? 'KES ' . number_format($s['commit_amount']) . ' (' . $s['share'] . '%)' : 'not yet' ?>
                </span>
              <?php endforeach; ?>
            </div>
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

            <?php if ($p['my_commit'] !== null): ?>
              <div class="form-grid">
                <div class="alert success">You committed KES <?= kes2($p['my_commit']) ?> (<?= $total > 0 ? round($p['my_commit'] / $total * 100, 1) : 0 ?>%). The budget becomes active when it is fully funded, or when the student accepts the funding so far.</div>
                <a class="btn btn-outline" href="sponsor-dashboard.php#send-funds">Send funds</a>
              </div>
            <?php elseif ($maxNow <= 0): ?>
              <div class="form-grid"><div class="alert success">This budget is already fully covered by the other funders.</div></div>
            <?php else: ?>
            <form action="../Backend/review_budget_proposal.php" method="post" class="form-grid commit-form"
                  data-total="<?= htmlspecialchars((string) $total) ?>" data-max="<?= htmlspecialchars((string) $maxNow) ?>">
              <input type="hidden" name="proposal_id" value="<?= $pid ?>">

              <fieldset class="commit-type">
                <legend>I will fund</legend>
                <label class="check"><input type="radio" name="commit_type" value="percent" checked> <span>A percentage</span></label>
                <label class="check"><input type="radio" name="commit_type" value="amount"> <span>A fixed amount</span></label>
              </fieldset>

              <div class="two-col">
                <div>
                  <label for="commit-<?= $pid ?>" class="js-commit-label">Share (%)</label>
                  <input type="number" id="commit-<?= $pid ?>" name="commit_value" min="0.01" step="0.01" max="<?= $maxPct ?>"
                         value="<?= htmlspecialchars((string) $maxPct) ?>" required>
                </div>
                <div class="money-box">
                  <div><span>That is</span><b class="js-commit-result">KES <?= kes2($maxNow) ?></b></div>
                </div>
              </div>
              <p class="muted small" style="margin-top:-6px;">
                You can cover up to KES <?= kes2($maxNow) ?> (<?= $maxPct ?>%)<?= $limit > 0 ? ', your limit for this student' : '' ?>.
                The student sees every funder's share.
              </p>

              <label class="check">
                <input type="checkbox" name="pay_now" value="1" class="js-pay-now" <?= $frozen ? 'disabled' : '' ?>>
                <span>Pay my share now with M-Pesa</span>
              </label>
              <div class="js-phone" hidden>
                <label for="phone-<?= $pid ?>">Pay from number</label>
                <input type="tel" id="phone-<?= $pid ?>" name="phone" placeholder="0712 345 678" value="<?= htmlspecialchars($sponsorPhone) ?>">
              </div>

              <div>
                <label for="feedback-<?= $pid ?>">Note for the student <span class="muted" style="font-weight:400;">(required for Request changes)</span></label>
                <textarea id="feedback-<?= $pid ?>" name="feedback" rows="3" maxlength="1000" placeholder="e.g. I will cover rent and tuition. / Please reduce Entertainment."></textarea>
              </div>

              <div class="row">
                <button type="submit" name="decision" value="commit" class="btn"
                        onclick="return confirm('Commit this share of the budget?');">Fund this budget</button>
                <button type="submit" name="decision" value="changes" class="btn btn-outline" formnovalidate>Request changes</button>
              </div>
            </form>
            <?php endif; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <section class="card">
        <div class="card-head"><h3>Recently decided</h3></div>
        <?php if (!$reviewed): ?>
          <p class="empty">Nothing decided yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Student</th><th>Decided</th><th>Status</th><th>Note</th><th class="num">Budget (KES)</th><th class="num">Your part (KES)</th></tr></thead>
              <tbody>
                <?php foreach ($reviewed as $r): [$label, $color] = $statusPill[$r['status']] ?? [ucfirst($r['status']), 'amber']; ?>
                  <tr>
                    <td><?= htmlspecialchars($r['student_name']) ?></td>
                    <td><?= $r['reviewed_at'] ? htmlspecialchars(date('j M Y', strtotime($r['reviewed_at']))) : '' ?>
                      <?php if ($r['reviewer_name']): ?><div class="muted small">by <?= (int) $r['reviewed_by'] === $sponsorId ? 'you' : htmlspecialchars($r['reviewer_name']) ?></div><?php endif; ?></td>
                    <td><span class="pill <?= $color ?>"><?= $label ?></span></td>
                    <td class="wrap muted"><?= htmlspecialchars($r['sponsor_feedback'] ?? '') ?></td>
                    <td class="num"><?= kes2($r['total']) ?></td>
                    <td class="num"><?= $r['my_commit'] !== null ? kes2($r['my_commit']) : '—' ?></td>
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

<script>
  // Switch between % and amount, and show what the choice means in shillings.
  // The server checks everything again.
  document.querySelectorAll('.commit-form').forEach(form => {
    const total  = parseFloat(form.dataset.total) || 0;
    const max    = parseFloat(form.dataset.max) || 0;
    const input  = form.querySelector('[name="commit_value"]');
    const label  = form.querySelector('.js-commit-label');
    const result = form.querySelector('.js-commit-result');
    const pay    = form.querySelector('.js-pay-now');
    const phone  = form.querySelector('.js-phone');
    const fmt = n => 'KES ' + n.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const type = () => form.querySelector('[name="commit_type"]:checked').value;

    function update() {
      const v = parseFloat(input.value) || 0;
      if (type() === 'percent') {
        result.textContent = fmt(total * v / 100);
      } else {
        result.textContent = total > 0 ? (Math.round(v / total * 1000) / 10) + '% of the budget' : '';
      }
    }
    form.querySelectorAll('[name="commit_type"]').forEach(r => r.addEventListener('change', () => {
      if (type() === 'percent') {
        label.textContent = 'Share (%)';
        input.max = total > 0 ? Math.floor(max / total * 10000) / 100 : 0;
        input.value = input.max;
      } else {
        label.textContent = 'Amount (KES)';
        input.max = max;
        input.value = max;
      }
      update();
    }));
    input.addEventListener('input', update);
    if (pay) pay.addEventListener('change', () => { phone.hidden = !pay.checked; });
    update();
  });
</script>
</body>
</html>