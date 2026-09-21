<?php
/**
 * slug_igazitas.php - a fejezetek URL-jenek igazitasa a mostani szamhoz.
 *
 * Amig a slug nem kovette a szamozast, elcsuszhatott: a "7.1 Felhasznalok"
 * cime maradhatott "16-1-felhasznalok". Ez egyszer futtatando rendrakas.
 *
 * CSAK azt bantja, ami MAGATOL keletkezett: a slug szamelotag nelkuli resze
 * pontosan a cim slugositott alakja. Amit kezzel irtak at, ahhoz nem nyul.
 * A regi cim bekerul a slug-tortenetbe, tehat a kiadott hivatkozasok 301-gyel
 * tovabb elnek.
 *
 *   docker compose exec php php /var/www/html/tools/slug_igazitas.php          (csak mutatja)
 *   docker compose exec php php /var/www/html/tools/slug_igazitas.php --alkalmaz
 */
declare(strict_types=1);

require __DIR__ . '/../lib/util.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/admin_i18n.php';
require __DIR__ . '/../lib/admin_layout.php';

$cfg = require __DIR__ . '/../config.php';
$db  = new PDO($cfg['admin_dsn'], $cfg['admin_user'], $cfg['admin_pass'],
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$alkalmaz = in_array('--alkalmaz', $argv, true);
$rows = $db->query('SELECT id, lang, chapter_no, slug, title FROM help_article ORDER BY lang, sort_order')->fetchAll();

$valtozik = 0; $kihagyva = 0;
foreach ($rows as $r) {
    $kell = help_slug((string)$r['chapter_no'], (string)$r['title']);
    if ($kell === '' || $kell === (string)$r['slug']) { continue; }

    // Magatol keletkezett-e? A slug elejerol a szamelotagot levagva a
    // maradeknak a cim slugositott alakjanak kell lennie.
    $cimResz  = help_slug('', (string)$r['title']);
    $slugResz = preg_replace('/^[0-9]+(?:-[0-9]+)*-/', '', (string)$r['slug']);
    if ($cimResz === '' || $slugResz !== $cimResz) {
        $kihagyva++;
        continue;                                   // kezzel allitottak be
    }

    printf("%-3s %-8s %-34s -> %s\n", $r['lang'], $r['chapter_no'], $r['slug'], $kell);
    $valtozik++;
    if ($alkalmaz) { slug_change($db, (int)$r['id'], $kell); }
}

echo "\n", $valtozik, " cim ", ($alkalmaz ? 'atirva' : 'ternne at'), ", ",
     $kihagyva, " kezzel beallitott cim erintetlen.\n";
if (!$alkalmaz && $valtozik > 0) {
    echo "Alkalmazashoz: --alkalmaz\n";
}
