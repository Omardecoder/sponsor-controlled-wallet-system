<?php
// Frontend/student-profile.php
// Student: a short public profile (institution, course, year, "my story") and the choice to be
// listed on the Students page, where well-wishers browse students and choose whom to fund.
// Well-wishers see only the first name and initial, never the email or phone.
// Posts to ../Backend/profile_action.php.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/sponsorship.php';
require_role('student');

$studentId = (int) $_SESSION['user_id'];
$flash = $_SESSION['flash'] ?? null;
$old   = $_SESSION['profile_old'] ?? null;
unset($_SESSION['flash'], $_SESSION['profile_old']);

$profile = student_profile($conn, $studentId);
$ready   = $profile !== null;   // false until the Step 29 SQL is run
$p = $old ?? [
    'institution'   => $profile['institution'] ?? '',
    'course'        => $profile['course'] ?? '',
    'year_of_study' => $profile['year_of_study'] ?? '',
    'story'         => $profile['story'] ?? '',
    'listed'        => (int) ($profile['listed'] ?? 0),
];
$listed = $ready && (int) $profile['listed'] === 1;

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));

$active = 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Profile | SS-Wallet</title>
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
      <h1>My Profile</h1>
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
          <h2>Be found by well-wishers</h2>
          <p class="muted">Well-wishers are private donors and alumni. If you list yourself, they can read your profile and budget and choose to fund it, without you having to know them first.</p>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <?php if (!$ready): ?>
        <div class="alert error">Profiles are not set up yet. Ask the administrator to run the Step 29 database update.</div>
      <?php else: ?>

      <div class="grid-2">
        <section class="card">
          <div class="card-head">
            <h3>Your profile</h3>
            <?php if ($listed): ?><span class="pill green">Listed for well-wishers</span><?php else: ?><span class="pill grey">Not listed</span><?php endif; ?>
          </div>
          <form action="../Backend/profile_action.php" method="post" class="form-grid">
            <div>
              <label for="institution">University or college</label>
              <input type="text" id="institution" name="institution" maxlength="120" placeholder="e.g. Strathmore University" value="<?= htmlspecialchars($p['institution'] ?? '') ?>">
            </div>
            <div class="two-col">
              <div>
                <label for="course">Course</label>
                <input type="text" id="course" name="course" maxlength="120" placeholder="e.g. BBIT" value="<?= htmlspecialchars($p['course'] ?? '') ?>">
              </div>
              <div>
                <label for="year_of_study">Year of study</label>
                <select id="year_of_study" name="year_of_study">
                  <option value="">Choose…</option>
                  <?php for ($y = 1; $y <= 6; $y++): ?>
                    <option value="<?= $y ?>" <?= (string) ($p['year_of_study'] ?? '') === (string) $y ? 'selected' : '' ?>>Year <?= $y ?></option>
                  <?php endfor; ?>
                </select>
              </div>
            </div>
            <div>
              <label for="story">My story <span class="muted" style="font-weight:400;">(why you need support, 50–600 characters)</span></label>
              <textarea id="story" name="story" rows="6" maxlength="600" placeholder="e.g. I am a second-year student from Kitui. My mother sells vegetables and pays part of my fees, but rent and food in Nairobi are hard to cover..."><?= htmlspecialchars($p['story'] ?? '') ?></textarea>
            </div>
            <label class="check">
              <input type="checkbox" name="listed" value="1" <?= !empty($p['listed']) ? 'checked' : '' ?>>
              <span><strong>Show me to well-wishers</strong><br>
                <span class="muted small">They see your first name and initial (<?= htmlspecialchars(public_student_name($_SESSION['full_name'])) ?>), your institution, course, year, story and budget. Never your email or phone. A well-wisher who funds you becomes one of your sponsors straight away, without a request to accept.</span></span>
            </label>
            <button type="submit" class="btn">Save profile</button>
          </form>
        </section>

        <section class="card">
          <div class="card-head"><h3>How it works</h3></div>
          <ol class="steps-list">
            <li>Fill in your profile and tick <strong>Show me to well-wishers</strong>.</li>
            <li>Create your budget on <a href="student-budget.php">My Budget</a> and submit it. You can do this even before you have any sponsor.</li>
            <li>Well-wishers browse listed students and choose to fund a % or an amount of your budget.</li>
            <li>When your funders reach 100%, the budget becomes active. If it stays below, you decide whether to start with what is funded.</li>
          </ol>
          <p class="muted small">You can untick the box at any time. Sponsors who already fund you stay linked.</p>
        </section>
      </div>
      <?php endif; ?>
    </main>
  </div>
</div>
</body>
</html>