<?php
/**
 * admin_uitexts.php - A KEZELOFELULET SZOVEGEI, sajat lapon.
 *
 * Korabban ez a Beallitasok fulon allt, egyetlen 600 soros tablazatban -
 * kezelhetetlenul. Itt sajat lapot kap:
 *   - terulet szerint csoportositva (fejezetek, forditas, szerkeszto, ...)
 *   - keresheto (magyar szoveg ES forditas szerint egyarant)
 *   - szurheto: csak a hianyzok / csak a leforditottak
 *   - nyelvenkent haladasjelzo, mellette "forditsd le a hianyzokat" gomb
 *
 * A kulcsok jegyzeket a ui_keys_in_use() adja: az VEGIGOLVASSA a forrast, es
 * kigyujti a t('...') hivasokat - igy a lista magatol koveti a kodot.
 */
declare(strict_types=1);

/**
 * Melyik teruletre tartozik egy kulcs? A pontozott elotag alapjan; aminek
 * nincs elotagja (maga a magyar szoveg a kulcs), az az "Altalanos".
 */
function uitext_group(string $key): string
{
    $map = [
        'tab'        => 'Menü és fejléc',
        'head'       => 'Menü és fejléc',
        'eszkoz'     => 'Fejezetlista',
        'lathato'    => 'Fejezetlista',
        'fofejezet'  => 'Fejezetlista',
        'torles'     => 'Törlés és megerősítés',
        'ed'         => 'Szerkesztő',
        'diff'       => 'Változatok',
        'valtozat'   => 'Változatok',
        'valtozatok' => 'Változatok',
        'kozzetetel' => 'Közzététel',
        'kiadas'     => 'Kiadások',
        'import'     => 'Word import',
        'forditas'   => 'Fordítás',
        'mt'         => 'Gépi fordítás',
        'ui'         => 'Felületszövegek',
        'media'      => 'Képek, videók',
        'auth'       => 'Belépés és jelszó',
        'flash'      => 'Visszajelző üzenetek',
        'state'      => 'Állapotok',
        'ujfejezet'  => 'Fejezetlista',
        'ujfofejezet'=> 'Fejezetlista',
        'jelszo'     => 'Belépés és jelszó',
        'sugo'       => 'Súgószövegek',
        'ures'       => 'Súgószövegek',
        'vazlat'     => 'Közzététel',
        'fejezet'    => 'Közzététel',
        'kep'        => 'Képek, videók',
        'undo'       => 'Visszavonás',
    ];
    $prefix = str_contains($key, '.') ? strstr($key, '.', true) : '';
    return $map[$prefix] ?? 'Általános';
}

