<?php
// UnitSync: i-Cadet Knowledge Base & Rule Engine
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../helpers.php';

/**
 * Returns combined markdown knowledge for a specific role + common
 */
function ai_get_role_knowledge(string $role): string {
    $knowledgeDir = __DIR__ . '/../../knowledge';
    $commonFile = $knowledgeDir . '/common.md';
    $roleFile = '';

    switch ($role) {
        case 'platoon_leader':
            $roleFile = $knowledgeDir . '/platoon_leader.md';
            break;
        case 'battalion_s1':
        case 'brigade_s1':
            $roleFile = $knowledgeDir . '/s1.md';
            break;
        case 'class_president':
            $roleFile = $knowledgeDir . '/class_president.md';
            break;
        case 'admin':
            $roleFile = $knowledgeDir . '/admin.md';
            break;
    }

    $content = '';
    if (file_exists($commonFile)) {
        $content .= file_get_contents($commonFile) . "\n\n";
    }
    if ($roleFile && file_exists($roleFile)) {
        $content .= file_get_contents($roleFile) . "\n";
    }

    return $content;
}

/**
 * Returns contextual quick-suggestion questions based on role and page
 */
function ai_get_page_suggestions(string $role, string $page = ''): array {
    $page = basename($page);

    if ($role === 'platoon_leader') {
        if ($page === 'attendance.php') {
            return [
                "Why is the Submit button disabled?",
                "How do I mark late or excused cadets?",
                "I edited after submitting, what happened?",
                "How do I save draft attendance?"
            ];
        }
        if ($page === 'cadets.php' || $page === 'add_cadet.php') {
            return [
                "How do I remove or reassign a cadet?",
                "How do I add a new cadet?",
                "What does cadet status mean?",
                "How do I submit attendance?"
            ];
        }
        return [
            "Why is Submit disabled?",
            "I edited after submitting, what happened?",
            "How do I remove a cadet?",
            "What does At Risk mean?"
        ];
    }

    if ($role === 'battalion_s1' || $role === 'brigade_s1') {
        if ($page === 'review_attendance.php' || $page === 'review_session.php') {
            return [
                "Why can't I approve this submission?",
                "How do I return a submission with remarks?",
                "What is the difference between Battalion and Brigade S1 approval?",
                "How do I check at-risk cadets?"
            ];
        }
        if ($page === 'roster.php' || $page === 'import_cadets.php') {
            return [
                "How do I reassign an unassigned cadet?",
                "How do I import cadets via CSV?",
                "How do I import officers?",
                "Why can't I approve this?"
            ];
        }
        return [
            "Why can't I approve this submission?",
            "How do I return a submission with remarks?",
            "How do I import cadets by CSV?",
            "How do I reassign an unassigned cadet?"
        ];
    }

    if ($role === 'class_president') {
        return [
            "Why doesn't recent attendance show up?",
            "How is attendance % computed?",
            "What does At Risk mean?",
            "Can I edit attendance or add an excuse?"
        ];
    }

    if ($role === 'admin') {
        if ($page === 'requests.php') {
            return [
                "Why can't I approve this S1 registration?",
                "How do I approve or reject a user?",
                "How do I switch the active term?",
                "What happens when a session is cancelled?"
            ];
        }
        return [
            "Why can't I approve this registration?",
            "How do I switch the active term?",
            "What happens when a session is cancelled?",
            "How do I reset a user password?"
        ];
    }

    return [
        "How is attendance % computed?",
        "What are the P/A/L/E statuses?",
        "How do I change my password?"
    ];
}

/**
 * Built-in Rule Engine: matches queries against role knowledge
 * Returns structured reply, action links, and suggested next questions.
 */
