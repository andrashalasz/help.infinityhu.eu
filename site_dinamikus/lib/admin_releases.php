<?php
/**
 * admin_releases.php - Kiadasok: mi valtozott, mikor, es mi var a kovetkezore.
 *
 * Eddig ez egy kis doboz volt a Beallitasok aljan, es csak lezarni tudott.
 * Sajat fulon most lathato a nyitott kiadas tartalma, a korabbi kiadasok
 * valtozasnaploja, es a bejegyzesek szerkesztheto szovege.
 */
declare(strict_types=1);

function page_releases(PDO $db, array $counts, int $showId = 0): void
{
    $releases = $db->query("SELECT r.*,
                                   (SELECT COUNT(*) FROM help_changelog c
                                     WHERE c.release_id = r.id AND c.is_minor = 0) AS n_major,
                                   (SELECT COUNT(*) FROM help_changelog c
                                     WHERE c.release_id = r.id) AS n_all
                              FROM help_release r
                          ORDER BY r.status = 'open' DESC, r.released_at DESC, r.id DESC")->fetchAll();

    $open = null;
    foreach ($releases as $r) { if ($r['status'] === 'open') { $open = $r; break; } }

    // melyik kiadas tartalmat mutatjuk: alapbol a nyitott
    if ($showId === 0 && $open) { $showId = (int)$open['id']; }
    $shown = null;
    foreach ($releases as $r) { if ((int)$r['id'] === $showId) { $shown = $r; } }

    $entries = [];
    if ($shown) {
        $e = $db->prepare("SELECT c.*, a.chapter_no, a.title AS article_title, a.lang, a.slug,
                                  m.title AS module_title, u.display_name
                             FROM help_changelog c
                             LEFT JOIN help_article a ON a.id = c.article_id
                             LEFT JOIN help_module  m ON m.id = c.module_id
                             LEFT JOIN help_user    u ON u.id = c.created_by
                            WHERE c.release_id = ?
                         ORDER BY c.is_minor, m.sort_order, a.sort_order, c.id");
        $e->execute([$showId]);
        $entries = $e->fetchAll();
    }

    $KIND = ['new' => ['badge--ok', 'új'], 'mod' => ['badge--info', 'módosítás'], 'fix' => ['badge--warn', 'javítás']];

    admin_head('Kiadások', 'releases', $counts);
    ?>
<div class="page">
  <h1 class="pt">Kiadások</h1>
  <p class="lead">
    Minden közzétételkor keletkezik egy <b>változásnapló-bejegyzés</b>, és a <b>nyitott</b>
    kiadásba gyűlik. Ez az a szöveg, amit az olvasó a <b>Frissítések</b> lapon lát.
    A kiadás lezárása dátumot és verziószámot ad nekik, leveszi az újdonságjelzéseket,
    és megnyit egy újat.
    <br><span class="muted">A fejezetek <b>korábbi állapotai</b> nem itt, hanem a Fejezetek
    fülön, a <b>Változatok</b> gomb alatt vannak.</span>
  </p>
  <?= flash_render() ?>

  <div class="page--split" style="padding:0">
    <div class="panel picker">
      <div class="picker__l">
        <?php foreach ($releases as $r): ?>
          <a class="picker__a<?= (int)$r['id'] === $showId ? ' on' : '' ?>"
             href="<?= h(admin_url(['p' => 'releases', 'rel' => $r['id']])) ?>">
            <em><?= h($r['version']) ?></em>
            <span><?= $r['status'] === 'open' ? 'nyitott' : h((string)$r['released_at']) ?></span>
            <span class="badge <?= $r['status'] === 'open' ? 'badge--warn' : '' ?>"
                  style="margin-left:auto"><?= (int)$r['n_major'] ?></span>
          </a>
        <?php endforeach; ?>
        <?php if (!$releases): ?><div class="empty">Még nincs kiadás.</div><?php endif; ?>
      </div>
    </div>

    <div>
      <?php if (!$shown): ?>
        <div class="panel"><div class="empty">Válassz egy kiadást a bal oldali listából.</div></div>
      <?php else: ?>

        <div class="panel" style="margin-bottom:16px">
          <div class="panel__h">
            <h2><?= h($shown['version']) ?></h2>
            <span class="badge <?= $shown['status'] === 'open' ? 'badge--warn' : 'badge--ok' ?>">
              <?= $shown['status'] === 'open' ? 'nyitott' : 'lezárva ' . h((string)$shown['released_at']) ?></span>
            <span class="sp"></span>
            <span class="muted"><?= (int)$shown['n_major'] ?> bejegyzés<?php
              if ((int)$shown['n_all'] > (int)$shown['n_major']): ?>
              · <?= (int)$shown['n_all'] - (int)$shown['n_major'] ?> apró javítás<?php endif; ?></span>
          </div>

          <?php if ($shown['status'] === 'open'): ?>
            <div class="panel__b">
              <?php
              $nextVersion = '';
              if (preg_match('/^v?(\d{4})\.(\d{1,2})$/', trim((string)$shown['version']), $vm)) {
                  $y = (int)$vm[1]; $mo = (int)$vm[2] + 1;
                  if ($mo > 12) { $mo = 1; $y++; }
                  $nextVersion = sprintf('v%04d.%02d', $y, $mo);
              }
              ?>
              <form method="post" action="<?= h(admin_url()) ?>"
                    data-confirm="Lezárod a kiadást? Ez minden fejezet verziószámát frissíti, és leveszi az újdonságjelzéseket.">
                <?= csrf_input() ?>
                <input type="hidden" name="a" value="release.close">
                <input type="hidden" name="from" value="releases">
                <div class="row">
                  <div class="field"><label>Lezárandó verzió neve</label>
                    <input class="inp" name="version" value="<?= h($shown['version']) ?>" required></div>
                  <div class="field"><label>A következő (nyitott) verzió</label>
                    <input class="inp" name="next" value="<?= h($nextVersion) ?>" required>
                    <div class="hint">Automatikusan a következő hónap — átírható.</div></div>
                  <div class="field" style="flex:0 1 auto;align-self:flex-end">
                    <button class="btn btn--p" type="submit">Kiadás lezárása</button></div>
                </div>
              </form>
            </div>
          <?php endif; ?>
        </div>

        <div class="panel">
          <div class="panel__h"><h2>Változásnapló</h2><span class="sp"></span>
            <span class="badge"><?= count($entries) ?></span></div>
          <div class="panel__b panel__b--flush">
            <?php if (!$entries): ?>
              <div class="empty">Ebben a kiadásban még nincs bejegyzés — tegyél közzé egy fejezetet.</div>
            <?php else: ?>
              <table class="tbl">
                <thead><tr><th>Fejezet</th><th>Amit az olvasó lát</th><th>Típus</th><th>Ki</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($entries as $c):
                    [$cls, $lbl] = $KIND[$c['change_type']] ?? ['', (string)$c['change_type']]; ?>
                  <tr<?= (int)$c['is_minor'] ? ' class="muted"' : '' ?>>
                    <td class="nowrap" data-label="Fejezet">
                      <?php if ($c['article_id']): ?>
                        <a href="<?= h(admin_url(['p' => 'articles', 'lang' => $c['lang'], 'id' => $c['article_id']])) ?>">
                          <?= h((string)$c['chapter_no']) ?></a>
                        <span class="mono muted"><?= h((string)$c['lang']) ?></span>
                      <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td data-label="Amit az olvasó lát">
                      <form method="post" action="<?= h(admin_url()) ?>" class="row" style="gap:6px;align-items:center">
                        <?= csrf_input() ?>
                        <input type="hidden" name="a" value="changelog.save">
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <input type="hidden" name="rel" value="<?= (int)$showId ?>">
                        <input class="inp" name="description" value="<?= h((string)$c['description']) ?>"
                               style="flex:1;min-width:200px">
                        <label class="check" title="Az apró javítás nem jelenik meg a Frissítések lapon">
                          <input type="checkbox" name="is_minor" value="1" <?= (int)$c['is_minor'] ? 'checked' : '' ?>> apró
                        </label>
                        <button class="btn btn--sm" type="submit">Mentés</button>
                      </form>
                    </td>
                    <td data-label="Típus"><span class="badge <?= h($cls) ?>"><?= h($lbl) ?></span></td>
                    <td class="muted nowrap" data-label="Ki"><?= h((string)$c['display_name']) ?></td>
                    <td class="nowrap" data-label="">
                      <form method="post" action="<?= h(admin_url()) ?>"
                            data-confirm="Törlöd ezt a bejegyzést a változásnaplóból? A fejezet tartalmát nem érinti.">
                        <?= csrf_input() ?>
                        <input type="hidden" name="a" value="changelog.delete">
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <input type="hidden" name="rel" value="<?= (int)$showId ?>">
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
      <?php endif; ?>
    </div>
  </div>
</div>
    <?php
    admin_foot();
}
