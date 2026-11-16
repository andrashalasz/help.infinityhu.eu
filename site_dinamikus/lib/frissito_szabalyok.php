<?php
/**
 * frissito_szabalyok.php - az eles frissito szabalyai, FAJLMUVELET NELKUL.
 *
 * A V40 Vital server/deploy/rules.ts mintajara. Tiszta fuggvenyek: semmit nem
 * olvasnak es nem irnak, ezert a tesztek (tests/futtat.php) kozvetlenul
 * betolthetik, adatbazis es fajlrendszer nelkul.
 *
 * A csatorna (frissites/) tartalmat a gazdagepen futo deploy/sugo-frissito.py
 * irja. A PHP-oldal azt NEM tekinti megbizhatonak: minden mezot itt
 * ellenorzunk, a hibas fajl allapot, nem kivetel.
 */
declare(strict_types=1);

const FR_SCHEMA            = 1;
const FR_STATUS_MAX_BYTES  = 262144;   // 256 KB
const FR_HISTORY_MAX_BYTES = 262144;
const FR_REQUEST_MAX_BYTES = 4096;
const FR_LOG_TAIL_BYTES    = 32768;
const FR_LOG_TAIL_LINES    = 200;
const FR_HEARTBEAT_MAX_AGE = 300;      // mp: ennel regebbi eletjel = a frissito nem fut
const FR_HISTORY_SHOWN     = 20;
const FR_MAX_COMMITS       = 50;
const FR_MAX_STEPS         = 30;
const FR_SUBJECT_MAX       = 200;
const FR_ERROR_MAX         = 2000;
const FR_LOG_LINE_MAX      = 2000;

/** A lepesek sorrendje - a deploy/sugo-frissito.py STEPS listajaval egyezik (szerzodes-teszt). */
const FR_STEP_KEYS          = ['validate', 'fetch', 'tests', 'build', 'backup', 'migrate', 'switch', 'health', 'cleanup'];
const FR_ROLLBACK_STEP_KEYS = ['rollback_db', 'rollback_image'];

const FR_STEP_LABELS = [
    'validate'       => 'A kérés ellenőrzése',
    'fetch'          => 'Az új verzió letöltése',
    'tests'          => 'Tesztek futtatása',
    'build'          => 'Új verzió építése',
    'backup'         => 'Adatbázis-mentés és próba-visszatöltés',
    'migrate'        => 'Adatbázis frissítése',
    'switch'         => 'Átváltás az új verzióra',
    'health'         => 'Működés ellenőrzése',
    'cleanup'        => 'Takarítás',
    'rollback_db'    => 'Az adatbázis visszaállítása a mentésből',
    'rollback_image' => 'Visszaállás az előző verzióra',
];

const FR_STEP_STATES = ['PENDING', 'RUNNING', 'DONE', 'FAILED', 'SKIPPED'];
const FR_RUN_STATES  = ['RUNNING', 'SUCCEEDED', 'FAILED', 'ROLLED_BACK'];

/** Elutasitasok - minden okhoz sajat, kozerthato szoveg (a teszt ellenorzi). */
const FR_REFUSAL_TEXT = [
    'CHANNEL_MISSING'       => 'A frissítő megosztott mappája nincs becsatolva a webes konténerbe. A szervercsapat teendője (docs/FRISSITO.md).',
    'UPDATER_NOT_INSTALLED' => 'A frissítő szolgáltatás még nincs telepítve a szerveren. A szervercsapat teendője (docs/FRISSITO.md).',
    'STATUS_UNREADABLE'     => 'A frissítő állapotfájlja nem olvasható. A szervercsapat teendője.',
    'NO_HEARTBEAT'          => 'A frissítő szolgáltatás nem fut (öt percnél régebben adott életjelet). A szervercsapat teendője.',
    'CHANNEL_UNWRITABLE'    => 'A frissítő megosztott mappája nem írható a webes konténerből. A szervercsapat teendője.',
    'RUN_IN_PROGRESS'       => 'Már folyamatban van egy telepítés. Várd meg, amíg befejeződik.',
    'REQUEST_PENDING'       => 'Már van egy elküldött telepítési kérés, amit a frissítő még nem vett fel. Várj egy percet.',
    'NOTHING_NEW'           => 'Nincs telepíthető újabb verzió – a szerveren a legújabb fut.',
    'COMMIT_CHANGED'        => 'Időközben újabb változat érkezett: a megerősített verzió már nem a legújabb. Töltsd újra az oldalt, nézd át a változásokat, és indítsd újra.',
];

