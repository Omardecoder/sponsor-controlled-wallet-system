<?php
// Frontend/org-applications.php  (?program=ID to show one program)
// Organization: review students' applications and their documents, then accept or reject.
// Posts to ../Backend/application_decision.php.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/programs.php';
require_role('sponsor');

$orgId = (int) $_SESSION['user_id'];
if (!is_organization($conn, $orgId)) {
    header('Location: sponsor-dashboard.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// This organization's programs (for the filter)
$stmt = $conn->prepare(
    "SELECT pr.id, pr.title, pr.slots,
            (SELECT COUNT(*) FROM applications a WHERE a.program_id = pr.id AND a.status = 'accepted') AS n_accepted
     FROM programs pr WHERE pr.org_id = ? ORDER BY pr.id DESC"
);
$stmt->bind_param('i', $orgId);
$stmt->execute();
$programs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$programsById = array_column($programs, null, 'id');

$programFilter = (int) ($_GET['program'] ?? 0);
if (!isset($programsById[$programFilter])) {
    $programFilter = 0;
}

// Applications (undecided first), without withdrawn ones
$sql = "SELECT a.id, a.program_id, a.status, a.motivation, a.decision_note, a.created_at, a.decided_at,
               u.full_name AS student_name, u.email, u.phone, pr.title, pr.amount,
               (SELECT COUNT(*) FROM sponsorships s WHERE s.student_id = a.student_id AND s.status = 'active' AND s.sponsor_id <> pr.org_id) AS other_sponsors
        FROM applications a
        JOIN programs pr ON pr.id = a.program_id
        JOIN users u ON u.id = a.student_id
        WHERE pr.org_id = ? AND a.status <> 'withdrawn'" . ($programFilter ? ' AND a.program_id = ?' : '') . "
        ORDER BY a.status <> 'submitted', a.created_at";
$stmt = $conn->prepare($sql);
if ($programFilter) {
    $stmt->bind_param('ii', $orgId, $programFilter);
} else {
    $stmt->bind_param('i', $orgId);
}
$stmt->execute();
$applications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Documents of each application
$docs = [];
$stmt = $conn->prepare('SELECT id, doc_type, original_name FROM application_documents WHERE application_id = ? ORDER BY id');
foreach ($applications as $a) {
    $aid = (int) $a['id'];
    $stmt->bind_param('i', $aid);
    $stmt->execute();
    $docs[$aid] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

$waiting = count(array_filter($applications, fn($a) => $a['status'] === 'submitted'));
$statusPill = [
    'submitted' => ['New', 'amber'],
    'accepted'  => ['Accepted', 'green'],
    'rejected'  => ['Rejected', 'red'],
];

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));

$active = 'applications';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Applications | SS-Wallet</title>
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
      <h1>Applications</h1>
      <div class="topbar-right">
        <div class="profile">
          <div class="avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></div>
          <div class="profile-text">
            <div class="profile-name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
            <div class="profile-role">Organization</div>
          </div>
        </div>
      </div>
    </header>

    <main class="page">
      <div class="page-head">
        <div>
          <h2>Waiting for your decision (<?= $waiting ?>)</h2>
          <p class="muted">Read each student's reason and documents. Accepting links the student to you; you can then fund up to the program amount of each budget they send.</p>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <form method="get" class="card filters" style="grid-template-columns: 1fr auto;">
        <div>
          <label for="program">Program</label>
          <select id="program" name="program">
            <option value="">All programs</option>
            <?php foreach ($programs as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= $programFilter === (int) $p['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['title']) ?> (<?= (int) $p['n_accepted'] ?> of <?= (int) $p['slots'] ?> places filled)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-actions"><button type="submit" class="btn">Show</button></div>
      </form>

      <?php if (!$applications): ?>
        <section class="card"><p class="empty">No applications yet<?= $programFilter ? ' for this program' : '' ?>.</p></section>
      <?php endif; ?>

      <?php foreach ($applications as $a):
          $aid = (int) $a['id'];
          [$label, $color] = $statusPill[$a['status']] ?? [ucfirst($a['status']), 'grey'];
          $prog = $programsById[(int) $a['program_id']] ?? null;
          $full = $prog && (int) $prog['n_accepted'] >= (int) $prog['slots'];
      ?>
        <section class="card application">
          <div class="card-head">
            <div>
              <h3><?= htmlspecialchars($a['student_name']) ?> <span class="pill <?= $color ?>"><?= $label ?></span></h3>
              <div class="muted small"><?= htmlspecialchars($a['email']) ?> · <?= htmlspecialchars($a['phone']) ?> · applied <?= htmlspecialchars(date('j M Y', strtotime($a['created_at']))) ?></div>
              <div class="muted small">Program: <?= htmlspecialchars($a['title']) ?> (KES <?= number_format((float) $a['amount']) ?>)
                <?= (int) $a['other_sponsors'] > 0 ? ' · already has ' . (int) $a['other_sponsors'] . ' other sponsor' . ((int) $a['other_sponsors'] === 1 ? '' : 's') : '' ?></div>
            </div>
          </div>

          <div class="proposal-body">
            <div>
              <h4 class="mini-head">Why they need it</h4>
              <p class="motivation"><?= nl2br(htmlspecialchars($a['motivation'])) ?></p>
              <h4 class="mini-head">Documents</h4>
              <?php if (!$docs[$aid]): ?>
                <p class="muted small">No documents were required.</p>
              <?php else: ?>
                <ul class="doc-list">
                  <?php foreach ($docs[$aid] as $d): ?>
                    <li><a href="../Backend/view_document.php?id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener"><?= sw_icon('file') ?><?= htmlspecialchars(DOC_TYPES[$d['doc_type']] ?? $d['doc_type']) ?></a>
                      <span class="muted small"><?= htmlspecialchars($d['original_name']) ?></span></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>

            <?php if ($a['status'] === 'submitted'): ?>
              <form action="../Backend/application_decision.php" method="post" class="form-grid">
                <input type="hidden" name="application_id" value="<?= $aid ?>">
                <input type="hidden" name="return_program" value="<?= $programFilter ?>">
                <div>
                  <label for="note-<?= $aid ?>">Message to the student <span class="muted" style="font-weight:400;">(optional)</span></label>
                  <textarea id="note-<?= $aid ?>" name="note" rows="3" maxlength="500" placeholder="e.g. Welcome to the program! / Sorry, places were limited this year."></textarea>
                </div>
                <?php if ($full): ?><p class="alert error">All places in this program are filled.</p><?php endif; ?>
                <div class="row">
                  <button type="submit" name="decision" value="accept" class="btn" <?= $full ? 'disabled' : '' ?>
                          onclick="return confirm('Accept <?= htmlspecialchars(addslashes($a['student_name'])) ?>? They will be linked to you, and you can fund up to KES <?= number_format((float) $a['amount']) ?> of each budget.');">Accept</button>
                  <button type="submit" name="decision" value="reject" class="btn btn-outline"
                          onclick="return confirm('Reject this application?');">Reject</button>
                </div>
              </form>
            <?php else: ?>
              <div class="form-grid">
                <p class="muted">Decided <?= $a['decided_at'] ? htmlspecialchars(date('j M Y', strtotime($a['decided_at']))) : '' ?>.</p>
                <?php if ($a['decision_note']): ?><p class="muted">Your message: “<?= htmlspecialchars($a['decision_note']) ?>”</p><?php endif; ?>
                <?php if ($a['status'] === 'accepted'): ?><a class="btn btn-outline" href="sponsor-dashboard.php#students">See in My Students</a><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </section>
      <?php endforeach; ?>
    </main>
  </div>
</div>
</body>
</html>