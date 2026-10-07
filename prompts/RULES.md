# Project Rules

Read these files first: `FEATURE.md` (behavior), `UI_DESIGN.md` (screens and style), `IMPLEMENTATION_PLAN.md` (what to build now), and this file (technical rules). If they conflict or something is unclear, ask me before coding.

## Stack and environment
- Stack: PHP 8 (plain, no framework), PDO + MySQL (phpMyAdmin on XAMPP), HTML, CSS, vanilla JS.
- Runs at http://localhost/UnitSync (project folder: `htdocs/UnitSync`). Database name: `unitsync`, already created and empty. Do not create the database in SQL files, only the tables.
- Folders: config/, includes/, auth/, admin/, leader/, s1/, president/, api/, assets/css, assets/js, sql/, logs/.
- Use a `BASE_URL` constant (`http://localhost/UnitSync`) for every link and asset path. Never hardcode the folder name anywhere else.
- No frameworks and no CDN dependencies. Icons are inline SVG. No external fonts.
- Timezone: Asia/Manila. Call `date_default_timezone_set()` and set the MySQL session timezone to match.
- Charset: utf8mb4 everywhere.

## Development flow for every feature
1. **HTML and PHP first.** Build the page with plain, unstyled HTML and the PHP logic. It must work fully in the browser (forms submit, data saves, errors show) before any styling.
2. **Then CSS.** Style the working page using the tokens in `UI_DESIGN.md` section 5.
3. **Then JavaScript.** Add enhancements only after the page works without JS (modals, toasts, dependent dropdowns, show and hide fields).
- Without JS, messages are plain text rendered by PHP (flash messages stored in the session and shown once). CSS later turns them into banners, and JS turns them into toasts.
- Never make the page depend on JS for validation. Validate on the server first. Add JS validation only as a convenience.
- Stop after each step (A: HTML and PHP, B: CSS, C: JS) so I can test it.

## Security
- Always use PDO prepared statements. Escape all output with `htmlspecialchars()`.
- Hash passwords with `password_hash()` and check them with `password_verify()`.
- Every protected page starts with `require_login()` and `require_role()`. Check scope on the server (a Platoon Leader only sees their own platoon). The same applies to every endpoint in `api/`.
- Add a CSRF token to every form and AJAX request that changes data.
- Session cookies: httponly, samesite=Lax. Call `session_regenerate_id(true)` after login. Apply an inactivity timeout.
- Never commit credentials. Keep them in `config/` and add the file to `.gitignore`.

## UI rules
- Never use `alert()`, `confirm()`, or `prompt()`. Use the toast, modal, banner, and inline-error helpers (`showToast()`, `showConfirm()` in `assets/js/ui.js`) once the JS step is reached.
- Follow the colors, spacing, and animation rules in `UI_DESIGN.md` section 5. Define them once as CSS variables in `:root` and never hardcode colors elsewhere.

## Code conventions
- Forms post to the same page. After a successful POST, redirect (post/redirect/get).
- AJAX endpoints live in `api/` and return JSON in the form `{ "ok": true|false, "message": "...", "data": {} }`.
- Status values are stored as ENUMs or constants, spelled exactly as in `FEATURE.md`.
- All database structure lives in `sql/schema.sql`. Later changes go in `sql/migrations/`. Never change a table by hand without updating these files.
- Use soft deletes (a status column). Never delete user rows.
- Build one feature at a time. Give complete files and say where each file goes.

## Local development overrides (these win over FEATURE.md where they conflict)
- **Admin account:** Create it with a one-time `setup_admin.php` script that reads the username and password from a git-ignored config file. Hash the password, force a password change at first login, and tell me to delete the script afterward.
- **Email:** XAMPP has no mail server. Write every "email" to `logs/mail.log`. Show the generated username on the Admin approval screen. Put sending behind one `send_mail()` function so real SMTP (PHPMailer) can be added later.
- **Backups:** Skip them. I export from phpMyAdmin.
- **PDF export:** Skip until the end. CSV export uses plain PHP.

## Current MVP scope (see IMPLEMENTATION_PLAN.md)
- **Working features:** log in, log out, registration, Admin approval or rejection, and the Admin user list.
- **Dashboard only (no functionality yet):** Class President, Platoon Leader, Battalion S1, and Brigade S1. Each shows a welcome card with the user's name, role, and assignment, plus the navigation with "Coming soon" items.
- **Not in the MVP:** cadet encoding and roster, attendance, notifications, CSV import and export, audit log viewer, forgot password by email, S1 override, and analytics.
- Only create the database tables the MVP needs. Add the rest through `sql/migrations/` in the next phase.

## Build order (do not skip ahead)
1. `sql/schema.sql` and `sql/seed.sql`
2. Foundation: `config/`, `includes/auth.php`, `includes/csrf.php`, layout includes
3. Registration and log in (`auth/`), then `setup_admin.php`
4. Admin dashboard, registration requests (approve and reject), and users
5. Placeholder dashboards for Platoon Leader, Class President, Battalion S1, and Brigade S1

For each item, follow the HTML and PHP, then CSS, then JS flow. After each step: list the files created, tell me how to test it, and wait for me before continuing.