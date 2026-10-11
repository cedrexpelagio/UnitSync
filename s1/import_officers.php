<?php
// S1 Officer CSV Import (Battalion S1 and Brigade S1 only)
// Imports Company Commanders and Platoon Leaders into the officers roster.
// Flow: upload CSV -> preview with validation -> confirm to save
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/officer_accounts.php';
require_once __DIR__ . '/../includes/s1_import_ui.php';

require_role('battalion_s1', 'brigade_s1');

$user = current_user();

const OFFICER_IMPORT_MAX_BYTES = 1 * 1024 * 1024; // 1 MB
const OFFICER_IMPORT_MAX_ROWS  = 200;

const OFFICER_ROLE_LABELS = [
    'company_commander' => 'Company Commander',
    'platoon_leader'    => 'Platoon Leader',
];

// ---------- Helpers ----------

function off_clean_cell(?string $v): string {
    $v = $v ?? '';
    if (!mb_check_encoding($v, 'UTF-8')) {
        // Excel on Windows often saves CSV as Windows-1252 (keeps ñ, é, etc.)
        $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
    return trim((string)preg_replace('/\s+/u', ' ', $v));
}

function off_norm(string $s): string {
    return preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
}

// "Dela Cruz, Juan A." -> ['Dela Cruz', 'Juan', 'A.']. Returns null when there is no comma.
function off_split_name(string $full): ?array {
    $pos = strpos($full, ',');
    if ($pos === false) return null;
    $last = trim(substr($full, 0, $pos));
    $rest = trim(substr($full, $pos + 1));
    if ($last === '' || $rest === '') return null;
    $tokens = explode(' ', $rest);
    $middle = '';
    if (count($tokens) > 1) {
        $tail = end($tokens);
        if (preg_match('/^\p{L}\.?$/u', $tail)) { // a single initial such as "A." or "A"
            $middle = $tail;
            array_pop($tokens);
        }
    }
    return [$last, implode(' ', $tokens), $middle];
}

// "Company Commander", "CO", "CC" -> company_commander. "Platoon Leader", "PL", "Plt Ldr" -> platoon_leader.
function off_norm_role(string $r): ?string {
    $n = off_norm($r);
    if (in_array($n, ['companycommander', 'companycmdr', 'companycdr', 'cc', 'co', 'commander'], true)) return 'company_commander';
    if (in_array($n, ['platoonleader', 'platoonldr', 'pltleader', 'pltldr', 'pl'], true)) return 'platoon_leader';
    return null;
}

// Detect which CSV column holds which field by matching header keywords.
function off_map_columns(array $headers): array {
    $aliases = [
        'full_name' => ['fullname', 'completename', 'officername', 'name'],
        'role'      => ['role', 'position', 'designation', 'assignment'],
        'company'   => ['company'],
        'platoon'   => ['platoon'],
    ];
    $normalized = array_map('off_norm', $headers);
    $map = [];
    $used = [];

    // Pass 1: exact match. Pass 2: header contains the keyword.
    foreach ([false, true] as $contains) {
        foreach ($aliases as $field => $words) {
            if (isset($map[$field])) continue;
            foreach ($normalized as $i => $n) {
                if (isset($used[$i]) || $n === '' || $n === 'timestamp') continue;
                foreach ($words as $w) {
                    if (($contains && strpos($n, $w) !== false) || (!$contains && $n === $w)) {
                        $map[$field] = $i;
                        $used[$i] = true;
                        continue 3;
                    }
                }
            }
        }
    }
    return $map;
}

function off_person_key(string $last, string $first): string {
    return mb_strtolower($last) . '|' . mb_strtolower($first);
}

function off_slot_key(string $role, int $companyId, ?int $platoonId): string {
    return $role === 'company_commander' ? "cc:{$companyId}" : "pl:{$platoonId}";
}

// Active officers already saved, indexed by person and by slot
function off_load_existing(PDO $pdo): array {
    $people = [];
    $slots = [];
    $q = $pdo->query("SELECT last_name, first_name, role, company_id, platoon_id FROM officers WHERE status = 'active'");
    foreach ($q as $r) {
        $people[off_person_key($r['last_name'], $r['first_name'])] = true;
        $slots[off_slot_key($r['role'], (int)$r['company_id'], $r['platoon_id'] !== null ? (int)$r['platoon_id'] : null)]
            = $r['last_name'] . ', ' . $r['first_name'];
    }
    return [$people, $slots];
}

// ---------- CSV template download ----------
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="officer_import_template.csv"');
    echo "Full Name,Role,Company,Platoon\r\n";
    echo "\"Reyes, Carlo M.\",Company Commander,Alpha,\r\n";
    echo "\"Santos, Ana B.\",Platoon Leader,Alpha,1st Platoon\r\n";
    exit;
}

