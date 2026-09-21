<?php
/**
 * admin_layout.php - az admin felulet kozos kerete es apro segedei.
 */
declare(strict_types=1);

/** A fulek sorrendje. A feliratuk a forditasbol jon: 'tab.<kulcs>'. */
const ADMIN_TAB_KEYS = ['dashboard', 'articles', 'import', 'translate', 'media',
                        'releases', 'export', 'users', 'trash'];

/** Ful-kulcs => felirat az aktualis felulet-nyelven. */
function admin_tabs(): array
{
    $out = [];
    foreach (ADMIN_TAB_KEYS as $k) { $out[$k] = t('tab.' . $k); }
    return $out;
}

/** A fulsavon kivul, a fejlec ikonjai kozott elerheto lapok. */
/**
 * A fogaskerek menujenek pontjai. Ezek nem a fulsavon vannak, mert
 * ritkabban kellenek - de mindegyik onallo lap, nem egy hosszu
 * "Beallitasok" oldal egymas ala zsufolt paneljei.
 */
const ADMIN_ICON_PAGES = [
    'mt'       => 'Gépi fordítás',
    'uitexts'  => 'A kezelőfelület szövegei',
    'langs'    => 'Nyelvek',
    'releases' => 'Kiadások',
];

/**
 * A sugo nyelvei. UJ NYELVET ITT kell felvenni - a forrasnyelv az elso elem
 * (magyar), a tobbi celnyelv. Minden felulet ebbol dolgozik: a Forditas ful
 * oszlopai, a gepi forditas celnyelvei, a nyelvi kartyak es az export.
 */
/**
 * Tartalek nyelvlista, ha a help_lang tabla meg nem letezik (regi telepites,
 * a 07_nyelvek.sql meg nem futott le).
 */
const ADMIN_LANGS_FALLBACK = ['hu' => 'Magyar', 'en' => 'English', 'de' => 'Deutsch'];

/**
 * A sugo nyelvei az ADATBAZISBOL: kod => nev, a beallitott sorrendben.
 * Uj nyelvhez nem kell kodot modositani - a Beallitasok fulon vehetsz fel.
 */
function admin_langs(?PDO $db = null): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }

    try {
        $pdo = $db ?? help_db_rw(require __DIR__ . '/../config.php');
        $rows = $pdo->query('SELECT code, name FROM help_lang WHERE is_active = 1
                             ORDER BY is_source DESC, sort_order, code')->fetchAll();
        $out = [];
        foreach ($rows as $r) { $out[(string)$r['code']] = (string)$r['name']; }
        if ($out) { return $cache = $out; }
    } catch (Throwable $e) {
        // a tabla meg nincs meg - menjen a tartalek
    }
    return $cache = ADMIN_LANGS_FALLBACK;
}

/** A forrasnyelv kodja (a lista elso eleme). */
function admin_source_lang(): string
{
    return (string)array_key_first(admin_langs());
}

/** A celnyelvek: minden nyelv a forrasnyelv nelkul. */
function admin_target_langs(): array
{
    $l = admin_langs();
    unset($l[admin_source_lang()]);
    return $l;
}

function flash(string $type, string $text): void
{
    $_SESSION['flash'][] = ['type' => $type, 'text' => $text];
}

