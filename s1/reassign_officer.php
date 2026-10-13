<?php
// S1 Reassign Platoon Leader: move a Platoon Leader to another platoon (or swap two leaders),
// or fill a vacant platoon with an existing Platoon Leader (Battalion S1 and Brigade S1 only).
// The leader's login follows the move, so their cadet list and attendance scope change with it.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/officer_accounts.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

// Back link keeps the roster filters the user had
$back = 's1/officers.php' . (!empty($_SESSION['officer_roster_qs']) ? '?' . $_SESSION['officer_roster_qs'] : '');

function ra_int($v): int {
    return (is_string($v) && ctype_digit($v)) ? (int)$v : 0;
}

// ---------- Data for the form ----------
$leaders = $pdo->query("
    SELECT o.id, o.last_name, o.first_name, o.middle_name, o.status, o.company_id, o.platoon_id,
           co.name AS company_name, p.name AS platoon_name
    FROM officers o
    JOIN companies co ON co.id = o.company_id
    LEFT JOIN platoons p ON p.id = o.platoon_id
    WHERE o.role = 'platoon_leader'
    ORDER BY (o.status = 'inactive') ASC, co.name, p.name, o.last_name, o.first_name
")->fetchAll();
$leader_by_id = [];
foreach ($leaders as $l) $leader_by_id[(int)$l['id']] = $l;

$platoons = $pdo->query("
    SELECT p.id, p.name, p.company_id, co.name AS company_name
    FROM platoons p JOIN companies co ON p.company_id = co.id
    ORDER BY co.name, p.name
")->fetchAll();
$platoon_by_id = [];
foreach ($platoons as $pl) $platoon_by_id[(int)$pl['id']] = $pl;

// Active holder of each platoon
$holder_by_platoon = [];
foreach ($leaders as $l) {
    if ($l['status'] === 'active' && $l['platoon_id'] !== null) {
        $holder_by_platoon[(int)$l['platoon_id']] = $l;
    }
}

function ra_platoon_label(array $pl): string {
    return $pl['company_name'] . ' - ' . $pl['name'];
}

$officer_id = ra_int($_POST['officer_id'] ?? ($_GET['id'] ?? ''));
$target_id  = ra_int($_POST['platoon_id'] ?? ($_GET['platoon'] ?? ''));
$swap       = !empty($_POST['swap']);

// Opened from an officer row: the officer is fixed. Opened from a vacant platoon: S1 picks the officer.
$officer_locked = isset($_GET['id']) && ra_int($_GET['id']) > 0 && isset($leader_by_id[ra_int($_GET['id'])]);
if (isset($_POST['locked'])) $officer_locked = $_POST['locked'] === '1' && isset($leader_by_id[$officer_id]);

if (!$officer_locked && $_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['id']) && !isset($leader_by_id[ra_int($_GET['id'])])) {
    set_flash('error', 'Platoon Leader not found.');
    redirect($back);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token. Please try again.');
        redirect('s1/reassign_officer.php');
    }

    if (!isset($leader_by_id[$officer_id])) $errors['officer'] = 'Choose a Platoon Leader.';
    if (!isset($platoon_by_id[$target_id])) $errors['platoon'] = 'Choose a platoon.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Lock the rows involved so two S1 users cannot both fill the same platoon
            $stmt = $pdo->prepare("SELECT * FROM officers WHERE id = ? AND role = 'platoon_leader' FOR UPDATE");
            $stmt->execute([$officer_id]);
            $x = $stmt->fetch();
            if (!$x) throw new RuntimeException('That Platoon Leader no longer exists.');

            $stmt = $pdo->prepare("SELECT * FROM officers WHERE role = 'platoon_leader' AND platoon_id = ? AND status = 'active' AND id <> ? FOR UPDATE");
            $stmt->execute([$target_id, $officer_id]);
            $y = $stmt->fetch() ?: null;

            $target = $platoon_by_id[$target_id];
            $target_label = ra_platoon_label($target);
            $x_active = ($x['status'] === 'active' && $x['platoon_id'] !== null);
            $x_old_label = ($x['platoon_id'] !== null && isset($platoon_by_id[(int)$x['platoon_id']]))
                ? ra_platoon_label($platoon_by_id[(int)$x['platoon_id']]) : 'no platoon';

            if ($x['status'] === 'active' && (int)$x['platoon_id'] === $target_id) {
                throw new DomainException(officer_display_name($x) . ' is already the Platoon Leader of ' . $target_label . '.');
            }

            $moves = []; // [officer row, new company id, new platoon id]
            $note = '';
            if ($y) {
                if (!$swap) {
                    throw new DomainException($target_label . ' already has a Platoon Leader (' . officer_display_name($y) . '). Tick "Swap places" to exchange positions, or deactivate that officer first.');
                }
                if (!$x_active) {
                    throw new DomainException('A swap needs an active Platoon Leader with a platoon. To place ' . officer_display_name($x) . ' in ' . $target_label . ', deactivate ' . officer_display_name($y) . ' first.');
                }
                // Swap: Y first, so the login of the platoon being vacated is moved before X's login is touched
                $moves[] = [$y, (int)$x['company_id'], (int)$x['platoon_id']];
                $moves[] = [$x, (int)$target['company_id'], $target_id];
                $note = officer_display_name($y) . ' moved to ' . $x_old_label;
            } else {
                $moves[] = [$x, (int)$target['company_id'], $target_id];
            }

            $upd = $pdo->prepare("UPDATE officers SET company_id = ?, platoon_id = ?, status = 'active' WHERE id = ?");
            $credentials = [];
            $messages = [];
            foreach ($moves as [$row, $new_company, $new_platoon]) {
                $upd->execute([$new_company, $new_platoon, (int)$row['id']]);
                $sync = officer_sync_login($pdo, [
                    'role'    => $row['role'],
                    'status'  => $row['status'],
                    'user_id' => $row['user_id'],
                ], [
                    'id'          => (int)$row['id'],
                    'last_name'   => $row['last_name'],
                    'first_name'  => $row['first_name'],
                    'middle_name' => (string)($row['middle_name'] ?? ''),
                    'role'        => 'platoon_leader',
                    'status'      => 'active',
                    'company_id'  => $new_company,
                    'platoon_id'  => $new_platoon,
                ], (int)$user['id']);
                if ($sync['credential']) $credentials[] = $sync['credential'];
                foreach ($sync['messages'] as $m) $messages[] = $m;
            }

            $details = $y
                ? 'Swapped Platoon Leaders: ' . officer_display_name($x) . ' (#' . (int)$x['id'] . ') ' . $x_old_label . ' -> ' . $target_label
                  . '; ' . officer_display_name($y) . ' (#' . (int)$y['id'] . ') ' . $target_label . ' -> ' . $x_old_label
                : 'Reassigned Platoon Leader ' . officer_display_name($x) . ' (#' . (int)$x['id'] . ') ' . $x_old_label . ' -> ' . $target_label
                  . ($x['status'] === 'inactive' ? ' (reactivated)' : '');
            log_audit($pdo, (int)$user['id'], 'reassign_officer', 'officers', (int)$x['id'], mb_substr($details, 0, 1000));

            $pdo->commit();

            foreach ($credentials as $c) officer_credentials_stash($c);
            set_flash('success', $y
                ? officer_display_name($x) . ' and ' . officer_display_name($y) . ' swapped platoons.'
                : officer_display_name($x) . ' is now the Platoon Leader of ' . $target_label . '.');
            foreach ($messages as $m) set_flash('info', $m);
            redirect($back);
        } catch (DomainException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors['platoon'] = $ex->getMessage();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('error', 'Could not reassign, nothing was saved. ' . $ex->getMessage());
            redirect('s1/reassign_officer.php' . ($officer_id ? '?id=' . $officer_id : ''));
        }
    }
}

