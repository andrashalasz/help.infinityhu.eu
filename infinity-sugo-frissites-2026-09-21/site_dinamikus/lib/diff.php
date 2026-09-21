<?php
/**
 * diff.php - szoveg-osszehasonlitas a Word-import "mi valtozott?" nezetehez.
 *
 * Szo szintu diff: kozos eleje/vege levagasa, majd LCS a maradekra.
 * Nagyon hosszu fejezeteknel (ahol az LCS mar draga lenne) bekezdes
 * szintre valtunk - a kimenet ugyanolyan, csak durvabb felbontasu.
 */
declare(strict_types=1);

/** @return list<string> */
function diff_tokens(string $text): array
{
    $t = preg_split('/(\s+)/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values($t);
}

/**
 * Hasonlosag 0..100 kozott, token-halmaz atfedes alapjan (Dice-egyuttható).
 * Gyors, es hosszu szovegen is ertelmes szamot ad.
 */
function diff_similarity(string $a, string $b): float
{
    $ta = diff_tokens(mb_strtolower($a));
    $tb = diff_tokens(mb_strtolower($b));
    if (!$ta && !$tb) { return 100.0; }
    if (!$ta || !$tb) { return 0.0; }

    $ca = array_count_values($ta);
    $cb = array_count_values($tb);
    $common = 0;
    foreach ($ca as $tok => $n) {
        if (isset($cb[$tok])) { $common += min($n, $cb[$tok]); }
    }
    return round(200.0 * $common / (count($ta) + count($tb)), 2);
}

/**
 * @return array{html:string, added:int, removed:int, changed:bool}
 *   html: <ins>/<del> jelolessel ellatott szoveg
 */
function diff_html(string $old, string $new): array
{
    $a = diff_tokens($old);
    $b = diff_tokens($new);

    // kozos eleje / vege
    $pre = 0;
    $maxPre = min(count($a), count($b));
    while ($pre < $maxPre && $a[$pre] === $b[$pre]) { $pre++; }

    $suf = 0;
    while ($suf < (min(count($a), count($b)) - $pre) && $a[count($a) - 1 - $suf] === $b[count($b) - 1 - $suf]) { $suf++; }

    $midA = array_slice($a, $pre, count($a) - $pre - $suf);
    $midB = array_slice($b, $pre, count($b) - $pre - $suf);

    if (!$midA && !$midB) {
        return ['html' => diff_esc_join($a), 'added' => 0, 'removed' => 0, 'changed' => false];
    }

    // Tul nagy kulonbseg eseten nem futtatunk LCS-t (a tabla memoriaigenye
    // negyzetesen no), csak kiirjuk a ket blokkot egymas utan.
    $ops = (count($midA) * count($midB) > 250_000)
        ? [['-', $midA], ['+', $midB]]
        : diff_lcs_ops($midA, $midB);

    $out = '';
    if ($pre > 0) { $out .= diff_esc_join(array_slice($a, 0, $pre)) . ' '; }

    $added = 0; $removed = 0;
    foreach ($ops as [$op, $toks]) {
        if (!$toks) { continue; }
        $txt = diff_esc_join($toks);
        if ($op === '=') { $out .= $txt . ' '; }
        elseif ($op === '-') { $removed += count($toks); $out .= '<del>' . $txt . '</del> '; }
        else { $added += count($toks); $out .= '<ins>' . $txt . '</ins> '; }
    }

    if ($suf > 0) { $out .= diff_esc_join(array_slice($a, count($a) - $suf)); }

    return [
        'html'    => trim($out),
        'added'   => $added,
        'removed' => $removed,
        'changed' => $added > 0 || $removed > 0,
    ];
}

function diff_esc_join(array $tokens): string
{
    return htmlspecialchars(implode(' ', $tokens), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Klasszikus LCS visszafejtessel, osszevont futamokra.
 * @return list<array{0:string,1:list<string>}>
 */
function diff_lcs_ops(array $a, array $b): array
{
    $n = count($a);
    $m = count($b);
    if ($n === 0) { return $m ? [['+', $b]] : []; }
    if ($m === 0) { return [['-', $a]]; }

    // csak az aktualis es az elozo sort tartjuk meg -> O(min) memoria,
    // de a visszafejteshez teljes tabla kell, ezert kompakt int-tomb
    $L = [];
    for ($i = 0; $i <= $n; $i++) { $L[$i] = array_fill(0, $m + 1, 0); }
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $L[$i][$j] = $a[$i] === $b[$j]
                ? $L[$i + 1][$j + 1] + 1
                : max($L[$i + 1][$j], $L[$i][$j + 1]);
        }
    }

    $ops = [];
    $push = function (string $op, string $tok) use (&$ops): void {
        $last = count($ops) - 1;
        if ($last >= 0 && $ops[$last][0] === $op) { $ops[$last][1][] = $tok; }
        else { $ops[] = [$op, [$tok]]; }
    };

    $i = 0; $j = 0;
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) { $push('=', $a[$i]); $i++; $j++; }
        elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) { $push('-', $a[$i]); $i++; }
        else { $push('+', $b[$j]); $j++; }
    }
    while ($i < $n) { $push('-', $a[$i++]); }
    while ($j < $m) { $push('+', $b[$j++]); }

    return $ops;
}