// ---------- Download the rows that have errors, ready to fix and upload again ----------
function off_csv_safe($v): string {
    $s = (string)$v;
    return ($s !== '' && in_array($s[0], ['=', '+', '-', '@'], true)) ? "'" . $s : $s;
}

if (isset($_GET['errors'])) {
    $imp = $_SESSION['officer_import'] ?? null;
    if (!$imp) {
        set_flash('error', 'No import in progress. Please upload the file again.');
        redirect('s1/import_officers.php');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="officer_import_rows_to_fix.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Full Name', 'Role', 'Company', 'Platoon', 'Reason']);
    foreach ($imp['rows'] as $r) {
        if ($r['status'] !== 'error') continue;
        fputcsv($out, array_map('off_csv_safe', [$r['full_name'], $r['role_label'], $r['company'], $r['platoon'], $r['note']]));
    }
    fclose($out);
    exit;
}

// ---------- POST handling ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash('error', 'Invalid security token. Please try again.');
        redirect('s1/import_officers.php');
    }

    $action = $_POST['action'] ?? '';

    // --- Cancel preview ---
    if ($action === 'cancel') {
        unset($_SESSION['officer_import']);
        set_flash('info', 'Import cancelled. Nothing was saved.');
        redirect('s1/import_officers.php');
    }

    // --- Confirm and save ---
    if ($action === 'confirm') {
        $import = $_SESSION['officer_import'] ?? null;
        if (!$import || empty($import['rows'])) {
            set_flash('error', 'No import in progress. Please upload the file again.');
            redirect('s1/import_officers.php');
        }

        try {
            $pdo->beginTransaction();

            // Re-check against the database right before saving (data may have changed since the preview)
            [$people, $slots] = off_load_existing($pdo);

            $ins = $pdo->prepare("
                INSERT INTO officers (last_name, first_name, middle_name, role, company_id, platoon_id, status, created_by, created_at, updated_at)
                VALUES (:last_name, :first_name, :middle_name, :role, :company_id, :platoon_id, 'active', :created_by, NOW(), NOW())
            ");

            $saved = 0;
            $skipped = 0;
            $credentials = [];   // generated logins for Platoon Leaders
            $account_notes = []; // Platoon Leaders that got no login, with the reason
            foreach ($import['rows'] as $row) {
                if ($row['status'] !== 'ok') continue;
                $pKey = off_person_key($row['last_name'], $row['first_name']);
                $sKey = off_slot_key($row['role'], $row['company_id'], $row['platoon_id']);
                if (isset($people[$pKey]) || isset($slots[$sKey])) { $skipped++; continue; }

                $ins->execute([
                    'last_name'   => $row['last_name'],
                    'first_name'  => $row['first_name'],
                    'middle_name' => $row['middle_name'] !== '' ? $row['middle_name'] : null,
                    'role'        => $row['role'],
                    'company_id'  => $row['company_id'],
                    'platoon_id'  => $row['platoon_id'],
                    'created_by'  => $user['id'],
                ]);
                $people[$pKey] = true;
                $slots[$sKey] = $row['last_name'] . ', ' . $row['first_name'];
                $saved++;

                // Every Platoon Leader gets a generated login account
                if ($row['role'] === 'platoon_leader') {
                    $acc = officer_create_pl_account($pdo, [
                        'id'          => (int)$pdo->lastInsertId(),
                        'last_name'   => $row['last_name'],
                        'first_name'  => $row['first_name'],
                        'middle_name' => $row['middle_name'],
                        'company_id'  => $row['company_id'],
                        'platoon_id'  => $row['platoon_id'],
                    ], (int)$user['id']);
                    if ($acc['ok']) $credentials[] = $acc['credential'];
                    else $account_notes[] = $acc['note'];
                }
            }

            log_audit($pdo, (int)$user['id'], 'import_officers', 'officers', null, "Imported {$saved} officers ("
                . count($credentials) . ' logins generated or linked) from ' . $import['file']);

            $pdo->commit();
            unset($_SESSION['officer_import']);

            foreach ($credentials as $c) officer_credentials_stash($c);

            $msg = "Import complete: {$saved} officer(s) saved.";
            if ($skipped > 0) $msg .= " {$skipped} skipped because they or their position were already filled.";
            $generated = count(array_filter($credentials, fn($c) => $c['password'] !== null));
            $linked = count($credentials) - $generated;
            if ($generated) $msg .= " {$generated} login account(s) generated: copy the temporary passwords below, they are shown only here.";
            if ($linked) $msg .= " {$linked} officer(s) linked to a login account that already existed.";
            $mismatch = array_filter($credentials, fn($c) => !empty($c['registered_name']));
            if ($mismatch) {
                set_flash('warning', 'Please check: ' . implode('; ', array_map(
                    fn($c) => $c['officer'] . ' was linked to ' . $c['username'] . ', which is registered under the name ' . $c['registered_name'],
                    $mismatch)) . '.');
            }
            set_flash('success', $msg);
            if ($account_notes) {
                set_flash('warning', 'Saved without a login account: ' . implode('; ', $account_notes) . '.');
            }
            redirect('s1/officers.php');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash('error', 'Import failed, nothing was saved. ' . $ex->getMessage());
        }
        redirect('s1/import_officers.php');
    }

    // --- Upload and build preview ---
    if ($action === 'upload') {
        unset($_SESSION['officer_import']);
        $f = $_FILES['csv_file'] ?? null;

        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            set_flash('error', 'Please choose a CSV file to upload.');
            redirect('s1/import_officers.php');
        }
        if ($f['size'] > OFFICER_IMPORT_MAX_BYTES) {
            set_flash('error', 'File is too large. Maximum size is 1 MB.');
            redirect('s1/import_officers.php');
        }
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'csv') {
            set_flash('error', 'Only .csv files are accepted. In Google Sheets use File > Download > Comma-separated values.');
            redirect('s1/import_officers.php');
        }

        $h = fopen($f['tmp_name'], 'r');
        if (!$h) {
            set_flash('error', 'Could not read the uploaded file.');
            redirect('s1/import_officers.php');
        }

        // Detect delimiter from the first line
        $firstLine = fgets($h);
        rewind($h);
        $delim = ',';
        if ($firstLine !== false) {
            $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
            arsort($counts);
            $delim = array_key_first($counts);
        }

        $headers = fgetcsv($h, 0, $delim);
        if (!$headers) {
            fclose($h);
            set_flash('error', 'The file is empty.');
            redirect('s1/import_officers.php');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]); // strip BOM
        $headers = array_map(fn($x) => off_clean_cell($x), $headers);

        $map = off_map_columns($headers);
        // Platoon is only required for Platoon Leaders, but the column must exist so S1 notices it
        $missing = array_diff(['full_name', 'role', 'company', 'platoon'], array_keys($map));
        if ($missing) {
            fclose($h);
            $labels = ['full_name' => 'Full Name', 'role' => 'Role', 'company' => 'Company', 'platoon' => 'Platoon'];
            $names = implode(', ', array_map(fn($m) => $labels[$m], $missing));
            set_flash('error', "Missing column(s): {$names}. Columns found in your file: " . implode(', ', $headers) . '.');
            redirect('s1/import_officers.php');
        }

        // Companies and platoons lookups (case-insensitive)
        $companyLookup = []; // normalized name (with or without "company") => [id, name]
        foreach ($pdo->query("SELECT id, name FROM companies") as $c) {
            $n = off_norm($c['name']);
            $companyLookup[$n] = [(int)$c['id'], $c['name']];
            $companyLookup[$n . 'company'] = [(int)$c['id'], $c['name']];
        }
        $platoonLookup = []; // company_id => normalized platoon name => [id, name]
        foreach ($pdo->query("SELECT id, company_id, name FROM platoons") as $p) {
            $n = off_norm($p['name']);
            $short = str_replace('platoon', '', $n); // "1stplatoon" -> "1st"
            $entry = [(int)$p['id'], $p['name']];
            $platoonLookup[(int)$p['company_id']][$n] = $entry;
            if ($short !== '') {
                $platoonLookup[(int)$p['company_id']][$short] = $entry;
                // "1st" also reachable as "1"
                $digits = preg_replace('/\D/', '', $short);
                if ($digits !== '' && !isset($platoonLookup[(int)$p['company_id']][$digits])) {
                    $platoonLookup[(int)$p['company_id']][$digits] = $entry;
                }
            }
        }

        [$existingPeople, $existingSlots] = off_load_existing($pdo);

        $rows = [];
        $seenPeople = [];
        $seenSlots = [];
        $line = 1;
        $tooMany = false;
        while (($data = fgetcsv($h, 0, $delim)) !== false) {
            $line++;
            if (count($data) === 1 && trim((string)$data[0]) === '') continue; // blank line

            $name    = off_clean_cell($data[$map['full_name']] ?? '');
            $roleRaw = off_clean_cell($data[$map['role']] ?? '');
            $compRaw = off_clean_cell($data[$map['company']] ?? '');
            $platRaw = off_clean_cell($data[$map['platoon']] ?? '');
            if ($name === '' && $roleRaw === '' && $compRaw === '' && $platRaw === '') continue;

            if (count($rows) >= OFFICER_IMPORT_MAX_ROWS) { $tooMany = true; break; }

            $errors = [];

            // Name
            $parts = ['', '', ''];
            if ($name === '') $errors[] = 'Full name is required';
            elseif (mb_strlen($name) > 150) $errors[] = 'Full name is too long';
            else {
                $split = off_split_name($name);
                if ($split === null) $errors[] = 'Use the format "Last name, First name M."';
                else $parts = $split;
            }

            // Role
            $role = null;
            if ($roleRaw === '') $errors[] = 'Role is required';
            else {
                $role = off_norm_role($roleRaw);
                if ($role === null) $errors[] = 'Role must be Company Commander or Platoon Leader';
            }

            // Company
            $companyId = null;
            $companyName = $compRaw;
            if ($compRaw === '') $errors[] = 'Company is required';
            else {
                $c = $companyLookup[off_norm($compRaw)] ?? null;
                if ($c === null) $errors[] = 'Unknown company "' . $compRaw . '"';
                else { $companyId = $c[0]; $companyName = $c[1]; }
            }

            // Platoon (required for Platoon Leader, must be blank for Company Commander)
            $platoonId = null;
            $platoonName = '';
            if ($role === 'platoon_leader') {
                if ($platRaw === '') $errors[] = 'Platoon is required for a Platoon Leader';
                elseif ($companyId !== null) {
                    $p = $platoonLookup[$companyId][off_norm($platRaw)] ?? null;
                    if ($p === null) $errors[] = 'Unknown platoon "' . $platRaw . '" in ' . $companyName . ' Company';
                    else { $platoonId = $p[0]; $platoonName = $p[1]; }
                } else {
                    $platoonName = $platRaw;
                }
            } elseif ($role === 'company_commander') {
                if ($platRaw !== '') $errors[] = 'A Company Commander has no platoon; leave Platoon empty';
            } else {
                $platoonName = $platRaw;
            }

            $status = 'ok';
            $note = '';
            if ($errors) {
                $status = 'error';
                $note = implode('; ', $errors);
            } else {
                $pKey = off_person_key($parts[0], $parts[1]);
                $sKey = off_slot_key($role, $companyId, $platoonId);
                $slotLabel = $role === 'company_commander' ? "{$companyName} Company" : "{$companyName} {$platoonName}";

                if (isset($existingPeople[$pKey])) {
                    $status = 'duplicate';
                    $note = 'Already an active officer';
                } elseif (isset($seenPeople[$pKey])) {
                    $status = 'duplicate';
                    $note = 'Repeated in this file';
                } elseif (isset($existingSlots[$sKey])) {
                    $status = 'error';
                    $note = "{$slotLabel} already has a " . OFFICER_ROLE_LABELS[$role] . ' (' . $existingSlots[$sKey] . ')';
                } elseif (isset($seenSlots[$sKey])) {
                    $status = 'error';
                    $note = "{$slotLabel} is given to more than one " . OFFICER_ROLE_LABELS[$role] . ' in this file';
                } else {
                    $seenPeople[$pKey] = true;
                    $seenSlots[$sKey] = true;
                }
            }

            $rows[] = [
                'line'        => $line,
                'full_name'   => $name,
                'last_name'   => $parts[0],
                'first_name'  => $parts[1],
                'middle_name' => $parts[2],
                'role'        => $role ?? '',
                'role_label'  => $role ? OFFICER_ROLE_LABELS[$role] : $roleRaw,
                'company_id'  => $companyId ?? 0,
                'company'     => $companyName,
                'platoon_id'  => $platoonId,
                'platoon'     => $platoonName,
                'status'      => $status,
                'note'        => $note,
            ];
        }
        fclose($h);

        if (!$rows) {
            set_flash('error', 'No data rows found in the file.');
            redirect('s1/import_officers.php');
        }

        $_SESSION['officer_import'] = ['file' => $f['name'], 'rows' => $rows];
        if ($tooMany) {
            set_flash('warning', 'Only the first ' . OFFICER_IMPORT_MAX_ROWS . ' rows were read. Split the file and import the rest separately.');
        }
        redirect('s1/import_officers.php');
    }

    redirect('s1/import_officers.php');
}

