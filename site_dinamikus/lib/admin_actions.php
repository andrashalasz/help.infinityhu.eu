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
        flash('err', 'Lejárt vagy érvénytelen munkamenet – töltsd újra az oldalt, és próbáld meg ismét.');
        back($action === 'login' ? ['p' => 'login'] : []);
    }

    switch ($action) {

        // ================================================== fiók
        case 'login': {
            $r = auth_login($db, post('username'), (string)($_POST['password'] ?? ''));
            if (!$r['ok']) {
                flash('err', h($r['error'] ?? 'Sikertelen bejelentkezés.'));
                back(['p' => 'login']);
            }
            back(auth_user()['must_change'] ? ['p' => 'chpw'] : []);
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
                flash('err', 'A jelenlegi jelszó nem stimmel.');
                back(['p' => 'chpw']);
            }
            if ($new !== $new2) {
                flash('err', 'A két új jelszó nem egyezik.');
                back(['p' => 'chpw']);
            }
            if (($problem = auth_password_problem($new, $u['username'])) !== null) {
                flash('err', h($problem));
                back(['p' => 'chpw']);
            }
            if (password_verify($new, $hash)) {
                flash('err', 'Az új jelszó nem lehet ugyanaz, mint a régi.');
                back(['p' => 'chpw']);
            }
            auth_set_password($db, (int)$u['id'], $new);
            audit_me($db, 'password.change', 'user:' . $u['username']);
            flash('ok', 'A jelszó megváltozott.');
            back();
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
            audit_me($db, 'article.draft', 'article:' . $id);

            if ($wantsJson) { help_json(['ok' => true, 'saved_at' => date('H:i:s')]); }
            flash('ok', 'Vázlat mentve.');
            back(['p' => 'articles', 'id' => $id]);
        }

        case 'article.publish': {
            $id = (int)post('id');
            $st = $db->prepare('SELECT lang, slug, draft_html FROM help_article WHERE id = ?');
            $st->execute([$id]);
            $before = $st->fetch();
            if (!$before || $before['draft_html'] === null) {
                if ($wantsJson) { help_json(['ok' => false, 'error' => 'Nincs közzétételre váró vázlat.'], 400); }
                flash('warn', 'Nincs közzétételre váró vázlat ehhez a fejezethez.');
                back(['p' => 'articles', 'id' => $id]);
            }

            $summary = post('summary');
            $kind    = in_array(post('kind'), ['new', 'mod', 'fix'], true) ? post('kind') : 'mod';
            // a PDO a PHP bool-t ures sztringkent kuldene, amit a Postgres nem
            // tud boolean-re alakitani - ezert szoveges 'true'/'false' megy at
            $minor   = isset($_POST['minor']) ? 'true' : 'false';

            $db->beginTransaction();
            try {
                $p = $db->prepare('SELECT help_publish(?, ?, ?, ?, NULL, ?)');
                $p->execute([$id, auth_user()['id'], $summary !== '' ? $summary : null, $kind, $minor]);

                $body = $db->prepare('SELECT body_html FROM help_article WHERE id = ?');
                $body->execute([$id]);
                sections_rebuild($db, $id, (string)$body->fetchColumn());
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                if ($wantsJson) { help_json(['ok' => false, 'error' => $e->getMessage()], 500); }
                flash('err', 'A közzététel nem sikerült: ' . h($e->getMessage()));
                back(['p' => 'articles', 'id' => $id]);
            }
            audit_me($db, 'article.publish', 'article:' . $id, $summary);

            if ($wantsJson) { help_json(['ok' => true]); }
            flash('ok', 'A fejezet közzétéve — a nyilvános oldalon már ez látszik.');
            back(['p' => 'articles', 'id' => $id]);
        }

        case 'article.discard': {
            $id = (int)post('id');
            $db->prepare('UPDATE help_article SET draft_html = NULL, draft_title = NULL, draft_by = NULL, draft_at = NULL WHERE id = ?')
               ->execute([$id]);
            audit_me($db, 'article.discard', 'article:' . $id);
            flash('ok', 'A vázlat eldobva, a közzétett tartalom változatlan.');
            back(['p' => 'articles', 'id' => $id]);
        }

        // Gyors ki-/bekapcsolas: amig egy fejezet nincs kesz, ne latszodjon
        // a nyilvanos oldalon. A tartalom es a vazlat erintetlen marad.
        case 'article.toggle': {
            $id = (int)post('id');
            $st = $db->prepare('UPDATE help_article SET is_published = NOT is_published WHERE id = ? RETURNING is_published, title');
            $st->execute([$id]);
            $r = $st->fetch();
            if (!$r) {
                flash('err', 'Nincs ilyen fejezet.');
                back(['p' => 'articles']);
            }
            audit_me($db, 'article.toggle', 'article:' . $id, $r['is_published'] ? 'kozzeteve' : 'kikapcsolva');
            flash('ok', $r['is_published']
                ? 'A fejezet <b>közzétéve</b> — mostantól látszik a nyilvános oldalon.'
                : 'A fejezet <b>kikapcsolva</b> — a nyilvános oldalon nem jelenik meg, a tartalma megmarad.');
            back(['p' => 'articles', 'id' => $id]);
        }

        case 'article.meta': {
            $id = (int)post('id');
            $slug = post('slug');
            $chapter = post('chapter_no');
            $title = post('title');
            if ($title === '') {
                flash('err', 'A cím nem lehet üres.');
                back(['p' => 'articles', 'id' => $id]);
            }
            if ($slug === '') { $slug = help_slug($chapter, $title); }

            try {
                $db->prepare('UPDATE help_article
                                 SET chapter_no = ?, slug = ?, title = ?, module_id = ?, sort_order = ?,
                                     is_published = ?, permission = ?
                               WHERE id = ?')
                   ->execute([
                       mb_substr($chapter, 0, 16), mb_substr($slug, 0, 160), mb_substr($title, 0, 255),
                       (int)post('module_id') ?: null, (int)post('sort_order'),
                       isset($_POST['is_published']) ? 'true' : 'false',
                       post('permission') !== '' ? mb_substr(post('permission'), 0, 64) : null,
                       $id,
                   ]);
            } catch (PDOException $e) {
                flash('err', str_contains($e->getMessage(), 'help_article_slug_lang_key')
                    ? 'Ez az URL-azonosító (slug) már foglalt ezen a nyelven.'
                    : 'Mentési hiba: ' . h($e->getMessage()));
                back(['p' => 'articles', 'id' => $id]);
            }
            audit_me($db, 'article.meta', 'article:' . $id);
            flash('ok', 'A fejezet adatai mentve.');
            back(['p' => 'articles', 'id' => $id]);
        }

        case 'article.create': {
            $lang    = array_key_exists(post('lang'), ADMIN_LANGS) ? post('lang') : 'hu';
            $module  = (int)post('module_id');
            $chapter = post('chapter_no');
            $title   = post('title');
            if ($title === '' || $module === 0) {
                flash('err', 'Modult és címet is meg kell adni.');
                back(['p' => 'articles', 'lang' => $lang]);
            }
            $slug = post('slug') !== '' ? post('slug') : help_slug($chapter, $title);

            $ver = (string)$db->query("SELECT coalesce(max(doc_version), 'v1') FROM help_article")->fetchColumn();
            try {
                $st = $db->prepare('INSERT INTO help_article
                        (module_id, chapter_no, slug, title, lang, body_html, plain_text, doc_version,
                         updated_at, content_hash, sort_order, is_published, draft_html, draft_by, draft_at, source)
                        VALUES (?,?,?,?,?,\'\',\'\',?, CURRENT_DATE, md5(?), ?, false, \'\', ?, now(), \'editor\')
                        RETURNING id');
                $st->execute([
                    $module, mb_substr($chapter, 0, 16), mb_substr($slug, 0, 160), mb_substr($title, 0, 255),
                    $lang, $ver, $slug . $title, (int)post('sort_order'), auth_user()['id'],
                ]);
                $newId = (int)$st->fetchColumn();
            } catch (PDOException $e) {
                flash('err', str_contains($e->getMessage(), 'help_article_slug_lang_key')
                    ? 'Ez az URL-azonosító (slug) már foglalt ezen a nyelven.'
                    : 'Létrehozási hiba: ' . h($e->getMessage()));
                back(['p' => 'articles', 'lang' => $lang]);
            }
            audit_me($db, 'article.create', 'article:' . $newId, $title);
            flash('ok', 'Fejezet létrehozva. Írd meg a tartalmát, majd tedd közzé.');
            back(['p' => 'articles', 'lang' => $lang, 'id' => $newId]);
        }

        case 'article.delete': {
            $id = (int)post('id');
            $st = $db->prepare('SELECT lang, slug, title FROM help_article WHERE id = ?');
            $st->execute([$id]);
            $a = $st->fetch();
            $db->prepare('DELETE FROM help_article WHERE id = ?')->execute([$id]);
            audit_me($db, 'article.delete', 'article:' . $id, $a['title'] ?? null);
            flash('ok', 'A fejezet törölve.');
            back(['p' => 'articles', 'lang' => $a['lang'] ?? 'hu']);
        }

        case 'article.restore': {
            $id  = (int)post('id');
            $rev = (int)post('rev');
            $st = $db->prepare('SELECT title, body_html FROM help_article_revision WHERE article_id = ? AND rev_no = ?');
            $st->execute([$id, $rev]);
            $r = $st->fetch();
            if (!$r) {
                flash('err', 'Nincs ilyen korábbi változat.');
                back(['p' => 'articles', 'id' => $id]);
            }
            $db->prepare('UPDATE help_article SET draft_html = ?, draft_title = ?, draft_by = ?, draft_at = now() WHERE id = ?')
               ->execute([$r['body_html'], $r['title'], auth_user()['id'], $id]);
            audit_me($db, 'article.restore', 'article:' . $id, 'rev ' . $rev);
            flash('ok', "A(z) {$rev}. változat betöltve vázlatként. Nézd át, és tedd közzé, ha jó.");
            back(['p' => 'articles', 'id' => $id]);
        }

        // ================================================== modulok
        case 'module.save': {
            $id    = (int)post('id');
            $lang  = array_key_exists(post('lang'), ADMIN_LANGS) ? post('lang') : 'hu';
            $title = post('title');
            if ($title === '') {
                flash('err', 'A modul neve nem lehet üres.');
                back(['p' => 'modules', 'lang' => $lang]);
            }
            $slug = post('slug') !== '' ? post('slug') : help_slug(post('chapter_no'), $title);

            try {
                if ($id > 0) {
                    $db->prepare('UPDATE help_module SET chapter_no = ?, slug = ?, title = ?, sort_order = ? WHERE id = ?')
                       ->execute([mb_substr(post('chapter_no'), 0, 16), mb_substr($slug, 0, 120), mb_substr($title, 0, 255), (int)post('sort_order'), $id]);
                } else {
                    $db->prepare('INSERT INTO help_module (chapter_no, slug, title, lang, sort_order) VALUES (?,?,?,?,?)')
                       ->execute([mb_substr(post('chapter_no'), 0, 16), mb_substr($slug, 0, 120), mb_substr($title, 0, 255), $lang, (int)post('sort_order')]);
                }
            } catch (PDOException $e) {
                flash('err', 'Mentési hiba: ' . h($e->getMessage()));
                back(['p' => 'modules', 'lang' => $lang]);
            }
            audit_me($db, 'module.save', 'module:' . ($id ?: 'new'), $title);
            flash('ok', 'Modul mentve.');
            back(['p' => 'modules', 'lang' => $lang]);
        }

        case 'module.delete': {
            $id   = (int)post('id');
            $lang = post('lang', 'hu');
            $n = $db->prepare('SELECT count(*) FROM help_article WHERE module_id = ?');
            $n->execute([$id]);
            if ((int)$n->fetchColumn() > 0) {
                flash('err', 'Ez a modul még tartalmaz fejezeteket – előbb helyezd át vagy töröld őket.');
                back(['p' => 'modules', 'lang' => $lang]);
            }
            $db->prepare('DELETE FROM help_module WHERE id = ?')->execute([$id]);
            audit_me($db, 'module.delete', 'module:' . $id);
            flash('ok', 'Modul törölve.');
            back(['p' => 'modules', 'lang' => $lang]);
        }

        // ================================================== Word import
        case 'import.upload': {
            $lang = array_key_exists(post('lang'), ADMIN_LANGS) ? post('lang') : 'hu';
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
                flash('err', h($codes[$f['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'A feltöltés nem sikerült.'));
                back(['p' => 'import', 'lang' => $lang]);
            }
            if (!preg_match('/\.docx$/i', (string)$f['name'])) {
                flash('err', 'Csak .docx fájlt tudok beolvasni (a régi .doc formátumot nem).');
                back(['p' => 'import', 'lang' => $lang]);
            }

            try {
                $parser = new DocxParser($cfg['media_dir']);
                $res = $parser->parse((string)$f['tmp_name']);
            } catch (Throwable $e) {
                flash('err', 'A Word-fájl feldolgozása nem sikerült: ' . h($e->getMessage()));
                back(['p' => 'import', 'lang' => $lang]);
            }

            $chapters = 0;
            foreach ($res['modules'] as $m) { $chapters += count($m['articles']); }
            if ($chapters === 0) {
                flash('warn', 'A dokumentumban nem találtam fejezeteket. A modulokat 1. szintű, '
                    . 'a fejezeteket 2. szintű címsorral kell jelölni (Címsor 1 / Címsor 2).');
                back(['p' => 'import', 'lang' => $lang]);
            }

            $db->beginTransaction();
            $st = $db->prepare('INSERT INTO help_import (filename, lang, bytes, status, stats, uploaded_by)
                                VALUES (?,?,?,?,?::jsonb,?) RETURNING id');
            $st->execute([
                mb_substr((string)$f['name'], 0, 255), $lang, (int)$f['size'], 'parsed',
                json_encode(['chapters' => $chapters, 'images' => $res['images'], 'paragraphs' => $res['paragraphs']], JSON_UNESCAPED_UNICODE),
                auth_user()['id'],
            ]);
            $importId = (int)$st->fetchColumn();

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

            flash('ok', "Beolvasva: <b>{$chapters} fejezet</b>, {$res['images']} új kép. "
                . 'Nézd át az eltéréseket, és jelöld ki, mit importáljak.');
            back(['p' => 'import', 'lang' => $lang, 'import' => $importId]);
        }

        case 'import.apply': {
            $importId = (int)post('import_id');
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['items'] ?? []))));
            if (!$ids) {
                flash('warn', 'Nem jelöltél ki egyetlen fejezetet sem.');
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
                            $mi = $db->prepare('INSERT INTO help_module (chapter_no, slug, title, lang, sort_order)
                                                VALUES (?,?,?,?, (SELECT coalesce(max(sort_order),0)+10 FROM help_module WHERE lang = ?))
                                                RETURNING id');
                            $mi->execute([
                                $it['module_no'], help_slug($it['module_no'], $it['module_title']),
                                $it['module_title'], $lang, $lang,
                            ]);
                            $moduleId = (int)$mi->fetchColumn();
                        }

                        $ver = (string)$db->query("SELECT coalesce(max(doc_version), 'v1') FROM help_article")->fetchColumn();
                        $ai = $db->prepare('INSERT INTO help_article
                                (module_id, chapter_no, slug, title, lang, body_html, plain_text, doc_version,
                                 updated_at, content_hash, img_count, sort_order, is_published,
                                 draft_html, draft_by, draft_at, source)
                                VALUES (?,?,?,?,?,\'\',\'\',?, CURRENT_DATE, md5(?), ?,
                                        (SELECT coalesce(max(sort_order),0)+10 FROM help_article WHERE module_id = ?),
                                        false, ?, ?, now(), \'word\')
                                RETURNING id');
                        $ai->execute([
                            $moduleId, $it['chapter_no'], $it['slug'], $it['title'], $lang, $ver,
                            $it['slug'] . $it['title'], (int)$it['img_count'], $moduleId,
                            $it['body_html'], auth_user()['id'],
                        ]);
                        $articleId = (int)$ai->fetchColumn();
                        $created++;
                    } else {
                        $db->prepare('UPDATE help_article
                                         SET draft_html = ?, draft_title = ?, draft_by = ?, draft_at = now(), source = \'word\'
                                       WHERE id = ?')
                           ->execute([$it['body_html'], $it['title'], auth_user()['id'], $articleId]);
                        $updated++;
                    }

                    if ($publish) {
                        $db->prepare('SELECT help_publish(?, ?, ?, ?, NULL, false)')
                           ->execute([$articleId, auth_user()['id'],
                                      'Word-import: ' . $it['chapter_no'] . ' ' . $it['title'],
                                      $it['article_id'] ? 'mod' : 'new']);
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
            audit_me($db, 'import.apply', 'import:' . $importId, "{$created} új, {$updated} frissített, {$published} közzétett");

            $msg = "Kész: <b>{$created}</b> új fejezet, <b>{$updated}</b> frissített vázlat";
            $msg .= $publish ? ", <b>{$published}</b> közzétéve." : '. A vázlatokat a Fejezetek fülön nézheted át és teheted közzé.';
            flash($errors ? 'warn' : 'ok', $msg);
            foreach (array_slice($errors, 0, 5) as $e) { flash('err', h($e)); }
            back(['p' => 'import', 'import' => $importId]);
        }

        case 'import.discard': {
            $importId = (int)post('import_id');
            $db->prepare('DELETE FROM help_import WHERE id = ?')->execute([$importId]);
            audit_me($db, 'import.discard', 'import:' . $importId);
            flash('ok', 'Az import eldobva. (A fejezetek érintetlenek maradtak.)');
            back(['p' => 'import']);
        }

        // ================================================== fordítás
        case 'translate.machine': {
            $srcId = (int)post('src_id');
            $to    = post('to');
            if (!array_key_exists($to, ADMIN_LANGS)) { help_json(['ok' => false, 'error' => 'Ismeretlen célnyelv.'], 400); }

            $st = $db->prepare('SELECT lang, body_html, title FROM help_article WHERE id = ?');
            $st->execute([$srcId]);
            $src = $st->fetch();
            if (!$src) { help_json(['ok' => false, 'error' => 'Nincs ilyen forrásfejezet.'], 404); }

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

            $st = $db->prepare('SELECT * FROM help_article WHERE id = ?');
            $st->execute([$srcId]);
            $src = $st->fetch();
            if (!$src) {
                flash('err', 'Nincs ilyen forrásfejezet.');
                back(['p' => 'translate']);
            }

            $db->beginTransaction();
            try {
                $t = $db->prepare('SELECT id FROM help_article WHERE slug = ? AND lang = ?');
                $t->execute([$src['slug'], $to]);
                $targetId = (int)($t->fetchColumn() ?: 0);

                if ($targetId === 0) {
                    // a celnyelvi modul megkeresese (ugyanaz a chapter_no)
                    $mst = $db->prepare('SELECT m2.id FROM help_module m1 JOIN help_module m2
                                            ON m2.chapter_no = m1.chapter_no AND m2.lang = ?
                                          WHERE m1.id = ?');
                    $mst->execute([$to, $src['module_id']]);
                    $moduleId = (int)($mst->fetchColumn() ?: 0);
                    if ($moduleId === 0) {
                        $mi = $db->prepare('INSERT INTO help_module (chapter_no, slug, title, lang, sort_order)
                                            SELECT chapter_no, slug, title, ?, sort_order FROM help_module WHERE id = ?
                                            RETURNING id');
                        $mi->execute([$to, $src['module_id']]);
                        $moduleId = (int)$mi->fetchColumn();
                    }
                    $ai = $db->prepare('INSERT INTO help_article
                            (module_id, chapter_no, slug, title, lang, body_html, plain_text, doc_version,
                             updated_at, content_hash, sort_order, is_published, draft_html, draft_title,
                             draft_by, draft_at, source)
                            VALUES (?,?,?,?,?,\'\',\'\',?, CURRENT_DATE, md5(?), ?, false, ?, ?, ?, now(), \'editor\')
                            RETURNING id');
                    $ai->execute([
                        $moduleId, $src['chapter_no'], $src['slug'], $title !== '' ? $title : $src['title'], $to,
                        $src['doc_version'], $src['slug'] . $to, (int)$src['sort_order'],
                        $html, $title !== '' ? $title : null, auth_user()['id'],
                    ]);
                    $targetId = (int)$ai->fetchColumn();
                } else {
                    $db->prepare('UPDATE help_article SET draft_html = ?, draft_title = ?, draft_by = ?, draft_at = now() WHERE id = ?')
                       ->execute([$html, $title !== '' ? $title : null, auth_user()['id'], $targetId]);
                }

                $db->prepare('UPDATE help_article SET translated_from_hash = ?, translated_by = ?, translated_at = now() WHERE id = ?')
                   ->execute([$src['content_hash'], mb_substr($how, 0, 16), $targetId]);

                if ($publishNow) {
                    $db->prepare('SELECT help_publish(?, ?, ?, ?, NULL, true)')
                       ->execute([$targetId, auth_user()['id'], null, 'mod']);
                    $b = $db->prepare('SELECT body_html FROM help_article WHERE id = ?');
                    $b->execute([$targetId]);
                    sections_rebuild($db, $targetId, (string)$b->fetchColumn());
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                flash('err', 'A fordítás mentése nem sikerült: ' . h($e->getMessage()));
                back(['p' => 'translate', 'src' => $srcId, 'to' => $to]);
            }

            audit_me($db, 'translate.save', 'article:' . $srcId, $src['lang'] . '->' . $to . ($publishNow ? ' + közzététel' : ''));
            flash('ok', $publishNow ? 'A fordítás mentve és közzétéve.' : 'A fordítás vázlatként mentve.');
            back(['p' => 'translate', 'src' => $srcId, 'to' => $to]);
        }

        // ================================================== képernyő-hozzárendelés
        case 'screen.save': {
            $id = (int)post('id');
            $route = post('route');
            $articleId = (int)post('article_id');
            if ($route === '' || $articleId === 0) {
                flash('err', 'Az útvonalat és a fejezetet is meg kell adni.');
                back(['p' => 'screens']);
            }
            try {
                if ($id > 0) {
                    $db->prepare('UPDATE help_screen_map SET route = ?, article_id = ?, anchor = ?, is_verified = ? WHERE id = ?')
                       ->execute([mb_substr($route, 0, 160), $articleId, post('anchor') ?: null, isset($_POST['is_verified']) ? 'true' : 'false', $id]);
                } else {
                    $db->prepare('INSERT INTO help_screen_map (route, article_id, anchor, is_verified) VALUES (?,?,?,?)')
                       ->execute([mb_substr($route, 0, 160), $articleId, post('anchor') ?: null, isset($_POST['is_verified']) ? 'true' : 'false']);
                }
            } catch (PDOException $e) {
                flash('err', str_contains($e->getMessage(), 'help_screen_map_route_key')
                    ? 'Ehhez az útvonalhoz már tartozik fejezet.'
                    : 'Mentési hiba: ' . h($e->getMessage()));
                back(['p' => 'screens']);
            }
            audit_me($db, 'screen.save', 'route:' . $route);
            flash('ok', 'Hozzárendelés mentve.');
            back(['p' => 'screens']);
        }

        case 'screen.delete': {
            $db->prepare('DELETE FROM help_screen_map WHERE id = ?')->execute([(int)post('id')]);
            audit_me($db, 'screen.delete', 'screen:' . post('id'));
            flash('ok', 'Hozzárendelés törölve.');
            back(['p' => 'screens']);
        }

        // ================================================== képek
        case 'media.upload': {
            $files = $_FILES['images'] ?? null;
            if (!is_array($files) || !isset($files['tmp_name'])) {
                flash('err', 'Nem választottál képet.');
                back(['p' => 'media']);
            }
            $names = (array)$files['name'];
            $ok = 0; $skipped = 0;
            foreach (array_keys($names) as $i) {
                if ((int)$files['error'][$i] !== UPLOAD_ERR_OK) { $skipped++; continue; }
                $tmp = (string)$files['tmp_name'][$i];
                $info = @getimagesize($tmp);
                if ($info === false) { $skipped++; continue; }
                $mime = (string)$info['mime'];
                $ext = match ($mime) {
                    'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp',
                    default => null,
                };
                if ($ext === null) { $skipped++; continue; }

                $bytes = (string)file_get_contents($tmp);
                $sha = hash('sha256', $bytes);
                $name = 'img_' . substr($sha, 0, 12) . '.' . $ext;
                $target = rtrim($cfg['media_dir'], '/') . '/' . $name;
                if (!is_file($target) && @file_put_contents($target, $bytes) === false) { $skipped++; continue; }

                $db->prepare('INSERT INTO help_media (filename, sha256, mime, bytes, width, height, uploaded_by)
                              VALUES (?,?,?,?,?,?,?) ON CONFLICT (sha256) DO NOTHING')
                   ->execute([$name, $sha, $mime, strlen($bytes), (int)$info[0], (int)$info[1], auth_user()['id']]);
                $ok++;
            }
            audit_me($db, 'media.upload', null, "{$ok} kép");
            flash($ok ? 'ok' : 'err', "Feltöltve: {$ok} kép." . ($skipped ? " Kihagyva: {$skipped} (nem támogatott formátum vagy hiba)." : ''));
            back(['p' => 'media']);
        }

        // ================================================== felhasználók
        case 'user.save': {
            if (!auth_is('admin')) { flash('err', 'Ehhez adminisztrátori jog kell.'); back(['p' => 'users']); }
            $id = (int)post('id');
            $username = post('username');
            $role = in_array(post('role'), HELP_ROLES, true) ? post('role') : 'editor';

            if ($id > 0) {
                $db->prepare('UPDATE help_user SET display_name = ?, email = ?, role = ?, is_active = ? WHERE id = ?')
                   ->execute([post('display_name'), post('email') ?: null, $role, isset($_POST['is_active']) ? 'true' : 'false', $id]);
                audit_me($db, 'user.update', 'user:' . $id);
                flash('ok', 'A felhasználó adatai mentve.');
            } else {
                $pw = (string)($_POST['password'] ?? '');
                if ($username === '') { flash('err', 'A felhasználónév kötelező.'); back(['p' => 'users']); }
                if (($problem = auth_password_problem($pw, $username)) !== null) { flash('err', h($problem)); back(['p' => 'users']); }
                try {
                    $db->prepare('INSERT INTO help_user (username, password_hash, display_name, email, role, must_change_pw)
                                  VALUES (?,?,?,?,?,true)')
                       ->execute([$username, password_hash($pw, PASSWORD_BCRYPT, ['cost' => 10]),
                                  post('display_name') ?: $username, post('email') ?: null, $role]);
                } catch (PDOException $e) {
                    flash('err', 'Ez a felhasználónév már foglalt.');
                    back(['p' => 'users']);
                }
                audit_me($db, 'user.create', 'user:' . $username, $role);
                flash('ok', 'Felhasználó létrehozva. Az első belépéskor jelszót kell cserélnie.');
            }
            back(['p' => 'users']);
        }

        case 'user.resetpw': {
            if (!auth_is('admin')) { flash('err', 'Ehhez adminisztrátori jog kell.'); back(['p' => 'users']); }
            $id = (int)post('id');
            $pw = (string)($_POST['password'] ?? '');
            $un = $db->prepare('SELECT username FROM help_user WHERE id = ?');
            $un->execute([$id]);
            $username = (string)$un->fetchColumn();
            if (($problem = auth_password_problem($pw, $username)) !== null) { flash('err', h($problem)); back(['p' => 'users']); }

            $db->prepare('UPDATE help_user SET password_hash = ?, must_change_pw = true, failed_logins = 0, locked_until = NULL WHERE id = ?')
               ->execute([password_hash($pw, PASSWORD_BCRYPT, ['cost' => 10]), $id]);
            audit_me($db, 'user.resetpw', 'user:' . $username);
            flash('ok', "A(z) {$username} jelszava beállítva. Első belépéskor cserélnie kell.");
            back(['p' => 'users']);
        }

        case 'user.delete': {
            if (!auth_is('admin')) { flash('err', 'Ehhez adminisztrátori jog kell.'); back(['p' => 'users']); }
            $id = (int)post('id');
            if ($id === (int)auth_user()['id']) {
                flash('err', 'Saját magadat nem törölheted.');
                back(['p' => 'users']);
            }
            $n = (int)$db->query("SELECT count(*) FROM help_user WHERE role = 'admin' AND is_active")->fetchColumn();
            $r = $db->prepare('SELECT role FROM help_user WHERE id = ?');
            $r->execute([$id]);
            if ($r->fetchColumn() === 'admin' && $n <= 1) {
                flash('err', 'Az utolsó adminisztrátort nem lehet törölni.');
                back(['p' => 'users']);
            }
            $db->prepare('DELETE FROM help_user WHERE id = ?')->execute([$id]);
            audit_me($db, 'user.delete', 'user:' . $id);
            flash('ok', 'Felhasználó törölve.');
            back(['p' => 'users']);
        }

        // ================================================== beállítások
        case 'setting.save': {
            if (!auth_is('admin')) { flash('err', 'Ehhez adminisztrátori jog kell.'); back(['p' => 'settings']); }
            $keys = ['site_title_hu', 'site_title_en', 'site_title_de', 'mt_provider', 'mt_endpoint', 'mt_key'];
            $st = $db->prepare('INSERT INTO help_setting (key, value, updated_by, updated_at) VALUES (?,?,?,now())
                                ON CONFLICT (key) DO UPDATE SET value = excluded.value, updated_by = excluded.updated_by, updated_at = now()');
            foreach ($keys as $k) {
                if (!array_key_exists($k, $_POST)) { continue; }
                $v = trim((string)$_POST[$k]);
                if ($k === 'mt_key' && $v === '********') { continue; }   // nem irtuk ki, ne is felejtsuk el
                $st->execute([$k, $v, auth_user()['id']]);
            }
            audit_me($db, 'setting.save');
            flash('ok', 'A beállítások mentve.');
            back(['p' => 'settings']);
        }

        case 'release.close': {
            if (!auth_is('admin')) { flash('err', 'Ehhez adminisztrátori jog kell.'); back(['p' => 'settings']); }
            $version = post('version');
            $next    = post('next');
            if ($version === '' || $next === '') {
                flash('err', 'A lezárandó és a következő verziószámot is add meg.');
                back(['p' => 'settings']);
            }
            try {
                $st = $db->prepare('SELECT help_close_release(?, ?, ?)');
                $st->execute([$version, $next, auth_user()['id']]);
                $n = (int)$st->fetchColumn();
                audit_me($db, 'release.close', $version, "{$n} bejegyzés");
                flash('ok', "A(z) {$version} kiadás lezárva, {$n} bejegyzéssel. A következő nyitott kiadás: {$next}.");
            } catch (Throwable $e) {
                flash('err', 'A kiadás lezárása nem sikerült: ' . h($e->getMessage()));
            }
            back(['p' => 'settings']);
        }

        default:
            flash('err', 'Ismeretlen művelet.');
            back();
    }
}
