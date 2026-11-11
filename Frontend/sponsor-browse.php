<?php
// Frontend/sponsor-browse.php
// Well-wishers: browse students who chose to be listed, and pick whom to fund.
// Each card shows only the first name and initial, institution, course, year, story and the
// student's open budget (categories, % funded, amount still needed). No email or phone.
// Filters: search (institution / course), category, amount still needed, has documents, has achievements.
// Sort: least / most funded, smallest / largest budget, most achievements, newest. (Step 30)
// "View and fund" opens sponsor-student.php?id=...
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_once __DIR__ . '/../Backend/categories.php';
require_once __DIR__ . '/../Backend/sponsorship.php';
require_once __DIR__ . '/../Backend/student_docs.php';
require_role('sponsor');

$sponsorId = (int) $_SESSION['user_id'];
if (!is_well_wisher($conn, $sponsorId)) {
    header('Location: sponsor-dashboard.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---- Filters ----
$q        = trim($_GET['q'] ?? '');
$catQ     = trim($_GET['category'] ?? '');
$need     = $_GET['need'] ?? '';
$sort     = $_GET['sort'] ?? 'need';
$hasDocs  = !empty($_GET['docs']);
$hasAch   = !empty($_GET['ach']);
$needBands = [
    ''      => 'Any amount',
    'small' => 'Up to KSh 10,000',
    'mid'   => 'KSh 10,000 – 50,000',
    'large' => 'Over KSh 50,000',
];
$sorts = [
    'need'    => 'Least funded first',
    'closest' => 'Closest to fully funded',
    'smallest'=> 'Smallest budget',
    'largest' => 'Largest budget',
    'achieve' => 'Most achievements',
    'newest'  => 'Newest budgets',
];
if (!isset($needBands[$need])) { $need = ''; }
if (!isset($sorts[$sort]))     { $sort = 'need'; }

// ---- Listed students, with their open (pending) budget if any ----
$rows = [];
$ready = true;
try {
    $sql = "SELECT u.id, u.full_name, u.institution, u.course, u.year_of_study, u.story, u.achievements,
                   (SELECT COUNT(*) FROM student_documents d WHERE d.student_id = u.id) AS doc_count,
                   w.id AS wallet_id,
                   (SELECT MAX(p.id) FROM budget_proposals p WHERE p.wallet_id = w.id AND p.status = 'pending') AS proposal_id,
                   (SELECT sp.status FROM sponsorships sp WHERE sp.student_id = u.id AND sp.sponsor_id = ?) AS my_link
            FROM users u
            LEFT JOIN wallets w ON w.student_id = u.id
            WHERE u.role = 'student' AND u.listed = 1"
         . ($q !== '' ? ' AND (u.institution LIKE ? OR u.course LIKE ?)' : '');
    $stmt = $conn->prepare($sql);
    if ($q !== '') {
        $like = '%' . $q . '%';
        $stmt->bind_param('iss', $sponsorId, $like, $like);
    } else {
        $stmt->bind_param('i', $sponsorId);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Throwable $e) {
    $ready = false;   // Step 29 / Step 30 SQL not run yet
}

$cards = [];
$itemStmt = $conn->prepare('SELECT category, proposed_amount FROM budget_proposal_items WHERE proposal_id = ? AND proposed_amount > 0 ORDER BY proposed_amount DESC');
$dateStmt = $conn->prepare('SELECT created_at FROM budget_proposals WHERE id = ?');
foreach ($rows as $r) {
    $card = $r + ['budget' => null, 'cats' => []];
    $card['ach'] = achievements_list($r['achievements']);
    if ($hasDocs && (int) $r['doc_count'] === 0) { continue; }
    if ($hasAch && !$card['ach']) { continue; }
    if ($r['proposal_id']) {
        $pid = (int) $r['proposal_id'];
        $f   = proposal_funding($conn, $pid, (int) $r['id']);
        $names = budget_categories((int) $r['wallet_id']);
        $itemStmt->bind_param('i', $pid);
        $itemStmt->execute();
        foreach ($itemStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $it) {
            $card['cats'][] = $names[$it['category']] ?? $it['category'];
        }
        $dateStmt->bind_param('i', $pid);
        $dateStmt->execute();
        $created = $dateStmt->get_result()->fetch_assoc()['created_at'] ?? null;
        $card['budget'] = ['total' => $f['total'], 'committed' => $f['committed'], 'remaining' => $f['remaining'],
                           'percent' => $f['percent'], 'created' => $created];
    }

    // Filters that need the budget
    if ($catQ !== '') {
        $hit = false;
        foreach ($card['cats'] as $c) {
            if (mb_stripos($c, $catQ) !== false) { $hit = true; break; }
        }
        if (!$hit) { continue; }
    }
    if ($need !== '') {
        $left = $card['budget']['remaining'] ?? null;
        if ($left === null || $left <= 0) { continue; }
        if ($need === 'small' && $left > 10000) { continue; }
        if ($need === 'mid' && ($left <= 10000 || $left > 50000)) { continue; }
        if ($need === 'large' && $left <= 50000) { continue; }
    }
    $cards[] = $card;
}
$itemStmt->close();
$dateStmt->close();

// Open budgets that still need money first, then the rest
usort($cards, function ($a, $b) use ($sort) {
    $aOpen = $a['budget'] && $a['budget']['remaining'] > 0;
    $bOpen = $b['budget'] && $b['budget']['remaining'] > 0;
    if ($aOpen !== $bOpen) { return $aOpen ? -1 : 1; }
    if ($sort === 'achieve' && count($a['ach']) !== count($b['ach'])) {
        return count($b['ach']) <=> count($a['ach']);
    }
    if (!$aOpen) { return strcmp($a['full_name'], $b['full_name']); }
    return match ($sort) {
        'closest' => $b['budget']['percent'] <=> $a['budget']['percent'],
        'smallest'=> $a['budget']['total'] <=> $b['budget']['total'],
        'largest' => $b['budget']['total'] <=> $a['budget']['total'],
        'achieve' => $a['budget']['percent'] <=> $b['budget']['percent'],
        'newest'  => strcmp((string) $b['budget']['created'], (string) $a['budget']['created']),
        default   => $a['budget']['percent'] <=> $b['budget']['percent'],
    };
});

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));
function kes0($v): string { return 'KSh ' . number_format((float) $v, 0); }

$active = 'browse';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Students | SS-Wallet</title>
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
      <div class="page-head">
        <div>
          <h2>Choose a student to support</h2>
          <p class="muted">These students chose to be listed. You see their first name, studies, story, achievements, documents and budget. Open a student to fund a % or an amount of their budget; you can stay anonymous.</p>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <?php if (!$ready): ?>
        <div class="alert error">The Students page is not set up yet. Ask the administrator to run the Step 29 and Step 30 database updates.</div>
      <?php endif; ?>

      <form method="get" class="card filters browse-filters more">
        <div>
          <label for="q">Institution or course</label>
          <input type="text" id="q" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="e.g. Strathmore, BBIT">
        </div>
        <div>
          <label for="category">Budget category</label>
          <input type="text" id="category" name="category" value="<?= htmlspecialchars($catQ) ?>" placeholder="e.g. Tuition">
        </div>
        <div>
          <label for="need">Still needed</label>
          <select id="need" name="need">
            <?php foreach ($needBands as $k => $label): ?>
              <option value="<?= $k ?>" <?= $need === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="sort">Sort</label>
          <select id="sort" name="sort">
            <?php foreach ($sorts as $k => $label): ?>
              <option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="browse-checks">
          <label class="check"><input type="checkbox" name="docs" value="1" <?= $hasDocs ? 'checked' : '' ?>> <span>Has documents</span></label>
          <label class="check"><input type="checkbox" name="ach" value="1" <?= $hasAch ? 'checked' : '' ?>> <span>Has achievements</span></label>
        </div>
        <div class="filter-actions"><button type="submit" class="btn"><?= sw_icon('search') ?>Show</button></div>
      </form>

      <?php if ($ready && !$cards): ?>
        <section class="card"><p class="empty">No listed students match<?= ($q || $catQ || $need || $hasDocs || $hasAch) ? ' these filters' : ' yet' ?>.</p></section>
      <?php endif; ?>

      <div class="student-cards">
        <?php foreach ($cards as $c):
            $b = $c['budget'];
            $mine = $c['my_link'];
        ?>
          <section class="card student-card">
            <div>
              <h3><?= htmlspecialchars(public_student_name($c['full_name'])) ?>
                <?php if ($mine === 'active'): ?><span class="pill green">You support</span><?php endif; ?>
              </h3>
              <div class="muted small"><?= htmlspecialchars($c['institution'] ?? '') ?> · <?= htmlspecialchars($c['course'] ?? '') ?><?= $c['year_of_study'] ? ' · Year ' . (int) $c['year_of_study'] : '' ?></div>
            </div>
            <p class="story"><?= htmlspecialchars(mb_strimwidth((string) $c['story'], 0, 180, '…')) ?></p>
            <?php if ($c['ach'] || (int) $c['doc_count'] > 0): ?>
              <div class="trust-row">
                <?php if ($c['ach']): ?><span class="pill green" title="<?= htmlspecialchars(implode(' · ', $c['ach'])) ?>">★ <?= htmlspecialchars(mb_strimwidth($c['ach'][0], 0, 40, '…')) ?><?= count($c['ach']) > 1 ? ' +' . (count($c['ach']) - 1) : '' ?></span><?php endif; ?>
                <?php if ((int) $c['doc_count'] > 0): ?><span class="pill grey"><?= (int) $c['doc_count'] ?> document<?= (int) $c['doc_count'] === 1 ? '' : 's' ?></span><?php endif; ?>
              </div>
            <?php endif; ?>

            <?php if ($b): ?>
              <div class="chips-row">
                <?php foreach ($c['cats'] as $cat): ?><span class="pill grey"><?= htmlspecialchars($cat) ?></span><?php endforeach; ?>
              </div>
              <div>
                <div class="bar-top"><b><?= $b['percent'] ?>% funded</b><span><?= kes0($b['remaining']) ?> still needed of <?= kes0($b['total']) ?></span></div>
                <div class="bar-track" role="img" aria-label="<?= $b['percent'] ?>% funded"><div class="bar-fill" style="width: <?= $b['percent'] ?>%"></div></div>
              </div>
            <?php else: ?>
              <p class="muted small">No open budget right now.</p>
            <?php endif; ?>

            <div class="card-foot">
              <?php if ($mine === 'declined'): ?>
                <span class="muted small">This student declined your earlier request.</span>
              <?php else: ?>
                <a class="btn <?= $b && $b['remaining'] > 0 ? '' : 'btn-outline' ?>" href="sponsor-student.php?id=<?= (int) $c['id'] ?>"><?= $b && $b['remaining'] > 0 ? 'View and fund' : 'View' ?></a>
              <?php endif; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    </main>
  </div>
</div>
</body>
</html>
