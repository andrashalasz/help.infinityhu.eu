<?php
/**
 * admin_frissites.php - a sugo frissitese a bongeszobol.
 *
 * MIT NEM CSINAL: ez a lap SEMMIT NEM FUTTAT a szerveren. Csak leir egy
 * kerelem-fajlt. A tenyleges munkat (git pull, docker compose, mentes) a
 * gazdagepen futo tools/frissito-figyelo.sh vegzi, percenkent ranezve.
 *
 * MIERT IGY: a PHP a kontenerben fut, a frissites viszont a gazdagepen
 * tortenik. Ha a kontener kapna jogot a gazdagep vezerlesere (pl. becsatolt
 * Docker socket), akkor a sugo admin felulete gyakorlatilag root hozzaferest
 * adna a szerverhez. Igy viszont a legrosszabb, ami egy feltort adminbol
 * kovetkezhet: valaki elindit egy frissitest.
 */
declare(strict_types=1);

function frissites_dir(array $cfg): string
{
    return rtrim($cfg['update_dir'] ?? '/srv/frissites', '/');
}

/** Egy JSON-fajl beolvasasa a megosztott mappabol; hiba eseten ures tomb. */
function frissites_json(array $cfg, string $nev): array
{
    $f = frissites_dir($cfg) . '/' . $nev;
    if (!is_file($f)) { return []; }
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function page_frissites(array $cfg, array $counts): void
{
    $dir     = frissites_dir($cfg);
    $verzio  = frissites_json($cfg, 'verzio.json');
    $allapot = frissites_json($cfg, 'allapot.json');
    $naplo   = @file_get_contents($dir . '/naplo.txt') ?: '';

    $fut      = ($allapot['allapot'] ?? '') === 'fut';
    $elerheto = (int)($verzio['elerheto_db'] ?? 0);
    $elozo    = trim((string)@file_get_contents($dir . '/elozo-commit.txt'));
    $mukodik  = is_dir($dir) && is_writable($dir) && $verzio !== [];

    admin_head(t('Frissítés'), 'settings', $counts);
    ?>
<?php if ($fut): ?><meta http-equiv="refresh" content="4"><?php endif; ?>
<div class="page" style="max-width:900px">
  <h1 class="pt"><?= h(t('Frissítés')) ?></h1>
  <?= flash_render() ?>

  <?php if (!$mukodik): ?>
    <div class="msg msg--warn">
      <span class="msg__h"><?= h(t('A frissítő még nincs beállítva')) ?></span>
      <p>A gazdagépen percenként futnia kell a <span class="mono">tools/frissito-figyelo.sh</span>
         szkriptnek, és a <span class="mono"><?= h($dir) ?></span> mappának írhatónak kell lennie.
         A beállítást a <span class="mono">FRISSITES_DOCKER.md</span> írja le.</p>
    </div>
  <?php endif; ?>

  <div class="panel" style="margin-bottom:16px">
    <div class="panel__h"><h2><?= h(t('Ami most fut')) ?></h2><span class="sp"></span>
      <?php if ($elerheto > 0): ?>
        <span class="badge badge--warn"><?= (int)$elerheto ?> <?= h(t('új változat')) ?></span>
      <?php elseif ($mukodik): ?>
        <span class="badge badge--ok"><?= h(t('naprakész')) ?></span>
      <?php endif; ?>
    </div>
    <div class="panel__b">
      <?php if ($verzio): ?>
        <p><b class="mono"><?= h((string)($verzio['commit'] ?? '?')) ?></b>
           &nbsp;<?= h((string)($verzio['uzenet'] ?? '')) ?></p>
        <p class="lead">
          <?= h(t('ág')) ?>: <span class="mono"><?= h((string)($verzio['ag'] ?? '?')) ?></span>
          &nbsp;·&nbsp;
          <?= h(substr((string)($verzio['datum'] ?? ''), 0, 16)) ?>
        </p>
      <?php else: ?>
        <p class="lead"><?= h(t('Még nincs adat — a figyelő szkript nem futott le.')) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($elerheto > 0 && !$fut): ?>
    <div class="panel" style="margin-bottom:16px">
      <div class="panel__h"><h2><?= h(t('Ami jönne')) ?></h2></div>
      <div class="panel__b">
        <table class="tbl">
          <thead><tr><th><?= h(t('Változat')) ?></th><th><?= h(t('Mi változott')) ?></th></tr></thead>
          <tbody>
          <?php foreach (($verzio['elerheto'] ?? []) as $c): ?>
            <tr>
              <td class="mono nowrap"><?= h((string)($c['c'] ?? '')) ?></td>
              <td><?= h((string)($c['u'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>

        <div class="msg msg--info" style="margin-top:14px">
          <p>A frissítés <b>először biztonsági mentést készít</b> az adatbázisról, és csak utána
             tölti le a kódot és építi újra a konténereket. Ha bármelyik lépés elakad, megáll,
             és a napló megmutatja, hol.</p>
        </div>

        <form method="post" action="<?= h(admin_url()) ?>" style="margin-top:12px"
              onsubmit="return confirm('Elindítod a frissítést? Előbb mentés készül az adatbázisról.')">
          <?= csrf_input() ?>
          <input type="hidden" name="a" value="frissites.kerelem">
          <input type="hidden" name="muvelet" value="frissites">
          <button class="btn btn--p"><?= h(t('Frissítés indítása')) ?></button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($fut || $naplo !== ''): ?>
    <div class="panel" style="margin-bottom:16px">
      <div class="panel__h">
        <h2><?= h(t('Napló')) ?></h2><span class="sp"></span>
        <?php if ($fut): ?>
          <span class="badge badge--warn"><?= h(t('fut…')) ?></span>
        <?php elseif (($allapot['allapot'] ?? '') === 'hiba'): ?>
          <span class="badge badge--err"><?= h(t('hibára futott')) ?></span>
        <?php elseif (($allapot['allapot'] ?? '') === 'kesz'): ?>
          <span class="badge badge--ok"><?= h(t('kész')) ?></span>
        <?php endif; ?>
      </div>
      <div class="panel__b">
        <?php if (($allapot['uzenet'] ?? '') !== ''): ?>
          <p><b><?= h((string)$allapot['uzenet']) ?></b></p>
        <?php endif; ?>
        <pre class="mono" style="max-height:420px;overflow:auto;font-size:12px;line-height:1.5;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;white-space:pre-wrap"><?= h($naplo) ?></pre>
        <?php if ($fut): ?>
          <p class="lead" style="margin-top:8px"><?= h(t('Az oldal magától frissül, amíg fut.')) ?></p>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($elozo !== '' && !$fut): ?>
    <div class="panel">
      <div class="panel__h"><h2><?= h(t('Visszaállítás')) ?></h2></div>
      <div class="panel__b">
        <p class="lead">Ha a frissítés után valami nem jó, egy kattintással visszaállhatsz az
           előző változatra (<span class="mono"><?= h($elozo) ?></span>). Ez a <b>kódot</b> állítja
           vissza — az adatbázist nem. Ahhoz a mentést kell visszatölteni.</p>
        <form method="post" action="<?= h(admin_url()) ?>" style="margin-top:10px"
              onsubmit="return confirm('Visszaállsz erre: <?= h($elozo) ?>?')">
          <?= csrf_input() ?>
          <input type="hidden" name="a" value="frissites.kerelem">
          <input type="hidden" name="muvelet" value="visszaallitas">
          <input type="hidden" name="cel" value="<?= h($elozo) ?>">
          <button class="btn btn--d"><?= h(t('Visszaállás erre a változatra')) ?></button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
    <?php
    admin_foot();
}
