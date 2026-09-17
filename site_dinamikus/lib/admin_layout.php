<?php
/**
 * admin_layout.php - az admin felulet kozos kerete es apro segedei.
 */
declare(strict_types=1);

const ADMIN_TABS = [
    'dashboard' => 'Áttekintés',
    'articles'  => 'Fejezetek',
    'modules'   => 'Modulok',
    'import'    => 'Word import',
    'translate' => 'Fordítás',
    'screens'   => 'Képernyők',
    'media'     => 'Képek, videók',
    'export'    => 'Export',
    'users'     => 'Felhasználók',
    'trash'     => 'Kuka',
    'settings'  => 'Beállítások',
];

/**
 * A sugo nyelvei. UJ NYELVET ITT kell felvenni - a forrasnyelv az elso elem
 * (magyar), a tobbi celnyelv. Minden felulet ebbol dolgozik: a Forditas ful
 * oszlopai, a gepi forditas celnyelvei, a nyelvi kartyak es az export.
 */
const ADMIN_LANGS = ['hu' => 'Magyar', 'en' => 'English', 'de' => 'Deutsch'];

/** A forrasnyelv kodja (az ADMIN_LANGS elso eleme). */
function admin_source_lang(): string
{
    return (string)array_key_first(ADMIN_LANGS);
}

/** A celnyelvek: minden nyelv a forrasnyelv nelkul. */
function admin_target_langs(): array
{
    $l = ADMIN_LANGS;
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

  <button class="sbtn" id="palette-open" title="Ugrás / keresés (Ctrl+K)">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
    <span class="sbtn__kbd">Ctrl K</span>
  </button>
  <button class="sbtn" id="keys-open" title="Billentyűparancsok (?)">?</button>

  <a class="sbtn" href="/hu/" target="_blank" rel="noopener" title="A súgó megnyitása új lapon">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14 21 3"/></svg>
  </a>
  <button class="sbtn" id="theme-toggle" title="Világos / sötét téma"></button>

  <span class="ashell__user">
    <span class="ashell__av"><?= h(mb_strtoupper(mb_substr($u['display_name'], 0, 1))) ?></span>
    <span><?= h($u['display_name']) ?> · <?= h($u['role']) ?></span>
  </span>
  <a class="sbtn" href="<?= h(admin_url(['p' => 'account'])) ?>" title="Saját fiók, jelszócsere">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>
  </a>
  <a class="sbtn" href="<?= h(admin_url(['a' => 'logout'])) ?>" title="Kilépés">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v2"/><path d="M19 12H9m10 0-3-3m3 3-3 3"/></svg>
  </a>
</header>

<nav class="tabs">
  <?php foreach (ADMIN_TABS as $key => $label): ?>
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
    <div class="modal__h">Billentyűparancsok</div>
    <div class="modal__b">
      <div class="kbdbox">
        <kbd>Ctrl</kbd><span>Ugrás / keresés — fejezetek és fülek egyben</span>
        <kbd>/</kbd><span>A bal oldali fejezetszűrő</span>
        <kbd>↑</kbd><span>Lépkedés a fejezetlistán</span>
        <kbd>Enter</kbd><span>A kijelölt elem megnyitása</span>
        <kbd>Ctrl</kbd><span>Vázlat mentése a szerkesztőben</span>
        <kbd>A</kbd><span>Fejezetek</span>
        <kbd>M</kbd><span>Modulok</span>
        <kbd>I</kbd><span>Word import</span>
        <kbd>T</kbd><span>Fordítás</span>
        <kbd>K</kbd><span>Képek, videók</span>
        <kbd>E</kbd><span>Export</span>
        <kbd>U</kbd><span>Felhasználók</span>
        <kbd>B</kbd><span>Beállítások</span>
        <kbd>D</kbd><span>Áttekintés</span>
        <kbd>Esc</kbd><span>Ablak bezárása</span>
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
    <div class="modal__h" id="confirm-title">Megerősítés</div>
    <div class="modal__b">
      <div class="msg msg--warn" style="margin:0" id="confirm-text"></div>
    </div>
    <div class="modal__f">
      <button class="btn btn--ghost" type="button" data-close>Mégsem</button>
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
    $mods = $db->prepare('SELECT id, chapter_no, title, slug, sort_order FROM help_module WHERE lang = ? ORDER BY sort_order, id');
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
