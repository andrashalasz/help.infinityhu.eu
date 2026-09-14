<?php
/**
 * index.php - a help.infinityhu.eu teljes alkalmazasa, EGY fajlban.
 *
 * Nincs Yii2, nincs Composer, nincs framework - csak PHP + PDO. Szandekosan
 * ilyen egyszeru: ez egy KULON, kis felulet a sajat kis adatbazisaval, nem
 * kell hozza tobb, mint egy PHP-t futtato webszerver.
 *
 * Utvonalak:
 *   /                          -> nyelv-eszleles, atiranyitas /hu|/en|/de ala
 *   /hu/                       -> kezdolap (modul-lista)
 *   /hu/5-4-kintlevoseg-kezeles -> fejezet
 *   /hu/embed/5-4-...          -> beagyazhato (iframe) valtozat, csupasz
 *   /search?lang=hu&q=...      -> JSON kereses (ekezet-fuggetlen)
 *   /media/img_xxx.png         -> kepek (kozvetlenul a diszkrol)
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');   // eles kornyezetben ne irjunk ki PHP hibat a valaszba

$cfg = require __DIR__ . '/config.php';
try {
    $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(503);
    echo 'A súgó adatbázisa jelenleg nem érhető el.';
    exit;
}

$LANGS = ['hu', 'en', 'de'];
$UI = [
    'hu' => ['title' => 'Súgó', 'search' => 'Keresés a súgóban...', 'onpage' => 'Ezen az oldalon',
             'action' => 'Művelet', 'copy' => 'Hivatkozás másolása', 'print' => 'Nyomtatás',
             'updated' => 'Frissítve', 'full' => 'Teljes oldal', 'noresults' => 'Nincs találat.'],
    'en' => ['title' => 'Help', 'search' => 'Search the help...', 'onpage' => 'On this page',
             'action' => 'Actions', 'copy' => 'Copy link', 'print' => 'Print',
             'updated' => 'Updated', 'full' => 'Full page', 'noresults' => 'No results.'],
    'de' => ['title' => 'Hilfe', 'search' => 'Hilfe durchsuchen...', 'onpage' => 'Auf dieser Seite',
             'action' => 'Aktionen', 'copy' => 'Link kopieren', 'print' => 'Drucken',
             'updated' => 'Aktualisiert', 'full' => 'Volle Seite', 'noresults' => 'Keine Treffer.'],
];

// -------------------------------------------------------------- adatlekerdezesek
function getModules(PDO $pdo, string $lang): array
{
    $st = $pdo->prepare("SELECT id, chapter_no, title, slug FROM help_module WHERE lang = ? ORDER BY sort_order");
    $st->execute([$lang]);
    $modules = $st->fetchAll();
    foreach ($modules as &$m) {
        $st2 = $pdo->prepare("SELECT slug, chapter_no, title FROM help_article
                               WHERE module_id = ? AND lang = ? AND is_published ORDER BY sort_order");
        $st2->execute([$m['id'], $lang]);
        $m['articles'] = $st2->fetchAll();
    }
    return $modules;
}

function getArticle(PDO $pdo, string $slug, string $lang): ?array
{
    $st = $pdo->prepare("SELECT a.*, m.title AS module_title, m.chapter_no AS module_no
                            FROM help_article a JOIN help_module m ON m.id = a.module_id
                           WHERE a.slug = ? AND a.lang = ? AND a.is_published LIMIT 1");
    $st->execute([$slug, $lang]);
    $a = $st->fetch();
    if (!$a) { return null; }
    $st = $pdo->prepare("SELECT chapter_no, title, anchor, level FROM help_section
                           WHERE article_id = ? ORDER BY sort_order");
    $st->execute([$a['id']]);
    $a['sections'] = $st->fetchAll();
    return $a;
}

function searchArticles(PDO $pdo, string $lang, string $q): array
{
    $st = $pdo->prepare("
        SELECT a.slug, a.chapter_no, a.title, m.title AS module,
               ts_headline('simple', a.plain_text, plainto_tsquery('simple', erp_norm(:q))) AS snippet
          FROM help_article a JOIN help_module m ON m.id = a.module_id
         WHERE a.lang = :lang AND a.is_published
           AND (a.search_vector @@ plainto_tsquery('simple', erp_norm(:q))
                OR erp_norm(a.title) LIKE '%' || erp_norm(:q) || '%')
         ORDER BY ts_rank(a.search_vector, plainto_tsquery('simple', erp_norm(:q))) DESC
         LIMIT 20");
    $st->execute(['q' => $q, 'lang' => $lang]);
    return $st->fetchAll();
}

// -------------------------------------------------------------- kis segedek
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fixImgUrl(string $html): string { return str_replace('src="media/', 'src="/media/', $html); }

// -------------------------------------------------------------- utvonal-feldolgozas
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$parts = array_values(array_filter(explode('/', $uri)));

if (isset($parts[0]) && $parts[0] === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    $lang = in_array($_GET['lang'] ?? '', $LANGS, true) ? $_GET['lang'] : 'hu';
    $q = trim($_GET['q'] ?? '');
    echo json_encode(['items' => strlen($q) >= 2 ? searchArticles($pdo, $lang, $q) : []], JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($parts)) {
    $accept = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'hu', 0, 2);
    $lang = in_array($accept, $LANGS, true) ? $accept : 'hu';
    header('Location: /' . $lang . '/');
    exit;
}

$lang = $parts[0];
if (!in_array($lang, $LANGS, true)) {
    http_response_code(404);
    echo 'Ismeretlen nyelv.';
    exit;
}

$isEmbed = isset($parts[1]) && $parts[1] === 'embed';
$slug = $isEmbed ? ($parts[2] ?? null) : ($parts[1] ?? null);
$u = $UI[$lang];
$modules = getModules($pdo, $lang);
if ($slug) {
    $article = getArticle($pdo, $slug, $lang);
} else {
    $firstSlug = $modules[0]['articles'][0]['slug'] ?? null;
    $article = $firstSlug ? getArticle($pdo, $firstSlug, $lang) : null;
}
if ($slug && $article === null) {
    // fallback: ha az adott nyelven meg nincs kesz a forditas, mutassuk a magyart
    $article = getArticle($pdo, $slug, 'hu');
}

$css = '<link rel="stylesheet" href="/assets/help.css"><link rel="stylesheet" href="/assets/help-reader-shell.css">';

if ($isEmbed) {
    // ---------------------------------------------------------- BEAGYAZOTT NEZET
    if (!$article) { http_response_code(404); echo 'Nincs ilyen fejezet.'; exit; }
    ?>
<!DOCTYPE html><html lang="<?= h($lang) ?>"><head><meta charset="utf-8">
<meta name="robots" content="noindex"><title><?= h($article['title']) ?></title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,-apple-system,sans-serif;font-size:14px;color:#333B4D}
.bar{position:sticky;top:0;display:flex;gap:10px;align-items:center;padding:10px 16px;background:#FAFAF9;
border-bottom:1px solid #E8E7E3;font-size:12px}
.bar b{flex:1;font-size:13px;color:#1E293B}.bar a{color:#6B7280;text-decoration:none}
.pad{padding:18px 20px 30px}img{max-width:100%;border:1px solid #E8E7E3;border-radius:5px}
</style></head><body>
<div class="bar"><b><?= h($article['chapter_no'] . ' ' . $article['title']) ?></b>
<a href="/<?= h($lang) ?>/<?= h($slug) ?>" target="_blank"><?= h($u['full']) ?> ↗</a></div>
<div class="pad"><?= fixImgUrl($article['body_html'] ?? $article['intro'] ?? '') ?></div>
</body></html>
    <?php
    exit;
}

// -------------------------------------------------------------------- TELJES OLDAL
?>
<!DOCTYPE html><html lang="<?= h($lang) ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $article ? h($article['title'] . ' — ' . $u['title']) : h($u['title']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?= $css ?>
</head><body>
<div class="help-hbar">
  <h1 class="help-h1"><?= h($u['title']) ?></h1>
  <div class="help-langsw">
    <?php foreach ($LANGS as $L): ?>
      <a class="help-langsw__btn<?= $L === $lang ? ' on' : '' ?>"
         href="/<?= $L ?>/<?= $article ? h($article['slug']) : '' ?>"><?= strtoupper($L) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="help-search">
    <input id="help-q" placeholder="<?= h($u['search']) ?>" autocomplete="off">
    <div class="help-res" id="help-res"></div>
  </div>
</div>
<div class="help-wrap">
  <nav class="help-tree">
    <?php foreach ($modules as $m): ?>
      <div class="help-tree__m">
        <div class="help-tree__mt"><i><?= h($m['chapter_no']) ?></i><?= h($m['title']) ?></div>
        <?php foreach ($m['articles'] as $a): ?>
          <a class="help-tree__a<?= $article && $a['slug'] === $article['slug'] ? ' on' : '' ?>"
             href="/<?= h($lang) ?>/<?= h($a['slug']) ?>"><?= h($a['chapter_no'] . ' ' . $a['title']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>
  <div class="help-content-col">
    <?php if (!$article): ?>
      <p><?= h('Nincs tartalom.') ?></p>
    <?php else: ?>
      <div class="help-bc"><?= h($m['module_no'] ?? $article['module_no']) ?> <?= h($article['module_title']) ?> › <?= h($article['chapter_no']) ?></div>
      <h1 class="help-art-title"><?= h($article['title']) ?></h1>
      <div class="help-meta"><?= h($u['updated']) ?> <?= h((string)$article['updated_at']) ?> · <?= h($article['doc_version']) ?></div>
      <div class="help-content"><?= fixImgUrl($article['body_html']) ?></div>
      <?php if ($article['sections']): ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <aside class="help-rail">
    <?php if ($article && $article['sections']): ?>
      <div class="help-rail__t"><?= h($u['onpage']) ?></div>
      <?php foreach ($article['sections'] as $s): if ((int)$s['level'] !== 3) { continue; } ?>
        <a href="#<?= h($s['anchor']) ?>"><?= h($s['chapter_no'] . ' ' . $s['title']) ?></a>
      <?php endforeach; ?>
    <?php endif; ?>
    <div class="help-rail__t" style="margin-top:22px"><?= h($u['action']) ?></div>
    <a href="javascript:void(0)" onclick="navigator.clipboard&&navigator.clipboard.writeText(location.href)"><?= h($u['copy']) ?></a>
    <a href="javascript:void(0)" onclick="window.print()"><?= h($u['print']) ?></a>
  </aside>
</div>
<script>
(function () {
  var box = document.getElementById('help-q'), res = document.getElementById('help-res'), t;
  box.addEventListener('input', function () {
    clearTimeout(t);
    var v = box.value.trim();
    if (v.length < 2) { res.className = 'help-res'; return; }
    t = setTimeout(function () {
      fetch('/search?lang=<?= h($lang) ?>&q=' + encodeURIComponent(v))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          var items = data.items || [];
          res.innerHTML = items.length ? items.map(function (r) {
            return '<a href="/<?= h($lang) ?>/' + r.slug + '"><b>' + r.chapter_no + ' ' + r.title + '</b><span>' + r.module + '</span></a>';
          }).join('') : '<a><b><?= h($u['noresults']) ?></b></a>';
          res.className = 'help-res on';
        });
    }, 150);
  });
  document.addEventListener('click', function (e) { if (!res.contains(e.target) && e.target !== box) { res.className = 'help-res'; } });
})();
</script>
</body></html>