function flash_render(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $out .= '<div class="msg msg--' . h($f['type']) . '" data-flash="' . h($f['type']) . '">'
              . $f['text'] . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

function admin_url(array $params = []): string
{
    return 'admin.php' . ($params ? '?' . http_build_query($params) : '');
}

function admin_head(string $title, string $page = '', array $counts = []): void
{
    $u = auth_user();
    ?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($title) ?> — Infinity Súgó admin</title>
<meta name="color-scheme" content="light dark">
<script>(function(){try{
  var t=localStorage.getItem('help.theme');
  if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}
  var f=parseFloat(localStorage.getItem('help.fs'));
  if(f>=0.85&&f<=1.6){document.documentElement.style.setProperty('--fs',String(f));}
}catch(e){}})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h(asset_url('/assets/app.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_url('/assets/admin.css')) ?>">
</head>
<body class="admin">
<?php if ($u !== null): ?>
<header class="ashell">
  <a class="ashell__brand" href="<?= h(admin_url()) ?>">
    <span class="ashell__logo"><?= help_logo(28) ?></span> Infinity Súgó
  </a>
  <span class="ashell__tag">admin</span>
  <span class="ashell__spacer"></span>

  <button class="sbtn" id="palette-open" title="<?= h(t('Ugrás / keresés (Ctrl+K)')) ?>">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
    <span class="sbtn__kbd">Ctrl K</span>
  </button>
  <button class="sbtn" id="keys-open" title="<?= h(t('Billentyűparancsok (?)')) ?>">?</button>

  <a class="sbtn" id="site-open" href="/hu/" target="_blank" rel="noopener" title="<?= h(t('A súgó megnyitása új lapon')) ?>">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14 21 3"/></svg>
  </a>
  <button class="sbtn" id="theme-toggle" title="<?= h(t('head.theme')) ?>"
          data-t-light="<?= h(t('Világos téma')) ?>" data-t-dark="<?= h(t('Sötét téma')) ?>"></button>

  <?php // A kezelofelulet merete: az admin 13px-es alapra epul, ami nagy
        // felbontasu kepernyon aprora sikerul. Itt allithato, es megjegyzi. ?>
  <span class="fsgrp">
    <button class="sbtn sbtn--fs" id="fs-down" title="<?= h(t('head.fs.down')) ?>">A<span>−</span></button>
    <button class="sbtn sbtn--fs" id="fs-up"   title="<?= h(t('head.fs.up')) ?>">A<span>+</span></button>
  </span>

  <span class="ashell__user">
    <span class="ashell__av"><?= h(mb_strtoupper(mb_substr($u['display_name'], 0, 1))) ?></span>
    <span><?= h($u['display_name']) ?> · <?= h($u['role']) ?></span>
  </span>
  <?php $uiLangs = admin_langs(); if (count($uiLangs) > 1): ?>
    <form method="post" action="<?= h(admin_url()) ?>" style="display:inline">
      <?= csrf_input() ?>
      <input type="hidden" name="a" value="user.uilang">
      <select class="sbtn sbtn--sel" name="ui_lang" title="<?= h(t('head.uilang')) ?>"
              onchange="this.form.submit()">
        <?php foreach ($uiLangs as $code => $label): ?>
          <option value="<?= h($code) ?>" <?= ui_lang() === $code ? 'selected' : '' ?>>
            <?= h(strtoupper($code)) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>

  <?php if (auth_can('settings')): ?>
    <?php // A fogaskerek MENUT nyit: gepi forditas, felulet-szovegek,
          // nyelvek, kiadasok - mindegyik sajat lap. ?>
    <div class="amenu">
      <button class="sbtn<?= array_key_exists($page, ADMIN_ICON_PAGES) ? ' on' : '' ?>" type="button"
              id="gear-btn" aria-expanded="false" aria-haspopup="true"
              title="<?= h(t('head.settings')) ?>">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"/>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.14.35.4.64.73.82.29.16.62.24.95.24H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
      </button>
      <div class="amenu__m" id="gear-menu" role="menu" hidden>
        <?php foreach (ADMIN_ICON_PAGES as $key => $label): ?>
          <a role="menuitem" class="<?= $page === $key ? 'on' : '' ?>"
             href="<?= h(admin_url(['p' => $key])) ?>"><?= h(t($label)) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
  <a class="sbtn" href="<?= h(admin_url(['p' => 'account'])) ?>" title="<?= h(t('head.account')) ?>">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>
  </a>
  <a class="sbtn" href="<?= h(admin_url(['a' => 'logout'])) ?>" title="<?= h(t('head.logout')) ?>">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v2"/><path d="M19 12H9m10 0-3-3m3 3-3 3"/></svg>
  </a>
</header>

<nav class="tabs">
  <?php foreach (admin_tabs() as $key => $label): ?>
    <?php if (!auth_can($key)) { continue; } ?>
    <a class="<?= $page === $key ? 'on' : '' ?>" href="<?= h(admin_url(['p' => $key])) ?>">
      <?= h($label) ?><?php if (isset($counts[$key])): ?><span class="n"><?= (int)$counts[$key] ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>
<?php endif;
}

function admin_foot(): void
{
    if (auth_user() !== null): ?>
<div class="modal" id="modal-keys">
  <div class="modal__box" style="max-width:480px">
    <div class="modal__h"><?= h(t('Billentyűparancsok')) ?></div>
    <div class="modal__b">
      <div class="kbdbox">
        <kbd>Ctrl</kbd><span><?= h(t('Ugrás / keresés — fejezetek és fülek egyben')) ?></span>
        <kbd>/</kbd><span><?= h(t('A bal oldali fejezetszűrő')) ?></span>
        <kbd>↑</kbd><span><?= h(t('Lépkedés a fejezetlistán')) ?></span>
        <kbd>Enter</kbd><span><?= h(t('A kijelölt elem megnyitása')) ?></span>
        <kbd>Ctrl</kbd><span><?= h(t('Vázlat mentése a szerkesztőben')) ?></span>
        <kbd>A</kbd><span>Fejezetek</span>
        <kbd>M</kbd><span>Modulok</span>
        <kbd>I</kbd><span>Word import</span>
        <kbd>T</kbd><span><?= h(t('Fordítás')) ?></span>
        <kbd>K</kbd><span><?= h(t('Képek, videók')) ?></span>
        <kbd>E</kbd><span>Export</span>
        <kbd>U</kbd><span><?= h(t('Felhasználók')) ?></span>
        <kbd>B</kbd><span><?= h(t('Beállítások')) ?></span>
        <kbd>D</kbd><span><?= h(t('Áttekintés')) ?></span>
        <kbd>Esc</kbd><span><?= h(t('Ablak bezárása')) ?></span>
      </div>
    </div>
    <div class="modal__f"><button class="btn btn--p" type="button" data-close>Rendben</button></div>
  </div>
</div>
<?php endif; ?>
<!-- Altalanos megerosito ablak.
     Korabban minden veszelyes muvelet a bongeszo window.confirm() ablakat
     hasznalta. Azt tobb bongeszo es minden beagyazott nezet letiltja, es
     olyankor a muvelet NEMAN elmaradt - a gomb latszolag nem csinalt semmit.
     Ez a sajat ablak mindenhol mukodik. Barmelyik urlapra rairhato:
     <form data-confirm="Biztosan?"> -->
<div class="modal" id="modal-confirm">
  <div class="modal__box">
    <div class="modal__h" id="confirm-title"><?= h(t('Megerősítés')) ?></div>
    <div class="modal__b">
      <div class="msg msg--warn" style="margin:0" id="confirm-text"></div>
    </div>
    <div class="modal__f">
      <button class="btn btn--ghost" type="button" data-close><?= h(t('Mégsem')) ?></button>
      <button class="btn" type="button" id="confirm-alt" hidden></button>
      <button class="btn btn--danger" type="button" id="confirm-ok">Igen, folytatom</button>
    </div>
  </div>
</div>

<script src="<?= h(asset_url('/assets/admin.js')) ?>" defer></script>
</body>
</html>
    <?php
}

/**
 * A cikk szakaszainak (help_section) ujraepitese a kozzetett HTML-bol.
 * Ezt hasznalja az olvasoi oldal jobb oldali tartalomjegyzeke, ezert minden
 * kozzetetel utan frissiteni kell.
 */
/**
 * Uj fejezet helye a modulon belul.
 *
 * Ha a szerkeszto nem adott meg fejezetszamot, a modul szama ala kepezzuk a
 * kovetkezo szabad sorszamot: az "1 Elso lepesek" modulban 1.1 ... 1.4 utan
 * 1.5-ot. A sorrend ugyanigy a lista vegere kerul, tizesevel lepve, hogy
 * kesobb kezzel is legyen hova beszurni.
 *
 * @return array{0:string,1:int} [fejezetszam, sorrend]
 */
/**
 * A fanak megadja fejezetenkent a merulesi melyseget es a kovetkezo szabad
 * ALFEJEZET-szamot, hogy a bal oldali listaban behuzva lassanak, es minden
 * sor melle kikerulhessen a "+" gomb.
 *
 * Az "1.3" ala az "1.3.1" jon, ha az mar letezik, akkor az "1.3.2".
 */
function tree_annotate(array $tree): array
{
    foreach ($tree as &$m) {
        $moduleNo = trim((string)$m['chapter_no']);
        $numbers  = array_map(static fn(array $a): string => trim((string)$a['chapter_no']), $m['articles']);

        foreach ($m['articles'] as &$a) {
            $no = trim((string)$a['chapter_no']);
            $a['depth'] = help_chapter_depth($moduleNo, $no);

            $maxSub = 0;
            if ($no !== '') {
                foreach ($numbers as $other) {
                    if (preg_match('/^' . preg_quote($no, '/') . '\.(\d+)$/', $other, $mm)) {
                        $maxSub = max($maxSub, (int)$mm[1]);
                    }
                }
            }
            $a['next_sub'] = $no !== '' ? $no . '.' . ($maxSub + 1) : '';
        }
        unset($a);
    }
    unset($m);
    return $tree;
}

function article_next_slot(PDO $db, int $moduleId, string $lang, string $chapter, string $sortOrder): array
{
    $st = $db->prepare('SELECT chapter_no FROM help_module WHERE id = ?');
    $st->execute([$moduleId]);
    $moduleNo = trim((string)$st->fetchColumn());

    $st = $db->prepare('SELECT chapter_no, sort_order FROM help_article WHERE module_id = ? AND lang = ?');
    $st->execute([$moduleId, $lang]);
    $rows = $st->fetchAll();

    $maxSort = 0;
    $maxOrd  = 0;
    foreach ($rows as $r) {
        $maxSort = max($maxSort, (int)$r['sort_order']);
        if ($moduleNo !== ''
            && preg_match('/^' . preg_quote($moduleNo, '/') . '\.(\d+)$/', trim((string)$r['chapter_no']), $m)) {
            $maxOrd = max($maxOrd, (int)$m[1]);
        }
    }

    if ($chapter === '' && $moduleNo !== '') {
        $chapter = $moduleNo . '.' . ($maxOrd + 1);
    }

    $sort = $sortOrder !== '' ? (int)$sortOrder : 0;
    if ($sort !== 0) { return [$chapter, $sort]; }

    // Alfejezet (pl. 1.3.1) rogton a szuloje (1.3) moge kerul, ne a modul
    // vegere. A sorrend tizesevel lep, ezert van hely kozottuk.
    if (substr_count($chapter, '.') > substr_count($moduleNo, '.') + 1) {
        $parentNo = substr($chapter, 0, (int)strrpos($chapter, '.'));
        $after = null;
        foreach ($rows as $r) {
            $no = trim((string)$r['chapter_no']);
            if ($no === $parentNo || str_starts_with($no, $parentNo . '.')) {
                $after = max((int)$after, (int)$r['sort_order']);
            }
        }
        if ($after !== null) { return [$chapter, $after + 1]; }
    }

    return [$chapter, $maxSort + 10];
}

function sections_rebuild(PDO $db, int $articleId, string $html): int
{
    [, $sections] = help_anchorize($html);

    $db->prepare('DELETE FROM help_section WHERE article_id = ?')->execute([$articleId]);
    if (!$sections) { return 0; }

    $st = $db->prepare('INSERT INTO help_section (article_id, chapter_no, anchor, title, level, plain_text, sort_order)
                        VALUES (?,?,?,?,?,?,?)');
    $i = 0;
    foreach ($sections as $s) {
        $st->execute([
            $articleId,
            mb_substr($s['chapter_no'], 0, 16),
            mb_substr($s['anchor'], 0, 160),
            mb_substr($s['title'], 0, 255),
            $s['level'],
            '',
            $i++,
        ]);
    }
    return $i;
}

/** Modulok + cikkeik egy nyelven, az admin listakhoz. */
function admin_tree(PDO $db, string $lang): array
{
    $mods = $db->prepare('SELECT id, chapter_no, title, slug, sort_order, is_published
                            FROM help_module WHERE lang = ? ORDER BY sort_order, id');
    $mods->execute([$lang]);
    $modules = $mods->fetchAll();
    if (!$modules) { return []; }

    $st = $db->prepare('SELECT id, module_id, chapter_no, slug, title, sort_order, is_published,
                               draft_html IS NOT NULL AS has_draft, updated_at
                          FROM help_article WHERE lang = ? ORDER BY sort_order, id');
    $st->execute([$lang]);
    $by = [];
    foreach ($st->fetchAll() as $a) { $by[(int)$a['module_id']][] = $a; }

    foreach ($modules as &$m) { $m['articles'] = $by[(int)$m['id']] ?? []; }
    unset($m);
    return $modules;
}

/**
 * A szem ikon egy fejezethez vagy fofejezethez: ki- es bekapcsolja a
 * lathatosagot a nyilvanos oldalon.
 *
 * A gomb sajat urlapot kap (data-scope-ask), amit az admin.js fog el: elobb
 * megkerdezi, hogy minden nyelven vagy csak ezen az egyen kapcsoljunk-e.
 *
 * @param string $what 'article' vagy 'module'
 */
function visibility_form(string $what, int $id, bool $on, string $lang, string $label, string $from = 'articles'): string
{
    $eye = $on
        ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
          . ' stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12Z"/>'
          . '<circle cx="12" cy="12" r="2.6"/></svg>'
        : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"'
          . ' stroke-linecap="round" stroke-linejoin="round"><path d="M10.6 6.1A10 10 0 0 1 12 6c6.4 0 10 6 10 6a17 17 0 0 1-3 3.6"/>'
          . '<path d="M6.3 7.6A17 17 0 0 0 2 12s3.6 6.5 10 6.5a10 10 0 0 0 3.7-.7"/>'
          . '<path d="m3 3 18 18"/><path d="M9.7 9.8a2.6 2.6 0 0 0 3.5 3.6"/></svg>';

    $title = $on
        ? t('lathato.ki', ['nev' => $label])
        : t('lathato.be', ['nev' => $label]);

    $ask = $on
        ? t('lathato.kerdes.ki', ['nev' => $label])
        : t('lathato.kerdes.be', ['nev' => $label]);

    ob_start(); ?>
<form method="post" action="<?= h(admin_url()) ?>" class="picker__eyef" data-scope-ask="<?= h($ask) ?>"
      data-scope-title="<?= h(t('lathato.cim')) ?>"
      data-scope-all="<?= h(t('lathato.mind')) ?>"
      data-scope-one="<?= h(t('lathato.egy')) ?>">
  <?= csrf_input() ?>
  <input type="hidden" name="a" value="<?= h($what) ?>.toggle">
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <input type="hidden" name="lang" value="<?= h($lang) ?>">
  <input type="hidden" name="from" value="<?= h($from) ?>">
  <input type="hidden" name="scope" value="one">
  <button type="submit" class="picker__madd picker__eye<?= $on ? '' : ' picker__eye--off' ?>"
          title="<?= h($title) ?>"><?= $eye ?></button>
</form>
<?php
    return (string)ob_get_clean();
}

/**
 * A szem ikon utani visszajelzes. Mindig megmondja, hany nyelvre hatott -
 * ez a kulonbseg a ket valasz kozott, es ezt keresi a felhasznalo.
 */
function visibility_flash(bool $on, string $name, string $scope, int $langs, string $lang): string
{
    $hol = $scope === 'all'
        ? t('lathato.hol.mind', ['n' => $langs])
        : t('lathato.hol.egy', ['ny' => mb_strtoupper($lang)]);

    return $on
        ? t('lathato.kesz.be', ['nev' => $name, 'hol' => $hol])
        : t('lathato.kesz.ki', ['nev' => $name, 'hol' => $hol]);
}

/**
 * Egy fofejezet fejezeteinek UJRASZAMOZASA a lista sorrendje szerint.
 *
 * Ha athuzol egy fejezetet masik fofejezet ala, a szama nem maradhat a regi
 * (5.2 nem allhat a 6-os fofejezetben), es a fofejezeten belul sem lehet
 * lyukas vagy osszekevert a szamozas.
 *
 * A melyseget a REGI szambol olvassuk ki (hany pontozott resze van a
 * fofejezet szamahoz kepest), igy az alfejezetek alfejezetek maradnak:
 *
 *      6      Keszletezes        (bevezeto - a fofejezet szama, marad)
 *      6.1    Beallitasok
 *      6.1.1  Torzsadatok
 *      6.2    Arucikkek
 *
 * A szamozas NYELVFUGGETLEN: a nyelvi valtozatokat a regi fejezetszam koti
 * ossze (a slug nyelvenkent mas), ezert mindet egyszerre irjuk at. A slughoz
 * NEM nyulunk: az a fejezet URL-je, es a mar kiadott hivatkozasok eltornenek.
 *
 * @return int ahany fejezet szama valoban megvaltozott (minden nyelvvel egyutt)
 */
/**
 * Ujraszamozas a FORRASNYELV szerint, barmelyik nyelvu modul-azonositobol.
 *
 * A fejezetszam nyelvfuggetlen: egy fejezetnek minden nyelven ugyanaz a
 * szama. Ezert a sorrendet EGY nyelv - a forrasnyelv - dontheti el. Ha
 * nyelvenkent kulon futtatnank az ujraszamozast, a masodik hivas felulirna
 * az elsot (a nyelvek fejezetkeszlete elterhet), es osszekeverednenek a
 * szamok.
 */
function renumber_module_source(PDO $db, int $moduleId): int
{
    $st = $db->prepare('SELECT chapter_no FROM help_module WHERE id = ?');
    $st->execute([$moduleId]);
    $no = trim((string)$st->fetchColumn());
    if ($no === '') { return 0; }

    $src = admin_source_lang();
    $q = $db->prepare('SELECT id FROM help_module WHERE chapter_no = ? AND lang = ?');
    $q->execute([$no, $src]);
    $srcModule = (int)$q->fetchColumn();
    if ($srcModule === 0) { return 0; }

    return renumber_module($db, $srcModule, $src);
}

function renumber_module(PDO $db, int $moduleId, string $lang): int
{
    $st = $db->prepare('SELECT chapter_no FROM help_module WHERE id = ?');
    $st->execute([$moduleId]);
    $moduleNo = trim((string)$st->fetchColumn());
    if ($moduleNo === '') { return 0; }

    $st = $db->prepare('SELECT id, chapter_no FROM help_article
                         WHERE module_id = ? AND lang = ? ORDER BY sort_order, id');
    $st->execute([$moduleId, $lang]);
    $rows = $st->fetchAll();
    if (!$rows) { return 0; }

    $counters = [0, 0, 0, 0];      // a 2..5. szint szamlaloi
    $plan = [];                    // regi szam => uj szam

    foreach ($rows as $r) {
        $old = trim((string)$r['chapter_no']);
        // Hany szinttel van a fofejezet alatt? A bevezeto (a fofejezet sajat
        // szama) 1, az "5.2" 2, az "5.3.1" 3.
        $depth = $old === '' ? 2 : count(explode('.', $old));
        $depth = max(1, min(5, $depth));

        if ($depth === 1) { $new = $moduleNo; }      // a bevezeto szama a fofejezete
        else {
            $i = $depth - 2;                          // 0 = elso alszint
            $counters[$i]++;
            for ($j = $i + 1; $j < 4; $j++) { $counters[$j] = 0; }
            $new = $moduleNo;
            for ($j = 0; $j <= $i; $j++) { $new .= '.' . $counters[$j]; }
        }
        if ($new !== $old && $old !== '') {
            // A terv AZONOSITO szerint keszul, nem fejezetszam szerint. A
            // szam ugyanis nem mindig egyedi: a Kukabol visszaallitott
            // fejezet a REGI szamaval jon vissza, ami idokozben mar masra
            // kerulhetett. Szam szerint tervezve mindket sort atirnank
            // ugyanarra, es tartos duplikatum keletkezne.
            $plan[] = ['id' => (int)$r['id'], 'old' => $old, 'new' => $new];
        }
    }
    if (!$plan) { return 0; }

    // KET MENETBEN. Egy menetben az uj szam raallhatna egy olyan sorra, ami
    // meg a regi szamat viseli (5.3 -> 5.2, mikozben az 5.2 meg letezik), es
    // onnantol ket fejezet vinne ugyanazt a szamot. Ezert eloszor mindegyik
    // egy egyedi ideiglenes jelolest kap (~1, ~2, ...), es csak utana kapja
    // meg a vegleges szamat.
    $changed = 0;
    $egy     = $db->prepare('UPDATE help_article SET chapter_no = ? WHERE id = ?');
    // a tobbi nyelv a REGI szam alapjan koveti (a nyelvi parokat az koti ossze)
    $tarsak  = $db->prepare('UPDATE help_article SET chapter_no = ?
                              WHERE chapter_no = ? AND lang <> ?');
    $vegleges = $db->prepare('UPDATE help_article SET chapter_no = ? WHERE chapter_no = ?');

    // Hanyszor fordul elo egy regi szam EBBEN a nyelvben? Ha ketszer (ilyen
    // allapot all elo kozvetlenul a Kukabol valo visszaallitas utan), akkor
    // nem lehet eldonteni, melyikhez tartoznak a masik nyelvu parok - ilyenkor
    // a tarsakhoz NEM nyulunk, csak ezt a nyelvet tesszuk rendbe.
    $elofordul = [];
    foreach ($rows as $r) {
        $k = trim((string)$r['chapter_no']);
        $elofordul[$k] = ($elofordul[$k] ?? 0) + 1;
    }

    $tmpMap = [];
    foreach ($plan as $i => $lepes) {
        $tmp = '~' . ($i + 1);
        $tmpMap[$tmp] = $lepes['new'];
        if (($elofordul[$lepes['old']] ?? 0) === 1) {
            $tarsak->execute([$tmp, $lepes['old'], $lang]);   // elobb a tarsak...
        }
        $egy->execute([$tmp, $lepes['id']]);                  // ...aztan a sajat sor
    }
    foreach ($tmpMap as $tmp => $new) {
        $vegleges->execute([$new, $tmp]);
        $changed += $vegleges->rowCount();
    }
    return $changed;
}

/**
 * A fofejezet neve = a LEIRAS fejezetenek cime.
 *
 * A fejezetlistaban (es a nyilvanos menuben) a fofejezet cimere kattintva a
 * leirasa nyilik meg - ezert ott egyetlen sor all, egyetlen nevvel. Ha a
 * szerkesztoben atirjak a leiras cimet, a fofejezet neve is kovesse, hogy
 * ne allhasson ket kulonbozo nev ugyanarra a dologra.
 *
 * Csak az adott NYELV fofejezetet erinti: a nevek nyelvenkent kulon allnak.
 * Ha a fejezet nem a leiras (a szama nem egyezik), nem csinal semmit.
 */
function module_title_sync(PDO $db, int $articleId, string $title): bool
{
    if ($title === '') { return false; }
    $st = $db->prepare('SELECT a.module_id, a.chapter_no, a.lang, m.chapter_no AS module_no
                          FROM help_article a JOIN help_module m ON m.id = a.module_id
                         WHERE a.id = ?');
    $st->execute([$articleId]);
    $r = $st->fetch();
    if (!$r || trim((string)$r['chapter_no']) !== trim((string)$r['module_no'])) { return false; }

    $db->prepare('UPDATE help_module SET title = ? WHERE id = ?')
       ->execute([$title, (int)$r['module_id']]);
    return true;
}

/**
 * A FOFEJEZET atszamozasa a leirasa fejezetszamabol.
 *
 * A fofejezet szamat ott lehet atirni, ahol a leirasat szerkesztik: a leiras
 * szama ES a fofejezet szama ugyanaz. Ha ez megvaltozik, kovetnie kell:
 *   - a fofejezetnek minden nyelven (a nyelvi valtozatokat a szam koti ossze)
 *   - az alatta levo osszes fejezetnek (5.2 -> 6.2, 5.3.1 -> 6.3.1)
 *
 * Ha a fejezet nem a leiras, vagy a szam nem valtozott, nem csinal semmit.
 * A slugokhoz nem nyul: azok a nyilvanos URL-ek.
 *
 * @return int ahany sort atirt (fofejezetek + fejezetek, minden nyelven)
 */
function module_number_cascade(PDO $db, int $articleId, string $newNo): int
{
    $newNo = trim($newNo);
    if ($newNo === '') { return 0; }

    $st = $db->prepare('SELECT a.chapter_no, m.chapter_no AS module_no
                          FROM help_article a JOIN help_module m ON m.id = a.module_id
                         WHERE a.id = ?');
    $st->execute([$articleId]);
    $r = $st->fetch();
    if (!$r) { return 0; }

    $oldNo = trim((string)$r['module_no']);
    // csak a LEIRAS fejezetszama vezerli a fofejezetet
    if (trim((string)$r['chapter_no']) !== $oldNo || $newNo === $oldNo) { return 0; }

    // ne irjunk ra egy letezo fofejezetre
    $busy = $db->prepare('SELECT COUNT(*) FROM help_module WHERE chapter_no = ?');
    $busy->execute([$newNo]);
    if ((int)$busy->fetchColumn() > 0) { return 0; }

    $ids = $db->prepare('SELECT id FROM help_module WHERE chapter_no = ?');
    $ids->execute([$oldNo]);
    $moduleIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
    if (!$moduleIds) { return 0; }
    $in = implode(',', $moduleIds);

    $n = 0;
    $u = $db->prepare('UPDATE help_module SET chapter_no = ? WHERE chapter_no = ?');
    $u->execute([$newNo, $oldNo]);
    $n += $u->rowCount();

    // a fejezetek szama a fofejezet szamabol epul: az elotagot csereljuk
    $a = $db->prepare("UPDATE help_article
                          SET chapter_no = CONCAT(?, SUBSTRING(chapter_no, CHAR_LENGTH(?) + 1))
                        WHERE module_id IN ($in)
                          AND (chapter_no = ? OR chapter_no LIKE CONCAT(?, '.%'))");
    $a->execute([$newNo, $oldNo, $oldNo, $oldNo]);
    $n += $a->rowCount();

    return $n;
}

function admin_setting(PDO $db, string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($db->query('SELECT `key`, value FROM help_setting')->fetchAll() as $r) {
            $cache[$r['key']] = (string)$r['value'];
        }
    }
    return $cache[$key] ?? $default;
}
