# UnitSync: Platoon Leader Implementation Plan

**Goal:** A Platoon Leader can add and manage cadets in their own platoon, take attendance per training session, submit it, and track its status (Draft, Submitted, Returned, Approved).

Reference files: `FEATURE.md` (behavior, section 3.3, 3.5, 4), `UI_DESIGN.md` (sections 2 to 5), `RULES.md` (technical rules), `MVP_IMPLEMENTATION_PLAN.md` (what already exists).

Starting point: the MVP is done. The Platoon Leader can register, be approved, log in, and see a placeholder dashboard (`leader/dashboard.php`) with "Coming soon" items.

---

## 0. Open Points to Settle Before Coding

`RULES.md` says to ask when files conflict or are unclear. These need your answer first:

| # | Point | Proposed answer |
|---|---|---|
| 1 | `RULES.md` refers to `IMPLEMENTATION_PLAN.md`, but the file is named `MVP_IMPLEMENTATION_PLAN.md`. | Rename the reference in `RULES.md` or keep both names. Also update the "Current MVP scope" section of `RULES.md`, because it forbids cadet and attendance work. |
| 2 | Attendance needs terms and training sessions (Admin), and a session cannot become "Approved" without S1 approval. The Platoon Leader cannot be tested end to end without them. | Build a small Admin **Terms and Training Sessions** stage (Stage PL-1) and a **minimal S1 approve/return** stage (Stage PL-7) as test support. |
| 3 | `FEATURE.md` lists "unassigned" as a cadet status, but section 5 says an empty enrollment means Unassigned. | Treat Unassigned as derived from an enrollment with NULL company and platoon. Cadet status keeps: active, dropped, transferred, graduated. |
| 4 | Gender is a controlled list but no values are given. | Male, Female, stored as an ENUM. |
| 5 | Section 3.3 says attendance is submitted per session, but sessions of an unassigned (removed) cadet are kept. | A removed cadet stops appearing in future attendance sheets. Past records stay. |
| 6 | Bulk CSV import, notifications, and percentage are Phase 2 in `FEATURE.md`. | Put them last (Stages PL-8 to PL-10), after the core flow works. |
| 7 | Excuse documents are Phase 3. | Excuse reason (text) only for now. Leave the `document_path` column NULL. |

---

## 1. Scope

| Feature | Stage | Phase in FEATURE.md |
|---|---|---|
| Terms and training sessions (Admin, needed by attendance) | PL-1 | MVP |
| Cadet tables and enrollment | PL-2 | MVP |
| Add cadet | PL-3 | MVP |
| View and edit cadets, remove from platoon | PL-4 | MVP |
| Take attendance (draft, mark, auto-save) | PL-5 | MVP |
| Submit, edit after submission, Returned handling | PL-6 | MVP |
| Minimal S1 approve and return (test support) | PL-7 | MVP |
| Dashboard summary and attendance percentage | PL-8 | Phase 2 |
| Notifications for the Platoon Leader | PL-9 | Phase 2 |
| CSV bulk import | PL-10 | Phase 2 |

**Out of scope here:** full S1 roster and filters, S1 View Attendance and exports, S1 override, audit log viewer, excuse documents, PDF export.

---

## 2. Stages at a Glance

| Stage | Goal | Depends on |
|---|---|---|
| PL-1 | Admin terms and training sessions | MVP |
| PL-2 | Database migrations for cadets and attendance | PL-1 |
| PL-3 | Add cadet | PL-2 |
| PL-4 | View, edit, and remove cadets | PL-3 |
| PL-5 | Take attendance | PL-4, PL-1 |
| PL-6 | Submit and edit after submission | PL-5 |
| PL-7 | Minimal S1 approval (to test PL-6) | PL-6 |
| PL-8 | Dashboard summary and percentage | PL-7 |
| PL-9 | Notifications | PL-7 |
| PL-10 | CSV bulk import | PL-3 |

Every stage follows **A: HTML and PHP, then B: CSS, then C: JS**, and stops after each step for you to test. Commit to git after every stage.

---

## 3. Conventions for All Stages

