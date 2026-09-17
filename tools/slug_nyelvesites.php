<?php
/**
 * slug_nyelvesites.php - az angol es nemet fejezetek URL-je a SAJAT cimukbol.
 *
 * Eredetileg mind a harom nyelv a magyar slugot hasznalta, ezert az angol
 * oldal cime is magyarul olvasott:
 *     /en/5-4-kintlevoseg-kezeles   ->   /en/5-4-receivables-management
 *
 * A regi hivatkozasok nem tornek el: az index.php a nem talalt slugot
 * megkeresi a tobbi nyelven, es 301-gyel atiranyit az adott nyelv sajat
 * cimere.
 *
 * Hasznalat a php kontenerben:
 *     docker compose cp tools/slug_nyelvesites.php php:/tmp/slug.php
 *     docker compose exec php php /tmp/slug.php              # csak megmutatja
 *     docker compose exec php php /tmp/slug.php --alkalmaz   # tenyleg atirja
 */
declare(strict_types=1);

// A kod a repo gyokerebol es a php kontenerbol is futtathato legyen.
$roots = [__DIR__ . '/../site_dinamikus', '/var/www/html'];
$app = null;
foreach ($roots as $r) {
    if (is_file($r . '/lib/util.php')) { $app = $r; break; }
}
if ($app === null) {
    fwrite(STDERR, "Nem talalom a site_dinamikus mappat.\n");
    exit(1);
}
require $app . '/lib/util.php';
$cfg = require $app . '/config.php';

$apply = in_array('--alkalmaz', $argv, true);
$db = help_db_rw($cfg);

$rows = $db->query("SELECT id, lang, chapter_no, slug, title
                      FROM help_article WHERE lang <> 'hu' ORDER BY lang, sort_order, id")->fetchAll();

$upd = $db->prepare('UPDATE help_article SET slug = ? WHERE id = ?');
$taken = [];
$n = 0; $skip = 0;

foreach ($rows as $r) {
    $new = help_slug((string)$r['chapter_no'], (string)$r['title']);
    if ($new === '' || $new === $r['slug']) { $skip++; continue; }

    // nyelvenkent egyedinek kell lennie
    $key = $r['lang'] . '|' . $new;
    $chk = $db->prepare('SELECT COUNT(*) FROM help_article WHERE slug = ? AND lang = ? AND id <> ?');
    $chk->execute([$new, $r['lang'], $r['id']]);
    if ((int)$chk->fetchColumn() > 0 || isset($taken[$key])) {
        printf("  ! %s %-8s utkozes, marad: %s\n", $r['lang'], $r['chapter_no'], $r['slug']);
        $skip++;
        continue;
    }
    $taken[$key] = true;

    printf("  %s %-8s %-46s -> %s\n", $r['lang'], $r['chapter_no'], $r['slug'], $new);
    if ($apply) { $upd->execute([$new, $r['id']]); }
    $n++;
}

echo "\n", $apply ? "ATIRVA: " : "ATIRHATO (probafutas): ", $n, " fejezet, valtozatlan: ", $skip, "\n";
if (!$apply) { echo "Eles futtatas:  php tools/slug_nyelvesites.php --alkalmaz\n"; }
