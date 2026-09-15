<?php
/**
 * index.php - a help.infinityhu.eu olvasoi felulete.
 *
 * Nincs Yii2, nincs Composer, nincs framework - csak PHP + PDO.
 *
 * Utvonalak:
 *   /                           -> nyelv-eszleles, atiranyitas /hu|/en|/de ala
 *   /hu/                        -> kezdolap (modul-csempek)
 *   /hu/5-4-kintlevoseg-kezeles -> fejezet
 *   /hu/embed/5-4-...           -> beagyazhato (iframe) valtozat, csupasz
 *   /search?lang=hu&q=...       -> JSON kereses (ekezet-fuggetlen)
 *   /media/img_xxx.png          -> kepek (a webszerver szolgalja ki, nem ez a fajl)
 *
 * Az admin felulet kulon fajlban van: /admin.php
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');   // eles kornyezetben ne irjunk ki PHP hibat a valaszba

require __DIR__ . '/lib/util.php';

$cfg = require __DIR__ . '/config.php';
try {
    $pdo = help_db($cfg);
} catch (PDOException $e) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Súgó</title>'
       . '<div style="font:15px/1.6 system-ui;max-width:36rem;margin:16vh auto;padding:0 1rem">'
       . '<h1 style="font-size:1.3rem">A súgó átmenetileg nem érhető el</h1>'
       . '<p>Az adatbázis-kapcsolat most nem él. Próbáld újra pár perc múlva.</p></div>';
    exit;
}

$LANGS = ['hu', 'en', 'de'];
$UI = [
    'hu' => [
        'title' => 'Infinity Súgó', 'search' => 'Keresés a súgóban…', 'onpage' => 'Ezen az oldalon',
        'action' => 'Műveletek', 'copy' => 'Hivatkozás másolása', 'print' => 'Nyomtatás',
        'updated' => 'Frissítve', 'full' => 'Teljes oldal', 'noresults' => 'Nincs találat.',
        'home' => 'Kezdőlap', 'chapters' => 'fejezet', 'filter' => 'Fejezet szűrése…',
        'hero' => 'Minden, amit az Infinity használatáról tudni érdemes — modulonként rendszerezve, kereshetően.',
        'prev' => 'Előző', 'next' => 'Következő', 'settings' => 'Megjelenítés',
        'fontsize' => 'Betűméret', 'theme' => 'Téma', 'light' => 'Világos', 'dark' => 'Sötét', 'auto' => 'Rendszer',
        'reset' => 'Alap', 'keys' => 'Billentyűparancsok', 'admin' => 'Adminisztráció',
        'clickzoom' => 'Kattints a nagyításhoz', 'nocontent' => 'Ehhez a fejezethez még nincs tartalom.',
        'notfound' => 'Nincs ilyen fejezet.', 'hits' => 'találat', 'image' => 'Képernyőkép',
        'linkcopied' => 'Hivatkozás a vágólapra másolva', 'close' => 'Bezárás',
        'zoomin' => 'Nagyítás', 'zoomout' => 'Kicsinyítés', 'fit' => 'Eredeti méret',
        'fallback' => 'Ez a fejezet még nem érhető el ezen a nyelven — a magyar változat látható.',
        'news' => 'Mi újság', 'newsslug' => 'mi-ujsag',
        'newslead' => 'A legutóbb megjelent és frissített fejezetek.',
        'nonews' => 'Az elmúlt időszakban nem volt változás.',
        'isnew' => 'új', 'isupd' => 'frissítve', 'allchapters' => 'Összes fejezet',
    ],
    'en' => [
        'title' => 'Infinity Help', 'search' => 'Search the help…', 'onpage' => 'On this page',
        'action' => 'Actions', 'copy' => 'Copy link', 'print' => 'Print',
        'updated' => 'Updated', 'full' => 'Full page', 'noresults' => 'No results.',
        'home' => 'Home', 'chapters' => 'chapters', 'filter' => 'Filter chapters…',
        'hero' => 'Everything worth knowing about using Infinity — organised by module, fully searchable.',
        'prev' => 'Previous', 'next' => 'Next', 'settings' => 'Display',
        'fontsize' => 'Font size', 'theme' => 'Theme', 'light' => 'Light', 'dark' => 'Dark', 'auto' => 'System',
        'reset' => 'Reset', 'keys' => 'Keyboard shortcuts', 'admin' => 'Administration',
        'clickzoom' => 'Click to enlarge', 'nocontent' => 'This chapter has no content yet.',
        'notfound' => 'No such chapter.', 'hits' => 'hits', 'image' => 'Screenshot',
        'linkcopied' => 'Link copied to clipboard', 'close' => 'Close',
        'zoomin' => 'Zoom in', 'zoomout' => 'Zoom out', 'fit' => 'Actual size',
        'fallback' => 'This chapter is not available in this language yet — showing the Hungarian version.',
        'news' => "What's new", 'newsslug' => 'whats-new',
        'newslead' => 'Recently added and updated chapters.',
        'nonews' => 'No changes in the recent period.',
        'isnew' => 'new', 'isupd' => 'updated', 'allchapters' => 'All chapters',
    ],
    'de' => [
        'title' => 'Infinity Hilfe', 'search' => 'Hilfe durchsuchen…', 'onpage' => 'Auf dieser Seite',
        'action' => 'Aktionen', 'copy' => 'Link kopieren', 'print' => 'Drucken',
        'updated' => 'Aktualisiert', 'full' => 'Volle Seite', 'noresults' => 'Keine Treffer.',
        'home' => 'Startseite', 'chapters' => 'Kapitel', 'filter' => 'Kapitel filtern…',
        'hero' => 'Alles Wissenswerte zur Nutzung von Infinity — nach Modulen geordnet und durchsuchbar.',
        'prev' => 'Zurück', 'next' => 'Weiter', 'settings' => 'Darstellung',
        'fontsize' => 'Schriftgröße', 'theme' => 'Design', 'light' => 'Hell', 'dark' => 'Dunkel', 'auto' => 'System',
        'reset' => 'Standard', 'keys' => 'Tastenkürzel', 'admin' => 'Administration',
        'clickzoom' => 'Zum Vergrößern klicken', 'nocontent' => 'Zu diesem Kapitel gibt es noch keinen Inhalt.',
        'notfound' => 'Kein solches Kapitel.', 'hits' => 'Treffer', 'image' => 'Bildschirmfoto',
        'linkcopied' => 'Link in die Zwischenablage kopiert', 'close' => 'Schließen',
        'zoomin' => 'Vergrößern', 'zoomout' => 'Verkleinern', 'fit' => 'Originalgröße',
        'fallback' => 'Dieses Kapitel ist in dieser Sprache noch nicht verfügbar — es wird die ungarische Fassung gezeigt.',
        'news' => 'Neuigkeiten', 'newsslug' => 'neuigkeiten',
        'newslead' => 'Zuletzt veröffentlichte und aktualisierte Kapitel.',
        'nonews' => 'Im letzten Zeitraum gab es keine Änderungen.',
        'isnew' => 'neu', 'isupd' => 'aktualisiert', 'allchapters' => 'Alle Kapitel',
    ],
];

// -------------------------------------------------------------- adatlekerdezesek
function getModules(PDO $pdo, string $lang): array
{
    $st = $pdo->prepare('SELECT id, chapter_no, title, slug FROM help_module WHERE lang = ? ORDER BY sort_order, id');
    $st->execute([$lang]);
    $modules = $st->fetchAll();
    if (!$modules) { return []; }

    $ids = array_column($modules, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st2 = $pdo->prepare("SELECT module_id, slug, chapter_no, title FROM help_article
                           WHERE module_id IN ($in) AND lang = ? AND is_published = 1
                        ORDER BY sort_order, id");
    $st2->execute([...$ids, $lang]);

    $byModule = [];
    foreach ($st2->fetchAll() as $a) { $byModule[(int)$a['module_id']][] = $a; }
    foreach ($modules as &$m) { $m['articles'] = $byModule[(int)$m['id']] ?? []; }
    unset($m);

    return $modules;
}

function getArticle(PDO $pdo, string $slug, string $lang): ?array
{
    $st = $pdo->prepare('SELECT a.*, m.title AS module_title, m.chapter_no AS module_no, m.slug AS module_slug
                           FROM help_article a JOIN help_module m ON m.id = a.module_id
                          WHERE a.slug = ? AND a.lang = ? AND a.is_published = 1 LIMIT 1');
    $st->execute([$slug, $lang]);
    $a = $st->fetch();
    if (!$a) { return null; }

    $st = $pdo->prepare('SELECT chapter_no, title, anchor, level FROM help_section
                          WHERE article_id = ? ORDER BY sort_order, id');
    $st->execute([$a['id']]);
    $a['sections'] = $st->fetchAll();
    return $a;
}

function searchArticles(PDO $pdo, string $lang, string $q): array
{
    $s = help_search_sql($q);
    $sql = "SELECT a.slug, a.chapter_no, a.title, a.plain_text, m.title AS module
              FROM help_article a JOIN help_module m ON m.id = a.module_id
             WHERE a.lang = :lang AND a.is_published = 1
               AND {$s['where']}
          ORDER BY {$s['order']}
             LIMIT 20";
    $st = $pdo->prepare($sql);
    $st->execute(['lang' => $lang] + $s['params']);

    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        // a kivonatot itt allitjuk elo (a MariaDB-ben nincs ts_headline)
        $r['snippet'] = help_snippet((string)$r['plain_text'], $q);
        unset($r['plain_text']);
    }
    return $rows;
}

/**
 * Az ujdonsagok (frissen kozzetett vagy modositott fejezetek) az adott nyelven.
 * A help_publish() allitja be a highlight_until datumot; ami lejart, az kiesik.
 */
