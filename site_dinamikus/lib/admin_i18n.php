<?php
/**
 * admin_i18n.php - a KEZELOFELULET forditasa.
 *
 * A kod kulcsokat hasznal, nem beegetett magyar szoveget:
 *
 *     'save'                    ->  "Mentés" / "Save" / "Speichern"
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
 * A kodban ELOFORDULO forditando szovegek jegyzeke.
 *
 * Vegigolvassa a forrasfajlokat, es kigyujti a '...' hivasok kulcsat.
 * Igy a Beallitasok fuloni forditó tablazat magatol koveti a kodot - nem kell
 * kulon karbantartani egy listat, es nem maradhat le rola uj szoveg.
 *
 * @return array<string,string>  kulcs => alapszoveg (a kulcs maga, ha nincs jegyezve)
 */
function ui_keys_in_use(): array
{
    static $keys = null;
    if ($keys !== null) { return $keys; }

    $known = ui_default();
    $found = [];
    foreach (glob(__DIR__ . '/*.php') ?: [] as $file) {
        if (basename($file) === 'admin_i18n.php') { continue; }   // sajat magat ne olvassa
        $src = (string)@file_get_contents($file);
        if (preg_match_all("/\bt\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m)) {
            foreach ($m[1] as $k) {
                $k = str_replace(["\\'", '\\\\'], ["'", '\\'], $k);
                $found[$k] = $known[$k] ?? $k;
            }
        }
    }
    ksort($found);
    return $keys = $found ?: $known;
}

/**
 * A forrasnyelvi (magyar) alapszovegek azokhoz a kulcsokhoz, amelyek nem
 * maguk a magyar szoveg (pl. 'tab.articles'). Ami nincs itt, annal a KULCS
 * maga az alapszoveg - igy 'Mentés' forditas nelkul is helyesen jelenik meg.
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
        'head.fs.down'   => 'Kisebb betű (dupla kattintás: alapméret)',
        'head.fs.up'     => 'Nagyobb betű (dupla kattintás: alapméret)',

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

        // --- fejezet adatai ---
        'meta.mire'     => 'Mindkettő magától áll be a fejezet létrehozásakor — csak akkor '
                           . 'nyúlj hozzájuk, ha felül akarod írni. A <b>címet</b> a szerkesztő '
                           . 'tetején írod, a <b>főfejezetet</b> pedig húzással változtatod a bal '
                           . 'oldali listában.',

        // --- uj fofejezet egy kattintassal ---
        'ujfofejezet.nev'         => 'Névtelen főfejezet',
        'ujfejezet.nev'           => 'Névtelen fejezet',
        'flash.module.quick.kesz' => 'Létrehoztam a(z) <b>{szam}.</b> főfejezetet minden nyelven. '
                                     . '<b>Írd be a nevét</b> a szerkesztő tetején — az lesz a '
                                     . 'főfejezet neve is —, és írd meg, mire való ez a menüpont.',

        // --- osszefuzott uzenetek: a valtozo resz {helyorzoben} all, hogy a
        //     fordito EGESZ mondatot lasson, ne toredeket ---
        'media.tipus' => 'Nem támogatott fájltípus: {tipus}. Kép: PNG, JPG, GIF, WebP, SVG '
                         . '— videó: MP4, WebM, MOV.',
        'valtozat.mentette' => 'A <b>{n}. változatot</b> {mikor}-kor mentette <b>{ki}</b>',
        'ismeretlen' => 'ismeretlen',

        // --- megerosito kerdesek, amikben nev vagy szam szerepel ---
        'torles.fofejezet' => 'Törlöd a(z) „{nev}” főfejezetet? Minden nyelven a Kukába kerül, '
            . 'ahonnan visszaállítható. Csak akkor sikerül, ha egyetlen nyelven sincs benne fejezet.',
        'valtozat.betoltes.szam' => 'Betöltöd a(z) {n}. változatot vázlatként? A jelenlegi vázlat felülíródik.',
        'torles.nyelv' => 'Törlöd a(z) „{nev}” nyelvet? Csak akkor sikerül, ha nincs rajta egyetlen fejezet sem.',

        // --- hosszabb magyarazo szovegek (egyben, hogy egesz mondat legyen) ---
        'ures.fejezetek' => 'Válassz egy fejezetet a bal oldali listából — vagy hozz létre újat a '
            . '<b>+</b> gombbal. A főfejezet címére kattintva a leírása nyílik meg: ott írod meg, '
            . 'mire való az a menüpont.',
        'sugo.kepfeltoltes' => 'Képet és videót a <b>Kép</b> / <b>Videó</b> gombbal tölthetsz fel '
            . '— vagy egyszerűen <b>húzd rá a fájlt a szövegre</b>, illetve illeszd be vágólapról. '
            . 'A feltöltés azonnal megtörténik, nem kell előre a Képek fülre menni.',
        'vazlat.figyelmeztetes' => 'Az alábbi szerkesztő a vázlatot mutatja — a nyilvános oldalon '
            . 'még a korábbi változat látszik. Mentve: {mikor}',

        // --- osszefuzott hibauzenetek {helyorzokkel} ---
        'kozzetetel.nincs.forditas' => 'Ez a(z) {nyelv} változat még nincs lefordítva — üresen nem '
            . 'teszem közzé. Írd meg a fordítást, vagy kérj gépi nyersfordítást a <b>Fordítás</b> fülön.',
        'valtozat.betoltve' => 'A(z) {n}. változat betöltve vázlatként. Nézd át, és tedd közzé, ha jó.',
        'mt.nem.erheto'     => 'A fordítószolgáltatás nem érhető el: {reszlet}',
        'media.mappa.nincs' => 'A képek mappája nem hozható létre: {mappa}',
        'media.mappa.ro'    => 'A képek mappája nem írható: {mappa}',
        'kep.formatum.kihagyva' => 'Nem támogatott képformátum kihagyva: .{kit}',

        // --- hosszabb magyarazo blokkok (egyben, {url} = hivatkozas) ---
        'jelszo.nem.kotelezo' => 'Adj meg egy új jelszót ehhez a fiókhoz. Ez nem kötelező — a '
            . '<a href="{url}">Beállítások</a> fülön is bármikor elvégezhető.',
        'kiadas.gyujtes' => 'A közzétételkor megadott összefoglalók ebbe a kiadásba gyűlnek. '
            . 'Lezárni a <a href="{url}">Beállítások</a> fülön lehet.',
        'fejezet.nincs.kozzeteve' => 'Ez a fejezet <b>nincs közzétéve</b>, a nyilvános oldalon '
            . 'nem jelenik meg.',
        'valtozatok.uresen' => 'Még nem volt közzététel ezen a fejezeten, ezért nincs mivel '
            . 'összehasonlítani.<br><span class="muted">A rendszer minden közzétételkor elmenti '
            . 'az előző állapotot — az első közzétételtől kezdve itt fognak sorakozni a változatok, '
            . '<b>Összehasonlítás</b> és <b>Visszatöltés</b> gombbal.</span>',
        'torles.kukaba.magyarazat' => 'A fejezet a <b>Kukába</b> kerül a korábbi változataival '
            . 'együtt — onnan egy kattintással visszaállítható, amíg ki nem üríted.',
        'kiadas.magyarazat' => 'Minden közzétételkor keletkezik egy <b>összefoglaló</b>, és a '
            . '<b>nyitott</b> kiadásba gyűlik. Ez az a szöveg, amit az olvasó a <b>Frissítések</b> '
            . 'lapon lát. A kiadás lezárása dátumot és verziószámot ad nekik, leveszi az '
            . 'újdonságjelzéseket, és megnyit egy újat.',
        'forditas.magyarazat' => 'A forrásnyelvről fordítunk. Válaszd ki a fejezetet, és írd meg '
            . 'mellé az idegen nyelvű változatot — vagy kérj gépi nyersfordítást, és javíts bele. '
            . 'A mentés <b>vázlatot</b> készít, közzétenni külön kell.',

        // --- osszehasonlitas ---
        'diff.cim'           => 'Összehasonlítás: {n}. változat → {mihez}',
        'diff.cim.valtozott' => 'A cím is változott: „<b>{regi}</b>” → „<b>{uj}</b>”',
        'diff.uj.szo'        => '{n} új szó',
        'diff.elhagyott.szo' => '{n} elhagyott szó',

        // --- Word import magyarazat ---
        'import.bevezeto' => 'Tölts fel egy <b>.docx</b> fájlt: a rendszer fejezetekre bontja, a '
            . 'képeket kibontja, és <b>összehasonlítja a mostani tartalommal</b>. Te döntöd el '
            . 'fejezetenként, mit veszek át. Ami átkerül, az <b>vázlat</b> lesz — a nyilvános '
            . 'oldalon csak közzététel után látszik.',
        'import.tagolas' => 'A tagolás a <b>címsorstílusokból</b> jön: <b>Címsor 1</b> = modul, '
            . '<b>Címsor 2</b> = fejezet, <b>Címsor 3–5</b> = a fejezeten belüli szakaszok. A '
            . 'fejezetszámot a címből olvassa ki („5.4 Kintlévőség kezelés”), és ez alapján '
            . 'párosítja a meglévő fejezethez. A képek a tartalmuk hash-ével kapnak nevet, így '
            . 'nem duplikálódnak.',

        // --- a felulet gepi forditasa ---
        'flash.ui.translate.nincs-mit' => 'Ezen a nyelven minden szöveg le van fordítva.',
        'flash.ui.translate.kesz'      => '<b>{n} szöveg</b> lefordítva {nyelv} nyelvre. '
            . 'Nézd át őket alább — amit átírsz, azt a gép többé nem bántja.',
        'flash.ui.translate.hiba'      => 'A gépi fordítás megszakadt: {reszlet} '
            . 'Ami addig elkészült, elmentve — nyomd meg újra a gombot a folytatáshoz.',
        'mt.ui.tul.hosszu' => 'A köteg túl hosszú volt a fordítónak. Nyomd meg újra a gombot — '
            . 'a rendszer kisebb adagokban folytatja.',
        'mt.ui.rossz.valasz'           => 'A fordító {kert} szöveget kapott, de {kapott} darabot '
            . 'adott vissza. Próbáld újra.',
        'ui.forditas.gomb'  => 'Hiányzó szövegek gépi fordítása',
        'ui.forditas.sugo'  => 'A {nyelv} nyelven még lefordítatlan felületszövegeket a beállított '
            . 'gépi fordító átülteti. Amit már kézzel beírtál, ahhoz nem nyúl. Sok szövegnél ez '
            . 'eltarthat egy percig.',

        // --- a felulet szovegeinek lapja ---
        'uitext.bevezeto' => 'Ezek a kezelőfelület saját feliratai — gombok, címkék, üzenetek. '
            . 'A bal oldali oszlop a forrásnyelvi szöveg, azt a kód adja. Amit üresen hagysz, az a '
            . 'forrásnyelven jelenik meg, tehát a felület sosem marad felirat nélkül.',
        'uitext.forditsd'     => '{n} hiányzó lefordítása',
        'uitext.kesz'         => 'Minden lefordítva',
        'uitext.kereses'      => 'Keresés a szövegek között…',
        'uitext.szuro.mind'   => 'Mind',
        'uitext.szuro.hianyzo'=> 'Csak a hiányzók',
        'uitext.szuro.hianyzo.nyelv' => 'Hiányzik: {nyelv}',
        'uitext.szuro.kesz'   => 'Minden nyelven kész',
        'uitext.mentes.sugo'  => 'A módosítások csak mentés után lépnek érvénybe.',
        'uitext.talalat'      => '{n} szöveg',
        'uitext.folyamat'     => 'Fordítás… {kesz} / {ossz}',
        // teruletek nevei
        'Menü és fejléc' => 'Menü és fejléc',
        'Fejezetlista' => 'Fejezetlista',
        'Törlés és megerősítés' => 'Törlés és megerősítés',
        'Szerkesztő' => 'Szerkesztő',
        'Változatok' => 'Változatok',
        'Közzététel' => 'Közzététel',
        'Kiadások' => 'Kiadások',
        'Word import' => 'Word import',
        'Fordítás' => 'Fordítás',
        'Gépi fordítás' => 'Gépi fordítás',
        'Felületszövegek' => 'Felületszövegek',
        'Képek, videók' => 'Képek, videók',
        'Belépés és jelszó' => 'Belépés és jelszó',
        'Visszajelző üzenetek' => 'Visszajelző üzenetek',
        'Állapotok' => 'Állapotok',
        'Súgószövegek' => 'Súgószövegek',
        'Visszavonás' => 'Visszavonás',
        'Általános' => 'Általános',

        // --- kozzetetel ---
        'kozzetetel.forditas.marad' => 'A fordítások maradjanak naprakészek',
        'kozzetetel.forditas.sugo'  => 'Egy fordítás attól lesz <b>elavult</b>, hogy a magyar '
            . 'szöveget közzétetted egy módosítás után — akkor is, ha csak egy elütést '
            . 'javítottál. Ezt bepipálva a fordítások naprakészek maradnak, és a gépi '
            . 'fordítás sem indul el újra.',

        // --- szerkeszto ---
        'ed.undo' => 'Visszavonás (Ctrl+Z)',
        'ed.redo' => 'Újra (Ctrl+Shift+Z)',

        // --- torles a listaban ---
        'torles.cim'    => 'A fejezet törlése (a Kukába kerül)',
        'torles.kerdes' => 'Törlöd ezt a fejezetet: „{nev}”? A Kukába kerül a korábbi '
                           . 'változataival együtt — onnan egy kattintással visszaállítható, '
                           . 'amíg ki nem üríted.',

        // --- a fejezetlista eszkozsora ---
        'eszkoz.osszecsuk'     => 'Összecsuk',
        'eszkoz.osszecsuk.cim' => 'Mindent összecsuk / kinyit',
        'eszkoz.sorrend'       => 'Sorrend',
        'eszkoz.sorrend.cim'   => 'Sorrend átrendezése húzással',
        'eszkoz.ujfo'          => 'Új főfejezet',
        'eszkoz.ujfo.cim'      => 'Új főfejezet a lista végén, minden nyelven',

        // --- belepes, jelszo (auth.php) ---
        'auth.rossz-jelszo'      => 'Hibás felhasználónév vagy jelszó.',
        'auth.inaktiv'           => 'Ez a fiók inaktív.',
        'auth.zarolva'           => 'Túl sok sikertelen próbálkozás. Próbáld újra {perc} perc múlva.',
        'auth.pw.rovid'          => 'A jelszó legyen legalább 8 karakter.',
        'auth.pw.hosszu'         => 'A jelszó túl hosszú (legfeljebb 200 karakter).',
        'auth.pw.azonos-nevvel'  => 'A jelszó ne egyezzen meg a felhasználónévvel.',
        'auth.pw.gyenge'         => 'Ez a jelszó túl könnyen kitalálható, válassz másikat.',
        'auth.pw.betu-szam'      => 'A jelszó tartalmazzon betűt és számot is.',

        // --- visszajelzo uzenetek (flash) ---
        'flash.article.create.cimtelen'                                     => 'Fejezet létrehozva — <b>írd be a címét</b> a szerkesztő tetején, majd mentsd a vázlatot.',
        'flash.article.create.kesz'                                         => 'Fejezet létrehozva. Írd meg a tartalmát, majd tedd közzé.',
        'flash.article.create.modult-adni'                                  => 'Modult meg kell adni.',
        'flash.article.delete.kesz'                                         => 'A fejezet a <b>Kukába</b> került: {nev}.',
        'flash.article.delete.nincs-ilyen-fejezet'                          => 'Nincs ilyen fejezet.',
        'flash.article.discard.vazlat-eldobva-kozzetett-tartalom'           => 'A vázlat eldobva, a közzétett tartalom változatlan.',
        'flash.article.draft.vazlat-mentve'                                 => 'Vázlat mentve.',
        'flash.article.meta.cim-ures'                                       => 'A cím nem lehet üres.',
        'flash.article.meta.atszamozva' => 'Mentve. A főfejezet új számot kapott, és vele <b>{n} sor</b> — az alatta levő fejezetek is, minden nyelven.',
        'flash.article.meta.fejezet-adatai-mentve'                          => 'A fejezet adatai mentve.',
        'flash.article.publish.hiba'                                        => 'A közzététel nem sikerült: {reszlet}',
        'flash.article.publish.keep-tr' => 'A fordítások <b>{n} nyelven</b> naprakészek maradtak.',
        'flash.article.publish.kesz'                                        => 'A fejezet közzétéve — a nyilvános oldalon már ez látszik.',
        'flash.article.publish.mt'                                          => 'Gépi fordítás: <b>{nyelvek}</b> vázlat elkészült.',
        'flash.article.publish.mt.hiba'                                     => 'Gépi fordítás ({nyelv}): {reszlet}',
        'flash.article.publish.nincs-kozzetetelre-varo-vazlat'              => 'Nincs közzétételre váró vázlat ehhez a fejezethez.',
        'flash.article.restore.nincs-ilyen-korabbi-valtozat'                => 'Nincs ilyen korábbi változat.',
        'flash.article.unpublish.ezen-fejezeten-volt-kozzetetel'            => 'Ezen a fejezeten még nem volt közzététel, nincs mit visszavonni.',
        'flash.article.unpublish.kesz'                                      => 'A közzététel <b>visszavonva</b> — a nyilvános oldalon ismét az előző változat látszik. A visszavont szöveg vázlatként megmaradt, nem veszett el.',
        'flash.articles.bulk.jeloltel-ki-egyetlen-fejezetet'                => 'Nem jelöltél ki egyetlen fejezetet sem.',
        'flash.bulk.athelyezve'                                             => '<b>{n} fejezet</b> áthelyezve.',
        'flash.bulk.bekapcsolva'                                            => '<b>{n} fejezet</b> bekapcsolva.',
        'flash.bulk.kikapcsolva'                                            => '<b>{n} fejezet</b> kikapcsolva.',
        'flash.bulk.kimaradt'                                               => '<b>{n} fejezet kimaradt</b>, mert még nincs lefordítva: {lista}',
        'flash.bulk.kozzeteve'                                              => '<b>{n} fejezet</b> közzétéve.',
        'flash.bulk.kukaba'                                                 => '<b>{n} fejezet</b> a Kukába került.',
        'flash.changelog.delete.bejegyzes-torolve-valtozasnaplobol-fejezet' => 'A bejegyzés törölve a változásnaplóból — a fejezet tartalmát nem érintette.',
        'flash.changelog.delete.ehhez-adminisztratori-jog'                  => 'Ehhez adminisztrátori jog kell.',
        'flash.changelog.save.bejegyzes-mentve'                             => 'A bejegyzés mentve.',
        'flash.changelog.save.ehhez-adminisztratori-jog'                    => 'Ehhez adminisztrátori jog kell.',
        'flash.chpw.jelenlegi-jelszo-stimmel'                               => 'A jelenlegi jelszó nem stimmel.',
        'flash.chpw.jelszo-megvaltozott'                                    => 'A jelszó megváltozott.',
        'flash.chpw.ket-uj-jelszo-egyezik'                                  => 'A két új jelszó nem egyezik.',
        'flash.chpw.uj-jelszo-ugyanaz-mint'                                 => 'Az új jelszó nem lehet ugyanaz, mint a régi.',
        'flash.delete.ismeretlen-muvelet'                                   => 'Ismeretlen művelet.',
        'flash.export.hiba'                                                 => 'Az export nem sikerült: {reszlet}',
        'flash.import.apply.jeloltel-ki-egyetlen-fejezetet'                 => 'Nem jelöltél ki egyetlen fejezetet sem.',
        'flash.import.apply.kesz'                                           => 'Kész: <b>{uj}</b> új fejezet, <b>{frissitett}</b> frissített vázlat',
        'flash.import.apply.kozzeteve'                                      => ', <b>{n}</b> közzétéve.',
        'flash.import.apply.mt'                                             => 'Gépi fordítás: <b>{n}</b> nyelvi változat elkészült',
        'flash.import.apply.mt.hiba'                                        => ', {n} nem sikerült.',
        'flash.import.apply.vazlatok'                                       => '. A vázlatokat a Fejezetek fülön nézheted át és teheted közzé.',
        'flash.import.discard.import-eldobva-fejezetek-erintetlenek'        => 'Az import eldobva. (A fejezetek érintetlenek maradtak.)',
        'flash.import.upload.csak-docx-fajlt-tudok'                         => 'Csak .docx fájlt tudok beolvasni (a régi .doc formátumot nem).',
        'flash.import.upload.hiba'                                          => 'A Word-fájl feldolgozása nem sikerült: {reszlet}',
        'flash.import.upload.kesz'                                          => 'Beolvasva: <b>{n} fejezet</b>, {kepek} új kép. Nézd át az eltéréseket, és jelöld ki, mit importáljak.',
        'flash.import.upload.nincs-fejezet'                                 => 'A dokumentumban nem találtam fejezeteket. A modulokat 1. szintű, a fejezeteket 2. szintű címsorral kell jelölni (Címsor 1 / Címsor 2).',
        'flash.lang.add.ehhez-adminisztratori-jog'                          => 'Ehhez adminisztrátori jog kell.',
        'flash.lang.add.kesz'                                               => 'A(z) <b>{nev}</b> nyelv felvéve, {n} főfejezettel. A Fordítás fülön máris megjelenik — a főfejezetek nevét a Fejezetek fülön írhatod át erre a nyelvre.',
        'flash.lang.add.kod-ket-ot-betu'                                    => 'A kód két-öt betű legyen (pl. sk), a név pedig nem lehet üres.',
        'flash.lang.add.nyelvkod-szerepel-listaban'                         => 'Ez a nyelvkód már szerepel a listában.',
        'flash.lang.delete.ehhez-adminisztratori-jog'                       => 'Ehhez adminisztrátori jog kell.',
        'flash.lang.delete.forrasnyelvet-torolni-ezen-irodnak'              => 'A forrásnyelvet nem lehet törölni — ezen íródnak a fejezetek.',
        'flash.lang.delete.kesz'                                            => 'A(z) <b>{nev}</b> nyelv törölve.',
        'flash.lang.delete.nem-ures'                                        => 'Ezen a nyelven még vannak fejezetek — előbb töröld őket. Ha csak el akarod rejteni, vedd ki a „látszik" pipát.',
        'flash.lang.delete.nincs-ilyen-nyelv'                               => 'Nincs ilyen nyelv.',
        'flash.lang.save.ehhez-adminisztratori-jog'                         => 'Ehhez adminisztrátori jog kell.',
        'flash.lang.save.nyelv-mentve'                                      => 'A nyelv mentve.',
        'flash.letrehozasi.hiba'                                            => 'Létrehozási hiba: {reszlet}',
        'flash.media.delete.ervenytelen-fajlnev'                            => 'Érvénytelen fájlnév.',
        'flash.media.delete.fajl-torolve'                                   => 'A fájl törölve.',
        'flash.media.delete.fajlt-hasznalja-legalabb-fejezet'               => 'Ezt a fájlt még használja legalább egy fejezet, ezért nem töröltem.',
        'flash.media.upload.kesz'                                           => 'Feltöltve: <b>{n}</b> fájl.',
        'flash.media.upload.kihagyva'                                       => 'Kihagyva: {n}.',
        'flash.media.upload.valasztottal-fajlt'                             => 'Nem választottál fájlt.',
        'flash.mentesi.hiba'                                                => 'Mentési hiba: {reszlet}',
        'flash.module.delete.kesz'                                          => 'A főfejezet mind a(z) <b>{n} nyelven</b> a <b>Kukába</b> került.',
        'flash.module.delete.nem-ures'                                      => 'Ez a főfejezet még tartalmaz fejezeteket ({lista}) — előbb helyezd át vagy töröld őket. Figyelj rá, hogy a forrásnyelv mellett a többi nyelvű változatban is lehetnek fejezetek.',
        'flash.module.delete.nincs-ilyen-fofejezet'                         => 'Nincs ilyen főfejezet.',
        'flash.module.save.fofejezet-neve-ures'                             => 'A főfejezet neve nem lehet üres.',
        'flash.module.save.mentve'                                          => 'Főfejezet mentve.',
        'flash.module.save.uj'                                              => 'Főfejezet létrehozva minden nyelven, a lista végén. A többi nyelvű nevét a ✎ gombbal írhatod át.',
        'flash.move.valaszd-ki-melyik-modulba'                              => 'Válaszd ki, melyik modulba kerüljenek.',
        'flash.release.close.ehhez-adminisztratori-jog'                     => 'Ehhez adminisztrátori jog kell.',
        'flash.release.close.hiba'                                          => 'A kiadás lezárása nem sikerült: {reszlet}',
        'flash.release.close.ismeretlen-muvelet'                            => 'Ismeretlen művelet.',
        'flash.release.close.kesz'                                          => 'A(z) {verzio} kiadás lezárva, {n} bejegyzéssel. A következő nyitott kiadás: {kovetkezo}.',
        'flash.release.close.lezarando-kovetkezo-verzioszamot-is'           => 'A lezárandó és a következő verziószámot is add meg.',
        'flash.screen.delete.hozzarendeles-torolve'                         => 'Hozzárendelés törölve.',
        'flash.screen.save.foglalt'                                         => 'Ehhez az útvonalhoz már tartozik fejezet.',
        'flash.screen.save.hozzarendeles-mentve'                            => 'Hozzárendelés mentve.',
        'flash.screen.save.utvonalat-fejezetet-is-adni'                     => 'Az útvonalat és a fejezetet is meg kell adni.',
        'flash.session.lejart-vagy-ervenytelen-munkamenet'                  => 'Lejárt vagy érvénytelen munkamenet – töltsd újra az oldalt, és próbáld meg ismét.',
        'flash.setting.save.beallitasok-mentve'                             => 'A beállítások mentve.',
        'flash.setting.save.ehhez-adminisztratori-jog'                      => 'Ehhez adminisztrátori jog kell.',
        'flash.slug.foglalt'                                                => 'Ez az URL-azonosító (slug) már foglalt ezen a nyelven.',
        'flash.translate.auto.hiba'                                         => '{nyelv}: {reszlet}',
        'flash.translate.auto.kesz'                                         => 'Gépi fordítás kész: <b>{nyelvek}</b> — vázlatként mentve, nézd át és tedd közzé.',
        'flash.translate.auto.nem-forras'                                   => 'Nem történt fordítás — a forrás csak forrásnyelvi fejezet lehet.',
        'flash.translate.auto.nincs'                                        => 'Nem történt fordítás — nincs ilyen fejezet.',
        'flash.translate.auto.semmi'                                        => 'Nem történt fordítás.',
        'flash.translate.auto.ures'                                         => 'Nem történt fordítás — ennek a fejezetnek <b>még nincs tartalma</b>. Írd meg a forrásnyelvi szöveget, és mentsd legalább vázlatként.',
        'flash.translate.save.hiba'                                         => 'A fordítás mentése nem sikerült: {reszlet}',
        'flash.translate.save.kozzeteve'                                    => 'A fordítás mentve és közzétéve.',
        'flash.translate.save.vazlat'                                       => 'A fordítás vázlatként mentve.',
        'flash.trash.purge.mind'                                            => 'A Kuka kiürítve ({n} elem).',
        'flash.trash.purge.veglegesen-torolve'                              => 'Véglegesen törölve.',
        'flash.trash.restore.kesz'                                          => '<b>{n} elem</b> visszaállítva.',
        'flash.ui.save.ehhez-adminisztratori-jog'                           => 'Ehhez adminisztrátori jog kell.',
        'flash.ui.save.kesz'                                                => '<b>{n} szöveg</b> mentve. Az üresen hagyottak a forrásnyelven jelennek meg.',
        'flash.upload.hiba'                                                 => 'A feltöltés nem sikerült.',
        'flash.user.delete.ehhez-adminisztratori-jog'                       => 'Ehhez adminisztrátori jog kell.',
        'flash.user.delete.felhasznalo-torolve'                             => 'Felhasználó törölve.',
        'flash.user.delete.sajat-magadat-torolheted'                        => 'Saját magadat nem törölheted.',
        'flash.user.delete.utolso-adminisztratort-torolni'                  => 'Az utolsó adminisztrátort nem lehet törölni.',
        'flash.user.resetpw.ehhez-adminisztratori-jog'                      => 'Ehhez adminisztrátori jog kell.',
        'flash.user.resetpw.kesz'                                           => 'A(z) {nev} jelszava beállítva. Első belépéskor cserélnie kell.',
        'flash.user.save.ehhez-adminisztratori-jog'                         => 'Ehhez adminisztrátori jog kell.',
        'flash.user.save.felhasznalo-adatai-mentve'                         => 'A felhasználó adatai mentve.',
        'flash.user.save.felhasznalo-letrehozva-elso-belepeskor'            => 'Felhasználó létrehozva. Az első belépéskor jelszót kell cserélnie.',
        'flash.user.save.felhasznalonev-foglalt'                            => 'Ez a felhasználónév már foglalt.',
        'flash.user.save.felhasznalonev-kotelezo'                           => 'A felhasználónév kötelező.',

        // --- visszavono gombok ---
        'undo.publish'     => 'Közzététel visszavonása',
        'undo.restore'     => 'Visszaállítom',
        'undo.restore.all' => 'Mindet visszaállítom',
        'undo.undo'        => 'Visszavonom',

        // --- lathatosag (szem ikon) ---
        'lathato.be'        => '„{nev}” el van rejtve — kattints a megjelenítéshez',
        'lathato.cim'       => 'Megjelenés a súgóban',
        'lathato.egy'       => 'Csak ezen a nyelven',
        'lathato.hol.egy'   => 'csak <b>{ny}</b> nyelven',
        'lathato.hol.mind'  => '<b>{n} nyelven</b>',
        'lathato.kerdes.be' => 'Megjelenik a nyilvános oldalon: „{nev}”?',
        'lathato.kerdes.ki' => 'Elrejted a nyilvános oldalról: „{nev}”?',
        'lathato.kesz.be'   => '„{nev}” mostantól <b>látszik</b> a nyilvános oldalon — {hol}.',
        'lathato.kesz.ki'   => '„{nev}” <b>elrejtve</b> a nyilvános oldalról — {hol}. A tartalma megmarad.',
        'lathato.ki'        => '„{nev}” látszik a nyilvános oldalon — kattints az elrejtéshez',
        'lathato.mind'      => 'Minden nyelven',

        // --- fofejezet-leiras ---
        'fofejezet.leiras.kerdes' => 'Létrehozzam a(z) „{nev}” főfejezet leírását? Üres fejezetként jön létre, a címét és a szövegét a szerkesztőben írod meg.',
        'fofejezet.leiras.nincs' => 'Ehhez a főfejezethez még nincs leírás — kattints, és megírhatod, mire való',
        'fofejezet.leiras.nyit'  => 'A főfejezet leírása: mire való ez a menüpont',
    ];
    return $key === '' ? $d : ($d[$key] ?? $key);
}
