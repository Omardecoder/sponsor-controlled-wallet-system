<?php
// Frontend/admin-dashboard.php
// Administrator: overview of the whole system, all users, wallets (freeze / unfreeze) and M-Pesa payments.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_role('admin');

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- Overview figures ----------
$roles = ['student' => 0, 'sponsor' => 0, 'admin' => 0];
foreach ($conn->query('SELECT role, COUNT(*) AS n FROM users GROUP BY role')->fetch_all(MYSQLI_ASSOC) as $r) {
    $roles[$r['role']] = (int) $r['n'];
}

$w = $conn->query(
    "SELECT COUNT(*) AS n, COALESCE(SUM(balance), 0) AS balance, COALESCE(SUM(status = 'frozen'), 0) AS frozen FROM wallets"
)->fetch_assoc();

$t = $conn->query(
    "SELECT COALESCE(SUM(CASE WHEN type = 'funding'  THEN amount END), 0) AS funded,
            COALESCE(SUM(CASE WHEN type = 'spending' THEN amount END), 0) AS spent
     FROM transactions"
)->fetch_assoc();

$m = $conn->query(
    "SELECT COALESCE(SUM(status = 'success'), 0) AS ok,
            COALESCE(SUM(status = 'failed'), 0)  AS failed,
            COALESCE(SUM(status = 'pending'), 0) AS pending
     FROM mpesa_payments"
)->fetch_assoc();

$pendingProposals = (int) $conn->query("SELECT COUNT(*) AS n FROM budget_proposals WHERE status = 'pending'")->fetch_assoc()['n'];

// ---------- Users (with search and role filter) ----------
$q          = trim($_GET['q'] ?? '');
$roleFilter = in_array($_GET['role'] ?? '', ['student', 'sponsor', 'admin'], true) ? $_GET['role'] : '';

$where  = ['1 = 1'];
$types  = '';
$params = [];
if ($q !== '') {
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
    $like = '%' . $q . '%';
    $types .= 'sss';
    array_push($params, $like, $like, $like);
}
if ($roleFilter) {
    $where[] = 'u.role = ?';
    $types .= 's';
    $params[] = $roleFilter;
}
// A student can have several sponsors and a sponsor several students (sponsorships table)
$sql = "SELECT u.id, u.full_name, u.email, u.phone, u.role, u.sponsor_type, u.created_at,
               (SELECT GROUP_CONCAT(CONCAT(s.full_name, IF(x.is_anonymous = 1, ' (anonymous)', '')) ORDER BY x.created_at SEPARATOR ', ')
                  FROM sponsorships x JOIN users s ON s.id = x.sponsor_id
                 WHERE x.student_id = u.id AND x.status = 'active') AS sponsor_name,
               (SELECT COUNT(*) FROM sponsorships x WHERE x.sponsor_id = u.id AND x.status = 'active') AS student_count
        FROM users u
        WHERE " . implode(' AND ', $where) . "
        ORDER BY u.created_at DESC
        LIMIT 100";
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---------- Wallets ----------
$wallets = $conn->query(
    "SELECT w.id, w.balance, w.status, w.created_at,
            st.full_name AS student_name,
            (SELECT GROUP_CONCAT(CONCAT(s.full_name, IF(x.is_anonymous = 1, ' (anonymous)', '')) ORDER BY x.created_at SEPARATOR ', ')
               FROM sponsorships x JOIN users s ON s.id = x.sponsor_id
              WHERE x.student_id = w.student_id AND x.status = 'active') AS sponsor_name,
            (SELECT COALESCE(SUM(amount), 0) FROM transactions t WHERE t.wallet_id = w.id AND t.type = 'spending') AS spent
     FROM wallets w
     JOIN users st ON st.id = w.student_id
     ORDER BY w.status DESC, st.full_name"
)->fetch_all(MYSQLI_ASSOC);

