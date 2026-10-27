<?php
// Frontend/org-programs.php
// Organization: describe the organization, post sponsorship programs, and see how many students applied.
// Posts to ../Backend/program_action.php. Applications are reviewed on org-applications.php.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/programs.php';
require_role('sponsor');

$orgId   = (int) $_SESSION['user_id'];
$profile = sponsor_profile($conn, $orgId);
if (!$profile || $profile['sponsor_type'] !== 'organization') {
    header('Location: sponsor-dashboard.php');
    exit;
}
$verified = (int) $profile['org_verified'] === 1;

$flash = $_SESSION['flash'] ?? null;
$old   = $_SESSION['program_old'] ?? [];
unset($_SESSION['flash'], $_SESSION['program_old']);
$old += ['title' => '', 'description' => '', 'amount' => '', 'slots' => '1', 'deadline' => '', 'docs' => ['national_id', 'admission', 'fee_structure']];

// This organization's programs with application counts
$stmt = $conn->prepare(
    "SELECT pr.*,
            (SELECT COUNT(*) FROM applications a WHERE a.program_id = pr.id AND a.status = 'submitted') AS n_new,
            (SELECT COUNT(*) FROM applications a WHERE a.program_id = pr.id AND a.status = 'accepted')  AS n_accepted,
            (SELECT COUNT(*) FROM applications a WHERE a.program_id = pr.id AND a.status <> 'withdrawn') AS n_total
     FROM programs pr
     WHERE pr.org_id = ?
     ORDER BY pr.status = 'closed', pr.deadline, pr.id DESC"
);
$stmt->bind_param('i', $orgId);
$stmt->execute();
$programs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));
$today     = date('Y-m-d');

$active = 'programs';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Programs | SS-Wallet</title>
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
      <h1>Programs</h1>
      <div class="topbar-right">
        <div class="profile">
          <div class="avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></div>
          <div class="profile-text">
            <div class="profile-name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
            <div class="profile-role">Organization<?= $verified ? ' · Verified' : '' ?></div>
          </div>
        </div>
      </div>
    </header>

    <main class="page">
      <div class="page-head">
        <div>
          <h2>Sponsorship programs</h2>
          <p class="muted">Post a program, students apply with their documents, and each student you accept is linked to you automatically with the program amount as your pledge.</p>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <?php if (!$verified): ?>
        <div class="notice">
          <span><strong>Waiting for verification.</strong> The administrator checks every organization before students can see its programs. You can set up your programs now.</span>
        </div>
      <?php endif; ?>

      <div class="grid-2">
        <!-- New program -->
        <section class="card">
          <div class="card-head"><h3>Post a new program</h3></div>
          <form action="../Backend/program_action.php" method="post" class="form-grid">
            <input type="hidden" name="action" value="create">
            <div>
              <label for="title">Program title</label>
              <input type="text" id="title" name="title" maxlength="120" required placeholder="e.g. 2027 Upkeep Bursary" value="<?= htmlspecialchars($old['title']) ?>">
            </div>
            <div>
              <label for="description">Who can apply and what it covers</label>
              <textarea id="description" name="description" rows="4" maxlength="2000" required placeholder="e.g. For first-year students at Kenyan universities who need help with upkeep..."><?= htmlspecialchars($old['description']) ?></textarea>
            </div>
            <div class="two-col">
              <div>
                <label for="amount">Amount per student (KES)</label>
                <input type="number" id="amount" name="amount" min="1" step="1" required placeholder="e.g. 60000" value="<?= htmlspecialchars($old['amount']) ?>">
              </div>
              <div>
                <label for="slots">Number of students</label>
                <input type="number" id="slots" name="slots" min="1" max="1000" step="1" required value="<?= htmlspecialchars($old['slots']) ?>">
              </div>
            </div>
            <div>
              <label for="deadline">Applications close on</label>
              <input type="date" id="deadline" name="deadline" min="<?= $today ?>" required value="<?= htmlspecialchars($old['deadline']) ?>">
            </div>
            <fieldset class="doc-choices">
              <legend>Documents students must upload</legend>
              <?php foreach (DOC_TYPES as $code => $label): ?>
                <label class="check">
                  <input type="checkbox" name="docs[]" value="<?= $code ?>" <?= in_array($code, $old['docs'], true) ? 'checked' : '' ?>>
                  <span><?= htmlspecialchars($label) ?></span>
                </label>
              <?php endforeach; ?>
            </fieldset>
            <button type="submit" class="btn"><?= sw_icon('plus') ?>Post program</button>
          </form>
        </section>

        <!-- About -->
        <section class="card">
          <div class="card-head"><h3>About your organization</h3></div>
          <form action="../Backend/program_action.php" method="post" class="form-grid">
            <input type="hidden" name="action" value="about">
            <div>
              <label for="about">Shown to students next to your programs</label>
              <textarea id="about" name="about" rows="5" maxlength="500" placeholder="e.g. We are a Nairobi-based foundation supporting students from low-income families since 2015."><?= htmlspecialchars($profile['about'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-outline">Save</button>
          </form>
        </section>
      </div>

      <!-- Programs -->
      <section class="card">
        <div class="card-head"><h3>Your programs</h3></div>
        <?php if (!$programs): ?>
          <p class="empty">No programs yet. Post one above.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Program</th><th class="num">Per student (KES)</th><th>Places</th><th>Applications</th><th>Closes</th><th>Status</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($programs as $p):
                    $expired = $p['deadline'] < $today;
                    $full    = (int) $p['n_accepted'] >= (int) $p['slots'];
                    $isOpen  = $p['status'] === 'open' && !$expired && !$full;
                ?>
                  <tr>
                    <td>
                      <strong><?= htmlspecialchars($p['title']) ?></strong>
                      <div class="muted small">Documents: <?= htmlspecialchars(implode(', ', array_map(fn($c) => DOC_TYPES[$c], program_docs($p['required_docs']))) ?: 'none') ?></div>
                    </td>
                    <td class="num"><?= number_format((float) $p['amount'], 2) ?></td>
                    <td><?= (int) $p['n_accepted'] ?> of <?= (int) $p['slots'] ?> filled</td>
                    <td>
                      <a href="org-applications.php?program=<?= (int) $p['id'] ?>"><?= (int) $p['n_total'] ?> total</a>
                      <?php if ((int) $p['n_new'] > 0): ?><span class="pill amber"><?= (int) $p['n_new'] ?> new</span><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(date('j M Y', strtotime($p['deadline']))) ?></td>
                    <td>
                      <?php if ($isOpen): ?><span class="pill green">Open</span>
                      <?php elseif ($p['status'] === 'closed'): ?><span class="pill grey">Closed</span>
                      <?php elseif ($full): ?><span class="pill grey">Full</span>
                      <?php else: ?><span class="pill grey">Deadline passed</span><?php endif; ?>
                    </td>
                    <td>
                      <form action="../Backend/program_action.php" method="post" class="inline-action">
                        <input type="hidden" name="program_id" value="<?= (int) $p['id'] ?>">
                        <?php if ($p['status'] === 'open'): ?>
                          <input type="hidden" name="action" value="close">
                          <button type="submit" class="btn btn-outline btn-sm">Close</button>
                        <?php else: ?>
                          <input type="hidden" name="action" value="reopen">
                          <button type="submit" class="btn btn-outline btn-sm">Reopen</button>
                        <?php endif; ?>
                      </form>
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