function getWhatsNew(PDO $pdo, string $lang): array
{
    try {
        $st = $pdo->prepare('SELECT * FROM help_whatsnew WHERE lang = ?
                          ORDER BY updated_at DESC, chapter_no LIMIT 50');
        $st->execute([$lang]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];   // ha a 006 migracio meg nem futott le, a sugo ettol meg mukodjon
    }
}

/** Az adott cikk elozo/kovetkezo szomszedja a teljes, modulokon atnyulo sorrendben. */
function neighbours(array $modules, string $slug): array
{
    $flat = [];
    foreach ($modules as $m) {
        foreach ($m['articles'] as $a) { $flat[] = $a; }
    }
    foreach ($flat as $i => $a) {
        if ($a['slug'] === $slug) {
            return [$flat[$i - 1] ?? null, $flat[$i + 1] ?? null];
        }
    }
    return [null, null];
}

// -------------------------------------------------------------- utvonal-feldolgozas
$uri   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$parts = array_values(array_filter(explode('/', rawurldecode($uri)), static fn($p) => $p !== ''));

if (($parts[0] ?? '') === 'search') {
    $lang = in_array($_GET['lang'] ?? '', $LANGS, true) ? (string)$_GET['lang'] : 'hu';
    $q = trim((string)($_GET['q'] ?? ''));
    help_json(['items' => mb_strlen($q) >= 2 ? searchArticles($pdo, $lang, $q) : []]);
}

if (!$parts) {
    $accept = strtolower(substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'hu'), 0, 2));
    $lang = in_array($accept, $LANGS, true) ? $accept : 'hu';
    header('Location: /' . $lang . '/', true, 302);
    exit;
}

