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
    foreach (ADMIN_LANGS as $code => $label) {
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
<title>Bejelentkezés — Infinity Súgó admin</title>
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
    <h1>Infinity Súgó</h1>
    <p class="sub">Szerkesztői és adminisztrációs felület</p>

    <?= flash_render() ?>

    <?= csrf_input() ?>
    <input type="hidden" name="a" value="login">
    <div class="field">
      <label for="username">Felhasználónév</label>
      <input class="inp" id="username" name="username" autocomplete="username" autofocus required>
    </div>
    <div class="field">
      <label for="password">Jelszó</label>
      <input class="inp" id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <button class="btn btn--p" type="submit" style="width:100%">Belépés</button>

    <div class="login__foot">
      <a href="/hu/">Vissza a súgóhoz</a>
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
    admin_head('Jelszócsere');
    ?>
<div class="page" style="max-width:560px">
  <h1 class="pt">Jelszócsere</h1>
  <p class="lead">
    Adj meg egy új jelszót ehhez a fiókhoz. Ez nem kötelező — a
    <a href="<?= h(admin_url(['p' => 'settings'])) ?>#jelszo">Beállítások</a> fülön is bármikor elvégezhető.
  </p>
  <?= flash_render() ?>
 <div class="panel panel__b"><div >
    <form method="post" action="<?= h(admin_url()) ?>" autocomplete="off">
      <?= csrf_input() ?>
      <input type="hidden" name="a" value="chpw">
      <div class="field">
        <label for="current">Jelenlegi jelszó</label>
        <input class="inp" id="current" name="current" type="password" autocomplete="current-password" required autofocus>
      </div>
      <div class="field">
        <label for="new">Új jelszó</label>
        <input class="inp" id="new" name="new" type="password" autocomplete="new-password" required minlength="8">
        <div class="hint">Legalább 8 karakter, betű és szám is legyen benne.</div>
      </div>
      <div class="field">
        <label for="new2">Új jelszó még egyszer</label>
        <input class="inp" id="new2" name="new2" type="password" autocomplete="new-password" required minlength="8">
      </div>
      <div class="btnbar">
        <button class="btn btn--p" type="submit">Jelszó mentése</button>
        <a class="btn btn--ghost" href="<?= h(admin_url()) ?>">Mégsem</a>
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

    admin_head('Saját fiók', '');
    ?>
<div class="page" style="max-width:640px">
  <h1 class="pt">Saját fiók</h1>
  <p class="lead">A bejelentkezési adataid és a jelszavad.</p>
  <?= flash_render() ?>

  <div class="panel" style="margin-bottom:16px">
    <div class="panel__h"><h2>Adatok</h2></div>
    <div class="panel__b">
      <table class="tbl">
        <tr><td style="width:180px" class="muted">Felhasználónév</td><td><b><?= h($me['username']) ?></b></td></tr>
        <tr><td class="muted">Név</td><td><?= h($me['display_name']) ?></td></tr>
 <tr><td class="muted badge badge--info">Szerepkör</td><td><span ><?= h($me['role']) ?></span></td></tr>
        <tr><td class="muted">Utolsó belépés</td><td><?= h((string)($me['last_login_at'] ?? '—')) ?></td></tr>
      </table>
    </div>
  </div>

  <div class="panel">
    <div class="panel__h"><h2>Jelszó módosítása</h2></div>
    <div class="panel__b">
      <form method="post" action="<?= h(admin_url()) ?>" autocomplete="off">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="chpw">
        <div class="field"><label for="c">Jelenlegi jelszó</label>
          <input class="inp" id="c" name="current" type="password" required></div>
        <div class="row">
          <div class="field"><label for="n1">Új jelszó</label>
            <input class="inp" id="n1" name="new" type="password" required minlength="8"></div>
          <div class="field"><label for="n2">Még egyszer</label>
            <input class="inp" id="n2" name="new2" type="password" required minlength="8"></div>
        </div>
        <button class="btn btn--p" type="submit">Mentés</button>
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

    $audit = $db->query("SELECT * FROM help_audit ORDER BY created_at DESC LIMIT 12")->fetchAll();
    $release = $db->query("SELECT * FROM help_release WHERE status = 'open' LIMIT 1")->fetch();

    admin_head('Áttekintés', 'dashboard', $counts);
    ?>
<div class="page">
  <h1 class="pt">Áttekintés</h1>
  <p class="lead">A súgó jelenlegi állapota. A bal oldali fülekről érhető el minden szerkesztési feladat.</p>
  <?= flash_render() ?>

  <!-- A csempék kattinthatók: mindegyik a hozzá tartozó, MÁR LESZŰRT listára visz. -->
  <div class="stats">
    <a class="stat" href="<?= h(admin_url(['p' => 'articles'])) ?>">
 <div class="stat__n stat__l"><?= (int)$stats['articles'] ?></div><div >fejezet (3 nyelven)</div></a>
    <a class="stat <?= (int)$stats['drafts'] ? 'stat--warn' : '' ?>" href="#drafts">
 <div class="stat__n stat__l"><?= (int)$stats['drafts'] ?></div><div >közzétételre váró vázlat</div></a>
    <a class="stat <?= $stale ? 'stat--warn' : '' ?>" href="<?= h(admin_url(['p' => 'translate', 'to' => 'en', 'st' => 'stale'])) ?>">
 <div class="stat__n stat__l"><?= $stale ?></div><div >elavult fordítás</div></a>
    <a class="stat <?= $missing ? 'stat--err' : '' ?>" href="<?= h(admin_url(['p' => 'translate', 'to' => 'en', 'st' => 'missing'])) ?>">
 <div class="stat__n stat__l"><?= $missing ?></div><div >hiányzó fordítás</div></a>
    <a class="stat" href="<?= h(admin_url(['p' => 'modules'])) ?>">
 <div class="stat__n stat__l"><?= (int)$stats['modules'] ?></div><div >modul</div></a>
    <a class="stat" href="<?= h(admin_url(['p' => 'screens'])) ?>">
 <div class="stat__n stat__l"><?= (int)$stats['screens'] ?></div><div >képernyő-hozzárendelés</div></a>
  </div>

  <div class="page--split" style="padding:0">
    <div>
      <div class="panel" id="drafts" style="margin-bottom:16px">
 <div class="panel__h sp"><h2>Közzétételre vár</h2><span ></span>
          <span class="badge"><?= count($drafts) ?></span></div>
        <div class="panel__b panel__b--flush">
          <?php if (!$drafts): ?>
            <div class="empty">Nincs nyitott vázlat — minden közzé van téve.</div>
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
                  <td data-label="Cím">
                    <a href="<?= h(admin_url(['p' => 'articles', 'lang' => $d['lang'], 'id' => $d['id']])) ?>"><?= h($dTitle) ?></a>
                    <?php if ($isMt): ?>
                      <span class="badge badge--info" title="Magyarból készült gépi nyersfordítás — nézd át, mielőtt közzéteszed.">gépi fordítás</span>
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
        <div class="panel__h"><h2>Legutóbbi Word-importok</h2></div>
        <div class="panel__b panel__b--flush">
          <?php if (!$imports): ?>
            <div class="empty">Még nem volt import. <a href="<?= h(admin_url(['p' => 'import'])) ?>">Word betöltése →</a></div>
          <?php else: ?>
            <table class="tbl">
              <?php foreach ($imports as $i): $s = json_decode((string)$i['stats'], true) ?: []; ?>
                <tr>
                  <td data-label="Fájl"><a href="<?= h(admin_url(['p' => 'import', 'import' => $i['id']])) ?>"><?= h($i['filename']) ?></a></td>
                  <td class="nowrap muted" data-label="Tartalom"><?= (int)($s['chapters'] ?? 0) ?> fejezet · <?= (int)($s['images'] ?? 0) ?> kép</td>
 <td class="nowrap badge <?= $i['status'] === 'applied' ? 'badge--ok' : '' ?>" data-label="Állapot"><span ><?= h($i['status']) ?></span></td>
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
        <div class="panel__h"><h2>Nyitott kiadás</h2></div>
        <div class="panel__b">
          <?php if ($release): ?>
            <div class="stat__n" style="font-size:calc(20px * var(--fs))"><?= h($release['version']) ?></div>
            <div class="hint">A közzétételkor megadott összefoglalók ebbe a kiadásba gyűlnek.
              Lezárni a <a href="<?= h(admin_url(['p' => 'settings'])) ?>">Beállítások</a> fülön lehet.</div>
          <?php else: ?>
            <div class="muted">Nincs nyitott kiadás.</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel__h"><h2>Napló</h2></div>
        <div class="panel__b panel__b--flush">
          <table class="tbl">
            <?php foreach ($audit as $a): ?>
              <tr>
                <td class="nowrap muted" style="width:104px" data-label="Mikor"><?= h(substr((string)$a['created_at'], 5, 11)) ?></td>
                <td class="mono nowrap" data-label="Művelet"><?= h($a['action']) ?></td>
                <td class="muted" data-label="Ki"><?= h((string)$a['username']) ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
    <?php
    admin_foot();
}

