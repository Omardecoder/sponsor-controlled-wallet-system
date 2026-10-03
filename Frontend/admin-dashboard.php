<?php
// frontend/admin_dashboard.php  (placeholder)
session_start();
require_once __DIR__ . '/../Backend/auth.php';
require_role('admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Dashboard</title>
  <style>
    body { font-family: "Segoe UI", Arial, sans-serif; background: #f1f5f0; color: #1b2a29; margin: 0; padding: 48px 24px; }
    .box { max-width: 560px; margin: 0 auto; background: #fff; border: 1.5px solid #cfdad6; border-radius: 14px; padding: 32px; }
    h1 { color: #0f3d3a; margin-bottom: 8px; }
    a { color: #0f3d3a; font-weight: 600; }
  </style>
</head>
<body>
  <div class="box">
    <h1>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?></h1>
    <p>Admin dashboard (placeholder). We'll build this out later.</p>
    <p><a href="../Backend/logout.php">Log out</a></p>
  </div>
</body>
</html>