$lang = strtolower($parts[0]);
if (!in_array($lang, $LANGS, true)) {
    http_response_code(404);
    echo 'Ismeretlen nyelv.';
    exit;
}

$u       = $UI[$lang];
$isEmbed = ($parts[1] ?? '') === 'embed';
$slug    = $isEmbed ? ($parts[2] ?? null) : ($parts[1] ?? null);
$modules = getModules($pdo, $lang);

$fresh    = getWhatsNew($pdo, $lang);
$freshSet = [];
foreach ($fresh as $f) { $freshSet[$f['slug']] = $f; }

$NEWS_SLUGS = ['mi-ujsag', 'whats-new', 'neuigkeiten'];
$isNews = $slug !== null && in_array($slug, $NEWS_SLUGS, true);

$article  = null;
$fallback = false;
if ($slug !== null && $slug !== '' && !$isNews) {
    $article = getArticle($pdo, $slug, $lang);
    if ($article === null && $lang !== 'hu') {
        // ha az adott nyelven meg nincs kesz a forditas, mutassuk a magyart
        $article = getArticle($pdo, $slug, 'hu');
        $fallback = $article !== null;
    }
}

$siteTitle = $u['title'];

/** Rovid nyelvi szotar a JS-nek. */
function jsI18n(array $u): string
{
    return json_encode([
        'noResults'   => $u['noresults'],
        'fontSize'    => $u['fontsize'],
        'themeLight'  => $u['light'],
        'themeDark'   => $u['dark'],
        'clickToZoom' => $u['clickzoom'],
        'linkCopied'  => $u['linkcopied'],
        'copyLink'    => $u['copy'],
        'close'       => $u['close'],
        'zoomIn'      => $u['zoomin'],
        'zoomOut'     => $u['zoomout'],
        'fit'         => $u['fit'],
        'prev'        => $u['prev'],
        'next'        => $u['next'],
        'hits'        => $u['hits'],
        'image'       => $u['image'],
    ], JSON_UNESCAPED_UNICODE) ?: '{}';
}

