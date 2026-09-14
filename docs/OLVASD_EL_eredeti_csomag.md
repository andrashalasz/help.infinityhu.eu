# Infinity Súgó — teljes fejlesztői csomag

A tartalom kész: 109 fejezet, 3 nyelven (HU/EN/DE), 100%-osan lefordítva, **275 valódi képernyőképpel**.

**Ez a csomag most már bizonyítottan működik** — nem csak PHP-szintaxist ellenőriztem, hanem ténylegesen felállítottam egy futó Yii2-példányt ezzel a pontos kóddal, valódi PostgreSQL adatbázissal, és böngészőből lekértem az oldalakat. Lásd a `03_helyi_teszt/` mappát — ugyanezt te is le tudod futtatni.

## Négy rész

```
01_infinity_fo_rendszerbe/     → a súgó bekerül magába az Infinity-be
02_help_infinityhu_eu_domain/  → a súgó felmegy a help.infinityhu.eu domainre
03_helyi_teszt/                → EGY PARANCCSAL kipróbálható helyi teszt-környezet
media/                          → 275 valódi képernyőkép (közös, mindkét feladathoz)
```

Lehet, hogy ugyanaz a fejlesztő csinálja mindet, lehet, hogy más-más ember — a mappák egymástól függetlenül is átadhatók.

---

## 0. Először ezt próbáld ki: `03_helyi_teszt/`

Mielőtt bármit az éles Infinity-be tennétek, **győződjetek meg róla, hogy tényleg működik** — ehhez nem kell más, csak ez a mappa.

```bash
cd 03_helyi_teszt
chmod +x helyi_teszt_inditasa.sh
./helyi_teszt_inditasa.sh
```

Ez a szkript:
1. Létrehoz egy `infinity_teszt` PostgreSQL adatbázist
2. Betölti mind a három SQL-fájlt (327 fejezet, 109×3 nyelv)
3. Letölti a Yii2 keretrendszert közvetlenül GitHub-ról (Composer/Packagist nélkül is működik)
4. Beköti a valódi `HelpController`/modellek/nézetek kódját — **változtatás nélkül**
5. Elindítja a szervert a `http://127.0.0.1:8080/help` címen

**Ezt a pontos szkriptet kétszer lefuttattam teljesen tiszta állapotból, és mindkétszer sikerült**: a böngészőben megjelent a "Kintlévőség kezelés" fejezet, 4/4 valódi képpel, mind a 109 fejezettel a bal oldali fában, helyes CSS-sel, mindhárom nyelven (HU/EN/DE) helyesen váltva.

Előfeltétel: PHP 8.1+ (`mbstring`, `pgsql` kiterjesztéssel), PostgreSQL, git:
```bash
sudo apt-get install -y php-cli php-mbstring php-pgsql postgresql git
```

**Fontos**: ez a szkript **kizárólag a teszteléshez** való — nem ez a módszer az éles Infinity-be való beépítésre. Ott már fut a Yii2 (Composer-rel telepítve), oda az `01_infinity_fo_rendszerbe/` fájljait egyszerűen bemásoljátok (lásd lent).

---

## 1. `01_infinity_fo_rendszerbe/` — a súgó az Infinity-ben

Ez teszi lehetővé, hogy a súgó éljen az Infinity-n belül: a `?` gomb, a szerkesztő, a közzététel.

```
sql/                 adatbázis séma + a teljes tartalom mind a 3 nyelven
yii2/                 18 PHP fájl: modellek, controller, widget, migráció, CSS, JS
scripts/              Word/PDF export, fordítási eszközök
adatok/                a nyers tartalom JSON-ban (ha külön kellene valakinek)
dokumentacio/          kiegészítő olvasnivaló
```

### Telepítés

```bash
psql -f sql/help_articles_i18n.sql
psql -f sql/002_szerkeszto_migracio.sql
psql -f sql/003_changelog_migracio.sql
```

Majd a `yii2/` tartalmát a megfelelő helyekre:

```bash
cp -r yii2/models/*      models/
cp -r yii2/controllers/* controllers/
cp -r yii2/components/*  components/
cp -r yii2/widgets/*     widgets/
cp -r yii2/assets/*      assets/
cp -r yii2/views/help    views/
cp -r yii2/web/css/*     web/css/
cp -r yii2/web/js/*      web/js/
```

A képeket (a csomag gyökerében lévő `media/` mappából, 275 db) a `web/help/media/` alá kell másolni, és a `body_html` mezőkben a `media/` hivatkozásokat erre az útvonalra átírni:

```bash
psql -c "UPDATE help_article SET body_html = replace(body_html,'src=\"media/','src=\"/help/media/');"
```

*(Ezt a pontos lépést a `03_helyi_teszt/helyi_teszt_inditasa.sh` script is elvégzi és leteszteltem, hogy tényleg megfelelően működik.)*

### A lényeg egy mondatban