function ai_rule_based_reply(string $role, string $page, string $message): array {
    $clean = mb_strtolower(trim($message));

    // Word-boundary check helper
    $hasWord = function(string $w) use ($clean): bool {
        return preg_match('/(?:\b|^)' . preg_quote($w, '/') . '(?:\b|$)/iu', $clean) === 1;
    };

    // Helper: matches if at least one word from EACH provided list is found
    $hasAll = function(array ...$groups) use ($hasWord): bool {
        foreach ($groups as $group) {
            $found = false;
            foreach ($group as $w) {
                if ($hasWord($w)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) return false;
        }
        return true;
    };

    // 1. Greetings (Exact word boundaries)
    $greetings = ['hello', 'hi', 'hey', 'magandang', 'kamusta', 'kumusta', 'greetings', 'salute', 'snappy'];
    foreach ($greetings as $g) {
        if ($hasWord($g)) {
            return [
                'reply' => "Snappy salute! I'm **i-Cadet**, your friendly AI cadet guide for UnitSync! 🫡\n\nHow can I help you today, Sir/Ma'am? You can ask me how to perform tasks, why buttons or actions might be blocked, or select from the quick topics below!",
                'actions' => [],
                'suggestions' => ai_get_page_suggestions($role, $page),
                'unanswered' => false
            ];
        }
    }

    // 2. Who are you / Identity
    if ($hasAll(['who', 'what', 'sino', 'ano'], ['you', 'ka', 'assistant', 'name']) || $hasWord('i-cadet') || $hasWord('icadet')) {
        return [
            'reply' => "I am **i-Cadet**, your friendly AI assistant in UnitSync! 🎖️\n\nI'm here to assist cadets and officers with navigating the system, understanding attendance rules and formulas, and explaining approval workflows.",
            'actions' => [],
            'suggestions' => ai_get_page_suggestions($role, $page),
            'unanswered' => false
        ];
    }

    // 3. Platoon Leader: Submit Disabled
    if ($role === 'platoon_leader' && ($hasAll(['submit', 'submitting'], ['disabled', 'grayed', 'cannot', 'cant', 'unable', 'unclickable', 'bakit', 'why']) || $hasWord('unmarked'))) {
        return [
            'reply' => "Sir/Ma'am, the **Submit to S1** button is disabled due to one of these reasons:\n\n" .
                       "1. **Unmarked Cadets (Most Common):** Every active cadet in your platoon must have a status selected (**P**, **A**, **L**, or **E**). If even a single cadet is unmarked, submission is blocked.\n" .
                       "2. **Cancelled Session:** You cannot submit attendance for sessions cancelled by the Admin.\n" .
                       "3. **Future Session:** Submissions cannot be made before the session date.\n" .
                       "4. **Already Approved:** If the session is already fully approved by S1, it is locked.\n\n" .
                       "💡 *Tip:* Check your attendance table and ensure every row has a selected status badge!",
            'actions' => [
                ['label' => 'Open Take Attendance', 'url' => BASE_URL . '/leader/attendance.php']
            ],
            'suggestions' => [
                "How do I mark late or excused cadets?",
                "I edited after submitting, what happened?",
                "How do I save draft attendance?"
            ],
            'unanswered' => false
        ];
    }

    // 4. Platoon Leader: Edited after submitting
    if ($role === 'platoon_leader' && $hasAll(['edit', 'edited', 'editing', 'nag-edit', 'na-edit'], ['after', 'submitting', 'submitted', 'submit'])) {
        return [
            'reply' => "Sir/Ma'am, when you edit an attendance sheet after submitting:\n\n" .
                       "• **Prior S1 Approvals are Cleared:** To preserve data integrity and prevent unauthorized modifications, the system automatically resets any Battalion or Brigade S1 approvals on that session.\n" .
                       "• **Action Required:** After finishing your edits, you **must click Submit to S1 again** so Battalion and Brigade S1 can review and approve your updated marks.",
            'actions' => [
                ['label' => 'Go to Take Attendance', 'url' => BASE_URL . '/leader/attendance.php']
            ],
            'suggestions' => [
                "Why is Submit disabled?",
                "What does Returned status mean?"
            ],
            'unanswered' => false
        ];
    }

    // 5. Platoon Leader: Remove / Reassign cadet
    if ($role === 'platoon_leader' && $hasAll(['remove', 'delete', 'reassign', 'transfer', 'lipat', 'tanggalin', 'move'], ['cadet', 'cadets'])) {
        return [
            'reply' => "Sir/Ma'am, Platoon Leaders **cannot remove or reassign cadets** to other platoons.\n\n" .
                       "• Only **Battalion S1 or Brigade S1** has authority to move or reassign cadets across companies and platoons in the Master Roster.\n" .
                       "• If a cadet transferred or needs reassignment, please contact your S1 Officer with the cadet's name and student number so they can update the assignment.",
            'actions' => [
                ['label' => 'View Platoon Cadets', 'url' => BASE_URL . '/leader/cadets.php']
            ],
            'suggestions' => [
                "Why is Submit disabled?",
                "How do I add a new cadet?"
            ],
            'unanswered' => false
        ];
    }

    // 6. Platoon Leader / Common: Returned Submissions & Remarks
    if ($hasWord('returned') || $hasWord('remarks') || $hasWord('binalik') || $hasAll(['return', 'reject'], ['attendance', 'submission'])) {
        return [
            'reply' => "Sir/Ma'am, if an attendance submission has a **Returned** status:\n\n" .
                       "1. S1 identified an issue or discrepancy and sent it back with specific remarks.\n" .
                       "2. Open **Take Attendance** (`/leader/attendance.php`) or check your Dashboard to view the **Return Remarks** from S1.\n" .
                       "3. Correct the marks or provide required documentation as requested.\n" .
                       "4. Click **Re-submit to S1** once corrected.",
            'actions' => [
                ['label' => 'Check Take Attendance', 'url' => BASE_URL . '/leader/attendance.php']
            ],
            'suggestions' => [
                "Why is Submit disabled?",
                "How do I mark late or excused cadets?"
            ],
            'unanswered' => false
        ];
    }

    // 7. S1: Why can't I approve this?
    if (($role === 'battalion_s1' || $role === 'brigade_s1') && $hasAll(['approve', 'approving', 'approval'], ['cant', 'cannot', 'why', 'unable', 'di', 'bakit', 'disabled'])) {
        $extra = ($role === 'battalion_s1') 
            ? "As **Battalion S1**, you can only approve if the submission is currently in `Submitted` status and has not yet been approved by Battalion S1."
            : "As **Brigade S1**, you can only give final approval **after** Battalion S1 has approved first.";

        return [
            'reply' => "Sir/Ma'am, here is why an attendance submission might not be approvable:\n\n" .
                       "• **Sequential Approval:** The workflow requires Platoon Leader Submit → Battalion S1 Approval → Brigade S1 Approval.\n" .
                       "• {$extra}\n" .
                       "• **Already Locked:** If Brigade S1 has already approved, the record is locked permanently.",
            'actions' => [
                ['label' => 'Open Review Attendance', 'url' => BASE_URL . '/s1/review_attendance.php']
            ],
            'suggestions' => [
                "How do I return a submission with remarks?",
                "How do I reassign an unassigned cadet?"
            ],
            'unanswered' => false
        ];
    }

    // 8. S1: How do I import cadets via CSV?
    if (($role === 'battalion_s1' || $role === 'brigade_s1') && ($hasWord('csv') || $hasAll(['import', 'upload'], ['cadet', 'cadets']))) {
        return [
            'reply' => "Sir/Ma'am, to import cadets via CSV:\n\n" .
                       "1. Navigate to **Import Cadets** (`/s1/import_cadets.php`).\n" .
                       "2. Prepare your CSV with columns: `Cadet Code`, `First Name`, `Last Name`, `Middle Name`, `Student Number`, `Gender`, `Contact Number`, `Company`, `Platoon`, and `Program`.\n" .
                       "3. Upload the file to view the **Validation Preview**.\n" .
                       "4. Review any detected errors or duplicates, then click **Confirm Import** to save to the database.",
            'actions' => [
                ['label' => 'Go to Import Cadets', 'url' => BASE_URL . '/s1/import_cadets.php']
            ],
            'suggestions' => [
                "How do I import officers?",
                "How do I reassign an unassigned cadet?"
            ],
            'unanswered' => false
        ];
    }

    // 9. S1: Reassign unassigned cadet
    if (($role === 'battalion_s1' || $role === 'brigade_s1') && ($hasWord('unassigned') || $hasAll(['reassign', 'assign', 'transfer', 'lipat'], ['cadet', 'cadets']))) {
        return [
            'reply' => "Sir/Ma'am, to assign or move a cadet:\n\n" .
                       "1. Go to **Cadet Roster** (`/s1/roster.php`).\n" .
                       "2. Search the cadet's name or filter by 'Unassigned'.\n" .
                       "3. Click **Edit** next to their record.\n" .
                       "4. Select the target **Company** and **Platoon** dropdowns.\n" .
                       "5. Click **Save Changes**.",
            'actions' => [
                ['label' => 'Open Cadet Roster', 'url' => BASE_URL . '/s1/roster.php']
            ],
            'suggestions' => [
                "Why can't I approve this submission?",
                "How do I import cadets via CSV?"
            ],
            'unanswered' => false
        ];
    }

    // 10. Class President: Why doesn't recent attendance show up?
    if ($role === 'class_president' && ($hasAll(['recent', 'latest', 'new', 'missing'], ['attendance', 'session', 'sessions']) || $hasAll(['not', 'di', 'wala'], ['show', 'showing', 'reflected', 'lumalabas', 'makita', 'appear']))) {
        return [
            'reply' => "Sir/Ma'am, in UnitSync:\n\n" .
                       "• Class Presidents only see **fully approved attendance**.\n" .
                       "• Sessions currently in *Draft*, *Submitted*, or *Returned* status will not count toward official percentages until both Battalion S1 and Brigade S1 grant final approval.\n" .
                       "• Once Brigade S1 approves, the attendance matrix and percentages update automatically!",
            'actions' => [
                ['label' => 'View Attendance Matrix', 'url' => BASE_URL . '/president/view_attendance.php']
            ],
            'suggestions' => [
                "How is attendance % computed?",
                "What does At Risk mean?"
            ],
            'unanswered' => false
        ];
    }

    // 11. Formula & Computation (Common to all roles)
    if ($hasWord('formula') || $hasWord('computation') || $hasWord('compute') || $hasWord('computed') || $hasAll(['how', 'paano'], ['attendance', 'percentage', 'computed', 'calculate', 'calculated'])) {
        return [
            'reply' => "Sir/Ma'am, here is the official ROTC Attendance Percentage formula:\n\n" .
                       "$$\\text{Attendance \\%} = \\frac{\\text{Present} + \\text{Late}}{\\text{Approved Sessions Held} - \\text{Excused}} \\times 100$$\n\n" .
                       "• **Approved Sessions Only:** Only sessions fully approved by both S1 levels count.\n" .
                       "• **Late (L):** Counts as attended in the numerator.\n" .
                       "• **Excused (E):** Deducts from the total sessions held (denominator), so the cadet is never penalized for authorized absences!\n" .
                       "• **Absent (A):** Counts against the total without increasing the numerator.",
            'actions' => [],
            'suggestions' => [
                "What does At Risk mean?",
                "What are the P/A/L/E statuses?"
            ],
            'unanswered' => false
        ];
    }

    // 12. At Risk meaning (Common)
    if ($hasAll(['risk']) || $hasWord('at-risk') || $hasWord('failing') || $hasWord('threshold') || $hasWord('80%')) {
        return [
            'reply' => "Sir/Ma'am, **At Risk** identifies cadets who are in danger of failing ROTC attendance requirements:\n\n" .
                       "• Any cadet whose official attendance percentage falls below the passing threshold (default **80%**) is marked with an *At Risk* badge.\n" .
                       "• Excused absences do not count against this percentage.\n" .
                       "• Leaders and Class Presidents can monitor at-risk counts from their dashboards to provide timely reminders before final grading.",
            'actions' => [],
            'suggestions' => [
                "How is attendance % computed?",
                "What are the P/A/L/E statuses?"
            ],
            'unanswered' => false
        ];
    }

    // 13. P / A / L / E Statuses
    if ($hasWord('pale') || $hasAll(['status', 'statuses'], ['meaning', 'p', 'a', 'l', 'e', 'what', 'ano'])) {
        return [
            'reply' => "Sir/Ma'am, UnitSync uses 4 standard attendance statuses:\n\n" .
                       "• **P (Present):** Cadet attended on time.\n" .
                       "• **A (Absent):** Cadet was unexcused and did not attend.\n" .
                       "• **L (Late):** Cadet arrived after formation. Minutes late must be specified.\n" .
                       "• **E (Excused):** Cadet had prior clearance or medical reason. An excuse reason is mandatory, and it reduces the session denominator.",
            'actions' => [],
            'suggestions' => [
                "How is attendance % computed?",
                "What does At Risk mean?"
            ],
            'unanswered' => false
        ];
    }

    // 14. Admin: S1 Slot Quota / Cannot approve registration
    if ($role === 'admin' && ($hasWord('quota') || $hasAll(['s1'], ['slot', 'slots', 'limit', 'limits', 'registration', 'registrations']) || $hasAll(['approve', 'approval'], ['s1', 'registration']))) {
        return [
            'reply' => "Sir/Ma'am, UnitSync enforces strict S1 account quotas:\n\n" .
                       "• **Battalion S1:** Maximum of **2 active accounts**.\n" .
                       "• **Brigade S1:** Maximum of **1 active account**.\n\n" .
                       "If the quota is currently full, approving a new S1 registration will be blocked until an existing S1 account is deactivated under **Users** (`/admin/users.php`).",
            'actions' => [
                ['label' => 'View Registration Requests', 'url' => BASE_URL . '/admin/requests.php'],
                ['label' => 'Manage Users', 'url' => BASE_URL . '/admin/users.php']
            ],
            'suggestions' => [
                "How do I switch the active term?",
                "What happens when a session is cancelled?"
            ],
            'unanswered' => false
        ];
    }

    // 15. Admin: Switch active term
    if ($role === 'admin' && $hasAll(['switch', 'change', 'set', 'active', 'palit'], ['term', 'terms', 'semester', 'semesters'])) {
        return [
            'reply' => "Sir/Ma'am, to switch the active academic term:\n\n" .
                       "1. Navigate to **Terms & Semesters** (`/admin/terms.php`).\n" .
                       "2. Find the desired term and click **Set Active**.\n" .
                       "3. Only one term can be active at a time. The previous active term is automatically archived/deactivated, and all dashboards switch to the new term.",
            'actions' => [
                ['label' => 'Open Terms & Semesters', 'url' => BASE_URL . '/admin/terms.php']
            ],
            'suggestions' => [
                "What happens when a session is cancelled?",
                "Why can't I approve this registration?"
            ],
            'unanswered' => false
        ];
    }

    // 16. Admin: Cancelled sessions
    if ($role === 'admin' && ($hasWord('cancelled') || $hasWord('canceled') || $hasAll(['cancel', 'cancelling'], ['session', 'sessions', 'training']))) {
        return [
            'reply' => "Sir/Ma'am, when a training session is marked **Cancelled** in `/admin/sessions.php`:\n\n" .
                       "• Platoon Leaders cannot take or submit attendance for it.\n" .
                       "• It is **completely excluded** from the attendance formula denominator, ensuring no cadets are penalized due to weather suspensions or holidays.",
            'actions' => [
                ['label' => 'Open Training Sessions', 'url' => BASE_URL . '/admin/sessions.php']
            ],
            'suggestions' => [
                "How do I switch the active term?",
                "Why can't I approve this registration?"
            ],
            'unanswered' => false
        ];
    }

    // 17. Password & Profile
    if ($hasWord('password') || $hasWord('profile')) {
        return [
            'reply' => "Sir/Ma'am, you can update your password by visiting **My Profile**:\n\n" .
                       "1. Click your name/avatar in the top header or sidebar.\n" .
                       "2. Select **My Profile** (`/profile.php`).\n" .
                       "3. Enter your current password and your new password, then save.",
            'actions' => [
                ['label' => 'Go to My Profile', 'url' => BASE_URL . '/profile.php']
            ],
            'suggestions' => [
                "What are the P/A/L/E statuses?",
                "How is attendance % computed?"
            ],
            'unanswered' => false
        ];
    }

    // 18. Fallback / Unanswered
    return [
        'reply' => "I apologize, Sir/Ma'am, but I don't have a direct answer for that in my current knowledge base. 🫡\n\n" .
                   "As a guide bot, I follow official UnitSync procedures. For questions outside standard workflows, please consult your **S1 Officer** or **System Administrator**.\n\n" .
                   "You can also check the topics below:",
        'actions' => [],
        'suggestions' => ai_get_page_suggestions($role, $page),
        'unanswered' => true
    ];
}