/** A témát és a betűméretet még az első festés előtt beállítjuk (ne villanjon). */
const BOOT_JS = <<<'JS'
(function(){try{
  var t=localStorage.getItem('help.theme');
  if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}
  var f=parseFloat(localStorage.getItem('help.fs'));
  if(f>=0.85&&f<=1.6){document.documentElement.style.setProperty('--fs',String(f));}
}catch(e){}})();
JS;

// ============================================================ BEÁGYAZOTT NÉZET
if ($isEmbed) {
    if (!$article) {
        http_response_code(404);
        echo h($u['notfound']);
        exit;
    }
    ?>
<!doctype html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($article['title']) ?></title>
<script><?= BOOT_JS ?></script>
<link rel="stylesheet" href="/assets/app.css">
<style>
  body { background: var(--surface); }
  .embed-bar {
    position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 12px;
    padding: 10px 18px; background: var(--surface-2); border-bottom: 1px solid var(--line);
  }
  .embed-bar b { flex: 1; font-size: calc(13.5px * var(--fs)); color: var(--ink); font-weight: 600; }
  .embed-bar a, .embed-bar button {
    font: inherit; font-size: calc(12.5px * var(--fs)); color: var(--ink-2);
    background: none; border: 0; cursor: pointer; padding: 4px 6px; border-radius: var(--r-sm);
  }
  .embed-bar a:hover, .embed-bar button:hover { background: var(--surface-3); color: var(--ink); text-decoration: none; }
  .embed-pad { padding: 20px 22px 36px; }
</style>
</head>
<body>
<div class="embed-bar">
  <b><?= h(trim($article['chapter_no'] . ' ' . $article['title'])) ?></b>
  <button id="fs-dec" title="<?= h($u['fontsize']) ?> −">A−</button>
  <button id="fs-inc" title="<?= h($u['fontsize']) ?> +">A+</button>
  <button id="theme-toggle" title="<?= h($u['theme']) ?>"></button>
  <a href="/<?= h($lang) ?>/<?= h($article['slug']) ?>" target="_blank" rel="noopener"><?= h($u['full']) ?> ↗</a>
</div>
<div class="embed-pad body"><?= fix_img_url((string)$article['body_html']) ?></div>
<script>window.HELP_LANG=<?= json_encode($lang) ?>;window.HELP_I18N=<?= jsI18n($u) ?>;</script>
<script src="/assets/app.js" defer></script>
</body>
</html>
    <?php
    exit;
}

// ============================================================ TELJES OLDAL
if ($slug !== null && $slug !== '' && $article === null && !$isNews) {
    http_response_code(404);
}
[$prev, $next] = $article ? neighbours($modules, $article['slug']) : [null, null];
$totalArticles = array_sum(array_map(static fn($m) => count($m['articles']), $modules));
?>
<!doctype html>
<html lang="<?= h($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $article ? h($article['title'] . ' — ' . $siteTitle) : h($siteTitle) ?></title>
<meta name="description" content="<?= h($article ? mb_substr((string)$article['plain_text'], 0, 180) : $u['hero']) ?>">
<meta name="color-scheme" content="light dark">
<script><?= BOOT_JS ?></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css">
</head>
<body>

<div class="progress" id="progress"></div>