const FR_STATUS_PROBLEM_TEXT = [
    'TOO_LARGE'      => 'Az állapotfájl túl nagy.',
    'NOT_JSON'       => 'Az állapotfájl nem érvényes JSON.',
    'UNKNOWN_SCHEMA' => 'Az állapotfájl ismeretlen változatú – a frissítő és a webes felület verziója eltér.',
    'BAD_SHAPE'      => 'Az állapotfájl szerkezete hibás.',
];

// ----------------------------------------------------------------------------
// Ellenorzok
// ----------------------------------------------------------------------------

function fr_is_commit(mixed $v): bool
{
    return is_string($v) && preg_match('/^[0-9a-f]{40}$/', $v) === 1;
}

function fr_is_uuid(mixed $v): bool
{
    return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v) === 1;
}

function fr_is_uuid_v4(mixed $v): bool
{
    return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $v) === 1;
}

/** ISO-8601 idopont idozonaval (Z vagy +hh:mm), legfeljebb 40 karakter. */
function fr_is_iso_date(mixed $v): bool
{
    if (!is_string($v) || strlen($v) > 40) { return false; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})$/', $v) !== 1) {
        return false;
    }
    return strtotime($v) !== false;
}

function fr_is_step_key(mixed $v): bool
{
    return is_string($v) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $v) === 1;
}

function fr_short(?string $commit): string
{
    return is_string($commit) ? substr($commit, 0, 7) : '';
}

/**
 * Megjelenitheto szoveg: vezerlokarakterek nelkul, levagva. Tobbsoros
 * szovegben a sortores megmarad, egysorosban szokozze valik.
 */
function fr_clean_text(mixed $v, int $max, bool $multiline = false): string
{
    if (!is_string($v)) { return ''; }
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = str_replace(["\u{2028}", "\u{2029}"], "\n", $v);
    if (!$multiline) { $v = str_replace(["\n", "\t"], ' ', $v); }
    // C0 (a sortores kivetelevel) es C1 vezerlok
    $v = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]|\xC2[\x80-\x9F]/', '', $v) ?? '';
    if (!$multiline) { $v = trim(preg_replace('/ {2,}/', ' ', $v) ?? ''); }
    if (mb_strlen($v) > $max) { $v = mb_substr($v, 0, $max - 1) . '…'; }
    return $v;
}

function fr_step_label(string $key): string
{
    return FR_STEP_LABELS[$key] ?? $key;
}

/** Frissitest csak az admin szerepkor kerhet (a sugoban az a legmagasabb). */
function fr_may_request_update(?string $role): bool
{
    return $role === 'admin';
}

/** A futo verzio a PHP-kepbe egetett APP_COMMIT / APP_BUILT_AT kornyezeti valtozobol. */
function fr_running_version(array $env): array
{
    $commit = fr_is_commit($env['APP_COMMIT'] ?? null) ? $env['APP_COMMIT'] : null;
    $built  = fr_is_iso_date($env['APP_BUILT_AT'] ?? null) ? $env['APP_BUILT_AT'] : null;
    return ['commit' => $commit, 'builtAt' => $built, 'dev' => $commit === null, 'short' => fr_short($commit)];
}

// ----------------------------------------------------------------------------
// Allapotfajl (status.json)
// ----------------------------------------------------------------------------

