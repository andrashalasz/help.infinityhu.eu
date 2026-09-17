<?php
/**
 * felulet_forditas.php - a kezelofelulet hianyzo szovegeinek leforditasa.
 *
 * Ugyanazt csinalja, mint a Beallitasok fuloni gomb, csak parancssorbol:
 * ott ugyanis 600+ szoveg forditasa tullepi a webkiszolgalo idokorlatjat.
 * Amit a felhasznalo mar beirt, ahhoz NEM nyul.
 *
 *   docker compose exec php php /var/www/html/tools/felulet_forditas.php
 *   ... egy nyelvre:            ... felulet_forditas.php de
 */
declare(strict_types=1);

require __DIR__ . '/../lib/util.php';
require __DIR__ . '/../lib/auth.php';
require __DIR__ . '/../lib/admin_i18n.php';
require __DIR__ . '/../lib/admin_layout.php';
require __DIR__ . '/../lib/mt.php';

$cfg = require __DIR__ . '/../config.php';
$db  = new PDO($cfg['admin_dsn'], $cfg['admin_user'], $cfg['admin_pass'],
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$only    = $argv[1] ?? '';
$source  = admin_source_lang();
$targets = array_keys(admin_target_langs());
if ($only !== '') { $targets = array_values(array_filter($targets, static fn($l) => $l === $only)); }
if (!$targets) { fwrite(STDERR, "Nincs ilyen celnyelv.\n"); exit(1); }

$tr = Translator::fromConfig($cfg, $db);
if (!$tr->isConfigured()) { fwrite(STDERR, "Nincs beallitva gepi fordito.\n"); exit(1); }

$keys = ui_keys_in_use();
$ins  = $db->prepare('INSERT INTO help_ui (lang, ui_key, text) VALUES (?,?,?)
                      ON DUPLICATE KEY UPDATE text = VALUES(text)');

foreach ($targets as $to) {
    $have = [];
    $q = $db->prepare("SELECT ui_key FROM help_ui WHERE lang = ? AND text <> ''");
    $q->execute([$to]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $k) { $have[$k] = true; }

    $todo = [];
    foreach ($keys as $key => $src) {
        if (!isset($have[$key]) && trim((string)$src) !== '') { $todo[$key] = $src; }
    }
    printf("%s: %d hianyzo szoveg\n", strtoupper($to), count($todo));
    if (!$todo) { continue; }

    $done = 0;
    foreach (mt_ui_chunks($todo) as $i => $chunk) {
        $t0 = microtime(true);
        try {
            $out = $tr->translateUi(array_map(static fn($k) => $todo[$k], $chunk), $source, $to);
        } catch (Throwable $e) {
            fwrite(STDERR, sprintf("  %d. koteg HIBA: %s\n", $i + 1, $e->getMessage()));
            continue;
        }
        foreach ($chunk as $n => $k) {
            $text = trim((string)($out[$n] ?? ''));
            if ($text === '') { continue; }
            $ins->execute([$to, $k, $text]);
            $done++;
        }
        printf("  %2d. koteg: %2d szoveg (%.1f mp)\n", $i + 1, count($chunk), microtime(true) - $t0);
    }
    printf("%s: kesz, %d szoveg elmentve\n\n", strtoupper($to), $done);
}
