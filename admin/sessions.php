<?php
// Admin Training Sessions Management
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_ui.php';

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
$totals = ['scheduled' => 0, 'held' => 0, 'cancelled' => 0];
foreach ($sessions as $s) {
    $days[$s['session_date']][] = $s;
    if (isset($totals[$s['status']])) $totals[$s['status']]++;
}

// ... and by month, so a long term is easy to scan
$months = [];
foreach ($days as $date => $list) {
    $months[substr($date, 0, 7)][$date] = $list;
}

// Small form that changes the status of every session on one training day
function day_status_form(int $term_id, string $date, string $to, string $text, string $btn_class, string $title, ?string $confirm_label = null): string {
    $cls = $confirm_label !== null ? ' class="cancel-session-form" data-session-label="' . e($confirm_label) . '"' : '';
    return '<form method="POST" action="' . BASE_URL . '/admin/sessions.php"' . $cls . '>'
        . csrf_field()
        . '<input type="hidden" name="action" value="set_day_status">'
        . '<input type="hidden" name="term_id" value="' . $term_id . '">'
        . '<input type="hidden" name="session_date" value="' . e($date) . '">'
        . '<input type="hidden" name="status" value="' . e($to) . '">'
        . '<button type="submit" class="btn ' . e($btn_class) . ' btn-sm" data-tip="' . e($title) . '">' . e($text) . '</button>'
        . '</form>';
}

$status_names = ['scheduled' => 'Scheduled', 'held' => 'Held', 'cancelled' => 'Cancelled'];
$dot = ['scheduled' => 's', 'held' => 'h', 'cancelled' => 'c'];
$today = date('Y-m-d');
$this_month = date('Y-m');

// Next training day that still has something scheduled
$next_day = null;
foreach ($days as $date => $list) {
    if ($date >= $today) {
        foreach ($list as $s) {
            if ($s['status'] === 'scheduled') { $next_day = $date; break 2; }
        }
    }
}

// Day-level chip counts
$chip_counts = ['scheduled' => 0, 'held' => 0, 'cancelled' => 0];
foreach ($days as $list) {
    $present = array_unique(array_column($list, 'status'));
    foreach ($present as $st) if (isset($chip_counts[$st])) $chip_counts[$st]++;
}

// Which tab opens first
$has_post_error = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors);
$posted_action = $_POST['action'] ?? '';
if ($has_post_error && $posted_action === 'bulk_generate') {
    $open_tab = 'generate';
} elseif ($has_post_error && $posted_action === 'create_session') {
    $open_tab = 'add';
} elseif (empty($sessions)) {
    $open_tab = 'generate';
} else {
    $open_tab = 'calendar';
}

$non_cancelled = $totals['held'] + $totals['scheduled'];
$held_pct = $non_cancelled > 0 ? (int)round($totals['held'] / $non_cancelled * 100) : 0;

$page_title = 'Training Sessions';
require_once __DIR__ . '/../includes/header.php';
ad_css();

// ---- Calendar markup (rendered once, placed below) ----
ob_start();
if (empty($sessions)): ?>
    <div class="ad-empty">
        <?= ad_icon('calendar', 36) ?>
        <strong>No training sessions yet</strong>
        <p>The quickest way to start is to generate a full term of weekly sessions in one go.</p>
        <?php if (!$editing_session): ?><a href="#generate" class="btn btn-primary btn-sm" data-goto-tab="generate"><?= ad_icon('zap', 14) ?> Generate weekly sessions</a><?php endif; ?>
    </div>
