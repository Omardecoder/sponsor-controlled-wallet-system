<?php
// Frontend/sponsor-student.php?id=STUDENT_ID
// Well-wishers: one listed student's profile and open budget, with a form to fund a % or an amount.
// Posts to ../Backend/review_budget_proposal.php with from_browse=1: if the well-wisher is not
// linked yet, the sponsorship is created together with the commitment (anonymous if ticked).
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_once __DIR__ . '/../Backend/sponsorship.php';
require_role('sponsor');

$sponsorId = (int) $_SESSION['user_id'];
if (!is_well_wisher($conn, $sponsorId)) {
    header('Location: sponsor-dashboard.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$studentId = (int) ($_GET['id'] ?? 0);
$student   = student_profile($conn, $studentId);

// My link with this student (if any)
$link = null;
if ($student) {
    $stmt = $conn->prepare('SELECT status, is_anonymous FROM sponsorships WHERE student_id = ? AND sponsor_id = ?');
    $stmt->bind_param('ii', $studentId, $sponsorId);
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$isMine = $link && $link['status'] === 'active';

// Only listed students, or students this well-wisher already supports
if (!$student || ((int) $student['listed'] !== 1 && !$isMine)) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'That student is not listed for well-wishers.'];
    header('Location: sponsor-browse.php');
    exit;
}

// The open budget, its lines and funding so far
$stmt = $conn->prepare(
    "SELECT p.id, p.created_at, w.id AS wallet_id, w.status AS wallet_status
     FROM wallets w JOIN budget_proposals p ON p.wallet_id = w.id AND p.status = 'pending'
     WHERE w.student_id = ? ORDER BY p.id DESC LIMIT 1"
);
$stmt->bind_param('i', $studentId);
$stmt->execute();
$proposal = $stmt->get_result()->fetch_assoc();
$stmt->close();

$items = [];
$funding = null;
$myCommit = null;
if ($proposal) {
    $pid = (int) $proposal['id'];
    $labels = budget_categories((int) $proposal['wallet_id']);
    $stmt = $conn->prepare('SELECT category, proposed_amount FROM budget_proposal_items WHERE proposal_id = ? AND proposed_amount > 0 ORDER BY proposed_amount DESC');
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $it) {
        $items[] = ['name' => $labels[$it['category']] ?? $it['category'], 'amount' => (float) $it['proposed_amount']];
    }
    $stmt->close();
    $funding = proposal_funding($conn, $pid, $studentId);

    $stmt = $conn->prepare('SELECT decision, commit_amount FROM proposal_reviews WHERE proposal_id = ? AND sponsor_id = ?');
    $stmt->bind_param('ii', $pid, $sponsorId);
    $stmt->execute();
    $myCommit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$total  = $funding['total'] ?? 0;
$maxNow = $funding['remaining'] ?? 0;
$maxPct = $total > 0 ? floor($maxNow / $total * 10000) / 100 : 0;

// Once linked, the well-wisher may see the full name (like any sponsor); before that, first name + initial
$shownName = $isMine ? $student['full_name'] : public_student_name($student['full_name']);

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));
function kes2($v): string { return number_format((float) $v, 2); }

