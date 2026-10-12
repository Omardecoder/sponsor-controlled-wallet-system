<?php
// Frontend/mpesa-wait.php?id=PAYMENT_ID
// Sponsor: "Check your phone" page while the M-Pesa payment is completed.
// Asks ../Backend/mpesa_status.php every 3 seconds and shows the result.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/auth.php';
require_role('sponsor');

$sponsorId = (int) $_SESSION['user_id'];
$paymentId = (int) ($_GET['id'] ?? 0);

$stmt = $conn->prepare(
    'SELECT p.id, p.amount, p.phone, p.status, u.full_name AS student_name
     FROM mpesa_payments p
     JOIN wallets w ON w.id = p.wallet_id
     JOIN users u   ON u.id = w.student_id
     WHERE p.id = ? AND p.sponsor_id = ?'
);
$stmt->bind_param('ii', $paymentId, $sponsorId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    header('Location: sponsor-dashboard.php');
    exit;
}

// Show the phone as 0712 *** 678
$local = '0' . substr($payment['phone'], 3);
$maskedPhone = substr($local, 0, 4) . ' *** ' . substr($local, -3);

$nameParts = preg_split('/\s+/', trim($_SESSION['full_name']));
$initials  = strtoupper(mb_substr($nameParts[0], 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));

$active = 'send';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>M-Pesa payment | SponsorWallet</title>
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
      <h1>M-Pesa payment</h1>
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
      <section class="card pay-card" id="pay" data-id="<?= (int) $payment['id'] ?>" data-status="<?= htmlspecialchars($payment['status']) ?>">

        <div class="pay-state" data-state="pending">
          <div class="spinner" aria-hidden="true"></div>
          <h2>Check your phone</h2>
          <p>An M-Pesa prompt for <strong>KSh <?= number_format((float) $payment['amount'], 0) ?></strong> was sent to <strong><?= htmlspecialchars($maskedPhone) ?></strong>.</p>
          <p class="muted">Enter your M-Pesa PIN to fund <?= htmlspecialchars($payment['student_name']) ?>'s wallet. This page updates by itself.</p>
          <p class="muted small" id="slow" hidden>Still waiting for M-Pesa. If you already entered your PIN, the wallet will be credited as soon as Safaricom confirms. You can leave this page.</p>
        </div>

        <div class="pay-state" data-state="success" hidden>
          <div class="pay-icon ok" aria-hidden="true">✓</div>
          <h2>Payment received</h2>
          <p><strong>KSh <span class="js-amount"><?= number_format((float) $payment['amount'], 2) ?></span></strong> was added to <?= htmlspecialchars($payment['student_name']) ?>'s wallet.</p>
          <p class="muted">M-Pesa receipt: <strong class="js-receipt">—</strong></p>
        </div>

        <div class="pay-state" data-state="failed" hidden>
          <div class="pay-icon bad" aria-hidden="true">!</div>
          <h2>Payment not completed</h2>
          <p class="js-message">The payment was cancelled or failed. No money was added.</p>
        </div>

        <div class="row" style="justify-content:center; margin-top:18px;">
          <a class="btn" href="sponsor-dashboard.php">Back to dashboard</a>
          <a class="btn btn-outline" href="sponsor-dashboard.php#send-funds">Send another payment</a>
        </div>
      </section>
    </main>
  </div>
</div>

<script>
  const card   = document.getElementById('pay');
  const id     = card.dataset.id;
  let   tries  = 0;

  function show(state, data) {
    card.querySelectorAll('.pay-state').forEach(el => el.hidden = el.dataset.state !== state);
    if (state === 'success' && data) {
      card.querySelector('.js-receipt').textContent = data.receipt || '—';
      card.querySelector('.js-amount').textContent  = data.amount;
    }
    if (state === 'failed' && data && data.message) {
      card.querySelector('.js-message').textContent = data.message + ' No money was added.';
    }
  }

  async function check() {
    tries++;
    try {
      const res  = await fetch('../Backend/mpesa_status.php?id=' + encodeURIComponent(id), { cache: 'no-store' });
      const data = await res.json();
      if (data.status === 'success' || data.status === 'failed') { show(data.status, data); return; }
    } catch (e) { /* try again */ }
    if (tries === 40) document.getElementById('slow').hidden = false;   // about 2 minutes
    if (tries < 100) setTimeout(check, 3000);                          // stop after about 5 minutes
  }

  show(card.dataset.status === 'pending' ? 'pending' : card.dataset.status);
  if (card.dataset.status === 'pending') setTimeout(check, 3000); else check();
</script>
</body>
</html>