<header class="shell">
  <button class="shell__burger" id="burger" aria-label="Menü" aria-expanded="false">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
  </button>

  <a class="shell__brand" href="/<?= h($lang) ?>/">
    <span class="shell__logo"><?= help_logo(28) ?></span>
    <span class="shell__title"><?= h($siteTitle) ?></span>
  </a>
  <span class="shell__sep" aria-hidden="true"></span>

  <div class="gsearch">
    <span class="gsearch__icon">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
    </span>
    <input class="gsearch__in" id="gsearch" type="search" autocomplete="off" spellcheck="false"
           placeholder="<?= h($u['search']) ?>" aria-label="<?= h($u['search']) ?>">
    <span class="gsearch__kbd">Ctrl K</span>
    <div class="gsearch__res" id="gsearch-res" role="listbox"></div>
  </div>

  <span class="shell__spacer"></span>

  <nav class="lang" aria-label="<?= h($u['theme']) ?>">
    <?php foreach ($LANGS as $L): ?>
      <a class="<?= $L === $lang ? 'on' : '' ?>" hreflang="<?= h($L) ?>"
         href="/<?= h($L) ?>/<?= $article ? h($article['slug']) : '' ?>"><?= strtoupper($L) ?></a>
    <?php endforeach; ?>
  </nav>

  <a class="sbtn<?= $isNews ? ' on' : '' ?>" href="/<?= h($lang) ?>/<?= h($u['newsslug']) ?>" title="<?= h($u['news']) ?>">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h10v12H4z"/><path d="M14 9h4a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-1"/><path d="M7 9h4M7 12h4M7 15h3"/></svg>
    <?php if ($fresh): ?><span class="count"><?= count($fresh) > 99 ? '99+' : count($fresh) ?></span><?php endif; ?>
  </a>

  <button class="sbtn" id="theme-toggle" title="<?= h($u['theme']) ?>" aria-label="<?= h($u['theme']) ?>"></button>

  <button class="sbtn" id="fs-btn" title="<?= h($u['settings']) ?>" aria-expanded="false">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V9M4 5V4M12 20v-7M12 9V4M20 20v-4M20 12V4M1 9h6M9 13h6M17 16h6"/></svg>
  </button>

  <button class="sbtn" id="kbd-btn" title="<?= h($u['keys']) ?>" aria-expanded="false">?</button>

</header>

<!-- megjelenítési beállítások -->
<div class="fs-pop" id="fs-pop" role="dialog" aria-label="<?= h($u['settings']) ?>">
  <div class="pop__t">
    <span><?= h($u['fontsize']) ?></span><span class="fs-val" id="fs-val">100%</span>
  </div>
  <div class="fs-row">
    <button class="fs-btn fs-btn--a" id="fs-dec" aria-label="<?= h($u['fontsize']) ?> -">A</button>
    <input class="fs-range" id="fs-range" type="range" aria-label="<?= h($u['fontsize']) ?>">
    <button class="fs-btn fs-btn--b" id="fs-inc" aria-label="<?= h($u['fontsize']) ?> +">A</button>
  </div>
  <div class="seg" style="margin-top:10px"><button id="fs-reset"><?= h($u['reset']) ?> (100%)</button></div>

  <div class="pop__t" style="margin-top:16px"><?= h($u['theme']) ?></div>
  <div class="seg">
    <button data-theme-set="light" aria-pressed="false"><?= h($u['light']) ?></button>
    <button data-theme-set="dark"  aria-pressed="false"><?= h($u['dark']) ?></button>
    <button data-theme-set="auto"  aria-pressed="false"><?= h($u['auto']) ?></button>
  </div>
</div>

<!-- billentyűparancsok -->
<div class="pop" id="kbd-pop" role="dialog" aria-label="<?= h($u['keys']) ?>">
  <div class="pop__t"><?= h($u['keys']) ?></div>
  <div class="kbd-help">
    <kbd>Ctrl</kbd><span><?= h($u['search']) ?> (<kbd>/</kbd>)</span>
    <kbd>+ / −</kbd><span><?= h($u['fontsize']) ?></span>
    <kbd>0</kbd><span><?= h($u['reset']) ?></span>
    <kbd>D</kbd><span><?= h($u['light']) ?> / <?= h($u['dark']) ?></span>
    <kbd>← →</kbd><span><?= h($u['prev']) ?> / <?= h($u['next']) ?> (<?= h($u['image']) ?>)</span>
    <kbd>Esc</kbd><span><?= h($u['close']) ?></span>
  </div>
</div>

<div class="scrim" id="scrim"></div>

