# System Tools & Maintenance Scripts

This directory contains standalone utility, diagnostic, and maintenance scripts for GlobalSCM.

### Available Tools

| File | Purpose |
| :--- | :--- |
| `debug_db.php` | Quick check for database connection and table schema verification. |
| `test.php` | Basic PDO connection and query test script. |
| `verify_sqlite.php` | Verifies SQLite read/write capability by querying the admin user. |
| `reset_admin_password.php` | Resets or generates the default `admin@globalscm.com` administrator account password (`admin@08`). |
| `hello world.txt` | Scratchpad / testing artifact. |
| `Screenshot 2026-09-26 153807.png` | Historical UI reference screenshot. |

### Running Tools
You can execute any of these scripts directly via CLI:
```bash
php tools/debug_db.php
php tools/verify_sqlite.php
php tools/reset_admin_password.php
```