/** @return array{ok:bool,status?:array,problem?:string,message?:string,detail?:string} */
function fr_parse_status(string $text): array
{
    $bad = static fn(string $p, string $d = '') =>
        ['ok' => false, 'problem' => $p, 'message' => FR_STATUS_PROBLEM_TEXT[$p], 'detail' => $d];

    if (strlen($text) > FR_STATUS_MAX_BYTES) { return $bad('TOO_LARGE'); }
    $d = json_decode($text, true);
    if (!is_array($d) || array_is_list($d)) { return $bad('NOT_JSON'); }
    if (($d['schema'] ?? null) !== FR_SCHEMA) { return $bad('UNKNOWN_SCHEMA'); }

    $nullableIso = static fn($v) => $v === null || fr_is_iso_date($v);
    $nullableCommit = static fn($v) => $v === null || fr_is_commit($v);

    if (!fr_is_iso_date($d['heartbeatAt'] ?? null)) { return $bad('BAD_SHAPE', 'heartbeatAt'); }

    $cur = $d['current'] ?? null;
    if (!is_array($cur)) { return $bad('BAD_SHAPE', 'current'); }
    if (!$nullableCommit($cur['commit'] ?? null)) { return $bad('BAD_SHAPE', 'current.commit'); }
    if (!$nullableIso($cur['deployedAt'] ?? null)) { return $bad('BAD_SHAPE', 'current.deployedAt'); }
    $current = ['commit' => $cur['commit'] ?? null, 'deployedAt' => $cur['deployedAt'] ?? null];

    $available = null;
    if (($d['available'] ?? null) !== null) {
        $a = $d['available'];
        if (!is_array($a)) { return $bad('BAD_SHAPE', 'available'); }
        if (!fr_is_commit($a['commit'] ?? null)) { return $bad('BAD_SHAPE', 'available.commit'); }
        foreach (['committedAt', 'checkedAt'] as $k) {
            if (!fr_is_iso_date($a[$k] ?? null)) { return $bad('BAD_SHAPE', 'available.' . $k); }
        }
        if (!is_int($a['count'] ?? null) || $a['count'] < 0) { return $bad('BAD_SHAPE', 'available.count'); }
        if (!is_bool($a['hasMigrations'] ?? null)) { return $bad('BAD_SHAPE', 'available.hasMigrations'); }
        if (!is_array($a['commits'] ?? null) || !array_is_list($a['commits'])) { return $bad('BAD_SHAPE', 'available.commits'); }
        $commits = [];
        foreach (array_slice($a['commits'], 0, FR_MAX_COMMITS) as $i => $c) {
            if (!is_array($c) || !fr_is_commit($c['sha'] ?? null) || !fr_is_iso_date($c['date'] ?? null)
                || !is_string($c['subject'] ?? null)) {
                return $bad('BAD_SHAPE', "available.commits[$i]");
            }
            $commits[] = ['sha' => $c['sha'], 'date' => $c['date'], 'subject' => fr_clean_text($c['subject'], FR_SUBJECT_MAX)];
        }
        $available = [
            'commit' => $a['commit'], 'committedAt' => $a['committedAt'], 'checkedAt' => $a['checkedAt'],
            'count' => $a['count'], 'hasMigrations' => $a['hasMigrations'], 'commits' => $commits,
        ];
    }

    $run = null;
    if (($d['run'] ?? null) !== null) {
        $r = $d['run'];
        if (!is_array($r)) { return $bad('BAD_SHAPE', 'run'); }
        if (!fr_is_uuid($r['id'] ?? null)) { return $bad('BAD_SHAPE', 'run.id'); }
        if (!fr_is_commit($r['commit'] ?? null)) { return $bad('BAD_SHAPE', 'run.commit'); }
        if (!$nullableCommit($r['previousCommit'] ?? null)) { return $bad('BAD_SHAPE', 'run.previousCommit'); }
        if (!is_int($r['requestedBy'] ?? null) || $r['requestedBy'] < 0) { return $bad('BAD_SHAPE', 'run.requestedBy'); }
        if (!in_array($r['state'] ?? null, FR_RUN_STATES, true)) { return $bad('BAD_SHAPE', 'run.state'); }
        if (($r['step'] ?? null) !== null && !fr_is_step_key($r['step'])) { return $bad('BAD_SHAPE', 'run.step'); }
        if (!fr_is_iso_date($r['startedAt'] ?? null)) { return $bad('BAD_SHAPE', 'run.startedAt'); }
        if (!$nullableIso($r['finishedAt'] ?? null)) { return $bad('BAD_SHAPE', 'run.finishedAt'); }
        if (!is_array($r['steps'] ?? null) || !array_is_list($r['steps']) || count($r['steps']) > FR_MAX_STEPS) {
            return $bad('BAD_SHAPE', 'run.steps');
        }
        $steps = [];
        foreach ($r['steps'] as $i => $s) {
            if (!is_array($s) || !fr_is_step_key($s['key'] ?? null) || !in_array($s['state'] ?? null, FR_STEP_STATES, true)
                || !$nullableIso($s['startedAt'] ?? null) || !$nullableIso($s['finishedAt'] ?? null)) {
                return $bad('BAD_SHAPE', "run.steps[$i]");
            }
            $steps[] = ['key' => $s['key'], 'state' => $s['state'],
                        'startedAt' => $s['startedAt'] ?? null, 'finishedAt' => $s['finishedAt'] ?? null];
        }
        $err = $r['error'] ?? null;
        if ($err !== null && !is_string($err)) { return $bad('BAD_SHAPE', 'run.error'); }
        $bf = $r['backupFile'] ?? null;
        if ($bf !== null && !is_string($bf)) { return $bad('BAD_SHAPE', 'run.backupFile'); }
        $run = [
            'id' => $r['id'], 'commit' => $r['commit'], 'previousCommit' => $r['previousCommit'] ?? null,
            'requestedBy' => $r['requestedBy'], 'state' => $r['state'], 'step' => $r['step'] ?? null,
            'steps' => $steps, 'startedAt' => $r['startedAt'], 'finishedAt' => $r['finishedAt'] ?? null,
            'error' => $err === null ? null : fr_clean_text($err, FR_ERROR_MAX, true),
            'backupFile' => $bf === null ? null : fr_clean_text(basename($bf), 255),
        ];
    }

    return ['ok' => true, 'status' => [
        'schema' => FR_SCHEMA, 'heartbeatAt' => $d['heartbeatAt'],
        'current' => $current, 'available' => $available, 'run' => $run,
    ]];
}