<?php else: ?>
    <div data-filter>
        <div class="ad-toolbar">
            <div class="ad-chipbar" role="group" aria-label="Filter days">
                <button type="button" class="ad-chip" data-chip="all" aria-pressed="true">All days <b><?= count($days) ?></b></button>
                <button type="button" class="ad-chip" data-chip="scheduled" aria-pressed="false">Has scheduled <b><?= $chip_counts['scheduled'] ?></b></button>
                <button type="button" class="ad-chip" data-chip="held" aria-pressed="false">Has held <b><?= $chip_counts['held'] ?></b></button>
                <button type="button" class="ad-chip" data-chip="cancelled" aria-pressed="false">Has cancelled <b><?= $chip_counts['cancelled'] ?></b></button>
            </div>
            <div class="ad-legend ad-count" aria-label="Legend">
                <span><i class="s"></i>Scheduled</span><span><i class="h"></i>Held</span><span><i class="c"></i>Cancelled</span>
            </div>
        </div>

        <?php foreach ($months as $ym => $month_days):
            $month_sessions = array_sum(array_map('count', $month_days));
            $open_month = ($ym >= $this_month) || $ym === array_key_last($months);
        ?>
            <details class="ad-month" data-section <?= $open_month ? 'open' : '' ?>>
                <summary>
                    <?= e(date('F Y', strtotime($ym . '-01'))) ?>
                    <small><?= e(ad_plural(count($month_days), 'day')) ?> &middot; <?= e(ad_plural($month_sessions, 'session')) ?></small>
                    <?= ad_icon('chevron', 16, 'ad-caret') ?>
                </summary>

                <?php foreach ($month_days as $date => $day_sessions):
                    $n = count($day_sessions);
                    $by = ['scheduled' => 0, 'held' => 0, 'cancelled' => 0];
                    foreach ($day_sessions as $ds) $by[$ds['status']]++;
                    $tags = implode(' ', array_keys(array_filter($by)));
                    $ts = strtotime($date);
                    $classes = [];
                    if ($date === $today) $classes[] = 'is-today';
                    if ($date === $next_day) $classes[] = 'is-next';
                    if ($by['cancelled'] === $n) $classes[] = 'is-cancelled';
                    $overdue = $date < $today && $by['scheduled'] > 0;
                ?>
                    <div class="ad-day <?= e(implode(' ', $classes)) ?>" data-item data-tags="<?= e($tags) ?>"
                         data-search="<?= e(strtolower($date . ' ' . date('l F j', $ts) . ' ' . implode(' ', array_column($day_sessions, 'label')))) ?>">
                        <div class="ad-day-date"><b><?= (int)date('j', $ts) ?></b><span><?= e(date('D', $ts)) ?></span></div>

                        <div class="ad-day-main">
                            <div class="ad-chips">
                                <?php foreach ($day_sessions as $s): ?>
                                    <a href="<?= BASE_URL ?>/admin/sessions.php?term_id=<?= (int)$term['id'] ?>&amp;edit=<?= (int)$s['id'] ?>"
                                       class="ad-sess <?= $s['status'] === 'cancelled' ? 'is-cancelled' : '' ?>"
                                       data-tip="Edit &ldquo;<?= e($s['label']) ?>&rdquo; (<?= e($status_names[$s['status']]) ?>)"><i class="ad-dot <?= $dot[$s['status']] ?>"></i><?= e($s['label']) ?></a>
                                <?php endforeach; ?>
                            </div>
                            <div class="ad-day-meta">
                                <span><?= e(date('l, M j', $ts)) ?></span>
                                <?php if ($date === $today): ?><span class="badge badge-draft">Today</span><?php endif; ?>
                                <?php if ($date === $next_day && $date !== $today): ?><span class="badge badge-scheduled">Next training day</span><?php endif; ?>
                                <?php if ($overdue): ?><span class="badge badge-pending">Past: mark held or cancel</span><?php endif; ?>
                                <?php if ($by['held'] === $n): ?><span class="badge badge-held">Held</span>
                                <?php elseif ($by['cancelled'] === $n): ?><span class="badge badge-cancelled">Cancelled</span>
                                <?php elseif ($by['scheduled'] === $n): ?><span class="badge badge-scheduled">Scheduled</span>
                                <?php else:
                                    $parts = [];
                                    foreach ($by as $k => $cnt) if ($cnt > 0) $parts[] = $cnt . ' ' . strtolower($status_names[$k]);
                                ?><span><?= e(implode(', ', $parts)) ?></span><?php endif; ?>
                            </div>
                        </div>

                        <div class="ad-day-actions">
                            <?php if ($by['scheduled'] > 0): ?>
                                <?= day_status_form((int)$term['id'], $date, 'held', 'Mark held', 'btn-success', 'Mark every scheduled session on this date as Held') ?>
                            <?php endif; ?>
                            <?php if ($by['cancelled'] < $n): ?>
                                <?= day_status_form((int)$term['id'], $date, 'cancelled', 'Cancel day', 'btn-outline-danger', 'Cancel every session on this date', 'all sessions on ' . $date) ?>
                            <?php endif; ?>
                            <?php if ($by['cancelled'] > 0): ?>
                                <?= day_status_form((int)$term['id'], $date, 'scheduled', 'Re-schedule', 'btn-secondary', 'Put cancelled sessions on this date back to Scheduled') ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </details>
        <?php endforeach; ?>

        <div class="ad-empty" data-filter-empty hidden>
            <?= ad_icon('search', 36) ?>
            <strong>No days match this filter</strong>
            <p>Pick "All days" to see the whole calendar again.</p>
        </div>

        <p class="ad-muted" style="margin-top: 14px; font-size: 12.5px;">
            Mark held, Cancel day and Re-schedule apply to every session on that date. Click a session name to edit it or change just that one.
        </p>
    </div>
