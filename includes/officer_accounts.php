<?php
// Login accounts for Platoon Leader officers.
// An active Platoon Leader officer gets a generated login: role platoon_leader, status approved,
// must change the password at first login. These functions do NOT start a transaction;
// the calling page wraps them in its own.

// Next username in the same format the registration page uses, e.g. PL-2026-0001
function officer_next_username(PDO $pdo): string {
    $prefix = 'PL';
    $year = date('Y');
    $stmt = $pdo->prepare("
        SELECT username FROM users
        WHERE username LIKE ?
        ORDER BY username DESC
        LIMIT 1 FOR UPDATE
    ");
    $stmt->execute(["{$prefix}-{$year}-%"]);
    $last = $stmt->fetch();

    $nextSeq = 1;
    if ($last) {
        $parts = explode('-', $last['username']);
        if (isset($parts[2]) && is_numeric($parts[2])) {
            $nextSeq = ((int)$parts[2]) + 1;
        }
    }
    return sprintf('%s-%s-%04d', $prefix, $year, $nextSeq);
}

// Meets the password rules (10+ characters with upper case, lower case and a digit)
function officer_temp_password(): string {
    return 'Unit' . bin2hex(random_bytes(4)) . '#7';
}

function officer_display_name(array $o): string {
    $mid = !empty($o['middle_name']) ? ' ' . $o['middle_name'] : '';
    return $o['last_name'] . ', ' . $o['first_name'] . $mid;
}

// Create the login for one Platoon Leader officer.
// $o needs: id, last_name, first_name, middle_name, company_id, platoon_id
// Returns ['ok' => bool, 'credential' => array|null, 'note' => string]
function officer_create_pl_account(PDO $pdo, array $o, int $actorId): array {
    $platoonId = (int)$o['platoon_id'];

    $stmt = $pdo->prepare("SELECT co.name AS company_name, p.name AS platoon_name FROM platoons p JOIN companies co ON co.id = p.company_id WHERE p.id = ?");
    $stmt->execute([$platoonId]);
    $pl = $stmt->fetch();
    $label = $pl ? $pl['company_name'] . ' - ' . $pl['platoon_name'] : 'Platoon #' . $platoonId;

    // The platoon may already have a login (for example the leader registered on their own).
    // Never create a second one. If it belongs to this same person, link it to the officer and show it.
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.status, u.first_name, u.last_name
        FROM users u
        JOIN user_assignments ua ON ua.user_id = u.id
        WHERE u.role = 'platoon_leader' AND u.status IN ('pending', 'approved') AND ua.platoon_id = ?
        LIMIT 1
    ");
    $stmt->execute([$platoonId]);
    $existing = $stmt->fetch();
    if ($existing) {
        $sameName = mb_strtolower(trim($existing['last_name'])) === mb_strtolower(trim($o['last_name']))
                 && mb_strtolower(trim($existing['first_name'])) === mb_strtolower(trim($o['first_name']));

        $taken = $pdo->prepare("SELECT id FROM officers WHERE user_id = ? AND id <> ? LIMIT 1");
        $taken->execute([(int)$existing['id'], (int)$o['id']]);

        if ($sameName && !$taken->fetch()) {
            $pdo->prepare("UPDATE officers SET user_id = ? WHERE id = ?")->execute([(int)$existing['id'], (int)$o['id']]);
            log_audit($pdo, $actorId, 'link_officer_login', 'user', (int)$existing['id'],
                'Linked existing login ' . $existing['username'] . ' to officer #' . (int)$o['id'] . ' (' . officer_display_name($o) . ')');
            return [
                'ok' => true,
                'credential' => [
                    'officer'  => officer_display_name($o),
                    'platoon'  => $label,
                    'username' => $existing['username'],
                    'password' => null, // an existing account: its password is not known to the system
                    'existing' => true,
                    'status'   => $existing['status'],
                ],
                'note' => '',
            ];
        }
        return ['ok' => false, 'credential' => null,
                'note' => officer_display_name($o) . ': this platoon already has a login account (' . $existing['username'] . ') that belongs to someone else'];
    }

    $username = officer_next_username($pdo);
    $password = officer_temp_password();

    // Officers have no student number or email, but both columns are required and unique,
    // so the username is used for the student number and a placeholder address for the email.
    $ins = $pdo->prepare("
        INSERT INTO users (
            username, password_hash, first_name, middle_name, last_name,
            student_number, email, role, status, must_change_password,
            reviewed_by, reviewed_at, created_at
        ) VALUES (
            :username, :password_hash, :first_name, :middle_name, :last_name,
            :student_number, :email, 'platoon_leader', 'approved', 1,
            :reviewed_by, NOW(), NOW()
        )
    ");
    $ins->execute([
        'username'       => $username,
        'password_hash'  => password_hash($password, PASSWORD_DEFAULT),
        'first_name'     => $o['first_name'],
        'middle_name'    => !empty($o['middle_name']) ? $o['middle_name'] : null,
        'last_name'      => $o['last_name'],
        'student_number' => $username,
        'email'          => strtolower($username) . '@officer.unitsync.local',
        'reviewed_by'    => $actorId,
    ]);
    $userId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO user_assignments (user_id, company_id, platoon_id, created_at) VALUES (?, ?, ?, NOW())")
        ->execute([$userId, (int)$o['company_id'], $platoonId]);

    $pdo->prepare("UPDATE officers SET user_id = ? WHERE id = ?")->execute([$userId, (int)$o['id']]);

    log_audit($pdo, $actorId, 'create_officer_login', 'user', $userId,
        "Generated login {$username} for officer #" . (int)$o['id'] . ' (' . officer_display_name($o) . ')');

    return [
        'ok' => true,
        'credential' => [
            'officer'  => officer_display_name($o),
            'platoon'  => $label,
            'username' => $username,
            'password' => $password,
        ],
        'note' => '',
    ];
}

// Keep the login in step after an officer is edited.
// $old needs: role, status, user_id.   $new needs: id, last_name, first_name, middle_name, role, status, company_id, platoon_id
// Only a change in the officer's own role or status switches the account on or off, so an account an
// Administrator deactivated is not switched back on just because S1 corrected a name.
// Returns ['credential' => array|null, 'messages' => string[]]
function officer_sync_login(PDO $pdo, array $old, array $new, int $actorId): array {
    $result = ['credential' => null, 'messages' => []];

    $wasActivePl = $old['role'] === 'platoon_leader' && $old['status'] === 'active';
    $isActivePl  = $new['role'] === 'platoon_leader' && $new['status'] === 'active';
    $userId = !empty($old['user_id']) ? (int)$old['user_id'] : 0;

    if ($userId > 0) {
        $pdo->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ? WHERE id = ?")
            ->execute([$new['first_name'], $new['middle_name'] !== '' ? $new['middle_name'] : null, $new['last_name'], $userId]);

        if ($wasActivePl && !$isActivePl) {
            $stmt = $pdo->prepare("UPDATE users SET status = 'deactivated', deactivation_reason = ? WHERE id = ? AND status = 'approved'");
            $stmt->execute(['Officer record was set to inactive or moved to another role.', $userId]);
            if ($stmt->rowCount() > 0) {
                log_audit($pdo, $actorId, 'deactivate_officer_login', 'user', $userId, 'Login deactivated because officer #' . (int)$new['id'] . ' is no longer an active Platoon Leader');
                $result['messages'][] = 'The officer\'s login account was deactivated.';
            }
        } elseif (!$wasActivePl && $isActivePl) {
            $stmt = $pdo->prepare("UPDATE users SET status = 'approved', deactivation_reason = NULL WHERE id = ? AND status = 'deactivated'");
            $stmt->execute([$userId]);
            if ($stmt->rowCount() > 0) {
                log_audit($pdo, $actorId, 'reactivate_officer_login', 'user', $userId, 'Login reactivated for officer #' . (int)$new['id']);
                $result['messages'][] = 'The officer\'s login account was reactivated with the same username and password.';
            }
        }

        if ($isActivePl) {
            $pdo->prepare("
                INSERT INTO user_assignments (user_id, company_id, platoon_id, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE company_id = VALUES(company_id), platoon_id = VALUES(platoon_id)
            ")->execute([$userId, (int)$new['company_id'], (int)$new['platoon_id']]);
        }
        return $result;
    }

    if ($isActivePl) {
        $created = officer_create_pl_account($pdo, $new, $actorId);
        if ($created['ok']) {
            $result['credential'] = $created['credential'];
            $result['messages'][] = !empty($created['credential']['existing'])
                ? 'This officer was linked to the existing login account ' . $created['credential']['username'] . '.'
                : 'A login account was generated. Copy the temporary password from the roster page.';
        } else {
            $result['messages'][] = 'No login account was generated. ' . $created['note'] . '.';
        }
    }
    return $result;
}

// Keep generated credentials in the session until S1 saves or dismisses them (shown on the officer roster)
function officer_credentials_stash(array $credential): void {
    if (!isset($_SESSION['officer_credentials']) || !is_array($_SESSION['officer_credentials'])) {
        $_SESSION['officer_credentials'] = [];
    }
    $_SESSION['officer_credentials'][] = $credential;
}