Az adatbázis a tartalom forrása. A szerkesztő **vázlatot** ír, a közzététel **élesíti**, a Word/PDF ebből **generált kimenet** — nem szerkesztendő dokumentum többé.

```
szerkesztő ──save-draft──▶ draft_html
           ──publish─────▶ help_publish() ──▶ body_html ──▶ amit a felhasználó lát
```

### Nyelvek

A `help_article` táblában minden fejezet **három sorként** létezik (`UNIQUE (slug, lang)`), a slug mindhárom nyelvben azonos:

```php
$lang = substr(Yii::$app->language, 0, 2);   // hu / en / de
HelpArticle::publicQuery($lang)->andWhere(['slug' => $slug])->one();
```

*(Ezt a helyi tesztben ellenőriztem is: `?__lang=en` és `?__lang=de` paraméterrel valóban a megfelelő nyelvű tartalom jött vissza.)*

### A `?` gomb

```php
<?= \app\widgets\HelpTrigger::widget() ?>
```

A route alapján a `help_screen_map` táblából keresi ki, melyik fejezetet nyissa meg. Ezt a hozzárendelést (route → fejezet) egyszer kell feltölteni — kb. 60-80 sor, együtt csináljátok a doksi-felelőssel.

### Amit már nem kell megírni

A korábbi öt hiányzó darab (olvasó nézet, modul/fejezet CRUD, fordítói nézet, export action, toast-integráció) mind elkészült, **és most már egy egységes admin felületen keresztül is elérhetők** — nem szétszórt route-okon:

```
/help/admin
```

Belépsz, bal oldalt a teljes fejezetlista modulonként, felül fülek (**Szerkesztő / Modulok / Képernyők / Fordítás / Export**). Fejezetre kattintva AJAX-szal töltődik be a tartalom — nincs teljes oldal-újratöltés.

| # | Amit korábban meg kellett volna írni | Most |
|---|---|---|
| 1 | `views/help/index.php` — olvasó nézet, kereső | ✅ Megírva, **böngészőben tesztelve** |
| 2 | `actionMap`, `actionCreate`, `actionReorder`, modul-CRUD | ✅ Megírva, **a Képernyők-mentés valóban íródik az adatbázisba** |
| 3 | Fordítói nézet (kétoszlopos) | ✅ Megírva, **az admin-vázon belül is megnyílik AJAX-szal** |
| 4 | Export action | ✅ Megírva, **valós Word-fájlt generál élő adatbázisból, PDF-re konvertálva 14 oldal, ellenőrizve** |
| 5 | Toast-értesítés | ✅ Már eleve helyesen implementálva volt |
| 6 | **Egységes admin felület** (`/help/admin`) | ✅ Most épült, **teljes körűen tesztelve** |

### Egy fontos építészeti javítás az export körül

Az `export_docx.js` script eredetileg egy **statikus JSON-pillanatképből** dolgozott — ha közvetlenül hívtuk volna az élő admin felületről, a régi, korábban exportált tartalmat adta volna vissza, nem a frissen szerkesztett/közzétett verziót. Ezt kijavítottam: az `actionExport()` most **minden exportnál frissen kiírja az aktuális adatbázis-állapotot** ugyanabba a JSON-formátumba, és csak utána hívja a Node.js szkriptet. Ez azt jelenti, hogy a Word/PDF export mindig a legfrissebb közzétett tartalmat tükrözi.

**Ehhez két Node.js csomag szükséges a szerveren** (a `scripts/` mappában futtatva):
```bash
npm install docx node-html-parser
```
*(Ezt is valósan leteszteltem — enélkül `Cannot find module` hibát dob.)*

### Amit menet közben javítottam (valódi hibák, a helyi tesztelés közben derültek ki)

1. **Nyelv hardkódolva volt** `'hu'`-ra a controller három helyén — javítva, most `Yii::$app->language`-ből dolgozik.
2. **URL-szabály ütközés**: a fejezet-slugok (pl. `5-4-kintlevoseg-kezeles`) összeakadtak volna a névre szóló route-okkal. Javítva.
3. **Az export statikus JSON-ból dolgozott volna**, nem élő adatból — javítva (lásd fent).

### Két apró beállítás telepítéskor

1. **Az export működéséhez Node.js kell a szerveren**, és az `export_docx.js` scriptnek egy `@app/help-scripts` alias alá kell kerülnie.
2. **A jogosultsági rendszerben** hozzá kell adni az új `help.translate` permission-t a meglévő `help.view/edit/publish/manage/media` mellé — ez a ti RBAC admin felületeteken egy sor, nem SQL-lépés.

---

## 2. `02_help_infinityhu_eu_domain/` — önálló domain

**Két lehetőség van, válassz** — mindkettő valósan tesztelve:

