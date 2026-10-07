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

    // Action: Bulk Generate Weekly Sessions
    if ($action === 'bulk_generate') {
        $start_date = trim($_POST['start_date'] ?? '');
        $count = (int)($_POST['count'] ?? 15);
        $prefix = trim($_POST['prefix'] ?? 'Session ');

        if ($start_date === '') {
            $errors['start_date'] = 'Start date is required for generation.';
        }
        if ($count < 1 || $count > 30) {
            $errors['count'] = 'Session count must be between 1 and 30.';
        }
        if ($prefix === '') {
            $prefix = 'Session ';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $start_ts = strtotime($start_date);
                for ($i = 1; $i <= $count; $i++) {
                    $session_ts = strtotime("+" . (($i - 1) * 7) . " days", $start_ts);
                    $sess_date = date('Y-m-d', $session_ts);
                    $sess_label = $prefix . $i;

                    $stmt = $pdo->prepare("
                        INSERT INTO training_sessions (term_id, session_date, label, status)
                        VALUES (:term_id, :session_date, :label, 'scheduled')
                    ");
                    $stmt->execute([
                        'term_id' => $post_term_id,
                        'session_date' => $sess_date,
                        'label' => $sess_label
                    ]);
                }

                log_audit(
                    $pdo,
                    $admin['id'],
                    'bulk_generate_sessions',
                    'training_session',
                    null,
                    json_encode([
                        'term_id' => $post_term_id,
                        'count' => $count,
                        'start_date' => $start_date,
                        'prefix' => $prefix
                    ])
                );

                $pdo->commit();
                set_flash('success', "Successfully generated {$count} weekly training sessions.");
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
                <p style="font-size: 13px; color: var(--gray-700); margin-bottom: 16px;">Automatically generate sequential weekly drill dates starting from a designated start day.</p>
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

                    <div class="form-row">
                        <div class="form-group">
                            <label for="gen_count">Number of Sessions</label>
                            <input type="number" id="gen_count" name="count" min="1" max="30" class="form-control <?= isset($errors['count']) ? 'is-invalid' : '' ?>" value="<?= (int)($_POST['count'] ?? 15) ?>" required>
                            <span class="field-hint">Default: 15 sessions.</span>
                            <?php if (isset($errors['count'])): ?><span class="field-error"><?= e($errors['count']) ?></span><?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="gen_prefix">Label Prefix</label>
                            <input type="text" id="gen_prefix" name="prefix" class="form-control" value="<?= e($_POST['prefix'] ?? 'Session ') ?>" required>
                            <span class="field-hint">e.g. Session 1, Session 2...</span>
                        </div>
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
            <h2 class="card-title" style="margin-bottom: 0;">Training Sessions Calendar (<?= count($sessions) ?> total)</h2>
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
                            <th>Label</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sessions as $idx => $s): ?>
                            <tr class="<?= $s['status'] === 'cancelled' ? 'table-row-cancelled' : '' ?>">
                                <td><?= $idx + 1 ?></td>
                                <td><strong><?= e($s['session_date']) ?></strong></td>
                                <td><?= date('l', strtotime($s['session_date'])) ?></td>
                                <td><?= e($s['label']) ?></td>
                                <td>
                                    <?php if ($s['status'] === 'held'): ?>
                                        <span class="badge badge-held">Held</span>
                                    <?php elseif ($s['status'] === 'cancelled'): ?>
                                        <span class="badge badge-cancelled">Cancelled</span>
                                    <?php else: ?>
                                        <span class="badge badge-scheduled">Scheduled</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                        <a href="<?= BASE_URL ?>/admin/sessions.php?term_id=<?= (int)$term['id'] ?>&edit=<?= (int)$s['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>

                                        <?php if ($s['status'] !== 'cancelled'): ?>
                                            <form method="POST" action="<?= BASE_URL ?>/admin/sessions.php" class="cancel-session-form" data-session-label="<?= e($s['label']) ?>" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="set_status">
                                                <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">
                                                <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                                                <input type="hidden" name="status" value="cancelled">
                                                <button type="submit" class="btn btn-danger btn-sm">Cancel</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" action="<?= BASE_URL ?>/admin/sessions.php" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="set_status">
                                                <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">
                                                <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                                                <input type="hidden" name="status" value="scheduled">
                                                <button type="submit" class="btn btn-secondary btn-sm">Re-schedule</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($s['status'] !== 'held'): ?>
                                            <form method="POST" action="<?= BASE_URL ?>/admin/sessions.php" style="display:inline;">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="set_status">
                                                <input type="hidden" name="term_id" value="<?= (int)$term['id'] ?>">
                                                <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
                                                <input type="hidden" name="status" value="held">
                                                <button type="submit" class="btn btn-success btn-sm">Mark Held</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
