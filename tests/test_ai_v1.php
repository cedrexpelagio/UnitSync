<?php
// UnitSync: Automated Test Suite for i-Cadet v1
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/ai/config.php';
require_once __DIR__ . '/../includes/ai/knowledge.php';
require_once __DIR__ . '/../includes/ai/engine.php';

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $cond, string $detail = '') {
    global $passed, $failed;
    if ($cond) {
        echo " [PASS] $name\n";
        $passed++;
    } else {
        echo " [FAIL] $name: $detail\n";
        $failed++;
    }
}

echo "===========================================\n";
echo "   i-Cadet v1 Automated Test Suite\n";
echo "===========================================\n\n";

// 1. Config tests
assert_test("ai_is_enabled returns true", ai_is_enabled($pdo) === true);
assert_test("ai_get_name returns i-Cadet", ai_get_name($pdo) === 'i-Cadet');
$cfg = ai_get_config();
assert_test("ai_get_config returns provider", isset($cfg['provider']) && isset($cfg['model']));

// 2. Knowledge files exist and are loaded
$roles = ['platoon_leader', 'battalion_s1', 'brigade_s1', 'class_president', 'admin'];
foreach ($roles as $r) {
    $k = ai_get_role_knowledge($r);
    assert_test("Knowledge loaded for $r (" . strlen($k) . " bytes)", strlen($k) > 500);
}

// 3. Page suggestions per role
$plSug = ai_get_page_suggestions('platoon_leader', 'attendance.php');
assert_test("Platoon Leader suggestions on attendance.php", in_array("Why is the Submit button disabled?", $plSug));

$s1Sug = ai_get_page_suggestions('battalion_s1', 'review_attendance.php');
assert_test("S1 suggestions on review_attendance.php", in_array("Why can't I approve this submission?", $s1Sug));

$cpSug = ai_get_page_suggestions('class_president', 'view_attendance.php');
assert_test("Class President suggestions", in_array("Why doesn't recent attendance show up?", $cpSug));

$admSug = ai_get_page_suggestions('admin', 'requests.php');
assert_test("Admin suggestions on requests.php", in_array("Why can't I approve this S1 registration?", $admSug));

// 4. Test Intent Matching (Platoon Leader)
$plUser = ['id' => 1, 'role' => 'platoon_leader', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz'];

$r1 = AiCadetEngine::respond($plUser, 'attendance.php', 'Why is the submit button disabled?');
assert_test("PL: Why is submit disabled answer", stripos($r1['reply'], 'unmarked cadets') !== false);
assert_test("PL: Why is submit disabled has action", count($r1['actions']) > 0);

$r2 = AiCadetEngine::respond($plUser, 'attendance.php', 'I edited after submitting, what happened?');
assert_test("PL: Edited after submitting answer", stripos($r2['reply'], 'cleared') !== false || stripos($r2['reply'], 'resets') !== false);

$r3 = AiCadetEngine::respond($plUser, 'cadets.php', 'How do I remove or reassign a cadet?');
assert_test("PL: Remove cadet refusal & delegation to S1", stripos($r3['reply'], 'Battalion S1') !== false || stripos($r3['reply'], 'cannot remove') !== false);

// 5. Test Intent Matching (S1)
$s1User = ['id' => 2, 'role' => 'battalion_s1', 'first_name' => 'Maria', 'last_name' => 'Santos'];

$r4 = AiCadetEngine::respond($s1User, 'review_attendance.php', 'Why cant I approve this submission?');
assert_test("S1: Why can't I approve answer", stripos($r4['reply'], 'Sequential Approval') !== false || stripos($r4['reply'], 'Battalion S1') !== false);

$r5 = AiCadetEngine::respond($s1User, 'roster.php', 'How do I import cadets via CSV?');
assert_test("S1: Import cadets via CSV answer", stripos($r5['reply'], 'Import Cadets') !== false);

$r6 = AiCadetEngine::respond($s1User, 'roster.php', 'How do I reassign an unassigned cadet?');
assert_test("S1: Reassign unassigned cadet answer", stripos($r6['reply'], 'Cadet Roster') !== false);

// 6. Test Intent Matching (Class President)
$cpUser = ['id' => 3, 'role' => 'class_president', 'first_name' => 'Pedro', 'last_name' => 'Reyes'];

$r7 = AiCadetEngine::respond($cpUser, 'view_attendance.php', 'Why does recent attendance not show up?');
assert_test("CP: Recent attendance not showing answer", stripos($r7['reply'], 'fully approved') !== false);

$r8 = AiCadetEngine::respond($cpUser, 'view_attendance.php', 'How is attendance % computed?');
assert_test("CP: Attendance formula answer", stripos($r8['reply'], 'Attendance') !== false && stripos($r8['reply'], 'Excused') !== false);

$r9 = AiCadetEngine::respond($cpUser, 'dashboard.php', 'What does At Risk mean?');
assert_test("CP: At Risk threshold answer", stripos($r9['reply'], '80%') !== false);

// 7. Test Intent Matching (Admin)
$admUser = ['id' => 4, 'role' => 'admin', 'first_name' => 'Admin', 'last_name' => 'User'];

$r10 = AiCadetEngine::respond($admUser, 'requests.php', 'Why cannot I approve an S1 registration?');
assert_test("Admin: S1 slot limits answer", stripos($r10['reply'], '2 active') !== false || stripos($r10['reply'], 'quotas') !== false);

$r11 = AiCadetEngine::respond($admUser, 'terms.php', 'How do I switch the active term?');
assert_test("Admin: Switch active term answer", stripos($r11['reply'], 'Set Active') !== false);

$r12 = AiCadetEngine::respond($admUser, 'sessions.php', 'What happens when a training session is cancelled?');
assert_test("Admin: Cancelled session answer", stripos($r12['reply'], 'excluded') !== false || stripos($r12['reply'], 'cancelled') !== false);

// 8. Persona & Greetings & Fallbacks
$r13 = AiCadetEngine::respond($plUser, 'attendance.php', 'Snappy salute! Hello i-cadet');
assert_test("Greeting includes i-Cadet & Snappy salute", stripos($r13['reply'], 'i-Cadet') !== false && stripos($r13['reply'], 'salute') !== false);

$r14 = AiCadetEngine::respond($plUser, 'attendance.php', 'What is the recipe for chicken adobo?');
assert_test("Out of scope fallback", stripos($r14['reply'], 'consult') !== false || stripos($r14['reply'], 'knowledge base') !== false);
assert_test("Out of scope flag is_unanswered is true", !empty($r14['unanswered']));

echo "\n-------------------------------------------\n";
echo "Total tests: " . ($passed + $failed) . " | Passed: $passed | Failed: $failed\n";
echo "-------------------------------------------\n";

exit($failed > 0 ? 1 : 0);
