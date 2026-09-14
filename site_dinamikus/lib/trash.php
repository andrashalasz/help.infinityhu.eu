<?php
/**
 * trash.php - visszavonhato torles ("Kuka") es a visszavonas gomb.
 *
 * A torles nem semmisit meg semmit: a cikk teljes allapota - a szakaszaival,
 * a verziotortenetevel es a hozza tartozo kepernyo-hozzarendelesekkel egyutt -
 * JSON-kent a help_trash tablaba kerul, ahonnan egy kattintassal visszaallithato.
 */
declare(strict_types=1);

/** Egy tetel elhelyezese a kukaban. @return int a kuka-bejegyzes azonositoja */
function trash_put(PDO $db, string $kind, string $label, ?string $lang, array $payload, ?int $userId): int
{
    $st = $db->prepare('INSERT INTO help_trash (kind, label, lang, payload, deleted_by)
                        VALUES (?,?,?,?::jsonb,?) RETURNING id');
    $st->execute([
        $kind,
        mb_substr($label, 0, 300),
        $lang,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        $userId,
    ]);
    return (int)$st->fetchColumn();
}

/** Egy cikk torlese: elobb a kukaba tesszuk, csak utana toroljuk. */
function trash_article(PDO $db, array $article, ?int $userId): int
{
    $id = (int)$article['id'];

    $sec = $db->prepare('SELECT * FROM help_section WHERE article_id = ? ORDER BY sort_order, id');
    $sec->execute([$id]);

    $rev = $db->prepare('SELECT * FROM help_article_revision WHERE article_id = ? ORDER BY rev_no');
    $rev->execute([$id]);

    $map = $db->prepare('SELECT * FROM help_screen_map WHERE article_id = ?');
    $map->execute([$id]);

    $payload = [
        'article'   => $article,
        'sections'  => $sec->fetchAll(),
        'revisions' => $rev->fetchAll(),
        'screens'   => $map->fetchAll(),
    ];
    $label = trim(($article['chapter_no'] ?? '') . ' ' . ($article['title'] ?? ''));

    $trashId = trash_put($db, 'article', $label, (string)$article['lang'], $payload, $userId);
    $db->prepare('DELETE FROM help_article WHERE id = ?')->execute([$id]);

    return $trashId;
}

/** Egy modul torlese (csak ha ures). */
function trash_module(PDO $db, array $module, ?int $userId): int
{
    $label = trim(($module['chapter_no'] ?? '') . ' ' . ($module['title'] ?? ''));
    $trashId = trash_put($db, 'module', $label, (string)$module['lang'], ['module' => $module], $userId);
    $db->prepare('DELETE FROM help_module WHERE id = ?')->execute([(int)$module['id']]);
    return $trashId;
}

/**
 * Visszaallitas a kukabol. Ha az eredeti azonosito mar foglalt, ujat kap.
 * @throws RuntimeException
 */
function trash_restore(PDO $db, int $trashId): void
{
    $st = $db->prepare('SELECT * FROM help_trash WHERE id = ?');
    $st->execute([$trashId]);
    $t = $st->fetch();
    if (!$t) {
        throw new RuntimeException('Nincs ilyen elem a Kukában.');
    }
    if ($t['restored_at'] !== null) {
        throw new RuntimeException('Ezt már visszaállították: ' . $t['label']);
    }
    $p = json_decode((string)$t['payload'], true);
    if (!is_array($p)) {
        throw new RuntimeException('A mentett tartalom sérült: ' . $t['label']);
    }

    $db->beginTransaction();
    try {
        switch ($t['kind']) {

            case 'article': {
                $a = $p['article'];

                // a modul idokozben torlodhetett - ilyenkor a modul nelkuli cikk
                // is visszajon, csak besorolatlanul
                $mod = $db->prepare('SELECT 1 FROM help_module WHERE id = ?');
                $mod->execute([$a['module_id']]);
                $moduleId = $mod->fetchColumn() ? (int)$a['module_id'] : null;

                // ugyanaz a slug+lang idokozben ujra letrejohetett
                $dup = $db->prepare('SELECT 1 FROM help_article WHERE slug = ? AND lang = ?');
                $dup->execute([$a['slug'], $a['lang']]);
                $slug = $dup->fetchColumn()
                    ? mb_substr($a['slug'], 0, 150) . '-' . substr((string)time(), -4)
                    : $a['slug'];

                $cols = ['module_id', 'chapter_no', 'slug', 'title', 'lang', 'body_html', 'plain_text',
                         'doc_version', 'updated_at', 'change_flag', 'img_count', 'content_hash',
                         'sort_order', 'is_published', 'permission', 'draft_html', 'draft_title',
                         'source', 'highlight_until'];
                $vals = [];
                foreach ($cols as $c) {
                    $vals[] = match ($c) {
                        'module_id' => $moduleId,
                        'slug'      => $slug,
                        // a PDO a PHP bool-t ures sztringkent kuldene, amit a
                        // Postgres nem tud boolean-re alakitani
                        'is_published' => !empty($a[$c]) ? 'true' : 'false',
                        default     => $a[$c] ?? null,
                    };
                }
                $sql = 'INSERT INTO help_article (' . implode(',', $cols) . ') VALUES ('
                     . implode(',', array_fill(0, count($cols), '?')) . ') RETURNING id';
                $ins = $db->prepare($sql);
                $ins->execute($vals);
                $newId = (int)$ins->fetchColumn();

                $sec = $db->prepare('INSERT INTO help_section
                        (article_id, chapter_no, anchor, title, level, plain_text, sort_order)
                        VALUES (?,?,?,?,?,?,?)');
                foreach ($p['sections'] ?? [] as $r) {
                    $sec->execute([$newId, $r['chapter_no'], $r['anchor'], $r['title'],
                                   $r['level'], $r['plain_text'], $r['sort_order']]);
                }

                $rev = $db->prepare('INSERT INTO help_article_revision
                        (article_id, rev_no, doc_version, title, body_html, content_hash, note, created_by, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?)');
                foreach ($p['revisions'] ?? [] as $r) {
                    $rev->execute([$newId, $r['rev_no'], $r['doc_version'], $r['title'], $r['body_html'],
                                   $r['content_hash'], $r['note'], $r['created_by'], $r['created_at']]);
                }

                $map = $db->prepare('INSERT INTO help_screen_map (route, article_id, anchor, is_verified)
                                     VALUES (?,?,?,?) ON CONFLICT (route) DO NOTHING');
                foreach ($p['screens'] ?? [] as $r) {
                    $map->execute([$r['route'], $newId, $r['anchor'], $r['is_verified'] ? 'true' : 'false']);
                }
                break;
            }

            case 'module': {
                $m = $p['module'];
                $dup = $db->prepare('SELECT 1 FROM help_module WHERE slug = ? AND lang = ?');
                $dup->execute([$m['slug'], $m['lang']]);
                $slug = $dup->fetchColumn() ? $m['slug'] . '-' . substr((string)time(), -4) : $m['slug'];

                $db->prepare('INSERT INTO help_module (chapter_no, slug, title, lang, sort_order)
                              VALUES (?,?,?,?,?)')
                   ->execute([$m['chapter_no'], $slug, $m['title'], $m['lang'], $m['sort_order']]);
                break;
            }

            // tomeges athelyezes visszavonasa: mindenki visszakerul a sajat modulaba
            case 'move': {
                $upd = $db->prepare('UPDATE help_article SET module_id = ? WHERE id = ?');
                foreach ($p['articles'] ?? [] as $r) {
                    $upd->execute([$r['module_id'], $r['id']]);
                }
                break;
            }

            default:
                throw new RuntimeException('Ismeretlen elemtípus: ' . $t['kind']);
        }

        $db->prepare('UPDATE help_trash SET restored_at = now() WHERE id = ?')->execute([$trashId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $e;
    }
}

/**
 * "Visszavonom" gomb egy flash-uzenetbe.
 * Sima urlap, hogy JS nelkul is mukodjon.
 */
function undo_button(PDO $db, string $action, array $fields, string $label = 'Visszavonom'): string
{
    $hidden = '';
    foreach ($fields as $k => $v) {
        if (is_array($v)) {
            foreach ($v as $one) {
                $hidden .= '<input type="hidden" name="' . h($k) . '[]" value="' . h((string)$one) . '">';
            }
        } else {
            $hidden .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
        }
    }
    return '<form method="post" action="' . h(admin_url()) . '" class="undo-form">'
         . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
         . '<input type="hidden" name="a" value="' . h($action) . '">'
         . $hidden
         . '<button class="btn btn--sm" type="submit">↩ ' . h($label) . '</button></form>';
}
