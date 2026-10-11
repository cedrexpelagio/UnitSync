<?php
// Admin Terms & Semesters Management
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

// "Week 3 of 16", "Starts in 12 days", "Ended"
$today = new DateTime('today');
$timeline = static function (array $t) use ($today): array {
    $s = new DateTime($t['start_date']);
    $en = new DateTime($t['end_date']);
    $total_days = max(1, (int)$s->diff($en)->days + 1);
    $weeks = (int)ceil($total_days / 7);
    if ($today < $s) {
        $n = (int)$today->diff($s)->days;
        return ['Starts in ' . ad_plural($n, 'day'), 0, $weeks];
    }
    if ($today > $en) {
        return ['Ended', 100, $weeks];
    }
    $elapsed = (int)$s->diff($today)->days + 1;
    return ['Week ' . (int)ceil($elapsed / 7) . ' of ' . $weeks, (int)round($elapsed / $total_days * 100), $weeks];
};

// Show the form when editing, after a validation error, or when there is nothing yet
$form_open = $editing_term || !empty($errors) || empty($terms);
$is_edit = (bool)$editing_term;
$val = static function (string $key, ?string $edit_key = null) use ($editing_term) {
    if (isset($_POST[$key])) return $_POST[$key];
    return $editing_term[$edit_key ?? $key] ?? '';
};

