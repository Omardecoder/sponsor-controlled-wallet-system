<?php
mysqli_report(MYSQLI_REPORT_OFF);
$c = @new mysqli('localhost', 'root', '', 'sponsor_wallet_db');
if ($c->connect_error) {
    exit('CONNECTION FAILED: ' . $c->connect_error);
}
echo 'Connected to sponsor_wallet_db.<br>';
$r = $c->query('SELECT COUNT(*) AS n FROM users');
echo $r ? 'users table exists, rows: ' . $r->fetch_assoc()['n'] : 'TABLE PROBLEM: ' . $c->error;