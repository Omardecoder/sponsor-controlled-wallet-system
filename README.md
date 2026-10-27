# SS-Wallet

Sponsor-controlled wallet system (PHP + MySQL).

## Setup
1. Start MySQL in XAMPP.
2. In phpMyAdmin > SQL tab, run `Db/schema.sql`.
3. Check `Backend/db.php` (default: root, empty password).
4. From this folder run: `php -S localhost:8000`
5. Open http://localhost:8000/Frontend/register.php

## Roles
- Public registration: student, sponsor.
- Admin: register a user, then set `role` to `admin` in phpMyAdmin.

## Planned phases (from the functional map)
1. Disbursement via M-Pesa Daraja STK Push (not built yet; funding is currently recorded only)
2. Student self-budgeting into categories
3. Expenditure logging with receipt upload
4. Financial health score (0-100) and sponsor oversight