// ---------- Page data ----------
$import = $_SESSION['officer_import'] ?? null;
$counts = ['ok' => 0, 'error' => 0, 'duplicate' => 0];
$ok_cc = 0;   // Company Commanders ready to save
$ok_pl = 0;   // Platoon Leaders ready to save (each gets a login account)
if ($import) {
    foreach ($import['rows'] as $r) {
        $counts[$r['status']]++;
        if ($r['status'] === 'ok') {
            if ($r['role'] === 'company_commander') $ok_cc++;
            elseif ($r['role'] === 'platoon_leader') $ok_pl++;
        }
    }
}
$total_rows = $import ? count($import['rows']) : 0;

$officers = $pdo->query("
    SELECT o.last_name, o.first_name, o.middle_name, o.role, c.name AS company, p.name AS platoon
    FROM officers o
    JOIN companies c ON c.id = o.company_id
    LEFT JOIN platoons p ON p.id = o.platoon_id
    WHERE o.status = 'active'
    ORDER BY c.name, o.role = 'platoon_leader', p.name, o.last_name, o.first_name
")->fetchAll(PDO::FETCH_ASSOC);

// Which positions are filled and which are still open (guides what the file needs to contain)
$ref_companies = $pdo->query("SELECT id, name FROM companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$ref_platoons  = [];
foreach ($pdo->query("SELECT company_id, name FROM platoons ORDER BY name") as $p) {
    $ref_platoons[(int)$p['company_id']][] = $p['name'];
}
$cc_filled = [];
$pl_filled = [];
foreach ($officers as $o) {
    $nm = $o['last_name'] . ', ' . $o['first_name'];
    if ($o['role'] === 'company_commander') $cc_filled[$o['company']] = $nm;
    else $pl_filled[$o['company'] . '|' . $o['platoon']] = $nm;
}
$open_slots = 0;
foreach ($ref_companies as $c) {
    if (!isset($cc_filled[$c['name']])) $open_slots++;
    foreach ($ref_platoons[(int)$c['id']] ?? [] as $pn) {
        if (!isset($pl_filled[$c['name'] . '|' . $pn])) $open_slots++;
    }
}

$fix_hints = [];
if ($import && $counts['error'] > 0) {
    $rules = [
        ['Use the format',                    'Write names as "Last name, First name M." with a comma after the last name.'],
        ['Full name is required',             'Fill in the Full Name cell.'],
        ['Role must be',                      'Role is Company Commander or Platoon Leader (CC and PL also work).'],
        ['Role is required',                  'Fill in the Role cell.'],
        ['Company is required',               'Fill in the Company cell.'],
        ['Unknown company',                   'Spell the company as listed on the upload screen.'],
        ['Unknown platoon',                   'Use a platoon that belongs to that company, for example 1st Platoon.'],
        ['Platoon is required',               'Platoon Leaders need a platoon.'],
        ['has no platoon',                    'Leave Platoon empty for a Company Commander.'],
        ['already has a',                     'That position is already filled. Remove the row, or change the existing officer first.'],
        ['more than one',                     'Each company has one Company Commander and each platoon one Platoon Leader. Keep one row per position.'],
    ];
    foreach ($rules as [$needle, $hint]) {
        $n = 0;
        foreach ($import['rows'] as $r) {
            if ($r['status'] === 'error' && strpos($r['note'], $needle) !== false) $n++;
        }
        if ($n > 0) $fix_hints[] = [$n, $hint];
    }
}

$self = BASE_URL . '/s1/import_officers.php';
$page_title = 'Import Officers';
require_once __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/s1_manage.css">

<div class="s1-rv-head">
    <div>
        <h1>Import Officers</h1>
        <p>Add Company Commanders and Platoon Leaders from a CSV file. Active officers on file: <strong><?= count($officers) ?></strong>.</p>
    </div>
    <div class="s1-rv-nav">
        <a href="<?= BASE_URL ?>/s1/officers.php" class="btn btn-secondary btn-sm">&larr; Back to officer roster</a>
    </div>
</div>

<?= s1_import_stepper($import ? 2 : 1) ?>

<?php if (!$import): ?>

    <div class="s1-rv-layout">
        <div class="s1-col">
            <div class="s1-card">
                <h3>Upload your CSV file</h3>
                <?= s1_import_dropzone($self, OFFICER_IMPORT_MAX_BYTES, '.csv only, up to 1 MB and ' . OFFICER_IMPORT_MAX_ROWS . ' rows') ?>
            </div>

            <div class="s1-card">
                <h3>What your file should look like</h3>
                <p class="s1-sub">Keep all four column headers. Extra columns such as Timestamp are ignored.</p>
                <div class="table-responsive" style="margin-bottom: 0;">
                    <table class="s1-sample">
                        <thead>
                            <tr>
                                <th>Full Name<span class="s1-req s1-req-must">Required</span></th>
                                <th>Role<span class="s1-req s1-req-must">Required</span></th>
                                <th>Company<span class="s1-req s1-req-must">Required</span></th>
                                <th>Platoon<span class="s1-req s1-req-opt">Platoon Leader only</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>Reyes, Carlo M.</td><td>Company Commander</td><td>Alpha</td><td class="is-blank">empty</td></tr>
                            <tr><td>Santos, Ana B.</td><td>Platoon Leader</td><td>Alpha</td><td>1st Platoon</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="s1-card">
                <h3>Current officers</h3>
                <?php if (!$officers): ?>
                    <p class="s1-muted" style="font-style: normal;">No officers have been imported yet.</p>
                <?php else: ?>
                    <div class="s1-rv-wrap" style="max-height: 360px; margin-bottom: 0;">
                        <table class="s1-table">
                            <thead>
                                <tr><th>Name</th><th>Role</th><th>Company</th><th>Platoon</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($officers as $o): ?>
                                    <tr>
                                        <td class="s1-cadet-name"><?= e($o['last_name'] . ', ' . $o['first_name'] . ($o['middle_name'] ? ' ' . $o['middle_name'] : '')) ?></td>
                                        <td><?= e(OFFICER_ROLE_LABELS[$o['role']] ?? $o['role']) ?></td>
                                        <td><?= e($o['company']) ?></td>
                                        <td><?= e($o['platoon'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <aside class="s1-rv-side s1-side-after" aria-label="Import guide">
            <div class="s1-card">
                <h3>Before you upload</h3>
                <ul class="s1-checks">
                    <li class="info"><?= s1_import_icon('info') ?><span>Write each name as <strong>Last name, First name M.</strong></span></li>
                    <li class="info"><?= s1_import_icon('info') ?><span>Role is <strong>Company Commander</strong> or <strong>Platoon Leader</strong>.</span></li>
                    <li class="info"><?= s1_import_icon('info') ?><span>A Platoon Leader needs a platoon. Leave Platoon empty for a Company Commander.</span></li>
                    <li class="info"><?= s1_import_icon('info') ?><span>One Company Commander per company and one Platoon Leader per platoon.</span></li>
                    <li class="info"><?= s1_import_icon('info') ?><span>Every Platoon Leader gets a login account. The temporary passwords are shown <strong>once</strong> on the Officer Roster after you save.</span></li>
                </ul>
            </div>
            <div class="s1-card">
                <h3>Positions <?= $open_slots > 0 ? '(' . $open_slots . ' open)' : '(all filled)' ?></h3>
                <ul class="s1-slots">
                    <?php foreach ($ref_companies as $c): ?>
                        <li>
                            <strong><?= e($c['name']) ?></strong>
                            <?php $cc = $cc_filled[$c['name']] ?? null; ?>
                            <span class="s1-slot <?= $cc === null ? 'open' : '' ?>" title="Company Commander"><?= $cc === null ? 'Commander: open' : 'Commander: ' . e($cc) ?></span>
                            <?php foreach ($ref_platoons[(int)$c['id']] ?? [] as $pn): ?>
                                <?php $pl = $pl_filled[$c['name'] . '|' . $pn] ?? null; ?>
                                <span class="s1-slot <?= $pl === null ? 'open' : '' ?>" title="Platoon Leader"><?= e($pn) ?>: <?= $pl === null ? 'open' : e($pl) ?></span>
                            <?php endforeach; ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$ref_companies): ?><li class="s1-muted">No companies set up yet</li><?php endif; ?>
                </ul>
            </div>
        </aside>
    </div>

<?php else: ?>

    <div class="s1-rv-layout">
        <section data-s1-review aria-label="Rows in your file">
            <?php if ($counts['ok'] === 0): ?>
                <div class="s1-alert s1-alert-bad" style="margin-bottom: 14px;">
                    <div class="s1-alert-body"><strong>Nothing can be saved from this file.</strong> Every row has an error or is already on the roster. Fix the file and upload it again.</div>
                </div>
            <?php elseif ($counts['error'] > 0): ?>
                <div class="s1-alert s1-alert-warn" style="margin-bottom: 14px;">
                    <div class="s1-alert-body"><strong><?= $counts['error'] ?> row(s) have errors and will be skipped.</strong> Fix them in your file and upload again, or save only the <?= $counts['ok'] ?> valid row(s).</div>
                    <button type="button" class="btn btn-secondary btn-sm" data-s1-filter-mark="error" aria-pressed="false">Show errors only</button>
                </div>
            <?php else: ?>
                <div class="s1-alert s1-alert-ok" style="margin-bottom: 14px;">
                    <div class="s1-alert-body"><strong>Every row looks good.</strong> Check the list, then save.</div>
                </div>
            <?php endif; ?>

            <div class="s1-tiles">
                <button type="button" class="s1-tile s1-tile-all" data-s1-filter-mark="all" aria-pressed="true"><span>All rows</span><strong><?= $total_rows ?></strong></button>
                <button type="button" class="s1-tile s1-tile-P" data-s1-filter-mark="ok" aria-pressed="false"><span>Ready to save</span><strong><?= $counts['ok'] ?></strong></button>
                <button type="button" class="s1-tile s1-tile-L" data-s1-filter-mark="duplicate" aria-pressed="false"><span>Duplicates</span><strong><?= $counts['duplicate'] ?></strong></button>
                <button type="button" class="s1-tile s1-tile-A" data-s1-filter-mark="error" aria-pressed="false"><span>Errors</span><strong><?= $counts['error'] ?></strong></button>
            </div>

            <div class="s1-rv-tools">
                <div class="s1-search">
                    <svg class="s1-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <input type="search" class="form-control" placeholder="Find a name" data-s1-marksearch aria-label="Find a name in this file" autocomplete="off">
                </div>
                <span class="s1-rv-count">Showing <strong data-s1-markshown><?= $total_rows ?></strong> of <?= $total_rows ?> &middot; File: <?= e($import['file']) ?></span>
            </div>

            <div class="s1-rv-wrap">
                <table class="s1-table">
                    <thead>
                        <tr>
                            <th>Line</th>
                            <th>Full Name</th>
                            <th>Role</th>
                            <th>Company</th>
                            <th>Platoon</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($import['rows'] as $r): ?>
                            <tr data-mark="<?= e($r['status']) ?>" data-name="<?= e(mb_strtolower($r['full_name'])) ?>"
                                class="<?= $r['status'] === 'error' ? 's1-row-err' : ($r['status'] === 'duplicate' ? 's1-row-dup' : '') ?>">
                                <td class="s1-cadet-code"><?= (int)$r['line'] ?></td>
                                <td class="s1-cadet-name"><?= e($r['full_name']) ?></td>
                                <td><?= e($r['role_label']) ?></td>
                                <td><?= e($r['company']) ?></td>
                                <td><?= e($r['platoon'] !== '' ? $r['platoon'] : '-') ?></td>
                                <td>
                                    <?= s1_import_pill($r['status']) ?>
                                    <?php if ($r['note'] !== ''): ?><div class="s1-res-note <?= $r['status'] === 'error' ? 'is-bad' : '' ?>"><?= e($r['note']) ?></div><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="s1-rv-empty" data-s1-markempty hidden>No row matches this view. Clear the search or pick another tile.</div>
            </div>
        </section>

        <aside class="s1-rv-side" aria-label="Save or cancel">
            <div class="s1-card s1-decide">
                <h3>Ready to save?</h3>
                <ul class="s1-checks">
                    <li class="<?= $counts['ok'] > 0 ? 'ok' : 'bad is-bad' ?>"><?= s1_import_icon($counts['ok'] > 0 ? 'ok' : 'bad') ?><span><strong><?= $counts['ok'] ?></strong> officer(s) will be saved (<?= $ok_cc ?> commander, <?= $ok_pl ?> platoon leader)</span></li>
                    <?php if ($ok_pl > 0): ?>
                        <li class="info"><?= s1_import_icon('info') ?><span><?= $ok_pl ?> login account(s) will be created. Copy the temporary passwords on the Officer Roster, they are shown only once.</span></li>
                    <?php endif; ?>
                    <?php if ($counts['duplicate'] > 0): ?>
                        <li class="info"><?= s1_import_icon('info') ?><span><?= $counts['duplicate'] ?> duplicate(s) skipped</span></li>
                    <?php endif; ?>
                    <?php if ($counts['error'] > 0): ?>
                        <li class="bad is-bad"><?= s1_import_icon('bad') ?><span><?= $counts['error'] ?> row(s) with errors skipped</span></li>
                    <?php endif; ?>
                </ul>

                <form action="<?= e($self) ?>" method="POST"
                      data-s1-confirm="Save <?= (int)$counts['ok'] ?> officer(s)?<?= $ok_pl > 0 ? ' ' . $ok_pl . ' login account(s) will be created and their temporary passwords are shown only once.' : '' ?>"
                      data-s1-confirm-title="Save imported officers" data-s1-confirm-ok="Save">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="confirm">
                    <button type="submit" class="btn btn-primary btn-block" <?= $counts['ok'] === 0 ? 'disabled' : '' ?>>Save <?= $counts['ok'] ?> officer(s)</button>
                </form>
                <form action="<?= e($self) ?>" method="POST" style="margin-top: 8px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="btn btn-secondary btn-block">Cancel and start over</button>
                </form>
                <?php if ($counts['error'] > 0): ?>
                    <hr>
                    <a href="<?= e($self) ?>?errors=1" class="btn btn-secondary btn-block">Download rows to fix (CSV)</a>
                    <p class="help">Fix the problems listed in the Reason column, then upload that file as a new import.</p>
                <?php endif; ?>
            </div>

            <?php if ($fix_hints): ?>
                <div class="s1-card">
                    <h3>How to fix the errors</h3>
                    <ul class="s1-fixlist">
                        <?php foreach ($fix_hints as [$n, $hint]): ?>
                            <li><b><?= $n ?></b><span><?= e($hint) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </aside>
    </div>

<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/js/s1_manage.js" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