- New tables go in `sql/migrations/` (for example `001_cadets_terms_attendance.sql`) and are also merged into `sql/schema.sql` so it can rebuild from scratch.
- Every page starts with `require_login()` and `require_role('platoon_leader')`.
- **Scope check on the server:** load the leader's platoon from `user_assignments`. Every cadet and attendance query is filtered by that `platoon_id`. A cadet ID or session ID from another platoon returns a "not found" page, never the data.
- CSRF token on every POST. Prepared statements only. Escape all output.
- Forms post to the same page, then redirect (post/redirect/get). Flash messages for success and errors.
- Soft deletes only. Cadets are never removed from the database.
- Write to `audit_log` for: cadet created, cadet edited (old and new values), cadet removed from platoon, attendance edited after submission, attendance submitted.
- Attendance status values stored as ENUM `P`, `A`, `L`, `E`. Submission states: `draft`, `submitted`, `returned`, `approved`. Cadet statuses: `active`, `dropped`, `transferred`, `graduated`.

---

## Stage PL-1: Terms and Training Sessions (Admin)

A Platoon Leader cannot take attendance until sessions exist, so this comes first.

### Step A: HTML and PHP
- [x] Tables: `terms` (id, name, start_date, end_date, is_active), `training_sessions` (id, term_id, session_date, label, status: scheduled, held, cancelled).
- [x] `admin/terms.php`: create a term, activate one (only one active at a time, enforced in a transaction).
- [x] `admin/sessions.php`: for the active term, add a session (date, label), edit it, and set its status. Default 15 sessions, with a "Generate N weekly sessions from a start date" form (N configurable).
- [x] Add "Training Sessions" to the Admin sidebar (currently "Coming soon").
- [x] Audit log entries for term and session changes.

**Test:** one active term at a time. Sessions appear in date order. A cancelled session is shown as cancelled.

### Step B: CSS
- [x] Reuse table, badge, and form styles. Add badges for scheduled, held, cancelled.

### Step C: JS
- [x] Confirmation modal for activating a term and cancelling a session (`showConfirm()`).

---

## Stage PL-2: Database for Cadets and Attendance

### Step A: SQL only (`sql/migrations/003_...sql`, merged into `schema.sql`)

| Table | Key columns |
|---|---|
| `cadets` | id, cadet_code (unique, `CDT-2026-0001`), last_name, first_name, middle_name, birthday, gender (ENUM), program_id (FK), student_number (unique), email (unique), contact_number, status (active, dropped, transferred, graduated), created_by, created_at, updated_at |
| `enrollments` | id, cadet_id, term_id, company_id (nullable), platoon_id (nullable), UNIQUE (cadet_id, term_id). NULL company and platoon means Unassigned. |
| `attendance_records` | id, cadet_id, session_id, status (P, A, L, E), minutes_late (nullable), excuse_reason (nullable), document_path (nullable, unused for now), marked_by, updated_at, UNIQUE (cadet_id, session_id) |
| `attendance_submissions` | id, platoon_id, session_id, state (draft, submitted, returned, approved), submitted_by, submitted_at, battalion_approved_by, battalion_approved_at, brigade_approved_by, brigade_approved_at, remarks, updated_at, UNIQUE (platoon_id, session_id) |

- [x] InnoDB, foreign keys, utf8mb4, unique constraints as listed.
- [x] Seed: nothing new needed (programs, companies, and platoons already exist).
- [x] Helper `next_cadet_code()` in `includes/helpers.php`, using a transaction or a locked sequence query so two cadets never get the same code.

**Done when:** the migration runs on top of the MVP database without errors, and `schema.sql` rebuilds everything from an empty database.

---

## Stage PL-3: Add Cadet

### Step A: HTML and PHP (`leader/add_cadet.php`)
- [ ] Guard, and load the leader's company, platoon, and the active term. If there is no active term, show a message and disable the form.
- [ ] Fields: last name, first name, middle name, birthday, program (dropdown), gender (dropdown), student number, email, contact number.
- [ ] Company and platoon are shown read-only from the leader's assignment and are **not** accepted from the form.
- [ ] Server validation with a message next to each field: required fields, valid email, valid date (not in the future), contact number format, unique student number and email (in `cadets`, with a clear message naming which one is duplicated).
- [ ] On success: insert the cadet (status `active`), generate the cadet code, create the `enrollments` row for the active term with the leader's company and platoon, write `audit_log`, flash the new cadet code, redirect.
- [ ] Keep entered values after an error.
- [ ] Sidebar: change "Add Cadet" from "Coming soon" to a link.