// ============================================================ FEJEZETEK
function page_articles(PDO $db, string $lang, int $id, array $counts, int $modId = 0): void
{
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
    foreach ($modules as &$m) {
        [$next] = article_next_slot($db, (int)$m['id'], $lang, '', '');
        $m['next_no'] = $next;
    }
    unset($m);

    admin_head('Fejezetek', 'articles', $counts);
    ?>
<div class="page page--split">

  <!-- bal: fejezetválasztó -->
  <div class="panel picker" id="picker">
    <div class="picker__f">
      <input class="inp" id="pick-filter" placeholder="Szűrés…  (Ctrl+K a kereséshez)" autocomplete="off">
      <button class="btn btn--sm" type="button" id="pick-foldall" title="Mindent összecsuk / kinyit">⊟</button>
      <button class="btn btn--sm" type="button" id="pick-select" title="Több fejezet kijelölése">☑</button>
      <button class="btn btn--sm" type="button" id="pick-sort" title="Sorrend átrendezése húzással">↕</button>
      <button class="btn btn--sm" type="button" data-modal="new-module-a" title="Új főfejezet (modul)">+ fő</button>
      <button class="btn btn--p btn--sm" type="button" data-modal="new-article" title="Új fejezet">+</button>
    </div>
    <div style="padding:8px 10px 0"><?= lang_switch('articles', $lang) ?></div>

    <div class="picker__hint" id="pick-hint" hidden></div>

    <div class="picker__l" id="pick-list">
      <?php foreach ($tree as $m): ?>
        <div class="picker__mod" data-id="<?= (int)$m['id'] ?>">
        <div class="picker__m" data-module="<?= (int)$m['id'] ?>">
 <label class="picker__mchk pick-mod-all"><input type="checkbox" tabindex="-1"></label>
          <span class="picker__grip picker__mgrip" title="Húzd a főfejezetek sorrendjének átrendezéséhez" aria-hidden="true">⠿</span>
          <button type="button" class="picker__mt" data-fold="<?= (int)$m['id'] ?>"
                  title="Kattints a ki- és összecsukáshoz">
            <svg class="picker__caret" width="12" height="12" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
            <span><?= h($m['chapter_no']) ?> <?= h($m['title']) ?></span>
          </button>
          <a class="picker__madd picker__medit"
             href="<?= h(admin_url(['p' => 'articles', 'lang' => $lang, 'mod' => $m['id']])) ?>"
             title="A főfejezet neve, száma, URL-je — mindhárom nyelven">✎</a>
          <button type="button" class="picker__madd" data-new-in="<?= (int)$m['id'] ?>"
                  title="Új fejezet ebbe a főfejezetbe">+</button>
          <form method="post" action="<?= h(admin_url()) ?>" style="display:inline"
                data-confirm="Törlöd a(z) &quot;<?= h($m['chapter_no'] . ' ' . $m['title']) ?>&quot; főfejezetet? Mind a három nyelven a Kukába kerül, ahonnan visszaállítható. Csak akkor sikerül, ha egyetlen nyelven sincs benne fejezet.">
            <?= csrf_input() ?>
            <input type="hidden" name="a" value="module.delete">
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <input type="hidden" name="lang" value="<?= h($lang) ?>">
            <input type="hidden" name="from" value="articles">
            <button type="submit" class="picker__madd picker__mdel"
                    title="Főfejezet törlése">✕</button>
          </form>
        </div>
        <div class="picker__group" data-module="<?= (int)$m['id'] ?>">
        <?php foreach ($m['articles'] as $a): ?>
          <div class="picker__row picker__row--d<?= (int)$a['depth'] ?>" data-id="<?= (int)$a['id'] ?>">
 <label class="picker__chk pick-one"><input type="checkbox" value="<?= (int)$a['id'] ?>" tabindex="-1"></label>
            <span class="picker__grip" title="Húzd a sorrend átrendezéséhez" aria-hidden="true">⠿</span>
            <a class="picker__a<?= $article && (int)$a['id'] === (int)$article['id'] ? ' on' : '' ?>"
               href="<?= h(admin_url(['p' => 'articles', 'lang' => $lang, 'id' => $a['id']])) ?>">
              <em><?= h($a['chapter_no']) ?></em><span><?= h($a['title']) ?></span>
              <?php if (!$a['is_published']): ?><span class="dot dot--hidden" title="Kikapcsolva – nem látszik a nyilvános oldalon"></span><?php endif; ?>
              <?php if ($a['has_draft']): ?><span class="dot dot--draft" title="Van közzétételre váró vázlat"></span><?php endif; ?>
            </a>
            <?php if ($a['next_sub'] !== ''): ?>
              <button type="button" class="picker__madd picker__radd"
                      data-new-in="<?= (int)$m['id'] ?>" data-new-no="<?= h($a['next_sub']) ?>"
                      title="Alfejezet ide: <?= h($a['next_sub']) ?>">+</button>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
        </div>
      <?php endforeach; ?>
      <?php if (!$tree): ?><div class="empty">Ezen a nyelven még nincs főfejezet.</div><?php endif; ?>
    </div>

    <!-- tömeges műveletek sávja -->
    <form class="bulkbar" id="bulkbar" method="post" action="<?= h(admin_url()) ?>" hidden>
      <?= csrf_input() ?>
      <input type="hidden" name="a" value="articles.bulk">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">
      <input type="hidden" name="op" id="bulk-op" value="">
      <div class="bulkbar__n"><b id="bulk-count">0</b> kijelölve</div>
      <div class="bulkbar__b">
        <button class="btn btn--sm btn--ok" type="submit" data-op="publish-on" title="Megjelenik a nyilvános oldalon">● Bekapcsol</button>
        <button class="btn btn--sm btn--danger" type="submit" data-op="publish-off" title="Eltűnik a nyilvános oldalról">○ Kikapcsol</button>
        <button class="btn btn--sm" type="submit" data-op="publish-drafts" title="A kijelöltek vázlatainak közzététele">Vázlatok közzététele</button>
        <select class="sel" name="module_id" id="bulk-module" style="width:auto;min-width:140px">
          <option value="">Áthelyezés ide…</option>
          <?php foreach ($modules as $m): ?>
            <option value="<?= (int)$m['id'] ?>"><?= h($m['chapter_no'] . ' ' . $m['title']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn--sm" type="submit" data-op="move">Áthelyez</button>
        <span style="flex:1"></span>
        <button class="btn btn--sm btn--danger" type="submit" data-op="delete">Törlés</button>
        <button class="btn btn--sm btn--ghost" type="button" id="bulk-cancel">Mégsem</button>
      </div>
    </form>
  </div>

  <!-- jobb: szerkesztő -->
  <div>
    <?= flash_render() ?>

    <?php if ($module): ?>
      <div class="panel">
        <div class="panel__h"><h2>Főfejezet: <?= h($module['chapter_no'] . ' ' . $module['title']) ?></h2>
          <span class="sp"></span>
          <a class="btn btn--sm btn--ghost" href="<?= h(admin_url(['p' => 'articles', 'lang' => $lang])) ?>">Bezárás</a>
        </div>
        <div class="panel__b">
          <form method="post" action="<?= h(admin_url()) ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="a" value="module.save">
            <input type="hidden" name="id" value="<?= (int)$module['id'] ?>">
            <input type="hidden" name="lang" value="<?= h($lang) ?>">
            <input type="hidden" name="from" value="articles">

            <div class="row">
              <div class="field" style="flex:0 1 120px"><label>Szám</label>
                <input class="inp" name="chapter_no" value="<?= h($module['chapter_no']) ?>"></div>
              <div class="field"><label>URL-azonosító</label>
                <input class="inp mono" name="slug" value="<?= h($module['slug']) ?>"></div>
              <div class="field" style="flex:0 1 120px"><label>Sorrend</label>
                <input class="inp" name="sort_order" type="number" value="<?= (int)$module['sort_order'] ?>"></div>
            </div>

            <div class="lbl" style="margin-top:6px">A főfejezet neve nyelvenként</div>
            <div class="row">
              <?php foreach (ADMIN_LANGS as $code => $label):
                  $one = $moduleFamily[$code] ?? null; ?>
                <div class="field">
                  <label><?= h($label) ?> <span class="mono muted"><?= h($code) ?></span></label>
                  <input class="inp" name="title_<?= h($code) ?>"
                         value="<?= h($one ? (string)$one['title'] : '') ?>"
                         <?= $one ? '' : 'placeholder="ezen a nyelven még nincs"' ?>>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="hint">A számot és az URL-t mindhárom nyelven együtt állítjuk — ez köti össze
              a nyelvi változatokat. A nevet nyelvenként külön írhatod.</div>

            <div class="btnbar" style="margin-top:12px">
              <button class="btn btn--p" type="submit">Mentés</button>
              <span style="flex:1"></span>
              <span class="muted"><?= (int)$db->query('SELECT COUNT(*) FROM help_article WHERE module_id = ' . (int)$module['id'])->fetchColumn() ?> fejezet ezen a nyelven</span>
            </div>
          </form>
        </div>
      </div>

    <?php elseif (!$article): ?>
 <div class="panel empty"><div >
        Válassz egy fejezetet a bal oldali listából — vagy hozz létre újat a <b>+</b> gombbal.
        A <b>✎</b> gombbal a főfejezet nevét, számát és URL-jét szerkesztheted, mindhárom nyelven.
      </div></div>

    <?php else:
      $hasDraft = $article['draft_html'] !== null;
      $body = $hasDraft ? (string)$article['draft_html'] : (string)$article['body_html'];
      ?>
      <?php if ($hasDraft): ?>
        <div class="msg msg--warn">
          <b>Ezen a fejezeten van közzétételre váró vázlat.</b>
          Az alábbi szerkesztő a vázlatot mutatja — a nyilvános oldalon még a korábbi változat látszik.
          Mentve: <?= h(substr((string)$article['draft_at'], 0, 16)) ?>
        </div>
      <?php endif; ?>
      <?php if (!$article['is_published']): ?>
        <div class="msg msg--info">Ez a fejezet <b>nincs közzétéve</b>, a nyilvános oldalon nem jelenik meg.</div>
      <?php endif; ?>

      <?php
      // a fejezet osszes nyelvi valtozata (ugyanaz a slug), hogy nyelvenkent
      // lehessen ki-/bekapcsolni
      $sib = $db->prepare('SELECT id, lang, is_published, draft_html IS NOT NULL AS has_draft
                             FROM help_article WHERE slug = ? ORDER BY lang');
      $sib->execute([$article['slug']]);
      $siblings = [];
      foreach ($sib->fetchAll() as $r) { $siblings[$r['lang']] = $r; }
      $onCount = count(array_filter($siblings, static fn($r) => $r['is_published']));
      ?>
      <div class="panel" style="margin-bottom:14px">
        <div class="panel__h">
          <h2 style="font-size:calc(12.4px * var(--fs));text-transform:uppercase;letter-spacing:.6px;color:var(--ink-3)">
            Nyelvenkénti megjelenés</h2>
          <span class="sp"></span>
          <form method="post" action="<?= h(admin_url()) ?>" style="display:inline">
            <?= csrf_input() ?>
            <input type="hidden" name="a" value="article.toggle-all">
            <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
            <input type="hidden" name="on" value="<?= $onCount === count($siblings) ? '0' : '1' ?>">
            <button class="btn btn--sm" type="submit">
              <?= $onCount === count($siblings) ? 'Mindegyik kikapcsolása' : 'Mindegyik bekapcsolása' ?>
            </button>
          </form>
        </div>
        <div class="panel__b" style="display:flex;gap:10px;flex-wrap:wrap">
          <?php foreach (ADMIN_LANGS as $code => $label):
              $r = $siblings[$code] ?? null; ?>
            <div class="langcard<?= $r && $r['is_published'] ? ' on' : '' ?>">
 <div class="langcard__t mono muted"><?= h($label) ?> <span ><?= h($code) ?></span></div>
              <?php if (!$r): ?>
                <div class="muted" style="font-size:calc(12px * var(--fs))">nincs ilyen nyelvű változat</div>
                <a class="btn btn--sm" href="<?= h(admin_url(['p' => 'translate', 'to' => $code === 'hu' ? 'en' : $code,
                     'src' => $article['lang'] === 'hu' ? $article['id'] : 0])) ?>">Fordítás →</a>
              <?php else: ?>
                <form method="post" action="<?= h(admin_url()) ?>">
                  <?= csrf_input() ?>
                  <input type="hidden" name="a" value="article.toggle">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn--sm <?= $r['is_published'] ? 'btn--ok' : 'btn--danger' ?>" type="submit">
                    <?= $r['is_published'] ? '● Látszik' : '○ Kikapcsolva' ?>
                  </button>
                </form>
                <?php if ($r['has_draft']): ?><span class="badge badge--warn">vázlat</span><?php endif; ?>
                <?php if ((int)$r['id'] !== (int)$article['id']): ?>
                  <a class="btn btn--sm btn--ghost" href="<?= h(admin_url(['p' => 'articles', 'lang' => $code, 'id' => $r['id']])) ?>">Megnyitás</a>
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
                        ? 'Kikapcsolás: a fejezet eltűnik a nyilvános oldalról, a tartalma megmarad.'
                        : 'Bekapcsolás: a fejezet megjelenik a nyilvános oldalon.' ?>">
              <?= $article['is_published'] ? '● Közzétéve — kikapcsolom' : '○ Kikapcsolva — bekapcsolom' ?>
            </button>
          </form>
          <a class="btn btn--sm" href="/<?= h($article['lang']) ?>/<?= h($article['slug']) ?>" target="_blank" rel="noopener">Megnyitás a súgóban ↗</a>
          <button class="btn btn--sm" type="button" data-toggle="#meta-form">Adatok</button>
          <button class="btn btn--sm btn--danger" type="button" data-modal="del-article"
                  title="A fejezet a Kukába kerül, ahonnan visszaállítható">Törlés</button>
        </div>
        <div class="panel__b" id="meta-form" hidden>
          <form method="post" action="<?= h(admin_url()) ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="a" value="article.meta">
            <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
            <div class="row">
              <div class="field"><label>Fejezetszám</label>
                <input class="inp" name="chapter_no" value="<?= h($article['chapter_no']) ?>"></div>
              <div class="field" style="flex:3 1 300px"><label>Cím</label>
                <input class="inp" name="title" value="<?= h($article['title']) ?>" required></div>
            </div>
            <div class="row">
              <div class="field" style="flex:2 1 260px"><label>URL-azonosító (slug)</label>
                <input class="inp mono" name="slug" value="<?= h($article['slug']) ?>">
                <div class="hint">Ez lesz az URL: /<?= h($article['lang']) ?>/<b><?= h($article['slug']) ?></b></div></div>
              <div class="field"><label>Modul</label>
                <select class="sel" name="module_id">
                  <?php foreach ($modules as $m): ?>
                    <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === (int)$article['module_id'] ? 'selected' : '' ?>>
                      <?= h($m['chapter_no'] . ' ' . $m['title']) ?></option>
                  <?php endforeach; ?>
                </select></div>
              <div class="field" style="flex:0 1 120px"><label>Sorrend</label>
                <input class="inp" name="sort_order" type="number" value="<?= (int)$article['sort_order'] ?>"></div>
            </div>
            <div class="row">
              <div class="field"><label>Jogosultság (opcionális)</label>
                <input class="inp mono" name="permission" value="<?= h((string)$article['permission']) ?>" placeholder="pl. hr.view">
                <div class="hint">Ha ki van töltve, csak ezzel a joggal látható az Infinity-n belül.</div></div>
              <div class="field" style="align-self:center">
                <label class="check"><input type="checkbox" name="is_published" <?= $article['is_published'] ? 'checked' : '' ?>> Közzétéve</label>
              </div>
            </div>
            <div class="btnbar">
              <button class="btn btn--p" type="submit">Adatok mentése</button>
              <span class="sp" style="flex:1"></span>
              <button class="btn btn--danger btn--sm" type="button" data-modal="del-article">Fejezet törlése</button>
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
          <label for="ed-title-in">A fejezet címe</label>
          <input class="inp ed-title__in" id="ed-title-in" name="title" required
                 placeholder="Írd ide a fejezet címét"
                 value="<?= h((string)($article['draft_title'] ?? $article['title'])) ?>">
        </div>

        <?php
        // A szerkesztoben hasznalt betu- es kiemeloszinek. A kimenetet a
        // help_clean_style() ugyis ellenorzi, ez csak a kinalat.
        $edColors = [
            '#1f2a36' => 'Alap', '#0a6ed1' => 'Kék', '#107e3e' => 'Zöld',
            '#b8681a' => 'Narancs', '#bb0000' => 'Piros', '#6b21a8' => 'Lila',
            '#6b7a8d' => 'Szürke',
        ];
        $edMarks = ['#fff3a3' => 'Sárga', '#d6f2e0' => 'Zöld', '#fde2e2' => 'Piros', '#dceafd' => 'Kék'];
        ?>
        <?php editor_block((string)$article['chapter_no'], $body); ?>

        <div class="hint" style="margin-top:6px">
          Képet és videót a <b>Kép</b> / <b>Videó</b> gombbal tölthetsz fel — vagy egyszerűen
          <b>húzd rá a fájlt a szövegre</b>, illetve illeszd be vágólapról. A feltöltés azonnal
          megtörténik, nem kell előre a Képek fülre menni.
        </div>

        <div class="savebar">
          <button class="btn btn--p" type="submit" id="btn-save">Vázlat mentése</button>
          <span class="state" id="ed-state">A vázlat csak a szerkesztőben látszik, a nyilvános oldalon nem.</span>
          <span style="flex:1"></span>
          <?php if ($hasDraft): ?>
            <button class="btn btn--ghost btn--sm" type="submit" form="discard-form">Vázlat eldobása</button>
            <button class="btn btn--ok" type="button" data-modal="publish">Közzététel →</button>
          <?php else: ?>
            <span class="state">Közzétenni akkor lehet, ha van mentett vázlat.</span>
          <?php endif; ?>
        </div>
      </form>

      <form method="post" action="<?= h(admin_url()) ?>" id="discard-form" hidden>
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="article.discard">
        <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
      </form>

      <!-- korábbi változatok -->
      <div class="panel" style="margin-top:16px">
 <div class="panel__h sp"><h2>Korábbi változatok</h2><span ></span>
          <span class="badge"><?= count($revisions) ?></span></div>
        <div class="panel__b panel__b--flush">
          <?php if (!$revisions): ?>
            <div class="empty">Még nem volt közzététel ezen a fejezeten.</div>
          <?php else: ?>
            <table class="tbl">
              <thead><tr><th>#</th><th>Cím</th><th>Összefoglaló</th><th>Mikor</th><th>Ki</th><th></th></tr></thead>
              <tbody>
              <?php foreach ($revisions as $r): ?>
                <tr>
                  <td class="num" data-label="#"><?= (int)$r['rev_no'] ?></td>
                  <td data-label="Cím"><?= h($r['title']) ?></td>
                  <td class="muted" data-label="Összefoglaló"><?= h((string)$r['note']) ?></td>
                  <td class="nowrap muted" data-label="Mikor"><?= h(substr((string)$r['created_at'], 0, 16)) ?></td>
                  <td class="muted" data-label="Ki"><?= h((string)$r['display_name']) ?></td>
                  <td class="nowrap" data-label="">
                    <form method="post" action="<?= h(admin_url()) ?>" data-confirm="Betöltöd ezt a változatot vázlatként? A jelenlegi vázlat felülíródik.">
                      <?= csrf_input() ?>
                      <input type="hidden" name="a" value="article.restore">
                      <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
                      <input type="hidden" name="rev" value="<?= (int)$r['rev_no'] ?>">
                      <button class="btn btn--sm" type="submit">Visszatöltés</button>
                    </form>
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
            <div class="modal__h">Közzététel</div>
            <div class="modal__b">
              <p class="lead" style="margin-bottom:14px">A vázlat élesítése: ettől kezdve ez látszik a nyilvános oldalon.
                A korábbi változat megmarad, bármikor visszatölthető.</p>
              <div class="field">
                <label for="summary">Mi változott? (a Mi újság listába kerül)</label>
                <input class="inp" id="summary" name="summary" placeholder="pl. Frissített képernyőképek a kintlévőség-kezelésnél">
                <div class="hint">Üresen hagyva nem készül változásnapló-bejegyzés.</div>
              </div>
              <div class="row">
                <div class="field"><label for="kind">Típus</label>
                  <select class="sel" id="kind" name="kind">
                    <option value="mod">módosítás</option>
                    <option value="new">új tartalom</option>
                    <option value="fix">javítás</option>
                  </select></div>
                <div class="field" style="align-self:center">
                  <label class="check"><input type="checkbox" name="minor"> Apró javítás (ne jelenjen meg a Mi újságban)</label>
                </div>
              </div>
            </div>
            <div class="modal__f">
              <button class="btn btn--ghost" type="button" data-close>Mégsem</button>
              <button class="btn btn--ok" type="submit">Közzététel</button>
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
            <div class="modal__h">Fejezet törlése</div>
            <div class="modal__b">
              <div class="msg msg--err" style="margin:0">
                <b><?= h(trim($article['chapter_no'] . ' ' . $article['title'])) ?></b>
                A fejezet a <b>Kukába</b> kerül a korábbi változataival együtt — onnan
                egy kattintással visszaállítható, amíg ki nem üríted.
              </div>
            </div>
            <div class="modal__f">
              <button class="btn btn--ghost" type="button" data-close>Mégsem</button>
              <button class="btn btn--danger" type="submit">Végleges törlés</button>
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
    <div class="modal__h">Kijelölt fejezetek törlése</div>
    <div class="modal__b">
      <div class="msg msg--err" style="margin:0">
        <b><span id="bulk-del-count">0</span> fejezet</b> a <b>Kukába</b> kerül a korábbi
        változataival együtt — onnan egy kattintással visszaállítható, amíg ki nem üríted.
      </div>
    </div>
    <div class="modal__f">
      <button class="btn btn--ghost" type="button" data-close>Mégsem</button>
      <button class="btn btn--danger" type="button" id="bulk-del-ok">Törlés</button>
    </div>
  </div>
