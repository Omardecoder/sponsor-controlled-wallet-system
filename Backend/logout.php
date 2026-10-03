<?php
// backend/logout.php
// Destroys the current session, then sends the user back to the login page.

session_start();

// 1. Clear all session data
$_SESSION = [];

// 2. Delete the session cookie in the browser
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $p['path'],
        $p['domain'],
        $p['secure'],
        $p['httponly']
    );
}

// 3. Destroy the session on the server
session_destroy();

// 4. Stop browsers from caching protected pages (so Back doesn't reveal the dashboard)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 5. Redirect to the login page
header('Location: ../Frontend/login.php');
exit;