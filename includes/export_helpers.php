<?php
// UnitSync: shared rendering for branded Excel and PDF exports (S1)
// Excel = HTML table served as .xls (opens in Excel with colors intact).
// PDF   = same table in a print-optimized page; the browser's "Save as PDF" produces the file.

function exp_colors(): array {
    return [
        'P' => ['label' => 'Present', 'solid' => '#16A34A'],
        'L' => ['label' => 'Late',    'solid' => '#D97706'],
        'A' => ['label' => 'Absent',  'solid' => '#DC2626'],
        'E' => ['label' => 'Excused', 'solid' => '#2563EB'],
    ];
}

function exp_brand(PDO $pdo): array {
    return [
        'system' => 'UnitSync',
        'unit'   => (string)get_setting($pdo, 'unit_name', 'Cadet Battalion'),
        'tag'    => 'Cadet Management System',
    ];
}

function exp_format(): string {
    $f = $_GET['format'] ?? 'pdf';
    return ($f === 'xls') ? 'xls' : 'pdf';
}

/**
 * Grouped "Export" dropdown (works without JavaScript, uses <details>).
 * $items: [label => [script_path_under_s1, extra_params, hint]]; current $_GET filters are carried over (page dropped).
 * Styles are emitted once; they move to the new S1 stylesheet in a later step.
 */
function exp_menu(array $items): string {
    static $styled = false;
    $base = $_GET;
    unset($base['page'], $base['format']);
    $html = '';
    if (!$styled) {
        $styled = true;
        $html .= '<style>
            .exp-menu{position:relative;display:inline-block}
            .exp-menu>summary{list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:7px 12px;font-size:13px;font-weight:600;border:1px solid var(--gray-300);border-radius:var(--radius-default);background:var(--white);color:var(--green-900)}
            .exp-menu>summary::-webkit-details-marker{display:none}
            .exp-menu>summary:hover,.exp-menu[open]>summary{background:var(--green-100);border-color:var(--green-700)}
            .exp-menu-panel{position:absolute;right:0;top:calc(100% + 6px);min-width:250px;background:var(--white);border:1px solid var(--gray-300);border-radius:var(--radius-default);box-shadow:0 6px 18px rgba(0,0,0,.15);z-index:60;overflow:hidden}
            .exp-menu-panel a{display:block;padding:10px 14px;text-decoration:none;color:var(--green-900);border-bottom:1px solid var(--gray-50)}
            .exp-menu-panel a:last-child{border-bottom:none}
            .exp-menu-panel a:hover{background:var(--green-100)}
            .exp-menu-panel strong{display:block;font-size:13px}
            .exp-menu-panel small{display:block;font-size:11px;color:var(--gray-700)}
        </style>';
    }
    $html .= '<details class="exp-menu"><summary>'
        . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'
        . 'Export <span aria-hidden="true">&#9662;</span></summary><div class="exp-menu-panel">';
    foreach ($items as $label => [$script, $extra, $hint]) {
        $qs = http_build_query($extra + $base);
        $new_tab = (($extra['format'] ?? '') === 'pdf') ? ' target="_blank" rel="noopener"' : '';
        $html .= '<a href="' . e(BASE_URL . '/s1/' . $script . '?' . $qs) . '"' . $new_tab . '><strong>' . e($label) . '</strong><small>' . e($hint) . '</small></a>';
    }
    return $html . '</div></details>';
}

