# Class President Knowledge Base - UnitSync

## Scope & Access Level
- **Scope:** You are assigned to a specific Academic Program (e.g., BSCS, BSIT, BSCE).
- **Access Level:** Strictly **read-only**. Class Presidents do not encode attendance, modify cadet rosters, or approve submissions.
- Your primary responsibility is monitoring the attendance progress and compliance of cadets enrolled under your program.

## Navigation & Pages
1. **Dashboard** (`/president/dashboard.php`):
   - Displays summary statistics for your academic program.
   - Total cadets enrolled in your program.
   - Program-wide overall attendance percentage.
   - Count of cadets identified as "At Risk" (below threshold).

2. **View Attendance** (`/president/view_attendance.php`):
   - Detailed attendance matrix for cadets in your program.
   - Displays each cadet's Name, Company, Platoon, Present count, Late count, Excused count, Absent count, and calculated Attendance Percentage.
   - Visual badges identify cadets currently below the 80% passing threshold.

## Frequently Asked Questions (FAQ)

### Why doesn't recent attendance show up on my page?
- Only attendance submissions that have completed the full approval cycle (approved by **both** Battalion S1 and Brigade S1) are recorded as official.
- Sessions that are still in Draft, Submitted, or Returned status will not appear in the calculations until Brigade S1 gives final approval.

### How is the Attendance Percentage calculated?
- Formula:
  $$\text{Attendance \%} = \frac{\text{Present} + \text{Late}}{\text{Approved Sessions Held} - \text{Excused}} \times 100$$
- If a cadet was Excused (E), that session is removed from the total sessions held (the denominator), meaning the cadet is not penalized.
- Late (L) counts as attended in the numerator.
- Absent (A) counts against the percentage.

### What does "At Risk" mean?
- Any cadet whose overall attendance percentage is below the university/command threshold (default 80%) is tagged as **At Risk**.
- Class Presidents can use this report to remind fellow students about upcoming attendance recovery or mandatory sessions.

### Can I change a cadet's attendance or add an excuse?
- No. Class Presidents have view-only access.
- If a cadet believes an attendance mark is incorrect or submitted an excuse slip, please direct them to contact their **Platoon Leader** or **S1 Officer**.
