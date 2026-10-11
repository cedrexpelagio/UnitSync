<?php
// Shared view helpers for the admin pages.
// Presentation only: nothing in here talks to the database.
// Requires helpers.php (e(), format_role_name()) to be loaded first.

if (!function_exists('ad_icon')) {

    /** Inline SVG icon (Feather-style). */
    function ad_icon(string $name, int $size = 18, string $class = ''): string {
        static $paths = [
            'users'      => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'inbox'      => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'calendar'   => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            'layers'     => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
            'clock'      => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'list'       => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
            'check'      => '<polyline points="20 6 9 17 4 12"/>',
            'check-circle' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'x'          => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'alert'      => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'info'       => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
            'search'     => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'key'        => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
            'arrow'      => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
            'arrow-left' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
            'plus'       => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'edit'       => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
            'help'       => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'filter'     => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
            'user-check' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>',
            'user-x'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="18" y1="8" x2="23" y2="13"/><line x1="23" y1="8" x2="18" y2="13"/>',
            'user-plus'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>',
            'mail'       => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
            'book'       => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
            'chevron'    => '<polyline points="6 9 12 15 18 9"/>',
            'zap'        => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
            'ban'        => '<circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>',
            'rotate'     => '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>',
            'lock'       => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'file'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
            'flag'       => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
            'refresh'    => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'trash'      => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>',
        ];
        $inner = $paths[$name] ?? $paths['info'];
        return '<svg' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ' width="' . $size . '" height="' . $size
            . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . $inner . '</svg>';
    }

    /** Stylesheet link. Call once, right after header.php. */
    function ad_css(): void {
        echo '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin.css?v=1">' . "\n";
    }

    /** Script tag. Call once, just before footer.php. */
    function ad_js(): void {
        echo '<script src="' . BASE_URL . '/assets/js/admin.js?v=1" defer></script>' . "\n";
    }

    /**
     * Page title block with an optional "Guide" button.
     * $actions is trusted HTML (buttons/links built by the page).
     */
    function ad_head(string $title, string $desc, string $actions = '', ?string $guide_id = null): void {
        echo '<header class="ad-head ad-rise"><div><h1 class="ad-title">' . e($title) . '</h1><p class="ad-lede">' . e($desc) . '</p></div>';
        echo '<div class="ad-head-actions">';
        if ($guide_id !== null) {
            echo '<button type="button" class="btn btn-secondary btn-sm ad-js-only" data-guide-toggle="' . e($guide_id) . '" aria-expanded="true" aria-controls="guide-' . e($guide_id) . '">'
                . ad_icon('help', 16) . 'How this page works</button>';
        }
        echo $actions . '</div></header>';
    }

    /** Collapsible numbered guide. Open on first visit; remembers when closed. */
    function ad_guide(string $id, string $title, array $steps): void {
        echo '<aside class="ad-guide ad-rise" style="--i:1" id="guide-' . e($id) . '" data-guide="' . e($id) . '" aria-label="' . e($title) . '"><div class="ad-guide-wrap"><div class="ad-guide-box">';
        echo '<div class="ad-guide-top">' . ad_icon('book', 18) . '<span class="ad-guide-title">' . e($title) . '</span></div>';
        echo '<button type="button" class="ad-guide-close ad-js-only" data-guide-close aria-label="Hide guide">' . ad_icon('x', 16) . '</button>';
        echo '<ol>';
        foreach ($steps as [$head, $text]) {
            echo '<li><strong>' . e($head) . '</strong><span>' . e($text) . '</span></li>';
        }
        echo '</ol></div></div></aside>';
    }

    /** Label + control + inline error slot. $control is trusted HTML. */
    function ad_field(string $name, string $label, string $control, string $err = '', string $hint = '', bool $required = true): string {
        return '<div class="ad-field' . ($err !== '' ? ' has-error' : '') . '" data-field="' . e($name) . '">'
            . '<label for="' . e($name) . '">' . e($label) . ($required ? ' <span class="ad-req" aria-hidden="true">*</span>' : '') . '</label>'
            . $control
            . ($hint !== '' ? '<span class="ad-hint">' . e($hint) . '</span>' : '')
            . '<p class="ad-err" id="' . e($name) . '-err" role="alert">' . e($err) . '</p>'
            . '</div>';
    }

    function ad_initials(?string $first, ?string $last): string {
        $a = mb_strtoupper(mb_substr(trim((string)$first), 0, 1));
        $b = mb_strtoupper(mb_substr(trim((string)$last), 0, 1));
        return ($a . $b) !== '' ? $a . $b : '?';
    }

    function ad_plural(int $n, string $one, ?string $many = null): string {
        return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's'));
    }

    /** "Program: BSIT" / "Alpha - 1st Platoon" / "—" */
    function ad_assignment(array $u): string {
        if (($u['role'] ?? '') === 'class_president') {
            return !empty($u['prog_code']) ? 'Program ' . $u['prog_code'] : 'Unassigned';
        }
        if (($u['role'] ?? '') === 'platoon_leader') {
            $parts = array_filter([$u['company_name'] ?? '', $u['platoon_name'] ?? '']);
            return $parts ? implode(' - ', $parts) : 'Unassigned';
        }
        return '—';
    }

    function ad_time_ago(?string $ts): string {
        if (!$ts) return '';
        $t = strtotime($ts);
        if (!$t) return '';
        $d = time() - $t;
        if ($d < 60) return 'just now';
        if ($d < 3600) return ad_plural((int)floor($d / 60), 'minute') . ' ago';
        if ($d < 86400) return ad_plural((int)floor($d / 3600), 'hour') . ' ago';
        if ($d < 86400 * 14) return ad_plural((int)floor($d / 86400), 'day') . ' ago';
        return date('M j, Y', $t);
    }

    /* ----------------------------- audit helpers ----------------------------- */

    /** good | bad | warn | info | neutral */
    function ad_audit_tone(string $action): string {
        $a = strtolower($action);
        if (preg_match('/(reject|deactivat|cancel|delete|remove|lock)/', $a)) return 'bad';
        if (preg_match('/(approve|reactivat|activate|create|add|held|login)/', $a)) return 'good';
        if (preg_match('/(reset|update|change|edit|status)/', $a)) return 'warn';
        if (preg_match('/(generate|bulk)/', $a)) return 'info';
        return 'neutral';
    }

    function ad_audit_icon(string $action): string {
        $a = strtolower($action);
        if (strpos($a, 'reject') !== false) return 'user-x';
        if (strpos($a, 'approve') !== false) return 'user-check';
        if (strpos($a, 'deactivat') !== false) return 'ban';
        if (strpos($a, 'reactivat') !== false) return 'rotate';
        if (strpos($a, 'password') !== false) return 'key';
        if (strpos($a, 'generate') !== false) return 'zap';
        if (strpos($a, 'session') !== false || strpos($a, 'term') !== false) return 'calendar';
        if (strpos($a, 'create') !== false) return 'plus';
        if (strpos($a, 'update') !== false || strpos($a, 'change') !== false) return 'edit';
        return 'file';
    }

    function ad_label(string $s): string {
        return ucwords(str_replace('_', ' ', $s));
    }

    function ad_fmt_value($v): string {
        if ($v === null || $v === '') return '—';
        if (is_bool($v)) return $v ? 'Yes' : 'No';
        if (is_array($v)) {
            $flat = array_map(static fn($x) => is_scalar($x) ? (string)$x : json_encode($x), $v);
            return count($flat) > 6 ? count($flat) . ' items' : implode(', ', $flat);
        }
        $s = (string)$v;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && ($t = strtotime($s))) return date('M j, Y', $t);
        return $s;
    }

    /**
     * Turn an audit "details" value into readable markup.
     * Plain sentences stay as text; JSON becomes labelled chips, and
     * old/new pairs become "old -> new" so you can see what changed.
     */
    function ad_audit_details(?string $raw): string {
        if ($raw === null || trim($raw) === '') return '';
        $data = json_decode($raw, true);
        if (!is_array($data) || $data === []) {
            return '<p class="ad-detail-text">' . e($raw) . '</p>';
        }

        $chips = [];
        $chip = static function (string $label, string $valueHtml): string {
            return '<div><dt>' . e($label) . '</dt><dd>' . $valueHtml . '</dd></div>';
        };

        // Edited record: { old: {...}, new: {...} }
        if (isset($data['old'], $data['new']) && is_array($data['old']) && is_array($data['new'])) {
            foreach ($data['new'] as $k => $new) {
                $old = $data['old'][$k] ?? null;
                if ((string)ad_fmt_value($old) === (string)ad_fmt_value($new)) continue;
                $chips[] = $chip(ad_label((string)$k), '<s>' . e(ad_fmt_value($old)) . '</s> <span class="ad-arrow">&rarr;</span> <strong>' . e(ad_fmt_value($new)) . '</strong>');
            }
            if (!$chips) $chips[] = $chip('Change', 'No fields changed');
            unset($data['old'], $data['new']);
        }

        // Status change: { from, to }
        if (array_key_exists('from', $data) && array_key_exists('to', $data) && !is_array($data['from'])) {
            $chips[] = $chip('Status', '<s>' . e(ad_label((string)$data['from'])) . '</s> <span class="ad-arrow">&rarr;</span> <strong>' . e(ad_label((string)$data['to'])) . '</strong>');
            unset($data['from'], $data['to']);
        }

        foreach ($data as $k => $v) {
            $key = (string)$k;
            if ($key === 'session_ids' && is_array($v)) {
                $chips[] = $chip('Sessions', e(ad_plural(count($v), 'session')));
                continue;
            }
            if ($key === 'term_id') continue; // internal id, adds noise
            $chips[] = $chip(ad_label($key), e(ad_fmt_value($v)));
        }

        if (!$chips) return '';
        return '<dl class="ad-kvl">' . implode('', $chips) . '</dl>';
    }
}
