<?php
// General Helper Functions
require_once __DIR__ . '/../config/config.php';

function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void {
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        header("Location: " . $path);
    } else {
        $url = BASE_URL . '/' . ltrim($path, '/');
        header("Location: " . $url);
    }
    exit;
}

function log_mail_sim(string $to_email, string $subject, string $body): void {
    $log_dir = __DIR__ . '/../logs';
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0777, true);
    }
    $entry = "[" . date('Y-m-d H:i:s') . "] TO: {$to_email} | SUBJECT: {$subject}\nBODY:\n{$body}\n" . str_repeat('-', 50) . "\n";
    file_put_contents($log_dir . '/mail.log', $entry, FILE_APPEND);
}

function format_role_name(string $role): string {
    $roles = [
        'admin' => 'Admin',
        'class_president' => 'Class President',
        'platoon_leader' => 'Platoon Leader',
        'battalion_s1' => 'Battalion S1',
        'brigade_s1' => 'Brigade S1',
    ];
    return $roles[$role] ?? ucwords(str_replace('_', ' ', $role));
}

function log_audit(PDO $pdo, ?int $actorId, string $action, string $entity, ?int $entityId = null, ?string $details = null): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (actor_id, action, entity, entity_id, details, created_at)
            VALUES (:actor_id, :action, :entity, :entity_id, :details, NOW())
        ");
        $stmt->execute([
            'actor_id' => $actorId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId,
            'details' => $details
        ]);
    } catch (Throwable $e) {
        error_log("Failed to write to audit_log: " . $e->getMessage());
    }
}

function next_cadet_code(PDO $pdo): string {
    $year = date('Y');
    $prefix = "CDT-{$year}-";

    // Lock and get the latest cadet code for this year to prevent duplicates
    $stmt = $pdo->prepare("
        SELECT cadet_code FROM cadets 
        WHERE cadet_code LIKE :prefix 
        ORDER BY id DESC 
        LIMIT 1 
        FOR UPDATE
    ");
    $stmt->execute(['prefix' => $prefix . '%']);
    $lastCode = $stmt->fetchColumn();

    $nextSeq = 1;
    if ($lastCode) {
        $parts = explode('-', $lastCode);
        $num = (int)end($parts);
        $nextSeq = $num + 1;
    }

    return sprintf('CDT-%s-%04d', $year, $nextSeq);
}

/**
 * Get setting value from settings table with fallback
 */
function get_setting(PDO $pdo, string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE key_name = ? LIMIT 1");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null) {
            $cache[$key] = (string)$val;
            return (string)$val;
        }
    } catch (Throwable $e) {
        // Fallback to default if table/query fails
    }
    $cache[$key] = $default;
    return $default;
}

/**
 * Set setting value in settings table
 */
