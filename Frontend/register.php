<?php
// frontend/register.php
// Posts to ../Backend/register_process.php. Errors and old input come back through the session.
//
// NOTE: Public registration allows only "student" and "sponsor".
// Admin accounts must never be selectable here. Create them manually
// in the database or from a protected admin-only page.

session_start();
$errors = $_SESSION['register_errors'] ?? [];
$old    = $_SESSION['register_old'] ?? [];
unset($_SESSION['register_errors'], $_SESSION['register_old']);
$old += ['full_name' => '', 'email' => '', 'phone' => '', 'role' => ''];

$appName = 'SponsorWallet';          // change to your system name
$tagline = 'Sponsor-controlled wallets';

// Roles offered on public registration (value => [label, description])
$roles = [
    'student' => ['Student', 'Receive funds and spend within your sponsor\'s rules.'],
    'sponsor'      => ['Sponsor', 'A parent or guardian who funds a student and approves their budget.'],
    'organization' => ['Organization', 'A foundation or company that posts sponsorship programs students can apply to.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Create account | <?= htmlspecialchars($appName) ?></title>
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
    body { font-family: var(--body); color: var(--ink); background: var(--paper); line-height: 1.5; }

    .page { display: grid; grid-template-columns: 1.05fr 1fr; min-height: 100vh; }

    /* ---------- Left: brand + how it works ---------- */
    .brand-side {
      background: var(--forest);
      color: var(--white);
      padding: 48px 56px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 40px;
      position: sticky;
      top: 0;
      height: 100vh;
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

    /* sponsor -> student flow */
    .flow { margin-top: 36px; max-width: 380px; display: grid; gap: 0; }
    .flow-step {
      background: var(--forest-2);
      border: 1px solid #2c6a63;
      border-radius: 14px;
      padding: 14px 18px;
    }
    .flow-step strong { display: block; font-family: var(--display); font-size: 1.05rem; }
    .flow-step span { font-size: .88rem; color: #a9c4bf; }
    .flow-link {
      width: 2px; height: 18px; background: var(--saffron); margin-left: 28px;
    }

    .foot-note { font-size: .8rem; color: #8fb0aa; }

    /* ---------- Right: form ---------- */
    .form-side { display: grid; place-items: center; padding: 40px 24px; }
    .form-wrap { width: 100%; max-width: 440px; }
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
    .field label, .legend { display: block; font-weight: 600; font-size: .92rem; margin-bottom: 6px; }
    .input-box { position: relative; }
    input[type="text"], input[type="email"], input[type="tel"], input[type="password"] {
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

    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

    .toggle {
      position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
      background: none; border: 0; cursor: pointer;
      font: inherit; font-size: .85rem; font-weight: 600; color: var(--forest);
      padding: 6px 8px; border-radius: 6px;
    }
    .toggle:focus-visible { outline: 3px solid var(--saffron); }
    .has-toggle input { padding-right: 70px; }

    /* role choice */
    fieldset { border: 0; }
    .role-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
    .role { position: relative; }
    .role input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
    .role-card {
      display: block;
      height: 100%;
      background: var(--white);
      border: 1.5px solid var(--line);
      border-radius: 12px;
      padding: 14px;
      transition: border-color .15s, background .15s;
    }
    .role-card strong { display: block; font-family: var(--display); font-size: 1.05rem; color: var(--forest); }
    .role-card span { font-size: .82rem; color: var(--muted); }
    .role input:checked + .role-card { border-color: var(--forest); background: #e4efe9; box-shadow: inset 0 0 0 1px var(--forest); }
    .role input:focus-visible + .role-card { outline: 3px solid var(--saffron); outline-offset: 2px; }

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
    .alert ul { margin-left: 18px; }
    .login-link { margin-top: 24px; text-align: center; color: var(--muted); }
    .admin-note { margin-top: 14px; text-align: center; font-size: .8rem; color: var(--muted); }

    @media (max-width: 860px) {
      .page { grid-template-columns: 1fr; }
      .brand-side { display: none; }
      .mobile-logo { display: flex; }
      .form-side { place-items: start center; padding-top: 48px; }
    }
    @media (max-width: 440px) {
      .two-col, .role-grid { grid-template-columns: 1fr; }
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
        <h1>Set up in a minute. Spend with confidence.</h1>
        <p>Pick the role that fits you. Sponsors fund and control. Students spend within the rules.</p>

        <div class="flow" aria-hidden="true">
          <div class="flow-step"><strong>Sponsor funds the wallet</strong><span>Adds money and sets spending rules</span></div>
          <div class="flow-link"></div>
          <div class="flow-step"><strong>Student spends</strong><span>Only on what the sponsor allows</span></div>
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

        <h2>Create your account</h2>
        <p class="sub">It takes less than a minute.</p>

        <?php if ($errors): ?>
          <div class="alert" role="alert">
            <ul>
              <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form action="../Backend/register_process.php" method="post" novalidate>

          <div class="field">
            <label for="full_name">Full name <span style="font-weight:400;color:var(--muted);">(or organization name)</span></label>
            <input type="text" id="full_name" name="full_name" placeholder="e.g. Amina Wanjiru" autocomplete="name" value="<?= htmlspecialchars($old['full_name']) ?>" required>
          </div>

          <div class="two-col">
            <div class="field">
              <label for="email">Email</label>
              <input type="email" id="email" name="email" placeholder="you@example.com" autocomplete="email" value="<?= htmlspecialchars($old['email']) ?>" required>
            </div>
            <div class="field">
              <label for="phone">Phone number</label>
              <input type="tel" id="phone" name="phone" placeholder="0712 345 678" autocomplete="tel" value="<?= htmlspecialchars($old['phone']) ?>" required>
            </div>
          </div>

          <div class="field">
            <label for="password">Password</label>
            <div class="input-box has-toggle">
              <input type="password" id="password" name="password" placeholder="At least 8 characters" autocomplete="new-password" required>
              <button type="button" class="toggle" data-target="password" aria-controls="password" aria-pressed="false">Show</button>
            </div>
          </div>

          <div class="field">
            <label for="confirm_password">Confirm password</label>
            <div class="input-box has-toggle">
              <input type="password" id="confirm_password" name="confirm_password" placeholder="Type the password again" autocomplete="new-password" required>
              <button type="button" class="toggle" data-target="confirm_password" aria-controls="confirm_password" aria-pressed="false">Show</button>
            </div>
          </div>

          <fieldset>
            <legend class="legend">I am registering as a</legend>
            <div class="role-grid">
              <?php foreach ($roles as $value => [$label, $desc]): ?>
                <label class="role">
                  <input type="radio" name="role" value="<?= htmlspecialchars($value) ?>" <?= $old['role'] === $value ? 'checked' : '' ?> required>
                  <span class="role-card">
                    <strong><?= htmlspecialchars($label) ?></strong>
                    <span><?= htmlspecialchars($desc) ?></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <button type="submit" class="btn">Create account</button>
        </form>

        <p class="login-link">Already have an account? <a href="login.php">Log in</a></p>
        <p class="admin-note">Administrator accounts are created by the system owner, not here.</p>
      </div>
    </main>

  </div>

  <script>
    // Show / hide password (works for both password fields)
    document.querySelectorAll('.toggle').forEach(btn => {
      btn.addEventListener('click', () => {
        const input = document.getElementById(btn.dataset.target);
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.textContent = show ? 'Hide' : 'Show';
        btn.setAttribute('aria-pressed', show);
      });
    });
  </script>
</body>
</html>