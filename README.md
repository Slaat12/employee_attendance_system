# Employee Attendance Management System

A PHP-based employee attendance application with a navy, white and light-grey corporate interface.

## Current build status

This ZIP contains a working demonstration application using PHP sessions and a temporary JSON file store. PostgreSQL has **not** been connected yet, as requested. The data adapter is isolated in `app/JsonStore.php` so it can be replaced with a PostgreSQL repository.

Do not deploy the JSON-backed demo to a live company server. Connect PostgreSQL, configure HTTPS, change all demo credentials, and complete the security checklist first.

## Requirements

- PHP 8.1 or newer
- Apache (XAMPP is suitable)
- PHP extensions: `json`, `mbstring`
- PostgreSQL will be required in the database integration stage
- Browser: current Chrome, Edge or Firefox

## Run locally

1. Extract `employee_attendance_system.zip`.
2. Copy the `employee_attendance_system` folder into `C:\xampp\htdocs\`.
3. Start Apache in the XAMPP Control Panel.
4. Open `http://localhost/employee_attendance_system/`.
5. Sign in using the demo administrator account below.

No data setup is needed: the first request creates `storage/*.json` with working demo accounts.
To reset to the demo state, stop Apache, delete the `storage/*.json` files and `storage/.ready-v2`, then start again.

Quick try without XAMPP: run `php -S localhost:8000` inside the project folder and open `http://localhost:8000/`.

### Demo administrator

- Email: `admin@northstar.local`
- Password: `Admin@12345`

### Demo employees

| Employee ID | PIN | Name |
|---|---:|---|
| EMP001 | 1234 | Ama Mensah |
| EMP002 | 2468 | Kofi Asare |
| EMP003 | 1357 | Esi Boateng |

These are demonstration credentials only. Replace them before any real deployment.

## Included modules

- Administrator login and logout
- Employee kiosk with ID/PIN check-in and check-out
- Employee directory: add, edit, activate and deactivate
- Department listing and creation
- Attendance history with date filtering
- Dashboard attendance summary
- CSV attendance report export
- Work schedule and grace-period settings
- Server-side validation, CSRF tokens, output escaping, password hashing
- Login throttling: 5 wrong passwords/PINs in 5 minutes locks that account for 5 minutes
- Session ID regeneration, browser-session cookie, inactivity timeout and logout cleanup
- Company name from Settings appears in the header, footer and kiosk

## Important implementation notes

### Shared kiosk
The employee must authenticate for every attendance transaction. Employee identity is cleared after every attempt so the shared kiosk does not retain the previous employee. The employee's PIN is never stored in the session.

### Session behavior
The app uses a browser-session cookie and a 20-minute inactivity timeout. Logout clears the session data, expires the cookie and destroys the PHP session. Closing a browser normally removes the browser-session cookie. Server-side session files are cleaned by PHP's garbage collection, not by a reliable browser-close notification. Configure PHP session garbage collection appropriately on the host.

### Attendance rules
- One attendance record per employee per day: check in, then check out. A second check-in the same day is refused.
- Late = checked in after the opening time plus the grace period (Settings).
- If an employee forgets to check out, that day shows **Missed** in the admin views and CSV. It never blocks them from checking in the next day, and no hours are guessed for it.
- The kiosk uses the same Employee ID + PIN check every time and keeps nobody signed in. It works even while an admin is signed in on the same browser (sidebar -> Open kiosk).

### Current storage
`app/JsonStore.php` stores demo data in `storage/*.json`. All reads and writes go through file locks, so simultaneous check-ins cannot overwrite each other, and a damaged file raises an error instead of being silently treated as empty. The storage folder includes an Apache `.htaccess` deny rule. For Nginx or another server, add an equivalent deny rule. For a live deployment, move private storage outside the web root or replace it with PostgreSQL.

### Validation
Frontend validation improves usability, but every important field is validated again in PHP. Names accept Unicode letters, spaces, apostrophes, periods and hyphens. Phone numbers accept digits only (10–15 digits). Employee IDs accept 3–20 letters, digits and hyphens. Employee PINs accept 4–8 digits in this demo. For production, consider a stronger employee authentication design and rate limiting.

## PostgreSQL integration plan

The next stage should:
1. Create a PostgreSQL database and normalized tables for admins, employees, departments, attendance, work schedules, leave requests and audit logs.
2. Add a PDO PostgreSQL connection in `config/database.php`.
3. Replace `JsonStore` calls with repository methods using PDO prepared statements.
4. Add database-level unique constraints, foreign keys and checks.
5. Use transactions and row locking for check-in/check-out to prevent concurrent duplicate actions.
6. Add audit logging for administrative changes.
7. Add backup, restore and retention policies.

A starter SQL schema is included in `database/schema.sql`. It already matches the app's rules (one record per employee per day, throttle table). A PDO connection template is included at `config/database.php.example`.

## Security lab

`security-lab/README.md` describes safe, local-only vulnerability demonstrations. Deliberately vulnerable examples should remain isolated from the production application. Do not publish or deploy the vulnerable lab folder.

## Before real launch

- Connect PostgreSQL and remove JSON demo storage.
- Change all demo credentials and create named admin accounts.
- Use HTTPS.
- Put private configuration and storage outside the public web root.
- Set `APP_DEMO` to `false` in `app/bootstrap.php` (hides the demo credentials on the sign-in page).
- Configure server-side session cleanup and appropriate session storage.
- Add audit logs and backups.
- Test access control, CSRF, XSS, SQL injection, IDOR and concurrency.
- Review privacy, employee consent, retention and company policy requirements.
