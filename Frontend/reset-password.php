<?php
// Frontend/reset-password.php?token=...
// Step 2 of resetting a password: the link from the email opens this page. If the token is valid
// (exists, not used, not expired) the user chooses a new password. Posts to ../Backend/reset_process.php.
session_start();
require_once __DIR__ . '/../Backend/db.php';
require_once __DIR__ . '/../Backend/password_reset.php';

$token = (string) ($_GET['token'] ?? $_SESSION['reset_token'] ?? '');
$error = $_SESSION['reset_error'] ?? '';
unset($_SESSION['reset_error'], $_SESSION['reset_token']);

$valid = $token !== '' && find_reset($conn, $token) !== null;

$appName = 'SS-Wallet';
$tagline = 'Sponsor-controlled wallets';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset password | <?= htmlspecialchars($appName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --forest: #0f3d3a;
      --forest-2: #17524d;
      --paper: #f1f5f0;
      --ink: #1b2a29;
      --muted: #5d6f6c;
      --line: #cfdad6;
      --saffron: #f2b134;
      --white: #ffffff;
      --danger: #b3261e;
      --display: "Bricolage Grotesque", "Trebuchet MS", Arial, sans-serif;
      --body: "Instrument Sans", "Segoe UI", Arial, sans-serif;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
      font-family: var(--body);
      color: var(--ink);
      background: var(--paper);
      line-height: 1.5;
    }

    .page { display: grid; grid-template-columns: 1.05fr 1fr; min-height: 100%; }

    /* ---------- Left: brand + wallet preview ---------- */
    .brand-side {
      background: var(--forest);
      color: var(--white);
      padding: 48px 56px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 40px;
    }
    .logo { display: flex; align-items: center; gap: 12px; }
    .logo-mark {
      width: 40px; height: 40px; border-radius: 10px;
      background: var(--saffron); color: var(--forest);
      display: grid; place-items: center;
    }
    .logo-name { font-family: var(--display); font-weight: 700; font-size: 1.35rem; letter-spacing: -0.01em; }
    .logo-tag { font-size: .8rem; color: #a9c4bf; }

    .pitch h1 {
      font-family: var(--display);
      font-weight: 700;
      font-size: clamp(2rem, 3.6vw, 3.1rem);
      line-height: 1.05;
      letter-spacing: -0.02em;
      max-width: 14ch;
    }
    .pitch p { margin-top: 16px; color: #bcd3ce; max-width: 40ch; }

    /* the one memorable element: a wallet with rules */
    .wallet {
      margin-top: 36px;
      max-width: 380px;
      background: var(--forest-2);
      border: 1px solid #2c6a63;
      border-radius: 18px;
      padding: 22px;
    }
    .wallet-top { display: flex; justify-content: space-between; align-items: baseline; }
    .wallet-label { font-size: .85rem; color: #a9c4bf; }
    .wallet-amount { font-family: var(--display); font-weight: 700; font-size: 2rem; letter-spacing: -0.02em; }
    .wallet-amount small { font-size: .9rem; font-weight: 500; color: #a9c4bf; margin-right: 4px; }
    .rules { margin-top: 16px; border-top: 1px dashed #3d7c75; padding-top: 14px; display: grid; gap: 8px; list-style: none; }
    .rules li { display: flex; align-items: center; gap: 10px; font-size: .9rem; }
    .dot { width: 9px; height: 9px; border-radius: 50%; flex: none; }
    .dot.ok { background: #7ad9a4; }
    .dot.no { background: #f08a80; }
    .rules .state { margin-left: auto; color: #a9c4bf; font-size: .8rem; }

    .foot-note { font-size: .8rem; color: #8fb0aa; }

    /* ---------- Right: form ---------- */
    .form-side { display: grid; place-items: center; padding: 40px 24px; }
    .form-wrap { width: 100%; max-width: 400px; }
    .mobile-logo { display: none; margin-bottom: 28px; }
    .mobile-logo .logo-name { color: var(--forest); }

    .form-wrap h2 {
      font-family: var(--display);
      font-weight: 700;
      font-size: 1.9rem;
      letter-spacing: -0.02em;
      color: var(--forest);
    }
    .form-wrap .sub { margin-top: 6px; color: var(--muted); }

    form { margin-top: 28px; display: grid; gap: 18px; }
    .field label { display: block; font-weight: 600; font-size: .92rem; margin-bottom: 6px; }
    .input-box { position: relative; }
    input[type="text"], input[type="password"] {
      width: 100%;
      font: inherit;
      color: var(--ink);
      background: var(--white);
      border: 1.5px solid var(--line);
      border-radius: 10px;
      padding: 13px 14px;
      transition: border-color .15s, box-shadow .15s;
    }
    input::placeholder { color: #94a5a2; }
    input:focus { outline: none; border-color: var(--forest); box-shadow: 0 0 0 3px rgba(242, 177, 52, .45); }
    .hint { margin-top: 5px; font-size: .82rem; color: var(--muted); }

    .toggle {
      position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
      background: none; border: 0; cursor: pointer;
      font: inherit; font-size: .85rem; font-weight: 600; color: var(--forest);
      padding: 6px 8px; border-radius: 6px;
    }
    .toggle:focus-visible { outline: 3px solid var(--saffron); }
    #password { padding-right: 70px; }

    .row { display: flex; justify-content: space-between; align-items: center; font-size: .9rem; }
    .remember { display: flex; align-items: center; gap: 8px; color: var(--muted); }
    .remember input { width: 16px; height: 16px; accent-color: var(--forest); }
    a { color: var(--forest); font-weight: 600; }
    a:focus-visible { outline: 3px solid var(--saffron); border-radius: 4px; }

    .btn {
      font: inherit; font-weight: 600; font-size: 1rem;
      background: var(--forest); color: var(--white);
      border: 0; border-radius: 10px; padding: 14px 16px; cursor: pointer;
      transition: background .15s;
    }
    .btn:hover { background: var(--forest-2); }
    .btn:focus-visible { outline: 3px solid var(--saffron); outline-offset: 2px; }

    .alert {
      margin-top: 22px; padding: 12px 14px; border-radius: 10px;
      background: #fbe9e7; border: 1.5px solid #e8b4ae; color: var(--danger);
      font-size: .92rem; font-weight: 500;
    }
    .alert-success { background: #e4f3ea; border-color: #a9d3b9; color: #1d6b3a; }
    .alert + form { margin-top: 18px; }
    .register { margin-top: 24px; text-align: center; color: var(--muted); }

    @media (max-width: 860px) {
      .page { grid-template-columns: 1fr; }
      .brand-side { display: none; }
      .mobile-logo { display: flex; }
      .form-side { place-items: start center; padding-top: 48px; }
    }
    @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
  </style>
</head>
<body>
  <div class="page">

    <!-- Brand side -->
    <aside class="brand-side">
      <div class="logo">
        <div class="logo-mark" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-9a1 1 0 0 0-1-1H5a2 2 0 0 1-2-2z"/><circle cx="16.5" cy="14.5" r="1.2" fill="currentColor"/></svg>
        </div>
        <div>
          <div class="logo-name"><?= htmlspecialchars($appName) ?></div>
          <div class="logo-tag"><?= htmlspecialchars($tagline) ?></div>
        </div>
      </div>

      <div class="pitch">
        <h1>Money that goes where you meant it to.</h1>
        <p>Fund a wallet, set what it can be spent on, and see every transaction as it happens.</p>

        <div class="wallet" aria-hidden="true">
          <div class="wallet-top">
            <span class="wallet-label">School wallet</span>
            <span class="wallet-amount"><small>KES</small>12,400</span>
          </div>
          <ul class="rules">
            <li><span class="dot ok"></span>School fees <span class="state">Allowed</span></li>
            <li><span class="dot ok"></span>Books and stationery <span class="state">Allowed</span></li>
            <li><span class="dot no"></span>Betting and gaming <span class="state">Blocked</span></li>
          </ul>
        </div>
      </div>

      <p class="foot-note">&copy; <?= date('Y') ?> <?= htmlspecialchars($appName) ?></p>
    </aside>

    <!-- Form side -->
    <main class="form-side">
      <div class="form-wrap">
        <div class="logo mobile-logo">
          <div class="logo-mark" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-9a1 1 0 0 0-1-1H5a2 2 0 0 1-2-2z"/><circle cx="16.5" cy="14.5" r="1.2" fill="currentColor"/></svg>
          </div>
          <div class="logo-name"><?= htmlspecialchars($appName) ?></div>
        </div>

        <h2>Choose a new password</h2>

        <?php if (!$valid): ?>
          <div class="alert" role="alert">This reset link is invalid or has expired. Links work once and only for 30 minutes.</div>
          <p class="register"><a href="forgot-password.php">Send a new link</a> · <a href="login.php">Log in</a></p>
        <?php else: ?>
          <p class="sub">Use at least 8 characters. After saving, log in with your new password.</p>
          <?php if ($error !== ''): ?>
            <div class="alert" role="alert"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <form action="../Backend/reset_process.php" method="post" novalidate>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            <div class="field">
              <label for="password">New password</label>
              <div class="input-box">
                <input type="password" id="password" name="password" placeholder="At least 8 characters" autocomplete="new-password" required>
                <button type="button" class="toggle" id="toggle" aria-controls="password" aria-pressed="false">Show</button>
              </div>
            </div>
            <div class="field">
              <label for="confirm_password">Confirm new password</label>
              <input type="password" id="confirm_password" name="confirm_password" placeholder="Type it again" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn">Save new password</button>
          </form>
        <?php endif; ?>
      </div>
    </main>

  </div>

  <script>
    const pw = document.getElementById('password');
    const btn = document.getElementById('toggle');
    if (pw && btn) {
      btn.addEventListener('click', () => {
        const show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        btn.textContent = show ? 'Hide' : 'Show';
        btn.setAttribute('aria-pressed', show);
      });
    }
  </script>
</body>
</html>