function set_setting(PDO $pdo, string $key, string $value, ?string $description = null): void {
    $stmt = $pdo->prepare("
        INSERT INTO settings (key_name, value, description, updated_at)
        VALUES (:key_name, :value, :description, NOW())
        ON DUPLICATE KEY UPDATE value = VALUES(value), description = COALESCE(VALUES(description), description), updated_at = NOW()
    ");
    $stmt->execute([
        'key_name'    => $key,
        'value'       => $value,
        'description' => $description,
    ]);
}

/**
 * Calculate attendance percentage for a single cadet in a specific term.
 * Formula: (Present + Late) / (Approved Sessions Held - Excused) * 100
 *
 * Rules:
 * - Only fully approved sessions count (attendance_submissions.state = 'approved').
 * - Cancelled sessions never count (training_sessions.status != 'cancelled').
 * - Denominator <= 0 returns null (rendered as "—").
 *
 * @param PDO $pdo
 * @param int $cadetId
 * @param int $termId
 * @param int|null $platoonId Optional: platoon ID. If null, resolved from enrollment.
 * @return float|null Percentage (0.0 to 100.0) rounded to 1 decimal place, or null if denominator <= 0.
 */
function attendance_percentage(PDO $pdo, int $cadetId, int $termId, ?int $platoonId = null): ?float {
    if ($platoonId === null) {
        $stmt = $pdo->prepare("SELECT platoon_id FROM enrollments WHERE cadet_id = ? AND term_id = ? LIMIT 1");
        $stmt->execute([$cadetId, $termId]);
        $platoonId = $stmt->fetchColumn();
        if (!$platoonId) {
            return null;
        }
        $platoonId = (int)$platoonId;
    }

    // 1. Total approved sessions held for this platoon in the term
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM training_sessions ts
        JOIN attendance_submissions sub ON sub.session_id = ts.id AND sub.platoon_id = :platoon_id
        WHERE ts.term_id = :term_id
          AND ts.status != 'cancelled'
          AND sub.state = 'approved'
    ");
    $stmt->execute([
        'platoon_id' => $platoonId,
        'term_id'    => $termId
    ]);
    $approvedHeld = (int)$stmt->fetchColumn();

    if ($approvedHeld === 0) {
        return null;
    }

    // 2. Count Present (P), Late (L), Excused (E) for this cadet in those approved sessions
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN ar.status = 'P' THEN 1 ELSE 0 END) AS count_p,
            SUM(CASE WHEN ar.status = 'L' THEN 1 ELSE 0 END) AS count_l,
            SUM(CASE WHEN ar.status = 'E' THEN 1 ELSE 0 END) AS count_e
        FROM attendance_records ar
        JOIN training_sessions ts ON ts.id = ar.session_id
        JOIN attendance_submissions sub ON sub.session_id = ts.id AND sub.platoon_id = :platoon_id
        WHERE ts.term_id = :term_id
          AND ts.status != 'cancelled'
          AND sub.state = 'approved'
          AND ar.cadet_id = :cadet_id
    ");
    $stmt->execute([
        'platoon_id' => $platoonId,
        'term_id'    => $termId,
        'cadet_id'   => $cadetId
    ]);
    $counts = $stmt->fetch();

    $present = (int)($counts['count_p'] ?? 0);
    $late    = (int)($counts['count_l'] ?? 0);
    $excused = (int)($counts['count_e'] ?? 0);

    $denominator = $approvedHeld - $excused;
    if ($denominator <= 0) {
        return null;
    }

    $numerator = $present + $late;
    return round(($numerator / $denominator) * 100, 1);
}

/**
 * Batch calculate attendance percentage and counts for multiple cadets in a platoon.
 * Efficiently runs 2 queries for the whole set of cadets.
 *
 * @param PDO $pdo
 * @param int $platoonId
 * @param int $termId
 * @param array $cadetIds Array of cadet IDs to calculate
 * @return array Associative array keyed by cadet_id with keys: [percentage, present, late, excused, absent, approved_held, denominator]
 */
