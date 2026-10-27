<?php
// Frontend/student-programs.php
// Student: browse sponsorship programs from verified organizations, apply with documents,
// and follow the status of their applications. Posts to ../Backend/apply_program.php.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/programs.php';
require_role('student');

$studentId = (int) $_SESSION['user_id'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$today = date('Y-m-d');

// Open programs: verified organization, still open, before the deadline, places left
$stmt = $conn->prepare(
    "SELECT pr.id, pr.title, pr.description, pr.amount, pr.slots, pr.deadline, pr.required_docs,
            o.id AS org_id, o.full_name AS org_name, o.about,
            (SELECT COUNT(*) FROM applications a WHERE a.program_id = pr.id AND a.status = 'accepted') AS n_accepted,
            (SELECT a.status FROM applications a WHERE a.program_id = pr.id AND a.student_id = ?) AS my_status,
            EXISTS (SELECT 1 FROM sponsorships s WHERE s.student_id = ? AND s.sponsor_id = o.id AND s.status = 'active') AS already_sponsored
     FROM programs pr
     JOIN users o ON o.id = pr.org_id
     WHERE pr.status = 'open' AND pr.deadline >= ? AND o.org_verified = 1
     ORDER BY pr.deadline, pr.id"
);
$stmt->bind_param('iis', $studentId, $studentId, $today);
$stmt->execute();
$programs = array_values(array_filter(
    $stmt->get_result()->fetch_all(MYSQLI_ASSOC),
    fn($p) => (int) $p['n_accepted'] < (int) $p['slots']
));
$stmt->close();

// My applications (any program, including closed ones)
$stmt = $conn->prepare(
    "SELECT a.id, a.status, a.decision_note, a.created_at, a.decided_at,
            pr.title, pr.amount, o.full_name AS org_name,
            (SELECT COUNT(*) FROM application_documents d WHERE d.application_id = a.id) AS n_docs
     FROM applications a
     JOIN programs pr ON pr.id = a.program_id
     JOIN users o ON o.id = pr.org_id
     WHERE a.student_id = ?
     ORDER BY a.created_at DESC"
);
$stmt->bind_param('i', $studentId);
$stmt->execute();
$mine = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$statusPill = [
    'submitted' => ['Waiting for decision', 'amber'],
    'accepted'  => ['Accepted', 'green'],
    'rejected'  => ['Not successful', 'red'],
    'withdrawn' => ['Withdrawn', 'grey'],
];

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));
function ksh($v): string { return 'KSh ' . number_format((float) $v, 0); }

