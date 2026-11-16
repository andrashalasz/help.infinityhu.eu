<?php
/**
 * Az eles frissito szabalyai (site_dinamikus/lib/frissito_szabalyok.php) es a
 * szerzodes a gazdagepen futo deploy/sugo-frissito.py-jal.
 */
declare(strict_types=1);

require_once GYOKER . '/site_dinamikus/lib/frissito_szabalyok.php';

const C1 = '1111111111111111111111111111111111111111';
const C2 = '2222222222222222222222222222222222222222';
const U1 = '0b6c1a52-3f0e-4c9a-9a41-6a1d2e3f4b5c';

function fr_minta_status(array $fe = []): array
{
    $now = gmdate('Y-m-d\TH:i:s\Z');
    return array_replace([
        'schema' => 1, 'heartbeatAt' => $now,
        'current' => ['commit' => C1, 'deployedAt' => $now],
        'available' => ['commit' => C2, 'committedAt' => $now, 'checkedAt' => $now, 'count' => 3,
                        'hasMigrations' => false,
                        'commits' => [['sha' => C2, 'date' => $now, 'subject' => "Valtozas\x07 egy"]]],
        'run' => null,
    ], $fe);
}

t('ellenőrzők: commit, uuid, ISO-dátum', function () {
    ok(fr_is_commit(C1));
    no(fr_is_commit(str_repeat('A', 40)));
    no(fr_is_commit('abc'));
    ok(fr_is_uuid(U1));
    ok(fr_is_uuid_v4(U1));
    no(fr_is_uuid_v4('0b6c1a52-3f0e-1c9a-9a41-6a1d2e3f4b5c'));
    ok(fr_is_iso_date('2026-11-16T10:00:00Z'));
    ok(fr_is_iso_date('2026-11-16T10:00:00+01:00'));
    no(fr_is_iso_date('2026-11-16 10:00:00'));
    no(fr_is_iso_date(null));
});

t('frissítést csak admin kérhet', function () {
    ok(fr_may_request_update('admin'));
    no(fr_may_request_update('editor'));
    no(fr_may_request_update('translator'));
    no(fr_may_request_update(null));
});

t('futó verzió a környezeti változóból', function () {
    $v = fr_running_version(['APP_COMMIT' => C1, 'APP_BUILT_AT' => '2026-11-16T10:00:00Z']);
    eq(C1, $v['commit']);
    no($v['dev']);
    eq('1111111', $v['short']);
    ok(fr_running_version(['APP_COMMIT' => 'hamis'])['dev']);
});

t('minden lépésnek van felirata', function () {
    foreach (array_merge(FR_STEP_KEYS, FR_ROLLBACK_STEP_KEYS) as $k) {
        ok(isset(FR_STEP_LABELS[$k]), "hiányzó felirat: $k");
    }
});

t('állapotfájl: érvényes, null mezők, szöveg tisztítása', function () {
    $r = fr_parse_status(json_encode(fr_minta_status()));
    ok($r['ok']);
    eq('Valtozas egy', $r['status']['available']['commits'][0]['subject']);
    $r = fr_parse_status(json_encode(fr_minta_status(['available' => null, 'current' => ['commit' => null, 'deployedAt' => null]])));
    ok($r['ok']);
});

t('állapotfájl: szemét sosem dob kivételt, és megnevezi a hibát', function () {
    eq('NOT_JSON', fr_parse_status('nem json')['problem']);
    eq('NOT_JSON', fr_parse_status('[1,2]')['problem']);
    eq('UNKNOWN_SCHEMA', fr_parse_status(json_encode(fr_minta_status(['schema' => 2])))['problem']);
    eq('TOO_LARGE', fr_parse_status(str_repeat(' ', FR_STATUS_MAX_BYTES + 1))['problem']);
    $r = fr_parse_status(json_encode(fr_minta_status(['current' => ['commit' => 'x', 'deployedAt' => null]])));
    eq('BAD_SHAPE', $r['problem']);
    eq('current.commit', $r['detail']);
    $run = ['id' => U1, 'commit' => C2, 'previousCommit' => C1, 'requestedBy' => 1, 'state' => 'VALAMI',
            'step' => null, 'steps' => [], 'startedAt' => '2026-11-16T10:00:00Z', 'finishedAt' => null,
            'error' => null, 'backupFile' => null];
    eq('run.state', fr_parse_status(json_encode(fr_minta_status(['run' => $run])))['detail']);
});

t('életjel: 5 percnél régebbi = nem fut, a jövőbeli = fut', function () {
    $now = time();
    ok(fr_updater_alive(gmdate('Y-m-d\TH:i:s\Z', $now - 60), $now));
    no(fr_updater_alive(gmdate('Y-m-d\TH:i:s\Z', $now - 301), $now));
    ok(fr_updater_alive(gmdate('Y-m-d\TH:i:s\Z', $now + 600), $now));
    no(fr_updater_alive(null, $now));
});

t('előzmények: legújabb elöl, hibás sorok kihagyva', function () {
    $l = static fn($id, $c) => json_encode(['id' => $id, 'commit' => $c, 'previousCommit' => null, 'state' => 'SUCCEEDED',
        'startedAt' => '2026-11-16T10:00:00Z', 'finishedAt' => null, 'error' => null, 'requestedBy' => 1]);
    $r = fr_parse_history($l(U1, C1) . "\nszemet\n" . $l('1b6c1a52-3f0e-4c9a-9a41-6a1d2e3f4b5c', C2) . "\n");
    eq(2, count($r['items']));
    eq(C2, $r['items'][0]['commit']);
    eq(1, $r['skipped']);
});