function get_platoon_cadets_attendance_summary(PDO $pdo, int $platoonId, int $termId, array $cadetIds = []): array {
    if (empty($cadetIds)) {
        return [];
    }

    // 1. Total approved sessions held for this platoon in the term
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM training_sessions ts
        JOIN attendance_submissions sub ON sub.session_id = ts.id AND sub.platoon_id = :platoon_id
        WHERE ts.term_id = :term_id
          AND ts.status != 'cancelled'
          AND sub.state = 'approved'
    ");
    $stmt->execute([
        'platoon_id' => $platoonId,
        'term_id'    => $termId
    ]);
    $approvedHeld = (int)$stmt->fetchColumn();

    // Initialize default results for all requested cadet IDs
    $results = [];
    foreach ($cadetIds as $cid) {
        $cid = (int)$cid;
        $results[$cid] = [
            'percentage'    => null,
            'present'       => 0,
            'late'          => 0,
            'excused'       => 0,
            'absent'        => 0,
            'approved_held' => $approvedHeld,
            'denominator'   => $approvedHeld,
        ];
    }

    if ($approvedHeld === 0) {
        return $results;
    }

    // 2. Fetch counts in approved sessions for all specified cadets in one query
    $inPlaceholders = implode(',', array_fill(0, count($cadetIds), '?'));
    $sql = "
        SELECT 
            ar.cadet_id,
            SUM(CASE WHEN ar.status = 'P' THEN 1 ELSE 0 END) AS count_p,
            SUM(CASE WHEN ar.status = 'L' THEN 1 ELSE 0 END) AS count_l,
            SUM(CASE WHEN ar.status = 'E' THEN 1 ELSE 0 END) AS count_e,
            SUM(CASE WHEN ar.status = 'A' THEN 1 ELSE 0 END) AS count_a
        FROM attendance_records ar
        JOIN training_sessions ts ON ts.id = ar.session_id
        JOIN attendance_submissions sub ON sub.session_id = ts.id AND sub.platoon_id = ?
        WHERE ts.term_id = ?
          AND ts.status != 'cancelled'
          AND sub.state = 'approved'
          AND ar.cadet_id IN ($inPlaceholders)
        GROUP BY ar.cadet_id
    ";

    $params = array_merge([$platoonId, $termId], array_map('intval', $cadetIds));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $cid = (int)$row['cadet_id'];
        $p = (int)$row['count_p'];
        $l = (int)$row['count_l'];
        $e = (int)$row['count_e'];
        $a = (int)$row['count_a'];
        $denom = $approvedHeld - $e;

        $pct = null;
        if ($denom > 0) {
            $pct = round((($p + $l) / $denom) * 100, 1);
        }

        $results[$cid] = [
            'percentage'    => $pct,
            'present'       => $p,
            'late'          => $l,
            'excused'       => $e,
            'absent'        => $a,
            'approved_held' => $approvedHeld,
            'denominator'   => $denom,
        ];
    }

    return $results;
}

/**
 * Get overall attendance summary for a platoon in an active term
 *
 * @param PDO $pdo
 * @param int $platoonId
 * @param int $termId
 * @param float $atRiskThreshold
 * @return array [overall_percentage => ?float, approved_held => int, at_risk_count => int, total_active_cadets => int]
 */
function get_platoon_overall_attendance(PDO $pdo, int $platoonId, int $termId, float $atRiskThreshold = 80.0): array {
    $stmt = $pdo->prepare("
        SELECT c.id
        FROM cadets c
        JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = :term_id
        WHERE e.platoon_id = :platoon_id AND c.status = 'active'
    ");
    $stmt->execute([
        'term_id'    => $termId,
        'platoon_id' => $platoonId
    ]);
    $cadetIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $totalActive = count($cadetIds);

    if (empty($cadetIds)) {
        return [
            'overall_percentage'  => null,
            'approved_held'       => 0,
            'at_risk_count'       => 0,
            'total_active_cadets' => 0,
        ];
    }

    $summaries = get_platoon_cadets_attendance_summary($pdo, $platoonId, $termId, $cadetIds);

    $totalNum = 0;
    $totalDenom = 0;
    $atRiskCount = 0;
    $approvedHeld = 0;

    foreach ($summaries as $s) {
        $approvedHeld = $s['approved_held'];
        if ($s['denominator'] > 0) {
            $totalNum += ($s['present'] + $s['late']);
            $totalDenom += $s['denominator'];
        }
        if ($s['percentage'] !== null && $s['percentage'] < $atRiskThreshold) {
            $atRiskCount++;
        }
    }

    $overallPct = null;
    if ($totalDenom > 0) {
        $overallPct = round(($totalNum / $totalDenom) * 100, 1);
    }

    return [
        'overall_percentage'  => $overallPct,
        'approved_held'       => $approvedHeld,
        'at_risk_count'       => $atRiskCount,
        'total_active_cadets' => $totalActive,
    ];
}



