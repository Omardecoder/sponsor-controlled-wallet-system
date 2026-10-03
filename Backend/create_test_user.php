<?php
// Backend/create_test_user.php
// Creates the first ADMIN account (public registration can't make admins).
// Open http://localhost/sponsor-controlled-wallet-system/Backend/create_test_user.php once,
// then DELETE this file or change the password straight away.

require_once __DIR__ . '/db.php';

$fullName = 'System Admin';
$email    = 'admin@example.com';
$phone    = '0700000000';
$password = 'Admin@1234';   // change after first login

$stmt = $conn->prepare('SELECT id FROM users WHERE email = ? OR phone = ?');
$stmt->bind_param('ss', $email, $phone);
$stmt->execute();
$exists = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($exists) {
    exit('An admin with this email or phone already exists. Nothing was changed.');
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$role = 'admin';
$stmt = $conn->prepare('INSERT INTO users (full_name, email, phone, password, role) VALUES (?, ?, ?, ?, ?)');
$stmt->bind_param('sssss', $fullName, $email, $phone, $hash, $role);
$stmt->execute();
$stmt->close();

echo 'Admin created. Log in with ' . htmlspecialchars($email) . ' / ' . htmlspecialchars($password)
   . '<br>Now delete Backend/create_test_user.php.';