<div class="layout">

  <!-- bal navigáció -->
  <nav class="nav" id="nav" aria-label="<?= h($u['chapters']) ?>">
    <div class="nav__head">
      <input class="nav__filter" id="nav-filter" type="search" placeholder="<?= h($u['filter']) ?>"
             autocomplete="off" aria-label="<?= h($u['filter']) ?>">
    </div>
    <div class="nav__count" id="nav-count"></div>
    <div class="nav__body">
      <?php if (!$modules): ?>
        <div class="nav__empty"><?= h($u['nocontent']) ?></div>
      <?php endif; ?>
      <?php foreach ($modules as $m): ?>
        <div class="nav__mod" data-mod="<?= h((string)$m['id']) ?>">
          <button class="nav__mt" aria-expanded="true">
            <i><?= h($m['chapter_no']) ?></i><span><?= h($m['title']) ?></span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
          </button>
          <div class="nav__list">
            <?php foreach ($m['articles'] as $a): ?>
              <a class="nav__a<?= $article && $a['slug'] === $article['slug'] ? ' on' : '' ?>"
                 href="/<?= h($lang) ?>/<?= h($a['slug']) ?>"><em><?= h($a['chapter_no']) ?></em><?= h($a['title']) ?><?php
                 if (isset($freshSet[$a['slug']])): ?><span class="fresh" title="<?=
                     h($freshSet[$a['slug']]['change_flag'] === 'new' ? $u['isnew'] : $u['isupd']) ?>"></span><?php endif; ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </nav>

  <!-- tartalom -->
  <main>
    <?php if ($isNews): ?>
      <!-- ---------------- Mi újság ---------------- -->
      <section class="hero" style="padding:24px 30px">
        <h1><?= h($u['news']) ?></h1>
        <p><?= h($u['newslead']) ?></p>
      </section>
      <?php if (!$fresh): ?>
        <div class="card"><p class="muted"><?= h($u['nonews']) ?></p>
          <p><a href="/<?= h($lang) ?>/"><?= h($u['allchapters']) ?> →</a></p></div>
      <?php else: ?>
        <?php foreach ($fresh as $f): ?>
          <a class="news-item" href="/<?= h($lang) ?>/<?= h($f['slug']) ?>">
            <div class="news-item__h">
              <span class="chip chip--fresh"><?= h($f['change_flag'] === 'new' ? $u['isnew'] : $u['isupd']) ?></span>
              <span class="news-item__t"><?= h(trim($f['chapter_no'] . ' ' . $f['title'])) ?></span>
              <span style="flex:1"></span>
              <span class="news-date"><?= h((string)$f['updated_at']) ?></span>
            </div>
            <div class="news-item__m"><?= h(trim(($f['module_no'] ?? '') . ' ' . ($f['module_title'] ?? ''))) ?></div>
            <?php if (!empty($f['summary'])): ?>
              <div class="news-item__s"><?= h((string)$f['summary']) ?></div>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>

    <?php elseif ($article === null && ($slug === null || $slug === '')): ?>
      <!-- ---------------- kezdőlap ---------------- -->
      <section class="hero">
        <h1><?= h($siteTitle) ?></h1>
        <p><?= h($u['hero']) ?></p>
      </section>
      <?php if ($fresh): ?>
        <a class="news-item" href="/<?= h($lang) ?>/<?= h($u['newsslug']) ?>" style="margin-bottom:18px">
          <div class="news-item__h">
            <span class="chip chip--fresh"><?= count($fresh) ?></span>
            <span class="news-item__t"><?= h($u['news']) ?></span>
            <span style="flex:1"></span>
            <span class="news-date"><?= h((string)$fresh[0]['updated_at']) ?></span>
          </div>
          <div class="news-item__s"><?= h($u['newslead']) ?></div>
        </a>
      <?php endif; ?>

      <div class="tiles">
        <?php foreach ($modules as $m): if (!$m['articles']) { continue; } ?>
          <a class="tile" href="/<?= h($lang) ?>/<?= h($m['articles'][0]['slug']) ?>">
            <div class="tile__n"><?= h($m['chapter_no']) ?></div>
            <div class="tile__t"><?= h($m['title']) ?></div>
            <div class="tile__c"><?= count($m['articles']) ?> <?= h($u['chapters']) ?></div>
          </a>
        <?php endforeach; ?>
      </div>

    <?php elseif ($article === null): ?>
      <!-- ---------------- 404 ---------------- -->
      <article class="card">
        <h1 class="art-title">404</h1>
        <p><?= h($u['notfound']) ?> <a href="/<?= h($lang) ?>/"><?= h($u['home']) ?></a></p>
      </article>

    <?php else: ?>
      <!-- ---------------- fejezet ---------------- -->
      <article class="card">
        <nav class="bc" aria-label="breadcrumb">
          <a href="/<?= h($lang) ?>/"><?= h($u['home']) ?></a>
          <span class="sep">›</span>
          <span><?= h(trim(($article['module_no'] ?? '') . ' ' . ($article['module_title'] ?? ''))) ?></span>
          <span class="sep">›</span>
          <span><?= h($article['chapter_no']) ?></span>
        </nav>

        <h1 class="art-title"><?= h($article['title']) ?></h1>

        <div class="meta">
          <span class="chip"><?= h($u['updated']) ?> <?= h((string)$article['updated_at']) ?></span>
          <span class="chip"><?= h((string)$article['doc_version']) ?></span>
          <?php if (isset($freshSet[$article['slug']])): ?>
            <span class="chip chip--fresh"><?= h($article['change_flag'] === 'new' ? $u['isnew'] : $u['isupd']) ?></span>
          <?php elseif ($article['change_flag'] === 'new'): ?><span class="chip chip--new"><?= h($u['isnew']) ?></span>
          <?php elseif ($article['change_flag'] === 'mod'): ?><span class="chip chip--mod"><?= h($u['isupd']) ?></span><?php endif; ?>
          <?php if ((int)$article['img_count'] > 0): ?>
            <span class="chip"><?= (int)$article['img_count'] ?> <?= h($u['image']) ?></span>
          <?php endif; ?>
          <?php if ($fallback): ?><span class="chip chip--info"><?= h($u['fallback']) ?></span><?php endif; ?>
        </div>

        <div class="body">
          <?php
          $html = trim((string)$article['body_html']);
          echo $html !== '' ? fix_img_url($html) : '<p>' . h($u['nocontent']) . '</p>';
          ?>
        </div>

        <nav class="pager" aria-label="<?= h($u['prev']) ?> / <?= h($u['next']) ?>">
          <?php if ($prev): ?>
            <a href="/<?= h($lang) ?>/<?= h($prev['slug']) ?>">
              <span>← <?= h($u['prev']) ?></span><b><?= h($prev['chapter_no'] . ' ' . $prev['title']) ?></b>
            </a>
          <?php else: ?><span class="none"></span><?php endif; ?>
          <?php if ($next): ?>
            <a class="next" href="/<?= h($lang) ?>/<?= h($next['slug']) ?>">
              <span><?= h($u['next']) ?> →</span><b><?= h($next['chapter_no'] . ' ' . $next['title']) ?></b>
            </a>
          <?php else: ?><span class="none"></span><?php endif; ?>
        </nav>
      </article>
    <?php endif; ?>
  </main>

  <!-- jobb sáv -->
  <aside class="rail">
    <?php if ($article && $article['sections']): ?>
      <div class="rail__t"><?= h($u['onpage']) ?></div>
      <div class="rail__list">
        <?php foreach ($article['sections'] as $s): ?>
          <a class="rail__a" data-anchor="<?= h($s['anchor']) ?>" href="#<?= h($s['anchor']) ?>"><?= h(trim($s['chapter_no'] . ' ' . $s['title'])) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="rail__t"><?= h($u['action']) ?></div>
    <button class="rail__btn" id="act-copy" type="button">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg>
      <?= h($u['copy']) ?>
    </button>
    <button class="rail__btn" id="act-print" type="button">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6M6 18H4v-6h16v6h-2M8 14h8v7H8z"/></svg>
      <?= h($u['print']) ?>
    </button>
    <div class="rail__t" style="margin-top:20px"><?= h($u['chapters']) ?></div>
    <div class="nav__count" style="padding-left:11px"><?= (int)$totalArticles ?></div>
  </aside>
</div>

<script>
  window.HELP_LANG = <?= json_encode($lang) ?>;
  window.HELP_I18N = <?= jsI18n($u) ?>;
</script>
<script src="/assets/app.js" defer></script>
</body>
</html>