// ---------- M-Pesa payments ----------
$payments = $conn->query(
    'SELECT p.id, p.amount, p.phone, p.status, p.mpesa_receipt, p.result_desc, p.created_at,
            sp.full_name AS sponsor_name, st.full_name AS student_name
     FROM mpesa_payments p
     JOIN wallets w ON w.id = p.wallet_id
     JOIN users st  ON st.id = w.student_id
     JOIN users sp  ON sp.id = p.sponsor_id
     ORDER BY p.id DESC
     LIMIT 30'
)->fetch_all(MYSQLI_ASSOC);

// ---------- Organizations (must be verified before students see their programs) ----------
$orgs = [];
try {
    $orgs = $conn->query(
        "SELECT u.id, u.full_name, u.email, u.phone, u.about, u.org_verified, u.created_at,
                (SELECT COUNT(*) FROM programs pr WHERE pr.org_id = u.id) AS n_programs,
                (SELECT COUNT(*) FROM sponsorships x WHERE x.sponsor_id = u.id AND x.status = 'active') AS n_students
         FROM users u
         WHERE u.role = 'sponsor' AND u.sponsor_type = 'organization'
         ORDER BY u.org_verified, u.created_at DESC"
    )->fetch_all(MYSQLI_ASSOC);
} catch (Throwable $e) {
    // Step 22a not run yet
}

function kes($v): string { return 'KES ' . number_format((float) $v, 0); }
function masked_phone(string $p254): string
{
    $local = str_starts_with($p254, '254') ? '0' . substr($p254, 3) : $p254;
    return substr($local, 0, 4) . ' *** ' . substr($local, -3);
}

$statusPill = ['success' => 'green', 'failed' => 'red', 'pending' => 'amber'];

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));

