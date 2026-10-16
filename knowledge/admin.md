# Admin Knowledge Base - UnitSync

## Scope & Responsibilities
- **Scope:** Full administrative access over system configuration, user accounts, academic terms, training sessions, and audit logging.
- Admins do not record platoon attendance directly; their role is system governance and configuration.

## Navigation & Pages
1. **Dashboard** (`/admin/dashboard.php`):
   - Executive overview of system health and activity.
   - Pending registration request counts.
   - Currently active term indicator.
   - Total active users, cadets, and sessions.

2. **Registration Requests** (`/admin/requests.php`):
   - Review incoming self-registration requests from Platoon Leaders, Class Presidents, Battalion S1, and Brigade S1 candidates.
   - Approve account: Generates active user credentials and sends simulated welcome mail to `logs/mail.log`.
   - Reject account: Provide a mandatory rejection reason for the applicant.

3. **Users** (`/admin/users.php`):
   - Master list of all user accounts across all roles.
   - Deactivate user with mandatory reason or reactivate inactive accounts.
   - Reset user password (forces password change upon next login).

4. **Training Sessions** (`/admin/sessions.php`):
   - Manage training dates and syllabus topics for the active term.
   - Add new training sessions with date, time, title, and session type.
   - Cancel sessions (e.g., due to weather disturbances or academic holidays). Cancelled sessions do not count toward cadet attendance statistics.

5. **Terms & Semesters** (`/admin/terms.php`):
   - Create and organize academic terms (e.g., 1st Semester 2024-2025).
   - Set the Active Term. Switching the active term adjusts the scope of all attendance tracking across the platform.

6. **Audit Log** (`/admin/audit_log.php`):
   - Immutable log of critical system operations: logins, failed attempts, registration approvals, role assignments, and session changes.

## Frequently Asked Questions (FAQ)

### Why can't I approve an S1 registration request?
- **S1 Account Limits:** UnitSync enforces strict command quotas:
  - Maximum of **2 Battalion S1** accounts.
  - Maximum of **1 Brigade S1** account.
- If the quota is already filled by existing active accounts, new S1 registrations cannot be approved until an existing account is deactivated or reassigned.

### How do I switch the active academic term?
- Navigate to **Terms & Semesters** (`/admin/terms.php`).
- Locate the desired term in the list.
- Click **Set Active**. Only one term can be active at a time; the previous term is automatically set to inactive.

### What happens when a training session is cancelled?
- A cancelled session is flagged in the database (`status = 'cancelled'`).
- Platoon Leaders cannot take or submit attendance for cancelled sessions.
- Cancelled sessions are automatically excluded from the attendance percentage formula denominator so cadets are not affected.
