<?php
/**
 * admin_pages.php - az admin felulet lapjai (csak megjelenites).
 * Az irasi muveletek az admin_actions.php-ban vannak.
 */
declare(strict_types=1);

function csrf_input(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function lang_switch(string $page, string $lang, array $extra = []): string
{
    $out = '<div class="seg" style="max-width:280px">';
    foreach (admin_langs() as $code => $label) {
        $url = admin_url(array_merge(['p' => $page, 'lang' => $code], $extra));
        $out .= '<a class="btn btn--sm' . ($code === $lang ? ' btn--p' : '') . '" href="' . h($url) . '" style="flex:1">' . h($label) . '</a>';
    }
    return $out . '</div>';
}

// ============================================================ BEJELENTKEZÉS
function page_login(): void
{
    ?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h(t('Bejelentkezés')) ?> — Infinity Súgó admin</title>
<meta name="color-scheme" content="light dark">
<script>(function(){try{var t=localStorage.getItem('help.theme');
  if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h(asset_url('/assets/app.css')) ?>">
<link rel="stylesheet" href="<?= h(asset_url('/assets/admin.css')) ?>">
</head>
<body class="admin">
<div class="login-wrap">
  <form class="login" method="post" action="<?= h(admin_url()) ?>" autocomplete="on">
    <div class="login__logo"><?= help_logo(52) ?></div>
    <h1><?= h(t('Infinity Súgó')) ?></h1>
    <p class="sub"><?= h(t('Szerkesztői és adminisztrációs felület')) ?></p>

    <?= flash_render() ?>

    <?= csrf_input() ?>
    <input type="hidden" name="a" value="login">
    <div class="field">
      <label for="username"><?= h(t('Felhasználónév')) ?></label>
      <input class="inp" id="username" name="username" autocomplete="username" autofocus required>
    </div>
    <div class="field">
      <label for="password"><?= h(t('Jelszó')) ?></label>
      <input class="inp" id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <button class="btn btn--p" type="submit" style="width:100%"><?= h(t('Belépés')) ?></button>

    <div class="login__foot">
      <a href="/hu/"><?= h(t('Vissza a súgóhoz')) ?></a>
    </div>
  </form>
</div>
</body>
</html>
    <?php
}

// ============================================================ JELSZÓCSERE
function page_chpw(bool $forced): void
{
    admin_head(t('Jelszócsere'));
    ?>
<div class="page" style="max-width:560px">
  <h1 class="pt"><?= h(t('Jelszócsere')) ?></h1>
  <p class="lead">
    <?= t('jelszo.nem.kotelezo', ['url' => h(admin_url(['p' => 'settings'])) . '#jelszo']) ?>
  </p>
  <?= flash_render() ?>
 <div class="panel"><div class="panel__b">
    <form method="post" action="<?= h(admin_url()) ?>" autocomplete="off">
      <?= csrf_input() ?>
      <input type="hidden" name="a" value="chpw">
      <div class="field">
        <label for="current"><?= h(t('Jelenlegi jelszó')) ?></label>
        <input class="inp" id="current" name="current" type="password" autocomplete="current-password" required autofocus>
      </div>
      <div class="field">
        <label for="new"><?= h(t('Új jelszó')) ?></label>
        <input class="inp" id="new" name="new" type="password" autocomplete="new-password" required minlength="8">
        <div class="hint"><?= h(t('Legalább 8 karakter, betű és szám is legyen benne.')) ?></div>
      </div>
      <div class="field">
        <label for="new2"><?= h(t('Új jelszó még egyszer')) ?></label>
        <input class="inp" id="new2" name="new2" type="password" autocomplete="new-password" required minlength="8">
      </div>
      <div class="btnbar">
        <button class="btn btn--p" type="submit"><?= h(t('Jelszó mentése')) ?></button>
        <a class="btn btn--ghost" href="<?= h(admin_url()) ?>"><?= h(t('Mégsem')) ?></a>
      </div>
    </form>
  </div></div>
</div>
    <?php
    admin_foot();
}

// ============================================================ SAJÁT FIÓK
function page_account(PDO $db): void
{
    $u = auth_user();
    $st = $db->prepare('SELECT * FROM help_user WHERE id = ?');
    $st->execute([$u['id']]);
    $me = $st->fetch();

    admin_head(t('Saját fiók'), '');
    ?>
<div class="page" style="max-width:640px">
  <h1 class="pt"><?= h(t('Saját fiók')) ?></h1>
  <p class="lead"><?= h(t('A bejelentkezési adataid és a jelszavad.')) ?></p>
  <?= flash_render() ?>

  <div class="panel" style="margin-bottom:16px">
    <div class="panel__h"><h2><?= h(t('Adatok')) ?></h2></div>
    <div class="panel__b">
      <table class="tbl">
        <tr><td style="width:180px" class="muted"><?= h(t('Felhasználónév')) ?></td><td><b><?= h($me['username']) ?></b></td></tr>
        <tr><td class="muted"><?= h(t('Név')) ?></td><td><?= h($me['display_name']) ?></td></tr>
 <tr><td class="muted"><?= h(t('Szerepkör')) ?></td><td><span class="badge badge--info"><?= h($me['role']) ?></span></td></tr>
        <tr><td class="muted"><?= h(t('Utolsó belépés')) ?></td><td><?= h((string)($me['last_login_at'] ?? '—')) ?></td></tr>
      </table>
    </div>
  </div>

  <div class="panel">
    <div class="panel__h"><h2><?= h(t('Jelszó módosítása')) ?></h2></div>
    <div class="panel__b">
      <form method="post" action="<?= h(admin_url()) ?>" autocomplete="off">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="chpw">
        <div class="field"><label for="c"><?= h(t('Jelenlegi jelszó')) ?></label>
          <input class="inp" id="c" name="current" type="password" required></div>
        <div class="row">
          <div class="field"><label for="n1"><?= h(t('Új jelszó')) ?></label>
            <input class="inp" id="n1" name="new" type="password" required minlength="8"></div>
          <div class="field"><label for="n2"><?= h(t('Még egyszer')) ?></label>
            <input class="inp" id="n2" name="new2" type="password" required minlength="8"></div>
        </div>
        <button class="btn btn--p" type="submit"><?= h(t('Mentés')) ?></button>
      </form>
    </div>
  </div>
</div>
    <?php
    admin_foot();
}

// ============================================================ ÁTTEKINTÉS
function page_dashboard(PDO $db, array $cfg, array $counts): void
{
    $stats = $db->query("
        SELECT
          (SELECT count(*) FROM help_article)                                    AS articles,
          (SELECT count(*) FROM help_article WHERE draft_html IS NOT NULL)       AS drafts,
          (SELECT count(*) FROM help_article WHERE is_published = 0)             AS hidden,
          (SELECT count(*) FROM help_module)                                     AS modules,
          (SELECT count(*) FROM help_screen_map)                                 AS screens,
          (SELECT count(*) FROM help_media)                                      AS media
    ")->fetch();

    // A nyelvi valtozatokat a FEJEZETSZAM koti ossze (a slug nyelvenkent elter),
    // a slug csak tartalek a szam nelkuli fejezetekhez.
    $stale = (int)$db->query("
        SELECT COUNT(*) FROM help_article t
          JOIN help_article s
            ON s.lang = 'hu'
           AND ((s.chapter_no <> '' AND s.chapter_no = t.chapter_no) OR s.slug = t.slug)
         WHERE t.lang <> 'hu'
           AND NOT (t.translated_from_hash <=> s.content_hash)
    ")->fetchColumn();

    $missing = (int)$db->query("
        SELECT COUNT(*) FROM help_article s
         CROSS JOIN (SELECT 'en' AS lang UNION ALL SELECT 'de') AS l
         WHERE s.lang = 'hu'
           AND NOT EXISTS (
                 SELECT 1 FROM help_article t
                  WHERE t.lang = l.lang
                    AND ((s.chapter_no <> '' AND t.chapter_no = s.chapter_no) OR t.slug = s.slug))
    ")->fetchColumn();

    $drafts = $db->query("
        SELECT a.id, a.lang, a.chapter_no, a.title, a.draft_title, a.draft_at,
               a.translated_by, a.translated_at, u.display_name
          FROM help_article a LEFT JOIN help_user u ON u.id = a.draft_by
         WHERE a.draft_html IS NOT NULL
      ORDER BY a.draft_at IS NULL, a.draft_at DESC LIMIT 12")->fetchAll();

    $imports = $db->query("
        SELECT i.*, u.display_name FROM help_import i LEFT JOIN help_user u ON u.id = i.uploaded_by
      ORDER BY i.uploaded_at DESC LIMIT 5")->fetchAll();

    // A naplo lapozhato: 12 sor fer el kenyelmesen az attekinto lapon, a
    // tobbi lapozassal erheto el (nem vegtelen gorgetes).
    $auditPerPage = 12;
    $auditTotal = (int)$db->query('SELECT COUNT(*) FROM help_audit')->fetchColumn();
    $auditPages = max(1, (int)ceil($auditTotal / $auditPerPage));
    $auditPage  = max(1, min($auditPages, (int)($_GET['nlap'] ?? 1)));
    $auditFrom  = ($auditPage - 1) * $auditPerPage;
    $aq = $db->prepare('SELECT * FROM help_audit ORDER BY created_at DESC, id DESC LIMIT ' . $auditPerPage . ' OFFSET ?');
    $aq->bindValue(1, $auditFrom, PDO::PARAM_INT);
    $aq->execute();
    $audit = $aq->fetchAll();
    $release = $db->query("SELECT * FROM help_release WHERE status = 'open' LIMIT 1")->fetch();

    admin_head(t('Áttekintés'), 'dashboard', $counts);
    ?>
<div class="page">
  <h1 class="pt"><?= h(t('Áttekintés')) ?></h1>
  <p class="lead"><?= h(t('A súgó jelenlegi állapota. A bal oldali fülekről érhető el minden szerkesztési feladat.')) ?></p>
  <?= flash_render() ?>

  <!-- A csempék kattinthatók: mindegyik a hozzá tartozó, MÁR LESZŰRT listára visz. -->
  <div class="stats">
    <a class="stat" href="<?= h(admin_url(['p' => 'articles'])) ?>">
 <div class="stat__n"><?= (int)$stats['articles'] ?></div><div class="stat__l">fejezet (3 nyelven)</div></a>
    <a class="stat <?= (int)$stats['drafts'] ? 'stat--warn' : '' ?>" href="#drafts">
 <div class="stat__n"><?= (int)$stats['drafts'] ?></div><div class="stat__l"><?= h(t('közzétételre váró vázlat')) ?></div></a>
    <a class="stat <?= $stale ? 'stat--warn' : '' ?>" href="<?= h(admin_url(['p' => 'translate', 'to' => 'en', 'st' => 'stale'])) ?>">
 <div class="stat__n"><?= $stale ?></div><div class="stat__l"><?= h(t('elavult fordítás')) ?></div></a>
    <a class="stat <?= $missing ? 'stat--err' : '' ?>" href="<?= h(admin_url(['p' => 'translate', 'to' => 'en', 'st' => 'missing'])) ?>">
 <div class="stat__n"><?= $missing ?></div><div class="stat__l"><?= h(t('hiányzó fordítás')) ?></div></a>
    <a class="stat" href="<?= h(admin_url(['p' => 'modules'])) ?>">
 <div class="stat__n"><?= (int)$stats['modules'] ?></div><div class="stat__l">modul</div></a>
    <a class="stat" href="<?= h(admin_url(['p' => 'screens'])) ?>">
 <div class="stat__n"><?= (int)$stats['screens'] ?></div><div class="stat__l"><?= h(t('képernyő-hozzárendelés')) ?></div></a>
  </div>

  <div class="page--split" style="padding:0">
    <div>
      <div class="panel" id="drafts" style="margin-bottom:16px">
 <div class="panel__h"><h2><?= h(t('Közzétételre vár')) ?></h2><span class="sp"></span>
          <span class="badge"><?= count($drafts) ?></span></div>
        <div class="panel__b panel__b--flush">
          <?php if (!$drafts): ?>
            <div class="empty"><?= h(t('Nincs nyitott vázlat — minden közzé van téve.')) ?></div>
          <?php else: ?>
            <table class="tbl">
              <?php foreach ($drafts as $d): ?>
                <?php
                  // A VAZLAT cimet mutatjuk, nem a mar kozzetettet - kulonben
                  // egy frissen forditott fejezet ugy nez ki, mintha nem tortent
                  // volna vele semmi.
                  $dTitle = (string)($d['draft_title'] ?? '') !== '' ? (string)$d['draft_title'] : (string)$d['title'];
                  $isMt   = in_array((string)$d['translated_by'], ['deepl', 'libre', 'google'], true);
                ?>
                <tr>
                  <td class="nowrap muted mono" data-label="Fejezet"><?= h($d['lang']) ?> · <?= h($d['chapter_no']) ?></td>
                  <td data-label="<?= h(t('Cím')) ?>">
                    <a href="<?= h(admin_url(['p' => 'articles', 'lang' => $d['lang'], 'id' => $d['id']])) ?>"><?= h($dTitle) ?></a>
                    <?php if ($isMt): ?>
                      <span class="badge badge--info" title="<?= h(t('Magyarból készült gépi nyersfordítás — nézd át, mielőtt közzéteszed.')) ?>"><?= h(t('gépi fordítás')) ?></span>
                    <?php endif; ?>
                    <?php if ($dTitle !== (string)$d['title']): ?>
                      <div class="muted" style="font-size:11.5px">eddig: <?= h((string)$d['title']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="nowrap muted" data-label="Mentve"><?= h(substr((string)$d['draft_at'], 0, 16)) ?> · <?= h((string)$d['display_name']) ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel__h"><h2><?= h(t('Legutóbbi Word-importok')) ?></h2></div>
        <div class="panel__b panel__b--flush">
          <?php if (!$imports): ?>
            <div class="empty">Még nem volt import. <a href="<?= h(admin_url(['p' => 'import'])) ?>"><?= h(t('Word betöltése →')) ?></a></div>
          <?php else: ?>
            <table class="tbl">
              <?php foreach ($imports as $i): $s = json_decode((string)$i['stats'], true) ?: []; ?>
                <tr>
                  <td data-label="<?= h(t('Fájl')) ?>"><a href="<?= h(admin_url(['p' => 'import', 'import' => $i['id']])) ?>"><?= h($i['filename']) ?></a></td>
                  <td class="nowrap muted" data-label="Tartalom"><?= (int)($s['chapters'] ?? 0) ?> fejezet · <?= (int)($s['images'] ?? 0) ?> <?= h(t('kép')) ?></td>
 <td class="nowrap" data-label="<?= h(t('Állapot')) ?>"><span class="badge <?= $i['status'] === 'applied' ? 'badge--ok' : '' ?>"><?= h($i['status']) ?></span></td>
                  <td class="nowrap muted" data-label="Mikor"><?= h(substr((string)$i['uploaded_at'], 0, 16)) ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div>
      <div class="panel" style="margin-bottom:16px">
        <div class="panel__h"><h2><?= h(t('Nyitott kiadás')) ?></h2></div>
        <div class="panel__b">
          <?php if ($release): ?>
            <div class="stat__n" style="font-size:calc(20px * var(--fs))"><?= h($release['version']) ?></div>
            <div class="hint"><?= t('kiadas.gyujtes', ['url' => h(admin_url(['p' => 'settings']))]) ?></div>
          <?php else: ?>
            <div class="muted"><?= h(t('Nincs nyitott kiadás.')) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel__h"><h2><?= h(t('Napló')) ?></h2>
          <span class="sp"></span>
          <span class="muted"><?= h(t('naplo.osszesen', ['n' => $auditTotal])) ?></span></div>
        <div class="panel__b panel__b--flush">
          <table class="tbl">
            <?php foreach ($audit as $a): ?>
              <tr>
                <td class="nowrap muted" style="width:104px" data-label="<?= h(t('Mikor')) ?>"><?= h(substr((string)$a['created_at'], 5, 11)) ?></td>
                <td class="mono nowrap" data-label="<?= h(t('Művelet')) ?>"><?= h($a['action']) ?></td>
                <td class="muted" data-label="<?= h(t('Ki')) ?>"><?= h((string)$a['username']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$audit): ?>
              <tr><td class="empty"><?= h(t('A napló üres.')) ?></td></tr>
            <?php endif; ?>
          </table>
        </div>
        <?php if ($auditPages > 1): ?>
          <div class="pager">
            <a class="btn btn--sm btn--ghost<?= $auditPage <= 1 ? ' is-off' : '' ?>"
               href="<?= h(admin_url(['p' => 'dashboard', 'nlap' => max(1, $auditPage - 1)])) ?>">←</a>
            <span class="muted"><?= h(t('naplo.lap', ['lap' => $auditPage, 'ossz' => $auditPages])) ?></span>
            <a class="btn btn--sm btn--ghost<?= $auditPage >= $auditPages ? ' is-off' : '' ?>"
               href="<?= h(admin_url(['p' => 'dashboard', 'nlap' => min($auditPages, $auditPage + 1)])) ?>">→</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
    <?php
    admin_foot();
}

// ============================================================ FEJEZETEK
function page_articles(PDO $db, string $lang, int $id, array $counts, int $modId = 0,
                       int $diffRev = 0, array $cfg = []): void
{
    // a gepi fordito allapotahoz kell (a "Kozzeteves mindenhol" gombhoz)
    if (!$cfg) { $cfg = require __DIR__ . '/../config.php'; }
    // A fofejezet szerkesztesehez a jobb oldali oszlop a fejezet-szerkeszto
    // helyett a fofejezet adatlapjat mutatja.
    $module = null;
    $moduleFamily = [];
    if ($modId > 0) {
        $q = $db->prepare('SELECT * FROM help_module WHERE id = ?');
        $q->execute([$modId]);
        $module = $q->fetch() ?: null;
        if ($module) {
            $lang = (string)$module['lang'];
            $f = $db->prepare('SELECT * FROM help_module WHERE chapter_no = ? ORDER BY lang');
            $f->execute([$module['chapter_no']]);
            foreach ($f->fetchAll() as $r) { $moduleFamily[(string)$r['lang']] = $r; }
        }
    }
    $tree = admin_tree($db, $lang);

    $article = null;
    $revisions = [];
    $diffRow = null;
    if ($id > 0) {
        $st = $db->prepare('SELECT * FROM help_article WHERE id = ?');
        $st->execute([$id]);
        $article = $st->fetch() ?: null;
        if ($article) {
            $lang = (string)$article['lang'];
            $tree = admin_tree($db, $lang);
            $r = $db->prepare('SELECT r.rev_no, r.title, r.created_at, r.note, u.display_name
                                 FROM help_article_revision r LEFT JOIN help_user u ON u.id = r.created_by
                                WHERE r.article_id = ? ORDER BY r.rev_no DESC LIMIT 20');
            $r->execute([$id]);
            $revisions = $r->fetchAll();

            // osszehasonlitando verzio
            if ($diffRev > 0) {
                $d = $db->prepare('SELECT r.*, u.display_name FROM help_article_revision r
                                     LEFT JOIN help_user u ON u.id = r.created_by
                                    WHERE r.article_id = ? AND r.rev_no = ?');
                $d->execute([$id, $diffRev]);
                $diffRow = $d->fetch() ?: null;
            }
        }
    }

    $tree = tree_annotate($tree);

    // a kovetkezo szabad FOFEJEZET-szam (a modulok szamozasa alapjan)
    $nextModuleNo = 1;
    foreach ($tree as $m) {
        if (preg_match('/^(\d+)$/', trim((string)$m['chapter_no']), $mm)) {
            $nextModuleNo = max($nextModuleNo, (int)$mm[1] + 1);
        }
    }

    $mods = $db->prepare('SELECT id, chapter_no, title FROM help_module WHERE lang = ? ORDER BY sort_order, id');
    $mods->execute([$lang]);
    $modules = $mods->fetchAll();

    // Az "Új fejezet" ablakban a Fejezetszám mezo a VALODI kovetkezo szamot
    // ajanlja a kivalasztott modulhoz (korabban egy beegetett "5.5" allt ott,
    // fuggetlenul attol, melyik modult valasztottad).
    $nextNoByModule = [];
    foreach ($modules as &$m) {
        [$next] = article_next_slot($db, (int)$m['id'], $lang, '', '');
        $m['next_no'] = $next;
        $nextNoByModule[(int)$m['id']] = $next;
    }
    unset($m);

    admin_head('Fejezetek', 'articles', $counts);
    ?>
<div class="page page--split">

  <!-- bal: fejezetválasztó -->
  <div class="panel picker" id="picker">
    <div class="picker__f">
      <input class="inp" id="pick-filter" placeholder="<?= h(t('Szűrés…  (Ctrl+K a kereséshez)')) ?>" autocomplete="off">
    </div>
    <?php // A gombok kulon sorban, a szuro ALATT: egy sorban osszeszorulva
          // aprok voltak es nehez volt eltalalni oket. ?>
    <div class="picker__tools">
      <button class="btn btn--sm" type="button" id="pick-foldall" title="<?= h(t('eszkoz.osszecsuk.cim')) ?>">
        <i>⊟</i><span><?= h(t('eszkoz.osszecsuk')) ?></span></button>
      <button class="btn btn--sm" type="button" id="pick-sort" title="<?= h(t('eszkoz.sorrend.cim')) ?>">
        <i>↕</i><span><?= h(t('eszkoz.sorrend')) ?></span></button>
      <?php // Egy kattintas: letrejon minden nyelven, es rogton a leirasa
            // nyilik meg a szerkesztoben - ugy, mint a sima fejezetnel. ?>
      <form method="post" action="<?= h(admin_url()) ?>" style="display:contents">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="module.quick">
        <input type="hidden" name="lang" value="<?= h($lang) ?>">
        <button class="btn btn--p btn--sm" type="submit" title="<?= h(t('eszkoz.ujfo.cim')) ?>">
          <i>+</i><span><?= h(t('eszkoz.ujfo')) ?></span></button>
      </form>
    </div>
    <div style="padding:8px 10px 0"><?= lang_switch('articles', $lang) ?></div>

    <div class="picker__hint" id="pick-hint" hidden></div>

    <div class="picker__l" id="pick-list"
         data-renum-title="<?= h(t('fofejezet.sorrend.cim')) ?>"
         data-renum-ask="<?= h(t('fofejezet.sorrend.kerdes')) ?>"
         data-renum-yes="<?= h(t('fofejezet.sorrend.igen')) ?>"
         data-renum-no="<?= h(t('fofejezet.sorrend.nem')) ?>">
      <?php foreach ($tree as $m): ?>
        <?php
          // A fofejezet BEVEZETO fejezete az, aminek ugyanaz a szama, mint a
          // fofejezetnek (pl. "5" az "5 Penzugy" alatt). Ez a fofejezet
          // leirasa: mire valo az a menupont. Kulon sorban felsorolva
          // ugyanugy nezett ki, mint maga a fofejezet - ezert itt kiemeljuk
          // a listabol, es MAGA A FOFEJEZET CIME nyitja meg.
          $intro = null;
          foreach ($m['articles'] as $k => $ia) {
              if (trim((string)$ia['chapter_no']) === trim((string)$m['chapter_no'])) {
                  $intro = $ia;
                  unset($m['articles'][$k]);
                  break;
              }
          }
        ?>
        <div class="picker__mod" data-id="<?= (int)$m['id'] ?>">
        <div class="picker__m<?= $m['is_published'] ? '' : ' picker__m--off' ?>" data-module="<?= (int)$m['id'] ?>">
          <span class="picker__grip picker__mgrip" title="<?= h(t('Húzd a főfejezetek sorrendjének átrendezéséhez')) ?>" aria-hidden="true">⠿</span>
          <button type="button" class="picker__mfold" data-fold="<?= (int)$m['id'] ?>"
                  title="<?= h(t('Ki- és összecsukás')) ?>" aria-label="<?= h(t('Ki- és összecsukás')) ?>">
            <svg class="picker__caret" width="12" height="12" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
          </button>
          <?php if ($intro !== null): ?>
            <a class="picker__mt<?= $article && (int)$intro['id'] === (int)$article['id'] ? ' on' : '' ?>"
               href="<?= h(admin_url(['p' => 'articles', 'lang' => $lang, 'id' => $intro['id']])) ?>"
               title="<?= h(t('fofejezet.leiras.nyit')) ?>">
              <span><?= h($m['chapter_no']) ?> <?= h($m['title']) ?></span>
              <?php if (!$intro['is_published']): ?><span class="dot dot--hidden" title="<?= h(t('Kikapcsolva – nem látszik a nyilvános oldalon')) ?>"></span><?php endif; ?>
              <?php if ($intro['has_draft']): ?><span class="dot dot--draft" title="<?= h(t('Van közzétételre váró vázlat')) ?>"></span><?php endif; ?>
            </a>
          <?php else: ?>
            <button type="button" class="picker__mt picker__mt--miss" data-fold="<?= (int)$m['id'] ?>"
                    title="<?= h(t('fofejezet.leiras.nincs')) ?>">
              <span><?= h($m['chapter_no']) ?> <?= h($m['title']) ?></span>
            </button>
            <?php // A cimre kattintas csak nyit/csuk - letrehozni CSAK ezzel a
                  // jelzessel lehet, hogy velatlenul ne szulessen nevtelen fejezet. ?>
            <button type="button" class="picker__miss"
                    data-new-in="<?= (int)$m['id'] ?>" data-new-no="<?= h($m['chapter_no']) ?>"
                    data-confirm-new="<?= h(t('fofejezet.leiras.kerdes', ['nev' => $m['chapter_no'] . ' ' . $m['title']])) ?>"
                    title="<?= h(t('fofejezet.leiras.nincs')) ?>"><?= h(t('nincs leírás')) ?></button>
          <?php endif; ?>
          <?php // Minden soron ugyanaz a sorrend: +  ✎  szem  ✕ ?>
          <button type="button" class="picker__madd" data-new-in="<?= (int)$m['id'] ?>"
                  data-new-no="<?= h((string)($nextNoByModule[(int)$m['id']] ?? '')) ?>"
                  title="<?= h(t('Új fejezet ebbe a főfejezetbe')) ?>">+</button>
          <?= visibility_form('module', (int)$m['id'], (bool)$m['is_published'], $lang,
                              $m['chapter_no'] . ' ' . $m['title']) ?>
          <form method="post" action="<?= h(admin_url()) ?>" style="display:inline"
                data-confirm="<?= h(t('torles.fofejezet', ['nev' => $m['chapter_no'] . ' ' . $m['title']])) ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="a" value="module.delete">
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <input type="hidden" name="lang" value="<?= h($lang) ?>">
            <input type="hidden" name="from" value="articles">
            <button type="submit" class="picker__madd picker__mdel"
                    title="<?= h(t('Főfejezet törlése')) ?>">✕</button>
          </form>
        </div>
        <div class="picker__group" data-module="<?= (int)$m['id'] ?>">
        <?php foreach ($m['articles'] as $a): ?>
          <div class="picker__row picker__row--d<?= (int)$a['depth'] ?>" data-id="<?= (int)$a['id'] ?>"
               data-from-module="<?= (int)$m['id'] ?>">
            <span class="picker__grip" title="<?= h(t('Húzd a sorrend átrendezéséhez')) ?>" aria-hidden="true">⠿</span>
            <a class="picker__a<?= $article && (int)$a['id'] === (int)$article['id'] ? ' on' : '' ?>"
               href="<?= h(admin_url(['p' => 'articles', 'lang' => $lang, 'id' => $a['id']])) ?>">
              <em><?= h($a['chapter_no']) ?></em><span><?= h($a['title']) ?></span>
              <?php if (!$a['is_published']): ?><span class="dot dot--hidden" title="<?= h(t('Kikapcsolva – nem látszik a nyilvános oldalon')) ?>"></span><?php endif; ?>
              <?php if ($a['has_draft']): ?><span class="dot dot--draft" title="<?= h(t('Van közzétételre váró vázlat')) ?>"></span><?php endif; ?>
            </a>
            <?php if ($a['next_sub'] !== ''): ?>
              <button type="button" class="picker__madd picker__radd"
                      data-new-in="<?= (int)$m['id'] ?>" data-new-no="<?= h($a['next_sub']) ?>"
                      title="Alfejezet ide: <?= h($a['next_sub']) ?>">+</button>
            <?php endif; ?>
            <?= visibility_form('article', (int)$a['id'], (bool)$a['is_published'], $lang,
                                $a['chapter_no'] . ' ' . $a['title']) ?>
            <form method="post" action="<?= h(admin_url()) ?>" class="picker__delf"
                  data-confirm="<?= h(t('torles.kerdes', ['nev' => trim($a['chapter_no'] . ' ' . $a['title'])])) ?>">
              <?= csrf_input() ?>
              <input type="hidden" name="a" value="article.delete">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <button type="submit" class="picker__madd picker__rdel"
                      title="<?= h(t('torles.cim')) ?>">✕</button>
            </form>
          </div>
        <?php endforeach; ?>
        </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$tree): ?><div class="empty"><?= h(t('Ezen a nyelven még nincs főfejezet.')) ?></div><?php endif; ?>
    </div>

    <?php // A tomeges kijeloles kivezetve: minden soron ott a sajat ✕
          // gombja, ami rakerdez. A kijelolo negyzetek ugysem mukodtek -
          // egy korabbi javito szkriptem a pick-one osztalyt az INPUT-rol a
          // LABEL-re tette, igy a label.checked mindig undefined volt, es a
          // muveleti sav sosem jott elo. ?>
  </div><?php // .picker ?>


  <!-- jobb: szerkesztő -->
  <div>
    <?= flash_render() ?>

    <?php // A fofejezet kulon szerkeszto-panelje kivezetve: a NEVET a leiras
          // cime adja (nyelvenkent), a modul URL-jet sehol nem hasznaljuk, a
          // SZAMOT pedig a leiras Adatok panelje allitja. ?>
    <?php if (!$article): ?>
 <div class="panel"><div class="panel__b">
        <?= t('ures.fejezetek') ?>
      </div></div>

    <?php else:
      $hasDraft = $article['draft_html'] !== null;
      $body = $hasDraft ? (string)$article['draft_html'] : (string)$article['body_html'];
      ?>
      <?php if ($hasDraft): ?>
        <div class="msg msg--warn">
          <span class="msg__h"><?= h(t('Ezen a fejezeten van közzétételre váró vázlat.')) ?></span>
          <?= t('vazlat.figyelmeztetes', ['mikor' => h(substr((string)$article['draft_at'], 0, 16))]) ?>
        </div>
      <?php endif; ?>
      <?php if (!$article['is_published']): ?>
        <div class="msg msg--info"><?= t('fejezet.nincs.kozzeteve') ?></div>
      <?php endif; ?>

      <?php
      // A fejezet osszes nyelvi valtozata, hogy nyelvenkent lehessen
      // ki-/bekapcsolni. A parokat a FEJEZETSZAM koti ossze - a slug
      // nyelvenkent mas (5-3-penzmozgasok / 5-3-cash-movements), ezert
      // slug szerint keresve a panel azt irta, hogy "nincs ilyen nyelvu
      // valtozat", holott volt. A slug csak tartalek a szam nelkuli
      // fejezetekhez.
      $siblings = [];
      if (trim((string)$article['chapter_no']) !== '') {
          $sib = $db->prepare('SELECT id, lang, is_published, draft_html IS NOT NULL AS has_draft
                                 FROM help_article WHERE chapter_no = ? ORDER BY lang');
          $sib->execute([$article['chapter_no']]);
          foreach ($sib->fetchAll() as $r) { $siblings[$r['lang']] = $r; }
      }
      if (!$siblings) {
          $sib = $db->prepare('SELECT id, lang, is_published, draft_html IS NOT NULL AS has_draft
                                 FROM help_article WHERE slug = ? ORDER BY lang');
          $sib->execute([$article['slug']]);
          foreach ($sib->fetchAll() as $r) { $siblings[$r['lang']] = $r; }
      }
      $onCount = count(array_filter($siblings, static fn($r) => $r['is_published']));
      ?>
      <div class="panel" style="margin-bottom:14px">
        <div class="panel__h">
          <h2 style="font-size:calc(12.4px * var(--fs));text-transform:uppercase;letter-spacing:.6px;color:var(--ink-3)">
            <?= h(t('Nyelvenkénti megjelenés')) ?></h2>
          <span class="sp"></span>
          <form method="post" action="<?= h(admin_url()) ?>" style="display:inline">
            <?= csrf_input() ?>
            <input type="hidden" name="a" value="article.toggle">
            <input type="hidden" name="scope" value="all">
            <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
            <input type="hidden" name="on" value="<?= $onCount === count($siblings) ? '0' : '1' ?>">
            <button class="btn btn--sm" type="submit">
              <?= $onCount === count($siblings) ? t('Mindegyik kikapcsolása') : t('Mindegyik bekapcsolása') ?>
            </button>
          </form>
        </div>
        <div class="panel__b" style="display:flex;gap:10px;flex-wrap:wrap">
          <?php foreach (admin_langs() as $code => $label):
              $r = $siblings[$code] ?? null; ?>
            <div class="langcard<?= $r && $r['is_published'] ? ' on' : '' ?>">
 <div class="langcard__t"><?= h($label) ?> <span class="mono muted"><?= h($code) ?></span></div>
              <?php if (!$r): ?>
                <div class="muted" style="font-size:calc(12px * var(--fs))"><?= h(t('nincs ilyen nyelvű változat')) ?></div>
                <a class="btn btn--sm" href="<?= h(admin_url(['p' => 'translate', 'to' => $code === 'hu' ? 'en' : $code,
                     'src' => $article['lang'] === 'hu' ? $article['id'] : 0])) ?>"><?= h(t('Fordítás →')) ?></a>
              <?php else: ?>
                <form method="post" action="<?= h(admin_url()) ?>">
                  <?= csrf_input() ?>
                  <input type="hidden" name="a" value="article.toggle">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn--sm <?= $r['is_published'] ? 'btn--ok' : 'btn--danger' ?>" type="submit">
                    <?= $r['is_published'] ? t('● Látszik') : '○ Kikapcsolva' ?>
                  </button>
                </form>
                <?php if ($r['has_draft']): ?><span class="badge badge--warn"><?= h(t('vázlat')) ?></span><?php endif; ?>
                <?php if ((int)$r['id'] !== (int)$article['id']): ?>
                  <a class="btn btn--sm btn--ghost" href="<?= h(admin_url(['p' => 'articles', 'lang' => $code, 'id' => $r['id']])) ?>"><?= h(t('Megnyitás')) ?></a>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- fejezet adatai -->
      <div class="panel" style="margin-bottom:14px">
        <div class="panel__h">
          <h2><?= h($article['chapter_no']) ?> <span id="ed-title-echo"><?= h($article['title']) ?></span></h2>
          <span class="badge badge--info"><?= h($article['lang']) ?></span>
          <span class="sp"></span>
          <form method="post" action="<?= h(admin_url()) ?>" style="display:inline">
            <?= csrf_input() ?>
            <input type="hidden" name="a" value="article.toggle">
            <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
            <button class="btn btn--sm <?= $article['is_published'] ? 'btn--ok' : 'btn--danger' ?>" type="submit"
                    title="<?= $article['is_published']
                        ? t('Kikapcsolás: a fejezet eltűnik a nyilvános oldalról, a tartalma megmarad.') : t('Bekapcsolás: a fejezet megjelenik a nyilvános oldalon.') ?>">
              <?= $article['is_published'] ? t('● Közzétéve — kikapcsolom') : '○ Kikapcsolva — bekapcsolom' ?>
            </button>
          </form>
          <a class="btn btn--sm" href="/<?= h($article['lang']) ?>/<?= h($article['slug']) ?>" target="_blank" rel="noopener"><?= h(t('Megnyitás a súgóban ↗')) ?></a>
          <button class="btn btn--sm" type="button" data-toggle="#meta-form"><?= h(t('Adatok')) ?></button>
          <a class="btn btn--sm<?= $revisions ? '' : ' btn--ghost' ?>" href="#revisions"
             title="<?= $revisions
                 ? t('A fejezet korábbi állapotai — összehasonlítás és visszatöltés') : t('Még nem volt közzététel ezen a fejezeten, ezért nincs korábbi változat') ?>">
            <?= h(t('Változatok')) ?><?php if ($revisions): ?> <span class="badge"><?= count($revisions) ?></span><?php endif; ?>
          </a>
          <button class="btn btn--sm btn--danger" type="button" data-modal="del-article"
                  title="<?= h(t('A fejezet a Kukába kerül, ahonnan visszaállítható')) ?>"><?= h(t('Törlés')) ?></button>
        </div>
        <div class="panel__b" id="meta-form" hidden>
          <form method="post" action="<?= h(admin_url()) ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="a" value="article.meta">
            <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
            <?php // Ami magatol all be, az itt csak AZERT van, hogy felul
                  // lehessen irni. A cimet a szerkeszto tetejen irod, a
                  // fofejezetet huzassal valtoztatod - azok nincsenek itt. ?>
            <div class="hint" style="margin-bottom:10px"><?= t('meta.mire') ?></div>
            <div class="row">
              <div class="field" style="flex:0 1 160px"><label><?= h(t('Fejezetszám')) ?></label>
                <input class="inp" name="chapter_no" value="<?= h($article['chapter_no']) ?>"></div>
              <div class="field" style="flex:2 1 260px"><label><?= h(t('URL-azonosító (slug)')) ?></label>
                <input class="inp mono" name="slug" value="<?= h($article['slug']) ?>">
                <div class="hint">Ez lesz az URL: /<?= h($article['lang']) ?>/<b><?= h($article['slug']) ?></b></div></div>
            </div>
            <?php // Innen kikerult: Sorrend (huzassal rendezel), Jogosultsag
                  // (sehol nem olvasta a rendszer, csak igerte), "Kozzeteve"
                  // pipa (a szem ikon es a fejleci gomb ugyanaz) es a masodik
                  // torles gomb (a fejlecben ott van). ?>
            <div class="btnbar">
              <button class="btn btn--p" type="submit"><?= h(t('Adatok mentése')) ?></button>
            </div>
          </form>
        </div>
      </div>

      <!-- tartalom szerkesztő -->
      <form method="post" action="<?= h(admin_url()) ?>" id="editor-form">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="article.draft">
        <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
        <div class="ed-title">
          <label for="ed-title-in"><?= h(t('A fejezet címe')) ?></label>
          <input class="inp ed-title__in" id="ed-title-in" name="title" required
                 placeholder="<?= h(t('Írd ide a fejezet címét')) ?>"
                 value="<?= h((string)($article['draft_title'] ?? $article['title'])) ?>">
        </div>

        <?php editor_block((string)$article['chapter_no'], $body); ?>

        <div class="hint" style="margin-top:6px">
          <?= t('sugo.kepfeltoltes') ?>
        </div>

        <div class="savebar">
          <button class="btn btn--p" type="submit" id="btn-save"><?= h(t('Vázlat mentése')) ?></button>
          <span class="state" id="ed-state"><?= h(t('A vázlat csak a szerkesztőben látszik, a nyilvános oldalon nem.')) ?></span>
          <span style="flex:1"></span>
          <?php if ($hasDraft): ?>
            <button class="btn btn--ghost btn--sm" type="submit" form="discard-form"><?= h(t('Vázlat eldobása')) ?></button>
            <button class="btn btn--ok" type="button" data-modal="publish"><?= h(t('Közzététel →')) ?></button>
          <?php else: ?>
            <span class="state"><?= h(t('Közzétenni akkor lehet, ha van mentett vázlat.')) ?></span>
          <?php endif; ?>
        </div>
      </form>

      <form method="post" action="<?= h(admin_url()) ?>" id="discard-form" hidden>
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="article.discard">
        <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
      </form>

      <?php if ($diffRow): ?>
        <?php
        // Amivel osszevetjuk: a mostani vazlat, ha van, kulonben a kozzetett.
        $nowHtml  = (string)($article['draft_html'] ?? $article['body_html']);
        $nowTitle = (string)($article['draft_title'] ?? $article['title']);
        $nowLabel = $article['draft_html'] !== null ? t('a mostani vázlat') : t('a közzétett változat');
        $cmp = diff_html(help_plain((string)$diffRow['body_html']), help_plain($nowHtml));
        ?>
        <div class="panel" style="margin-top:16px">
          <div class="panel__h"><h2><?= h(t('diff.cim', ['n' => (int)$diffRow['rev_no'], 'mihez' => $nowLabel])) ?></h2>
            <span class="sp"></span>
            <a class="btn btn--sm btn--ghost"
               href="<?= h(admin_url(['p' => 'articles', 'lang' => $lang, 'id' => $article['id']])) ?>"><?= h(t('Bezárás')) ?></a>
          </div>
          <div class="panel__b">
            <div class="hint" style="margin-bottom:10px">
              <?= t('valtozat.mentette', [
                     'n'    => (int)$diffRow['rev_no'],
                     'mikor' => h(substr((string)$diffRow['created_at'], 0, 16)),
                     'ki'   => h((string)($diffRow['display_name'] ?: t('ismeretlen'))),
                   ]) ?><?php
                if ((string)$diffRow['note'] !== ''): ?> — „<?= h((string)$diffRow['note']) ?>"<?php endif; ?>.
              <?php if ((string)$diffRow['title'] !== $nowTitle): ?>
                <br><?= t('diff.cim.valtozott', ['regi' => h((string)$diffRow['title']), 'uj' => h($nowTitle)]) ?>
              <?php endif; ?>
            </div>

            <?php if (!$cmp['changed']): ?>
              <div class="msg msg--info" style="margin:0">A két változat szövege <b><?= h(t('szó szerint megegyezik')) ?></b>.</div>
            <?php else: ?>
              <div class="diff-legend">
                <span><i style="background:var(--ok-soft);color:var(--ok)">+ <?= h(t('diff.uj.szo', ['n' => (int)$cmp['added']])) ?></i></span>
                <span><i style="background:var(--err-soft);color:var(--err)">− <?= h(t('diff.elhagyott.szo', ['n' => (int)$cmp['removed']])) ?></i></span>
                <span class="muted"><?= h(t('A zöld a mostaniban van benne, a piros a régiben volt.')) ?></span>
              </div>
              <div class="diff"><?= $cmp['html'] ?></div>
            <?php endif; ?>

            <div class="btnbar" style="margin-top:12px">
              <form method="post" action="<?= h(admin_url()) ?>"
                    data-confirm="<?= h(t('valtozat.betoltes.szam', ['n' => (int)$diffRow['rev_no']])) ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="a" value="article.restore">
                <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
                <input type="hidden" name="rev" value="<?= (int)$diffRow['rev_no'] ?>">
                <button class="btn btn--p btn--sm" type="submit"><?= h(t('Ezt a változatot töltöm vissza')) ?></button>
              </form>
              <span class="muted"><?= h(t('A visszatöltés vázlatot készít — a nyilvános oldal csak közzététel után változik.')) ?></span>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- korábbi változatok -->
      <div class="panel" id="revisions" style="margin-top:16px;scroll-margin-top:110px">
 <div class="panel__h"><h2><?= h(t('Korábbi változatok')) ?></h2><span class="sp"></span>
          <span class="badge"><?= count($revisions) ?></span></div>
        <div class="panel__b panel__b--flush">
          <?php if (!$revisions): ?>
            <div class="empty">
              <?= t('valtozatok.uresen') ?>
            </div>
          <?php else: ?>
            <table class="tbl">
              <thead><tr><th>#</th><th><?= h(t('Cím')) ?></th><th><?= h(t('Összefoglaló')) ?></th><th><?= h(t('Mikor')) ?></th><th><?= h(t('Ki')) ?></th><th></th></tr></thead>
              <tbody>
              <?php foreach ($revisions as $r): ?>
                <tr>
                  <td class="num" data-label="#"><?= (int)$r['rev_no'] ?></td>
                  <td data-label="<?= h(t('Cím')) ?>"><?= h($r['title']) ?></td>
                  <td class="muted" data-label="<?= h(t('Összefoglaló')) ?>"><?= h((string)$r['note']) ?></td>
                  <td class="nowrap muted" data-label="Mikor"><?= h(substr((string)$r['created_at'], 0, 16)) ?></td>
                  <td class="muted" data-label="Ki"><?= h((string)$r['display_name']) ?></td>
                  <td class="nowrap" data-label="">
                    <form method="post" action="<?= h(admin_url()) ?>" data-confirm="<?= h(t('Betöltöd ezt a változatot vázlatként? A jelenlegi vázlat felülíródik.')) ?>">
                      <?= csrf_input() ?>
                      <input type="hidden" name="a" value="article.restore">
                      <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
                      <input type="hidden" name="rev" value="<?= (int)$r['rev_no'] ?>">
                      <button class="btn btn--sm" type="submit"><?= h(t('Visszatöltés')) ?></button>
                    </form>
                    <a class="btn btn--sm btn--ghost"
                       href="<?= h(admin_url(['p' => 'articles', 'lang' => $lang, 'id' => $article['id'],
                                              'diff' => $r['rev_no']])) ?>"><?= h(t('Összehasonlítás')) ?></a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- közzététel modális -->
      <div class="modal" id="modal-publish">
        <div class="modal__box">
          <form method="post" action="<?= h(admin_url()) ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="a" value="article.publish">
            <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
            <div class="modal__h"><?= h(t('Közzététel')) ?></div>
            <div class="modal__b">
              <p class="lead" style="margin-bottom:14px"><?= t('A vázlat élesítése: ettől kezdve ez látszik a nyilvános oldalon. A korábbi változat megmarad, bármikor visszatölthető.') ?></p>
              <div class="field">
                <label for="summary"><?= h(t('Mi változott? (az Újdonságok listába kerül)')) ?></label>
                <input class="inp" id="summary" name="summary" placeholder="<?= h(t('pl. Frissített képernyőképek a kintlévőség-kezelésnél')) ?>">
                <div class="hint"><?= h(t('Üresen hagyva nem készül változásnapló-bejegyzés.')) ?></div>
              </div>
              <div class="row">
                <div class="field"><label for="kind"><?= h(t('Típus')) ?></label>
                  <select class="sel" id="kind" name="kind">
                    <option value="mod"><?= h(t('módosítás')) ?></option>
                    <option value="new"><?= h(t('új tartalom')) ?></option>
                    <option value="fix"><?= h(t('javítás')) ?></option>
                  </select></div>
                <div class="field" style="align-self:center">
                  <label class="check"><input type="checkbox" name="minor"> <?= h(t('Apró javítás (ne jelenjen meg a Mi újságban)')) ?></label>
                  <label class="check"><input type="checkbox" name="keep_tr"> <?= h(t('kozzetetel.forditas.marad')) ?></label>
                  <div class="hint"><?= t('kozzetetel.forditas.sugo') ?></div>
                </div>
              </div>
            </div>
            <div class="modal__f">
              <button class="btn btn--ghost" type="button" data-close><?= h(t('Mégsem')) ?></button>
              <span style="flex:1"></span>
              <button class="btn" type="submit"><?= h(t('Közzététel')) ?></button>
              <?php // A tobbi nyelv EGY gombbal: leforditja es kozze is teszi.
                    // Csak a forrasnyelven van ertelme, es csak ha van fordito. ?>
              <?php if ($article['lang'] === admin_source_lang() && admin_target_langs()):
                      $trCfg = Translator::fromConfig($cfg, $db); ?>
                <button class="btn btn--ok" type="submit" id="publish-all"
                        data-langs="<?= h(implode(',', array_keys(admin_target_langs()))) ?>"
                        <?= $trCfg->isConfigured() ? '' : 'disabled' ?>
                        title="<?= h($trCfg->isConfigured()
                             ? t('kozzetetel.mindenhol.sugo')
                             : t('Nincs beállítva gépi fordító')) ?>">
                  <?= h(t('kozzetetel.mindenhol')) ?>
                </button>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <!-- törlés modális -->
      <div class="modal" id="modal-del-article">
        <div class="modal__box">
          <form method="post" action="<?= h(admin_url()) ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="a" value="article.delete">
            <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
            <div class="modal__h"><?= h(t('Fejezet törlése')) ?></div>
            <div class="modal__b">
              <div class="msg msg--err" style="margin:0">
                <span class="msg__h"><?= h(trim($article['chapter_no'] . ' ' . $article['title'])) ?></span>
                <?= t('torles.kukaba.magyarazat') ?>
              </div>
            </div>
            <div class="modal__f">
              <button class="btn btn--ghost" type="button" data-close><?= h(t('Mégsem')) ?></button>
              <button class="btn btn--danger" type="submit"><?= h(t('Végleges törlés')) ?></button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- új fejezet modális -->
<!-- A tömeges törlés megerősítése. Szándékosan NEM window.confirm(): azt
     több böngésző (és a beágyazott nézetek) letiltják, és akkor a törlés
     némán elmarad — ez a saját modális mindenhol működik. -->
<div class="modal" id="modal-bulk-delete">
  <div class="modal__box">
    <div class="modal__h"><?= h(t('Kijelölt fejezetek törlése')) ?></div>
    <div class="modal__b">
      <div class="msg msg--err" style="margin:0">
        <b><span id="bulk-del-count">0</span> fejezet</b> a <b><?= h(t('Kukába')) ?></b> kerül a korábbi
        változataival együtt — onnan egy kattintással visszaállítható, amíg ki nem üríted.
      </div>
    </div>
    <div class="modal__f">
      <button class="btn btn--ghost" type="button" data-close><?= h(t('Mégsem')) ?></button>
      <button class="btn btn--danger" type="button" id="bulk-del-ok"><?= h(t('Törlés')) ?></button>
    </div>
  </div>
</div>

<!-- Új FŐFEJEZET (modul) közvetlenül a Fejezetek fülről -->
<?php // Az "Uj fofejezet" ablak kivezetve: a gomb rogton letrehozza. ?>

<?php // Az "Uj fejezet" ablak kivezetve: minden sorban ott a "+", ami
      // rogton letrehozza a fejezetet a kovetkezo szabad szammal. A felso
      // gomb ugyanezt csinalta, csak elobb meg ki kellett valasztani a
      // modult egy legorduloben. ?>
    <?php
    admin_foot();
}

// ============================================================ MODULOK
function page_modules(PDO $db, string $lang, array $counts): void
{
    $st = $db->prepare('SELECT m.*, (SELECT count(*) FROM help_article a WHERE a.module_id = m.id) AS n
                          FROM help_module m WHERE m.lang = ? ORDER BY m.sort_order, m.id');
    $st->execute([$lang]);
    $modules = $st->fetchAll();

    admin_head('Modulok', 'modules', $counts);
    ?>
<div class="page">
  <h1 class="pt"><?= h(t('Modulok')) ?></h1>
  <p class="lead"><?= t('A súgó felső szintje. A fejezetek ezekbe vannak besorolva, a sorrend itt állítható. Minden nyelvnek saját modulsora van — az összetartozást a fejezetszám köti össze.') ?></p>
  <?= flash_render() ?>
  <div style="margin-bottom:14px"><?= lang_switch('modules', $lang) ?></div>

  <div class="panel">
 <div class="panel__h"><h2><?= h(admin_langs()[$lang]) ?> modulok</h2><span class="sp"></span>
      <button class="btn btn--p btn--sm" type="button" data-modal="new-module"><?= h(t('+ Új modul')) ?></button></div>
    <div class="panel__b panel__b--flush">
      <div class="hint" style="padding:10px 16px 0"><?= t('A sorrendet a sor eleji <b>⠿</b> fogantyúval húzva is átrendezheted — a mentés automatikus.') ?></div>
      <?php foreach ($modules as $m): ?>
        <form method="post" action="<?= h(admin_url()) ?>" id="mf<?= (int)$m['id'] ?>">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="a" value="module.save">
          <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
          <input type="hidden" name="lang" value="<?= h($lang) ?>">
        </form>
      <?php endforeach; ?>
      <table class="tbl tbl--sortable" data-sort-action="modules.reorder">
        <thead><tr><th style="width:34px"></th><th style="width:90px">Sorrend</th><th style="width:90px"><?= h(t('Szám')) ?></th><th><?= h(t('Név')) ?></th><th><?= h(t('URL-azonosító')) ?></th><th class="num"><?= h(t('Fejezet')) ?></th><th></th></tr></thead>
        <tbody id="mod-sort">
        <?php foreach ($modules as $m): ?>
          <tr data-id="<?= (int)$m['id'] ?>">
 <td class="grip picker__grip" data-label=""><span title="<?= h(t('Húzd az átrendezéshez')) ?>">⠿</span></td>
            <td data-label="Sorrend"><input class="inp" form="mf<?= (int)$m['id'] ?>" name="sort_order" type="number" value="<?= (int)$m['sort_order'] ?>" style="width:74px"></td>
            <td data-label="<?= h(t('Szám')) ?>"><input class="inp" form="mf<?= (int)$m['id'] ?>" name="chapter_no" value="<?= h($m['chapter_no']) ?>" style="width:74px"></td>
            <td data-label="<?= h(t('Név')) ?>"><input class="inp" form="mf<?= (int)$m['id'] ?>" name="title" value="<?= h($m['title']) ?>"></td>
            <td data-label="<?= h(t('URL-azonosító')) ?>"><input class="inp mono" form="mf<?= (int)$m['id'] ?>" name="slug" value="<?= h($m['slug']) ?>"></td>
            <td class="num" data-label="Fejezet"><?= (int)$m['n'] ?></td>
            <td class="nowrap" data-label="">
              <button class="btn btn--sm btn--p" form="mf<?= (int)$m['id'] ?>" type="submit"><?= h(t('Mentés')) ?></button>
              <?php if (true): ?>
                <form method="post" action="<?= h(admin_url()) ?>" style="display:inline"
                      data-confirm="<?= h(t('Törlöd ezt a főfejezetet? Mind a három nyelven a Kukába kerül, ahonnan visszaállítható. Csak akkor sikerül, ha egyetlen nyelven sincs benne fejezet.')) ?>">
                  <?= csrf_input() ?>
                  <input type="hidden" name="a" value="module.delete">
                  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <input type="hidden" name="lang" value="<?= h($lang) ?>">
                  <button class="btn btn--sm btn--danger" type="submit"><?= h(t('Törlés')) ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!$modules): ?><div class="empty"><?= h(t('Ezen a nyelven még nincs modul.')) ?></div><?php endif; ?>
    </div>
  </div>
</div>

<div class="modal" id="modal-new-module">
  <div class="modal__box">
    <form method="post" action="<?= h(admin_url()) ?>">
      <?= csrf_input() ?>
      <input type="hidden" name="a" value="module.save">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">
      <div class="modal__h"><?= h(t('Új modul')) ?> (<?= h(admin_langs()[$lang]) ?>)</div>
      <div class="modal__b">
        <div class="row">
 <div class="field inp"><label><?= h(t('Szám')) ?></label><input name="chapter_no" placeholder="18"></div>
 <div class="field inp" style="flex:3 1 240px"><label><?= h(t('Név')) ?></label><input name="title" required></div>
        </div>
        <div class="row">
 <div class="field inp mono"><label><?= h(t('URL-azonosító (automatikus, ha üres)')) ?></label><input name="slug"></div>
 <div class="field inp" style="flex:0 1 120px"><label><?= h(t('Sorrend')) ?></label><input name="sort_order" type="number" value="0"></div>
        </div>
      </div>
      <div class="modal__f">
        <button class="btn btn--ghost" type="button" data-close><?= h(t('Mégsem')) ?></button>
        <button class="btn btn--p" type="submit"><?= h(t('Létrehozás')) ?></button>
      </div>
    </form>
  </div>
</div>
    <?php
    admin_foot();
}
