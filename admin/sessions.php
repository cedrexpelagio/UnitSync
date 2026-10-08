<?php
// Admin Training Sessions Management (Stage PL-1, Step A - Plain HTML & PHP)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$admin = current_user();
$errors = [];

// Determine selected term
$selected_term_id = isset($_GET['term_id']) ? (int)$_GET['term_id'] : 0;
$term = null;

if ($selected_term_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM terms WHERE id = ?");
    $stmt->execute([$selected_term_id]);
    $term = $stmt->fetch();
}

// Default to active term if none specified or not found
if (!$term) {
    $stmt = $pdo->query("SELECT * FROM terms WHERE is_active = 1 LIMIT 1");
    $term = $stmt->fetch();
    if ($term) {
        $selected_term_id = (int)$term['id'];
    }
}

// Fetch all available terms for dropdown/selector
$stmt = $pdo->query("SELECT id, name, is_active FROM terms ORDER BY start_date DESC");
$all_terms = $stmt->fetchAll();

// Edit session state
$edit_session_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing_session = null;
if ($edit_session_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM training_sessions WHERE id = ?");
    $stmt->execute([$edit_session_id]);
    $editing_session = $stmt->fetch();
    if ($editing_session) {
        $selected_term_id = (int)$editing_session['term_id'];
        if (!$term || (int)$term['id'] !== $selected_term_id) {
            $stmt = $pdo->prepare("SELECT * FROM terms WHERE id = ?");
            $stmt->execute([$selected_term_id]);
            $term = $stmt->fetch();
        }
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        redirect('admin/sessions.php' . ($selected_term_id ? '?term_id=' . $selected_term_id : ''));
    }

    $action = $_POST['action'] ?? '';
    $post_term_id = (int)($_POST['term_id'] ?? 0);

    // Verify term
    $stmt = $pdo->prepare("SELECT * FROM terms WHERE id = ?");
    $stmt->execute([$post_term_id]);
    $post_term = $stmt->fetch();

    if (!$post_term && in_array($action, ['create_session', 'bulk_generate'], true)) {
        set_flash('error', 'Selected term does not exist.');
        redirect('admin/sessions.php');
    }

    // Action: Create Single Session
    if ($action === 'create_session') {
        $session_date = trim($_POST['session_date'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $status = trim($_POST['status'] ?? 'scheduled');

        if ($session_date === '') {
            $errors['session_date'] = 'Session date is required.';
        }
        if ($label === '') {
            $errors['label'] = 'Session label is required.';
        }
        if (!in_array($status, ['scheduled', 'held', 'cancelled'], true)) {
            $status = 'scheduled';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO training_sessions (term_id, session_date, label, status)
                    VALUES (:term_id, :session_date, :label, :status)
                ");
                $stmt->execute([
                    'term_id' => $post_term_id,
                    'session_date' => $session_date,
                    'label' => $label,
                    'status' => $status
                ]);
                $new_id = (int)$pdo->lastInsertId();

                log_audit(
                    $pdo,
                    $admin['id'],
                    'create_session',
                    'training_session',
                    $new_id,
                    json_encode([
                        'term_id' => $post_term_id,
                        'session_date' => $session_date,
                        'label' => $label,
                        'status' => $status
                    ])
                );

                set_flash('success', 'Training session "' . $label . '" added successfully.');
                redirect('admin/sessions.php?term_id=' . $post_term_id);
            } catch (Exception $e) {
                set_flash('error', 'Database error: ' . $e->getMessage());
                redirect('admin/sessions.php?term_id=' . $post_term_id);
            }
        }
    }

    // Action: Bulk Generate Weekly Training Days (each day has one or more named sessions)
    if ($action === 'bulk_generate') {
        $start_date = trim($_POST['start_date'] ?? '');
        $count = (int)($_POST['count'] ?? 15);

        // Session names for each training day, typed in one box and separated by commas.
        // They are used exactly as typed (no numbers added).
        $raw_names = $_POST['names'] ?? '';
        $names = [];
        if (is_string($raw_names)) {
            foreach (preg_split('/[,;\r\n]+/', $raw_names) as $n) {
                $n = trim((string)preg_replace('/\s+/u', ' ', $n));
                if ($n === '') continue;
                $key = mb_strtolower($n);
                if (!isset($names[$key])) $names[$key] = $n; // ignore a name typed twice
            }
        }
        $names = array_values($names);

        if ($start_date === '') {
            $errors['start_date'] = 'Start date is required for generation.';
        } else {
            $sd = DateTime::createFromFormat('Y-m-d', $start_date);
            if (!$sd || $sd->format('Y-m-d') !== $start_date) {
                $errors['start_date'] = 'Start date is not a valid date.';
            }
        }
        if ($count < 1 || $count > 30) {
            $errors['count'] = 'Number of weeks must be between 1 and 30.';
        }
        if (!$names) {
            $errors['names'] = 'Enter at least one session name.';
        } elseif (count($names) > 10) {
            $errors['names'] = 'You can add at most 10 sessions per training day.';
        } else {
            foreach ($names as $n) {
                if (mb_strlen($n) > 100) {
                    $errors['names'] = 'Each session name must not exceed 100 characters.';
                    break;
                }
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $exists = $pdo->prepare("
                    SELECT id FROM training_sessions
                    WHERE term_id = :term_id AND session_date = :session_date AND label = :label
                    LIMIT 1
                ");
                $insert = $pdo->prepare("
                    INSERT INTO training_sessions (term_id, session_date, label, status)
                    VALUES (:term_id, :session_date, :label, 'scheduled')
                ");

                $created = 0;
                $skipped = 0;
                $start_ts = strtotime($start_date);
                for ($i = 0; $i < $count; $i++) {
                    $sess_date = date('Y-m-d', strtotime('+' . ($i * 7) . ' days', $start_ts));
                    foreach ($names as $label) {
                        $exists->execute(['term_id' => $post_term_id, 'session_date' => $sess_date, 'label' => $label]);
                        if ($exists->fetch()) {
                            $skipped++; // already on the calendar: do not duplicate
                            continue;
                        }
                        $insert->execute(['term_id' => $post_term_id, 'session_date' => $sess_date, 'label' => $label]);
                        $created++;
                    }
                }

                log_audit(
                    $pdo,
                    $admin['id'],
                    'bulk_generate_sessions',
                    'training_session',
                    null,
                    json_encode([
                        'term_id' => $post_term_id,
                        'weeks' => $count,
                        'start_date' => $start_date,
                        'names' => $names,
                        'created' => $created,
                        'skipped' => $skipped
                    ])
                );

                $pdo->commit();

                $msg = "Generated {$count} weekly training day(s) with " . count($names) . " session(s) each ({$created} sessions created).";
                if ($skipped > 0) {
                    $msg .= " {$skipped} already existed and were skipped.";
                }
                set_flash('success', $msg);
                redirect('admin/sessions.php?term_id=' . $post_term_id);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                set_flash('error', 'Generation failed: ' . $e->getMessage());
                redirect('admin/sessions.php?term_id=' . $post_term_id);
            }
        }
    }

    // Action: Update Session
    if ($action === 'update_session') {
        $session_id = (int)($_POST['session_id'] ?? 0);
        $session_date = trim($_POST['session_date'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $status = trim($_POST['status'] ?? 'scheduled');

        if ($session_date === '') {
            $errors['session_date'] = 'Session date is required.';
        }
        if ($label === '') {
            $errors['label'] = 'Session label is required.';
        }
        if (!in_array($status, ['scheduled', 'held', 'cancelled'], true)) {
            $status = 'scheduled';
        }

        // Fetch existing session
        $stmt = $pdo->prepare("SELECT * FROM training_sessions WHERE id = ?");
        $stmt->execute([$session_id]);
        $existing = $stmt->fetch();

        if (!$existing) {
            set_flash('error', 'Session not found.');
            redirect('admin/sessions.php');
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE training_sessions
                    SET session_date = :session_date, label = :label, status = :status
                    WHERE id = :id
                ");
                $stmt->execute([
                    'session_date' => $session_date,
                    'label' => $label,
                    'status' => $status,
                    'id' => $session_id
                ]);

                log_audit(
                    $pdo,
                    $admin['id'],
                    'update_session',
                    'training_session',
                    $session_id,
                    json_encode([
                        'old' => ['session_date' => $existing['session_date'], 'label' => $existing['label'], 'status' => $existing['status']],
                        'new' => ['session_date' => $session_date, 'label' => $label, 'status' => $status]
                    ])
                );

                set_flash('success', 'Session updated successfully.');
                redirect('admin/sessions.php?term_id=' . (int)$existing['term_id']);
            } catch (Exception $e) {
                set_flash('error', 'Database error: ' . $e->getMessage());
                redirect('admin/sessions.php?edit=' . $session_id);
            }
        }
    }

    // Action: Set Status Directly (e.g., Quick Cancel or Mark Held)
    if ($action === 'set_status') {
        $session_id = (int)($_POST['session_id'] ?? 0);
        $new_status = trim($_POST['status'] ?? '');

        if (!in_array($new_status, ['scheduled', 'held', 'cancelled'], true)) {
            set_flash('error', 'Invalid status value.');
            redirect('admin/sessions.php?term_id=' . $post_term_id);
        }

        $stmt = $pdo->prepare("SELECT * FROM training_sessions WHERE id = ?");
        $stmt->execute([$session_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $pdo->prepare("UPDATE training_sessions SET status = :status WHERE id = :id");
            $stmt->execute(['status' => $new_status, 'id' => $session_id]);

            log_audit(
                $pdo,
                $admin['id'],
                'change_session_status',
                'training_session',
                $session_id,
                json_encode([
                    'from' => $existing['status'],
                    'to' => $new_status
                ])
            );

            set_flash('success', "Session {$existing['label']} status changed to {$new_status}.");
            redirect('admin/sessions.php?term_id=' . (int)$existing['term_id']);
        }
    }

    // Action: Set status for every session on one training day
    if ($action === 'set_day_status') {
        $day = trim($_POST['session_date'] ?? '');
        $new_status = trim($_POST['status'] ?? '');
        $dt = DateTime::createFromFormat('Y-m-d', $day);

        if (!in_array($new_status, ['scheduled', 'held', 'cancelled'], true) || !$dt || $dt->format('Y-m-d') !== $day) {
            set_flash('error', 'Invalid day or status.');
            redirect('admin/sessions.php?term_id=' . $post_term_id);
        }

        // Only touch sessions that actually need the change; an individually cancelled
        // session stays cancelled when the day is marked held.
        if ($new_status === 'held') {
            $only = "AND status = 'scheduled'";
        } elseif ($new_status === 'scheduled') {
            $only = "AND status = 'cancelled'";
        } else {
            $only = "AND status <> 'cancelled'";
        }

        try {
            $stmt = $pdo->prepare("SELECT id FROM training_sessions WHERE term_id = ? AND session_date = ? $only");
            $stmt->execute([$post_term_id, $day]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($ids) {
                $upd = $pdo->prepare("UPDATE training_sessions SET status = :status WHERE term_id = :term_id AND session_date = :d $only");
                $upd->execute(['status' => $new_status, 'term_id' => $post_term_id, 'd' => $day]);

                log_audit(
                    $pdo,
                    $admin['id'],
                    'change_day_status',
                    'training_session',
                    null,
                    json_encode(['term_id' => $post_term_id, 'session_date' => $day, 'to' => $new_status, 'session_ids' => $ids])
                );
                set_flash('success', count($ids) . ' session(s) on ' . $day . ' set to ' . $new_status . '.');
            } else {
                set_flash('info', 'No sessions on ' . $day . ' needed that change.');
            }
        } catch (Exception $e) {
            set_flash('error', 'Database error: ' . $e->getMessage());
        }
        redirect('admin/sessions.php?term_id=' . $post_term_id);
    }
}

// Fetch sessions for current term in date order
$sessions = [];
if ($term) {
    $stmt = $pdo->prepare("
        SELECT * FROM training_sessions
        WHERE term_id = ?
        ORDER BY session_date ASC, id ASC
    ");
    $stmt->execute([(int)$term['id']]);
    $sessions = $stmt->fetchAll();
}

// Group sessions by date: one calendar row per training day, with its sessions inside
$days = [];
foreach ($sessions as $s) {
    $days[$s['session_date']][] = $s;
}

// Small form that changes the status of every session on one training day
function day_status_form(int $term_id, string $date, string $to, string $text, string $btn_class, string $title, ?string $confirm_label = null): string {
    $cls = $confirm_label !== null ? ' class="cancel-session-form" data-session-label="' . e($confirm_label) . '"' : '';
    return '<form method="POST" action="' . BASE_URL . '/admin/sessions.php"' . $cls . ' style="display:inline;">'
        . csrf_field()
        . '<input type="hidden" name="action" value="set_day_status">'
        . '<input type="hidden" name="term_id" value="' . $term_id . '">'
        . '<input type="hidden" name="session_date" value="' . e($date) . '">'
        . '<input type="hidden" name="status" value="' . e($to) . '">'
        . '<button type="submit" class="btn ' . e($btn_class) . ' btn-sm" title="' . e($title) . '">' . e($text) . '</button>'
        . '</form>';
}

$page_title = 'Training Sessions';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1>Training Sessions Management</h1>
        <p>Manage the ROTC training calendar, schedule weekly sessions, and track training status.</p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/admin/terms.php" class="btn btn-secondary btn-sm">&larr; Manage Academic Terms</a>
    </div>
</div>

<!-- Term Selector Card -->
<div class="card" style="padding: 16px 24px; margin-bottom: 20px;">
    <form method="GET" action="<?= BASE_URL ?>/admin/sessions.php" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <label for="term_select" style="font-weight: 600; color: var(--green-900);">Select Academic Term:</label>
        <select id="term_select" name="term_id" class="form-control" style="width: auto; min-width: 280px;" onchange="this.form.submit()">
            <?php if (empty($all_terms)): ?>
                <option value="">-- No terms available --</option>
            <?php else: ?>
                <?php foreach ($all_terms as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= ($term && (int)$term['id'] === (int)$t['id']) ? 'selected' : '' ?>>
                        <?= e($t['name']) ?><?= $t['is_active'] ? ' (ACTIVE TERM)' : '' ?>
                    </option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
        <noscript><button type="submit" class="btn btn-secondary btn-sm">Switch Term</button></noscript>
    </form>
</div>

<?php if (!$term): ?>
    <div class="banner banner-warning">
        <svg class="banner-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <div>
            <strong>Notice:</strong> There are no terms configured yet.
            Please <a href="<?= BASE_URL ?>/admin/terms.php">create and activate a term</a> before managing training sessions.
        </div>
    </div>
<?php else: ?>

    <div class="banner banner-info" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
        <div>
            <strong>Current Term:</strong> <?= e($term['name']) ?>
            <span style="margin: 0 8px; color: var(--gray-300);">|</span>
            <strong>Calendar:</strong> <?= e($term['start_date']) ?> to <?= e($term['end_date']) ?>
            <span style="margin: 0 8px; color: var(--gray-300);">|</span>
            <?php if ($term['is_active']): ?>
                <span class="badge badge-active">Active Term</span>
            <?php else: ?>
                <span class="badge badge-inactive">Inactive Term</span>
            <?php endif; ?>
        </div>
        <div>
            <a href="<?= BASE_URL ?>/admin/terms.php?edit=<?= (int)$term['id'] ?>" class="btn btn-secondary btn-sm" style="background: white;">Edit Term Dates</a>
        </div>
    </div>

    <?php if ($editing_session): ?>
        <!-- Edit Single Session Form Card -->
        <div class="card">
            <h2 class="card-title">Edit Session: <?= e($editing_session['label']) ?></h2>
            <form method="POST" action="<?= BASE_URL ?>/admin/sessions.php?edit=<?= (int)$editing_session['id'] ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_session">
                <input type="hidden" name="session_id" value="<?= (int)$editing_session['id'] ?>">
                <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_label">Session Label</label>
                        <input type="text" id="edit_label" name="label" class="form-control <?= isset($errors['label']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['label'] ?? $editing_session['label']) ?>" placeholder="e.g. Session 1, Field Training" required>
                        <?php if (isset($errors['label'])): ?><span class="field-error"><?= e($errors['label']) ?></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="edit_session_date">Session Date</label>
                        <input type="date" id="edit_session_date" name="session_date" class="form-control <?= isset($errors['session_date']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['session_date'] ?? $editing_session['session_date']) ?>" required>
                        <?php if (isset($errors['session_date'])): ?><span class="field-error"><?= e($errors['session_date']) ?></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="edit_status">Session Status</label>
                        <select id="edit_status" name="status" class="form-control">
                            <option value="scheduled" <?= ($editing_session['status'] === 'scheduled') ? 'selected' : '' ?>>Scheduled</option>
                            <option value="held" <?= ($editing_session['status'] === 'held') ? 'selected' : '' ?>>Held</option>
                            <option value="cancelled" <?= ($editing_session['status'] === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 8px; margin-top: 16px;">
                    <button type="submit" class="btn btn-primary">Save Session Changes</button>
                    <a href="<?= BASE_URL ?>/admin/sessions.php?term_id=<?= (int)$term['id'] ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    <?php else: ?>
        <!-- Two Column Forms on Desktop: Generator and Single Add -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 24px;">
            <!-- Bulk Generate N Weekly Sessions Card -->
            <div class="card" style="margin-bottom: 0;">
                <h2 class="card-title">Generate Weekly Training Sessions</h2>
                <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">Generate weekly training days. Each training day gets the sessions you list below, named exactly as you type them.</p>
                <form method="POST" action="<?= BASE_URL ?>/admin/sessions.php?term_id=<?= (int)$term['id'] ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="bulk_generate">
                    <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">

                    <div class="form-group">
                        <label for="gen_start_date">First Training Date</label>
                        <input type="date" id="gen_start_date" name="start_date" class="form-control <?= isset($errors['start_date']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['start_date'] ?? $term['start_date']) ?>" required>
                        <span class="field-hint">e.g. The first training Saturday of the semester.</span>
                        <?php if (isset($errors['start_date'])): ?><span class="field-error"><?= e($errors['start_date']) ?></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="gen_count">Number of Weeks</label>
                        <input type="number" id="gen_count" name="count" min="1" max="30" class="form-control <?= isset($errors['count']) ? 'is-invalid' : '' ?>" value="<?= (int)($_POST['count'] ?? 15) ?>" required>
                        <span class="field-hint">How many weekly training days to create. Default: 15.</span>
                        <?php if (isset($errors['count'])): ?><span class="field-error"><?= e($errors['count']) ?></span><?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="gen_names">Sessions on each training day</label>
                        <input type="text" id="gen_names" name="names" class="form-control <?= isset($errors['names']) ? 'is-invalid' : '' ?>" value="<?= e(is_string($_POST['names'] ?? null) ? $_POST['names'] : '') ?>" maxlength="500" placeholder="e.g. Session 1, Session 2, Session 3" required>
                        <span class="field-hint">Type the sessions for one training day, separated by commas. Each one is created on every training date exactly as typed (up to 10 per day).</span>
                        <?php if (isset($errors['names'])): ?><span class="field-error"><?= e($errors['names']) ?></span><?php endif; ?>
                    </div>

                    <div style="margin-top: 16px;">
                        <button type="submit" class="btn btn-primary">Generate Weekly Sessions</button>
                    </div>
                </form>
            </div>

            <!-- Add Single Session Card -->
            <div class="card" style="margin-bottom: 0;">
                <h2 class="card-title">Add Single Session</h2>
                <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">Add a standalone makeup drill, special formation, or individual session.</p>
                <form method="POST" action="<?= BASE_URL ?>/admin/sessions.php?term_id=<?= (int)$term['id'] ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_session">
                    <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">

                    <div class="form-group">
                        <label for="single_label">Session Label</label>
                        <input type="text" id="single_label" name="label" class="form-control <?= isset($errors['label']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['label'] ?? '') ?>" placeholder="e.g. Session 16, Orientation" required>
                        <?php if (isset($errors['label'])): ?><span class="field-error"><?= e($errors['label']) ?></span><?php endif; ?>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="single_date">Date</label>
                            <input type="date" id="single_date" name="session_date" class="form-control <?= isset($errors['session_date']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['session_date'] ?? '') ?>" required>
                            <?php if (isset($errors['session_date'])): ?><span class="field-error"><?= e($errors['session_date']) ?></span><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="single_status">Status</label>
                            <select id="single_status" name="status" class="form-control">
                                <option value="scheduled">Scheduled</option>
                                <option value="held">Held</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top: 16px;">
                        <button type="submit" class="btn btn-secondary">Add Session</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Sessions List Table Card -->
    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
            <h2 class="card-title" style="margin-bottom: 0;">Training Sessions Calendar (<?= count($days) ?> training days, <?= count($sessions) ?> sessions)</h2>
            <span style="font-size: 13px; color: var(--gray-700);">Term: <strong><?= e($term['name']) ?></strong></span>
        </div>

        <?php if (empty($sessions)): ?>
            <div class="empty-state">
                <p>No training sessions scheduled for this term yet. Use the weekly generator or add form above.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Date</th>
                            <th>Day</th>
                            <th>Sessions</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $day_no = 0; ?>
                        <?php foreach ($days as $date => $day_sessions): ?>
                            <?php
                                $day_no++;
                                $n = count($day_sessions);
                                $by = ['scheduled' => 0, 'held' => 0, 'cancelled' => 0];
                                foreach ($day_sessions as $ds) {
                                    $by[$ds['status']]++;
                                }
                                $status_names = ['scheduled' => 'Scheduled', 'held' => 'Held', 'cancelled' => 'Cancelled'];
                            ?>
                            <tr class="<?= $by['cancelled'] === $n ? 'table-row-cancelled' : '' ?>">
                                <td><?= $day_no ?></td>
                                <td><strong><?= e($date) ?></strong></td>
                                <td><?= date('l', strtotime($date)) ?></td>
                                <td>
                                    <div style="display: flex; flex-wrap: wrap; gap: 6px;">
                                        <?php foreach ($day_sessions as $s): ?>
                                            <a href="<?= BASE_URL ?>/admin/sessions.php?term_id=<?= (int)$term['id'] ?>&edit=<?= (int)$s['id'] ?>"
                                               class="badge badge-<?= e($s['status']) ?>"
                                               style="text-decoration: none;<?= $s['status'] === 'cancelled' ? ' text-decoration: line-through;' : '' ?>"
                                               title="Edit <?= e($s['label']) ?> (<?= e($status_names[$s['status']]) ?>)"><?= e($s['label']) ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($by['held'] === $n): ?>
                                        <span class="badge badge-held">Held</span>
                                    <?php elseif ($by['cancelled'] === $n): ?>
                                        <span class="badge badge-cancelled">Cancelled</span>
                                    <?php elseif ($by['scheduled'] === $n): ?>
                                        <span class="badge badge-scheduled">Scheduled</span>
                                    <?php else: ?>
                                        <?php
                                            $parts = [];
                                            foreach ($by as $k => $cnt) {
                                                if ($cnt > 0) $parts[] = $cnt . ' ' . $status_names[$k];
                                            }
                                        ?>
                                        <span style="font-size: 13px;"><?= e(implode(', ', $parts)) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                        <?php if ($by['cancelled'] < $n): ?>
                                            <?= day_status_form((int)$term['id'], $date, 'cancelled', 'Cancel', 'btn-danger', 'Cancel every session on this date', 'all sessions on ' . $date) ?>
                                        <?php endif; ?>
                                        <?php if ($by['cancelled'] > 0): ?>
                                            <?= day_status_form((int)$term['id'], $date, 'scheduled', 'Re-schedule', 'btn-secondary', 'Put cancelled sessions on this date back to Scheduled') ?>
                                        <?php endif; ?>
                                        <?php if ($by['scheduled'] > 0): ?>
                                            <?= day_status_form((int)$term['id'], $date, 'held', 'Mark Held', 'btn-success', 'Mark every scheduled session on this date as Held') ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="margin-top: 12px; font-size: 12px; color: var(--gray-700);">
                Cancel, Re-schedule and Mark Held apply to every session on that date. Click a session label to edit it or change just that one.
            </p>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>