$selected_officer = $leader_by_id[$officer_id] ?? null;

$page_title = 'Reassign Platoon Leader';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/s1_manage.css">

<div class="content-header">
    <h1>Reassign Platoon Leader</h1>
    <p>Move a Platoon Leader to another platoon, or fill a vacant platoon. The leader's login moves with them.</p>
</div>

<div class="summary-card" style="max-width: 650px;">
    <form action="<?= BASE_URL ?>/s1/reassign_officer.php" method="POST" id="reassignForm">
        <?= csrf_field() ?>
        <input type="hidden" name="locked" value="<?= $officer_locked ? '1' : '0' ?>">

        <div class="form-group">
            <label for="officer_id">Platoon Leader</label>
            <?php if ($officer_locked && $selected_officer): ?>
                <input type="hidden" name="officer_id" id="officer_id" value="<?= (int)$selected_officer['id'] ?>"
                       data-current="<?= (int)($selected_officer['platoon_id'] ?? 0) ?>"
                       data-name="<?= e(officer_display_name($selected_officer)) ?>"
                       data-active="<?= $selected_officer['status'] === 'active' ? '1' : '0' ?>">
                <p style="margin:0;">
                    <strong><?= e(officer_display_name($selected_officer)) ?></strong>
                    <span class="rt-muted">
                        <?php if ($selected_officer['status'] === 'inactive'): ?>
                            (inactive, will be reactivated)
                        <?php elseif ($selected_officer['platoon_name'] !== null): ?>
                            (now <?= e($selected_officer['company_name'] . ' - ' . $selected_officer['platoon_name']) ?>)
                        <?php endif; ?>
                    </span>
                </p>
            <?php else: ?>
                <select id="officer_id" name="officer_id" class="form-control<?= isset($errors['officer']) ? ' is-invalid' : '' ?>" required>
                    <option value="">Choose a Platoon Leader</option>
                    <?php foreach ($leaders as $l): ?>
                        <option value="<?= (int)$l['id'] ?>"
                                data-current="<?= (int)($l['platoon_id'] ?? 0) ?>"
                                data-name="<?= e(officer_display_name($l)) ?>"
                                data-active="<?= $l['status'] === 'active' ? '1' : '0' ?>"
                                <?= $officer_id === (int)$l['id'] ? 'selected' : '' ?>>
                            <?= e(officer_display_name($l)) ?> &mdash;
                            <?= $l['status'] === 'inactive' ? 'inactive' : e($l['company_name'] . ' - ' . ($l['platoon_name'] ?? 'no platoon')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['officer'])): ?><span class="field-error"><?= e($errors['officer']) ?></span><?php endif; ?>
                <small>Not on the list? <a href="<?= BASE_URL ?>/s1/import_officers.php">Import the officer first</a>.</small>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label for="platoon_id">New platoon</label>
            <select id="platoon_id" name="platoon_id" class="form-control<?= isset($errors['platoon']) ? ' is-invalid' : '' ?>" required>
                <option value="">Choose a platoon</option>
                <?php
                $current_company = null;
                foreach ($platoons as $pl):
                    if ($current_company !== $pl['company_name']) {
                        if ($current_company !== null) echo '</optgroup>';
                        echo '<optgroup label="' . e($pl['company_name']) . ' Company">';
                        $current_company = $pl['company_name'];
                    }
                    $h = $holder_by_platoon[(int)$pl['id']] ?? null;
                ?>
                    <option value="<?= (int)$pl['id'] ?>"
                            data-label="<?= e(ra_platoon_label($pl)) ?>"
                            data-holder-id="<?= $h ? (int)$h['id'] : 0 ?>"
                            data-holder="<?= $h ? e(officer_display_name($h)) : '' ?>"
                            <?= $target_id === (int)$pl['id'] ? 'selected' : '' ?>>
                        <?= e($pl['name']) ?> &mdash; <?= $h ? 'held by ' . e(officer_display_name($h)) : 'vacant' ?>
                    </option>
                <?php endforeach; ?>
                <?php if ($current_company !== null) echo '</optgroup>'; ?>
            </select>
            <?php if (isset($errors['platoon'])): ?><span class="field-error"><?= e($errors['platoon']) ?></span><?php endif; ?>
            <small>An inactive officer is reactivated when placed in a platoon.</small>
        </div>

        <div class="s1-swap<?= $swap || isset($errors['platoon']) ? ' show' : '' ?>" id="swapBox">
            <label>
                <input type="checkbox" name="swap" value="1" id="swapCheck" <?= $swap ? 'checked' : '' ?>>
                <span>Swap places. This platoon already has a Platoon Leader, so the two officers exchange platoons.</span>
            </label>
        </div>

        <div class="s1-preview" id="reassignPreview" aria-live="polite"></div>

        <div style="margin-top: 16px;">
            <button type="submit" class="btn btn-primary">Save reassignment</button>
            <a href="<?= BASE_URL . '/' . e($back) ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<script src="<?= BASE_URL ?>/assets/js/reassign_officer.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
