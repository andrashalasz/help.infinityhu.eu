<?php
/**
 * frissito_csatorna.php - az EGYETLEN hely, ahol a PHP a frissito megosztott
 * mappajahoz (a "csatornahoz") nyul.
 *
 * A web itt soha nem futtat parancsot. Egyetlen fajlt ir: request.json. Minden
 * mast (status.json, history.jsonl, log/<id>.log) a gazdagepen futo
 * deploy/sugo-frissito.py ir, a PHP csak olvassa - meretkorlattal, link
 * kovetese nelkul, es mindent a frissito_szabalyok.php ellenoriz.
 */
declare(strict_types=1);

require_once __DIR__ . '/frissito_szabalyok.php';

function frc_dir(array $cfg): string
{
    $d = (string)($cfg['update_dir'] ?? '/srv/frissites');
    // csak abszolut utvonal - relativ utvonal a munkakonyvtartol fuggene
    return str_starts_with($d, '/') ? rtrim($d, '/') : '/srv/frissites';
}

/** 'OK' | 'MISSING' | 'UNWRITABLE' */
function frc_channel_state(array $cfg): string
{
    $d = frc_dir($cfg);
    if (is_link($d) || !is_dir($d)) { return 'MISSING'; }
    return is_writable($d) ? 'OK' : 'UNWRITABLE';
}

/**
 * Kis fajl beolvasasa: link nem lehet, szabalyos fajl legyen, meretkorlattal.
 * $tail: tul nagy fajlnal a VEGET adja (az elso, csonka sort eldobva).
 * @return array{state:string,text?:string} state: OK | MISSING | TOO_LARGE | ERROR
 */
function frc_read_capped(string $path, int $max, bool $tail = false): array
{
    clearstatcache(true, $path);
    if (!file_exists($path) && !is_link($path)) { return ['state' => 'MISSING']; }
    if (is_link($path) || !is_file($path)) { return ['state' => 'ERROR']; }
    $size = filesize($path);
    if ($size === false) { return ['state' => 'ERROR']; }
    if ($size > $max && !$tail) { return ['state' => 'TOO_LARGE']; }
    $fh = @fopen($path, 'rb');
    if ($fh === false) { return ['state' => 'ERROR']; }
    try {
        if ($size > $max) {
            fseek($fh, -$max, SEEK_END);
            $text = (string)fread($fh, $max);
            $nl = strpos($text, "\n");
            $text = $nl === false ? '' : substr($text, $nl + 1);
        } else {
            $text = (string)stream_get_contents($fh, $max + 1);
        }
    } finally {
        fclose($fh);
    }
    return ['state' => 'OK', 'text' => $text];
}

/** null = nincs allapotfajl; kulonben az fr_parse_status() eredmenye. */
function frc_read_status(array $cfg): ?array
{
    $r = frc_read_capped(frc_dir($cfg) . '/status.json', FR_STATUS_MAX_BYTES);
    if ($r['state'] === 'MISSING') { return null; }
    if ($r['state'] === 'TOO_LARGE') {
        return ['ok' => false, 'problem' => 'TOO_LARGE', 'message' => FR_STATUS_PROBLEM_TEXT['TOO_LARGE'], 'detail' => ''];
    }
    if ($r['state'] !== 'OK') {
        return ['ok' => false, 'problem' => 'NOT_JSON', 'message' => FR_STATUS_PROBLEM_TEXT['NOT_JSON'], 'detail' => ''];
    }
    return fr_parse_status($r['text']);
}

function frc_read_history(array $cfg, int $limit = FR_HISTORY_SHOWN): array
{
    $r = frc_read_capped(frc_dir($cfg) . '/history.jsonl', FR_HISTORY_MAX_BYTES, true);
    if ($r['state'] !== 'OK') { return ['items' => [], 'skipped' => 0]; }
    return fr_parse_history($r['text'], $limit);
}

/** @return array{state:string,request:?array} state: NONE | UNREADABLE | FOUND */
function frc_read_request(array $cfg): array
{
    $r = frc_read_capped(frc_dir($cfg) . '/request.json', FR_REQUEST_MAX_BYTES);
    if ($r['state'] === 'MISSING') { return ['state' => 'NONE', 'request' => null]; }
    if ($r['state'] !== 'OK') { return ['state' => 'UNREADABLE', 'request' => null]; }
    $req = fr_parse_request_file($r['text']);
    return $req === null ? ['state' => 'UNREADABLE', 'request' => null] : ['state' => 'FOUND', 'request' => $req];
}

