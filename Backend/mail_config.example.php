<?php
// Backend/mail_config.example.php
// TEMPLATE ONLY. Copy this file, rename the copy to mail_config.php (same folder) and fill it in.
// mail_config.php is in .gitignore: never push it to GitHub and never paste the App Password in chat.
//
// Gmail: turn on 2-Step Verification on the Google account, then create an App Password at
// https://myaccount.google.com/apppasswords (16 letters). Use that here, NOT your normal Gmail password.

define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);                          // 587 = STARTTLS
define('MAIL_SECURE', 'tls');                      // 'tls' for 587, 'ssl' for 465
define('MAIL_USERNAME', 'your.address@gmail.com');
define('MAIL_PASSWORD', 'PASTE_16_LETTER_APP_PASSWORD');
define('MAIL_FROM', 'your.address@gmail.com');     // Gmail requires the same address as MAIL_USERNAME
define('MAIL_FROM_NAME', 'SS-Wallet');

// The address people open SS-Wallet with. Reset links in emails start with this.
// On your PC/laptop: http://localhost/sponsor-controlled-wallet-system
// With ngrok:       https://YOUR-DOMAIN.ngrok-free.dev/sponsor-controlled-wallet-system
define('APP_URL', 'http://localhost/sponsor-controlled-wallet-system');