</div>

<!-- Új FŐFEJEZET (modul) közvetlenül a Fejezetek fülről -->
<div class="modal" id="modal-new-module-a">
  <div class="modal__box">
    <form method="post" action="<?= h(admin_url()) ?>">
      <?= csrf_input() ?>
      <input type="hidden" name="a" value="module.save">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">
      <input type="hidden" name="from" value="articles">
      <div class="modal__h">Új főfejezet (<?= h(ADMIN_LANGS[$lang]) ?>)</div>
      <div class="modal__b">
        <div class="row">
          <div class="field" style="flex:0 1 110px"><label>Szám</label>
            <input class="inp" name="chapter_no" value="<?= (int)$nextModuleNo ?>"></div>
          <div class="field" style="flex:3 1 240px"><label>Név</label>
            <input class="inp" name="title" placeholder="pl. Első lépések" required></div>
        </div>
        <div class="hint">A főfejezet a bal oldali lista vastag csoportcíme — ez alá kerülnek
          a fejezetek. A sorrendet utólag húzással is állíthatod.</div>
      </div>
      <div class="modal__f">
        <button class="btn btn--ghost" type="button" data-close>Mégsem</button>
        <button class="btn btn--p" type="submit">Létrehozás</button>
      </div>
    </form>
  </div>