**Test checklist**
- [ ] Add a cadet and see a code like `CDT-2026-0001`.
- [ ] A second cadet gets the next number.
- [ ] A duplicate student number and a duplicate email are each rejected next to the field.
- [ ] Posting a different `platoon_id` in the form has no effect.
- [ ] Another role opening the page is redirected.

### Step B: CSS
- [ ] Two-column form on desktop, one column on phone. Read-only assignment fields styled as info.

### Step C: JS
- [ ] Inline field checks as a convenience only. Toast on success. Server stays the source of truth.

---

## Stage PL-4: View, Edit, and Remove Cadets

### Step A: HTML and PHP

**List (`leader/cadets.php`)**
- [ ] Table of cadets enrolled in the leader's platoon for the active term: cadet code, full name (Last, First, M.I.), program, gender, student number, status.
- [ ] Search box and pagination (plain GET). Default page size 25.
- [ ] Row actions: Edit, Remove from platoon.
- [ ] Empty state message when there are no cadets.

**Edit (`leader/cadet_edit.php?id=`)**
- [ ] Verify the cadet is in the leader's platoon first, otherwise a not-found page.
- [ ] Editable: all personal fields from PL-3, and cadet status.
- [ ] **Not editable:** cadet code, company, platoon.
- [ ] Same validation as PL-3, with uniqueness that ignores the cadet's own row.
- [ ] Write old and new values to `audit_log`.

**Remove from platoon**
- [ ] POST form with CSRF. Sets the enrollment's `company_id` and `platoon_id` to NULL (cadet becomes Unassigned). The cadet leaves the leader's list and future attendance sheets. Past attendance records stay.
- [ ] Write `audit_log`.
- [ ] A cadet who is in a submitted but not approved session: the removal is allowed, and the cadet is dropped from that session's sheet. Flag this for your decision (open point 5).

**Test checklist**
- [ ] A leader sees only their own platoon's cadets. Guessing another platoon's cadet ID shows not found.
- [ ] Editing saves, and the change appears in `audit_log`.
- [ ] A removed cadet disappears from the list, and the `cadets` row still exists with NULL platoon in `enrollments`.
- [ ] The leader cannot move a cadet to another platoon by any URL or form.

### Step B: CSS
- [ ] Table with hover rows, status badges, action buttons, pagination, empty state. Table scrolls horizontally on phones.

### Step C: JS
- [ ] Remove opens a confirmation modal with the consequence explained ("The cadet becomes Unassigned. Only S1 can reassign."). Toasts for results.

---

## Stage PL-5: Take Attendance

### Step A: HTML and PHP (`leader/attendance.php`)

**Session selector**
- [ ] List sessions of the active term with a badge from `attendance_submissions` for this platoon: Not started, Draft, Submitted, Returned, Approved. Cancelled sessions are shown disabled.
- [ ] Future sessions are shown but locked (date greater than today in Asia/Manila). Only current or past sessions can be marked.
- [ ] Selecting a session (GET `?session=ID`) shows the sheet.

**Sheet**
- [ ] Rows: Last name, First name, M.I., Program, for each active cadet in the platoon.
- [ ] Per cadet a status radio group: P, A, L, E. All cadets start blank (Not yet marked), never defaulted to Absent.
- [ ] Late: optional minutes field. Excused: required reason text.
- [ ] **Mark all Present** as a plain submit button (sets every cadet to P, keeps existing L and E rows only if the leader chooses "only unmarked"; proposed default is to fill only unmarked cadets so work is not lost).
- [ ] **Save draft** button (POST). Saves marks, creates the submission row with state `draft` if none exists.
- [ ] Locked (read-only) when the session is `approved`. Editable when `draft`, `submitted`, or `returned`.
- [ ] Show the S1's remarks in a notice when the state is `returned`.