function page_uitexts(PDO $db, array $cfg, array $counts): void
{
    if (!auth_is('admin')) {
        admin_head(t('A kezelőfelület szövegei'), '', $counts);
        echo '<div class="page"><div class="panel"><div class="panel__b">'
           . h(t('flash.ui.save.ehhez-adminisztratori-jog')) . '</div></div></div>';
        admin_foot();
        return;
    }

    $keys    = ui_keys_in_use();
    $targets = admin_target_langs();

    $text = [];
    try {
        foreach ($db->query('SELECT ui_key, lang, text FROM help_ui')->fetchAll() as $r) {
            $text[(string)$r['lang']][(string)$r['ui_key']] = (string)$r['text'];
        }
    } catch (Throwable $e) {
        // a 08_felulet_forditas.sql meg nem futott le
    }

    // csoportositas + nyelvenkenti keszultseg
    $groups = [];
    foreach ($keys as $key => $src) { $groups[uitext_group($key)][$key] = $src; }
    ksort($groups);

    $stat = [];
    foreach ($targets as $code => $label) {
        $kesz = 0;
        foreach ($keys as $key => $src) {
            if (trim((string)($text[$code][$key] ?? '')) !== '') { $kesz++; }
        }
        $stat[$code] = ['label' => $label, 'kesz' => $kesz, 'ossz' => count($keys),
                        'szazalek' => count($keys) ? (int)round($kesz / count($keys) * 100) : 100];
    }

    admin_head(t('A kezelőfelület szövegei'), '', $counts);
    ?>
<div class="page">

  <div class="panel" style="margin-bottom:16px">
    <div class="panel__h">
      <h2><?= h(t('A kezelőfelület szövegei')) ?></h2>
      <span class="sp"></span>
      <a class="btn btn--sm btn--ghost" href="<?= h(admin_url(['p' => 'settings'])) ?>"><?= h(t('Beállítások')) ?></a>
    </div>
    <div class="panel__b">
      <p class="lead" style="margin-bottom:14px"><?= t('uitext.bevezeto') ?></p>

      <div class="uit-stats">
        <?php foreach ($stat as $code => $st): ?>
          <div class="uit-stat">
            <div class="uit-stat__h">
              <b><?= h($st['label']) ?></b>
              <span class="muted"><?= (int)$st['kesz'] ?> / <?= (int)$st['ossz'] ?></span>
            </div>
            <div class="uit-bar"><i style="width:<?= (int)$st['szazalek'] ?>%"></i></div>
            <?php $hiany = $st['ossz'] - $st['kesz']; ?>
            <button class="btn btn--sm<?= $hiany ? ' btn--p' : ' btn--ghost' ?>" type="button"
                    data-ui-translate="<?= h((string)$code) ?>" <?= $hiany ? '' : 'disabled' ?>
                    title="<?= h(t('ui.forditas.sugo', ['nyelv' => $st['label']])) ?>">
              <?= $hiany
                  ? h(t('uitext.forditsd', ['n' => $hiany]))
                  : h(t('uitext.kesz')) ?>
            </button>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <form method="post" action="<?= h(admin_url()) ?>" id="uit-form">
    <?= csrf_input() ?>
    <input type="hidden" name="a" value="ui.save">

    <div class="panel">
      <div class="panel__h uit-toolbar">
        <input class="inp" id="uit-search" type="search" style="max-width:340px"
               placeholder="<?= h(t('uitext.kereses')) ?>" autocomplete="off">
        <select class="sel" id="uit-filter" style="width:auto">
          <option value="all"><?= h(t('uitext.szuro.mind')) ?></option>
          <option value="missing"><?= h(t('uitext.szuro.hianyzo')) ?></option>
          <option value="done"><?= h(t('uitext.szuro.kesz')) ?></option>
        </select>
        <span class="sp"></span>
        <span class="muted" id="uit-count"></span>
      </div>

      <div class="panel__b" style="padding-top:6px">
        <?php foreach ($groups as $gname => $gkeys): ?>
          <section class="uit-group" data-group="<?= h($gname) ?>">
            <h3 class="uit-group__h">
              <?= h(t($gname)) ?> <span class="badge"><?= count($gkeys) ?></span>
            </h3>
            <?php foreach ($gkeys as $key => $src): ?>
              <?php
                $ures = false;
                foreach ($targets as $code => $l) {
                    if (trim((string)($text[$code][$key] ?? '')) === '') { $ures = true; break; }
                }
              ?>
              <div class="uit-row<?= $ures ? ' uit-row--missing' : '' ?>">
                <div class="uit-src" title="<?= h($key) ?>"><?= h((string)$src) ?></div>
                <?php foreach ($targets as $code => $label): ?>
                  <label class="uit-cell">
                    <span class="uit-cell__l"><?= h(strtoupper((string)$code)) ?></span>
                    <input class="inp" name="ui[<?= h((string)$code) ?>][<?= h($key) ?>]"
                           value="<?= h($text[$code][$key] ?? '') ?>"
                           placeholder="<?= h((string)$src) ?>">
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </section>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="uit-save">
      <button class="btn btn--p" type="submit"><?= h(t('Mentés')) ?></button>
      <span class="muted"><?= h(t('uitext.mentes.sugo')) ?></span>
    </div>
  </form>
</div>
    <?php
    admin_foot();
}