</div>

<div class="modal" id="modal-new-article">
  <div class="modal__box">
    <form method="post" action="<?= h(admin_url()) ?>">
      <?= csrf_input() ?>
      <input type="hidden" name="a" value="article.create">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">
      <div class="modal__h">Új fejezet (<?= h(ADMIN_LANGS[$lang]) ?>)</div>
      <div class="modal__b">
        <div class="field"><label>Modul</label>
          <select class="sel" name="module_id" id="new-module" required>
            <?php foreach ($modules as $m): ?>
              <option value="<?= (int)$m['id'] ?>" data-next="<?= h((string)$m['next_no']) ?>"><?= h($m['chapter_no'] . ' ' . $m['title']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="row">
 <div class="field muted"><label>Fejezetszám <span >(üresen hagyva automatikus)</span></label>
            <input class="inp" name="chapter_no" id="new-chapter"
                   placeholder="<?= h((string)($modules[0]['next_no'] ?? '')) ?>"></div>
 <div class="field inp" style="flex:3 1 260px"><label>Cím</label><input name="title" required></div>
        </div>
        <div class="row">
 <div class="field inp mono"><label>URL-azonosító (üresen hagyva automatikus)</label><input name="slug"></div>
 <div class="field inp" style="flex:0 1 120px"><label>Sorrend</label><input name="sort_order" type="number" value="0"></div>
        </div>
      </div>
      <div class="modal__f">
        <button class="btn btn--ghost" type="button" data-close>Mégsem</button>
        <button class="btn btn--p" type="submit">Létrehozás</button>
      </div>
    </form>
  </div>
</div>
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
  <h1 class="pt">Modulok</h1>
  <p class="lead">A súgó felső szintje. A fejezetek ezekbe vannak besorolva, a sorrend itt állítható.
    Minden nyelvnek saját modulsora van — az összetartozást a fejezetszám köti össze.</p>
  <?= flash_render() ?>
  <div style="margin-bottom:14px"><?= lang_switch('modules', $lang) ?></div>

  <div class="panel">
 <div class="panel__h sp"><h2><?= h(ADMIN_LANGS[$lang]) ?> modulok</h2><span ></span>
      <button class="btn btn--p btn--sm" type="button" data-modal="new-module">+ Új modul</button></div>
    <div class="panel__b panel__b--flush">
      <div class="hint" style="padding:10px 16px 0">
        A sorrendet a sor eleji <b>⠿</b> fogantyúval húzva is átrendezheted — a mentés automatikus.
      </div>
      <?php foreach ($modules as $m): ?>
        <form method="post" action="<?= h(admin_url()) ?>" id="mf<?= (int)$m['id'] ?>">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="a" value="module.save">
          <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
          <input type="hidden" name="lang" value="<?= h($lang) ?>">
        </form>
      <?php endforeach; ?>
      <table class="tbl tbl--sortable" data-sort-action="modules.reorder">
        <thead><tr><th style="width:34px"></th><th style="width:90px">Sorrend</th><th style="width:90px">Szám</th><th>Név</th><th>URL-azonosító</th><th class="num">Fejezet</th><th></th></tr></thead>
        <tbody id="mod-sort">
        <?php foreach ($modules as $m): ?>
          <tr data-id="<?= (int)$m['id'] ?>">
 <td class="grip picker__grip" data-label=""><span title="Húzd az átrendezéshez">⠿</span></td>
            <td data-label="Sorrend"><input class="inp" form="mf<?= (int)$m['id'] ?>" name="sort_order" type="number" value="<?= (int)$m['sort_order'] ?>" style="width:74px"></td>
            <td data-label="Szám"><input class="inp" form="mf<?= (int)$m['id'] ?>" name="chapter_no" value="<?= h($m['chapter_no']) ?>" style="width:74px"></td>
            <td data-label="Név"><input class="inp" form="mf<?= (int)$m['id'] ?>" name="title" value="<?= h($m['title']) ?>"></td>
            <td data-label="URL-azonosító"><input class="inp mono" form="mf<?= (int)$m['id'] ?>" name="slug" value="<?= h($m['slug']) ?>"></td>
            <td class="num" data-label="Fejezet"><?= (int)$m['n'] ?></td>
            <td class="nowrap" data-label="">
              <button class="btn btn--sm btn--p" form="mf<?= (int)$m['id'] ?>" type="submit">Mentés</button>
              <?php if (true): ?>
                <form method="post" action="<?= h(admin_url()) ?>" style="display:inline"
                      data-confirm="Törlöd ezt a főfejezetet? Mind a három nyelven a Kukába kerül, ahonnan visszaállítható. Csak akkor sikerül, ha egyetlen nyelven sincs benne fejezet.">
                  <?= csrf_input() ?>
                  <input type="hidden" name="a" value="module.delete">
                  <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                  <input type="hidden" name="lang" value="<?= h($lang) ?>">
                  <button class="btn btn--sm btn--danger" type="submit">Törlés</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!$modules): ?><div class="empty">Ezen a nyelven még nincs modul.</div><?php endif; ?>
    </div>
  </div>
</div>

<div class="modal" id="modal-new-module">
  <div class="modal__box">
    <form method="post" action="<?= h(admin_url()) ?>">
      <?= csrf_input() ?>
      <input type="hidden" name="a" value="module.save">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">
      <div class="modal__h">Új modul (<?= h(ADMIN_LANGS[$lang]) ?>)</div>
      <div class="modal__b">
        <div class="row">
 <div class="field inp"><label>Szám</label><input name="chapter_no" placeholder="18"></div>
 <div class="field inp" style="flex:3 1 240px"><label>Név</label><input name="title" required></div>
        </div>
        <div class="row">
 <div class="field inp mono"><label>URL-azonosító (automatikus, ha üres)</label><input name="slug"></div>
 <div class="field inp" style="flex:0 1 120px"><label>Sorrend</label><input name="sort_order" type="number" value="0"></div>
        </div>
      </div>
      <div class="modal__f">
        <button class="btn btn--ghost" type="button" data-close>Mégsem</button>
        <button class="btn btn--p" type="submit">Létrehozás</button>
      </div>
    </form>
  </div>
</div>
    <?php
    admin_foot();
}
