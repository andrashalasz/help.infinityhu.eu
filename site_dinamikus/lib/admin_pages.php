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
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/admin.css">
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
  <div class="panel"><div class="panel__b">
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
        <tr><td class="muted">Szerepkör</td><td><span class="badge badge--info"><?= h($me['role']) ?></span></td></tr>
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
          (SELECT count(*) FROM help_article WHERE NOT is_published)             AS hidden,
          (SELECT count(*) FROM help_module)                                     AS modules,
          (SELECT count(*) FROM help_screen_map)                                 AS screens,
          (SELECT count(*) FROM help_media)                                      AS media
    ")->fetch();

    $stale = (int)$db->query("
        SELECT count(*) FROM help_article t
          JOIN help_article s ON s.slug = t.slug AND s.lang = 'hu'
         WHERE t.lang <> 'hu'
           AND (t.translated_from_hash IS NULL OR t.translated_from_hash <> s.content_hash)
    ")->fetchColumn();

    $missing = (int)$db->query("
        SELECT count(*) FROM help_article s
         CROSS JOIN (VALUES ('en'),('de')) AS l(lang)
         WHERE s.lang = 'hu'
           AND NOT EXISTS (SELECT 1 FROM help_article t WHERE t.slug = s.slug AND t.lang = l.lang)
    ")->fetchColumn();

    $drafts = $db->query("
        SELECT a.id, a.lang, a.chapter_no, a.title, a.draft_at, u.display_name
          FROM help_article a LEFT JOIN help_user u ON u.id = a.draft_by
         WHERE a.draft_html IS NOT NULL
      ORDER BY a.draft_at DESC NULLS LAST LIMIT 12")->fetchAll();

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

  <div class="stats">
    <div class="stat"><div class="stat__n"><?= (int)$stats['articles'] ?></div><div class="stat__l">fejezet (3 nyelven)</div></div>
    <div class="stat <?= (int)$stats['drafts'] ? 'stat--warn' : '' ?>">
      <div class="stat__n"><?= (int)$stats['drafts'] ?></div><div class="stat__l">közzétételre váró vázlat</div></div>
    <div class="stat <?= $stale ? 'stat--warn' : '' ?>"><div class="stat__n"><?= $stale ?></div><div class="stat__l">elavult fordítás</div></div>
    <div class="stat <?= $missing ? 'stat--err' : '' ?>"><div class="stat__n"><?= $missing ?></div><div class="stat__l">hiányzó fordítás</div></div>
    <div class="stat"><div class="stat__n"><?= (int)$stats['modules'] ?></div><div class="stat__l">modul</div></div>
    <div class="stat"><div class="stat__n"><?= (int)$stats['screens'] ?></div><div class="stat__l">képernyő-hozzárendelés</div></div>
  </div>

  <div class="page--split" style="padding:0">
    <div>
      <div class="panel" style="margin-bottom:16px">
        <div class="panel__h"><h2>Közzétételre vár</h2><span class="sp"></span>
          <span class="badge"><?= count($drafts) ?></span></div>
        <div class="panel__b panel__b--flush">
          <?php if (!$drafts): ?>
            <div class="empty">Nincs nyitott vázlat — minden közzé van téve.</div>
          <?php else: ?>
            <table class="tbl">
              <?php foreach ($drafts as $d): ?>
                <tr>
                  <td class="nowrap muted mono" data-label="Fejezet"><?= h($d['lang']) ?> · <?= h($d['chapter_no']) ?></td>
                  <td data-label="Cím"><a href="<?= h(admin_url(['p' => 'articles', 'lang' => $d['lang'], 'id' => $d['id']])) ?>"><?= h($d['title']) ?></a></td>
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
                  <td class="nowrap" data-label="Állapot"><span class="badge <?= $i['status'] === 'applied' ? 'badge--ok' : '' ?>"><?= h($i['status']) ?></span></td>
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
function page_articles(PDO $db, string $lang, int $id, array $counts): void
{
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

    $mods = $db->prepare('SELECT id, chapter_no, title FROM help_module WHERE lang = ? ORDER BY sort_order, id');
    $mods->execute([$lang]);
    $modules = $mods->fetchAll();

    admin_head('Fejezetek', 'articles', $counts);
    ?>
<div class="page page--split">

  <!-- bal: fejezetválasztó -->
  <div class="panel picker" id="picker">
    <div class="picker__f">
      <input class="inp" id="pick-filter" placeholder="Szűrés…  (Ctrl+K a kereséshez)" autocomplete="off">
      <button class="btn btn--sm" type="button" id="pick-select" title="Több fejezet kijelölése">☑</button>
      <button class="btn btn--sm" type="button" id="pick-sort" title="Sorrend átrendezése húzással">↕</button>
      <button class="btn btn--p btn--sm" type="button" data-modal="new-article" title="Új fejezet">+</button>
    </div>
    <div style="padding:8px 10px 0"><?= lang_switch('articles', $lang) ?></div>

    <div class="picker__hint" id="pick-hint" hidden></div>

    <div class="picker__l" id="pick-list">
      <?php foreach ($tree as $m): ?>
        <div class="picker__m" data-module="<?= (int)$m['id'] ?>">
          <label class="picker__mchk"><input type="checkbox" class="pick-mod-all" tabindex="-1"></label>
          <?= h($m['chapter_no']) ?> <?= h($m['title']) ?>
        </div>
        <div class="picker__group" data-module="<?= (int)$m['id'] ?>">
        <?php foreach ($m['articles'] as $a): ?>
          <div class="picker__row" data-id="<?= (int)$a['id'] ?>">
            <label class="picker__chk"><input type="checkbox" class="pick-one" value="<?= (int)$a['id'] ?>" tabindex="-1"></label>
            <span class="picker__grip" title="Húzd a sorrend átrendezéséhez" aria-hidden="true">⠿</span>
            <a class="picker__a<?= $article && (int)$a['id'] === (int)$article['id'] ? ' on' : '' ?>"
               href="<?= h(admin_url(['p' => 'articles', 'lang' => $lang, 'id' => $a['id']])) ?>">
              <em><?= h($a['chapter_no']) ?></em><span><?= h($a['title']) ?></span>
              <?php if (!$a['is_published']): ?><span class="dot dot--hidden" title="Kikapcsolva – nem látszik a nyilvános oldalon"></span><?php endif; ?>
              <?php if ($a['has_draft']): ?><span class="dot dot--draft" title="Van közzétételre váró vázlat"></span><?php endif; ?>
            </a>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$tree): ?><div class="empty">Ezen a nyelven még nincs modul.</div><?php endif; ?>
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
        <button class="btn btn--sm btn--danger" type="submit" data-op="delete"
                data-confirm="A kijelölt fejezetek a Kukába kerülnek, ahonnan visszaállíthatók. Folytatod?">Törlés</button>
        <button class="btn btn--sm btn--ghost" type="button" id="bulk-cancel">Mégsem</button>
      </div>
    </form>
  </div>

  <!-- jobb: szerkesztő -->
  <div>
    <?= flash_render() ?>

    <?php if (!$article): ?>
      <div class="panel"><div class="empty">
        Válassz egy fejezetet a bal oldali listából — vagy hozz létre újat a <b>+</b> gombbal.
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
              <div class="langcard__t"><?= h($label) ?> <span class="mono muted"><?= h($code) ?></span></div>
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
          <h2><?= h($article['chapter_no']) ?> <?= h($article['title']) ?></h2>
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
        <input type="hidden" name="title" value="<?= h((string)($article['draft_title'] ?? $article['title'])) ?>">

        <div class="ed" id="ed">
          <div class="ed-toolbar">
            <button type="button" data-cmd="bold" title="Félkövér (Ctrl+B)"><b>F</b></button>
            <button type="button" data-cmd="italic" title="Dőlt (Ctrl+I)"><i>D</i></button>
            <button type="button" data-cmd="underline" title="Aláhúzott"><u>A</u></button>
            <span class="divider"></span>
            <button type="button" data-block="h2" title="Címsor 2">H2</button>
            <button type="button" data-block="h3" title="Címsor 3">H3</button>
            <button type="button" data-block="p" title="Bekezdés">¶</button>
            <span class="divider"></span>
            <button type="button" data-cmd="insertUnorderedList" title="Felsorolás">• lista</button>
            <button type="button" data-cmd="insertOrderedList" title="Számozott lista">1. lista</button>
            <span class="divider"></span>
            <button type="button" data-act="link" title="Hivatkozás">🔗</button>
            <button type="button" data-act="upload-image" title="Kép feltöltése és beszúrása">🖼 Kép</button>
            <button type="button" data-act="upload-video" title="Videó feltöltése és beszúrása">🎬 Videó</button>
            <button type="button" data-act="callout-tip" title="Tipp doboz">Tipp</button>
            <button type="button" data-act="callout-warn" title="Figyelmeztetés doboz">Figyelem</button>
            <span class="divider"></span>
            <button type="button" data-cmd="removeFormat" title="Formázás törlése">Tiszta</button>
            <span class="sp"></span>
            <button type="button" id="ed-source" title="HTML forrás mutatása">&lt;/&gt; HTML</button>
          </div>
          <div class="ed-area body" id="ed-area" contenteditable="true" spellcheck="true"><?= fix_img_url($body) ?></div>
          <textarea class="ta ed-src" id="ed-src" name="body"></textarea>
        </div>

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
        <div class="panel__h"><h2>Korábbi változatok</h2><span class="sp"></span>
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
                    <form method="post" action="<?= h(admin_url()) ?>" onsubmit="return confirm('Betöltöd ezt a változatot vázlatként? A jelenlegi vázlat felülíródik.')">
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
                <b><?= h($article['chapter_no'] . ' ' . $article['title']) ?></b>
                Ez a fejezet és minden korábbi változata véglegesen törlődik. A művelet nem vonható vissza.
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
<div class="modal" id="modal-new-article">
  <div class="modal__box">
    <form method="post" action="<?= h(admin_url()) ?>">
      <?= csrf_input() ?>
      <input type="hidden" name="a" value="article.create">
      <input type="hidden" name="lang" value="<?= h($lang) ?>">
      <div class="modal__h">Új fejezet (<?= h(ADMIN_LANGS[$lang]) ?>)</div>
      <div class="modal__b">
        <div class="field"><label>Modul</label>
          <select class="sel" name="module_id" required>
            <?php foreach ($modules as $m): ?>
              <option value="<?= (int)$m['id'] ?>"><?= h($m['chapter_no'] . ' ' . $m['title']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="row">
          <div class="field"><label>Fejezetszám</label><input class="inp" name="chapter_no" placeholder="5.5"></div>
          <div class="field" style="flex:3 1 260px"><label>Cím</label><input class="inp" name="title" required></div>
        </div>
        <div class="row">
          <div class="field"><label>URL-azonosító (üresen hagyva automatikus)</label><input class="inp mono" name="slug"></div>
          <div class="field" style="flex:0 1 120px"><label>Sorrend</label><input class="inp" name="sort_order" type="number" value="0"></div>
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
    <div class="panel__h"><h2><?= h(ADMIN_LANGS[$lang]) ?> modulok</h2><span class="sp"></span>
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
            <td class="grip" data-label=""><span class="picker__grip" title="Húzd az átrendezéshez">⠿</span></td>
            <td data-label="Sorrend"><input class="inp" form="mf<?= (int)$m['id'] ?>" name="sort_order" type="number" value="<?= (int)$m['sort_order'] ?>" style="width:74px"></td>
            <td data-label="Szám"><input class="inp" form="mf<?= (int)$m['id'] ?>" name="chapter_no" value="<?= h($m['chapter_no']) ?>" style="width:74px"></td>
            <td data-label="Név"><input class="inp" form="mf<?= (int)$m['id'] ?>" name="title" value="<?= h($m['title']) ?>"></td>
            <td data-label="URL-azonosító"><input class="inp mono" form="mf<?= (int)$m['id'] ?>" name="slug" value="<?= h($m['slug']) ?>"></td>
            <td class="num" data-label="Fejezet"><?= (int)$m['n'] ?></td>
            <td class="nowrap" data-label="">
              <button class="btn btn--sm btn--p" form="mf<?= (int)$m['id'] ?>" type="submit">Mentés</button>
              <?php if ((int)$m['n'] === 0): ?>
                <form method="post" action="<?= h(admin_url()) ?>" style="display:inline"
                      onsubmit="return confirm('Biztosan törlöd ezt az üres modult?')">
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
          <div class="field"><label>Szám</label><input class="inp" name="chapter_no" placeholder="18"></div>
          <div class="field" style="flex:3 1 240px"><label>Név</label><input class="inp" name="title" required></div>
        </div>
        <div class="row">
          <div class="field"><label>URL-azonosító (automatikus, ha üres)</label><input class="inp mono" name="slug"></div>
          <div class="field" style="flex:0 1 120px"><label>Sorrend</label><input class="inp" name="sort_order" type="number" value="0"></div>
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