/** Send headers and open the document + <table>. */
function exp_begin(string $format, string $title, string $filename_base, bool $landscape = true): void {
    $filename = $filename_base . '_' . date('Y-m-d') . '.' . ($format === 'xls' ? 'xls' : 'pdf.html');
    if ($format === 'xls') {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    } else {
        header('Content-Type: text/html; charset=utf-8');
    }
    header('Pragma: no-cache');
    header('Expires: 0');

    $page = $landscape ? 'A4 landscape' : 'A4 portrait';
    echo '<!DOCTYPE html><html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8">';
    echo '<title>' . e($title) . '</title>';
    if ($format === 'xls') {
        echo '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>' . e(mb_substr($title, 0, 28)) . '</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
    }
    echo '<style>
        @page { size: ' . $page . '; margin: 10mm; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #1f2937; margin: 0; }
        table.exp { border-collapse: collapse; width: 100%; }
        table.exp td, table.exp th { border: 1px solid #D5D9D2; padding: 4px 6px; vertical-align: middle; }
        td.brand { background: #243321; color: #ffffff; font-size: 18px; font-weight: bold; border: none; padding: 10px 12px; }
        td.brand-sub { background: #243321; color: #E8C75A; font-size: 11px; border: none; padding: 0 12px 10px; }
        td.brand-gold { background: #C9A227; height: 4px; font-size: 1px; border: none; padding: 0; }
        td.doc-title { font-size: 16px; font-weight: bold; color: #243321; border: none; padding: 12px 6px 2px; }
        td.doc-sub { font-size: 11px; color: #4A4F47; border: none; padding: 0 6px 8px; }
        td.meta { border: none; font-size: 11px; padding: 1px 6px; color: #4A4F47; }
        td.legend-h { border: none; font-weight: bold; font-size: 11px; padding: 8px 6px 2px; color: #243321; }
        td.lg-sw { width: 28px; border: 1px solid #9CA3AF; }
        td.lg-tx { border: none; padding: 2px 14px 2px 4px; font-size: 11px; }
        th.h { background: #3B5232; color: #ffffff; border: 1px solid #243321; border-bottom: 3px solid #C9A227; text-align: center; font-size: 11px; font-weight: bold; }
        th.h-left { text-align: left; }
        tr.zebra td { background: #F7F8F6; }
        td.sess { text-align: center; min-width: 34px; }
        td.num { text-align: center; }
        td.risk { background: #FEE2E2; color: #991B1B; font-weight: bold; text-align: center; }
        td.sp { border: none; height: 18px; }
        td.sig-box { border: none; padding: 34px 24px 0; vertical-align: bottom; text-align: center; }
        .sig-line { border-top: 1px solid #111827; padding-top: 4px; font-weight: bold; }
        .sig-role { font-size: 10px; color: #4A4F47; }
        td.foot { border: none; font-size: 9px; color: #6B7280; padding-top: 12px; }
        .toolbar { position: sticky; top: 0; background: #243321; padding: 10px 16px; display: flex; gap: 10px; align-items: center; color: #fff; }
        .toolbar a, .toolbar button { background: #C9A227; color: #243321; border: none; border-radius: 6px; padding: 8px 14px; font-weight: bold; font-size: 13px; cursor: pointer; text-decoration: none; }
        .toolbar a.ghost { background: transparent; color: #fff; border: 1px solid #fff; }
        .toolbar span { font-size: 12px; opacity: .85; }
        .wrap { padding: 12px 16px; }
        * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        @media print { .toolbar { display: none; } .wrap { padding: 0; } thead { display: table-header-group; } tr { page-break-inside: avoid; } }
    </style></head><body>';
    if ($format === 'pdf') {
        $back = e($_SERVER['HTTP_REFERER'] ?? 'javascript:history.back()');
        echo '<div class="toolbar"><a class="ghost" href="' . $back . '">&larr; Back</a>
              <button type="button" onclick="window.print()">Save as PDF / Print</button>
              <span>In the print dialog, choose &ldquo;Save as PDF&rdquo; as the destination. Background graphics are kept for the colors.</span></div>';
    }
    echo '<div class="wrap"><table class="exp">';
}

/** Brand band, document title, meta lines. */
function exp_banner(PDO $pdo, int $cols, string $doc_title, string $doc_sub, array $meta): void {
    $b = exp_brand($pdo);
    echo '<tr><td class="brand" colspan="' . $cols . '">' . e($b['system']) . ' &nbsp;|&nbsp; ' . e($b['unit']) . '</td></tr>';
    echo '<tr><td class="brand-sub" colspan="' . $cols . '">' . e($b['tag']) . ' &mdash; Official S1 Report</td></tr>';
    echo '<tr><td class="brand-gold" colspan="' . $cols . '">&nbsp;</td></tr>';
    echo '<tr><td class="doc-title" colspan="' . $cols . '">' . e($doc_title) . '</td></tr>';
    if ($doc_sub !== '') echo '<tr><td class="doc-sub" colspan="' . $cols . '">' . e($doc_sub) . '</td></tr>';
    foreach ($meta as $label => $value) {
        echo '<tr><td class="meta" colspan="' . $cols . '"><strong>' . e((string)$label) . ':</strong> ' . e((string)$value) . '</td></tr>';
    }
}

/** Legend row. $with_attendance adds the P/L/A/E color swatches; $extra adds custom [label => hex]. */
function exp_legend(int $cols, bool $with_attendance, array $extra = []): void {
    $items = [];
    if ($with_attendance) {
        foreach (exp_colors() as $c) $items[$c['label']] = $c['solid'];
        $items['No record'] = '#FFFFFF';
    }
    foreach ($extra as $label => $hex) $items[$label] = $hex;
    if (!$items) return;
    echo '<tr><td class="legend-h" colspan="' . $cols . '">Legend</td></tr>';
    echo '<tr><td colspan="' . $cols . '" style="border:none;padding:0 6px;"><table style="border-collapse:collapse;"><tr>';
    foreach ($items as $label => $hex) {
        echo '<td class="lg-sw" bgcolor="' . e($hex) . '" style="background:' . e($hex) . ';">&nbsp;</td><td class="lg-tx">' . e((string)$label) . '</td>';
    }
    echo '</tr></table></td></tr>';
    echo '<tr><td class="sp" colspan="' . $cols . '">&nbsp;</td></tr>';
}

/** Signature block for Brigade S1 and Brigade Commander, then close the document. */
function exp_end(string $format, int $cols, string $generated_by): void {
    $half = max(1, intdiv($cols, 2));
    $rest = max(1, $cols - $half);
    echo '<tr><td class="sp" colspan="' . $cols . '">&nbsp;</td></tr>';
    echo '<tr><td class="legend-h" colspan="' . $cols . '">Certification</td></tr>';
    echo '<tr><td class="meta" colspan="' . $cols . '">I certify that the information in this report has been checked and is accurate.</td></tr>';
    echo '<tr>';
    foreach ([[$half, 'Brigade S1'], [$rest, 'Brigade Commander']] as [$span, $role]) {
        echo '<td class="sig-box" colspan="' . $span . '"><div class="sig-line">&nbsp;</div>'
           . '<div class="sig-role">Signature over printed name</div><div class="sig-role"><strong>' . e($role) . '</strong></div>'
           . '<div class="sig-role">Date: ____________________</div></td>';
    }
    echo '</tr>';
    echo '<tr><td class="foot" colspan="' . $cols . '">Generated by ' . e($generated_by) . ' on ' . e(date('F j, Y g:i A')) . ' &mdash; UnitSync. This document is valid only when signed.</td></tr>';
    echo '</table></div>';
    if ($format === 'pdf') echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},400);});</script>';
    echo '</body></html>';
}