$page_title = 'Terms & Semesters';
require_once __DIR__ . '/../includes/header.php';
ad_css();
?>
<div class="ad">
    <?php
    $actions = '<button type="button" class="btn btn-primary btn-sm ad-js-only" data-toggle-target="termForm">' . ad_icon('plus', 14) . ' New term</button>'
        . '<a href="' . BASE_URL . '/admin/sessions.php" class="btn btn-secondary btn-sm">Training sessions ' . ad_icon('arrow', 14) . '</a>';
    ad_head('Terms & semesters', 'A term is one semester of training. Only one term is active at a time, and attendance is recorded against it.', $actions, 'terms');
    ad_guide('terms', 'How terms work', [
        ['Create the term', 'Give it a clear name like "1st Semester AY 2026-2027" and set its start and end dates.'],
        ['Make it active', 'The active term is the one officers see. Switching term changes it for everyone straight away.'],
        ['Add its sessions', 'Open a term\'s Sessions button to build the weekly training calendar.'],
    ]);
    ?>

    <section id="termForm" class="ad-panel ad-collapse <?= $form_open ? 'is-open' : '' ?> ad-rise" style="--i:2" aria-labelledby="termFormTitle">
        <h2 class="ad-panel-title" id="termFormTitle"><?= $is_edit ? 'Edit term: ' . e($editing_term['name']) : 'Create a new term' ?></h2>
        <p class="ad-panel-sub"><?= $is_edit ? 'Changing dates does not move sessions that already exist.' : 'You can set it active now or later from its card.' ?></p>

        <form method="POST" action="<?= BASE_URL ?>/admin/terms.php<?= $is_edit ? '?edit=' . (int)$editing_term['id'] : '' ?>" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $is_edit ? 'update_term' : 'create_term' ?>">
            <?php if ($is_edit): ?><input type="hidden" name="term_id" value="<?= (int)$editing_term['id'] ?>"><?php endif; ?>

            <?= ad_field('name', 'Term name',
                '<input type="text" id="name" name="name" class="ad-input" required data-msg="Give the term a name." value="' . e($val('name')) . '" placeholder="e.g. 1st Semester AY 2026-2027" aria-describedby="name-err">',
                $errors['name'] ?? '', 'The semester and academic year.') ?>

            <div class="ad-form-grid">
                <?= ad_field('start_date', 'Start date',
                    '<input type="date" id="start_date" name="start_date" class="ad-input" required data-msg="Choose when the term starts." value="' . e($val('start_date')) . '" aria-describedby="start_date-err">',
                    $errors['start_date'] ?? '') ?>
                <?= ad_field('end_date', 'End date',
                    '<input type="date" id="end_date" name="end_date" class="ad-input" required data-after="start_date" data-msg="Choose when the term ends." data-after-msg="The end date can\'t be before the start date." value="' . e($val('end_date')) . '" aria-describedby="end_date-err">',
                    $errors['end_date'] ?? '') ?>
            </div>

            <?php if (!$is_edit): ?>
                <label class="ad-check" for="make_active">
                    <input type="checkbox" id="make_active" name="make_active" value="1" <?= !empty($_POST['make_active']) ? 'checked' : '' ?>>
                    <span>Make this the active term now. The current active term will be switched off.</span>
                </label>
            <?php endif; ?>

            <div class="ad-actions">
                <button type="submit" class="btn btn-primary"><?= $is_edit ? 'Save changes' : 'Create term' ?></button>
                <?php if ($is_edit): ?>
                    <a href="<?= BASE_URL ?>/admin/terms.php" class="btn btn-secondary">Cancel</a>
                <?php elseif ($terms): ?>
                    <button type="button" class="btn btn-secondary ad-js-only" data-toggle-target="termForm">Cancel</button>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <?php if (empty($terms)): ?>
        <div class="ad-empty ad-rise" style="--i:3">
            <?= ad_icon('layers', 36) ?>
            <strong>No terms yet</strong>
            <p>Create your first term above. After that, head to Training sessions to generate its calendar.</p>
        </div>
    <?php else: ?>
        <div class="ad-cards">
            <?php foreach ($terms as $i => $t):
                [$when, $pct, $weeks] = $timeline($t);
                $active = (int)$t['is_active'] === 1;
            ?>
                <article class="ad-card ad-term <?= $active ? 'is-active' : '' ?> ad-rise" style="--i:<?= min($i, 8) + 3 ?>">
                    <div class="ad-term-top">
                        <div>
                            <div class="ad-term-name"><?= e($t['name']) ?></div>
                            <div class="ad-term-dates"><?= e(date('M j, Y', strtotime($t['start_date']))) ?> &ndash; <?= e(date('M j, Y', strtotime($t['end_date']))) ?></div>
                        </div>
                        <span class="badge <?= $active ? 'badge-active' : 'badge-inactive' ?>"><?= $active ? 'Active' : 'Inactive' ?></span>
                    </div>

                    <div>
                        <div class="ad-progress-row"><span><?= e($when) ?></span><span><?= $pct ?>%</span></div>
                        <div class="ad-progress"><span style="--p: <?= $pct ?>%"></span></div>
                    </div>

                    <div class="ad-term-stats">
                        <div><strong><?= (int)$t['session_count'] ?></strong>sessions</div>
                        <div><strong><?= $weeks ?></strong>weeks</div>
                    </div>

                    <?php if ((int)$t['session_count'] === 0): ?>
                        <div class="ad-note ad-note-info"><?= ad_icon('info', 16) ?><div>No sessions yet. Open Sessions to generate the weekly calendar.</div></div>
                    <?php endif; ?>

                    <div class="ad-term-foot">
                        <a href="<?= BASE_URL ?>/admin/sessions.php?term_id=<?= (int)$t['id'] ?>" class="btn btn-primary btn-sm"><?= ad_icon('calendar', 14) ?> Sessions</a>
                        <a href="<?= BASE_URL ?>/admin/terms.php?edit=<?= (int)$t['id'] ?>" class="btn btn-secondary btn-sm"><?= ad_icon('edit', 14) ?> Edit</a>
                        <?php if (!$active): ?>
                            <form method="POST" action="<?= BASE_URL ?>/admin/terms.php" class="activate-term-form" data-term-name="<?= e($t['name']) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="activate_term">
                                <input type="hidden" name="term_id" value="<?= (int)$t['id'] ?>">
                                <button type="submit" class="btn btn-success btn-sm"><?= ad_icon('check', 14) ?> Set active</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php ad_js(); ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
