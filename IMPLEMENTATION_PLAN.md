# UnitSync: MVP Implementation Plan

**Goal of this MVP:** Users can register and log in, and the Admin can accept or reject registrations. The other roles get a dashboard only, with no functionality yet.

Reference files: `FEATURE.md` (behavior), `UI_DESIGN.md` (screens and style), `RULES.md` (technical rules).

---

## 1. MVP Scope

| Role | MVP status |
|---|---|
| Everyone | Register, log in, log out, change password (forced at first login) |
| Admin | **Working:** dashboard, accept or reject registrations, user list (deactivate, reactivate, reset password) |
| Platoon Leader | Dashboard only |
| Class President | Dashboard only |
| Battalion S1 and Brigade S1 | Dashboard only |

> I assumed Battalion S1 is also dashboard only, since you named Brigade S1. Tell me if Battalion S1 should differ.

**Out of scope (next phase):** cadet encoding and roster, training sessions, attendance, notifications, CSV import and export, audit log viewer, forgot password by email, real SMTP email.

---

## 2. How Every Feature Is Built

Each feature goes through three steps. Do not start the next step until the current one works.

| Step | What you build | Done when |
|---|---|---|
| **A. HTML and PHP** | Plain, unstyled pages with all the logic: forms, validation, database, sessions, redirects, messages | The feature fully works in the browser, even if it looks plain |
| **B. CSS** | Styles from `UI_DESIGN.md`: layout, forms, tables, buttons, banners | The pages look right on desktop and phone width |
| **C. JavaScript** | Enhancements only: modals, toasts, dependent dropdowns, show and hide fields, mobile menu | Everything still works if JS is turned off, only less polished |

Rules for step A:
- Messages (errors, success, status) are plain text from PHP. Use a session "flash message" that shows once after a redirect.
- Validate everything on the server. Show errors next to the field in plain text.
- Use plain HTML elements. Do not add classes or IDs you will not use, but do add a `class` on the main containers so the CSS step has hooks.

---

## 3. Stages at a Glance

| Stage | Goal | Depends on |
|---|---|---|
| 0 | Database tables, foundation files | Your empty `unitsync` database |
| 1 | Log in and registration | Stage 0 |
| 2 | Admin account and dashboard (accept or reject) | Stage 1 |
| 3 | Platoon Leader dashboard | Stage 2 |
| 4 | Class President dashboard | Stage 2 |
| 5 | Battalion S1 and Brigade S1 dashboards | Stage 2 |

Stages 3, 4, and 5 are independent of each other, so do them in any order. Test each stage in the browser and commit to git before starting the next.

---

## Stage 0: Database and Foundation

### 0.1 Environment (already done)
- [x] XAMPP running, project at `htdocs/UnitSync`, URL `http://localhost/UnitSync`
- [x] Empty database `unitsync` created in phpMyAdmin
- [x] Run `git init` and add a `.gitignore` containing `config/config.local.php` and `logs/`
- [x] Put `FEATURE.md`, `UI_DESIGN.md`, `RULES.md`, and this plan in the project root

### 0.2 Folder structure

```
UnitSync/
├── config/        config.php, config.local.php (git-ignored), db.php
├── includes/      auth.php, csrf.php, flash.php, helpers.php, header.php, sidebar.php, footer.php
├── auth/          login.php, register.php, logout.php, change_password.php
├── admin/         dashboard.php, requests.php, users.php
├── leader/        dashboard.php
├── president/     dashboard.php
├── s1/            dashboard.php
├── api/           (used later, for example companies_platoons.php)
├── assets/
│   ├── css/       style.css
│   └── js/        ui.js, register.js
├── sql/           schema.sql, seed.sql, migrations/
├── logs/          mail.log
└── setup_admin.php   (run once, then delete)
```

### 0.3 Database tables (`sql/schema.sql`)

Only the tables this MVP needs. The database already exists, so the file creates tables only.

| Table | Key columns |
|---|---|
| `programs` | id, code (BSIT, BSCE), name |
| `companies` | id, name (Alpha, Bravo) |
| `platoons` | id, company_id, name (1st, 2nd) |
| `users` | id, username (unique), password_hash, first_name, middle_name, last_name, student_number (unique), email (unique), role, status (pending, approved, rejected, deactivated), rejection_reason, must_change_password, created_at, reviewed_by, reviewed_at |
| `user_assignments` | user_id, program_id (Class President), company_id and platoon_id (Platoon Leader) |
| `login_attempts` | id, username, ip_address, attempted_at (for rate limiting) |
| `audit_log` | id, actor_id, action, entity, entity_id, details, created_at (written on approve, reject, deactivate, and password reset) |

Requirements:
- InnoDB, foreign keys, utf8mb4, unique constraints on username, student number, and email.
- Role values: `admin`, `class_president`, `platoon_leader`, `battalion_s1`, `brigade_s1`.
- Cadets, terms, training sessions, and attendance tables come in the next phase through `sql/migrations/`.

### 0.4 Seed data (`sql/seed.sql`)
- [x] Programs: BSIT, BSCE (add the others your unit uses)
- [x] Companies: Alpha, Bravo. Platoons: 1st and 2nd for each.

