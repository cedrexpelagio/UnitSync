# Platoon Leader Knowledge Base - UnitSync

## Scope & Authorization
- **Scope:** You are assigned to a specific Company and Platoon.
- You can only view and manage cadets and attendance belonging to your own platoon.
- You cannot view other platoons' records or perform S1-level roster reassignments.

## Navigation & Pages
1. **Dashboard** (`/leader/dashboard.php`):
   - Shows active term information and assigned platoon details.
   - Summarizes total cadets, overall platoon attendance percentage, and count of at-risk cadets.
   - Highlights any returned attendance submissions requiring your attention.

2. **Add Cadet** (`/leader/add_cadet.php`):
   - Form to add an individual cadet into your platoon.
   - Auto-generates a Cadet Code (`CDT-YYYY-XXXX`).
   - Requires First Name, Last Name, Student Number, Academic Program, Gender, Contact Number, and Status.

3. **View Cadets** (`/leader/cadets.php`):
   - Roster of all cadets in your platoon.
   - Allows searching and filtering by status.
   - Click "Edit" (`cadet_edit.php`) to update personal or academic details.
   - Note: Platoon Leaders cannot delete cadets or transfer them to another platoon.

4. **Take Attendance** (`/leader/attendance.php`):
   - The primary attendance workspace for training sessions.
   - Select the training session from the session dropdown.
   - Mark each cadet as Present (P), Absent (A), Late (L), or Excused (E).

## Step-by-Step Attendance Workflow
1. Navigate to **Take Attendance** (`/leader/attendance.php`).
2. Choose the relevant training session from the dropdown.
3. For each cadet, select their status:
   - For **Late (L)**: Input the minutes late (must be a positive integer).
   - For **Excused (E)**: Enter the specific excuse reason.
4. Auto-save will regularly save your progress as a draft. You can also manually click **Save Draft**.
5. Once **all cadets** in your platoon have been marked, click **Submit to S1**.
6. Your submission will enter the queue for Battalion S1 and Brigade S1 review.

## Frequently Asked Questions (FAQ)

### Why is the "Submit to S1" button disabled?
- **Unmarked Cadets:** Every single active cadet in your platoon must have an assigned status (P, A, L, or E). If even one cadet remains unmarked, the submit button is disabled.
- **Cancelled Session:** You cannot submit attendance for a cancelled training session.
- **Future Session:** Attendance cannot be submitted before the training session date.
- **Already Approved:** If the session is already fully approved, it is locked against new submissions.

### I edited attendance after submitting. What happened?
- Any edit made to a submitted attendance sheet immediately **clears prior S1 approvals** and reverts the submission back to Draft or unapproved state. You must re-submit it to S1 after making your adjustments.

### What does "Returned" status mean, and what should I do?
- If Battalion S1 or Brigade S1 finds a discrepancy or requires clarification, they will return the submission with specific **Remarks**.
- Check the return remarks displayed on your dashboard or attendance page.
- Make the requested corrections and click **Re-submit to S1**.

### How do I remove or reassign a cadet who moved to another platoon?
- Platoon Leaders do not have permission to remove cadets or reassign them to other companies or platoons.
- Please contact your **Battalion S1 or Brigade S1** with the cadet's details so they can perform the reassignment in the Master Cadet Roster.