$active = 'overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin | SS-Wallet</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="layout">

  <?php include __DIR__ . '/partials/admin-sidebar.php'; ?>

  <div class="content">
    <header class="topbar">
      <h1>Administration</h1>
      <div class="topbar-right">
        <div class="profile">
          <div class="avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></div>
          <div class="profile-text">
            <div class="profile-name"><?= htmlspecialchars($_SESSION['full_name']) ?></div>
            <div class="profile-role">Administrator</div>
          </div>
        </div>
      </div>
    </header>

    <main class="page">
      <div class="page-head">
        <div>
          <h2>System overview</h2>
          <p class="muted">Everyone and every shilling in SS-Wallet, in one place.</p>
        </div>
      </div>

      <?php if ($flash): ?>
        <div class="alert <?= $flash['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= htmlspecialchars($flash['message']) ?></div>
      <?php endif; ?>

      <!-- Overview -->
      <section class="stats" aria-label="Overview">
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('students') ?></div>
          <div class="stat-label">Users</div>
          <div class="stat-value"><?= $roles['student'] + $roles['sponsor'] ?></div>
          <div class="stat-note"><?= $roles['student'] ?> student<?= $roles['student'] === 1 ? '' : 's' ?> · <?= $roles['sponsor'] ?> sponsor<?= $roles['sponsor'] === 1 ? '' : 's' ?></div>
        </div>
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('wallet') ?></div>
          <div class="stat-label">Held in wallets</div>
          <div class="stat-value"><?= kes($w['balance']) ?></div>
          <div class="stat-note"><?= (int) $w['n'] ?> wallets<?= (int) $w['frozen'] ? ' · ' . (int) $w['frozen'] . ' frozen' : '' ?></div>
        </div>
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('activity') ?></div>
          <div class="stat-label">Funded / spent</div>
          <div class="stat-value"><?= kes($t['funded']) ?></div>
          <div class="stat-note"><?= kes($t['spent']) ?> spent by students</div>
        </div>
        <div class="card stat">
          <div class="stat-icon"><?= sw_icon('send') ?></div>
          <div class="stat-label">M-Pesa payments</div>
          <div class="stat-value"><?= (int) $m['ok'] ?> <span class="stat-of">successful</span></div>
          <div class="stat-note"><?= (int) $m['failed'] ?> failed · <?= (int) $m['pending'] ?> pending · <?= $pendingProposals ?> budget<?= $pendingProposals === 1 ? '' : 's' ?> awaiting approval</div>
        </div>
      </section>

      <!-- Users -->
      <section class="card" id="users">
        <div class="card-head">
          <h3>Users</h3>
          <span class="muted small"><?= count($users) ?> shown</span>
        </div>
        <form method="get" action="admin-dashboard.php#users" class="filters filters-2">
          <div>
            <label for="q">Search</label>
            <input type="text" id="q" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Name, email or phone">
          </div>
          <div>
            <label for="role">Role</label>
            <select id="role" name="role">
              <option value="">All roles</option>
              <?php foreach (['student' => 'Students', 'sponsor' => 'Sponsors', 'admin' => 'Admins'] as $val => $lbl): ?>
                <option value="<?= $val ?>" <?= $roleFilter === $val ? 'selected' : '' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-actions">
            <button type="submit" class="btn">Search</button>
            <?php if ($q !== '' || $roleFilter): ?><a href="admin-dashboard.php#users" class="small">Clear</a><?php endif; ?>
          </div>
        </form>

        <?php if (!$users): ?>
          <p class="empty" style="margin-top:14px;">No users match.</p>
        <?php else: ?>
          <div class="table-wrap" style="margin-top:16px;">
            <table>
              <thead><tr><th>Name</th><th>Contact</th><th>Role</th><th>Linked to</th><th>Joined</th></tr></thead>
              <tbody>
                <?php foreach ($users as $u): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
                    <td class="muted"><?= htmlspecialchars($u['email']) ?><br><?= htmlspecialchars($u['phone']) ?></td>
                    <td><span class="pill <?= ['student' => 'green', 'sponsor' => 'amber', 'admin' => 'grey'][$u['role']] ?? 'grey' ?>"><?= $u['role'] === 'sponsor' ? htmlspecialchars(['family' => 'Family', 'individual' => 'Well-wisher', 'organization' => 'Organization'][$u['sponsor_type'] ?? 'individual'] ?? 'Sponsor') : ucfirst(htmlspecialchars($u['role'])) ?></span></td>
                    <td>
                      <?php if ($u['role'] === 'student'): ?>
                        <?= $u['sponsor_name'] ? 'Sponsored by ' . htmlspecialchars($u['sponsor_name']) : '<span class="muted">Not linked yet</span>' ?>
                      <?php elseif ($u['role'] === 'sponsor'): ?>
                        <?= (int) $u['student_count'] ?> student<?= (int) $u['student_count'] === 1 ? '' : 's' ?>
                      <?php else: ?>
                        <span class="muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(date('j M Y', strtotime($u['created_at']))) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <!-- Organizations -->
      <section class="card" id="organizations">
        <div class="card-head">
          <h3>Organizations</h3>
          <span class="muted small">Students only see programs from verified organizations</span>
        </div>
        <?php if (!$orgs): ?>
          <p class="empty">No organization accounts yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Organization</th><th>Contact</th><th>Programs</th><th>Students</th><th>Status</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($orgs as $o): $ok = (int) $o['org_verified'] === 1; ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($o['full_name']) ?></strong>
                      <?php if ($o['about']): ?><div class="muted small wrap"><?= htmlspecialchars($o['about']) ?></div><?php endif; ?></td>
                    <td class="muted"><?= htmlspecialchars($o['email']) ?><br><?= htmlspecialchars($o['phone']) ?></td>
                    <td><?= (int) $o['n_programs'] ?></td>
                    <td><?= (int) $o['n_students'] ?></td>
                    <td><span class="pill <?= $ok ? 'green' : 'amber' ?>"><?= $ok ? 'Verified' : 'Waiting' ?></span></td>
                    <td>
                      <form action="../Backend/admin_org_status.php" method="post" class="inline-action">
                        <input type="hidden" name="org_id" value="<?= (int) $o['id'] ?>">
                        <input type="hidden" name="action" value="<?= $ok ? 'unverify' : 'verify' ?>">
                        <button type="submit" class="btn btn-sm <?= $ok ? 'btn-outline' : '' ?>"
                                onclick="return confirm('<?= $ok ? 'Remove verification? Its programs will be hidden from students.' : 'Verify this organization? Students will be able to see and apply to its programs.' ?>');"><?= $ok ? 'Unverify' : 'Verify' ?></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <!-- Wallets -->
      <section class="card" id="wallets">
        <div class="card-head">
          <h3>Wallets</h3>
          <span class="muted small">Freezing stops funding, spending and new budgets</span>
        </div>
        <?php if (!$wallets): ?>
          <p class="empty">No wallets yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Student</th><th>Sponsors</th><th class="num">Balance</th><th class="num">Spent</th><th>Status</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($wallets as $wl): $frozen = $wl['status'] !== 'active'; ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($wl['student_name']) ?></strong></td>
                    <td><?= $wl['sponsor_name'] ? htmlspecialchars($wl['sponsor_name']) : '<span class="muted">None active</span>' ?></td>
                    <td class="num"><?= number_format((float) $wl['balance'], 2) ?></td>
                    <td class="num"><?= number_format((float) $wl['spent'], 2) ?></td>
                    <td><span class="pill <?= $frozen ? 'red' : 'green' ?>"><?= $frozen ? 'Frozen' : 'Active' ?></span></td>
                    <td>
                      <form action="../Backend/admin_wallet_status.php" method="post" class="inline-action">
                        <input type="hidden" name="wallet_id" value="<?= (int) $wl['id'] ?>">
                        <?php if ($frozen): ?>
                          <input type="hidden" name="action" value="unfreeze">
                          <button type="submit" class="btn btn-outline btn-sm">Unfreeze</button>
                        <?php else: ?>
                          <input type="hidden" name="action" value="freeze">
                          <input type="hidden" name="reason" value="">
                          <button type="submit" class="btn btn-danger btn-sm"
                                  onclick="const r = prompt('Reason for freezing <?= htmlspecialchars(addslashes($wl['student_name'])) ?>\'s wallet (the student and sponsor will see it):'); if (!r) return false; this.form.reason.value = r; return true;">Freeze</button>
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

      <!-- M-Pesa payments -->
      <section class="card" id="payments">
        <div class="card-head">
          <h3>M-Pesa payments</h3>
          <span class="muted small">Latest 30</span>
        </div>
        <?php if (!$payments): ?>
          <p class="empty">No M-Pesa payments yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Date</th><th>Sponsor → Student</th><th>Phone</th><th>Status</th><th>Receipt / reason</th><th class="num">Amount (KES)</th></tr></thead>
              <tbody>
                <?php foreach ($payments as $p): ?>
                  <tr>
                    <td><?= htmlspecialchars(date('j M Y, H:i', strtotime($p['created_at']))) ?></td>
                    <td><?= htmlspecialchars($p['sponsor_name']) ?> → <?= htmlspecialchars($p['student_name']) ?></td>
                    <td class="muted"><?= htmlspecialchars(masked_phone($p['phone'])) ?></td>
                    <td><span class="pill <?= $statusPill[$p['status']] ?? 'grey' ?>"><?= ucfirst(htmlspecialchars($p['status'])) ?></span></td>
                    <td class="wrap <?= $p['status'] === 'success' ? '' : 'muted' ?>"><?= htmlspecialchars($p['status'] === 'success' ? ($p['mpesa_receipt'] ?? '') : ($p['result_desc'] ?? '')) ?></td>
                    <td class="num"><?= number_format((float) $p['amount'], 2) ?></td>
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