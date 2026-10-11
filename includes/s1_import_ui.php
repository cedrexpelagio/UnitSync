<?php
// UnitSync: shared pieces of the S1 CSV import screens (cadets and officers).
// Needs e(), csrf_field() and BASE_URL from the page that includes it.

if (!function_exists('s1_import_stepper')) {
    /** Three-step progress strip. $current is 1 (upload), 2 (review) or 3 (saved). */
    function s1_import_stepper(int $current): string {
        $steps = [
            ['Prepare and upload', 'Pick your CSV file'],
            ['Review and fix', 'Check every row before saving'],
            ['Saved', 'Added to the roster'],
        ];
        $out = '<ol class="s1-stepper" aria-label="Import progress">';
        foreach ($steps as $i => [$name, $note]) {
            $n = $i + 1;
            $cls = $n < $current ? 'is-done' : ($n === $current ? 'is-current' : '');
            $out .= '<li class="s1-step ' . $cls . '"><span class="s1-step-dot">' . ($n < $current ? '&#10003;' : $n) . '</span>'
                  . '<div><b>' . e($name) . '</b><small>' . e($note) . '</small></div></li>';
        }
        return $out . '</ol>';
    }
}

if (!function_exists('s1_import_icon')) {
    /** Small inline icon for checklists: ok, bad or info. */
    function s1_import_icon(string $type): string {
        $paths = [
            'ok'   => '<path d="M20 6 9 17l-5-5"/>',
            'bad'  => '<path d="M18 6 6 18M6 6l12 12"/>',
            'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        ];
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
             . ($paths[$type] ?? $paths['info']) . '</svg>';
    }
}

if (!function_exists('s1_import_pill')) {
    /** Result pill for one preview row. */
    function s1_import_pill(string $status): string {
        $map = ['ok' => ['active', 'Ready'], 'duplicate' => ['unassigned', 'Duplicate'], 'error' => ['dropped', 'Error']];
        [$cls, $label] = $map[$status] ?? ['unassigned', ucfirst($status)];
        return '<span class="s1-status s1-status-' . $cls . '"><i></i>' . e($label) . '</span>';
    }
}

if (!function_exists('s1_import_dropzone')) {
    /** The upload form with a drag-and-drop area. Works as a plain file input without JavaScript. */
    function s1_import_dropzone(string $action_url, int $max_bytes, string $limits_label): string {
        ob_start(); ?>
        <form action="<?= e($action_url) ?>" method="POST" enctype="multipart/form-data" data-s1-upload>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <label class="s1-drop" data-s1-drop data-max-bytes="<?= (int)$max_bytes ?>" for="csv_file">
                <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                <span class="s1-drop-empty" data-s1-drop-empty>
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span class="s1-drop-title">Drop your CSV file here</span>
                    <span>or <u>browse your computer</u></span>
                    <small><?= e($limits_label) ?></small>
                </span>
                <span class="s1-drop-file" data-s1-drop-file hidden>
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <strong data-s1-drop-name></strong>
                    <button type="button" class="s1-linkbtn" data-s1-drop-clear style="background:none;border:none;cursor:pointer;">Choose a different file</button>
                </span>
            </label>
            <p class="s1-drop-msg" data-s1-drop-msg role="alert" hidden></p>
            <div class="s1-actions">
                <button type="submit" class="btn btn-primary" data-s1-drop-submit>Upload and preview</button>
                <a class="btn btn-secondary" href="<?= e($action_url) ?>?template=1">Download CSV template</a>
            </div>
        </form>
        <?php
        return (string)ob_get_clean();
    }
}