### 0.5 Foundation files (HTML and PHP only)
- [x] `config/config.php`: `BASE_URL` (`http://localhost/UnitSync`), timezone Asia/Manila, session settings
- [x] `config/db.php`: PDO connection (exceptions on, utf8mb4)
- [x] `includes/auth.php`: `require_login()`, `require_role()`, `current_user()`, session timeout, redirect to the right dashboard by role
- [x] `includes/csrf.php`: create and check tokens
- [x] `includes/flash.php`: `set_flash()` and `show_flash()` for one-time messages
- [x] `includes/header.php`, `sidebar.php`, `footer.php`: the plain dashboard shell with the sidebar items for each role (see `UI_DESIGN.md` section 3)

**Done when:** `schema.sql` and `seed.sql` import without errors, the tables and seed rows appear in phpMyAdmin, and a test page connects to the database.

---

## Stage 1: Log In and Registration

### Step A: HTML and PHP

**Registration (`auth/register.php`)**
- [x] Fields: first name, middle name, last name, student number, email, password, confirm password, role, consent checkbox.
- [x] Role-specific fields, always visible in this step with a hint (JS will show and hide them later):
  - **Program** (dropdown): required for Class President.
  - **Company and Platoon** (one dropdown grouped by company, for example "Alpha - 1st"): required for Platoon Leader.
  - Battalion S1 and Brigade S1: nothing extra.
- [x] Server validation, with a plain error message next to each field:
  - Required fields, valid email, password policy (at least 10 characters with mixed types), matching passwords, consent checked.
  - Unique student number and email.
  - Program required for Class President. Company and platoon required for Platoon Leader, and the platoon must belong to the company.
- [x] On success: insert the user with status `pending`, hash the password, and generate the username (`PL-2026-0001`, `CP-`, `BN-`, `BR-`) with the next sequence number for that role and year.
- [x] Redirect to a confirmation page that shows the generated username and says the account is waiting for Admin approval.
- [x] Keep the entered values in the form after an error (except passwords).

