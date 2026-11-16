<?php
/**
 * admin_frissites.php - az eles frissites lapja (V40 Vital "Frissitesek" mintajara).
 *
 * MIT NEM CSINAL: ez a lap SEMMIT NEM FUTTAT a szerveren. A gombnyomas egyetlen
 * keresfajlt ir a megosztott mappaba (frissito_csatorna.php); a munkat a
 * gazdagepen futo deploy/sugo-frissito.py vegzi, es az allapotat, az
 * elozmenyeket es a naplot ugyanebbe a mappaba irja vissza. Ez a lap azt olvassa.
 *
 * Frissitest csak admin kerhet, a jelszava ujboli megadasaval, es csak azt a
 * verziot, amit a frissito maga latott a fo ag legujabbjanak.
 */
declare(strict_types=1);

function frl_time(?string $iso): string
{
    if ($iso === null || $iso === '') { return '–'; }
    try {
        $d = new DateTimeImmutable($iso);
        return $d->setTimezone(new DateTimeZone('Europe/Budapest'))->format('Y-m-d H:i');
    } catch (Throwable $e) {
        return '–';
    }
}

/** Felhasznaloi azonositok -> megjelenitett nev (#id, ha nincs meg). */
function frl_names(PDO $db, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
    if (!$ids) { return []; }
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT id, username, display_name FROM help_user WHERE id IN ($in)");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['id']] = $r['display_name'] !== '' ? $r['display_name'] : $r['username'];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

function frl_who(array $names, ?int $id): string
{
    if (!$id) { return 'a szervercsapat'; }
    return $names[$id] ?? ('#' . $id);
}

function frl_badge(string $state): string
{
    $map = [
        'RUNNING'     => ['warn', 'Folyamatban'],
        'SUCCEEDED'   => ['ok', 'Sikeres'],
        'FAILED'      => ['err', 'Sikertelen'],
        'ROLLED_BACK' => ['err', 'Visszaállítva'],
    ];
    [$tone, $txt] = $map[$state] ?? ['', $state];
    return '<span class="badge badge--' . h($tone) . '">' . h($txt) . '</span>';
}

/** A naplo vegenek megjelenitese (sima szoveg, soha nem HTML). */
function frl_log_box(array $cfg, string $id): string
{
    $log = frc_read_log_tail($cfg, $id);
    if ($log['state'] !== 'OK' || !$log['lines']) {
        return '<p class="lead">Ehhez a telepítéshez nincs elérhető napló.</p>';
    }
    $html = '<pre class="fr-naplo">' . h(implode("\n", $log['lines'])) . '</pre>';
    if ($log['truncated']) {
        $html .= '<p class="lead">A napló vége látszik (legfeljebb ' . FR_LOG_TAIL_LINES . ' sor).</p>';
    }
    return $html;
}

/** A lap valtozo resze - ezt kerdezi le a bongeszo ujra es ujra (?p=frissites&resz=1). */
function frl_render(PDO $db, array $cfg, array $v): string
{
    $run = $v['run'];
    $ids = [($run['requestedBy'] ?? 0), ($v['pending']['requestedBy'] ?? 0)];
    foreach ($v['history'] as $hh) { $ids[] = $hh['requestedBy']; }
    $names = frl_names($db, $ids);
    $busy = (($run['state'] ?? '') === 'RUNNING') || $v['pending'] !== null;
    $openLog = (string)($_GET['nyitott'] ?? '');

    ob_start();
    ?>
<div id="fr-allapot" data-busy="<?= $busy ? '1' : '0' ?>" data-running="<?= h((string)($v['running']['commit'] ?? '')) ?>">

  <div class="panel" style="margin-bottom:16px">
    <div class="panel__h"><h2>A most futó verzió</h2><span class="sp"></span>
      <?php
        $u = $v['updater']['state'];
        $ub = ['OK' => ['ok', 'Működik'], 'STOPPED' => ['err', 'Nem fut'],
               'UNREADABLE' => ['err', 'Hiba'], 'NOT_INSTALLED' => ['warn', 'Nincs beállítva']][$u];
      ?>
      <span class="lead" style="margin-right:6px">Frissítő:</span>
      <span class="badge badge--<?= h($ub[0]) ?>"><?= h($ub[1]) ?></span>
    </div>
    <div class="panel__b">
      <?php if ($v['running']['dev']): ?>
        <p class="lead">Ebben a környezetben a PHP-képfájlban nincs verzióazonosító (az első, frissítőn
           keresztüli telepítés után lesz).<?php if ($v['current']['commit'] ?? null): ?>
           A frissítő szerint a forrás: <b class="mono"><?= h(fr_short($v['current']['commit'])) ?></b>.<?php endif; ?></p>
      <?php else: ?>
        <p>Azonosító: <b class="mono"><?= h($v['running']['short']) ?></b>
           &nbsp;·&nbsp; Készült: <?= h(frl_time($v['running']['builtAt'])) ?></p>
      <?php endif; ?>
      <p class="lead">
        <?php if ($u === 'OK'): ?>
          A frissítő szolgáltatás fut a szerveren. Utolsó életjel: <?= h(frl_time($v['updater']['heartbeatAt'])) ?>.
        <?php elseif ($u === 'STOPPED'): ?>
          A frissítő szolgáltatás leállt (utolsó életjel: <?= h(frl_time($v['updater']['heartbeatAt'])) ?>).
          Amíg a szervercsapat el nem indítja, innen nem lehet telepíteni.
        <?php elseif ($u === 'UNREADABLE'): ?>
          <?= h((string)$v['updater']['problem']) ?>
        <?php else: ?>
          A frissítő szolgáltatás még nincs telepítve a szerveren. Ez egyszeri beállítás, a
          <span class="mono">docs/FRISSITO.md</span> írja le. Addig a frissítés a megszokott kézi módon megy.
        <?php endif; ?>
      </p>
    </div>
  </div>

  <?php if ($u !== 'NOT_INSTALLED'): ?>
  <div class="panel" style="margin-bottom:16px">
    <div class="panel__h"><h2>Elérhető frissítés</h2></div>
    <div class="panel__b">
      <?php $av = $v['available']; $cur = $v['current']['commit'] ?? null; ?>
      <?php if ($av && $av['commit'] !== $cur): ?>
        <p><b><?= (int)$av['count'] ?> változás</b> vár telepítésre · verzió:
           <b class="mono"><?= h(fr_short($av['commit'])) ?></b> · <?= h(frl_time($av['committedAt'])) ?></p>
        <?php if ($av['hasMigrations']): ?>
          <div class="msg msg--warn" style="margin:10px 0">
            <p><b>Ez a frissítés az adatbázist is módosítja.</b> A telepítés idejére a súgó néhány percre
               leáll (a nyilvános oldal és az admin is), előtte teljes mentés készül. Olyan időpontot
               válassz, amikor senki nem szerkeszt.</p>
          </div>
        <?php endif; ?>
        <table class="tbl" style="margin:10px 0">
          <thead><tr><th>Verzió</th><th>Mi változott</th><th>Mikor</th></tr></thead>
          <tbody>
          <?php foreach ($av['commits'] as $i => $c): ?>
            <tr class="<?= $i >= 8 ? 'fr-tobb' : '' ?>"<?= $i >= 8 ? ' hidden' : '' ?>>
              <td class="mono nowrap"><?= h(fr_short($c['sha'])) ?></td>
              <td><?= h($c['subject']) ?></td>
              <td class="nowrap"><?= h(frl_time($c['date'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (count($av['commits']) > 8): ?>
          <button type="button" class="btn btn--ghost" data-fr-tobb>Mind a(z) <?= count($av['commits']) ?> változás mutatása</button>
        <?php endif; ?>
        <?php if ($av['count'] > count($av['commits'])): ?>
          <p class="lead">A lista a legutóbbi <?= count($av['commits']) ?> változást mutatja.</p>
        <?php endif; ?>
        <p style="margin-top:12px">
          <button type="button" class="btn btn--p" data-fr-nyit
                  data-commit="<?= h($av['commit']) ?>" data-short="<?= h(fr_short($av['commit'])) ?>"
                  data-count="<?= (int)$av['count'] ?>" data-mig="<?= $av['hasMigrations'] ? '1' : '0' ?>"
                  <?= $v['canRequest']['ok'] ? '' : 'disabled' ?>>Frissítés telepítése</button>
          <span class="lead">&nbsp; Utoljára ellenőrizve: <?= h(frl_time($av['checkedAt'])) ?></span>
        </p>
        <?php if (!$v['canRequest']['ok']): ?>
          <p class="lead"><?= h($v['canRequest']['message']) ?></p>
        <?php endif; ?>
      <?php else: ?>
        <p class="lead">Nincs telepíthető újabb verzió – a szerveren a legújabb fut.</p>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($v['pending'] !== null): ?>
    <div class="msg msg--info" style="margin-bottom:16px">
      <span class="msg__h">A kérés elküldve</span>
      <p>A frissítő szolgáltatás néhány másodpercen belül felveszi
         <?php if ($v['pending']['id'] ?? null): ?>· kérte: <?= h(frl_who($names, (int)$v['pending']['requestedBy'])) ?>,
           <?= h(frl_time($v['pending']['requestedAt'])) ?><?php endif; ?>.</p>
    </div>
  <?php endif; ?>

  <?php if ($run): ?>
    <div class="panel" style="margin-bottom:16px">
      <div class="panel__h"><h2><?= $run['state'] === 'RUNNING' ? 'Telepítés folyamatban' : 'Az utolsó telepítés' ?></h2>
        <span class="sp"></span><?= frl_badge($run['state']) ?></div>
      <div class="panel__b">
        <p class="lead">Verzió: <span class="mono"><?= h(fr_short($run['commit'])) ?></span>
           (előtte: <span class="mono"><?= h(fr_short($run['previousCommit']) ?: '–') ?></span>)
           · indította: <?= h(frl_who($names, $run['requestedBy'])) ?> · <?= h(frl_time($run['startedAt'])) ?></p>
        <ol class="fr-lepesek">
          <?php foreach ($run['steps'] as $s): ?>
            <?php $ikon = ['PENDING' => '○', 'RUNNING' => '◌', 'DONE' => '✓', 'FAILED' => '✕', 'SKIPPED' => '–'][$s['state']]; ?>
            <li class="fr-l fr-l--<?= h(strtolower($s['state'])) ?>">
              <span class="fr-ikon" aria-hidden="true"><?= $ikon ?></span>
              <?= h(fr_step_label($s['key'])) ?>
              <?php if ($s['state'] === 'RUNNING'): ?><span class="lead"> folyamatban…</span>
              <?php elseif ($s['state'] === 'SKIPPED'): ?><span class="lead"> kihagyva</span>
              <?php elseif ($s['state'] === 'FAILED'): ?><span class="lead"> hiba</span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>
        <?php if ($run['state'] === 'ROLLED_BACK'): ?>
          <div class="msg msg--err"><p><b>A frissítés nem sikerült, a rendszer visszaállt az előző verzióra.</b>
            Adat nem veszett el. A hiba oka lent és a naplóban olvasható.</p></div>
        <?php elseif ($run['state'] === 'FAILED'): ?>
          <div class="msg msg--err"><p><b>A frissítés nem ment végig.</b> Az ok lent olvasható. Ha a súgó nem
            érhető el vagy hibásan működik, azonnal szólj a szervercsapatnak.</p></div>
        <?php endif; ?>
        <?php if ($run['error']): ?>
          <p style="white-space:pre-wrap;margin-top:8px"><?= h($run['error']) ?></p>
        <?php endif; ?>
        <?php if ($run['backupFile']): ?>
          <p class="lead">Adatbázis-mentés: <span class="mono"><?= h($run['backupFile']) ?></span></p>
        <?php endif; ?>
        <details data-fr-naplo="<?= h($run['id']) ?>"<?= ($openLog === $run['id'] || $run['state'] === 'RUNNING') ? ' open' : '' ?>>
          <summary>Napló</summary>
          <div class="fr-naplo-hely"><?= ($openLog === $run['id'] || $run['state'] === 'RUNNING') ? frl_log_box($cfg, $run['id']) : '' ?></div>
        </details>
      </div>
    </div>
  <?php endif; ?>

  <?php $korabbi = array_values(array_filter($v['history'], static fn($hh) => $hh['id'] !== ($run['id'] ?? null))); ?>
  <?php if ($korabbi): ?>
    <div class="panel">
      <div class="panel__h"><h2>Korábbi telepítések</h2></div>
      <div class="panel__b">
        <?php foreach ($korabbi as $hh): ?>
          <div class="fr-elozmeny">
            <p><span class="mono"><?= h(fr_short($hh['commit'])) ?></span> · <?= h(frl_time($hh['startedAt'])) ?>
               · <?= h(frl_who($names, $hh['requestedBy'])) ?> &nbsp;<?= frl_badge($hh['state']) ?></p>
            <?php if ($hh['error']): ?><p class="lead" style="white-space:pre-wrap"><?= h($hh['error']) ?></p><?php endif; ?>
            <details data-fr-naplo="<?= h($hh['id']) ?>"<?= $openLog === $hh['id'] ? ' open' : '' ?>>
              <summary>Napló</summary>
              <div class="fr-naplo-hely"><?= $openLog === $hh['id'] ? frl_log_box($cfg, $hh['id']) : '' ?></div>
            </details>
          </div>
        <?php endforeach; ?>
        <?php if ($v['historySkipped']): ?>
          <p class="lead"><?= (int)$v['historySkipped'] ?> olvashatatlan sor kimaradt az előzményekből.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
    <?php
    return (string)ob_get_clean();
}

function page_frissites(PDO $db, array $cfg, array $counts): void
{
    if (!fr_may_request_update(auth_user()['role'] ?? null)) {
        http_response_code(404);
        admin_head('Nincs ilyen oldal', '', $counts);
        echo '<div class="page"><div class="msg msg--err">Nincs ilyen oldal.</div></div>';
        admin_foot();
        return;
    }

    // Napló: csak a frissitotol kapott azonositok kozul (a futo + az elozmenyek).
    if (isset($_GET['naplo'])) {
        $id = (string)$_GET['naplo'];
        $v = frc_view($cfg, time());
        $known = array_merge($v['run'] ? [$v['run']['id']] : [], array_column($v['history'], 'id'));
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        if (!fr_is_uuid($id) || !in_array($id, $known, true)) {
            http_response_code(404);
            echo '<p class="lead">Ehhez a futáshoz nincs napló.</p>';
            return;
        }
        echo frl_log_box($cfg, $id);
        return;
    }

    $v = frc_view($cfg, time());

    // A lap valtozo resze (frissites kozben 4, egyebkent 30 masodpercenkent).
    if (isset($_GET['resz'])) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo frl_render($db, $cfg, $v);
        return;
    }

    admin_head(t('Frissítés'), 'settings', $counts);
    ?>
<style>
  .fr-naplo{max-height:360px;overflow:auto;font:12px/1.5 ui-monospace,Menlo,monospace;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;white-space:pre-wrap;margin-top:8px}
  .fr-lepesek{list-style:none;padding:0;margin:10px 0}
  .fr-l{padding:3px 0}
  .fr-ikon{display:inline-block;width:1.4em;text-align:center}
  .fr-l--done .fr-ikon{color:#15803d}.fr-l--failed .fr-ikon{color:#b91c1c}.fr-l--running{font-weight:600}
  .fr-l--pending{color:#94a3b8}
  .fr-elozmeny{border-top:1px solid #e2e8f0;padding:8px 0}
  .fr-elozmeny:first-child{border-top:0}
  #fr-modal{position:fixed;inset:0;background:rgba(15,23,42,.45);display:flex;align-items:center;justify-content:center;z-index:50}
  #fr-modal[hidden]{display:none}
  #fr-modal .panel{max-width:520px;width:calc(100% - 32px)}
</style>
<div class="page" style="max-width:900px">
  <h1 class="pt"><?= h(t('Frissítés')) ?></h1>
  <?= flash_render() ?>
  <div class="msg msg--warn" id="fr-offline" hidden>
    <p><b>A szerver most nem válaszol.</b> Telepítés közben ez rendben van: a súgó épp átvált az új verzióra.
       Az oldal magától próbálkozik tovább.</p>
  </div>
  <div class="msg msg--info" id="fr-ujverzio" hidden>
    <p><b>Új verzió fut a szerveren.</b> Töltsd újra az oldalt, hogy a felület is az új legyen.
       <button type="button" class="btn" onclick="location.reload()">Oldal újratöltése</button></p>
  </div>
  <div id="fr-tarto"><?= frl_render($db, $cfg, $v) ?></div>
</div>

<div id="fr-modal" hidden role="dialog" aria-modal="true" aria-labelledby="fr-modal-cim">
  <form class="panel" method="post" action="<?= h(admin_url()) ?>" autocomplete="off">
    <div class="panel__h"><h2 id="fr-modal-cim">Frissítés telepítése</h2></div>
    <div class="panel__b">
      <?= csrf_input() ?>
      <input type="hidden" name="a" value="frissites.kerelem">
      <input type="hidden" name="commit" id="fr-commit" value="">
      <p id="fr-modal-szoveg"></p>
      <div class="msg msg--warn" id="fr-modal-mig" hidden><p>Az adatbázis is módosul. A súgó a mentés és az
        átállás idejére néhány percre leáll.</p></div>
      <p class="lead">A telepítés közben a súgó rövid időre nem érhető el – az el nem mentett szerkesztés elveszhet.</p>
      <label style="display:block;margin-top:10px">A jelszavad
        <input type="password" name="password" id="fr-jelszo" class="inp" autocomplete="current-password" required maxlength="200">
      </label>
      <p style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn" data-fr-megse>Mégsem</button>
        <button class="btn btn--p">Megerősítés és telepítés</button>
      </p>
    </div>
  </form>
</div>

<script>
(function () {
  var tarto = document.getElementById('fr-tarto');
  var modal = document.getElementById('fr-modal');
  var jelszo = document.getElementById('fr-jelszo');
  var kezdoVerzio = (document.getElementById('fr-allapot') || {}).dataset ? document.getElementById('fr-allapot').dataset.running : '';
  var nyitott = {};
  var url = function (extra) { return location.pathname + '?p=frissites&' + extra; };

  function bekot() {
    document.querySelectorAll('[data-fr-naplo]').forEach(function (d) {
      if (d.dataset.kotve) { return; }
      d.dataset.kotve = '1';
      if (d.open) { nyitott[d.dataset.frNaplo] = true; }
      d.addEventListener('toggle', function () {
        var id = d.dataset.frNaplo;
        nyitott[id] = d.open;
        if (d.open) { naplo(d); }
      });
    });
  }
  function naplo(d) {
    var hely = d.querySelector('.fr-naplo-hely');
    if (!hely.innerHTML.trim()) { hely.innerHTML = '<p class="lead">Betöltés…</p>'; }
    fetch(url('naplo=' + encodeURIComponent(d.dataset.frNaplo)), {credentials: 'same-origin', cache: 'no-store'})
      .then(function (r) { return r.text(); })
      .then(function (t) { hely.innerHTML = t; })
      .catch(function () { hely.innerHTML = '<p class="lead">Ehhez a telepítéshez nincs elérhető napló.</p>'; });
  }
  function frissit() {
    var nyitottIdk = Object.keys(nyitott).filter(function (k) { return nyitott[k]; });
    fetch(url('resz=1' + (nyitottIdk[0] ? '&nyitott=' + encodeURIComponent(nyitottIdk[0]) : '')),
          {credentials: 'same-origin', cache: 'no-store'})
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
      .then(function (t) {
        document.getElementById('fr-offline').hidden = true;
        if (modal.hidden) { tarto.innerHTML = t; bekot(); }
        var a = document.getElementById('fr-allapot');
        if (a && kezdoVerzio && a.dataset.running && a.dataset.running !== kezdoVerzio) {
          document.getElementById('fr-ujverzio').hidden = false;
        }
        idozit(a && a.dataset.busy === '1');
      })
      .catch(function () { document.getElementById('fr-offline').hidden = false; idozit(true); });
  }
  function idozit(sietos) { setTimeout(frissit, sietos ? 4000 : 30000); }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-fr-nyit]');
    if (b && !b.disabled) {
      // A modal a MEGNYITAS pillanataban latott verziot kuldi el.
      document.getElementById('fr-commit').value = b.dataset.commit;
      document.getElementById('fr-modal-szoveg').textContent =
        'A ' + b.dataset.short + ' verzió kerül ki (' + b.dataset.count + ' változás).';
      document.getElementById('fr-modal-mig').hidden = b.dataset.mig !== '1';
      modal.hidden = false;
      jelszo.value = '';
      jelszo.focus();
    }
    if (e.target.closest('[data-fr-megse]')) { modal.hidden = true; jelszo.value = ''; }
    var t = e.target.closest('[data-fr-tobb]');
    if (t) { document.querySelectorAll('.fr-tobb').forEach(function (r) { r.hidden = !r.hidden; }); }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { modal.hidden = true; jelszo.value = ''; } });

  bekot();
  var a0 = document.getElementById('fr-allapot');
  idozit(a0 && a0.dataset.busy === '1');
})();
</script>
    <?php
    admin_foot();
}
