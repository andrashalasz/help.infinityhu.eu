<?php
/**
 * admin_i18n.php - a KEZELOFELULET forditasa.
 *
 * A kod kulcsokat hasznal, nem beegetett magyar szoveget:
 *
 *     t('save')                    ->  "Mentés" / "Save" / "Speichern"
 *     t('n_chapters', ['n' => 5])  ->  "5 fejezet"
 *
 * Ami nincs leforditva, az MAGYARUL jelenik meg (a kulcs melle irt
 * alapszoveggel), igy a felulet sosem marad felirat nelkul, es a forditas
 * fokozatosan potolhato a Beallitasok fulon.
 */
declare(strict_types=1);

/** A felulet nyelve erre a keresre. */
function ui_lang(?string $set = null): string
{
    static $lang = null;
    if ($set !== null) { $lang = $set; }
    if ($lang !== null) { return $lang; }

    // 1. a bejelentkezett felhasznalo beallitasa, 2. a sugo forrasnyelve
    $u = function_exists('auth_user') ? auth_user() : null;
    $lang = (string)($u['ui_lang'] ?? '') ?: admin_source_lang();
    return $lang;
}

/** A betoltott forditasok (kulcs => szoveg) az aktualis felulet-nyelven. */
function ui_strings(?PDO $db = null): array
{
    static $map = null;
    if ($map !== null) { return $map; }

    $map = [];
    $lang = ui_lang();
    if ($lang === admin_source_lang()) { return $map; }   // a forrasnyelv az alapszoveg

    try {
        $pdo = $db ?? help_db_rw(require __DIR__ . '/../config.php');
        $st = $pdo->prepare('SELECT ui_key, text FROM help_ui WHERE lang = ?');
        $st->execute([$lang]);
        foreach ($st->fetchAll() as $r) { $map[(string)$r['ui_key']] = (string)$r['text']; }
    } catch (Throwable $e) {
        // a tabla meg nincs meg - marad az alapszoveg
    }
    return $map;
}

/**
 * Egy felulet-szoveg.
 *
 * @param string $key   a kulcs (pl. 'save')
 * @param array  $vars  behelyettesitendo ertekek: {n} -> $vars['n']
 * @param string $fallback  az alapszoveg, ha nincs forditas (a forrasnyelven)
 */
function t(string $key, array $vars = [], string $fallback = ''): string
{
    $map = ui_strings();
    $text = $map[$key] ?? ($fallback !== '' ? $fallback : ui_default($key));

    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

/**
 * A forrasnyelvi (magyar) alapszovegek. Ez a lista egyben a forditando
 * kulcsok jegyzeke is - a Beallitasok fulon ebbol keszul a forditó tablazat.
 */
function ui_default(string $key = ''): string|array
{
    static $d = [
        // --- fulek ---
        'tab.dashboard' => 'Áttekintés',
        'tab.articles'  => 'Fejezetek',
        'tab.import'    => 'Word import',
        'tab.translate' => 'Fordítás',
        'tab.releases'  => 'Kiadások',
        'tab.media'     => 'Képek, videók',
        'tab.export'    => 'Export',
        'tab.users'     => 'Felhasználók',
        'tab.trash'     => 'Kuka',
        'tab.settings'  => 'Beállítások',
        'tab.screens'   => 'Képernyők',

        // --- fejlec ---
        'head.search'    => 'Ugrás / keresés (Ctrl+K)',
        'head.keys'      => 'Billentyűparancsok (?)',
        'head.open'      => 'A súgó megnyitása új lapon',
        'head.theme'     => 'Világos / sötét téma',
        'head.settings'  => 'Beállítások',
        'head.account'   => 'Saját fiók, jelszócsere',
        'head.logout'    => 'Kilépés',
        'head.uilang'    => 'A felület nyelve',

        // --- altalanos gombok ---
        'save'    => 'Mentés',
        'cancel'  => 'Mégsem',
        'delete'  => 'Törlés',
        'create'  => 'Létrehozás',
        'close'   => 'Bezárás',
        'open'    => 'Megnyitás',
        'confirm' => 'Megerősítés',
        'yes_continue' => 'Igen, folytatom',

        // --- allapotok ---
        'state.missing' => 'hiányzik',
        'state.stale'   => 'elavult',
        'state.draft'   => 'vázlat',
        'state.ok'      => 'naprakész',
        'state.all'     => 'Mind',
    ];
    return $key === '' ? $d : ($d[$key] ?? $key);
}