<?php endif;
$calendar_html = ob_get_clean();
?>
<div class="ad">
    <?php
    ad_head(
        'Training sessions',
        'Build the training calendar for a term, then mark each day as it happens.',
        '<a href="' . BASE_URL . '/admin/terms.php" class="btn btn-secondary btn-sm">' . ad_icon('arrow-left', 14) . ' Terms</a>',
        'sessions'
    );
    ad_guide('sessions', 'From empty term to a working calendar', [
        ['Pick the term', 'Sessions belong to a term. Choose which one you are working on below.'],
        ['Generate the weeks', 'Use Generate weekly: give the first date, how many weeks, and the session names for each day. A preview shows exactly what will be created.'],
        ['Run the calendar', 'After a training day, mark it held. If it is called off, cancel it. Click any session to edit just that one.'],
        ['Add one-offs', 'Use Add one session for a makeup drill or special formation.'],
    ]);
    ?>

    <!-- Term picker -->
    <form method="GET" action="<?= BASE_URL ?>/admin/sessions.php" class="ad-card ad-termbar ad-rise" style="--i:2" data-autosubmit>
        <label for="term_select">Term</label>
        <select id="term_select" name="term_id" class="ad-input">
            <?php if (empty($all_terms)): ?>
                <option value="">No terms available</option>
            <?php else: foreach ($all_terms as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= ($term && (int)$term['id'] === (int)$t['id']) ? 'selected' : '' ?>>
                    <?= e($t['name']) ?><?= $t['is_active'] ? ' (active)' : '' ?>
                </option>
            <?php endforeach; endif; ?>
        </select>
        <?php if ($term): ?>
            <span class="ad-term-dates"><?= ad_icon('calendar', 15) ?> <?= e(date('M j, Y', strtotime($term['start_date']))) ?> &ndash; <?= e(date('M j, Y', strtotime($term['end_date']))) ?></span>
            <span class="badge <?= $term['is_active'] ? 'badge-active' : 'badge-inactive' ?>"><?= $term['is_active'] ? 'Active term' : 'Inactive term' ?></span>
            <a href="<?= BASE_URL ?>/admin/terms.php?edit=<?= (int)$term['id'] ?>" class="btn btn-ghost btn-sm ad-spacer">Edit term dates</a>
        <?php endif; ?>
        <noscript><button type="submit" class="btn btn-secondary btn-sm">Switch term</button></noscript>
    </form>

    <?php if (!$term): ?>
        <div class="ad-empty ad-rise" style="--i:3">
            <?= ad_icon('layers', 36) ?>
            <strong>Create a term first</strong>
            <p>Training sessions live inside a term. Create and activate one, then come back to build its calendar.</p>
            <a href="<?= BASE_URL ?>/admin/terms.php" class="btn btn-primary btn-sm">Go to terms <?= ad_icon('arrow', 14) ?></a>
        </div>
    <?php else: ?>

        <!-- Overview -->
        <section class="ad-card ad-overview ad-rise" style="--i:3" aria-label="Term overview">
            <div class="ad-overview-top">
                <div class="ad-ov"><strong data-count="<?= count($days) ?>"><?= count($days) ?></strong><span>training days</span></div>
                <div class="ad-ov"><strong data-count="<?= count($sessions) ?>"><?= count($sessions) ?></strong><span>sessions</span></div>
                <div class="ad-ov"><strong data-count="<?= $totals['scheduled'] ?>"><?= $totals['scheduled'] ?></strong><span>scheduled</span></div>
                <div class="ad-ov is-held"><strong data-count="<?= $totals['held'] ?>"><?= $totals['held'] ?></strong><span>held</span></div>
                <div class="ad-ov is-cancelled"><strong data-count="<?= $totals['cancelled'] ?>"><?= $totals['cancelled'] ?></strong><span>cancelled</span></div>
            </div>
            <?php if ($sessions): ?>
                <div class="ad-progress-row"><span>Term progress (held of non-cancelled sessions)</span><strong><?= $held_pct ?>%</strong></div>
                <div class="ad-progress"><span style="--p: <?= $held_pct ?>%"></span></div>
            <?php endif; ?>
            <?php if ($next_day): ?>
                <div class="ad-next"><?= ad_icon('clock', 16) ?><span><strong>Next training day:</strong> <?= e(date('l, M j', strtotime($next_day))) ?> (<?= e(ad_plural(count(array_filter($days[$next_day], static fn($s) => $s['status'] === 'scheduled')), 'session')) ?> scheduled)</span></div>
            <?php endif; ?>
        </section>

        <?php if ($editing_session): ?>
            <!-- Edit one session -->
            <section class="ad-panel ad-rise" style="--i:4" aria-labelledby="editTitle">
                <h2 class="ad-panel-title" id="editTitle">Edit session: <?= e($editing_session['label']) ?></h2>
                <p class="ad-panel-sub">Changes apply to this one session only.</p>
                <form method="POST" action="<?= BASE_URL ?>/admin/sessions.php?edit=<?= (int)$editing_session['id'] ?>" data-validate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_session">
                    <input type="hidden" name="session_id" value="<?= (int)$editing_session['id'] ?>">
                    <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">

                    <div class="ad-form-grid">
                        <?= ad_field('edit_label', 'Session name',
                            '<input type="text" id="edit_label" name="label" class="ad-input" required data-msg="Give the session a name." value="' . e($_POST['label'] ?? $editing_session['label']) . '" placeholder="e.g. Session 1, Field Training" aria-describedby="edit_label-err">',
                            $errors['label'] ?? '') ?>
                        <?= ad_field('edit_session_date', 'Date',
                            '<input type="date" id="edit_session_date" name="session_date" class="ad-input" required data-msg="Choose a date." value="' . e($_POST['session_date'] ?? $editing_session['session_date']) . '" aria-describedby="edit_session_date-err">',
                            $errors['session_date'] ?? '') ?>
                        <?= ad_field('edit_status', 'Status',
                            '<select id="edit_status" name="status" class="ad-input">'
                            . implode('', array_map(static fn($k, $v) => '<option value="' . $k . '"' . ((($_POST['status'] ?? $editing_session['status']) === $k) ? ' selected' : '') . '>' . $v . '</option>', array_keys($status_names), $status_names))
                            . '</select>', '', '', false) ?>
                    </div>
                    <div class="ad-actions">
                        <button type="submit" class="btn btn-primary">Save changes</button>
                        <a href="<?= BASE_URL ?>/admin/sessions.php?term_id=<?= (int)$term['id'] ?>" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </section>

            <h2 class="ad-section-title">Calendar</h2>
            <?= $calendar_html ?>

        <?php else: ?>

            <div data-tabs data-active="<?= e($open_tab) ?>">
                <div class="ad-tablist ad-rise" style="--i:4" role="tablist" aria-label="Training session tools">
                    <button type="button" role="tab" class="ad-tab" data-tab="calendar" aria-selected="false"><?= ad_icon('calendar', 16) ?> Calendar <b><?= count($days) ?></b></button>
                    <button type="button" role="tab" class="ad-tab" data-tab="generate" aria-selected="false"><?= ad_icon('zap', 16) ?> Generate weekly</button>
                    <button type="button" role="tab" class="ad-tab" data-tab="add" aria-selected="false"><?= ad_icon('plus', 16) ?> Add one session</button>
                </div>

                <section data-panel="calendar" id="calendar" role="tabpanel"><?= $calendar_html ?></section>

                <section data-panel="generate" id="generate" role="tabpanel">
                    <div class="ad-panel ad-two">
                        <div>
                            <h2 class="ad-panel-title">Generate weekly training sessions</h2>
                            <p class="ad-panel-sub">Creates one training day per week, each with the sessions you list. Sessions that already exist on a date are skipped, so it is safe to run twice.</p>
                        </div>
                        <form method="POST" action="<?= BASE_URL ?>/admin/sessions.php?term_id=<?= (int)$term['id'] ?>" data-validate data-gen>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="bulk_generate">
                            <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">

                            <div class="ad-form-grid">
                                <?= ad_field('start_date', 'First training date',
                                    '<input type="date" id="start_date" name="start_date" class="ad-input" required data-msg="Choose the first training date." value="' . e($_POST['start_date'] ?? $term['start_date']) . '" aria-describedby="start_date-err">',
                                    $errors['start_date'] ?? '', 'For example, the first training Saturday.') ?>
                                <?= ad_field('count', 'Number of weeks',
                                    '<input type="number" id="count" name="count" min="1" max="30" class="ad-input" required data-msg="Enter how many weeks." data-range-msg="Enter a number from 1 to 30." value="' . (int)($_POST['count'] ?? 15) . '" aria-describedby="count-err">',
                                    $errors['count'] ?? '', 'One training day per week. Default 15.') ?>
                            </div>

                            <?= ad_field('names', 'Sessions on each training day',
                                '<input type="text" id="names" name="names" class="ad-input" required maxlength="500" data-msg="Type at least one session name." value="' . e(is_string($_POST['names'] ?? null) ? $_POST['names'] : '') . '" placeholder="e.g. Session 1, Session 2, Session 3" aria-describedby="names-err">',
                                $errors['names'] ?? '', 'Separate names with commas. Each is created on every training date exactly as typed (up to 10 per day).') ?>

                            <div class="ad-preview" data-gen-preview aria-live="polite">
                                <?= ad_icon('info', 18) ?>
                                <div>
                                    <div data-gen-text>Fill in the date, the number of weeks and at least one session name to see what will be created.</div>
                                    <div class="ad-chips" data-gen-chips></div>
                                </div>
                            </div>

                            <div class="ad-actions"><button type="submit" class="btn btn-primary"><?= ad_icon('zap', 14) ?> Generate sessions</button></div>
                        </form>
                    </div>
                </section>

                <section data-panel="add" id="add" role="tabpanel">
                    <div class="ad-panel ad-two">
                        <div>
                            <h2 class="ad-panel-title">Add one session</h2>
                            <p class="ad-panel-sub">For a makeup drill, special formation or any one-off. It appears in the calendar on its date.</p>
                        </div>
                        <form method="POST" action="<?= BASE_URL ?>/admin/sessions.php?term_id=<?= (int)$term['id'] ?>" data-validate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="create_session">
                            <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">

                            <?= ad_field('single_label', 'Session name',
                                '<input type="text" id="single_label" name="label" class="ad-input" required data-msg="Give the session a name." value="' . e($_POST['label'] ?? '') . '" placeholder="e.g. Session 16, Orientation" aria-describedby="single_label-err">',
                                $errors['label'] ?? '') ?>

                            <div class="ad-form-grid">
                                <?= ad_field('single_date', 'Date',
                                    '<input type="date" id="single_date" name="session_date" class="ad-input" required data-msg="Choose a date." value="' . e($_POST['session_date'] ?? '') . '" aria-describedby="single_date-err">',
                                    $errors['session_date'] ?? '', 'Term runs ' . date('M j', strtotime($term['start_date'])) . ' to ' . date('M j, Y', strtotime($term['end_date'])) . '.') ?>
                                <?= ad_field('single_status', 'Status',
                                    '<select id="single_status" name="status" class="ad-input">'
                                    . implode('', array_map(static fn($k, $v) => '<option value="' . $k . '">' . $v . '</option>', array_keys($status_names), $status_names))
                                    . '</select>', '', '', false) ?>
                            </div>
                            <div class="ad-actions"><button type="submit" class="btn btn-primary"><?= ad_icon('plus', 14) ?> Add session</button></div>
                        </form>
                    </div>
                </section>
            </div>

        <?php endif; ?>
    <?php endif; ?>
</div>
<?php ad_js(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
