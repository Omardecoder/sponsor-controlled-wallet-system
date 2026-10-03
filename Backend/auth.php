<?php
// Backend/auth.php
// Protects dashboard pages. At the top of a page:
//   session_start();
//   require_once __DIR__ . '/../Backend/auth.php';
//   require_role('student');   // or 'sponsor' / 'admin'

function require_role(string $role): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // Not logged in, or logged in with a different role -> back to login
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== $role) {
        header('Location: ../Frontend/login.php');
        exit;
    }

    // Stop the browser caching protected pages (so Back after logout shows nothing)
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}
