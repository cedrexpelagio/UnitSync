<?php
// UnitSync: Attendance Query Functions for S1 View and CSV Export
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/helpers.php';

/**
 * Fetch approved training sessions in the active term that have at least one approved submission
 * matching the given scope (company_id, platoon_id, session_id).
 *
 * @param PDO $pdo
 * @param int $term_id
 * @param int|string|null $company_id Filter by company (int or 'unassigned')
 * @param int|string|null $platoon_id Filter by platoon (int or 'unassigned')
 * @param int|null $session_id Specific session filter
 * @return array List of session records: [id, session_date, label]
 */
function get_approved_sessions(PDO $pdo, int $term_id, $company_id = null, $platoon_id = null, ?int $session_id = null): array {
    $where = [
        "ts.term_id = :term_id",
        "ts.status != 'cancelled'",
        "sub.state = 'approved'"
    ];
    $params = ['term_id' => $term_id];

    if ($session_id !== null && $session_id > 0) {
        $where[] = "ts.id = :session_id";
        $params['session_id'] = $session_id;
    }

    if (is_numeric($platoon_id) && (int)$platoon_id > 0) {
        $where[] = "sub.platoon_id = :platoon_id";
        $params['platoon_id'] = (int)$platoon_id;
    } elseif ($platoon_id === 'unassigned') {
        // Unassigned filter: look for sessions approved in any platoon where unassigned cadets have records
        // but submissions always belong to platoons. So unassigned cadets can view sessions approved for the term.
    } elseif (is_numeric($company_id) && (int)$company_id > 0) {
        $where[] = "p.company_id = :company_id";
        $params['company_id'] = (int)$company_id;
    }

    $where_sql = implode(' AND ', $where);

    $sql = "
        SELECT DISTINCT ts.id, ts.session_date, ts.label
        FROM training_sessions ts
        JOIN attendance_submissions sub ON sub.session_id = ts.id
        JOIN platoons p ON p.id = sub.platoon_id
        WHERE {$where_sql}
        ORDER BY ts.session_date ASC, ts.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Build WHERE clauses and parameters for filtered cadets
 *
 * @param int $term_id
 * @param array $filters [company_id, platoon_id, program_id, status, q]
 * @return array [$where_sql, $params]
 */
function build_cadet_filter_clauses(int $term_id, array $filters): array {
    $where = [];
    $params = ['term_id' => $term_id];

    // Status filter (default: 'all')
    $status = trim($filters['status'] ?? 'all');
    if ($status !== '' && $status !== 'all') {
        $where[] = "c.status = :cadet_status";
        $params['cadet_status'] = $status;
    }

    // Program filter
    $program_id = (int)($filters['program_id'] ?? 0);
    if ($program_id > 0) {
        $where[] = "c.program_id = :program_id";
        $params['program_id'] = $program_id;
    }

    // Company & Platoon filter
    $company_id = $filters['company_id'] ?? null;
    $platoon_id = $filters['platoon_id'] ?? null;

    if ($platoon_id === 'unassigned' || $company_id === 'unassigned') {
        $where[] = "e.platoon_id IS NULL";
    } else {
        if (is_numeric($platoon_id) && (int)$platoon_id > 0) {
            $where[] = "e.platoon_id = :platoon_id";
            $params['platoon_id'] = (int)$platoon_id;
        } elseif (is_numeric($company_id) && (int)$company_id > 0) {
            $where[] = "e.company_id = :company_id";
            $params['company_id'] = (int)$company_id;
        }
    }

    // Search query
    $q = trim($filters['q'] ?? '');
    if ($q !== '') {
        $where[] = "(c.last_name LIKE :q OR c.first_name LIKE :q OR c.middle_name LIKE :q OR c.cadet_code LIKE :q OR c.student_number LIKE :q)";
        $params['q'] = '%' . addcslashes($q, '%_\\') . '%';
    }

    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    return [$where_sql, $params];
}

/**
 * Count total cadets matching filters
 *
 * @param PDO $pdo
 * @param int $term_id
 * @param array $filters
 * @return int
 */
function count_filtered_cadets(PDO $pdo, int $term_id, array $filters): int {
    [$where_sql, $params] = build_cadet_filter_clauses($term_id, $filters);

    $sql = "
        SELECT COUNT(*)
        FROM cadets c
        LEFT JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = :term_id
        {$where_sql}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * Fetch cadets matching filters with optional pagination
 *
 * @param PDO $pdo
 * @param int $term_id
 * @param array $filters
 * @param int|null $limit
 * @param int|null $offset
 * @return array List of cadet records with company, platoon, and program info
 */
function get_filtered_cadets(PDO $pdo, int $term_id, array $filters, ?int $limit = null, ?int $offset = null): array {
    [$where_sql, $params] = build_cadet_filter_clauses($term_id, $filters);

    $limit_sql = '';
    if ($limit !== null && $limit > 0) {
        $off = ($offset !== null && $offset >= 0) ? (int)$offset : 0;
        $limit_sql = "LIMIT " . (int)$limit . " OFFSET " . $off;
    }

    $sql = "
        SELECT c.id, c.cadet_code, c.last_name, c.first_name, c.middle_name,
               c.gender, c.status, c.student_number,
               pr.code AS program_code, pr.name AS program_name,
               e.company_id, e.platoon_id,
               co.name AS company_name,
               pl.name AS platoon_name
        FROM cadets c
        LEFT JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = :term_id
        LEFT JOIN companies co ON co.id = e.company_id
        LEFT JOIN platoons pl ON pl.id = e.platoon_id
        LEFT JOIN programs pr ON pr.id = c.program_id
        {$where_sql}
        ORDER BY c.last_name ASC, c.first_name ASC, c.id ASC
        {$limit_sql}
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Fetch approved attendance records for specified cadets across specified sessions.
 * Returns an associative map: [$cadet_id][$session_id] = [status, minutes_late, excuse_reason]
 *
 * @param PDO $pdo
 * @param int $term_id
 * @param array $cadet_ids
 * @param array $session_ids
 * @return array
 */
function get_approved_attendance_matrix(PDO $pdo, int $term_id, array $cadet_ids, array $session_ids): array {
    $matrix = [];
    if (empty($cadet_ids) || empty($session_ids)) {
        return $matrix;
    }

    $cadet_placeholders = implode(',', array_fill(0, count($cadet_ids), '?'));
    $session_placeholders = implode(',', array_fill(0, count($session_ids), '?'));

    $sql = "
        SELECT ar.cadet_id, ar.session_id, ar.status, ar.minutes_late, ar.excuse_reason
        FROM attendance_records ar
        JOIN attendance_submissions sub ON sub.id = ar.submission_id
        JOIN training_sessions ts ON ts.id = ar.session_id
        WHERE ts.term_id = ?
          AND ts.status != 'cancelled'
          AND sub.state = 'approved'
          AND ar.cadet_id IN ({$cadet_placeholders})
          AND ar.session_id IN ({$session_placeholders})
    ";

    $params = array_merge([$term_id], array_map('intval', $cadet_ids), array_map('intval', $session_ids));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $cid = (int)$row['cadet_id'];
        $sid = (int)$row['session_id'];
        $matrix[$cid][$sid] = [
            'status'        => $row['status'],
            'minutes_late'  => $row['minutes_late'],
            'excuse_reason' => $row['excuse_reason'],
        ];
    }

    return $matrix;
}

/**
 * Calculate attendance totals and percentage for cadets across ALL approved sessions in the term
 * (Not restricted by Session filter, as required by RULES and FEATURE.md).
 * Formula: (P + L) / (P + A + L) * 100.
 *
 * @param PDO $pdo
 * @param int $term_id
 * @param array $cadet_ids
 * @return array Associative array [$cadet_id => [p, a, l, e, denominator, percentage]]
 */
function get_cadet_term_attendance_stats(PDO $pdo, int $term_id, array $cadet_ids): array {
    $stats = [];
    if (empty($cadet_ids)) {
        return $stats;
    }

    // Default stats for each cadet
    foreach ($cadet_ids as $cid) {
        $stats[(int)$cid] = [
            'p'           => 0,
            'a'           => 0,
            'l'           => 0,
            'e'           => 0,
            'denominator' => 0,
            'percentage'  => null,
        ];
    }

    $cadet_placeholders = implode(',', array_fill(0, count($cadet_ids), '?'));

    $sql = "
        SELECT ar.cadet_id,
               SUM(CASE WHEN ar.status = 'P' THEN 1 ELSE 0 END) AS count_p,
               SUM(CASE WHEN ar.status = 'A' THEN 1 ELSE 0 END) AS count_a,
               SUM(CASE WHEN ar.status = 'L' THEN 1 ELSE 0 END) AS count_l,
               SUM(CASE WHEN ar.status = 'E' THEN 1 ELSE 0 END) AS count_e
        FROM attendance_records ar
        JOIN attendance_submissions sub ON sub.id = ar.submission_id
        JOIN training_sessions ts ON ts.id = ar.session_id
        WHERE ts.term_id = ?
          AND ts.status != 'cancelled'
          AND sub.state = 'approved'
          AND ar.cadet_id IN ({$cadet_placeholders})
        GROUP BY ar.cadet_id
    ";

    $params = array_merge([$term_id], array_map('intval', $cadet_ids));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $cid = (int)$row['cadet_id'];
        $p = (int)$row['count_p'];
        $a = (int)$row['count_a'];
        $l = (int)$row['count_l'];
        $e = (int)$row['count_e'];

        // Formula: (P + L) / (P + A + L) * 100
        $denom = $p + $a + $l;
        $pct = null;
        if ($denom > 0) {
            $pct = round((($p + $l) / $denom) * 100, 1);
        }

        $stats[$cid] = [
            'p'           => $p,
            'a'           => $a,
            'l'           => $l,
            'e'           => $e,
            'denominator' => $denom,
            'percentage'  => $pct,
        ];
    }

    return $stats;
}

/**
 * Aggregate session totals (P, A, L, E) for ALL cadets matching the filter criteria.
 * Returns map: [$session_id => [p, a, l, e, total_marked]]
 *
 * @param PDO $pdo
 * @param int $term_id
 * @param array $filters
 * @param array $session_ids
 * @return array
 */
function get_session_totals_for_filtered_cadets(PDO $pdo, int $term_id, array $filters, array $session_ids): array {
    $totals = [];
    if (empty($session_ids)) {
        return $totals;
    }

    foreach ($session_ids as $sid) {
        $totals[(int)$sid] = [
            'p'            => 0,
            'a'            => 0,
            'l'            => 0,
            'e'            => 0,
            'total_marked' => 0,
        ];
    }

    [$where_cadets, $filter_params] = build_cadet_filter_clauses($term_id, $filters);

    $session_named_placeholders = [];
    $all_params = $filter_params;
    foreach ($session_ids as $i => $sid) {
        $pname = ":sid_{$i}";
        $session_named_placeholders[] = $pname;
        $all_params["sid_{$i}"] = (int)$sid;
    }
    $session_in_sql = implode(',', $session_named_placeholders);

    // Base query joining attendance records with cadet filter
    $sql = "
        SELECT ar.session_id,
               SUM(CASE WHEN ar.status = 'P' THEN 1 ELSE 0 END) AS count_p,
               SUM(CASE WHEN ar.status = 'A' THEN 1 ELSE 0 END) AS count_a,
               SUM(CASE WHEN ar.status = 'L' THEN 1 ELSE 0 END) AS count_l,
               SUM(CASE WHEN ar.status = 'E' THEN 1 ELSE 0 END) AS count_e
        FROM attendance_records ar
        JOIN attendance_submissions sub ON sub.id = ar.submission_id
        JOIN training_sessions ts ON ts.id = ar.session_id
        JOIN cadets c ON c.id = ar.cadet_id
        LEFT JOIN enrollments e ON e.cadet_id = c.id AND e.term_id = ts.term_id
        WHERE ts.term_id = :term_id
          AND ts.status != 'cancelled'
          AND sub.state = 'approved'
          AND ar.session_id IN ({$session_in_sql})
    ";

    // If cadet filters exist, inject them
    if (!empty($where_cadets)) {
        $cadet_conditions = preg_replace('/^\s*WHERE\s+/i', '', $where_cadets);
        $sql .= " AND ({$cadet_conditions})";
    }

    $sql .= " GROUP BY ar.session_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($all_params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);


    foreach ($rows as $row) {
        $sid = (int)$row['session_id'];
        $p = (int)$row['count_p'];
        $a = (int)$row['count_a'];
        $l = (int)$row['count_l'];
        $e = (int)$row['count_e'];

        $totals[$sid] = [
            'p'            => $p,
            'a'            => $a,
            'l'            => $l,
            'e'            => $e,
            'total_marked' => $p + $a + $l + $e,
        ];
    }

    return $totals;
}