**Rules enforced on the server**
- [ ] The session belongs to the active term and is not cancelled or in the future.
- [ ] Every cadet ID posted belongs to the leader's platoon and is active.
- [ ] Status is one of P, A, L, E. Minutes late is a non-negative integer. E requires a reason.
- [ ] Use a transaction for the save (upsert on `cadet_id, session_id`).

**Test checklist**
- [ ] A future session cannot be marked, even by posting directly.
- [ ] Unmarked cadets stay blank after saving.
- [ ] E without a reason shows an error next to the row.
- [ ] A cadet from another platoon posted in the form is ignored or rejected.
- [ ] Reloading shows the saved draft.

### Step B: CSS
- [ ] Sheet table with sticky name column and horizontal scroll on phones. Large tap targets for the P, A, L, E buttons. Colors: P success, A error, L warning, E info, and the letter always visible. Status badges for session states.

### Step C: JS
- [ ] **Auto-save** of draft entries (debounced fetch to `api/attendance_save.php`, JSON in the standard format, CSRF checked, same server rules as the page). A small "Saved" or "Saving..." indicator.
- [ ] Show or hide the minutes and reason fields depending on the chosen status.
- [ ] Mark all Present without a page reload. A counter of marked and unmarked cadets.
- [ ] The page still works with JS off through the Save draft button.

---

## Stage PL-6: Submit and Edit After Submission

### Step A: HTML and PHP
- [ ] **Submit** button per session column or sheet (POST) with these checks:
  - Every active cadet in the platoon is marked. If not, show how many are missing and list them.
  - Session is current or past, and not cancelled.
  - State is `draft` or `returned`.
- [ ] On success: state becomes `submitted`, set `submitted_by` and `submitted_at`, clear any approval fields and remarks, write `audit_log`.
- [ ] **Edit after submission:** while the state is `submitted` (not `approved`), the sheet stays editable. Saving a change sets the state back to `submitted`, **clears both approvals**, and writes `audit_log` with old and new values. Show a notice before saving: "Editing will clear approvals already given."
- [ ] When the state is `returned`, editing and resubmitting is the normal path. All approvals are already cleared.
- [ ] When `approved`, everything is read-only with a "Locked" notice.
- [ ] Late-added cadets: if a cadet is added to the platoon after a session was submitted, the session shows them as unmarked and the leader must mark them and resubmit. Flag for your confirmation.

**Test checklist**
- [ ] Submit is blocked until all cadets are marked.
- [ ] After submit, the badge shows Submitted.
- [ ] Editing a submitted session clears approvals (verify in phpMyAdmin once PL-7 exists).
- [ ] An approved session cannot be changed, even through a direct POST.
- [ ] Submit twice quickly creates one submission only.

### Step B: CSS
- [ ] Submit button states (enabled, disabled with reason), notice banners for Returned, Locked, and Editing-clears-approvals.

### Step C: JS
- [ ] Submit opens a confirmation modal ("Send to Battalion S1 and Brigade S1"). Editing a submitted session shows a modal warning once per page load. Toasts for results.

---

## Stage PL-7: Minimal S1 Approve and Return (test support)

Full S1 features are a separate plan. This is only the smallest slice needed to finish the Platoon Leader flow.

### Step A: HTML and PHP (`s1/review.php`)
- [ ] Guard: `battalion_s1` and `brigade_s1`.
- [ ] Queue of `submitted` sessions: platoon, company, session date, counts of P, A, L, E.
- [ ] Detail view (read-only) with each cadet's status.
- [ ] **Approve:** a Battalion S1 sets the battalion approval (only if none yet, so the second Battalion S1 sees "Already approved by Battalion S1"). The Brigade S1 sets the brigade approval. Either can come first. When both exist, state becomes `approved`.
- [ ] **Return** (reason required): state `returned`, remarks saved, both approvals cleared.
- [ ] Audit log on approve and return. Enable the "Review Attendance" sidebar item for S1.

**Test checklist**
- [ ] Battalion approves, then Brigade approves: state is Approved and the leader's sheet is locked.
- [ ] Brigade approves first, then Battalion: same result.
- [ ] The second Battalion S1 cannot approve again.
- [ ] Return shows the remarks to the leader, and the leader can fix and resubmit.
- [ ] A leader edit after one approval clears it.