$active = 'programs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Find Sponsorship | SS-Wallet</title>
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
      <h1>Find Sponsorship</h1>
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
          <h2>Sponsorship programs</h2>
          <p class="muted">Organizations checked by our administrator offer these programs. Apply with your documents; if you are accepted, the organization becomes one of your sponsors.</p>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <?php if (!$programs): ?>
        <section class="card"><p class="empty">No programs are open right now. Check again later.</p></section>
      <?php endif; ?>

      <?php foreach ($programs as $p):
          $pid    = (int) $p['id'];
          $docs   = program_docs($p['required_docs']);
          $left   = (int) $p['slots'] - (int) $p['n_accepted'];
          $status = $p['my_status'];
          $canApply = !$p['already_sponsored'] && ($status === null || $status === 'withdrawn');
      ?>
        <section class="card program" id="program-<?= $pid ?>">
          <div class="card-head">
            <div>
              <h3><?= htmlspecialchars($p['title']) ?></h3>
              <div class="muted small"><?= sw_icon('award') ?> <?= htmlspecialchars($p['org_name']) ?> · <span class="pill green">Verified</span></div>
            </div>
            <div class="program-amount">
              <div class="stat-value"><?= ksh($p['amount']) ?></div>
              <div class="muted small">per student</div>
            </div>
          </div>

          <p class="program-desc"><?= nl2br(htmlspecialchars($p['description'])) ?></p>
          <?php if ($p['about']): ?><p class="muted small">About <?= htmlspecialchars($p['org_name']) ?>: <?= htmlspecialchars($p['about']) ?></p><?php endif; ?>

          <div class="program-facts">
            <span><b><?= $left ?></b> place<?= $left === 1 ? '' : 's' ?> left</span>
            <span>Closes <b><?= htmlspecialchars(date('j M Y', strtotime($p['deadline']))) ?></b></span>
            <span>Documents: <b><?= htmlspecialchars(implode(', ', array_map(fn($c) => DOC_TYPES[$c], $docs)) ?: 'none') ?></b></span>
          </div>

          <?php if ($p['already_sponsored']): ?>
            <p class="alert success">This organization already sponsors you.</p>
          <?php elseif (!$canApply): ?>
            <p class="alert success">You applied. Status: <?= $statusPill[$status][0] ?? htmlspecialchars($status) ?>. See "My applications" below.</p>
          <?php else: ?>
            <details class="apply-box">
              <summary class="btn"><?= sw_icon('file') ?>Apply</summary>
              <form action="../Backend/apply_program.php" method="post" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="action" value="apply">
                <input type="hidden" name="program_id" value="<?= $pid ?>">
                <div>
                  <label for="motivation-<?= $pid ?>">Why do you need this sponsorship?</label>
                  <textarea id="motivation-<?= $pid ?>" name="motivation" rows="4" minlength="30" maxlength="2000" required
                            placeholder="Your course and year, your situation, and how the money will help."></textarea>
                </div>
                <?php foreach ($docs as $code): ?>
                  <div>
                    <label for="doc-<?= $pid ?>-<?= $code ?>"><?= htmlspecialchars(DOC_TYPES[$code]) ?></label>
                    <input type="file" id="doc-<?= $pid ?>-<?= $code ?>" name="docs[<?= $code ?>]" accept=".jpg,.jpeg,.png,.pdf" required>
                  </div>
                <?php endforeach; ?>
                <p class="muted small">JPG, PNG or PDF, up to 5 MB each. Only <?= htmlspecialchars($p['org_name']) ?> and the administrator can open your documents.</p>
                <button type="submit" class="btn">Send application</button>
              </form>
            </details>
          <?php endif; ?>
        </section>
      <?php endforeach; ?>

      <!-- My applications -->
      <section class="card" id="mine">
        <div class="card-head"><h3>My applications</h3></div>
        <?php if (!$mine): ?>
          <p class="empty">You haven't applied to any program yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Program</th><th>Applied</th><th>Status</th><th>Message from organization</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($mine as $a): [$label, $color] = $statusPill[$a['status']] ?? [ucfirst($a['status']), 'grey']; ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($a['title']) ?></strong>
                      <div class="muted small"><?= htmlspecialchars($a['org_name']) ?> · <?= ksh($a['amount']) ?> · <?= (int) $a['n_docs'] ?> document<?= (int) $a['n_docs'] === 1 ? '' : 's' ?></div></td>
                    <td><?= htmlspecialchars(date('j M Y', strtotime($a['created_at']))) ?></td>
                    <td><span class="pill <?= $color ?>"><?= $label ?></span></td>
                    <td class="wrap muted"><?= htmlspecialchars($a['decision_note'] ?? '') ?></td>
                    <td>
                      <?php if ($a['status'] === 'submitted'): ?>
                        <form action="../Backend/apply_program.php" method="post" class="inline-action" onsubmit="return confirm('Withdraw this application?');">
                          <input type="hidden" name="action" value="withdraw">
                          <input type="hidden" name="application_id" value="<?= (int) $a['id'] ?>">
                          <button type="submit" class="link-btn">Withdraw</button>
                        </form>
                      <?php elseif ($a['status'] === 'accepted'): ?>
                        <a href="student-dashboard.php#sponsors">View sponsor</a>
                      <?php endif; ?>
                    </td>
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