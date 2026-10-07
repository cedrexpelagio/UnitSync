<?php
// Admin Terms & Semesters Management (Stage PL-1, Step A - Plain HTML & PHP)
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$admin = current_user();
$errors = [];
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing_term = null;

if ($edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM terms WHERE id = ?");
    $stmt->execute([$edit_id]);
    $editing_term = $stmt->fetch();
    if (!$editing_term) {
        set_flash('error', 'Term not found.');
        redirect('admin/terms.php');
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token.');
        redirect('admin/terms.php');
    }

    $action = $_POST['action'] ?? '';

    // Action: Create Term
    if ($action === 'create_term') {
        $name = trim($_POST['name'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');
        $make_active = !empty($_POST['make_active']);

        if ($name === '') {
            $errors['name'] = 'Term name is required.';
        }
        if ($start_date === '') {
            $errors['start_date'] = 'Start date is required.';
        }
        if ($end_date === '') {
            $errors['end_date'] = 'End date is required.';
        }
        if ($start_date !== '' && $end_date !== '' && $end_date < $start_date) {
            $errors['end_date'] = 'End date cannot be before start date.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                if ($make_active) {
                    $pdo->exec("UPDATE terms SET is_active = 0");
                }

                $stmt = $pdo->prepare("
                    INSERT INTO terms (name, start_date, end_date, is_active)
                    VALUES (:name, :start_date, :end_date, :is_active)
                ");
                $stmt->execute([
                    'name' => $name,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'is_active' => $make_active ? 1 : 0
                ]);
                $new_term_id = (int)$pdo->lastInsertId();

                log_audit(
                    $pdo,
                    $admin['id'],
                    'create_term',
                    'term',
                    $new_term_id,
                    json_encode([
                        'name' => $name,
                        'start_date' => $start_date,
                        'end_date' => $end_date,
                        'is_active' => $make_active ? 1 : 0
                    ])
                );

                $pdo->commit();
                set_flash('success', 'Term "' . $name . '" created successfully.' . ($make_active ? ' Set as active term.' : ''));
                redirect('admin/terms.php');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                set_flash('error', 'Database error: ' . $e->getMessage());
                redirect('admin/terms.php');
            }
        }
    }

    // Action: Update Term
    if ($action === 'update_term') {
        $term_id = (int)($_POST['term_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');

        if ($name === '') {
            $errors['name'] = 'Term name is required.';
        }
        if ($start_date === '') {
            $errors['start_date'] = 'Start date is required.';
        }
        if ($end_date === '') {
            $errors['end_date'] = 'End date is required.';
        }
        if ($start_date !== '' && $end_date !== '' && $end_date < $start_date) {
            $errors['end_date'] = 'End date cannot be before start date.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE terms
                    SET name = :name, start_date = :start_date, end_date = :end_date
                    WHERE id = :id
                ");
                $stmt->execute([
                    'name' => $name,
                    'start_date' => $start_date,
                    'end_date' => $end_date,
                    'id' => $term_id
                ]);

                log_audit(
                    $pdo,
                    $admin['id'],
                    'update_term',
                    'term',
                    $term_id,
                    json_encode([
                        'name' => $name,
                        'start_date' => $start_date,
                        'end_date' => $end_date
                    ])
                );

                set_flash('success', 'Term updated successfully.');
                redirect('admin/terms.php');
            } catch (Exception $e) {
                set_flash('error', 'Database error: ' . $e->getMessage());
                redirect('admin/terms.php?edit=' . $term_id);
            }
        }
    }

    // Action: Activate Term
    if ($action === 'activate_term') {
        $term_id = (int)($_POST['term_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM terms WHERE id = ?");
        $stmt->execute([$term_id]);
        $target_term = $stmt->fetch();

        if (!$target_term) {
            set_flash('error', 'Term not found.');
            redirect('admin/terms.php');
        }

        try {
            $pdo->beginTransaction();

            // Deactivate all terms
            $pdo->exec("UPDATE terms SET is_active = 0");

            // Activate chosen term
            $stmt = $pdo->prepare("UPDATE terms SET is_active = 1 WHERE id = ?");
            $stmt->execute([$term_id]);

            log_audit(
                $pdo,
                $admin['id'],
                'activate_term',
                'term',
                $term_id,
                json_encode(['name' => $target_term['name']])
            );

            $pdo->commit();
            set_flash('success', 'Term "' . $target_term['name'] . '" is now the active term.');
            redirect('admin/terms.php');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Database error: ' . $e->getMessage());
            redirect('admin/terms.php');
        }
    }
}

// Fetch all terms with session count
$stmt = $pdo->query("
    SELECT t.*, COUNT(s.id) AS session_count
    FROM terms t
    LEFT JOIN training_sessions s ON t.id = s.term_id
    GROUP BY t.id
    ORDER BY t.start_date DESC, t.id DESC
");
$terms = $stmt->fetchAll();

$page_title = 'Terms & Semesters';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px;">
    <div>
        <h1>Terms & Semesters</h1>
        <p>Manage academic terms and designate the single active term for ROTC training and attendance.</p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/admin/sessions.php" class="btn btn-secondary btn-sm">&rarr; Go to Training Sessions</a>
    </div>
</div>

<?php if ($editing_term): ?>
    <!-- Edit Term Form Card -->
    <div class="card">
        <h2 class="card-title">Edit Term: <?= e($editing_term['name']) ?></h2>
        <form method="POST" action="<?= BASE_URL ?>/admin/terms.php?edit=<?= (int)$editing_term['id'] ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_term">
            <input type="hidden" name="term_id" value="<?= (int)$editing_term['id'] ?>">

            <div class="form-group">
                <label for="edit_name">Term Name</label>
                <input type="text" id="edit_name" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['name'] ?? $editing_term['name']) ?>" required>
                <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="edit_start_date">Start Date</label>
                    <input type="date" id="edit_start_date" name="start_date" class="form-control <?= isset($errors['start_date']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['start_date'] ?? $editing_term['start_date']) ?>" required>
                    <?php if (isset($errors['start_date'])): ?><span class="field-error"><?= e($errors['start_date']) ?></span><?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="edit_end_date">End Date</label>
                    <input type="date" id="edit_end_date" name="end_date" class="form-control <?= isset($errors['end_date']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['end_date'] ?? $editing_term['end_date']) ?>" required>
                    <?php if (isset($errors['end_date'])): ?><span class="field-error"><?= e($errors['end_date']) ?></span><?php endif; ?>
                </div>
            </div>

            <div style="display: flex; gap: 8px; margin-top: 16px;">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="<?= BASE_URL ?>/admin/terms.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
<?php else: ?>
    <!-- Create Term Form Card -->
    <div class="card">
        <h2 class="card-title">Create New Academic Term</h2>
        <form method="POST" action="<?= BASE_URL ?>/admin/terms.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_term">

            <div class="form-group">
                <label for="name">Term Name</label>
                <input type="text" id="name" name="name" class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['name'] ?? '') ?>" placeholder="e.g. 1st Semester AY 2026-2027" required>
                <span class="field-hint">Specify the academic semester and academic year.</span>
                <?php if (isset($errors['name'])): ?><span class="field-error"><?= e($errors['name']) ?></span><?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" class="form-control <?= isset($errors['start_date']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['start_date'] ?? '') ?>" required>
                    <?php if (isset($errors['start_date'])): ?><span class="field-error"><?= e($errors['start_date']) ?></span><?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" class="form-control <?= isset($errors['end_date']) ? 'is-invalid' : '' ?>" value="<?= e($_POST['end_date'] ?? '') ?>" required>
                    <?php if (isset($errors['end_date'])): ?><span class="field-error"><?= e($errors['end_date']) ?></span><?php endif; ?>
                </div>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" id="make_active" name="make_active" value="1" <?= !empty($_POST['make_active']) ? 'checked' : '' ?>>
                <label for="make_active">Set as the active term immediately (deactivates currently active term)</label>
            </div>

            <div>
                <button type="submit" class="btn btn-primary">Create Term</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<!-- Terms List Table Card -->
<div class="card">
    <h2 class="card-title">Academic Terms List</h2>
    <?php if (empty($terms)): ?>
        <div class="empty-state">
            <p>No academic terms have been created yet. Please create one above to begin.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Term Name</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Status</th>
                        <th>Sessions Count</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($terms as $t): ?>
                        <tr>
                            <td><?= (int)$t['id'] ?></td>
                            <td>
                                <strong><?= e($t['name']) ?></strong>
                            </td>
                            <td><?= e($t['start_date']) ?></td>
                            <td><?= e($t['end_date']) ?></td>
                            <td>
                                <?php if ($t['is_active']): ?>
                                    <span class="badge badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= (int)$t['session_count'] ?></strong> sessions
                            </td>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                    <a href="<?= BASE_URL ?>/admin/sessions.php?term_id=<?= (int)$t['id'] ?>" class="btn btn-secondary btn-sm">Sessions</a>
                                    <a href="<?= BASE_URL ?>/admin/terms.php?edit=<?= (int)$t['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                                    <?php if (!$t['is_active']): ?>
                                        <form method="POST" action="<?= BASE_URL ?>/admin/terms.php" class="activate-term-form" data-term-name="<?= e($t['name']) ?>" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="activate_term">
                                            <input type="hidden" name="term_id" value="<?= (int)$t['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm">Set Active</button>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
