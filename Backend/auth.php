<?php
// Backend/auth.php
// Shared helper: protects a page so only a logged-in user with the right role can use it.
// Usage:  require_once __DIR__ . '/../Backend/auth.php';  require_role('sponsor');

function require_role(string $role): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== $role) {
        header('Location: ../Frontend/login.php');
        exit;
    }

    // Stop the browser from showing this page again after logout (Back button)
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}