/** Eletjel: legfeljebb 5 percnel regebbi. A jovobeli idopont orajel-elteres, nem leallas. */
function fr_updater_alive(?string $heartbeatAt, int $now): bool
{
    if (!fr_is_iso_date($heartbeatAt)) { return false; }
    $t = strtotime((string)$heartbeatAt);
    return $t !== false && ($now - $t) <= FR_HEARTBEAT_MAX_AGE;
}

// ----------------------------------------------------------------------------
// Elozmenyek (history.jsonl)
// ----------------------------------------------------------------------------

function fr_parse_history_line(string $line): ?array
{
    if ($line === '' || strlen($line) > 8192) { return null; }
    $h = json_decode($line, true);
    if (!is_array($h) || !fr_is_uuid($h['id'] ?? null) || !fr_is_commit($h['commit'] ?? null)
        || !in_array($h['state'] ?? null, ['SUCCEEDED', 'FAILED', 'ROLLED_BACK'], true)
        || !fr_is_iso_date($h['startedAt'] ?? null)) {
        return null;
    }
    $prev = $h['previousCommit'] ?? null;
    $fin  = $h['finishedAt'] ?? null;
    $by   = $h['requestedBy'] ?? null;
    return [
        'id' => $h['id'], 'commit' => $h['commit'],
        'previousCommit' => fr_is_commit($prev) ? $prev : null,
        'state' => $h['state'], 'startedAt' => $h['startedAt'],
        'finishedAt' => fr_is_iso_date($fin) ? $fin : null,
        'error' => is_string($h['error'] ?? null) ? fr_clean_text($h['error'], FR_ERROR_MAX, true) : null,
        'requestedBy' => is_int($by) && $by >= 0 ? $by : 0,
    ];
}

/** Legujabb elol, legfeljebb $limit; a hibas sorokat atugorja es megszamolja. */
function fr_parse_history(string $text, int $limit = FR_HISTORY_SHOWN): array
{
    $items = [];
    $skipped = 0;
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        $h = fr_parse_history_line($line);
        if ($h === null) { $skipped++; continue; }
        $items[] = $h;
    }
    return ['items' => array_slice(array_reverse($items), 0, $limit), 'skipped' => $skipped];
}

// ----------------------------------------------------------------------------
// Keresfajl (request.json)
// ----------------------------------------------------------------------------

/** A keresfajl tartalma - pontosan negy mezo, ebben a sorrendben. Hibas adatbol null. */
function fr_build_request_file(string $id, string $commit, string $requestedAt, int $requestedBy): ?string
{
    if (!fr_is_uuid_v4($id) || !fr_is_commit($commit) || !fr_is_iso_date($requestedAt) || $requestedBy <= 0) {
        return null;
    }
    return json_encode(['id' => $id, 'commit' => $commit, 'requestedAt' => $requestedAt, 'requestedBy' => $requestedBy],
                       JSON_UNESCAPED_SLASHES) . "\n";
}

function fr_parse_request_file(string $text): ?array
{
    if (strlen($text) > FR_REQUEST_MAX_BYTES) { return null; }
    $r = json_decode($text, true);
    if (!is_array($r) || !fr_is_uuid($r['id'] ?? null) || !fr_is_commit($r['commit'] ?? null)
        || !is_int($r['requestedBy'] ?? null) || $r['requestedBy'] <= 0) {
        return null;
    }
    $at = $r['requestedAt'] ?? null;
    return ['id' => $r['id'], 'commit' => $r['commit'],
            'requestedAt' => fr_is_iso_date($at) ? $at : null, 'requestedBy' => $r['requestedBy']];
}

/**
 * Van-e fuggo (meg fel nem vett) keres?
 *   $read: ['state' => 'NONE'|'UNREADABLE'|'FOUND', 'request' => ?array]
 * Az olvashatatlan fajl FUGGONEK szamit (soha nem irjuk felul vakon).
 * Ha a keres mar lefutott (az azonositoja a futo vagy a korabbi futasoke),
 * akkor felhasznaltnak szamit, es felulirhato.
 */
