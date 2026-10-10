<?php
// UnitSync: small rendering helpers shared by the S1 review pages.
// Guarded so they can sit next to the same helpers defined inside view_attendance.php.

if (!function_exists('s1_stack')) {
    /** Stacked P/L/A/E bar (colors only, with tooltips). */
    function s1_stack(array $t, string $extra_class = ''): string {
        $p = (int)($t['p'] ?? 0); $l = (int)($t['l'] ?? 0); $a = (int)($t['a'] ?? 0); $e = (int)($t['e'] ?? 0);
        $tot = $p + $l + $a + $e;
        $cls = 's1-stack' . ($extra_class !== '' ? ' ' . $extra_class : '');
        if ($tot === 0) return '<div class="' . $cls . '" role="img" aria-label="No marks"></div>';
        $out = '<div class="' . $cls . '" role="img" aria-label="' . e("Present $p, Late $l, Absent $a, Excused $e") . '">';
        foreach (['p' => ['Present', $p], 'l' => ['Late', $l], 'a' => ['Absent', $a], 'e' => ['Excused', $e]] as $k => [$label, $n]) {
            if ($n <= 0) continue;
            $out .= '<i class="s1-seg-' . $k . '" style="width:' . round($n * 100 / $tot, 2) . '%" title="' . e($label . ': ' . $n) . '"></i>';
        }
        return $out . '</div>';
    }
}

if (!function_exists('s1_age_days')) {
    /** Whole days since a timestamp, or null when it is empty. */
    function s1_age_days(?string $ts): ?int {
        if (!$ts) return null;
        $t = strtotime($ts);
        return $t ? (int)floor((time() - $t) / 86400) : null;
    }
}

if (!function_exists('s1_ago')) {
    /** "5 min ago", "3 hours ago", "2 days ago". */
    function s1_ago(?string $ts): string {
        if (!$ts) return '';
        $t = strtotime($ts);
        if (!$t) return '';
        $s = max(0, time() - $t);
        if ($s < 90)     return 'just now';
        if ($s < 3600)   return (int)round($s / 60) . ' min ago';
        if ($s < 86400)  { $h = (int)floor($s / 3600); return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago'; }
        $d = (int)floor($s / 86400);
        return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
    }
}

if (!function_exists('s1_rate')) {
    /** Session attendance rate: (P + L) / (P + L + A) * 100, excused excluded. Null when nothing counts. */
    function s1_rate(int $p, int $l, int $a): ?float {
        $den = $p + $l + $a;
        return $den > 0 ? round(($p + $l) * 100 / $den, 1) : null;
    }
}

if (!function_exists('s1_tone')) {
    /** CSS tone for a percentage against the at-risk threshold: good, bad or na (no data). */
    function s1_tone(?float $pct, float $threshold): string {
        return $pct === null ? 'na' : ($pct < $threshold ? 'bad' : 'good');
    }
}