**Log in (`auth/login.php`) and log out (`auth/logout.php`)**
- [x] Username and password form, with the "Don't have an account? Register" link.
- [x] Check with `password_verify()`.
- [x] Show a plain message for each account status: pending ("Your registration is being processed."), rejected (the Admin's reason), deactivated (the reason).
- [x] Rate limit: 5 failed attempts locks the username for 10 minutes, using `login_attempts`.
- [x] On success: `session_regenerate_id(true)`, then redirect to the dashboard for the role.
- [x] If `must_change_password` is set, redirect to `auth/change_password.php` and block every other page until it is done.
- [x] Logout destroys the session. The Sign out link is a plain POST form button in this step.

**Change password (`auth/change_password.php`)**
- [x] Current password, new password, confirm. Apply the password policy and clear `must_change_password`.

**Admin setup (`setup_admin.php`)**
- [x] One-time script. It reads the username and password from `config/config.local.php`, creates the Admin (status `approved`, `must_change_password` on), and refuses to run if an Admin already exists. Delete it afterward.

**Test checklist for step A**
- [x] Register one account of each role. Each gets a unique, correctly formatted username.
- [x] A duplicate student number or email is rejected with a message next to the field.
- [x] Missing program (Class President) or missing platoon (Platoon Leader) is rejected.
- [x] A pending account cannot log in and sees the pending message.
- [x] Five wrong passwords trigger the lockout message.
- [x] The Admin logs in and is forced to change the password.
- [x] Open the `users` table in phpMyAdmin. Passwords are hashes, not plain text.

### Step B: CSS
- [x] `assets/css/style.css` with the color variables from `UI_DESIGN.md` section 5.
- [x] Centered card layout for the log in, registration, and change password pages.
- [x] Form styles: labels, inputs, focus ring, inline error text, buttons with hover.
- [x] Banner styles for the status messages (success, error, warning, info) with icons.

### Step C: JavaScript
- [x] `assets/js/register.js`: show or hide the Program and Company/Platoon fields depending on the chosen role.
- [x] Optionally upgrade the grouped dropdown into two dependent dropdowns (Company, then Platoon) using `api/companies_platoons.php`, as in `FEATURE.md` section 2.2.
- [x] Show and hide password toggle (optional).

---

## Stage 2: Admin Account and Dashboard

### Step A: HTML and PHP

**Dashboard (`admin/dashboard.php`)**
- [x] Shell with sidebar: Dashboard, Registration Requests, Users (other items shown as "Coming soon").
- [x] Three plain summary numbers: pending requests, approved users, total users.

**Registration requests (`admin/requests.php`)**
- [x] Table of pending requests: username, full name, student number, email, role, assignment (program, or company and platoon), date submitted.
- [x] **Approve** button on each row (POST form with CSRF token).
- [x] **Reject** form on each row: a reason text box (required) and a Reject button (POST).
- [x] Rules enforced on the server when approving:
  - Maximum 2 active Battalion S1 and 1 active Brigade S1. If the limit is reached, show an error and do not approve.
  - Show a warning next to the row if the platoon already has an active Platoon Leader, or the program already has an active Class President. The Admin can still approve.
- [x] On approval: set status `approved`, save `reviewed_by` and `reviewed_at`, write the "email" containing the username to `logs/mail.log`, and show the username in a flash message.
- [x] On rejection: set status `rejected`, save the reason, and write an "email" to `logs/mail.log`.
- [x] Write each approval and rejection to `audit_log`.
- [x] A **Rejected** tab or list so the Admin can see past decisions.

**Users (`admin/users.php`)**
- [x] Table of all users with role and status, and a search box (plain GET form).
- [x] Actions as POST forms: **Deactivate** (with reason), **Reactivate**, and **Reset password** (shows a temporary password once, sets `must_change_password`).
- [x] The Admin cannot deactivate their own account.

**Test checklist for step A**
- [x] Approve a Platoon Leader, then log in as that user and reach the dashboard.
- [x] Reject a registration with a reason, then confirm the reason shows on the log-in page.
- [x] Try to approve a third Battalion S1 and confirm it is blocked.
- [x] A non-Admin who opens `/UnitSync/admin/requests.php` directly is redirected.
- [x] A deactivated user cannot log in. Reactivating restores access.
- [x] After a password reset, the user must change the password at the next log in.

### Step B: CSS
- [x] Dashboard shell: fixed top bar, left navigation with the active item highlighted, scrolling content area.
- [x] Summary cards, tables (hover rows), status badges (pending, approved, rejected, deactivated), buttons.
- [x] Mobile layout for the shell (the drawer's look, without the toggle behavior yet).

### Step C: JavaScript
- [x] `assets/js/ui.js`: `showToast()` and `showConfirm()`.
- [x] Approve and Deactivate open a confirmation modal. Reject opens a modal with the reason text area.
- [x] Flash messages appear as toasts.
- [x] Mobile menu toggle for the navigation drawer, and the Sign out confirmation modal.

---

## Stage 3: Platoon Leader Dashboard (placeholder)

### Step A: HTML and PHP (`leader/dashboard.php`)
- [ ] Guarded by `require_role('platoon_leader')`.
- [ ] Welcome card: full name, role, username, and the assigned company and platoon.
- [ ] Sidebar: Dashboard, Add Cadet, View Cadets, Take Attendance. The last three show "Coming soon" and do not link to pages yet.

### Steps B and C
- [ ] Reuse the shell styles and the shared `ui.js` (no new CSS or JS beyond a "Coming soon" badge).

**Test:** a Platoon Leader reaches only this dashboard, and opening any `/admin/` page redirects away.

---

## Stage 4: Class President Dashboard (placeholder)

### Step A: HTML and PHP (`president/dashboard.php`)
- [ ] Guarded by `require_role('class_president')`.
- [ ] Welcome card: full name, role, username, and the assigned program.
- [ ] A short note: "Your section's attendance will appear here."

**Test:** a Class President reaches only this dashboard.

---

## Stage 5: Battalion S1 and Brigade S1 Dashboards (placeholder)

### Step A: HTML and PHP (`s1/dashboard.php`)
- [ ] Guarded by `require_role('battalion_s1', 'brigade_s1')`. One page shared by both roles, showing the role name in the welcome card.
- [ ] Sidebar: Dashboard, Cadet Roster, Review Attendance, View Attendance. The last three show "Coming soon".

**Test:** both S1 roles log in and reach the same dashboard, and no other role can open it.

---

## 4. Definition of Done for the MVP

- [ ] All five roles can register, be approved or rejected, and log in.
- [ ] Rejected and deactivated users see the reason on the log-in page.
- [ ] Every protected page checks the role on the server.
- [ ] No `alert()`, `confirm()`, or `prompt()` anywhere (search the project to confirm).
- [ ] Every query uses prepared statements, and every printed value is escaped.
- [ ] Every state-changing form has a CSRF token.
- [ ] The pages work at phone width, and the registration and approval flow still works with JavaScript turned off.
- [ ] `sql/schema.sql` and `sql/seed.sql` recreate the tables from scratch.
- [ ] Everything is committed to git.

---

## 5. How to Work Through Each Stage with the AI

1. Start with: "Read RULES.md, FEATURE.md, UI_DESIGN.md, and IMPLEMENTATION_PLAN.md. We are on Stage N, Step A (HTML and PHP only, no CSS or JS). List the files you will create, then build them."
2. Test with the checklist. Paste any error message back with the file name.
3. When step A works, ask for step B (CSS), then step C (JS), one at a time.
4. After each stage, ask: "Review this for SQL injection, XSS, missing role checks, and anything that breaks FEATURE.md."
5. Commit to git, then move to the next stage.

---

## 6. Next Phase (after the MVP)

1. Cadet encoding: Platoon Leader adds, edits, and removes cadets (Unassigned)
2. Cadet roster for S1 (filters, edit, assign), and the Class President's cadet list
3. Terms and training sessions (Admin)
4. Take attendance and submit (Platoon Leader)
5. S1 attendance review and approval (one Battalion S1 plus the Brigade S1)
6. View attendance and attendance percentage
7. Notifications, CSV export, bulk import, audit log viewer, forgot password, real SMTP email