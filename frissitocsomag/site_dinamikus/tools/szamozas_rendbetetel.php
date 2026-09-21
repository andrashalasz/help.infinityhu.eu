<?php
/**
 * szamozas_rendbetetel.php - a fejezetszamok egyszeri helyretetele.
 *
 * A frissites csak a MOSTANTOL vegzett muveleteket szamozza ujra (torles,
 * huzas, visszaallitas). A korabban keletkezett hezagok - regi torlesekbol
 * vagy meg a Word-importbol - ottmaradnak. Ez az eszkoz azokat teszi rendbe:
 *
 *   1. FOFEJEZETEK: bezarja a hezagot (14 torolve -> 15-bol 14 lesz).
 *      A kezdoszam nem valtozik: ha a sugo az 5-ossel indul, marad az 5-os.
 *   2. FEJEZETEK: fofejezetenkent folyamatos szamozas a megjelenitesi
 *      sorrend szerint (5.2, 5.3, 5.4 - nem 5.2, 5.4, 5.7).
 *
 * A szamozas NYELVFUGGETLEN: a forrasnyelv sorrendje donti el, es minden
 * nyelvi valtozat egyutt kapja az uj szamot.
 *
 * Az URL-ek is kovetik a szamot, de a REGI cimek nem halnak el: bekerulnek
 * a slug-tortenetbe, es 301-gyel atiranyitanak. Amit kezzel allitottak be,
 * ahhoz nem nyul.
 *
 *   php tools/szamozas_rendbetetel.php              <- csak megmutatja
 *   php tools/szamozas_rendbetetel.php --alkalmaz   <- es meg is csinalja
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
$src = admin_source_lang();

/** A mostani allapot: fofejezet => [fejezetszamok] a forrasnyelven. */
function allapot(PDO $db, string $src): array
{
    $ki = [];
    $m = $db->prepare('SELECT id, chapter_no, title FROM help_module
                        WHERE lang = ? ORDER BY sort_order, id');
    $m->execute([$src]);
    foreach ($m->fetchAll() as $mod) {
        $a = $db->prepare('SELECT chapter_no FROM help_article
                            WHERE module_id = ? AND lang = ? ORDER BY sort_order, id');
        $a->execute([$mod['id'], $src]);
        $ki[] = ['no' => (string)$mod['chapter_no'], 'title' => (string)$mod['title'],
                 'cikkek' => $a->fetchAll(PDO::FETCH_COLUMN)];
    }
    return $ki;
}

function kiir(array $allapot, string $cim): void
{
    echo "\n$cim\n";
    foreach ($allapot as $m) {
        printf("  %-5s %-32s %s\n", $m['no'], mb_substr($m['title'], 0, 32),
               $m['cikkek'] ? implode(', ', $m['cikkek']) : '(nincs fejezet)');
    }
}

$elotte = allapot($db, $src);
kiir($elotte, '=== MOSTANI ÁLLAPOT ===');

if (!$alkalmaz) {
    // Szarazon: egy tranzakcioban elvegezzuk, megmutatjuk, majd visszagorgetjuk.
    $db->beginTransaction();
}
$n  = renumber_modules($db);
$mm = $db->prepare('SELECT id FROM help_module WHERE lang = ? ORDER BY sort_order, id');
$mm->execute([$src]);
foreach ($mm->fetchAll(PDO::FETCH_COLUMN) as $modId) {
    $n += renumber_module($db, (int)$modId, $src);
}
$utana = allapot($db, $src);

if (!$alkalmaz) {
    kiir($utana, '=== ÍGY NÉZNE KI ===');
    $db->rollBack();
    echo "\n$n sor változna. Semmit nem írtam — alkalmazáshoz: --alkalmaz\n";
} else {
    kiir($utana, '=== AZ ÚJ ÁLLAPOT ===');
    echo "\n$n sor átírva.\n";
    $d = (int)$db->query('SELECT count(*) FROM (SELECT lang, chapter_no FROM help_article
                          GROUP BY lang, chapter_no HAVING count(*) > 1) z')->fetchColumn();
    echo $d === 0 ? "Ellenőrzés: nincs duplikált fejezetszám.\n"
                  : "FIGYELEM: $d duplikált fejezetszám maradt!\n";
}
