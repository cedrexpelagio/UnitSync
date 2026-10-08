# UnitSync: S1 View Attendance Plan

**Goal:** Battalion S1 and Brigade S1 can view fully approved attendance of all cadets (read-only), filter it, see totals and percentages, and export CSV.

Reference files: `FEATURE.md` (3.4, 3.5, 4), `UI_DESIGN.md`, `RULES.md`, `PL_IMPLEMENTATION_PLAN.md`.

**Starting point:** PL-1 to PL-8 are done (leaders can submit attendance, S1 can approve it in `s1/review.php`, and `attendance_percentage()` and the `settings` threshold exist).

---

## 1. Rules and Decisions

| Topic | Rule |
|---|---|
| What is shown | Only records whose submission has `state = 'approved'`, in the active term. Draft, Submitted, Returned, and partly approved never show. |
| Link to approval | Add `attendance_records.submission_id` (FK to `attendance_submissions`). Needed because approval belongs to a platoon's submission, and removed cadets must keep their approved records. |
| Session columns | A session appears if at least one platoon in the current filter has it approved. A cadet's cell stays blank if their platoon is not approved. Cancelled sessions never show. |
| Percentage | Call `attendance_percentage($p, $l, $e, $p+$a+$l+$e)` with the cadet's own approved records, so it equals `(P+L) ÷ (P+A+L)`. Cadets added late are not penalized. The Session filter never changes it. |
| Unassigned cadets | Shown with "Unassigned" and keep their approved records. |
| Statuses | All cadet statuses shown, with a Status filter (default All). |
| Access | `battalion_s1` and `brigade_s1` only. Same data for both. |

**Not included:** PDF export, S1 override, audit log viewer, past terms.

---

## 2. Stages

| Stage | Goal |
|---|---|
| 1 | Link records to submissions |
| 2 | View Attendance page (filters, totals, percentage) |
| 3 | CSV export |

Each stage follows A (HTML and PHP), B (CSS), C (JS). Stop after each step to test. Commit after each stage.

---

## Stage 1: Link Records to Submissions

**A. SQL and PHP**
- [x] Migration (also merged into `sql/schema.sql`): add `attendance_records.submission_id` with FK, backfill existing rows, and add indexes on `attendance_submissions (session_id, state)` and `enrollments (term_id, platoon_id)`.
- [x] Update PL-5 (`leader/attendance.php`, `api/attendance_save.php`): create the draft submission first, then save each record with its `submission_id` in the same transaction.

**Test**
- [x] New records have a `submission_id`, and a removed cadet's records keep theirs.
- [x] `schema.sql` rebuilds from an empty database.

---

## Stage 2: View Attendance Page

**A. HTML and PHP**
- [x] `includes/attendance_queries.php` with: approved sessions, filtered cadets (LEFT JOIN so Unassigned appears, with pagination), one query for approved records indexed `[cadet_id][session_id]`, one aggregate query for totals. Prepared statements only.
- [x] `s1/view_attendance.php`, guarded by `require_role('battalion_s1', 'brigade_s1')`. Enable "View Attendance" in the sidebar.
- [x] Filters (GET): Company, Platoon (plus "Unassigned"), Program, Session, Status. Validate values on the server.
- [x] Table: Full name (Last, First, M.I.), Program, Company, Platoon, Status, one column per approved session (P, A, L, E, or blank), Attendance %. Pagination keeps the filters.
- [x] Totals row: Present, Absent, Late, and Excused per session, for all cadets matching the filters (not only the current page).
- [x] Percentage per cadet from all their approved sessions in the term. Show "—" when null. Show "At risk" when below `settings.at_risk_threshold`. Add a plain-text formula note under the table.
- [x] Empty states: no active term, no approved sessions, no matching cadets.

**Test (use the real PL flow)**
- [x] Alpha 1st: sessions 1 and 2 fully approved. Bravo 1st: session 1 approved by Battalion S1 only. The page shows sessions 1 and 2, with letters only for Alpha 1st.
- [x] Editing an approved session (approvals cleared) removes its column. A removed cadet shows "Unassigned" and keeps their letters.
- [x] P, L, A shows 66.7%. All Excused shows "—". A cadet added late is not penalized.
- [x] Totals match a manual count, with and without filters. The Session filter does not change percentages.
- [x] Other roles are redirected. Invalid filter IDs cause no error.

**B. CSS**
- [x] Filter card, scrolling table with sticky name column, status cell colors (P success, A error, L warning, E info, letter always shown), totals footer, "At risk" badge with text, info icon for the formula.

**C. JS** (`assets/js/view_attendance.js`)
- [x] Platoon list follows the selected Company, filters auto-submit on change (Apply stays as fallback), formula popover on the info icon, flash messages as toasts.

---

## Stage 3: CSV Export

**A. HTML and PHP (`s1/export_attendance_csv.php`)**
- [x] Same role guard and same GET filters, reusing `includes/attendance_queries.php`. No pagination.
- [x] Columns: names, Program, Company, Platoon, Status, one column per approved session, Present, Absent, Late, Excused, Attendance %, At risk.
- [x] UTF-8 with BOM, filename `attendance_YYYY-MM-DD.csv`. Prefix values starting with `=`, `+`, `-`, or `@` with a single quote.
- [x] Write one `audit_log` entry per export (filters and row count).
- [x] "Export CSV" link on the page keeps the current filters.

**Test**
- [x] The file opens in Excel and matches the filtered page. Non-S1 roles are blocked. A name starting with `=` is exported as text. `audit_log` records each export.

**B and C:** Style the link as a secondary button. Optional toast when the download starts.

---

## 3. Definition of Done

- [x] Only approved attendance is shown, for all cadets, to S1 only.
- [x] Totals, percentages, and "At risk" match manual counts.
- [x] CSV matches the filtered view and is logged.
- [x] Prepared statements, escaped output, CSRF where needed, no `alert()`, `confirm()`, or `prompt()`.
- [x] Works at phone width, and filtering works with JavaScript off.
- [x] `schema.sql` and `sql/migrations/` rebuild from scratch. Everything is committed.


---

## 4. Working with the AI

Start each stage with: "Read RULES.md, FEATURE.md, UI_DESIGN.md, PL_IMPLEMENTATION_PLAN.md, and S1_VIEW_ATTENDANCE_PLAN.md. We are on Stage N, Step A (HTML and PHP only). List the files you will create or change, then build them." After A works, ask for B, then C. After each stage, ask for a review of SQL injection, XSS, role checks, and conflicts with `FEATURE.md`.