/** A naplo vege. Az azonositot a hivo a frissito altal adott listabol valasztja. */
function frc_read_log_tail(array $cfg, string $id): array
{
    if (!fr_is_uuid($id)) { return ['state' => 'ERROR', 'lines' => [], 'truncated' => false]; }
    $r = frc_read_capped(frc_dir($cfg) . '/log/' . $id . '.log', FR_LOG_TAIL_BYTES, true);
    if ($r['state'] === 'MISSING') { return ['state' => 'MISSING', 'lines' => [], 'truncated' => false]; }
    if ($r['state'] !== 'OK') { return ['state' => 'ERROR', 'lines' => [], 'truncated' => false]; }
    $t = fr_tail_lines($r['text']);
    return ['state' => 'OK', 'lines' => $t['lines'], 'truncated' => $t['truncated']];
}

/** Veletlen UUID v4. */
function frc_uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}

/**
 * A keresfajl kiirasa ATOMIAN: ideiglenes fajl (x = O_EXCL), majd atnevezes.
 * Az ideiglenes nev nem .json-ra vegzodik, igy a frissito sosem veszi kerelemnek.
 * Fuggo kerest soha nem ir felul; egy mar felhasznaltat csak $replaceConsumed eseten.
 *
 * @return array{ok:bool,error?:string,id?:string,requestedAt?:string}
 */
function frc_write_request(array $cfg, string $commit, int $userId, bool $replaceConsumed): array
{
    $dir = frc_dir($cfg);
    $target = $dir . '/request.json';
    clearstatcache(true, $target);
    if (!$replaceConsumed && (file_exists($target) || is_link($target))) {
        return ['ok' => false, 'error' => 'EXISTS'];
    }
    $id = frc_uuid4();
    $at = date('c');
    $body = fr_build_request_file($id, $commit, $at, $userId);
    if ($body === null) { return ['ok' => false, 'error' => 'INVALID']; }

    $tmp = $dir . '/.request-' . $id . '.tmp';
    $fh = @fopen($tmp, 'xb');
    if ($fh === false) { return ['ok' => false, 'error' => 'TMP_CREATE']; }
    try {
        if (fwrite($fh, $body) !== strlen($body)) { throw new RuntimeException('WRITE'); }
        fflush($fh);
        fclose($fh);
        $fh = null;
        @chmod($tmp, 0644);
        if (!@rename($tmp, $target)) { throw new RuntimeException('RENAME'); }
    } catch (Throwable $e) {
        if ($fh) { fclose($fh); }
        @unlink($tmp);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    return ['ok' => true, 'id' => $id, 'requestedAt' => $at];
}

/**
 * Egy hely, ahol a frissites dontese es kiirasa egymas utan, zarolva tortenik.
 * A sugo egyetlen PHP-FPM poolban fut, de tobb folyamattal: ezert fajlzar kell
 * (nem eleg a folyamaton beluli sorba allitas, mint a V40 Node-szerveren).
 */
function frc_with_lock(array $cfg, callable $fn): mixed
{
    $lock = @fopen(sys_get_temp_dir() . '/sugo-frissito-keres.lock', 'c');
    if ($lock === false) { return $fn(); }
    try {
        flock($lock, LOCK_EX);
        return $fn();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Minden, ami a Frissites laphoz kell, egy helyen. */
function frc_view(array $cfg, int $now): array
{
    $channel = frc_channel_state($cfg);
    $status  = $channel === 'MISSING' ? null : frc_read_status($cfg);
    $history = $channel === 'MISSING' ? ['items' => [], 'skipped' => 0] : frc_read_history($cfg);
    $request = $channel === 'MISSING' ? ['state' => 'NONE', 'request' => null] : frc_read_request($cfg);

    $s = ($status['ok'] ?? false) ? $status['status'] : null;
    $run = $s['run'] ?? null;

    if ($channel === 'MISSING' || $status === null) {
        $updater = 'NOT_INSTALLED';
    } elseif (!($status['ok'] ?? false)) {
        $updater = 'UNREADABLE';
    } elseif (!fr_updater_alive($s['heartbeatAt'], $now)) {
        $updater = 'STOPPED';
    } else {
        $updater = 'OK';
    }

    $pending = fr_request_pending($request, $run, $history['items']);
    $decision = fr_decide_update_request([
        'channel' => $channel, 'status' => $status, 'pendingRequest' => $pending, 'now' => $now,
    ]);

    return [
        'now'      => $now,
        'running'  => fr_running_version(['APP_COMMIT' => getenv('APP_COMMIT') ?: null,
                                          'APP_BUILT_AT' => getenv('APP_BUILT_AT') ?: null]),
        'channel'  => $channel,
        'updater'  => ['state' => $updater, 'heartbeatAt' => $s['heartbeatAt'] ?? null,
                       'problem' => ($status !== null && !($status['ok'] ?? false)) ? $status['message'] : null],
        'current'  => $s['current'] ?? null,
        'available'=> $s['available'] ?? null,
        'run'      => $run,
        'pending'  => $pending ? ($request['request'] ?? ['id' => null, 'requestedAt' => null, 'requestedBy' => 0]) : null,
        'history'  => $history['items'],
        'historySkipped' => $history['skipped'],
        'canRequest' => $decision,
        'requestReadState' => $request['state'],
    ];
}