t('kérésfájl: pontosan négy mező, hibás adatból nincs fájl', function () {
    $f = fr_build_request_file(U1, C2, '2026-11-16T10:00:00+01:00', 5);
    eq(['id', 'commit', 'requestedAt', 'requestedBy'], array_keys(json_decode($f, true)));
    ok(str_ends_with($f, "\n"));
    eq(null, fr_build_request_file('nem-uuid', C2, '2026-11-16T10:00:00Z', 5));
    eq(null, fr_build_request_file(U1, 'abc', '2026-11-16T10:00:00Z', 5));
    eq(null, fr_build_request_file(U1, C2, '2026-11-16T10:00:00Z', 0));
    eq(C2, fr_parse_request_file($f)['commit']);
});

t('függő kérés: olvashatatlan = függ, lefutott = felülírható', function () {
    no(fr_request_pending(['state' => 'NONE', 'request' => null], null, []));
    ok(fr_request_pending(['state' => 'UNREADABLE', 'request' => null], null, []));
    $found = ['state' => 'FOUND', 'request' => ['id' => U1]];
    ok(fr_request_pending($found, null, []));
    no(fr_request_pending($found, ['id' => U1], []));
    no(fr_request_pending($found, null, [['id' => U1]]));
});

t('POST: csak jelszó és verzió, a hibás verzió 422', function () {
    eq(400, fr_parse_update_body([])['code']);
    eq(422, fr_parse_update_body(['password' => 'x', 'commit' => 'abc'])['code']);
    $r = fr_parse_update_body(['password' => 'x', 'commit' => C2, 'egyeb' => 'semmi']);
    ok($r['ok']);
    eq(C2, $r['commit']);
});

t('döntés: minden elutasítás a maga sorrendjében', function () {
    $st = static fn(array $fe = []) => fr_parse_status(json_encode(fr_minta_status($fe)));
    $base = ['channel' => 'OK', 'status' => $st(), 'pendingRequest' => false, 'now' => time()];
    eq('CHANNEL_MISSING', fr_decide_update_request(['channel' => 'MISSING'] + $base)['reason']);
    eq('UPDATER_NOT_INSTALLED', fr_decide_update_request(['status' => null] + $base)['reason']);
    eq('STATUS_UNREADABLE', fr_decide_update_request(['status' => fr_parse_status('x')] + $base)['reason']);
    eq('NO_HEARTBEAT', fr_decide_update_request(['status' => $st(['heartbeatAt' => '2020-01-01T00:00:00Z'])] + $base)['reason']);
    eq('CHANNEL_UNWRITABLE', fr_decide_update_request(['channel' => 'UNWRITABLE'] + $base)['reason']);
    $fut = ['id' => U1, 'commit' => C2, 'previousCommit' => C1, 'requestedBy' => 1, 'state' => 'RUNNING',
            'step' => 'tests', 'steps' => [], 'startedAt' => '2026-11-16T10:00:00Z', 'finishedAt' => null,
            'error' => null, 'backupFile' => null];
    eq('RUN_IN_PROGRESS', fr_decide_update_request(['status' => $st(['run' => $fut])] + $base)['reason']);
    eq('REQUEST_PENDING', fr_decide_update_request(['pendingRequest' => true] + $base)['reason']);
    eq('NOTHING_NEW', fr_decide_update_request(['status' => $st(['available' => null])] + $base)['reason']);
    eq('COMMIT_CHANGED', fr_decide_update_request(['confirmedCommit' => C1] + $base)['reason']);
    $ok = fr_decide_update_request(['confirmedCommit' => C2] + $base);
    ok($ok['ok']);
    eq(C2, $ok['commit'], 'a telepítendő verzió az állapotfájlból jön');
});

t('minden elutasításnak saját szövege van', function () {
    eq(count(FR_REFUSAL_TEXT), count(array_unique(FR_REFUSAL_TEXT)));
    foreach (FR_REFUSAL_TEXT as $k => $s) { ok(mb_strlen($s) > 20, $k); }
});

t('napló vége: ANSI-kódok nélkül, legfeljebb N sor', function () {
    $r = fr_tail_lines("a\n\x1B[31mpiros\x1B[0m\nc\n", 2);
    eq(['piros', 'c'], $r['lines']);
    ok($r['truncated']);
});

// --- szerzodes a gazdagepen futo frissitovel -------------------------------------

t('szerződés: a frissítő lépései egyeznek a felületéivel', function () {
    $py = file_get_contents(GYOKER . '/deploy/sugo-frissito.py');
    ok(preg_match('/^STEPS = \[(.*)\]$/m', $py, $m) === 1, 'nincs STEPS sor');
    preg_match_all('/"([a-z_]+)"/', $m[1], $k);
    eq(FR_STEP_KEYS, $k[1]);
    foreach (FR_ROLLBACK_STEP_KEYS as $rk) { ok(str_contains($py, '"' . $rk . '"'), $rk); }
});

t('szerződés: a frissítő csak a három mezőt olvassa, és nem indít héjat', function () {
    $py = file_get_contents(GYOKER . '/deploy/sugo-frissito.py');
    ok(str_contains($py, 'req.get("id"), req.get("commit"), req.get("requestedBy")'));
    no((bool)preg_match('/shell\s*=\s*True/', $py), 'shell=True a frissítőben');
    ok(str_contains($py, 'SCHEMA = ' . FR_SCHEMA));
});

t('a működésellenőrzés végpontja megvan', function () {
    ok(is_file(GYOKER . '/site_dinamikus/health.php'));
});

t('a fiókzárolás MariaDB-szintaxisú (nem PostgreSQL)', function () {
    $a = file_get_contents(GYOKER . '/site_dinamikus/lib/auth.php');
    no(str_contains($a, "interval '10 minutes'"));
    ok(str_contains($a, 'INTERVAL 10 MINUTE'));
});
