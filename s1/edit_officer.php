<?php
// S1 Edit Officer: edit name, change position (role + company + platoon), set status
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/officer_accounts.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

$status_labels = [
    'active'   => 'Active',
    'inactive' => 'Inactive',
];

// Back link keeps the roster filters the user had
$back = 's1/officers.php' . (!empty($_SESSION['officer_roster_qs']) ? '?' . $_SESSION['officer_roster_qs'] : '');

$raw_id = $_POST['id'] ?? ($_GET['id'] ?? '');
$id = (is_string($raw_id) && ctype_digit($raw_id)) ? (int)$raw_id : 0;

$stmt = $pdo->prepare("SELECT * FROM officers WHERE id = ?");
$stmt->execute([$id]);
$officer = $stmt->fetch();
if (!$officer) {
    set_flash('error', 'Officer not found.');
    redirect($back);
}

// Login account linked to this officer (Platoon Leaders only)
$login = null;
if (!empty($officer['user_id'])) {
    $stmt = $pdo->prepare("SELECT username, status FROM users WHERE id = ?");
    $stmt->execute([(int)$officer['user_id']]);
    $login = $stmt->fetch() ?: null;
}

// Position choices: one per company (Company Commander) and one per platoon (Platoon Leader)
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$platoons = $pdo->query("
    SELECT p.id, p.name, p.company_id, co.name AS company_name
    FROM platoons p JOIN companies co ON p.company_id = co.id
    ORDER BY co.name, p.name
")->fetchAll();
$company_by_id = [];
foreach ($companies as $c) $company_by_id[(int)$c['id']] = $c;
$platoon_by_id = [];
foreach ($platoons as $pl) $platoon_by_id[(int)$pl['id']] = $pl;

function officer_position_value(array $o): string {
    return $o['role'] === 'company_commander' ? 'cc:' . (int)$o['company_id'] : 'pl:' . (int)$o['platoon_id'];
}

// Human label of a position value, for the audit log
function officer_position_label(string $v, array $company_by_id, array $platoon_by_id): string {
    [$kind, $pid] = array_pad(explode(':', $v, 2), 2, '');
    $pid = (int)$pid;
    if ($kind === 'cc' && isset($company_by_id[$pid])) return 'Company Commander, ' . $company_by_id[$pid]['name'];
    if ($kind === 'pl' && isset($platoon_by_id[$pid])) return 'Platoon Leader, ' . $platoon_by_id[$pid]['company_name'] . ' ' . $platoon_by_id[$pid]['name'];
    return $v;
}

// Form values (current data, replaced by POST when saving)
$form = [
    'last_name'   => $officer['last_name'],
    'first_name'  => $officer['first_name'],
    'middle_name' => (string)($officer['middle_name'] ?? ''),
    'position'    => officer_position_value($officer),
    'status'      => $officer['status'],
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token. Please try again.');
        redirect('s1/edit_officer.php?id=' . $id);
    }

    foreach (array_keys($form) as $k) {
        $v = $_POST[$k] ?? '';
        $form[$k] = is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : '';
    }

    // Validate
    if ($form['last_name'] === '') $errors['last_name'] = 'Last name is required.';
    elseif (mb_strlen($form['last_name']) > 100) $errors['last_name'] = 'Last name is too long.';

    if ($form['first_name'] === '') $errors['first_name'] = 'First name is required.';
    elseif (mb_strlen($form['first_name']) > 100) $errors['first_name'] = 'First name is too long.';

    if (mb_strlen($form['middle_name']) > 100) $errors['middle_name'] = 'Middle name is too long.';

    // Position -> role, company, platoon
    $new_role = null;
    $new_company = null;
    $new_platoon = null;
    if (preg_match('/^(cc|pl):(\d+)$/', $form['position'], $m)) {
        $pid = (int)$m[2];
        if ($m[1] === 'cc' && isset($company_by_id[$pid])) {
            $new_role = 'company_commander';
            $new_company = $pid;
        } elseif ($m[1] === 'pl' && isset($platoon_by_id[$pid])) {
            $new_role = 'platoon_leader';
            $new_platoon = $pid;
            $new_company = (int)$platoon_by_id[$pid]['company_id']; // company always follows the platoon
        }
    }
    if ($new_role === null) $errors['position'] = 'Choose a valid position.';

    if (!isset($status_labels[$form['status']])) $errors['status'] = 'Choose a valid status.';

    // An active officer cannot share a name with, or take the position of, another active officer
    if (!$errors && $form['status'] === 'active') {
        $stmt = $pdo->prepare("SELECT id FROM officers WHERE last_name = ? AND first_name = ? AND status = 'active' AND id <> ? LIMIT 1");
        $stmt->execute([$form['last_name'], $form['first_name'], $id]);
        if ($stmt->fetch()) {
            $errors['first_name'] = 'Another active officer with this name already exists.';
        }

        if ($new_role === 'company_commander') {
            $stmt = $pdo->prepare("SELECT last_name, first_name FROM officers WHERE role = 'company_commander' AND company_id = ? AND status = 'active' AND id <> ? LIMIT 1");
            $stmt->execute([$new_company, $id]);
        } else {
            $stmt = $pdo->prepare("SELECT last_name, first_name FROM officers WHERE role = 'platoon_leader' AND platoon_id = ? AND status = 'active' AND id <> ? LIMIT 1");
            $stmt->execute([$new_platoon, $id]);
        }
        $holder = $stmt->fetch();
        if ($holder) {
            $errors['position'] = 'That position is already held by ' . $holder['last_name'] . ', ' . $holder['first_name'] . '. Deactivate or move that officer first.';
        }
    }

    if (!$errors) {
        try {
            // Work out what changed for the audit log
            $old = [
                'last_name' => $officer['last_name'], 'first_name' => $officer['first_name'],
                'middle_name' => (string)($officer['middle_name'] ?? ''),
                'position' => officer_position_value($officer),
                'status' => $officer['status'],
            ];
            $changes = [];
            foreach ($old as $k => $ov) {
                if ((string)$ov !== (string)$form[$k]) {
                    if ($k === 'position') {
                        $changes[] = "position: '" . officer_position_label($ov, $company_by_id, $platoon_by_id)
                                   . "' -> '" . officer_position_label($form[$k], $company_by_id, $platoon_by_id) . "'";
                    } else {
                        $changes[] = "$k: '" . $ov . "' -> '" . $form[$k] . "'";
                    }
                }
            }

            if (!$changes) {
                set_flash('info', 'No changes were made.');
                redirect($back);
            }

            $pdo->beginTransaction();
            $upd = $pdo->prepare("
                UPDATE officers
                SET last_name = :last_name, first_name = :first_name, middle_name = :middle_name,
                    role = :role, company_id = :company_id, platoon_id = :platoon_id, status = :status
                WHERE id = :id
            ");
            $upd->execute([
                'last_name'   => $form['last_name'],
                'first_name'  => $form['first_name'],
                'middle_name' => $form['middle_name'] !== '' ? $form['middle_name'] : null,
                'role'        => $new_role,
                'company_id'  => $new_company,
                'platoon_id'  => $new_platoon,
                'status'      => $form['status'],
                'id'          => $id,
            ]);

            // Keep the Platoon Leader login in step: create, rename, move, deactivate or reactivate it
            $sync = officer_sync_login($pdo, [
                'role'    => $officer['role'],
                'status'  => $officer['status'],
                'user_id' => $officer['user_id'],
            ], [
                'id'          => $id,
                'last_name'   => $form['last_name'],
                'first_name'  => $form['first_name'],
                'middle_name' => $form['middle_name'],
                'role'        => $new_role,
                'status'      => $form['status'],
                'company_id'  => $new_company,
                'platoon_id'  => $new_platoon,
            ], (int)$user['id']);

            $audit = $pdo->prepare("
                INSERT INTO audit_log (actor_id, action, entity, entity_id, details, created_at)
                VALUES (?, 'edit_officer', 'officers', ?, ?, NOW())
            ");
            $audit->execute([$user['id'], $id, mb_substr('Edited officer #' . $id . ' - ' . implode('; ', $changes), 0, 1000)]);
            $pdo->commit();

            if ($sync['credential']) officer_credentials_stash($sync['credential']);

            set_flash('success', 'Officer updated.');
            foreach ($sync['messages'] as $m) set_flash('info', $m);
            redirect($back);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('error', 'Could not save changes: ' . $ex->getMessage());
            redirect('s1/edit_officer.php?id=' . $id);
        }
    }
}

$page_title = 'Edit Officer';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="content-header">
    <h1>Edit Officer</h1>
    <p>Update details, change this officer's position, or deactivate the officer.</p>
</div>

<div class="summary-card" style="max-width: 650px;">
    <form action="<?= BASE_URL ?>/s1/edit_officer.php?id=<?= (int)$id ?>" method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="form-group">
            <label for="last_name">Last Name</label>
            <input type="text" id="last_name" name="last_name" class="form-control<?= isset($errors['last_name']) ? ' is-invalid' : '' ?>" value="<?= e($form['last_name']) ?>" maxlength="100" required>
            <?php if (isset($errors['last_name'])): ?><span class="field-error"><?= e($errors['last_name']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="first_name">First Name</label>
            <input type="text" id="first_name" name="first_name" class="form-control<?= isset($errors['first_name']) ? ' is-invalid' : '' ?>" value="<?= e($form['first_name']) ?>" maxlength="100" required>
            <?php if (isset($errors['first_name'])): ?><span class="field-error"><?= e($errors['first_name']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="middle_name">Middle Name (optional)</label>
            <input type="text" id="middle_name" name="middle_name" class="form-control<?= isset($errors['middle_name']) ? ' is-invalid' : '' ?>" value="<?= e($form['middle_name']) ?>" maxlength="100">
            <?php if (isset($errors['middle_name'])): ?><span class="field-error"><?= e($errors['middle_name']) ?></span><?php endif; ?>
        </div>

        <div class="form-group">
            <label for="position">Position</label>
            <select id="position" name="position" class="form-control<?= isset($errors['position']) ? ' is-invalid' : '' ?>" required>
                <optgroup label="Company Commander">
                    <?php foreach ($companies as $c): ?>
                        <option value="cc:<?= (int)$c['id'] ?>" <?= $form['position'] === 'cc:' . $c['id'] ? 'selected' : '' ?>>
                            <?= e($c['name']) ?> Company
                        </option>
                    <?php endforeach; ?>
                </optgroup>
                <optgroup label="Platoon Leader">
                    <?php foreach ($platoons as $pl): ?>
                        <option value="pl:<?= (int)$pl['id'] ?>" <?= $form['position'] === 'pl:' . $pl['id'] ? 'selected' : '' ?>>
                            <?= e($pl['company_name'] . ' - ' . $pl['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </optgroup>
            </select>
            <?php if (isset($errors['position'])): ?><span class="field-error"><?= e($errors['position']) ?></span><?php endif; ?>
            <small>Each company has one Company Commander and each platoon one Platoon Leader.</small>
        </div>

        <div class="form-group">
            <label>Login account</label>
            <p style="margin: 0;">
                <?php if ($login): ?>
                    <strong><?= e($login['username']) ?></strong> (<?= e($login['status']) ?>)
                <?php elseif ($officer['role'] === 'platoon_leader'): ?>
                    None yet. One is generated automatically when this Platoon Leader is active.
                <?php else: ?>
                    Not needed for a Company Commander.
                <?php endif; ?>
            </p>
        </div>

        <div class="form-group">
            <label for="status">Status</label>
            <select id="status" name="status" class="form-control<?= isset($errors['status']) ? ' is-invalid' : '' ?>" required>
                <?php foreach ($status_labels as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $form['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['status'])): ?><span class="field-error"><?= e($errors['status']) ?></span><?php endif; ?>
            <small>Set an officer to Inactive when they leave the position. Their position then becomes free for someone else.</small>
        </div>

        <button type="submit" class="btn btn-primary">Save changes</button>
        <a href="<?= BASE_URL . '/' . e($back) ?>" class="btn btn-secondary">Cancel</a>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>