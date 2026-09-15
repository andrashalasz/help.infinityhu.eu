<?php
/**
 * admin_pages2.php - Word import, Fordítás, Képernyők, Képek, Felhasználók, Beállítások.
 */
declare(strict_types=1);

// ============================================================ WORD IMPORT
function page_import(PDO $db, array $cfg, string $lang, int $importId, array $counts): void
{
    $import = null;
    $items  = [];
    if ($importId > 0) {
        $st = $db->prepare('SELECT i.*, u.display_name FROM help_import i LEFT JOIN help_user u ON u.id = i.uploaded_by WHERE i.id = ?');
        $st->execute([$importId]);
        $import = $st->fetch() ?: null;
        if ($import) {
            $lang = (string)$import['lang'];
            $st = $db->prepare('SELECT it.*, a.plain_text AS cur_plain, a.title AS cur_title
                                  FROM help_import_item it
                                  LEFT JOIN help_article a ON a.id = it.article_id
                                 WHERE it.import_id = ? ORDER BY it.seq');
            $st->execute([$importId]);
            $items = $st->fetchAll();
        }
    }

    $recent = $db->query('SELECT i.*, u.display_name FROM help_import i LEFT JOIN help_user u ON u.id = i.uploaded_by
                       ORDER BY i.uploaded_at DESC LIMIT 10')->fetchAll();

    admin_head('Word import', 'import', $counts);
    ?>
<div class="page">
  <h1 class="pt">Word import</h1>
  <p class="lead">
    Tölts fel egy <b>.docx</b> fájlt: a rendszer fejezetekre bontja, a képeket kibontja, és
    <b>összehasonlítja a jelenlegi tartalommal</b>. Te döntöd el fejezetenként, mit veszek át.
    Ami átkerül, az <b>vázlat</b> lesz — a nyilvános oldalon csak közzététel után látszik.
  </p>
  <?= flash_render() ?>

  <div class="panel" style="margin-bottom:16px">
    <div class="panel__h"><h2>Új dokumentum betöltése</h2></div>
    <div class="panel__b">
      <form method="post" action="<?= h(admin_url()) ?>" enctype="multipart/form-data">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="import.upload">
        <div class="row">
          <div class="field" style="flex:0 1 200px">
            <label for="imp-lang">Melyik nyelvhez</label>
            <select class="sel" id="imp-lang" name="lang">
              <?php foreach (ADMIN_LANGS as $code => $label): ?>
                <option value="<?= h($code) ?>" <?= $code === $lang ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="flex:2 1 320px">
            <label for="docx">Word-fájl (.docx)</label>
            <input class="inp" id="docx" name="docx" type="file" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
          </div>
          <div class="field" style="flex:0 1 auto; align-self:flex-end">
            <button class="btn btn--p" type="submit">Beolvasás</button>
          </div>
        </div>
        <div class="hint">
          A tagolás a <b>címsorstílusokból</b> jön: <b>Címsor 1</b> = modul, <b>Címsor 2</b> = fejezet,
          <b>Címsor 3–4</b> = a fejezeten belüli szakaszok. A fejezetszámot a címből olvassa ki („5.4 Kintlévőség kezelés”),
          és ez alapján párosítja a meglévő fejezethez. A képek a tartalmuk hash-ével kapnak nevet, így nem duplikálódnak.
        </div>
      </form>
    </div>
  </div>

  <?php if ($import):
      $stats = json_decode((string)$import['stats'], true) ?: [];
      $nNew = $nMod = $nSame = 0;
      foreach ($items as $it) {
          if ($it['match_state'] === 'new') { $nNew++; }
          elseif ($it['match_state'] === 'same') { $nSame++; }
          else { $nMod++; }
      }
  ?>
  <div class="panel">
    <div class="panel__h">
      <h2><?= h($import['filename']) ?></h2>
      <span class="badge badge--info"><?= h($import['lang']) ?></span>
      <span class="badge"><?= help_bytes((int)$import['bytes']) ?></span>
      <span class="badge <?= $import['status'] === 'applied' ? 'badge--ok' : '' ?>"><?= h($import['status']) ?></span>
      <span class="sp"></span>
      <span class="muted"><?= h(substr((string)$import['uploaded_at'], 0, 16)) ?> · <?= h((string)$import['display_name']) ?></span>
    </div>

    <div class="panel__b">
      <div class="stats" style="margin-bottom:0">
        <div class="stat"><div class="stat__n"><?= count($items) ?></div><div class="stat__l">fejezet a dokumentumban</div></div>
        <div class="stat stat--warn"><div class="stat__n"><?= $nMod ?></div><div class="stat__l">eltér a mostanitól</div></div>
        <div class="stat"><div class="stat__n"><?= $nSame ?></div><div class="stat__l">változatlan</div></div>
        <div class="stat"><div class="stat__n"><?= $nNew ?></div><div class="stat__l">új fejezet</div></div>
        <div class="stat"><div class="stat__n"><?= (int)($stats['images'] ?? 0) ?></div><div class="stat__l">új kép kibontva</div></div>
      </div>
    </div>

    <form method="post" action="<?= h(admin_url()) ?>" id="imp-form">
      <?= csrf_input() ?>
      <input type="hidden" name="a" value="import.apply">
      <input type="hidden" name="import_id" value="<?= (int)$import['id'] ?>">

      <div class="panel__h" style="border-top:1px solid var(--line-soft)">
        <button class="btn btn--sm" type="button" id="imp-all">Eltérők kijelölése</button>
        <button class="btn btn--sm" type="button" id="imp-none">Kijelölés törlése</button>
        <span class="sp"></span>
        <label class="check"><input type="checkbox" name="publish_now"> Átvétel után rögtön közzé is teszem</label>
        <button class="btn btn--p" type="submit">Kijelöltek átvétele</button>
      </div>

      <div class="panel__b panel__b--flush">
        <div class="diff-legend" style="padding:10px 14px 0">
          <span><i style="background:var(--err-soft);color:var(--err)">– törölt</i></span>
          <span><i style="background:var(--ok-soft);color:var(--ok)">+ új</i></span>
          <span class="muted">a jelenlegi közzétett szöveghez képest</span>
        </div>

        <?php foreach ($items as $it):
            $state = $it['match_state'];
            $badge = match ($state) {
                'new'  => '<span class="badge badge--info">új fejezet</span>',
                'same' => '<span class="badge">változatlan</span>',
                default => '<span class="badge badge--warn">' . number_format((float)$it['similarity'], 1, ',', ' ') . '% egyezés</span>',
            };
        ?>
          <div class="imp-item<?= $it['applied'] ? ' applied' : '' ?>" data-state="<?= h($state) ?>">
            <label class="imp-head">
              <input type="checkbox" name="items[]" value="<?= (int)$it['id'] ?>"
                     <?= $state === 'same' || $it['applied'] ? '' : 'checked' ?>>
              <span class="t"><em><?= h($it['chapter_no']) ?></em><?= h($it['title']) ?></span>
              <?php if ((int)$it['img_count']): ?><span class="badge"><?= (int)$it['img_count'] ?> kép</span><?php endif; ?>
              <?= $badge ?>
              <?php if ($it['applied']): ?><span class="badge badge--ok">átvéve</span><?php endif; ?>
              <button class="btn btn--sm btn--ghost" type="button" data-imp-toggle>Összehasonlítás</button>
            </label>
            <div class="imp-body">
              <?php
              if ($state === 'new') {
                  echo '<div class="msg msg--info" style="margin-bottom:10px">Ez a fejezet még nincs az adatbázisban — '
                     . 'átvételkor létrejön (rejtett vázlatként, amíg közzé nem teszed).</div>';
                  echo '<div class="diff">' . htmlspecialchars(mb_substr((string)$it['plain_text'], 0, 4000), ENT_QUOTES, 'UTF-8') . '</div>';
              } else {
                  $d = diff_html((string)$it['cur_plain'], (string)$it['plain_text']);
                  echo '<div class="hint" style="margin-bottom:8px">'
                     . '<b>' . $d['added'] . '</b> új szó, <b>' . $d['removed'] . '</b> elhagyott szó'
                     . ($it['cur_title'] !== $it['title']
                        ? ' · a cím is változik: „' . h((string)$it['cur_title']) . '” → „' . h((string)$it['title']) . '”'
                        : '')
                     . '</div>';
                  echo '<div class="diff">' . ($d['changed'] ? $d['html'] : '<span class="muted">A szöveg szó szerint megegyezik a mostanival.</span>') . '</div>';
              }
              ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </form>

    <div class="panel__h" style="border-top:1px solid var(--line-soft)">
      <span class="sp"></span>
      <form method="post" action="<?= h(admin_url()) ?>" onsubmit="return confirm('Eldobod ezt az importot? A fejezetek érintetlenek maradnak.')">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="import.discard">
        <input type="hidden" name="import_id" value="<?= (int)$import['id'] ?>">
        <button class="btn btn--sm btn--danger" type="submit">Import eldobása</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($recent): ?>
  <div class="panel" style="margin-top:16px">
    <div class="panel__h"><h2>Korábbi importok</h2></div>
    <div class="panel__b panel__b--flush">
      <table class="tbl">
        <thead><tr><th>Fájl</th><th>Nyelv</th><th>Tartalom</th><th>Állapot</th><th>Mikor</th><th>Ki</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): $s = json_decode((string)$r['stats'], true) ?: []; ?>
          <tr>
            <td data-label="Fájl"><a href="<?= h(admin_url(['p' => 'import', 'import' => $r['id']])) ?>"><?= h($r['filename']) ?></a></td>
            <td data-label="Nyelv"><span class="badge"><?= h($r['lang']) ?></span></td>
            <td class="muted" data-label="Tartalom"><?= (int)($s['chapters'] ?? 0) ?> fejezet · <?= (int)($s['images'] ?? 0) ?> kép</td>
            <td data-label="Állapot"><span class="badge <?= $r['status'] === 'applied' ? 'badge--ok' : '' ?>"><?= h($r['status']) ?></span></td>
            <td class="nowrap muted" data-label="Mikor"><?= h(substr((string)$r['uploaded_at'], 0, 16)) ?></td>
            <td class="muted" data-label="Ki"><?= h((string)$r['display_name']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
    <?php
    admin_foot();
}

// ============================================================ FORDÍTÁS
function page_translate(PDO $db, array $cfg, int $srcId, string $to, array $counts): void
{
    $tr = Translator::fromConfig($cfg, $db);
    if (!array_key_exists($to, ADMIN_LANGS) || $to === 'hu') { $to = 'en'; }

    $src = null; $target = null;
    if ($srcId > 0) {
        $st = $db->prepare("SELECT a.*, m.title AS module_title FROM help_article a
                              LEFT JOIN help_module m ON m.id = a.module_id
                             WHERE a.id = ? AND a.lang = 'hu'");
        $st->execute([$srcId]);
        $src = $st->fetch() ?: null;
        if ($src) {
            $t = $db->prepare('SELECT * FROM help_article WHERE slug = ? AND lang = ?');
            $t->execute([$src['slug'], $to]);
            $target = $t->fetch() ?: null;
        }
    }

    // a teljes HU lista a celnyelvi allapottal
    $rows = $db->prepare("
        SELECT s.id, s.chapter_no, s.title, s.content_hash, m.title AS module_title, m.chapter_no AS module_no,
               t.id AS t_id, t.title AS t_title, t.translated_from_hash, t.translated_by,
               t.draft_html IS NOT NULL AS t_draft, t.is_published AS t_published
          FROM help_article s
          LEFT JOIN help_module m ON m.id = s.module_id
          LEFT JOIN help_article t ON t.slug = s.slug AND t.lang = ?
         WHERE s.lang = 'hu'
      ORDER BY m.sort_order, s.sort_order, s.id");
    $rows->execute([$to]);
    $list = $rows->fetchAll();

    $stateOf = static function (array $r): array {
        if (!$r['t_id'])                                        { return ['missing', 'badge--err',  'hiányzik']; }
        if ($r['translated_from_hash'] !== $r['content_hash'])   { return ['stale',   'badge--warn', 'elavult']; }
        if ($r['t_draft'])                                      { return ['draft',   'badge--info', 'vázlat']; }
        return ['ok', 'badge--ok', 'naprakész'];
    };

    admin_head('Fordítás', 'translate', $counts);
    ?>
<div class="page">
  <h1 class="pt">Fordítás</h1>
  <p class="lead">
    A magyar a forrásnyelv. Válaszd ki a fejezetet, és írd meg mellé az idegen nyelvű változatot —
    vagy kérj gépi nyersfordítást, és javíts bele. A mentés <b>vázlatot</b> készít, közzétenni külön kell.
  </p>
  <?= flash_render() ?>

  <div class="panel" style="margin-bottom:14px"><div class="panel__b" style="display:flex;gap:14px;flex-wrap:wrap;align-items:center">
    <div>
      <span class="lbl" style="display:inline">Célnyelv:</span>
      <?php foreach (['en', 'de'] as $code): ?>
        <a class="btn btn--sm <?= $to === $code ? 'btn--p' : '' ?>"
           href="<?= h(admin_url(['p' => 'translate', 'to' => $code] + ($srcId ? ['src' => $srcId] : []))) ?>">
          <?= h(ADMIN_LANGS[$code]) ?></a>
      <?php endforeach; ?>
    </div>
    <span style="flex:1"></span>
    <div class="muted">
      Gépi fordító: <b><?= h($tr->label()) ?></b>
      <?php if (!$tr->isConfigured()): ?>
        — <a href="<?= h(admin_url(['p' => 'settings'])) ?>">beállítás</a>. Enélkül a kézi fordítás működik.
      <?php endif; ?>
    </div>
  </div></div>

  <div class="page--split" style="padding:0">
    <!-- fejezetlista -->
    <div class="panel picker">
      <div class="picker__f"><input class="inp" id="pick-filter" placeholder="Szűrés…" autocomplete="off"></div>
      <div class="picker__l" id="pick-list">
        <?php
        $curModule = null;
        foreach ($list as $r):
            if ($curModule !== $r['module_title']) {
                $curModule = $r['module_title'];
                echo '<div class="picker__m">' . h((string)$r['module_no']) . ' ' . h((string)$curModule) . '</div>';
            }
            [$state, $cls, $label] = $stateOf($r);
        ?>
          <a class="picker__a<?= (int)$r['id'] === $srcId ? ' on' : '' ?>"
             href="<?= h(admin_url(['p' => 'translate', 'to' => $to, 'src' => $r['id']])) ?>">
            <em><?= h($r['chapter_no']) ?></em><span><?= h($r['title']) ?></span>
            <span class="badge <?= $cls ?>" style="margin-left:auto"><?= h($label) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- szerkesztő -->
    <div>
      <?php if (!$src): ?>
        <div class="panel"><div class="empty">Válassz egy fejezetet a bal oldali listából.</div></div>
      <?php else:
        $targetBody = $target
            ? (string)($target['draft_html'] ?? $target['body_html'])
            : '';
        $targetTitle = $target ? (string)($target['draft_title'] ?? $target['title']) : '';
        $isStale = $target && $target['translated_from_hash'] !== $src['content_hash'];
      ?>
        <?php if ($target && $isStale): ?>
          <div class="msg msg--warn"><b>A magyar változat módosult a fordítás óta.</b>
            Érdemes átnézni, mi változott, és frissíteni ezt a nyelvet is.</div>
        <?php elseif (!$target): ?>
          <div class="msg msg--info"><b>Ehhez a fejezethez még nincs <?= h(ADMIN_LANGS[$to]) ?> változat.</b>
            Mentéskor létrejön, ugyanazzal az URL-azonosítóval.</div>
        <?php endif; ?>

        <form method="post" action="<?= h(admin_url()) ?>" id="tr-form">
          <?= csrf_input() ?>
          <input type="hidden" name="a" value="translate.save">
          <input type="hidden" name="src_id" value="<?= (int)$src['id'] ?>">
          <input type="hidden" name="to" value="<?= h($to) ?>">
          <input type="hidden" name="how" id="tr-how" value="manual">

          <div class="tr-grid">
            <!-- forrás -->
            <div class="panel">
              <div class="panel__h"><h2>Magyar (forrás)</h2><span class="sp"></span>
                <span class="badge"><?= h($src['chapter_no']) ?></span></div>
              <div class="panel__b">
                <div class="field"><label>Cím</label>
                  <input class="inp" value="<?= h($src['title']) ?>" readonly></div>
                <div class="tr-src body"><?= fix_img_url((string)$src['body_html']) ?></div>
              </div>
            </div>

            <!-- cél -->
            <div class="panel">
              <div class="panel__h"><h2><?= h(ADMIN_LANGS[$to]) ?> (fordítás)</h2><span class="sp"></span>
                <button class="btn btn--sm" type="button" id="tr-machine"
                        <?= $tr->isConfigured() ? '' : 'disabled title="Nincs beállítva gépi fordító"' ?>>
                  Gépi nyersfordítás
                </button>
                <form method="post" action="<?= h(admin_url()) ?>" style="display:inline">
                  <?= csrf_input() ?>
                  <input type="hidden" name="a" value="translate.auto">
                  <input type="hidden" name="src_id" value="<?= (int)$src['id'] ?>">
                  <button class="btn btn--sm" type="submit"
                          <?= $tr->isConfigured() ? '' : 'disabled title="Nincs beállítva gépi fordító"' ?>
                          title="Angol és német vázlat egyszerre">EN + DE egyben</button>
                </form>
              </div>
              <div class="panel__b">
                <div class="field"><label>Cím</label>
                  <input class="inp" name="title" id="tr-title" value="<?= h($targetTitle) ?>"></div>
                <div class="ed" id="ed">
                  <div class="ed-toolbar">
                    <button type="button" data-cmd="bold"><b>F</b></button>
                    <button type="button" data-cmd="italic"><i>D</i></button>
                    <button type="button" data-block="h2">H2</button>
                    <button type="button" data-block="h3">H3</button>
                    <button type="button" data-block="p">¶</button>
                    <button type="button" data-cmd="insertUnorderedList">•</button>
                    <button type="button" data-cmd="insertOrderedList">1.</button>
                    <span class="sp"></span>
                    <button type="button" id="ed-source">&lt;/&gt; HTML</button>
                  </div>
                  <div class="ed-area body" id="ed-area" contenteditable="true" style="min-height:340px"><?= fix_img_url($targetBody) ?></div>
                  <textarea class="ta ed-src" id="ed-src" name="body"></textarea>
                </div>
              </div>
            </div>
          </div>

          <div class="savebar">
            <button class="btn btn--p" type="submit">Fordítás mentése vázlatként</button>
            <label class="check"><input type="checkbox" name="publish_now"> Mentés után közzététel is</label>
            <span style="flex:1"></span>
            <?php if ($target): ?>
              <a class="btn btn--sm" href="<?= h(admin_url(['p' => 'articles', 'lang' => $to, 'id' => $target['id']])) ?>">Megnyitás a Fejezetek fülön</a>
            <?php endif; ?>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
    <?php
    admin_foot();
}

// ============================================================ KÉPERNYŐK
function page_screens(PDO $db, array $counts): void
{
    $rows = $db->query("SELECT s.*, a.chapter_no, a.title, a.lang, a.slug
                          FROM help_screen_map s JOIN help_article a ON a.id = s.article_id
                      ORDER BY s.route")->fetchAll();
    $arts = $db->query("SELECT id, chapter_no, title FROM help_article WHERE lang = 'hu' ORDER BY sort_order, id")->fetchAll();

    admin_head('Képernyők', 'screens', $counts);
    ?>
<div class="page">
  <h1 class="pt">Képernyő → fejezet hozzárendelés</h1>
  <p class="lead">
    Ez mondja meg, hogy az Infinity egy adott képernyőjén a <b>?</b> gomb melyik fejezetet nyissa meg.
    Az útvonal az Infinity route-ja, például <span class="mono">penzugy/egyenleg/index</span>.
  </p>
  <?= flash_render() ?>

  <div class="panel" style="margin-bottom:16px">
    <div class="panel__h"><h2>Új hozzárendelés</h2></div>
    <div class="panel__b">
      <form method="post" action="<?= h(admin_url()) ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="screen.save">
        <div class="row">
          <div class="field" style="flex:2 1 260px"><label>Útvonal (route)</label>
            <input class="inp mono" name="route" placeholder="penzugy/egyenleg/index" required></div>
          <div class="field" style="flex:3 1 320px"><label>Fejezet</label>
            <select class="sel" name="article_id" required>
              <?php foreach ($arts as $a): ?>
                <option value="<?= (int)$a['id'] ?>"><?= h($a['chapter_no'] . ' ' . $a['title']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>Horgony (opcionális)</label>
            <input class="inp mono" name="anchor" placeholder="5-4-2-egyenlegkozlo"></div>
          <div class="field" style="flex:0 1 auto;align-self:flex-end">
            <button class="btn btn--p" type="submit">Hozzáadás</button></div>
        </div>
      </form>
    </div>
  </div>

  <div class="panel">
    <div class="panel__h"><h2>Meglévő hozzárendelések</h2><span class="sp"></span><span class="badge"><?= count($rows) ?></span></div>
    <div class="panel__b panel__b--flush">
      <?php if (!$rows): ?>
        <div class="empty">Még nincs egyetlen hozzárendelés sem.</div>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>Útvonal</th><th>Fejezet</th><th>Horgony</th><th>Ellenőrzött</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="mono" data-label="Útvonal"><?= h($r['route']) ?></td>
              <td><a href="<?= h(admin_url(['p' => 'articles', 'lang' => $r['lang'], 'id' => $r['article_id']])) ?>">
                <?= h($r['chapter_no'] . ' ' . $r['title']) ?></a></td>
              <td class="mono muted" data-label="Horgony"><?= h((string)$r['anchor']) ?></td>
              <td data-label="Ellenőrzött"><?= $r['is_verified'] ? '<span class="badge badge--ok">igen</span>' : '<span class="badge">nem</span>' ?></td>
              <td class="nowrap">
                <form method="post" action="<?= h(admin_url()) ?>" onsubmit="return confirm('Törlöd ezt a hozzárendelést?')">
                  <?= csrf_input() ?>
                  <input type="hidden" name="a" value="screen.delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn--sm btn--danger" type="submit">Törlés</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
    <?php
    admin_foot();
}

// ============================================================ KÉPEK ÉS VIDEÓK
function page_media(PDO $db, array $cfg, int $page, array $counts): void
{
    $dir = media_dir($cfg);
    $files = is_dir($dir)
        ? (glob($dir . '/*.{png,jpg,jpeg,gif,webp,svg,mp4,webm,mov}', GLOB_BRACE) ?: [])
        : [];
    usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));

    $filter = (string)($_GET['kind'] ?? 'all');
    if ($filter === 'video') {
        $files = array_values(array_filter($files, static fn($f) => (bool)preg_match('/\.(mp4|webm|mov)$/i', $f)));
    } elseif ($filter === 'image') {
        $files = array_values(array_filter($files, static fn($f) => !preg_match('/\.(mp4|webm|mov)$/i', $f)));
    }

    $total   = count($files);
    $perPage = 48;
    $page    = max(1, $page);
    $slice   = array_slice($files, ($page - 1) * $perPage, $perPage);
    $pages   = max(1, (int)ceil($total / $perPage));
    $writable = is_dir($dir) && is_writable($dir);

    admin_head('Képek, videók', 'media', $counts);
    ?>
<div class="page">
  <h1 class="pt">Képek és videók</h1>
  <p class="lead">
    Ez a fájltár. <b>Szerkesztés közben nem kell ide jönni</b> — a fejezetszerkesztőben a
    <b>Kép</b> és a <b>Videó</b> gombbal (vagy a fájlt egyszerűen a szövegre húzva) közvetlenül
    feltölthetsz. Ez az oldal az áttekintésre és a tömeges feltöltésre való.
    A fájlnév a tartalom hash-e, ezért ugyanaz a fájl csak egyszer kerül a szerverre.
  </p>
  <?= flash_render() ?>

  <?php if (!$writable): ?>
    <div class="msg msg--warn"><b>A fájlok mappája nem írható</b> (<span class="mono"><?= h($dir) ?></span>).
      Feltöltés és Word-import képkibontás nem fog működni.</div>
  <?php endif; ?>

  <div class="panel" style="margin-bottom:16px">
    <div class="panel__h"><h2>Feltöltés</h2><span class="sp"></span>
      <span class="badge"><?= $total ?> fájl</span></div>
    <div class="panel__b">
      <form method="post" action="<?= h(admin_url()) ?>" enctype="multipart/form-data">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="media.upload">
        <div class="row">
          <div class="field" style="flex:3 1 320px">
            <label for="files">Fájlok (több is egyszerre)</label>
            <input class="inp" id="files" name="files[]" type="file"
                   accept="image/*,video/mp4,video/webm,video/quicktime" multiple required <?= $writable ? '' : 'disabled' ?>>
            <div class="hint">
              Kép: PNG, JPG, GIF, WebP, SVG — legfeljebb <?= help_bytes(MEDIA_MAX_IMAGE) ?>.
              Videó: MP4, WebM, MOV — legfeljebb <?= help_bytes(MEDIA_MAX_VIDEO) ?>.
              A böngészőkben a <b>H.264-es MP4</b> és a <b>WebM</b> játszható le mindenhol.
            </div>
          </div>
          <div class="field" style="flex:0 1 auto;align-self:flex-start;margin-top:22px">
            <button class="btn btn--p" type="submit" <?= $writable ? '' : 'disabled' ?>>Feltöltés</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="panel">
    <div class="panel__h">
      <h2>Fájlok</h2>
      <span class="sp"></span>
      <?php foreach (['all' => 'Mind', 'image' => 'Képek', 'video' => 'Videók'] as $k => $lbl): ?>
        <a class="btn btn--sm <?= $filter === $k ? 'btn--p' : '' ?>"
           href="<?= h(admin_url(['p' => 'media', 'kind' => $k])) ?>"><?= h($lbl) ?></a>
      <?php endforeach; ?>
      <span style="width:12px"></span>
      <?php if ($page > 1): ?>
        <a class="btn btn--sm" href="<?= h(admin_url(['p' => 'media', 'kind' => $filter, 'page' => $page - 1])) ?>">← Előző</a>
      <?php endif; ?>
      <span class="muted"><?= $page ?>. / <?= $pages ?></span>
      <?php if ($page < $pages): ?>
        <a class="btn btn--sm" href="<?= h(admin_url(['p' => 'media', 'kind' => $filter, 'page' => $page + 1])) ?>">Következő →</a>
      <?php endif; ?>
    </div>
    <div class="panel__b">
      <?php if (!$slice): ?>
        <div class="empty">Nincs ilyen fájl.</div>
      <?php else: ?>
        <div class="grid-media">
          <?php foreach ($slice as $f):
              $n = basename($f);
              $isVideo = (bool)preg_match('/\.(mp4|webm|mov)$/i', $n);
          ?>
            <div class="mcard">
              <?php if ($isVideo): ?>
                <video src="/media/<?= h(rawurlencode($n)) ?>" preload="metadata" muted playsinline></video>
                <span class="mcard__tag">videó</span>
              <?php else: ?>
                <img src="/media/<?= h(rawurlencode($n)) ?>" alt="<?= h($n) ?>" loading="lazy">
              <?php endif; ?>
              <div class="c">
                <span class="mono"><?= h($n) ?></span><br>
                <?= help_bytes((int)filesize($f)) ?>
                <form method="post" action="<?= h(admin_url()) ?>" style="display:inline;float:right"
                      onsubmit="return confirm('Törlöd ezt a fájlt? Csak akkor sikerül, ha egyetlen fejezet sem hivatkozik rá.')">
                  <?= csrf_input() ?>
                  <input type="hidden" name="a" value="media.delete">
                  <input type="hidden" name="filename" value="<?= h($n) ?>">
                  <button class="btn btn--sm btn--ghost" type="submit" title="Törlés">✕</button>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
    <?php
    admin_foot();
}

// ============================================================ EXPORT
function page_export(PDO $db, array $cfg, string $lang, array $counts): void
{
    $rows = $db->query("
        SELECT lang,
               COUNT(CASE WHEN is_published = 1 THEN 1 END) AS published,
               COUNT(CASE WHEN is_published = 0 THEN 1 END) AS hidden,
               SUM(img_count)                              AS images
          FROM help_article GROUP BY lang ORDER BY lang")->fetchAll();
    $stat = [];
    foreach ($rows as $r) { $stat[$r['lang']] = $r; }
    $version = (string)$db->query("SELECT COALESCE(MAX(doc_version), '') FROM help_article")->fetchColumn();
    $hasPdf  = pdf_engine() !== null;

    admin_head('Export', 'export', $counts);
    ?>
<div class="page" style="max-width:1000px">
  <h1 class="pt">A használati útmutató exportálása</h1>
  <p class="lead">
    A teljes útmutató letölthető <b>Word</b>- és <b>PDF</b>-fájlként. Mindkettő rendes
    kézikönyv: címlap (fejléc és lábléc nélkül), <b>tartalomjegyzék oldalszámokkal</b>, minden
    lap tetején a logó, alul balra az <b>aktuális modul neve</b>, jobbra az <b>oldalszám</b>.
    Mindig a <b>jelenlegi, közzétett</b> adatbázis-tartalomból készül, nem egy korábbi pillanatképből.
  </p>
  <?= flash_render() ?>

  <div class="panel">
    <div class="panel__h"><h2>Letöltés</h2><span class="sp"></span>
      <?php if ($version !== ''): ?><span class="badge badge--info"><?= h($version) ?></span><?php endif; ?></div>
    <div class="panel__b panel__b--flush">
      <table class="tbl">
        <thead><tr><th>Nyelv</th><th class="num">Közzétett fejezet</th><th class="num">Kikapcsolt</th>
          <th class="num">Kép</th><th>Letöltés</th></tr></thead>
        <tbody>
        <?php foreach (ADMIN_LANGS as $code => $label):
            $s = $stat[$code] ?? ['published' => 0, 'hidden' => 0, 'images' => 0]; ?>
          <tr>
            <td><b><?= h($label) ?></b> <span class="badge"><?= h($code) ?></span></td>
            <td class="num" data-label="Közzétett"><?= (int)$s['published'] ?></td>
            <td class="num" data-label="Kikapcsolt"><?= (int)$s['hidden'] ? '<span class="badge badge--warn">' . (int)$s['hidden'] . '</span>' : '0' ?></td>
            <td class="num" data-label="Kép"><?= (int)$s['images'] ?></td>
            <td class="nowrap">
              <form method="post" action="<?= h(admin_url()) ?>" style="display:inline">
                <?= csrf_input() ?>
                <input type="hidden" name="a" value="export.docx">
                <input type="hidden" name="lang" value="<?= h($code) ?>">
                <button class="btn btn--sm btn--p" type="submit">Word (.docx)</button>
              </form>
              <?php if ($hasPdf): ?>
                <form method="post" action="<?= h(admin_url()) ?>" style="display:inline">
                  <?= csrf_input() ?>
                  <input type="hidden" name="a" value="export.pdf">
                  <input type="hidden" name="lang" value="<?= h($code) ?>">
                  <button class="btn btn--sm btn--p" type="submit"
                          title="Oldalszámmal, futó lábléccel, kattintható tartalomjegyzékkel">PDF</button>
                </form>
              <?php endif; ?>
              <a class="btn btn--sm btn--ghost" target="_blank" rel="noopener"
                 href="<?= h(admin_url(['a' => 'export.html', 'lang' => $code])) ?>"
                 title="Megnyitás böngészőben (onnan is nyomtatható)">Előnézet</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="panel__b">
      <?php if (!$hasPdf): ?>
        <div class="msg msg--warn">
          <b>Nincs PDF-motor a szerveren</b>, ezért a közvetlen PDF-letöltés nem érhető el.
          Telepítés: <span class="mono">apt-get install -y weasyprint</span> (a Docker-változat
          már tartalmazza). Addig az <b>Előnézet</b> gombbal megnyitott oldalról a böngésző
          <i>Nyomtatás → Mentés PDF-ként</i> funkciójával is készíthető PDF — abban viszont
          nincs oldalszám és futó lábléc, mert azt a böngésző nem tudja.
        </div>
      <?php endif; ?>
      <div class="msg msg--info" style="margin:0">
        <b>A Word-fájl tartalomjegyzéke automatikusan frissül.</b>
        A Wordben megnyitva a program felajánlja a mezők frissítését — vagy jelöld ki a
        tartalomjegyzéket és nyomj <span class="mono">F9</span>-et, hogy az oldalszámok bekerüljenek.
        A logó a címlapra és minden lap jobb felső sarkába kerül, ahogy az eredeti útmutatóban.
        A videók nem kerülnek bele a dokumentumba (a Word nem játssza le őket), a helyükön hivatkozás marad.
      </div>
    </div>
  </div>

  <div class="panel" style="margin-top:16px">
    <div class="panel__h"><h2>Kikapcsolt fejezetek</h2></div>
    <div class="panel__b">
      <form method="post" action="<?= h(admin_url()) ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="export.docx">
        <div class="row">
          <div class="field" style="flex:0 1 200px"><label>Nyelv</label>
            <select class="sel" name="lang">
              <?php foreach (ADMIN_LANGS as $code => $label): ?>
                <option value="<?= h($code) ?>" <?= $code === $lang ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field" style="align-self:center">
            <label class="check"><input type="checkbox" name="include_hidden" checked>
              A még nem közzétett (kikapcsolt) fejezetek is kerüljenek bele</label>
            <div class="hint">Belső átnézésre hasznos: így a készülő fejezetek is benne lesznek a dokumentumban.</div>
          </div>
          <div class="field" style="flex:0 1 auto;align-self:center">
            <button class="btn" type="submit">Letöltés mindennel</button></div>
        </div>
      </form>
    </div>
  </div>
</div>
    <?php
    admin_foot();
}

// ============================================================ FELHASZNÁLÓK
function page_users(PDO $db, array $counts): void
{
    $users = $db->query('SELECT * FROM help_user ORDER BY username')->fetchAll();
    $me = auth_user();

    admin_head('Felhasználók', 'users', $counts);
    ?>
<div class="page">
  <h1 class="pt">Felhasználók</h1>
  <p class="lead">
    Szerepkörök: <b>admin</b> — mindent; <b>editor</b> — fejezetek, modulok, import, fordítás, képernyők, képek;
    <b>translator</b> — csak a Fordítás fül. A jelszó cseréje nem kötelező; a Beállítások fülön
    bármikor elvégezhető.
  </p>
  <?= flash_render() ?>

  <div class="panel" style="margin-bottom:16px">
    <div class="panel__h"><h2>Új felhasználó</h2></div>
    <div class="panel__b">
      <form method="post" action="<?= h(admin_url()) ?>" autocomplete="off">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="user.save">
        <div class="row">
          <div class="field"><label>Felhasználónév</label><input class="inp" name="username" required></div>
          <div class="field"><label>Név</label><input class="inp" name="display_name"></div>
          <div class="field"><label>E-mail</label><input class="inp" name="email" type="email"></div>
          <div class="field" style="flex:0 1 160px"><label>Szerepkör</label>
            <select class="sel" name="role">
              <option value="editor">editor</option>
              <option value="translator">translator</option>
              <option value="admin">admin</option>
            </select></div>
          <div class="field"><label>Kezdeti jelszó</label><input class="inp" name="password" type="password" required minlength="8"></div>
          <div class="field" style="flex:0 1 auto;align-self:flex-end"><button class="btn btn--p" type="submit">Létrehozás</button></div>
        </div>
        <div class="hint">Legalább 8 karakter, betű és szám is legyen benne.</div>
      </form>
    </div>
  </div>

  <div class="panel">
    <div class="panel__h"><h2>Meglévő felhasználók</h2><span class="sp"></span><span class="badge"><?= count($users) ?></span></div>
    <div class="panel__b panel__b--flush">
      <?php foreach ($users as $us): ?>
        <form method="post" action="<?= h(admin_url()) ?>" id="uf<?= (int)$us['id'] ?>">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="a" value="user.save">
          <input type="hidden" name="id" value="<?= (int)$us['id'] ?>">
        </form>
      <?php endforeach; ?>
      <table class="tbl">
        <thead><tr><th>Felhasználónév</th><th>Név</th><th>E-mail</th><th>Szerepkör</th><th>Aktív</th><th>Utolsó belépés</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $us): ?>
          <tr>
            <td data-label="Felhasználónév"><b><?= h($us['username']) ?></b>
              <?php if ($us['must_change_pw']): ?><br><span class="badge badge--warn">jelszócsere vár</span><?php endif; ?>
              <?php if ($us['locked_until'] !== null && strtotime((string)$us['locked_until']) > time()): ?>
                <br><span class="badge badge--err">zárolva</span><?php endif; ?>
            </td>
            <td data-label="Név"><input class="inp" form="uf<?= (int)$us['id'] ?>" name="display_name" value="<?= h($us['display_name']) ?>"></td>
            <td data-label="E-mail"><input class="inp" form="uf<?= (int)$us['id'] ?>" name="email" value="<?= h((string)$us['email']) ?>"></td>
            <td data-label="Szerepkör"><select class="sel" form="uf<?= (int)$us['id'] ?>" name="role">
                <?php foreach (HELP_ROLES as $r): ?>
                  <option value="<?= h($r) ?>" <?= $r === $us['role'] ? 'selected' : '' ?>><?= h($r) ?></option>
                <?php endforeach; ?>
              </select></td>
            <td data-label="Aktív"><label class="check"><input form="uf<?= (int)$us['id'] ?>" type="checkbox" name="is_active" <?= $us['is_active'] ? 'checked' : '' ?>></label></td>
            <td class="nowrap muted" data-label="Utolsó belépés"><?= h(substr((string)($us['last_login_at'] ?? '—'), 0, 16)) ?></td>
            <td class="nowrap" data-label="">
              <button class="btn btn--sm btn--p" form="uf<?= (int)$us['id'] ?>" type="submit">Mentés</button>
              <button class="btn btn--sm" type="button" data-modal="pw<?= (int)$us['id'] ?>">Jelszó</button>
              <?php if ((int)$us['id'] !== (int)$me['id']): ?>
                <form method="post" action="<?= h(admin_url()) ?>" style="display:inline"
                      onsubmit="return confirm('Véglegesen törlöd ezt a felhasználót?')">
                  <?= csrf_input() ?>
                  <input type="hidden" name="a" value="user.delete">
                  <input type="hidden" name="id" value="<?= (int)$us['id'] ?>">
                  <button class="btn btn--sm btn--danger" type="submit">Törlés</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php foreach ($users as $us): ?>
  <div class="modal" id="modal-pw<?= (int)$us['id'] ?>">
    <div class="modal__box">
      <form method="post" action="<?= h(admin_url()) ?>" autocomplete="off">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="user.resetpw">
        <input type="hidden" name="id" value="<?= (int)$us['id'] ?>">
        <div class="modal__h">Jelszó beállítása — <?= h($us['username']) ?></div>
        <div class="modal__b">
          <div class="field"><label>Új jelszó</label>
            <input class="inp" name="password" type="password" required minlength="8">
            <div class="hint">A felhasználónak az első belépéskor cserélnie kell.</div></div>
        </div>
        <div class="modal__f">
          <button class="btn btn--ghost" type="button" data-close>Mégsem</button>
          <button class="btn btn--p" type="submit">Beállítás</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; ?>
    <?php
    admin_foot();
}

// ============================================================ BEÁLLÍTÁSOK
function page_settings(PDO $db, array $cfg, array $counts): void
{
    $tr = Translator::fromConfig($cfg, $db);
    $release = $db->query("SELECT * FROM help_release WHERE status = 'open' LIMIT 1")->fetch();
    $closed  = $db->query("SELECT * FROM help_release WHERE status = 'closed' ORDER BY released_at DESC LIMIT 5")->fetchAll();
    $pending = (int)$db->query("SELECT COUNT(*) FROM help_changelog
                                  WHERE release_id = (SELECT id FROM (SELECT id FROM help_release WHERE status='open' LIMIT 1) r)")->fetchColumn();
    $envMt = ($cfg['mt_provider'] ?? '') !== '';

    admin_head('Beállítások', 'settings', $counts);
    ?>
<div class="page" style="max-width:900px">
  <h1 class="pt">Beállítások</h1>
  <?= flash_render() ?>

  <div class="panel" style="margin-bottom:16px">
    <div class="panel__h"><h2>Oldalcímek</h2></div>
    <div class="panel__b">
      <form method="post" action="<?= h(admin_url()) ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="setting.save">
        <div class="row">
          <?php foreach (ADMIN_LANGS as $code => $label): ?>
            <div class="field"><label><?= h($label) ?></label>
              <input class="inp" name="site_title_<?= h($code) ?>" value="<?= h(admin_setting($db, 'site_title_' . $code)) ?>"></div>
          <?php endforeach; ?>
        </div>
        <button class="btn btn--p" type="submit">Mentés</button>
      </form>
    </div>
  </div>

  <div class="panel" style="margin-bottom:16px" id="jelszo">
    <div class="panel__h"><h2>Saját jelszó</h2><span class="sp"></span>
      <span class="muted"><?= h(auth_user()['username']) ?></span></div>
    <div class="panel__b">
      <p class="lead" style="margin-bottom:14px">
        A jelszó cseréje <b>nem kötelező</b> — akkor változtasd meg, amikor szeretnéd.
      </p>
      <form method="post" action="<?= h(admin_url()) ?>" autocomplete="off">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="chpw">
        <div class="row">
          <div class="field"><label for="s-cur">Jelenlegi jelszó</label>
            <input class="inp" id="s-cur" name="current" type="password" autocomplete="current-password" required></div>
          <div class="field"><label for="s-n1">Új jelszó</label>
            <input class="inp" id="s-n1" name="new" type="password" autocomplete="new-password" required minlength="8"></div>
          <div class="field"><label for="s-n2">Még egyszer</label>
            <input class="inp" id="s-n2" name="new2" type="password" autocomplete="new-password" required minlength="8"></div>
          <div class="field" style="flex:0 1 auto;align-self:flex-end">
            <button class="btn btn--p" type="submit">Jelszó mentése</button></div>
        </div>
        <div class="hint">Legalább 8 karakter, betű és szám is legyen benne.</div>
      </form>
    </div>
  </div>

  <div class="panel" style="margin-bottom:16px">
    <div class="panel__h"><h2>Gépi fordítás</h2><span class="sp"></span>
      <span class="badge <?= $tr->isConfigured() ? 'badge--ok' : '' ?>"><?= h($tr->label()) ?></span></div>
    <div class="panel__b">
      <?php if ($envMt): ?>
        <div class="msg msg--info">A fordító <b>környezeti változóból</b> jön (HELP_MT_PROVIDER), ezért az itteni
          beállítás nem érvényesül. Ha itt akarod állítani, vedd ki a környezeti változót.</div>
      <?php endif; ?>
      <form method="post" action="<?= h(admin_url()) ?>" autocomplete="off">
        <?= csrf_input() ?>
        <input type="hidden" name="a" value="setting.save">
        <div class="row">
          <div class="field" style="flex:0 1 200px"><label>Szolgáltató</label>
            <select class="sel" name="mt_provider">
              <?php foreach (['none' => 'nincs (kézi fordítás)', 'deepl' => 'DeepL', 'libre' => 'LibreTranslate', 'google' => 'Google Translate'] as $k => $v): ?>
                <option value="<?= h($k) ?>" <?= admin_setting($db, 'mt_provider', 'none') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>Végpont (LibreTranslate-nél kötelező)</label>
            <input class="inp mono" name="mt_endpoint" value="<?= h(admin_setting($db, 'mt_endpoint')) ?>" placeholder="https://libretranslate.example.com"></div>
          <div class="field"><label>API-kulcs</label>
            <input class="inp mono" name="mt_key" type="password"
                   value="<?= admin_setting($db, 'mt_key') !== '' ? '********' : '' ?>"
                   placeholder="<?= admin_setting($db, 'mt_key') !== '' ? 'beállítva' : 'nincs beállítva' ?>">
            <div class="hint">Ha nem módosítod, a mostani kulcs marad érvényben.</div></div>
        </div>
        <button class="btn btn--p" type="submit">Mentés</button>
      </form>
        <div class="field" style="margin-top:6px">
          <label class="check">
            <input type="checkbox" name="mt_auto" value="1" <?= admin_setting($db, 'mt_auto', '0') === '1' ? 'checked' : '' ?>>
            <b>Automatikus fordítás:</b> új vagy Word-ből importált magyar fejezetből rögtön készüljön
            angol és német vázlat is
          </label>
          <div class="hint">A gépi fordítás mindig csak <b>vázlatot</b> készít — közzétenni ember dönt.
            Szolgáltató nélkül ez a kapcsoló hatástalan.</div>
        </div>

      <div class="hint" style="margin-top:12px">
        Fizetős kulcs nélkül is megoldható: a csomagban van egy <b>saját, helyben futó</b> fordító
        (LibreTranslate) — <span class="mono">docker compose --profile mt up -d</span>, majd
        Szolgáltató = LibreTranslate, Végpont = <span class="mono">http://libretranslate:5000</span>.
        <br>
        A fordítás HTML-ként megy át a szolgáltatóhoz (DeepL: <span class="mono">tag_handling=html</span>),
        így a formázás és a képek a helyükön maradnak. Gépi fordító nélkül is használható a Fordítás fül,
        csak a nyersfordítás gomb marad inaktív.
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel__h"><h2>Kiadások</h2></div>
    <div class="panel__b">
      <p class="lead" style="margin-bottom:14px">
        A közzétételkor megadott összefoglalók a nyitott kiadásba gyűlnek. A kiadás lezárása dátumot és
        verziószámot ad nekik, és megnyit egy újat.
      </p>
      <?php if ($release): ?>
        <div class="msg msg--info" style="margin-bottom:14px">
          Nyitott kiadás: <b><?= h($release['version']) ?></b> — <?= $pending ?> bejegyzés vár benne.
        </div>
        <form method="post" action="<?= h(admin_url()) ?>"
              onsubmit="return confirm('Lezárod a kiadást? Ez minden fejezet verziószámát frissíti.')">
          <?= csrf_input() ?>
          <input type="hidden" name="a" value="release.close">
          <div class="row">
            <div class="field"><label>Lezárandó verzió neve</label>
              <input class="inp" name="version" value="<?= h($release['version']) ?>" required></div>
            <div class="field"><label>A következő (nyitott) verzió</label>
              <input class="inp" name="next" placeholder="v2026.10" required></div>
            <div class="field" style="flex:0 1 auto;align-self:flex-end">
              <button class="btn" type="submit">Kiadás lezárása</button></div>
          </div>
        </form>
      <?php else: ?>
        <div class="muted">Nincs nyitott kiadás.</div>
      <?php endif; ?>

      <?php if ($closed): ?>
        <table class="tbl" style="margin-top:16px">
          <thead><tr><th>Verzió</th><th>Lezárva</th></tr></thead>
          <tbody>
          <?php foreach ($closed as $c): ?>
            <tr><td><b><?= h($c['version']) ?></b></td><td class="muted"><?= h((string)$c['released_at']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
    <?php
    admin_foot();
}

// ============================================================ KUKA
function page_trash(PDO $db, array $counts): void
{
    $rows = $db->query("
        SELECT t.*, u.display_name
          FROM help_trash t LEFT JOIN help_user u ON u.id = t.deleted_by
      ORDER BY t.restored_at IS NOT NULL, t.deleted_at DESC
         LIMIT 200")->fetchAll();
    $open = array_filter($rows, static fn($r) => $r['restored_at'] === null);

    admin_head('Kuka', 'trash', $counts);
    ?>
<div class="page" style="max-width:1100px">
  <h1 class="pt">Kuka</h1>
  <p class="lead">
    A törölt fejezetek és modulok <b>nem vesznek el</b>: teljes tartalmukkal ide kerülnek —
    a szakaszaikkal, a verziótörténetükkel és a képernyő-hozzárendeléseikkel együtt —, és
    egy kattintással visszaállíthatók. Véglegesen csak innen törlődnek.
  </p>
  <?= flash_render() ?>

  <div class="panel">
    <div class="panel__h"><h2>Törölt elemek</h2><span class="sp"></span>
      <span class="badge <?= $open ? 'badge--warn' : '' ?>"><?= count($open) ?> visszaállítható</span>
      <?php if ($open): ?>
        <form method="post" action="<?= h(admin_url()) ?>"
              onsubmit="return confirm('Véglegesen törlöd a Kuka teljes tartalmát? Ez nem vonható vissza.')">
          <?= csrf_input() ?>
          <input type="hidden" name="a" value="trash.purge">
          <input type="hidden" name="id" value="0">
          <button class="btn btn--sm btn--danger" type="submit">Kuka ürítése</button>
        </form>
      <?php endif; ?>
    </div>
    <div class="panel__b panel__b--flush">
      <?php if (!$rows): ?>
        <div class="empty">A Kuka üres.</div>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>Mit</th><th>Megnevezés</th><th>Nyelv</th><th>Mikor</th><th>Ki</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r):
              $kind = match ($r['kind']) {
                  'article' => 'fejezet', 'module' => 'modul', 'move' => 'áthelyezés', default => $r['kind'],
              }; ?>
            <tr<?= $r['restored_at'] !== null ? ' style="opacity:.55"' : '' ?>>
              <td data-label="Mit"><span class="badge"><?= h($kind) ?></span></td>
              <td data-label="Megnevezés"><b><?= h($r['label']) ?></b></td>
              <td data-label="Nyelv" class="mono"><?= h((string)$r['lang']) ?></td>
              <td data-label="Mikor" class="nowrap muted"><?= h(substr((string)$r['deleted_at'], 0, 16)) ?></td>
              <td data-label="Ki" class="muted"><?= h((string)$r['display_name']) ?></td>
              <td data-label="" class="nowrap">
                <?php if ($r['restored_at'] !== null): ?>
                  <span class="badge badge--ok">visszaállítva</span>
                <?php else: ?>
                  <form method="post" action="<?= h(admin_url()) ?>" style="display:inline">
                    <?= csrf_input() ?>
                    <input type="hidden" name="a" value="trash.restore">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn--sm btn--p" type="submit">↩ Visszaállítás</button>
                  </form>
                  <form method="post" action="<?= h(admin_url()) ?>" style="display:inline"
                        onsubmit="return confirm('Véglegesen törlöd? Ez nem vonható vissza.')">
                    <?= csrf_input() ?>
                    <input type="hidden" name="a" value="trash.purge">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn btn--sm btn--danger" type="submit">Végleges törlés</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
    <?php
    admin_foot();
}

/**
 * A parancspaletta (Ctrl+K) talalatai: fulek, muveletek es fejezetek egyben.
 * @return list<array{group:string,label:string,sub:string,url:string,key:string}>
 */
function palette_items(PDO $db, string $q): array
{
    $q = trim($q);
    $out = [];

    // 1. fulek es gyorsmuveletek
    $nav = [
        ['Áttekintés',        'dashboard', 'Vázlatok, statisztika, napló'],
        ['Fejezetek',         'articles',  'Szerkesztés, közzététel, verziók'],
        ['Modulok',           'modules',   'A súgó felső szintje'],
        ['Word import',       'import',    '.docx betöltése összehasonlítással'],
        ['Fordítás',          'translate', 'HU → EN/DE'],
        ['Képernyők',         'screens',   'Útvonal → fejezet'],
        ['Képek, videók',     'media',     'Fájltár, feltöltés'],
        ['Export',            'export',    'Word / PDF letöltés'],
        ['Felhasználók',      'users',     'Fiókok, szerepkörök'],
        ['Kuka',              'trash',     'Törölt elemek visszaállítása'],
        ['Beállítások',       'settings',  'Fordító, kiadás, oldalcímek'],
        ['Saját fiók',        'account',   'Jelszócsere'],
    ];
    foreach ($nav as [$label, $page, $sub]) {
        if (!auth_can($page) && !in_array($page, ['account'], true)) { continue; }
        if ($q === '' || mb_stripos(help_norm($label . ' ' . $sub), help_norm($q)) !== false) {
            $out[] = ['group' => 'Menü', 'label' => $label, 'sub' => $sub,
                      'url' => admin_url(['p' => $page]), 'key' => 'p-' . $page];
        }
    }

    // 2. fejezetek
    if ($q !== '' && auth_can('articles')) {
        // az ekezet-fuggetlenseget a tabla utf8mb4_uca1400_ai_ci rendezese adja
        $st = $db->prepare("
            SELECT a.id, a.lang, a.chapter_no, a.title, a.is_published,
                   a.draft_html IS NOT NULL AS has_draft, m.title AS module_title
              FROM help_article a LEFT JOIN help_module m ON m.id = a.module_id
             WHERE CONCAT(a.chapter_no, ' ', a.title) LIKE ?
          ORDER BY (a.lang = 'hu') DESC, a.sort_order
             LIMIT 25");
        $st->execute(['%' . $q . '%']);
        foreach ($st->fetchAll() as $r) {
            $flags = [];
            if ($r['has_draft'])     { $flags[] = 'vázlat'; }
            if (!$r['is_published']) { $flags[] = 'kikapcsolva'; }
            $out[] = [
                'group' => 'Fejezet',
                'label' => trim($r['chapter_no'] . ' ' . $r['title']),
                'sub'   => strtoupper((string)$r['lang']) . ' · ' . (string)$r['module_title']
                         . ($flags ? ' · ' . implode(', ', $flags) : ''),
                'url'   => admin_url(['p' => 'articles', 'lang' => $r['lang'], 'id' => $r['id']]),
                'key'   => 'a-' . $r['id'],
            ];
        }
    }

    return array_slice($out, 0, 40);
}