function fr_request_pending(array $read, ?array $run, array $history): bool
{
    if (($read['state'] ?? 'NONE') === 'NONE') { return false; }
    if ($read['state'] === 'UNREADABLE') { return true; }
    $id = $read['request']['id'] ?? null;
    if ($run !== null && ($run['id'] ?? null) === $id) { return false; }
    foreach ($history as $h) {
        if (($h['id'] ?? null) === $id) { return false; }
    }
    return true;
}

// ----------------------------------------------------------------------------
// A POST adatai es a dontes
// ----------------------------------------------------------------------------

/** @return array{ok:bool,commit?:string,password?:string,code?:int,message?:string} */
function fr_parse_update_body(array $post): array
{
    $pw = $post['password'] ?? null;
    $commit = $post['commit'] ?? null;
    if (!is_string($pw) || $pw === '' || strlen($pw) > 200 || !is_string($commit)) {
        return ['ok' => false, 'code' => 400, 'message' => 'Hiányzó vagy hibás adatok.'];
    }
    if (!fr_is_commit($commit)) {
        return ['ok' => false, 'code' => 422,
                'message' => 'A megerősített verzió azonosítója hiányzik vagy hibás. Töltsd újra az oldalt.'];
    }
    return ['ok' => true, 'commit' => $commit, 'password' => $pw];
}

/**
 * Szabad-e most kerest kuldeni? A telepitendo verzio MINDIG az allapotfajlbol
 * jon (amit a frissito maga latott legujabbnak), nem a kerest kuldotol.
 *
 * $in: channel ('OK'|'MISSING'|'UNWRITABLE'), status (fr_parse_status eredmenye
 *      vagy null, ha nincs fajl), pendingRequest (bool), confirmedCommit (?string), now (int)
 */
function fr_decide_update_request(array $in): array
{
    $no = static fn(string $r) => ['ok' => false, 'reason' => $r, 'message' => FR_REFUSAL_TEXT[$r]];

    if (($in['channel'] ?? 'MISSING') === 'MISSING') { return $no('CHANNEL_MISSING'); }
    $st = $in['status'] ?? null;
    if ($st === null) { return $no('UPDATER_NOT_INSTALLED'); }
    if (!($st['ok'] ?? false)) { return $no('STATUS_UNREADABLE'); }
    $s = $st['status'];
    if (!fr_updater_alive($s['heartbeatAt'], (int)$in['now'])) { return $no('NO_HEARTBEAT'); }
    if ($in['channel'] === 'UNWRITABLE') { return $no('CHANNEL_UNWRITABLE'); }
    if (($s['run']['state'] ?? null) === 'RUNNING') { return $no('RUN_IN_PROGRESS'); }
    if (!empty($in['pendingRequest'])) { return $no('REQUEST_PENDING'); }
    $av = $s['available'];
    if ($av === null || $av['commit'] === ($s['current']['commit'] ?? null)) { return $no('NOTHING_NEW'); }
    if (array_key_exists('confirmedCommit', $in) && $in['confirmedCommit'] !== null
        && $in['confirmedCommit'] !== $av['commit']) {
        return $no('COMMIT_CHANGED');
    }
    return ['ok' => true, 'commit' => $av['commit'], 'fromCommit' => $s['current']['commit'] ?? null,
            'count' => $av['count'], 'hasMigrations' => $av['hasMigrations']];
}

// ----------------------------------------------------------------------------
// Naplo
// ----------------------------------------------------------------------------

/** A naplo vege: legfeljebb $max sor, ANSI-kodok es vezerlok nelkul, a hosszu sorok levagva. */
function fr_tail_lines(string $text, int $max = FR_LOG_TAIL_LINES): array
{
    $text = preg_replace('/\x1B\[[0-9;?]*[ -\/]*[@-~]/', '', $text) ?? '';
    $lines = explode("\n", str_replace("\r\n", "\n", $text));
    if (end($lines) === '') { array_pop($lines); }
    $truncated = count($lines) > $max;
    $out = [];
    foreach (array_slice($lines, -$max) as $l) {
        $l = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $l) ?? '';
        $out[] = mb_strlen($l) > FR_LOG_LINE_MAX ? mb_substr($l, 0, FR_LOG_LINE_MAX - 1) . '…' : $l;
    }
    return ['lines' => $out, 'truncated' => $truncated];
}
