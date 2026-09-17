<?php
/**
 * admin_actions.php - az admin felulet irasi muveletei.
 *
 * Minden muvelet CSRF-tokent var, es vagy JSON-t ad vissza (a szerkeszto
 * AJAX-hivasaihoz), vagy visszairanyit egy lapra flash-uzenettel.
 */
declare(strict_types=1);

function back(array $params = []): never
{
    header('Location: ' . admin_url($params), true, 303);
    exit;
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function admin_handle_action(string $action, PDO $db, array $cfg): void
{
    $wantsJson = ($_POST['fmt'] ?? '') === 'json';

    if (!csrf_check($_POST['csrf'] ?? ($_GET['csrf'] ?? null))) {
        if ($wantsJson) { help_json(['ok' => false, 'error' => 'Lejárt munkamenet – töltsd újra az oldalt.'], 419); }
        flash('err', t('flash.session.lejart-vagy-ervenytelen-munkamenet'));
        back($action === 'login' ? ['p' => 'login'] : []);
    }

    // Egy fejezet nyelvi valtozatai. A magyar a forras, ezert ha azt toroljuk,

    // az angol/nemet parjanak sincs tobbe ertelme - kulonben arvan ottmaradnak

    // a listaban, es ugy tunik, hogy "nem torol". A parositas a fejezetszam,

    // tartalekban a slug alapjan megy.

    $siblings = static function (PDO $db, array $a): array {

        if ((string)$a['lang'] !== 'hu') { return [$a]; }

        $out = [$a];

        $seen = [(int)$a['id'] => true];

        $q = $db->prepare("SELECT * FROM help_article

                            WHERE lang <> 'hu' AND (chapter_no = ? OR slug = ?)");

        $q->execute([(string)$a['chapter_no'], (string)$a['slug']]);

        foreach ($q->fetchAll() as $r) {

            if (isset($seen[(int)$r['id']])) { continue; }

            if ((string)$a['chapter_no'] === '' && (string)$r['slug'] !== (string)$a['slug']) { continue; }

            $seen[(int)$r['id']] = true;

            $out[] = $r;

        }

        return $out;

    };


    switch ($action) {

        // ================================================== fiók
        case 'login': {
            $r = auth_login($db, post('username'), (string)($_POST['password'] ?? ''));
            if (!$r['ok']) {
                flash('err', h($r['error'] ?? 'Sikertelen bejelentkezés.'));
                back(['p' => 'login']);
            }
            // belepes utan egyenesen az Attekintesre - jelszocsere nem kotelezo
            back();
        }

        case 'chpw': {
            $u = auth_user();
            if ($u === null) { back(['p' => 'login']); }
            $cur = (string)($_POST['current'] ?? '');
            $new = (string)($_POST['new'] ?? '');
            $new2 = (string)($_POST['new2'] ?? '');

            $row = $db->prepare('SELECT password_hash FROM help_user WHERE id = ?');
            $row->execute([$u['id']]);
            $hash = (string)$row->fetchColumn();

            if (!password_verify($cur, $hash)) {
                flash('err', t('flash.chpw.jelenlegi-jelszo-stimmel'));
                back(['p' => 'settings']);
            }
            if ($new !== $new2) {
                flash('err', t('flash.chpw.ket-uj-jelszo-egyezik'));
                back(['p' => 'settings']);
            }
            if (($problem = auth_password_problem($new, $u['username'])) !== null) {
                flash('err', h($problem));
                back(['p' => 'settings']);
            }
            if (password_verify($new, $hash)) {
                flash('err', t('flash.chpw.uj-jelszo-ugyanaz-mint'));
                back(['p' => 'settings']);
            }
            auth_set_password($db, (int)$u['id'], $new);
            audit_me($db, 'password.change', 'user:' . $u['username']);
            flash('ok', t('flash.chpw.jelszo-megvaltozott'));
            back(['p' => 'settings']);
        }

        // ================================================== fejezetek
        case 'article.draft': {
            $id   = (int)post('id');
            $html = help_clean_html((string)($_POST['body'] ?? ''));
            [$html] = help_anchorize($html);
            $title = post('title');

            $st = $db->prepare('UPDATE help_article
                                   SET draft_html = ?, draft_title = ?, draft_by = ?, draft_at = now()
                                 WHERE id = ?');
            $st->execute([$html, $title !== '' ? mb_substr($title, 0, 255) : null, auth_user()['id'], $id]);

            // MEG SOSEM KOZZETETT fejezetnel a cim azonnal ervenybe lep - es
            // vele az URL is. Kulonben a "Nevtelen fejezet" maradna kint a
            // fejlecben es a listaban egeszen a kozzetetelig, holott a
            // fejezetnek meg nincs nyilvanos valtozata, amit vedeni kellene.
            $renamed = false;
            if ($title !== '') {
                $q = $db->prepare("SELECT a.chapter_no, a.slug, a.title, a.lang, a.is_published,
                                          (SELECT COUNT(*) FROM help_article_revision r
                                            WHERE r.article_id = a.id) AS revs
                                     FROM help_article a WHERE a.id = ?");
                $q->execute([$id]);
                $a = $q->fetch();

                if ($a && (int)$a['is_published'] === 0 && (int)$a['revs'] === 0) {
                    $newTitle = mb_substr($title, 0, 255);
                    $newSlug  = (string)$a['slug'];

                    // a slugot csak akkor irjuk at, ha meg a regi cimbol keszult
                    if ($a['slug'] === help_slug((string)$a['chapter_no'], (string)$a['title'])) {
                        $cand = help_slug((string)$a['chapter_no'], $newTitle);
                        if ($cand !== '') {
                            $free = $db->prepare('SELECT COUNT(*) FROM help_article WHERE slug = ? AND lang = ? AND id <> ?');
                            $free->execute([$cand, $a['lang'], $id]);
                            if ((int)$free->fetchColumn() === 0) { $newSlug = $cand; }
                        }
                    }

                    $db->prepare('UPDATE help_article SET title = ?, slug = ?, draft_title = NULL WHERE id = ?')
                       ->execute([$newTitle, $newSlug, $id]);
                    $renamed = true;
                }
            }

            // Ha ez a fejezet a FOFEJEZET LEIRASA (a szama megegyezik a
            // fofejezeteevel), akkor a cime egyben a fofejezet neve is - a
            // listaban is az latszik. Ezert a ketto egyutt mozog.
            if ($title !== '') { module_title_sync($db, $id, mb_substr($title, 0, 255)); }

            audit_me($db, 'article.draft', 'article:' . $id);

            if ($wantsJson) { help_json(['ok' => true, 'saved_at' => date('H:i:s'), 'renamed' => $renamed]); }
            flash('ok', t('flash.article.draft.vazlat-mentve'));
            back(['p' => 'articles', 'id' => $id]);
        }

        case 'article.publish': {
            $id = (int)post('id');
            $st = $db->prepare('SELECT lang, slug, chapter_no, title, draft_html FROM help_article WHERE id = ?');
            $st->execute([$id]);
            $before = $st->fetch();
            if (!$before || $before['draft_html'] === null) {
                if ($wantsJson) { help_json(['ok' => false, 'error' => 'Nincs közzétételre váró vázlat.'], 400); }
                flash('warn', t('flash.article.publish.nincs-kozzetetelre-varo-vazlat'));
                back(['p' => 'articles', 'id' => $id]);
            }

            // Idegen nyelvu fejezet csak akkor mehet ki, ha van benne szoveg.
            // Ures angol/nemet oldal rosszabb, mint a magyar tartalek, amit a
            // nyilvanos oldal enelkul is megmutat.
            if ($before['lang'] !== 'hu' && trim(help_plain((string)$before['draft_html'])) === '') {
                $msg = 'Ez a(z) ' . strtoupper($before['lang']) . ' változat még nincs lefordítva — '
                     . 'üresen nem teszem közzé. Írd meg a fordítást, vagy kérj gépi nyersfordítást '
                     . 'a <b>Fordítás</b> fülön.';
                if ($wantsJson) { help_json(['ok' => false, 'error' => strip_tags($msg)], 400); }
                flash('err', $msg);
                back(['p' => 'articles', 'id' => $id]);
            }

            $summary = post('summary');
            $kind    = in_array(post('kind'), ['new', 'mod', 'fix'], true) ? post('kind') : 'mod';
            $minor   = isset($_POST['minor']) ? 1 : 0;

            $db->beginTransaction();
            try {
                $p = $db->prepare('CALL help_publish(?, ?, ?, ?, NULL, ?)');
                $p->execute([$id, auth_user()['id'], $summary !== '' ? $summary : null, $kind, $minor]);
                $p->closeCursor();

                $body = $db->prepare('SELECT body_html FROM help_article WHERE id = ?');
                $body->execute([$id]);
                sections_rebuild($db, $id, (string)$body->fetchColumn());
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                if ($wantsJson) { help_json(['ok' => false, 'error' => $e->getMessage()], 500); }
                flash('err', t('flash.article.publish.hiba', ['reszlet' => h($e->getMessage())]));
                back(['p' => 'articles', 'id' => $id]);
            }
            audit_me($db, 'article.publish', 'article:' . $id, $summary);

            // Automatikus forditas: ha a Beallitasokban be van kapcsolva, a most
            // kozzetett MAGYAR fejezetbol rogton keszul angol es nemet vazlat is.
            // (Kozzetenni tovabbra is ember dont - ez csak vazlatot ir.)
            $mtMsg = '';
            if ($before['lang'] === 'hu' && mt_auto_on($db, $cfg)) {
                $r = mt_auto_translate($db, $cfg, $id, auth_user()['id']);
                if ($r['done']) {
                    $mtMsg = ' ' . t('flash.article.publish.mt',
                        ['nyelvek' => implode(', ', array_map('strtoupper', $r['done']))]);
                }
                foreach ($r['failed'] as $l => $err) {
                    flash('err', t('flash.article.publish.mt.hiba',
                                   ['nyelv' => strtoupper($l), 'reszlet' => h($err)]));
                }
            }

            if ($wantsJson) { help_json(['ok' => true]); }
            flash('ok', t('flash.article.publish.kesz') . $mtMsg . ' '
                . undo_button($db, 'article.unpublish', ['id' => $id], t('undo.publish')));
            back(['p' => 'articles', 'id' => $id]);
        }

        case 'article.discard': {
            $id = (int)post('id');
            $db->prepare('UPDATE help_article SET draft_html = NULL, draft_title = NULL, draft_by = NULL, draft_at = NULL WHERE id = ?')
               ->execute([$id]);
            audit_me($db, 'article.discard', 'article:' . $id);
            flash('ok', t('flash.article.discard.vazlat-eldobva-kozzetett-tartalom'));
            back(['p' => 'articles', 'id' => $id]);
        }

        // Gyors ki-/bekapcsolas a szem ikonnal: amig egy fejezet nincs kesz, ne
        // latszodjon a nyilvanos oldalon. A tartalom es a vazlat erintetlen marad.
        //
        // scope=one  - csak az adott nyelven
        // scope=all  - minden nyelven (a nyelvi parokat a fejezetszam koti
        //              ossze, mert a slug nyelvenkent mas)
        case 'article.toggle': {
            $id    = (int)post('id');
            $scope = post('scope') === 'all' ? 'all' : 'one';

            $st = $db->prepare('SELECT chapter_no, title, lang, is_published FROM help_article WHERE id = ?');
            $st->execute([$id]);
            $a = $st->fetch();
            if (!$a) {
                flash('err', t('flash.fejezet.nincs', [], 'Nincs ilyen fejezet.'));
                back(['p' => 'articles']);
            }
            // Alapesetben a kattintas atbillenti az allapotot; ha a hivo
            // megmondja (on=0/1), akkor azt allitjuk be.
            $on   = post('on') !== '' ? post('on') === '1' : (int)$a['is_published'] === 0;
            $name = trim($a['chapter_no'] . ' ' . $a['title']);

            if ($scope === 'all') {
                $c = $db->prepare('SELECT count(*) FROM help_article WHERE chapter_no = ?');
                $c->execute([$a['chapter_no']]);
                $n = (int)$c->fetchColumn();
                $db->prepare('UPDATE help_article SET is_published = ? WHERE chapter_no = ?')
                   ->execute([$on ? 1 : 0, $a['chapter_no']]);
            } else {
                $n = 1;
                $db->prepare('UPDATE help_article SET is_published = ? WHERE id = ?')
                   ->execute([$on ? 1 : 0, $id]);
            }

            audit_me($db, 'article.toggle', 'article:' . $id,
                     ($on ? 'lathato' : 'elrejtve') . ', ' . $scope . ', ' . $n . ' nyelv');
            flash('ok', visibility_flash($on, $name, $scope, $n, (string)$a['lang']));
            back(['p' => 'articles', 'lang' => (string)$a['lang'], 'id' => $id]);
        }

        // Ugyanez egy FOFEJEZETRE. Egy elrejtett fofejezet a nyilvanos oldalon
        // ugy viselkedik, mintha nem letezne: a benne levo fejezetek sem
        // nyithatok meg, akkor sem, ha azok kulon be vannak kapcsolva.
        case 'module.toggle': {
            $id    = (int)post('id');
            $scope = post('scope') === 'all' ? 'all' : 'one';

            $st = $db->prepare('SELECT chapter_no, title, lang, is_published FROM help_module WHERE id = ?');
            $st->execute([$id]);
            $m = $st->fetch();
            if (!$m) {
                flash('err', t('flash.fofejezet.nincs', [], 'Nincs ilyen főfejezet.'));
                back(['p' => 'articles']);
            }
            $on   = post('on') !== '' ? post('on') === '1' : (int)$m['is_published'] === 0;
            $name = trim($m['chapter_no'] . ' ' . $m['title']);

            if ($scope === 'all') {
                $c = $db->prepare('SELECT count(*) FROM help_module WHERE chapter_no = ?');
                $c->execute([$m['chapter_no']]);
                $n = (int)$c->fetchColumn();
                $db->prepare('UPDATE help_module SET is_published = ? WHERE chapter_no = ?')
                   ->execute([$on ? 1 : 0, $m['chapter_no']]);
            } else {
                $n = 1;
                $db->prepare('UPDATE help_module SET is_published = ? WHERE id = ?')
                   ->execute([$on ? 1 : 0, $id]);
            }

            audit_me($db, 'module.toggle', 'module:' . $id,
                     ($on ? 'lathato' : 'elrejtve') . ', ' . $scope . ', ' . $n . ' nyelv');
            flash('ok', visibility_flash($on, $name, $scope, $n, (string)$m['lang']));
            back(['p' => 'articles', 'lang' => (string)$m['lang']]);
        }

        case 'article.meta': {
            $id = (int)post('id');
            $slug = post('slug');
            $chapter = post('chapter_no');
            // A cim mar NEM ezen az urlapon van (a szerkeszto tetejen irod),
            // ezert ha nem erkezik, a meglevo marad. Enelkul az "Adatok
            // mentese" mindig azzal szallt el, hogy a cim ures.
            $title = post('title');
            if ($title === '') {
                $q = $db->prepare('SELECT title FROM help_article WHERE id = ?');
                $q->execute([$id]);
                $title = (string)$q->fetchColumn();
            }
            if ($title === '') {
                flash('err', t('flash.article.meta.cim-ures'));
                back(['p' => 'articles', 'id' => $id]);
            }
            if ($slug === '') { $slug = help_slug($chapter, $title); }

            // Ha ez a fejezet a FOFEJEZET LEIRASA, akkor a szama egyben a
            // fofejezet szama is. Ilyenkor az atirasa a fofejezetet es az
            // osszes alatta levo fejezetet is atszamozza, minden nyelven.
            $cascaded = module_number_cascade($db, $id, mb_substr($chapter, 0, 16));

            // Az urlap csak azt allitja, ami valoban ide tartozik. A
            // sorrendet huzassal rendezed, a lathatosagot a szem ikonnal -
            // ezert azokat itt NEM irjuk felul (korabban egy "Adatok
            // mentese" eleg volt hozza, hogy a sorrend nullazodjon).
            try {
                $db->prepare('UPDATE help_article
                                 SET chapter_no = ?, slug = ?, title = ?, module_id = ?
                               WHERE id = ?')
                   ->execute([
                       mb_substr($chapter, 0, 16), mb_substr($slug, 0, 160), mb_substr($title, 0, 255),
                       (int)post('module_id') ?: null,
                       $id,
                   ]);
            } catch (PDOException $e) {
                flash('err', str_contains($e->getMessage(), 'help_article_slug_lang_key')
                    ? t('flash.slug.foglalt')
                    : t('flash.mentesi.hiba', ['reszlet' => h($e->getMessage())]));
                back(['p' => 'articles', 'id' => $id]);
            }
            audit_me($db, 'article.meta', 'article:' . $id, $cascaded > 0 ? $cascaded . ' atszamozva' : null);
            flash('ok', $cascaded > 0
                ? t('flash.article.meta.atszamozva', ['n' => $cascaded])
                : t('flash.article.meta.fejezet-adatai-mentve'));
            back(['p' => 'articles', 'id' => $id]);
        }

        case 'article.create': {
            $lang    = array_key_exists(post('lang'), admin_langs()) ? post('lang') : 'hu';
            $module  = (int)post('module_id');
            $chapter = post('chapter_no');
            $title   = post('title');
            if ($module === 0) {
                flash('err', t('flash.article.create.modult-adni'));
                back(['p' => 'articles', 'lang' => $lang]);
            }
            // A "+" gomb cim nelkul hozza letre a fejezetet: a cimet a
            // szerkesztoben irja be a szerkeszto, ez az elso mezo ott.
            $untitled = $title === '';
            // Ha nincs megadva fejezetszam, a modul alatti kovetkezo szabad
            // szamot kapja (pl. az "1 Elso lepesek" modulban 1.4 utan 1.5-ot),
            // es a lista vegere kerul. Igy nem marad szam nelkuli fejezet.
            [$chapter, $sortOrder] = article_next_slot($db, $module, $lang, $chapter, post('sort_order'));

            if ($untitled) {
                // A fofejezet LEIRASA orokli a fofejezet nevet - annak mar van
                // neve, felesleges "Nevtelen fejezet"-kent letrehozni, aztan
                // kezzel beirni ugyanazt. (A leirast a szama arulja el: az
                // megegyezik a fofejezetevel.)
                $mq = $db->prepare('SELECT chapter_no, title FROM help_module WHERE id = ?');
                $mq->execute([$module]);
                $mrow = $mq->fetch();
                $title = ($mrow && trim((string)$mrow['chapter_no']) === trim($chapter))
                    ? (string)$mrow['title']
                    : t('ujfejezet.nev');
            }

            $slug = post('slug') !== '' ? post('slug') : help_slug($chapter, $title);

            $ver = (string)$db->query("SELECT COALESCE(MAX(doc_version), 'v1') FROM help_article")->fetchColumn();
            try {
                $st = $db->prepare("INSERT INTO help_article
                        (module_id, chapter_no, slug, title, lang, body_html, plain_text, doc_version,
                         updated_at, content_hash, sort_order, is_published, draft_html, draft_by, draft_at, source)
                        VALUES (?,?,?,?,?,'','',?, CURRENT_DATE, MD5(?), ?, 0, '', ?, NOW(), 'editor')");
                $st->execute([
                    $module, mb_substr($chapter, 0, 16), mb_substr($slug, 0, 160), mb_substr($title, 0, 255),
                    $lang, $ver, $slug . $title, $sortOrder, auth_user()['id'],
                ]);
                $newId = (int)$db->lastInsertId();
            } catch (PDOException $e) {
                flash('err', str_contains($e->getMessage(), 'help_article_slug_lang_key')
                    ? t('flash.slug.foglalt')
                    : t('flash.letrehozasi.hiba', ['reszlet' => h($e->getMessage())]));
                back(['p' => 'articles', 'lang' => $lang]);
            }
            audit_me($db, 'article.create', 'article:' . $newId, $title);
            flash('ok', t($untitled ? 'flash.article.create.cimtelen' : 'flash.article.create.kesz'));
            back(['p' => 'articles', 'lang' => $lang, 'id' => $newId]);
        }

        // A torles nem semmisit meg semmit: a cikk teljes allapota (szakaszaival es
        // a hozza tartozo kepernyo-hozzarendelesekkel egyutt) a Kukaba kerul,
        // ahonnan egy kattintassal visszaallithato.
        case 'article.delete': {
            $id = (int)post('id');
            $st = $db->prepare('SELECT * FROM help_article WHERE id = ?');
            $st->execute([$id]);
            $a = $st->fetch();
            if (!$a) {
                flash('err', t('flash.article.delete.nincs-ilyen-fejezet'));
                back(['p' => 'articles']);
            }
            $family = $siblings($db, $a);
            $tids = [];
            foreach ($family as $one) { $tids[] = trash_article($db, $one, auth_user()['id']); }
            audit_me($db, 'article.delete', 'article:' . $id, $a['title'] . ' (' . count($family) . ' nyelv)');

            $extra = count($family) > 1
                ? ' A(z) ' . (count($family) - 1) . ' idegen nyelvű változatával együtt.'
                : '';
            flash('ok', t('flash.article.delete.kesz',
                          ['nev' => h($a['chapter_no'] . ' ' . $a['title'])])
                . $extra . ' '
                . undo_button($db, 'trash.restore-many', ['ids' => $tids], t('undo.restore')));
            back(['p' => 'articles', 'lang' => $a['lang']]);
        }

        case 'article.restore': {
            $id  = (int)post('id');
            $rev = (int)post('rev');
            $st = $db->prepare('SELECT title, body_html FROM help_article_revision WHERE article_id = ? AND rev_no = ?');
            $st->execute([$id, $rev]);
            $r = $st->fetch();
            if (!$r) {
                flash('err', t('flash.article.restore.nincs-ilyen-korabbi-valtozat'));
                back(['p' => 'articles', 'id' => $id]);
            }
            $db->prepare('UPDATE help_article SET draft_html = ?, draft_title = ?, draft_by = ?, draft_at = now() WHERE id = ?')
               ->execute([$r['body_html'], $r['title'], auth_user()['id'], $id]);
            audit_me($db, 'article.restore', 'article:' . $id, 'rev ' . $rev);
            flash('ok', "A(z) {$rev}. változat betöltve vázlatként. Nézd át, és tedd közzé, ha jó.");
            back(['p' => 'articles', 'id' => $id]);
        }

        // ---------- a legutobbi kozzetetel visszavonasa ----------
        case 'article.unpublish': {
            $id = (int)post('id');
            $st = $db->prepare('CALL help_unpublish(?, ?, @help_ok)');
            $st->execute([$id, auth_user()['id']]);
            $st->closeCursor();
            $ok = (int)$db->query('SELECT @help_ok')->fetchColumn() === 1;

            if (!$ok) {
                flash('warn', t('flash.article.unpublish.ezen-fejezeten-volt-kozzetetel'));
                back(['p' => 'articles', 'id' => $id]);
            }
            $b = $db->prepare('SELECT body_html FROM help_article WHERE id = ?');
            $b->execute([$id]);
            sections_rebuild($db, $id, (string)$b->fetchColumn());

            audit_me($db, 'article.unpublish', 'article:' . $id);
            flash('ok', t('flash.article.unpublish.kesz'));
            back(['p' => 'articles', 'id' => $id]);
        }

        // ---------- tomeges muveletek a fejezetlistan ----------
        case 'articles.bulk': {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
            $op   = post('op');
            $lang = array_key_exists(post('lang'), admin_langs()) ? post('lang') : 'hu';

            if (!$ids) {
                flash('warn', t('flash.articles.bulk.jeloltel-ki-egyetlen-fejezetet'));
                back(['p' => 'articles', 'lang' => $lang]);
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $n = 0;

            switch ($op) {
                case 'publish-on': {
                    // ures idegen nyelvu fejezetet nem kapcsolunk be
                    $chk = $db->prepare("SELECT id, lang, title FROM help_article WHERE id IN ($in)");
                    $chk->execute($ids);
                    $blocked = [];
                    $okIds = [];
                    foreach ($chk->fetchAll() as $row) {
                        $body = $db->prepare('SELECT COALESCE(draft_html, body_html) FROM help_article WHERE id = ?');
                        $body->execute([$row['id']]);
                        if ($row['lang'] !== 'hu' && trim(help_plain((string)$body->fetchColumn())) === '') {
                            $blocked[] = strtoupper($row['lang']) . ' ' . $row['title'];
                            continue;
                        }
                        $okIds[] = (int)$row['id'];
                    }
                    if ($blocked) {
                        flash('warn', t('flash.bulk.kimaradt', [
                            'n'    => count($blocked),
                            'lista' => h(implode(', ', array_slice($blocked, 0, 5)))
                                     . (count($blocked) > 5 ? ' …' : ''),
                        ]));
                    }
                    if (!$okIds) { break; }
                    $inOk = implode(',', array_fill(0, count($okIds), '?'));
                    $st = $db->prepare("UPDATE help_article SET is_published = 1 WHERE id IN ($inOk)");
                    $st->execute($okIds);
                    $n = $st->rowCount();
                    audit_me($db, 'articles.bulk', 'publish-on', (string)$n);
                    flash('ok', t('flash.bulk.bekapcsolva', ['n' => $n]));
                    break;
                }

                case 'publish-off': {
                    $st = $db->prepare("UPDATE help_article SET is_published = 0 WHERE id IN ($in)");
                    $st->execute($ids);
                    $n = $st->rowCount();
                    audit_me($db, 'articles.bulk', 'publish-off', (string)$n);
                    flash('ok', t('flash.bulk.kikapcsolva', ['n' => $n]) . ' '
                        . undo_button($db, 'articles.bulk', ['op' => 'publish-on',
                                                             'lang' => $lang, 'ids' => $ids], t('undo.undo')));
                    break;
                }

                case 'publish-drafts': {
                    $st = $db->prepare("SELECT id FROM help_article WHERE id IN ($in) AND draft_html IS NOT NULL");
                    $st->execute($ids);
                    $summary = post('summary');
                    foreach ($st->fetchAll() as $r) {
                        try {
                            $db->beginTransaction();
                            $pub = $db->prepare('CALL help_publish(?, ?, ?, ?, NULL, ?)');
                            $pub->execute([$r['id'], auth_user()['id'], $summary !== '' ? $summary : null,
                                           'mod', isset($_POST['minor']) ? 1 : 0]);
                            $pub->closeCursor();
                            $b = $db->prepare('SELECT body_html FROM help_article WHERE id = ?');
                            $b->execute([$r['id']]);
                            sections_rebuild($db, (int)$r['id'], (string)$b->fetchColumn());
                            $db->commit();
                            $n++;
                        } catch (Throwable $e) {
                            if ($db->inTransaction()) { $db->rollBack(); }
                        }
                    }
                    audit_me($db, 'articles.bulk', 'publish-drafts', (string)$n);
                    flash('ok', t('flash.bulk.kozzeteve', ['n' => $n]));
                    break;
                }

                case 'move': {
                    $moduleId = (int)post('module_id');
                    if ($moduleId === 0) {
                        flash('err', t('flash.move.valaszd-ki-melyik-modulba'));
                        back(['p' => 'articles', 'lang' => $lang]);
                    }
                    // az eredeti modulok feljegyzese, hogy a visszavonas is mukodjon
                    $old = $db->prepare("SELECT id, module_id FROM help_article WHERE id IN ($in)");
                    $old->execute($ids);
                    $before = $old->fetchAll();

                    $st = $db->prepare("UPDATE help_article SET module_id = ? WHERE id IN ($in)");
                    $st->execute([$moduleId, ...$ids]);
                    $n = $st->rowCount();

                    $tid = trash_put($db, 'move', 'Áthelyezés visszavonása', $lang,
                                     ['articles' => $before], auth_user()['id']);
                    audit_me($db, 'articles.bulk', 'move', "{$n} -> module {$moduleId}");
                    flash('ok', t('flash.bulk.athelyezve', ['n' => $n]) . ' '
                        . undo_button($db, 'trash.restore', ['id' => $tid], t('undo.undo')));
                    break;
                }

                case 'delete': {
                    $st = $db->prepare("SELECT * FROM help_article WHERE id IN ($in)");
                    $st->execute($ids);
                    $tids = [];
                    $done = [];
                    foreach ($st->fetchAll() as $a) {
                        foreach ($siblings($db, $a) as $one) {
                            if (isset($done[(int)$one['id']])) { continue; }
                            $done[(int)$one['id']] = true;
                            $tids[] = trash_article($db, $one, auth_user()['id']);
                            $n++;
                        }
                    }
                    audit_me($db, 'articles.bulk', 'delete', (string)$n);
                    flash('ok', t('flash.bulk.kukaba', ['n' => $n]) . ' '
                        . undo_button($db, 'trash.restore-many', ['ids' => $tids], t('undo.restore.all')));
                    break;
                }

                default:
                    flash('err', t('flash.delete.ismeretlen-muvelet'));
            }
            back(['p' => 'articles', 'lang' => $lang]);
        }

        // ---------- sorrend huzassal ----------
        case 'articles.reorder': {
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['order'] ?? []))));
            $moduleId = (int)post('module_id');
            if (!$ids) { help_json(['ok' => false, 'error' => 'Üres sorrend.'], 400); }

            $db->beginTransaction();
            try {
                $st = $db->prepare('UPDATE help_article SET sort_order = ?'
                    . ($moduleId > 0 ? ', module_id = ?' : '') . ' WHERE id = ?');
                foreach ($ids as $i => $id) {
                    $st->execute($moduleId > 0 ? [($i + 1) * 10, $moduleId, $id] : [($i + 1) * 10, $id]);
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                help_json(['ok' => false, 'error' => $e->getMessage()], 500);
            }
            // Ujraszamozas: a szam kovesse a sorrendet. Enelkul egy athuzott
            // fejezet a regi szamat vitte magaval (5.2 a 6-os fofejezetben),
            // es a fofejezeten belul is osszekeveredtek a szamok.
            $renamed = 0;
            $from    = (int)post('from_module');
            try {
                $lang = (string)post('lang');
                if ($lang === '') {
                    $q = $db->prepare('SELECT lang FROM help_article WHERE id = ?');
                    $q->execute([$ids[0]]);
                    $lang = (string)$q->fetchColumn();
                }
                if ($moduleId > 0) { $renamed += renumber_module($db, $moduleId, $lang); }
                if ($from > 0 && $from !== $moduleId) { $renamed += renumber_module($db, $from, $lang); }
            } catch (Throwable $e) {
                // az ujraszamozas sosem akaszthatja meg a sorrend mentest
                $renamed = 0;
            }

            audit_me($db, 'articles.reorder', $moduleId > 0 ? 'module:' . $moduleId : null,
                     count($ids) . ' elem, ' . $renamed . ' ujraszamozva');
            help_json(['ok' => true, 'count' => count($ids), 'renamed' => $renamed]);
        }

        case 'modules.reorder': {
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['order'] ?? []))));
            if (!$ids) { help_json(['ok' => false, 'error' => 'Üres sorrend.'], 400); }
            $db->beginTransaction();
            try {
                // a sorrend nyelvfuggetlen: a testvereket is allitjuk
                $st = $db->prepare('UPDATE help_module SET sort_order = ?
                                     WHERE chapter_no = (SELECT chapter_no FROM (
                                             SELECT chapter_no FROM help_module WHERE id = ?) AS x)');
                foreach ($ids as $i => $id) { $st->execute([($i + 1) * 10, $id]); }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                help_json(['ok' => false, 'error' => $e->getMessage()], 500);
            }
            audit_me($db, 'modules.reorder', null, count($ids) . ' elem');
            help_json(['ok' => true, 'count' => count($ids)]);
        }

        // ---------- kuka ----------
        case 'trash.restore':
        case 'trash.restore-many': {
            $ids = $action === 'trash.restore'
                ? [(int)post('id')]
                : array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            $ok = 0; $err = [];
            foreach ($ids as $tid) {
                try {
                    trash_restore($db, $tid);
                    $ok++;
                } catch (Throwable $e) {
                    $err[] = $e->getMessage();
                }
            }
            audit_me($db, 'trash.restore', null, (string)$ok);
            if ($ok) { flash('ok', t('flash.trash.restore.kesz', ['n' => $ok])); }
            foreach (array_slice($err, 0, 3) as $e) { flash('err', h($e)); }
            back(['p' => (string)($_POST['back'] ?? 'trash')]);
        }

        case 'trash.purge': {
            $id = (int)post('id');
            if ($id > 0) {
                $db->prepare('DELETE FROM help_trash WHERE id = ?')->execute([$id]);
                flash('ok', t('flash.trash.purge.veglegesen-torolve'));
            } else {
                $n = $db->exec('DELETE FROM help_trash WHERE restored_at IS NULL');
                flash('ok', t('flash.trash.purge.mind', ['n' => $n]));
            }
            audit_me($db, 'trash.purge', $id > 0 ? 'trash:' . $id : 'all');
            back(['p' => 'trash']);
        }

        // ================================================== modulok
        // Uj fofejezet EGY kattintassal, ugy mint a sima fejezetnel: letrejon
        // minden nyelven, kap egy LEIRAS fejezetet (a szama = a fofejezet
        // szama), es rogton a szerkeszto nyilik meg rajta. Igy nem kell elobb
        // egy ablakban nevet adni, aztan megkeresni, hova irhatnank, hogy
        // mire valo az a menupont.
        case 'module.quick': {
            $lang = array_key_exists(post('lang'), admin_langs()) ? post('lang') : admin_source_lang();
            $name = t('ujfofejezet.nev');

            $no = (string)((int)$db->query(
                "SELECT COALESCE(MAX(CAST(chapter_no AS UNSIGNED)), 0) FROM help_module"
            )->fetchColumn() + 1);
            $sort = (int)$db->query('SELECT COALESCE(MAX(sort_order), 0) FROM help_module')->fetchColumn() + 10;
            $slug = help_slug($no, $name);

            $db->beginTransaction();
            try {
                $ins = $db->prepare('INSERT INTO help_module (chapter_no, slug, title, lang, sort_order) VALUES (?,?,?,?,?)');
                $mineId = 0;
                foreach (array_keys(admin_langs()) as $l) {
                    $ins->execute([$no, mb_substr($slug, 0, 120), $name, $l, $sort]);
                    if ($l === $lang) { $mineId = (int)$db->lastInsertId(); }
                }
                if ($mineId === 0) {
                    $q = $db->prepare('SELECT id FROM help_module WHERE chapter_no = ? AND lang = ?');
                    $q->execute([$no, $lang]);
                    $mineId = (int)$q->fetchColumn();
                }

                // a leiras fejezet: a szama MEGEGYEZIK a fofejezetevel
                $ver = (string)$db->query("SELECT COALESCE(MAX(doc_version), 'v1') FROM help_article")->fetchColumn();
                $db->prepare("INSERT INTO help_article
                        (module_id, chapter_no, slug, title, lang, body_html, plain_text, doc_version,
                         updated_at, content_hash, sort_order, is_published, draft_html, draft_by, draft_at, source)
                        VALUES (?,?,?,?,?,'','',?, CURRENT_DATE, MD5(?), 10, 0, '', ?, NOW(), 'editor')")
                   ->execute([$mineId, $no, mb_substr($slug, 0, 160), $name, $lang, $ver, $slug, auth_user()['id']]);
                $newId = (int)$db->lastInsertId();
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                flash('err', t('flash.letrehozasi.hiba', ['reszlet' => h($e->getMessage())]));
                back(['p' => 'articles', 'lang' => $lang]);
            }

            audit_me($db, 'module.quick', 'module:' . $mineId, $no);
            flash('ok', t('flash.module.quick.kesz', ['szam' => h($no)]));
            back(['p' => 'articles', 'lang' => $lang, 'id' => $newId]);
        }

        case 'module.save': {
            $id    = (int)post('id');
            $lang  = array_key_exists(post('lang'), admin_langs()) ? post('lang') : 'hu';
            $title = post('title');
            if ($title === '') {
                flash('err', t('flash.module.save.fofejezet-neve-ures'));
                back(['p' => post('from') === 'articles' ? 'articles' : 'modules', 'lang' => $lang]);
            }
            $slug = post('slug') !== '' ? post('slug') : help_slug(post('chapter_no'), $title);

            try {
                if ($id > 0) {
                    // A szam, az URL es a sorrend MINDEN nyelven egyutt valtozik
                    // (ez koti ossze a nyelvi valtozatokat), a nev viszont
                    // nyelvenkent kulon allithato.
                    $cur = $db->prepare('SELECT chapter_no FROM help_module WHERE id = ?');
                    $cur->execute([$id]);
                    $oldNo = (string)$cur->fetchColumn();

                    $no   = mb_substr(post('chapter_no'), 0, 16);
                    $sort = (int)post('sort_order');

                    $fam = $db->prepare('SELECT id, lang FROM help_module WHERE chapter_no = ?');
                    $fam->execute([$oldNo]);
                    $family = $fam->fetchAll();

                    $up = $db->prepare('UPDATE help_module SET chapter_no = ?, slug = ?, title = ?, sort_order = ? WHERE id = ?');
                    foreach ($family as $one) {
                        $l = (string)$one['lang'];
                        $t = post('title_' . $l);
                        if ($t === '') { $t = (int)$one['id'] === $id ? $title : ''; }
                        if ($t === '') {
                            // ezen a nyelven nem adtak nevet: marad a regi
                            $g = $db->prepare('SELECT title FROM help_module WHERE id = ?');
                            $g->execute([$one['id']]);
                            $t = (string)$g->fetchColumn();
                        }
                        $up->execute([$no, mb_substr($slug, 0, 120), mb_substr($t, 0, 255), $sort, (int)$one['id']]);
                    }

                    // a fejezetek szama koveti a fofejezet szamat (5.x -> 6.x)
                    if ($no !== '' && $oldNo !== '' && $no !== $oldNo) {
                        $ids = implode(',', array_map(static fn($r) => (int)$r['id'], $family));
                        $db->prepare("UPDATE help_article
                                         SET chapter_no = CONCAT(?, SUBSTRING(chapter_no, CHAR_LENGTH(?) + 1))
                                       WHERE module_id IN ($ids)
                                         AND chapter_no LIKE CONCAT(?, '.%')")
                           ->execute([$no, $oldNo, $oldNo]);
                    }
                } else {
                    // Uj fofejezet: a lista VEGERE kerul, es MINDEN nyelven
                    // letrejon - kulonben az angol/nemet oldalon nem lenne
                    // hova tenni a leforditott fejezeteket.
                    $no = mb_substr(post('chapter_no'), 0, 16);
                    if ($no === '') {
                        $no = (string)((int)$db->query(
                            "SELECT COALESCE(MAX(CAST(chapter_no AS UNSIGNED)), 0) FROM help_module WHERE lang = 'hu'"
                        )->fetchColumn() + 1);
                    }
                    $sort = (int)post('sort_order');
                    if ($sort === 0) {
                        $sort = (int)$db->query('SELECT COALESCE(MAX(sort_order), 0) FROM help_module')->fetchColumn() + 10;
                    }

                    $ins = $db->prepare('INSERT INTO help_module (chapter_no, slug, title, lang, sort_order) VALUES (?,?,?,?,?)');
                    foreach (array_keys(admin_langs()) as $l) {
                        $exists = $db->prepare('SELECT 1 FROM help_module WHERE chapter_no = ? AND lang = ?');
                        $exists->execute([$no, $l]);
                        if ($exists->fetchColumn()) { continue; }
                        $ins->execute([$no, mb_substr($slug, 0, 120), mb_substr($title, 0, 255), $l, $sort]);
                    }
                }
            } catch (PDOException $e) {
                flash('err', t('flash.mentesi.hiba', ['reszlet' => h($e->getMessage())]));
                back(['p' => 'modules', 'lang' => $lang]);
            }
            audit_me($db, 'module.save', 'module:' . ($id ?: 'new'), $title);
            flash('ok', t($id > 0 ? 'flash.module.save.mentve' : 'flash.module.save.uj'));
            // ha a Fejezetek fulrol nyitottak, oda terjunk vissza
            back(['p' => post('from') === 'articles' ? 'articles' : 'modules', 'lang' => $lang]);
        }

        case 'module.delete': {
            $id   = (int)post('id');
            $lang = post('lang', 'hu');

            $m = $db->prepare('SELECT * FROM help_module WHERE id = ?');
            $m->execute([$id]);
            $row = $m->fetch();
            if (!$row) {
                flash('err', t('flash.module.delete.nincs-ilyen-fofejezet'));
                back(['p' => post('from') === 'articles' ? 'articles' : 'modules', 'lang' => $lang]);
            }

            // A fofejezet mindharom nyelven letezik ugyanazzal a szammal, es
            // egyben mozog: kulonben az egyik nyelven torolt fofejezet a
            // tobbin ottmaradna, es ugy tunne, hogy "nem torol".
            $sib = $db->prepare('SELECT * FROM help_module WHERE chapter_no = ?');
            $sib->execute([$row['chapter_no']]);
            $family = $sib->fetchAll();
            if (!$family) { $family = [$row]; }

            $ids = array_map(static fn(array $r): int => (int)$r['id'], $family);
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $cnt = $db->prepare("SELECT lang, COUNT(*) n FROM help_article
                                  WHERE module_id IN ($in) GROUP BY lang");
            $cnt->execute($ids);
            $byLang = $cnt->fetchAll();

            if ($byLang) {
                $parts = array_map(
                    static fn(array $r): string => strtoupper((string)$r['lang']) . ': ' . (int)$r['n'],
                    $byLang);
                flash('err', t('flash.module.delete.nem-ures',
                               ['lista' => h(implode(', ', $parts))]));
                back(['p' => post('from') === 'articles' ? 'articles' : 'modules', 'lang' => $lang]);
            }

            $tids = [];
            foreach ($family as $one) { $tids[] = trash_module($db, $one, auth_user()['id']); }
            audit_me($db, 'module.delete', 'module:' . $id, $row['title'] . ' (' . count($family) . ' nyelv)');
            flash('ok', t('flash.module.delete.kesz', ['n' => count($family)]) . ' '
                . undo_button($db, 'trash.restore-many', ['ids' => $tids, 'back' => 'modules'], t('undo.restore')));
            back(['p' => post('from') === 'articles' ? 'articles' : 'modules', 'lang' => $lang]);
        }

        // ================================================== Word import
        case 'import.upload': {
            $lang = array_key_exists(post('lang'), admin_langs()) ? post('lang') : 'hu';
            $f = $_FILES['docx'] ?? null;

            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $codes = [
                    UPLOAD_ERR_INI_SIZE  => 'A fájl nagyobb, mint amennyit a PHP elfogad (upload_max_filesize).',
                    UPLOAD_ERR_FORM_SIZE => 'A fájl túl nagy.',
                    UPLOAD_ERR_PARTIAL   => 'A feltöltés félbeszakadt.',
                    UPLOAD_ERR_NO_FILE   => 'Nem választottál fájlt.',
                    UPLOAD_ERR_NO_TMP_DIR=> 'Hiányzik az ideiglenes mappa a szerveren.',
                    UPLOAD_ERR_CANT_WRITE=> 'A szerver nem tudta lemezre írni a fájlt.',
                ];
                flash('err', h($codes[$f['error'] ?? UPLOAD_ERR_NO_FILE] ?? t('flash.upload.hiba')));
                back(['p' => 'import', 'lang' => $lang]);
            }
            if (!preg_match('/\.docx$/i', (string)$f['name'])) {
                flash('err', t('flash.import.upload.csak-docx-fajlt-tudok'));
                back(['p' => 'import', 'lang' => $lang]);
            }

            try {
                $parser = new DocxParser($cfg['media_dir']);
                $res = $parser->parse((string)$f['tmp_name']);
            } catch (Throwable $e) {
                flash('err', t('flash.import.upload.hiba', ['reszlet' => h($e->getMessage())]));
                back(['p' => 'import', 'lang' => $lang]);
            }

            $chapters = 0;
            foreach ($res['modules'] as $m) { $chapters += count($m['articles']); }
            if ($chapters === 0) {
                flash('warn', t('flash.import.upload.nincs-fejezet'));
                back(['p' => 'import', 'lang' => $lang]);
            }

            $db->beginTransaction();
            $st = $db->prepare('INSERT INTO help_import (filename, lang, bytes, status, stats, uploaded_by)
                                VALUES (?,?,?,?,?,?)');
            $st->execute([
                mb_substr((string)$f['name'], 0, 255), $lang, (int)$f['size'], 'parsed',
                json_encode(['chapters' => $chapters, 'images' => $res['images'], 'paragraphs' => $res['paragraphs']], JSON_UNESCAPED_UNICODE),
                auth_user()['id'],
            ]);
            $importId = (int)$db->lastInsertId();

            $ins = $db->prepare('INSERT INTO help_import_item
                    (import_id, seq, chapter_no, title, slug, module_no, module_title,
                     body_html, plain_text, img_count, article_id, match_state, similarity)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $find = $db->prepare('SELECT id, plain_text FROM help_article WHERE lang = ? AND (chapter_no = ? OR slug = ?) LIMIT 1');

            $seq = 0;
            foreach ($res['modules'] as $m) {
                foreach ($m['articles'] as $a) {
                    $html  = help_clean_html($a['html']);
                    [$html] = help_anchorize($html);
                    $plain = help_plain($html);
                    $slug  = help_slug($a['no'], $a['title']);

                    $find->execute([$lang, $a['no'], $slug]);
                    $cur = $find->fetch();

                    $state = 'new';
                    $sim   = 0.0;
                    if ($cur) {
                        $sim   = diff_similarity((string)$cur['plain_text'], $plain);
                        $state = $sim >= 99.9 ? 'same' : 'matched';
                    }

                    $ins->execute([
                        $importId, $seq++, mb_substr($a['no'], 0, 16), mb_substr($a['title'], 0, 255),
                        mb_substr($slug, 0, 160), mb_substr($m['no'], 0, 16), mb_substr($m['title'], 0, 255),
                        $html, $plain, (int)$a['imgs'],
                        $cur['id'] ?? null, $state, $sim,
                    ]);
                }
            }
            $db->commit();
            audit_me($db, 'import.upload', 'import:' . $importId, $f['name'] . " ({$chapters} fejezet)");

            flash('ok', t('flash.import.upload.kesz',
                          ['n' => $chapters, 'kepek' => (int)$res['images']]));
            back(['p' => 'import', 'lang' => $lang, 'import' => $importId]);
        }

        case 'import.apply': {
            $importId = (int)post('import_id');
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['items'] ?? []))));
            if (!$ids) {
                flash('warn', t('flash.import.apply.jeloltel-ki-egyetlen-fejezetet'));
                back(['p' => 'import', 'import' => $importId]);
            }
            $publish = isset($_POST['publish_now']);

            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $db->prepare("SELECT * FROM help_import_item WHERE import_id = ? AND id IN ($in) ORDER BY seq");
            $st->execute([$importId, ...$ids]);
            $items = $st->fetchAll();

            $lang = (string)$db->query('SELECT lang FROM help_import WHERE id = ' . $importId)->fetchColumn();
            $created = 0; $updated = 0; $published = 0; $errors = [];

            foreach ($items as $it) {
                try {
                    $db->beginTransaction();
                    $articleId = (int)($it['article_id'] ?: 0);

                    if ($articleId === 0) {
                        // modul megkeresese vagy letrehozasa
                        $mst = $db->prepare('SELECT id FROM help_module WHERE lang = ? AND (chapter_no = ? OR title = ?) LIMIT 1');
                        $mst->execute([$lang, $it['module_no'], $it['module_title']]);
                        $moduleId = (int)($mst->fetchColumn() ?: 0);
                        if ($moduleId === 0) {
                            // MariaDB nem enged ugyanabbol a tablabol olvasni INSERT kozben
                            $nextSort = (int)$db->query(
                                'SELECT COALESCE(MAX(sort_order),0)+10 FROM help_module WHERE lang = '
                                . $db->quote($lang))->fetchColumn();
                            $mi = $db->prepare('INSERT INTO help_module (chapter_no, slug, title, lang, sort_order)
                                                VALUES (?,?,?,?,?)');
                            $mi->execute([
                                $it['module_no'], help_slug($it['module_no'], $it['module_title']),
                                $it['module_title'], $lang, $nextSort,
                            ]);
                            $moduleId = (int)$db->lastInsertId();
                        }

                        $ver = (string)$db->query("SELECT COALESCE(MAX(doc_version), 'v1') FROM help_article")->fetchColumn();
                        $sortSt = $db->prepare('SELECT COALESCE(MAX(sort_order),0)+10 FROM help_article WHERE module_id = ?');
                        $sortSt->execute([$moduleId]);
                        $nextSort = (int)$sortSt->fetchColumn();

                        $ai = $db->prepare("INSERT INTO help_article
                                (module_id, chapter_no, slug, title, lang, body_html, plain_text, doc_version,
                                 updated_at, content_hash, img_count, sort_order, is_published,
                                 draft_html, draft_by, draft_at, source)
                                VALUES (?,?,?,?,?,'','',?, CURRENT_DATE, MD5(?), ?, ?, 0, ?, ?, NOW(), 'word')");
                        $ai->execute([
                            $moduleId, $it['chapter_no'], $it['slug'], $it['title'], $lang, $ver,
                            $it['slug'] . $it['title'], (int)$it['img_count'], $nextSort,
                            $it['body_html'], auth_user()['id'],
                        ]);
                        $articleId = (int)$db->lastInsertId();
                        $created++;
                    } else {
                        $db->prepare('UPDATE help_article
                                         SET draft_html = ?, draft_title = ?, draft_by = ?, draft_at = now(), source = \'word\'
                                       WHERE id = ?')
                           ->execute([$it['body_html'], $it['title'], auth_user()['id'], $articleId]);
                        $updated++;
                    }

                    if ($publish) {
                        $pub = $db->prepare('CALL help_publish(?, ?, ?, ?, NULL, 0)');
                        $pub->execute([$articleId, auth_user()['id'],
                                       'Word-import: ' . $it['chapter_no'] . ' ' . $it['title'],
                                       $it['article_id'] ? 'mod' : 'new']);
                        $pub->closeCursor();
                        $b = $db->prepare('SELECT body_html FROM help_article WHERE id = ?');
                        $b->execute([$articleId]);
                        sections_rebuild($db, $articleId, (string)$b->fetchColumn());
                        $published++;
                    }

                    $db->prepare('UPDATE help_import_item SET applied = true, applied_at = now(), article_id = ? WHERE id = ?')
                       ->execute([$articleId, $it['id']]);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    $errors[] = $it['chapter_no'] . ' ' . $it['title'] . ': ' . $e->getMessage();
                }
            }

            $db->prepare('UPDATE help_import SET status = ? WHERE id = ?')->execute(['applied', $importId]);

            // Automatikus forditas: ha be van kapcsolva es van beallitva gepi fordito,
            // a frissen atvett MAGYAR fejezetekbol rogton keszul EN/DE vazlat is.
            $autoOk = 0; $autoFail = 0;
            if ($lang === 'hu' && mt_auto_on($db, $cfg)) {
                foreach ($items as $it) {
                    $aid = (int)($it['article_id'] ?: 0);
                    if ($aid === 0) { continue; }
                    $r = mt_auto_translate($db, $cfg, $aid, auth_user()['id']);
                    $autoOk += count($r['done']);
                    $autoFail += count($r['failed']);
                }
            }
            audit_me($db, 'import.apply', 'import:' . $importId, "{$created} új, {$updated} frissített, {$published} közzétett");

            $msg  = t('flash.import.apply.kesz', ['uj' => $created, 'frissitett' => $updated]);
            $msg .= $publish
                ? t('flash.import.apply.kozzeteve', ['n' => $published])
                : t('flash.import.apply.vazlatok');
            if ($autoOk || $autoFail) {
                $msg .= ' ' . t('flash.import.apply.mt', ['n' => $autoOk])
                      . ($autoFail ? ' ' . t('flash.import.apply.mt.hiba', ['n' => $autoFail]) : '.');
            }
            flash($errors ? 'warn' : 'ok', $msg);
            foreach (array_slice($errors, 0, 5) as $e) { flash('err', h($e)); }
            back(['p' => 'import', 'import' => $importId]);
        }

        case 'import.discard': {
            $importId = (int)post('import_id');
            $db->prepare('DELETE FROM help_import WHERE id = ?')->execute([$importId]);
            audit_me($db, 'import.discard', 'import:' . $importId);
            flash('ok', t('flash.import.discard.import-eldobva-fejezetek-erintetlenek'));
            back(['p' => 'import']);
        }

        // ================================================== fordítás
        case 'translate.machine': {
            $srcId = (int)post('src_id');
            $to    = post('to');
            if (!array_key_exists($to, admin_langs())) { help_json(['ok' => false, 'error' => 'Ismeretlen célnyelv.'], 400); }

            $st = $db->prepare('SELECT lang, body_html, draft_html, title, draft_title
                                  FROM help_article WHERE id = ?');
            $st->execute([$srcId]);
            $src = $st->fetch();
            if (!$src) { help_json(['ok' => false, 'error' => 'Nincs ilyen forrásfejezet.'], 404); }

            // A meg kozze nem tett vazlatot is forditjuk - kulonben egy frissen
            // megirt fejezetbol ures forditas keszult volna.
            if ($src['draft_html'] !== null && trim(help_plain((string)$src['draft_html'])) !== '') {
                $src['body_html'] = $src['draft_html'];
                if ((string)($src['draft_title'] ?? '') !== '') { $src['title'] = $src['draft_title']; }
            }
            if (trim(help_plain((string)$src['body_html'])) === '') {
                help_json(['ok' => false, 'error' => 'A magyar fejezetnek még nincs tartalma — nincs mit fordítani.'], 400);
            }

            try {
                $tr = Translator::fromConfig($cfg, $db);
                $html  = $tr->translateHtml((string)$src['body_html'], (string)$src['lang'], $to);
                $title = $tr->translateHtml('<p>' . htmlspecialchars((string)$src['title'], ENT_QUOTES, 'UTF-8') . '</p>', (string)$src['lang'], $to);
                $title = trim(help_plain($title));
            } catch (Throwable $e) {
                help_json(['ok' => false, 'error' => $e->getMessage()], 502);
            }
            audit_me($db, 'translate.machine', 'article:' . $srcId, $src['lang'] . '->' . $to . ' (' . $tr->provider . ')');
            help_json(['ok' => true, 'html' => help_clean_html($html), 'title' => $title, 'provider' => $tr->label()]);
        }

        case 'translate.save': {
            $srcId = (int)post('src_id');
            $to    = post('to');
            $how   = post('how', 'manual');
            $html  = help_clean_html((string)($_POST['body'] ?? ''));
            [$html] = help_anchorize($html);
            $title = post('title');
            $publishNow = isset($_POST['publish_now']);

            $db->beginTransaction();
            try {
                translate_store($db, $srcId, $to, $html, $title, $how, auth_user()['id'], $publishNow);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                flash('err', t('flash.translate.save.hiba', ['reszlet' => h($e->getMessage())]));
                back(['p' => 'translate', 'src' => $srcId, 'to' => $to]);
            }

            audit_me($db, 'translate.save', 'article:' . $srcId, 'hu->' . $to . ($publishNow ? ' + közzététel' : ''));
            flash('ok', t($publishNow ? 'flash.translate.save.kozzeteve' : 'flash.translate.save.vazlat'));
            back(['p' => 'translate', 'src' => $srcId, 'to' => $to]);
        }

        // Egy magyar fejezet automatikus leforditasa a tobbi nyelvre, kezzel inditva.
        case 'translate.auto': {
            $srcId = (int)post('src_id');
            $r = mt_auto_translate($db, $cfg, $srcId, auth_user()['id']);
            if ($r['done']) {
                flash('ok', t('flash.translate.auto.kesz',
                              ['nyelvek' => implode(', ', array_map('strtoupper', $r['done']))]));
            }
            foreach ($r['failed'] as $lang => $err) {
                flash('err', t('flash.translate.auto.hiba',
                               ['nyelv' => strtoupper($lang), 'reszlet' => h($err)]));
            }
            if (!$r['done'] && !$r['failed']) {
                flash('warn', t(match ($r['why'] ?? '') {
                    'ures'       => 'flash.translate.auto.ures',
                    'nem-magyar' => 'flash.translate.auto.nem-forras',
                    'nincs'      => 'flash.translate.auto.nincs',
                    default      => 'flash.translate.auto.semmi',
                }));
            }
            back(['p' => 'translate', 'src' => $srcId]);
        }

        // ================================================== képernyő-hozzárendelés
        case 'screen.save': {
            $id = (int)post('id');
            $route = post('route');
            $articleId = (int)post('article_id');
            if ($route === '' || $articleId === 0) {
                flash('err', t('flash.screen.save.utvonalat-fejezetet-is-adni'));
                back(['p' => 'screens']);
            }
            try {
                if ($id > 0) {
                    $db->prepare('UPDATE help_screen_map SET route = ?, article_id = ?, anchor = ?, is_verified = ? WHERE id = ?')
                       ->execute([mb_substr($route, 0, 160), $articleId, post('anchor') ?: null, isset($_POST['is_verified']) ? 1 : 0, $id]);
                } else {
                    $db->prepare('INSERT INTO help_screen_map (route, article_id, anchor, is_verified) VALUES (?,?,?,?)')
                       ->execute([mb_substr($route, 0, 160), $articleId, post('anchor') ?: null, isset($_POST['is_verified']) ? 1 : 0]);
                }
            } catch (PDOException $e) {
                flash('err', str_contains($e->getMessage(), 'help_screen_map_route_key')
                    ? t('flash.screen.save.foglalt')
                    : t('flash.mentesi.hiba', ['reszlet' => h($e->getMessage())]));
                back(['p' => 'screens']);
            }
            audit_me($db, 'screen.save', 'route:' . $route);
            flash('ok', t('flash.screen.save.hozzarendeles-mentve'));
            back(['p' => 'screens']);
        }

        case 'screen.delete': {
            $db->prepare('DELETE FROM help_screen_map WHERE id = ?')->execute([(int)post('id')]);
            audit_me($db, 'screen.delete', 'screen:' . post('id'));
            flash('ok', t('flash.screen.delete.hozzarendeles-torolve'));
            back(['p' => 'screens']);
        }

        // ================================================== képek és videók
        case 'media.upload': {
            $files = $_FILES['files'] ?? ($_FILES['images'] ?? null);
            if (!is_array($files) || !isset($files['tmp_name'])) {
                flash('err', t('flash.media.upload.valasztottal-fajlt'));
                back(['p' => 'media']);
            }
            $ok = 0; $skipped = 0; $problems = [];
            foreach (array_keys((array)$files['name']) as $i) {
                if ((int)$files['error'][$i] !== UPLOAD_ERR_OK) {
                    $skipped++;
                    $problems[] = $files['name'][$i] . ': ' . media_upload_error((int)$files['error'][$i]);
                    continue;
                }
                $r = media_store($db, $cfg, (string)$files['tmp_name'][$i], (string)$files['name'][$i], auth_user()['id']);
                if ($r['ok']) { $ok++; } else { $skipped++; $problems[] = $files['name'][$i] . ': ' . $r['error']; }
            }
            audit_me($db, 'media.upload', null, "{$ok} fajl");
            flash($ok ? 'ok' : 'err', t('flash.media.upload.kesz', ['n' => $ok])
                . ($skipped ? ' ' . t('flash.media.upload.kihagyva', ['n' => $skipped]) : ''));
            foreach (array_slice($problems, 0, 4) as $pr) { flash('warn', h($pr)); }
            back(['p' => 'media']);
        }

        // A szerkesztobol jovo kozvetlen feltoltes (kep vagy video), JSON valasszal.
        // Ez az, amit a "Kép" / "Videó" gomb es a fogd-és-vidd hasznal - nem kell
        // elore feltolteni a Kepek fulon, majd fajlnevet beirni.
        case 'media.inline': {
            $f = $_FILES['file'] ?? null;
            if (!is_array($f)) { help_json(['ok' => false, 'error' => 'Nem érkezett fájl.'], 400); }
            if ((int)$f['error'] !== UPLOAD_ERR_OK) {
                help_json(['ok' => false, 'error' => media_upload_error((int)$f['error'])], 400);
            }
            $r = media_store($db, $cfg, (string)$f['tmp_name'], (string)$f['name'], auth_user()['id']);
            if (!$r['ok']) { help_json(['ok' => false, 'error' => $r['error']], 400); }

            audit_me($db, 'media.inline', $r['filename'], $r['kind']);
            help_json([
                'ok'      => true,
                'url'     => $r['url'],
                'kind'    => $r['kind'],
                'html'    => media_snippet($r, ''),
                'existed' => $r['existed'],
                'name'    => $r['filename'],
            ]);
        }

        case 'media.delete': {
            $name = post('filename');
            if (!preg_match('/^(img|vid)_[0-9a-f]{12}\.[a-z0-9]{2,5}$/', $name)) {
                flash('err', t('flash.media.delete.ervenytelen-fajlnev'));
                back(['p' => 'media']);
            }
            $used = $db->prepare("SELECT count(*) FROM help_article
                                   WHERE body_html LIKE ? OR coalesce(draft_html, '') LIKE ?");
            $used->execute(['%' . $name . '%', '%' . $name . '%']);
            if ((int)$used->fetchColumn() > 0) {
                flash('err', t('flash.media.delete.fajlt-hasznalja-legalabb-fejezet'));
                back(['p' => 'media']);
            }
            @unlink(media_dir($cfg) . '/' . $name);
            $db->prepare('DELETE FROM help_media WHERE filename = ?')->execute([$name]);
            audit_me($db, 'media.delete', $name);
            flash('ok', t('flash.media.delete.fajl-torolve'));
            back(['p' => 'media']);
        }

        // ================================================== felhasználók
        case 'user.save': {
            if (!auth_is('admin')) { flash('err', t('flash.user.save.ehhez-adminisztratori-jog')); back(['p' => 'users']); }
            $id = (int)post('id');
            $username = post('username');
            $role = in_array(post('role'), HELP_ROLES, true) ? post('role') : 'editor';

            if ($id > 0) {
                $db->prepare('UPDATE help_user SET display_name = ?, email = ?, role = ?, is_active = ? WHERE id = ?')
                   ->execute([post('display_name'), post('email') ?: null, $role, isset($_POST['is_active']) ? 1 : 0, $id]);
                audit_me($db, 'user.update', 'user:' . $id);
                flash('ok', t('flash.user.save.felhasznalo-adatai-mentve'));
            } else {
                $pw = (string)($_POST['password'] ?? '');
                if ($username === '') { flash('err', t('flash.user.save.felhasznalonev-kotelezo')); back(['p' => 'users']); }
                if (($problem = auth_password_problem($pw, $username)) !== null) { flash('err', h($problem)); back(['p' => 'users']); }
                try {
                    $db->prepare('INSERT INTO help_user (username, password_hash, display_name, email, role, must_change_pw)
                                  VALUES (?,?,?,?,?,true)')
                       ->execute([$username, password_hash($pw, PASSWORD_BCRYPT, ['cost' => 10]),
                                  post('display_name') ?: $username, post('email') ?: null, $role]);
                } catch (PDOException $e) {
                    flash('err', t('flash.user.save.felhasznalonev-foglalt'));
                    back(['p' => 'users']);
                }
                audit_me($db, 'user.create', 'user:' . $username, $role);
                flash('ok', t('flash.user.save.felhasznalo-letrehozva-elso-belepeskor'));
            }
            back(['p' => 'users']);
        }

        case 'user.resetpw': {
            if (!auth_is('admin')) { flash('err', t('flash.user.resetpw.ehhez-adminisztratori-jog')); back(['p' => 'users']); }
            $id = (int)post('id');
            $pw = (string)($_POST['password'] ?? '');
            $un = $db->prepare('SELECT username FROM help_user WHERE id = ?');
            $un->execute([$id]);
            $username = (string)$un->fetchColumn();
            if (($problem = auth_password_problem($pw, $username)) !== null) { flash('err', h($problem)); back(['p' => 'users']); }

            $db->prepare('UPDATE help_user SET password_hash = ?, must_change_pw = true, failed_logins = 0, locked_until = NULL WHERE id = ?')
               ->execute([password_hash($pw, PASSWORD_BCRYPT, ['cost' => 10]), $id]);
            audit_me($db, 'user.resetpw', 'user:' . $username);
            flash('ok', t('flash.user.resetpw.kesz', ['nev' => h($username)]));
            back(['p' => 'users']);
        }

        case 'user.delete': {
            if (!auth_is('admin')) { flash('err', t('flash.user.delete.ehhez-adminisztratori-jog')); back(['p' => 'users']); }
            $id = (int)post('id');
            if ($id === (int)auth_user()['id']) {
                flash('err', t('flash.user.delete.sajat-magadat-torolheted'));
                back(['p' => 'users']);
            }
            $n = (int)$db->query("SELECT COUNT(*) FROM help_user WHERE role = 'admin' AND is_active = 1")->fetchColumn();
            $r = $db->prepare('SELECT role FROM help_user WHERE id = ?');
            $r->execute([$id]);
            if ($r->fetchColumn() === 'admin' && $n <= 1) {
                flash('err', t('flash.user.delete.utolso-adminisztratort-torolni'));
                back(['p' => 'users']);
            }
            $db->prepare('DELETE FROM help_user WHERE id = ?')->execute([$id]);
            audit_me($db, 'user.delete', 'user:' . $id);
            flash('ok', t('flash.user.delete.felhasznalo-torolve'));
            back(['p' => 'users']);
        }

        // ================================================== export
        case 'export.docx': {
            $lang = array_key_exists(post('lang'), admin_langs()) ? post('lang') : 'hu';
            $version = (string)$db->query("SELECT coalesce(max(doc_version), '') FROM help_article")->fetchColumn();
            try {
                $exp = new DocxExport($cfg['media_dir']);
                $r = $exp->build($db, $lang, [
                    'company'        => admin_setting($db, 'export_company', 'Infinity ERP'),
                    'version'        => $version,
                    'only_published' => !isset($_POST['include_hidden']),
                ]);
            } catch (Throwable $e) {
                flash('err', t('flash.export.hiba', ['reszlet' => h($e->getMessage())]));
                back(['p' => 'export']);
            }
            audit_me($db, 'export.docx', $lang, $r['chapters'] . ' fejezet, ' . $r['images'] . ' kép');

            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="' . $r['filename'] . '"');
            header('Content-Length: ' . (string)filesize($r['path']));
            header('X-Accel-Buffering: no');
            readfile($r['path']);
            @unlink($r['path']);
            exit;
        }

        case 'export.pdf': {
            $lang = array_key_exists(post('lang'), admin_langs()) ? post('lang') : 'hu';
            try {
                $r = export_pdf($db, $cfg, $lang, !isset($_POST['include_hidden']));
            } catch (Throwable $e) {
                flash('err', h($e->getMessage()));
                back(['p' => 'export']);
            }
            audit_me($db, 'export.pdf', $lang, help_bytes($r['bytes']));

            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $r['filename'] . '"');
            header('Content-Length: ' . (string)$r['bytes']);
            readfile($r['path']);
            @unlink($r['path']);
            exit;
        }

        // ================================================== beállítások
        case 'setting.save': {
            if (!auth_is('admin')) { flash('err', t('flash.setting.save.ehhez-adminisztratori-jog')); back(['p' => 'settings']); }
            $keys = ['site_title_hu', 'site_title_en', 'site_title_de',
                     'mt_provider', 'mt_endpoint', 'mt_key', 'mt_auto', 'mt_glossary',
                     'highlight_days', 'export_company', 'export_footer'];
            // a kipipalatlan jelolonegyzet nem kerul be a POST-ba
            if (isset($_POST['mt_provider'])) { $_POST['mt_auto'] = isset($_POST['mt_auto']) ? '1' : '0'; }
            $st = $db->prepare('INSERT INTO help_setting (`key`, value, updated_by, updated_at) VALUES (?,?,?,NOW())
                                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by), updated_at = NOW()');
            foreach ($keys as $k) {
                if (!array_key_exists($k, $_POST)) { continue; }
                $v = trim((string)$_POST[$k]);
                if ($k === 'mt_key' && $v === '********') { continue; }   // nem irtuk ki, ne is felejtsuk el
                $st->execute([$k, $v, auth_user()['id']]);
            }
            audit_me($db, 'setting.save');
            flash('ok', t('flash.setting.save.beallitasok-mentve'));
            back(['p' => 'settings']);
        }

        case 'ui.save': {
            if (!auth_is('admin')) { flash('err', t('flash.ui.save.ehhez-adminisztratori-jog')); back(['p' => 'settings']); }
            $known = array_keys(ui_default());
            $langs = array_keys(admin_langs());

            $ins = $db->prepare('INSERT INTO help_ui (ui_key, lang, text) VALUES (?,?,?)
                                 ON DUPLICATE KEY UPDATE text = VALUES(text), updated_at = NOW()');
            $del = $db->prepare('DELETE FROM help_ui WHERE ui_key = ? AND lang = ?');

            $n = 0;
            foreach ((array)($_POST['ui'] ?? []) as $lang => $pairs) {
                if (!in_array((string)$lang, $langs, true)) { continue; }
                foreach ((array)$pairs as $key => $text) {
                    if (!in_array((string)$key, $known, true)) { continue; }
                    $text = trim((string)$text);
                    // az ures mezo torli a forditast: a szoveg magyarul jelenik meg
                    if ($text === '') { $del->execute([$key, $lang]); continue; }
                    $ins->execute([$key, $lang, mb_substr($text, 0, 2000)]);
                    $n++;
                }
            }
            audit_me($db, 'ui.save', null, $n . ' szöveg');
            flash('ok', t('flash.ui.save.kesz', ['n' => $n]));
            back(['p' => 'settings']);
        }

        case 'user.uilang': {
            $code = strtolower(trim(post('ui_lang')));
            if (!array_key_exists($code, admin_langs())) { back([]); }
            $db->prepare('UPDATE help_user SET ui_lang = ? WHERE id = ?')
               ->execute([$code, auth_user()['id']]);
            $_SESSION['user']['ui_lang'] = $code;
            back([]);
        }

        // ================================================== nyelvek
        case 'lang.add': {
            if (!auth_is('admin')) { flash('err', t('flash.lang.add.ehhez-adminisztratori-jog')); back(['p' => 'settings']); }
            $code = strtolower(trim(post('code')));
            $name = trim(post('name'));
            if (!preg_match('/^[a-z]{2,5}$/', $code) || $name === '') {
                flash('err', t('flash.lang.add.kod-ket-ot-betu'));
                back(['p' => 'settings']);
            }

            $exists = $db->prepare('SELECT 1 FROM help_lang WHERE code = ?');
            $exists->execute([$code]);
            if ($exists->fetchColumn()) {
                flash('err', t('flash.lang.add.nyelvkod-szerepel-listaban'));
                back(['p' => 'settings']);
            }

            $max = (int)$db->query('SELECT COALESCE(MAX(sort_order), 0) FROM help_lang')->fetchColumn();
            $db->prepare('INSERT INTO help_lang (code, name, own_name, is_source, is_active, sort_order)
                          VALUES (?,?,?,0,1,?)')
               ->execute([$code, mb_substr($name, 0, 60),
                          mb_substr(trim(post('own_name')), 0, 60) ?: null, $max + 10]);

            // A fofejezetek atmasolasa, hogy legyen hova tenni a forditasokat.
            $src = admin_source_lang();
            $copied = $db->prepare('INSERT INTO help_module (chapter_no, slug, title, lang, sort_order)
                                    SELECT chapter_no, slug, title, ?, sort_order
                                      FROM help_module WHERE lang = ?');
            $copied->execute([$code, $src]);

            audit_me($db, 'lang.add', $code, $name);
            flash('ok', t('flash.lang.add.kesz',
                          ['nev' => h($name), 'n' => $copied->rowCount()]));
            back(['p' => 'settings']);
        }

        case 'lang.save': {
            if (!auth_is('admin')) { flash('err', t('flash.lang.save.ehhez-adminisztratori-jog')); back(['p' => 'settings']); }
            $code = strtolower(trim(post('code')));
            $q = $db->prepare('SELECT is_source FROM help_lang WHERE code = ?');
            $q->execute([$code]);
            $isSource = (int)$q->fetchColumn();

            $db->prepare('UPDATE help_lang SET name = ?, own_name = ?, sort_order = ?, is_active = ? WHERE code = ?')
               ->execute([
                   mb_substr(trim(post('name')), 0, 60),
                   mb_substr(trim(post('own_name')), 0, 60) ?: null,
                   (int)post('sort_order'),
                   $isSource ? 1 : (isset($_POST['is_active']) ? 1 : 0),
                   $code,
               ]);
            audit_me($db, 'lang.save', $code);
            flash('ok', t('flash.lang.save.nyelv-mentve'));
            back(['p' => 'settings']);
        }

        case 'lang.delete': {
            if (!auth_is('admin')) { flash('err', t('flash.lang.delete.ehhez-adminisztratori-jog')); back(['p' => 'settings']); }
            $code = strtolower(trim(post('code')));

            $q = $db->prepare('SELECT is_source, name FROM help_lang WHERE code = ?');
            $q->execute([$code]);
            $row = $q->fetch();
            if (!$row) { flash('err', t('flash.lang.delete.nincs-ilyen-nyelv')); back(['p' => 'settings']); }
            if ((int)$row['is_source']) {
                flash('err', t('flash.lang.delete.forrasnyelvet-torolni-ezen-irodnak'));
                back(['p' => 'settings']);
            }

            $n = $db->prepare('SELECT COUNT(*) FROM help_article WHERE lang = ?');
            $n->execute([$code]);
            if ((int)$n->fetchColumn() > 0) {
                flash('err', t('flash.lang.delete.nem-ures'));
                back(['p' => 'settings']);
            }

            $db->prepare('DELETE FROM help_module WHERE lang = ?')->execute([$code]);
            $db->prepare('DELETE FROM help_lang WHERE code = ?')->execute([$code]);
            audit_me($db, 'lang.delete', $code, (string)$row['name']);
            flash('ok', t('flash.lang.delete.kesz', ['nev' => h((string)$row['name'])]));
            back(['p' => 'settings']);
        }

        case 'changelog.save': {
            if (!auth_is('admin')) { flash('err', t('flash.changelog.save.ehhez-adminisztratori-jog')); back(['p' => 'releases']); }
            $cid = (int)post('id');
            $db->prepare('UPDATE help_changelog SET description = ?, is_minor = ? WHERE id = ?')
               ->execute([mb_substr(post('description'), 0, 2000), isset($_POST['is_minor']) ? 1 : 0, $cid]);
            audit_me($db, 'changelog.save', 'changelog:' . $cid);
            flash('ok', t('flash.changelog.save.bejegyzes-mentve'));
            back(['p' => 'releases', 'rel' => (int)post('rel')]);
        }

        case 'changelog.delete': {
            if (!auth_is('admin')) { flash('err', t('flash.changelog.delete.ehhez-adminisztratori-jog')); back(['p' => 'releases']); }
            $cid = (int)post('id');
            $db->prepare('DELETE FROM help_changelog WHERE id = ?')->execute([$cid]);
            audit_me($db, 'changelog.delete', 'changelog:' . $cid);
            flash('ok', t('flash.changelog.delete.bejegyzes-torolve-valtozasnaplobol-fejezet'));
            back(['p' => 'releases', 'rel' => (int)post('rel')]);
        }

        case 'release.close': {
            if (!auth_is('admin')) { flash('err', t('flash.release.close.ehhez-adminisztratori-jog')); back(['p' => 'settings']); }
            $version = post('version');
            $next    = post('next');
            if ($version === '' || $next === '') {
                flash('err', t('flash.release.close.lezarando-kovetkezo-verzioszamot-is'));
                back(['p' => 'settings']);
            }
            try {
                $st = $db->prepare('CALL help_close_release(?, ?, ?, @help_cnt)');
                $st->execute([$version, $next, auth_user()['id']]);
                $st->closeCursor();
                $n = (int)$db->query('SELECT @help_cnt')->fetchColumn();
                audit_me($db, 'release.close', $version, "{$n} bejegyzés");
                flash('ok', t('flash.release.close.kesz',
                              ['verzio' => h($version), 'n' => $n, 'kovetkezo' => h($next)]));
            } catch (Throwable $e) {
                flash('err', t('flash.release.close.hiba', ['reszlet' => h($e->getMessage())]));
            }
            back(['p' => post('from') === 'releases' ? 'releases' : 'settings']);
        }

        default:
            flash('err', t('flash.release.close.ismeretlen-muvelet'));
            back();
    }
}