### Steps B and C
- [ ] Reuse tables and badges. Approve and Return open modals (Return has a remarks text area).

---

## Stage PL-8: Dashboard Summary and Attendance Percentage (Phase 2)

### Step A: HTML and PHP
- [ ] `leader/dashboard.php`: keep the welcome card and add three summary numbers: cadets in the platoon, sessions waiting to be submitted (past or today, state none or draft), sessions returned by S1.
- [ ] Add a percentage column to the cadet list or a platoon attendance summary:
  `(Present + Late) ÷ (Approved sessions held − Excused) × 100`
  Only fully approved sessions count. Cancelled sessions never count. A zero denominator shows "—".
- [ ] Put the formula in one function (`attendance_percentage()` in `includes/helpers.php`) so Class President and S1 views reuse it.
- [ ] Settings table and the "at risk" threshold (Admin setting, default 80). The leader's view highlights cadets below it.
- [ ] Sidebar items all linked, no more "Coming soon" for the leader.

### Steps B and C
- [ ] Summary cards, at-risk highlight (with an icon or label, not only color), and an info icon explaining the formula.

---

## Stage PL-9: Notifications for the Platoon Leader (Phase 2)

### Step A: HTML and PHP
- [ ] `notifications` table (user_id, message, link, is_read, created_at).
- [ ] Create notifications for the leader when a session is approved or returned, and for S1 when a session is submitted or edited. Keep one `notify()` helper.
- [ ] Unread count in the top bar and a plain list page `includes/notifications.php` with Mark all as read.

### Steps B and C
- [ ] Bell dropdown with the latest items (newest first) and Mark all as read through `api/notifications.php`.

---

## Stage PL-10: CSV Bulk Import (Phase 2)

### Step A: HTML and PHP (`leader/import.php`)
- [ ] Downloadable CSV template (plain PHP).
- [ ] Upload (CSV only, size limit), then a **preview** page that shows valid rows and an error list (row number and reason) before anything is saved.
- [ ] Validation is the same as PL-3, plus duplicates inside the file. Company and platoon always come from the leader, never from the CSV.
- [ ] Confirm step saves all valid rows in one transaction, with generated cadet codes. Store the previewed rows in the session so the file is not trusted twice.
- [ ] Audit log entry for the import with a count.

### Steps B and C
- [ ] Preview table styles with error highlighting. Confirmation modal before saving.

---

## 4. Definition of Done for the Platoon Leader Features

- [ ] A leader can add, view, edit, and remove cadets, only in their own platoon.
- [ ] Removed cadets become Unassigned, and their past attendance stays.
- [ ] A leader can mark, save, submit, and edit attendance per session, and cannot mark future sessions.
- [ ] Editing a submitted session clears approvals. Approved sessions are locked.
- [ ] Returned sessions show the S1's remarks and can be resubmitted.
- [ ] Every page and `api/` endpoint checks role and platoon on the server. Guessing IDs from another platoon never shows data.
- [ ] No `alert()`, `confirm()`, or `prompt()`. All queries are prepared and all output is escaped.
- [ ] Every state-changing form and AJAX call has a CSRF token.
- [ ] The pages work at phone width, and every core flow works with JavaScript off.
- [ ] `sql/schema.sql` rebuilds everything, and migrations are in `sql/migrations/`.
- [ ] Everything is committed to git.

---

## 5. How to Work Through Each Stage with the AI

1. Start with: "Read RULES.md, FEATURE.md, UI_DESIGN.md, MVP_IMPLEMENTATION_PLAN.md, and PLATOON_LEADER_IMPLEMENTATION_PLAN.md. We are on Stage PL-N, Step A (HTML and PHP only, no CSS or JS). List the files you will create, then build them."
2. Test with the stage checklist and paste any error back with the file name.
3. When step A works, ask for step B (CSS), then step C (JS), one at a time.
4. After each stage, ask: "Review this for SQL injection, XSS, missing role or platoon checks, and anything that breaks FEATURE.md."
5. Commit to git, then move on.