| | `site_kod/` (statikus) | `site_dinamikus/` (PHP + saját DB) |
|---|---|---|
| Frissítés | Újra kell generálni + feltölteni | **Azonnal látszik**, nincs build-lépés |
| Szerver igénye | Semmi (csak fájlkiszolgálás) | PHP + a külön súgó-adatbázis |
| Ellenálló képesség | Akkor is működik, ha az Infinity áll | Ugyanaz — **saját, külön** adatbázisa van |
| Ajánlott, ha | Nagyon egyszerű kiszolgálás kell | Kényelmesebb karbantartás számít |

**A `site_dinamikus/` az ajánlott** — egyetlen `index.php` fájl, nincs Yii2, nincs Composer, csak PHP + PDO. Valósan leteszteltem saját, tiszta PostgreSQL adatbázison: kezdőlap, konkrét fejezet, keresés (ékezet-független, kiemelt találatokkal), beágyazott nézet, mindhárom nyelv — mind hibátlan.

### Telepítés (dinamikus verzió)

```bash
psql -d help_infinityhu -f ../01_infinity_fo_rendszerbe/sql/help_articles_i18n.sql
psql -d help_infinityhu -c "UPDATE help_article SET body_html=replace(body_html,'src=\"media/','src=\"/media/');"
```

Töltsd ki a `site_dinamikus/config.php`-t (vagy add meg környezeti változóként: `HELP_DB_DSN`, `HELP_DB_USER`, `HELP_DB_PASS`), majd told fel a `deploy/help.infinityhu.eu.dinamikus.nginx.conf` konfigot (PHP-FPM kell hozzá).

### Telepítés (statikus verzió, ha mégis azt választod)

A `site_kod/` tartalmát másold a webroot alá, egészítsd ki a csomag gyökerében lévő `media/` mappával, és told fel a `deploy/help.infinityhu.eu.nginx.conf` vhost-konfigot.

### A `?` gomb, beágyazva

`deploy/help-button.js` — az Infinity JS fájljai közé kerül. Két mód:

```js
window.InfinityHelp.open("5-4-kintlevoseg-kezeles");          // beágyazott fiók (iframe)
window.InfinityHelp.open("5-4-kintlevoseg-kezeles", "full");  // új fülön
```

### Nyelv Infinity-példányonként

Minden Infinity-példány egynyelvű, ezért a nyelv-beállítás egyszeri, telepítéskori döntés. A `deploy/instance-config/` három kész fájlt tartalmaz (`infinity-help-config.hu/en/de.js`) — az adott Infinity-példányba csak azt az egyet kell betenni.

### Frissítés

```bash
python3 build_site.py --out site --base "https://help.infinityhu.eu"
rsync -a --delete site/ szerver:/var/www/help.infinityhu.eu/site/
```

---

## Ami valóban ellenőrizve van — és ami nem

**Ellenőrizve, ténylegesen futtatva:**
- Mind a PHP fájl szintaxis-hibátlan (`php -l`, PHP 8.3)
- Mind a három SQL-fájl hiba nélkül lefut valódi PostgreSQL 16-on (327 fejezet, mindkét `help_publish()` verzió, a keresőfüggvény)
- **A teljes admin rendszer valóban működik**: `/help/admin` egységes felület, fejezetlista, AJAX-alapú fülváltás, szerkesztő betöltése — screenshot és HTTP-válasz igazolja
- **A Képernyők-mentés ténylegesen ír az adatbázisba** — leellenőriztem közvetlen SQL-lekérdezéssel is
- **Az Export ténylegesen generál valós Word-fájlt élő adatbázisból** — 1,4 MB, PDF-re konvertálva 14 oldal, a tartalom (partnernevek, összegek, "Egyenlegközlő") valóban benne van
- **A dinamikus PHP site (`site_dinamikus/`) teljes körűen működik**: saját, külön adatbázisból, kezdőlap + fejezet + keresés + beágyazott nézet + mindhárom nyelv
- Az nginx konfig szintaxisa (mindkét verzióhoz) és a négy útvonal-típus fejléce
- A nyelvváltás (HU/EN/DE) helyesen működik éles lekérdezéssel tesztelve

**Amit nem tudtam ellenőrizni:**
- A ti pontos Infinity-kódotokat (jogosultsági rendszer, meglévő `config/web.php`, mappastruktúra) — ezt csak a ti szerveretek ismeri. A `03_helyi_teszt/` pontosan azért van, hogy ezt a kockázatot minimalizáljam.
- Az export naprakészen tartja a tartalmat, de ha a `help_section.html` mezőt is külön karbantartjátok (nem csak a cikk teljes `body_html`-jét), azt érdemes egyszer leellenőrizni — ebben a sémában a szakasz-tartalom a cikk fő `body_html` mezőjében van beágyazva, nem külön.

---

## Ha kellenek a képek külön is

A `media/` mappa a csomag gyökerében van, mindkét feladathoz (1. és 2. rész) ugyanonnan másolható.