$active = 'browse';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($shownName) ?> | SS-Wallet</title>
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
      <h1>Students</h1>
      <div class="topbar-right">
        <div class="profile">
          <div class="avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></div>
          <div class="profile-text">
            <div class="profile-name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
            <div class="profile-role">Well-wisher</div>
          </div>
        </div>
      </div>
    </header>

    <main class="page">
      <p><a href="sponsor-browse.php">← All students</a></p>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <div class="grid-2">
        <section class="card">
          <div class="card-head">
            <div>
              <h3><?= htmlspecialchars($shownName) ?> <?php if ($isMine): ?><span class="pill green">You support</span><?php endif; ?></h3>
              <div class="muted small"><?= htmlspecialchars($student['institution'] ?? '') ?> · <?= htmlspecialchars($student['course'] ?? '') ?><?= $student['year_of_study'] ? ' · Year ' . (int) $student['year_of_study'] : '' ?></div>
            </div>
          </div>
          <h4 class="mini-head">Their story</h4>
          <p class="story-full"><?= htmlspecialchars((string) $student['story']) ?></p>

          <?php if ($funding): ?>
            <h4 class="mini-head" style="margin-top:18px;">Budget</h4>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Category</th><th class="num">Amount (KES)</th></tr></thead>
                <tbody>
                  <?php foreach ($items as $it): ?>
                    <tr><td><?= htmlspecialchars($it['name']) ?></td><td class="num"><?= kes2($it['amount']) ?></td></tr>
                  <?php endforeach; ?>
                  <tr class="total-row"><td>Total</td><td class="num"><?= kes2($total) ?></td></tr>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <section class="card">
          <?php if (!$funding): ?>
            <div class="card-head"><h3>No open budget</h3></div>
            <p class="muted">This student has no budget waiting for funding right now. Check again later.</p>
          <?php else: ?>
            <div class="card-head"><h3>Funding</h3></div>
            <div class="funding">
              <div class="bar-top"><b><?= $funding['percent'] ?>% funded</b><span>KES <?= number_format($funding['committed']) ?> of <?= number_format($total) ?></span></div>
              <div class="bar-track" role="img" aria-label="<?= $funding['percent'] ?>% funded"><div class="bar-fill" style="width: <?= $funding['percent'] ?>%"></div></div>
              <?php if ($funding['sponsors']): ?>
                <div class="funders">
                  <?php foreach ($funding['sponsors'] as $s): $me = (int) $s['sponsor_id'] === $sponsorId; ?>
                    <span class="pill <?= $s['commit_amount'] > 0 ? 'green' : 'grey' ?>"><?= $me ? 'You' : htmlspecialchars($s['full_name']) ?>:
                      <?= $s['commit_amount'] > 0 ? 'KES ' . number_format($s['commit_amount']) . ' (' . $s['share'] . '%)' : 'not yet' ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <?php if ($myCommit): ?>
              <div class="alert success">You committed KES <?= kes2($myCommit['commit_amount']) ?> to this budget.</div>
              <a class="btn btn-outline" href="sponsor-dashboard.php#send-funds">Send funds</a>
            <?php elseif ($maxNow <= 0): ?>
              <div class="alert success">This budget is already fully funded.</div>
            <?php else: ?>
              <form action="../Backend/review_budget_proposal.php" method="post" class="form-grid commit-form"
                    data-total="<?= htmlspecialchars((string) $total) ?>" data-max="<?= htmlspecialchars((string) $maxNow) ?>">
                <input type="hidden" name="from_browse" value="1">
                <input type="hidden" name="student_id" value="<?= $studentId ?>">
                <input type="hidden" name="proposal_id" value="<?= (int) $proposal['id'] ?>">
                <input type="hidden" name="decision" value="commit">

                <fieldset class="commit-type">
                  <legend>I will fund</legend>
                  <label class="check"><input type="radio" name="commit_type" value="percent" checked> <span>A percentage</span></label>
                  <label class="check"><input type="radio" name="commit_type" value="amount"> <span>A fixed amount</span></label>
                </fieldset>
                <div class="two-col">
                  <div>
                    <label for="commit_value" class="js-commit-label">Share (%)</label>
                    <input type="number" id="commit_value" name="commit_value" min="0.01" step="0.01" max="<?= $maxPct ?>" value="<?= htmlspecialchars((string) $maxPct) ?>" required>
                  </div>
                  <div class="money-box"><div><span>That is</span><b class="js-commit-result">KES <?= kes2($maxNow) ?></b></div></div>
                </div>
                <p class="muted small" style="margin-top:-6px;">Up to KES <?= kes2($maxNow) ?> (<?= $maxPct ?>%) is still uncovered.</p>

                <?php if (!$isMine): ?>
                  <label class="check">
                    <input type="checkbox" name="anonymous" value="1" checked>
                    <span><strong>Stay anonymous</strong><br><span class="muted small">The student sees "Anonymous well-wisher". You can change this later on your dashboard.</span></span>
                  </label>
                <?php endif; ?>

                <div>
                  <label for="feedback">Note for the student <span class="muted" style="font-weight:400;">(optional)</span></label>
                  <textarea id="feedback" name="feedback" rows="3" maxlength="1000" placeholder="e.g. Keep working hard. I will cover your rent this term."></textarea>
                </div>

                <button type="submit" class="btn" onclick="return confirm('Commit this share of the budget?<?= $isMine ? '' : ' You will become one of this student\'s sponsors.' ?>');">Fund this budget</button>
                <?php if (!$isMine): ?>
                  <p class="muted small">Funding makes you one of this student's sponsors. You can then follow their spending like any sponsor and send your share by M-Pesa from your dashboard.</p>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </section>
      </div>
    </main>
  </div>
</div>
<script>
  // Switch between % and amount, and show what the choice means in shillings. The server checks everything again.
  document.querySelectorAll('.commit-form').forEach(form => {
    const total  = parseFloat(form.dataset.total) || 0;
    const max    = parseFloat(form.dataset.max) || 0;
    const input  = form.querySelector('[name="commit_value"]');
    const label  = form.querySelector('.js-commit-label');
    const result = form.querySelector('.js-commit-result');
    const fmt = n => 'KES ' + n.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const type = () => form.querySelector('[name="commit_type"]:checked').value;
    function update() {
      const v = parseFloat(input.value) || 0;
      result.textContent = type() === 'percent' ? fmt(total * v / 100)
        : (total > 0 ? (Math.round(v / total * 1000) / 10) + '% of the budget' : '');
    }
    form.querySelectorAll('[name="commit_type"]').forEach(r => r.addEventListener('change', () => {
      if (type() === 'percent') {
        label.textContent = 'Share (%)';
        input.max = total > 0 ? Math.floor(max / total * 10000) / 100 : 0;
      } else {
        label.textContent = 'Amount (KES)';
        input.max = max;
      }
      input.value = input.max;
      update();
    }));
    input.addEventListener('input', update);
    update();
  });
</script>
</body>
</html>