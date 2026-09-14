# help.infinityhu.eu — Infinity Súgó

A súgó nyilvános oldala és szerkesztői felülete: **109 fejezet, 3 nyelven (HU/EN/DE), 275 valódi képernyőképpel**.

Ez a repó az `infinity_sugo_KOMPLETT` csomag **2. és 3. részét** tartalmazza (az önálló
domain + a helyi teszt), kiegészítve egy **admin felülettel**, egy újraépített
**olvasói felülettel** és egy kész **Docker** környezettel.

> Az `01_infinity_fo_rendszerbe/` rész (a Yii2 beépülő modul, ami magába az Infinity-be
> kerül) **szándékosan nincs** ebben a repóban — az SQL sémán kívül, ami nélkül nem lenne
> miből felépíteni az adatbázist. Lásd [`db/`](db/).

---

## Gyors indítás

```bash
docker compose up -d --build
```

| | |
|---|---|
| **Súgó** | http://localhost:8080/ |
| **Admin** | http://localhost:8080/admin.php — `admin` / `12345678` |
| PostgreSQL | `localhost:5433`, db `help_infinityhu`, user `help` / `help` |

Az első indításkor a PostgreSQL automatikusan lefuttatja a [`db/`](db/) alatti SQL-fájlokat
(327 cikk = 109 fejezet × 3 nyelv, 663 szakasz, 51 modul-sor). Ez kb. 20–30 másodperc;
addig a webes felület még `503`-at adhat.

A jelszócsere **nem kötelező**: belépés után a rendszer egyszer emlékeztet rá, cserélni pedig a
**Beállítások → Saját jelszó** résznél lehet, amikor jónak látod. Ha elrontanád a belépést:
5 sikertelen próbálkozás után a fiók 10 percre zárolódik.

```bash
docker compose down          # csak leállít
docker compose down -v       # az adatbázis kötetét is törli -> újraindításkor újratöltődik
```

Az alapértelmezett portok és jelszavak felülírhatók: másold a `.env.example` fájlt `.env` néven.

---

## Az olvasói felület

SAP Fiori / Microsoft Dynamics 365 stílusú felület: sötét fejlécsáv, bal oldali
fejezetfa, kártyás tartalom, jobb oldalt oldalon belüli tartalomjegyzék.

| | |
|---|---|
| **Képnagyítás** | a képekre kattintva teljes képernyős nagyító nyílik — görgővel/`+`/`−` zoomolható, nagyítva húzható, `←` `→` lapoz a fejezet képei között, `Esc` zár |
| **Betűméret** | a fejlécben a ⚙-gomb alatt: csúszka 85%–160% között, `+` / `−` / `0` billentyűvel is; a böngészőben megmarad |
| **Sötét mód** | világos / sötét / rendszer szerinti; a fejléc holdgombjával vagy `D` billentyűvel |
| **Keresés** | `Ctrl+K` vagy `/`, ékezet-független, nyilakkal járható, kiemelt találatokkal |
| **Navigáció** | modulonként nyitható-zárható fa, szűrőmezővel; az állapot megmarad |
| **Mi újság** | a fejlécben jelvény mutatja, hány fejezet új vagy frissült; a fejezetfában zöld pont, a fejezet fejlécében „új” / „frissítve” címke, és külön **Mi újság** lap az összes friss változással |
| **Videó** | a fejezetekbe videó is kerülhet, a böngésző saját lejátszójával (tekerhető, teljes képernyős) |
| **Egyéb** | olvasási csík, aktív szakasz követése, címsor-horgonyok másolása, előző/következő fejezet, nyomtatási nézet, mobilnézet |

Útvonalak:

| | |
|---|---|
| `/` | nyelv-észlelés, átirányítás `/hu/`, `/en/` vagy `/de/` alá |
| `/hu/` | kezdőlap, modul-csempékkel |
| `/hu/5-4-kintlevoseg-kezeles` | konkrét fejezet |
| `/hu/embed/5-4-kintlevoseg-kezeles` | beágyazható (iframe) változat |
| `/hu/mi-ujsag` · `/en/whats-new` · `/de/neuigkeiten` | Mi újság — a friss változások |
| `/search?lang=hu&q=szamla` | JSON keresés |

Az admin felületre **szándékosan nem vezet link a súgóból** — a `/admin.php` címet
ismerni kell hozzá.

---

## Az admin felület — `/admin.php`

Belépés után a felső füleken érhető el minden. Három szerepkör van: **admin** (mindent),
**editor** (fejezetek, modulok, import, fordítás, képernyők, képek), **translator** (csak a Fordítás fül).

### Áttekintés
Hány fejezet van, hány vázlat vár közzétételre, hány fordítás hiányzik vagy avult el,
mi történt legutóbb (napló), melyik kiadás van nyitva.

### Fejezetek
Bal oldalt a teljes fejezetlista nyelvenként, szűrővel. Jobb oldalt a szerkesztő:

- **WYSIWYG szerkesztő** (félkövér, dőlt, címsorok, listák, hivatkozás, kép, Tipp/Figyelem dobozok)
  és **HTML forrás nézet** — a kettő között bármikor lehet váltani. `Ctrl+S` ment.
- **Vázlat → közzététel**: amit mentesz, az *vázlat*, a nyilvános oldalon még a régi látszik.
  A közzététel élesíti, és eltesz egy visszaállítható verziót.
- **Ki-/bekapcsolás nyelvenként**: a fejezet fölött külön kártya mutatja a magyar, angol és német
  változatot, mindegyiket külön lehet láthatóvá tenni vagy elrejteni — és egy gombbal mind a hármat
  egyszerre. Ha egy fejezet még nincs kész egy nyelven, csak azt a nyelvet kapcsold ki; a tartalom
  és a vázlat megmarad. **Alapból minden be van kapcsolva.** A bal oldali listában szürke pont jelzi
  a kikapcsolt, sárga a vázlattal rendelkező fejezeteket.
- **Kép és videó feltöltése közvetlenül a szerkesztőből**: a **Kép** / **Videó** gomb, a fájl
  ráhúzása a szövegre, vagy vágólapról beillesztett képernyőkép — mind azonnal feltölt és beszúr.
  Nem kell előre a Képek fülre menni.
- **Tömeges műveletek**: a ☑ gombbal több fejezet jelölhető ki (Shift-kattintással tartomány,
  a modul fejlécével az egész csoport), majd egyszerre kapcsolható be/ki, tehető közzé,
  helyezhető át másik modulba vagy törölhető.
- **Sorrend húzással**: az ↕ gombbal megjelenik a ⠿ fogantyú, és a fejezetek húzással
  átrendezhetők — másik modul alá is. A mentés automatikus.
- **Adatok**: fejezetszám, cím, URL-azonosító, modul, sorrend, jogosultság.
- **Korábbi változatok**: minden közzététel előtti állapot megmarad, egy kattintással visszatölthető vázlatként.
- **Visszavonás**: a közzététel után megjelenő „↩ Közzététel visszavonása" gombbal a nyilvános
  oldal azonnal visszaáll az előző változatra — a visszavont szöveg vázlatként megmarad.
  A törölt fejezet a **Kukába** kerül, onnan teljes tartalmával (szakaszok, verziótörténet,
  képernyő-hozzárendelések) visszaállítható.
- Új fejezet létrehozása, fejezet törlése.

A beküldött HTML fehérlistás tisztításon megy át (`<script>`, `on*` eseménykezelő,
`javascript:` hivatkozás nem maradhat benne), és a címsorok automatikusan horgonyt kapnak,
amiből az olvasói oldal tartalomjegyzéke épül.

### Modulok
A felső szint: szám, név, URL-azonosító, sorrend — nyelvenként. A sorrend a ⠿ fogantyúval
húzva is átrendezhető. Üres modul törölhető (a Kukába kerül).

### Billentyűzet
| | |
|---|---|
| `Ctrl+K` | **Parancspaletta**: fejezetek és fülek egyben, nyilakkal járható |
| `/` | a bal oldali fejezetszűrő |
| `↑` `↓` | lépkedés a fejezetlistán, `Enter` megnyitás |
| `Ctrl+S` | vázlat mentése a szerkesztőben |
| `A` `M` `I` `T` `K` `E` `U` `B` `D` | ugrás a fülekre (Fejezetek, Modulok, Import, Fordítás, Képek, Export, Felhasználók, Beállítások, Áttekintés) |
| `?` | a billentyűparancsok listája |
| `Esc` | ablak bezárása |

A táblázatok telefonon **kártyákká alakulnak**, minden mező a saját címkéjével.

### Word import
Feltöltesz egy **.docx**-et, és a rendszer:

1. fejezetekre bontja (**Címsor 1** = modul, **Címsor 2** = fejezet, **Címsor 3–4** = szakasz).
   A fejezetszámot a címből olvassa ki („5.4 Kintlévőség kezelés”), **de ha a Word automatikus
   címsor-számozását használod** — és a cím szövegében nincs szám —, akkor a címsorhierarchiából
   számolja ki (a valódi útmutató pont ilyen). Ha egy modulnak magának is van szövege
   (pl. „2 A keretrendszer”), abból a modul száma alatt lesz fejezet;
2. a formázást (félkövér, dőlt, listák, táblázatok, hivatkozások) HTML-re fordítja,
3. a képeket kibontja és a tartalmuk hash-éről nevezi el (`img_<hash>.png`) — ugyanaz a kép nem duplikálódik,
4. a fejezetszám alapján **párosítja a meglévő fejezethez**, és **szó szintű összehasonlítást**
   mutat: mi került bele, mi maradt ki, hány százalék az egyezés,
5. fejezetenként te döntesz, mit vesz át.

Az átvett tartalom **vázlat** lesz — közzétenni külön kell (vagy egy pipával rögtön az átvételkor).
Az új fejezetek kikapcsolt állapotban jönnek létre, amíg közzé nem teszed őket.
Ha a **Beállítások** fülön be van kapcsolva az automatikus fordítás, a frissen átvett magyar
fejezetekből rögtön elkészül az angol és német vázlat is.
Nem használ külső könyvtárat, csak a PHP `zip` és `dom` kiterjesztését.

### Fordítás
A magyar a forrásnyelv. A listában látszik fejezetenként, hogy a cél nyelv **hiányzik**,
**elavult** (a magyar azóta változott), **vázlat** vagy **naprakész**. Megnyitva két hasáb:
bal oldalt a magyar eredeti, jobb oldalt a szerkeszthető fordítás.

**Gépi nyersfordítás** is kérhető, ha be van állítva szolgáltató (DeepL, LibreTranslate vagy
Google Translate) — a szöveg HTML-ként megy át, így a formázás és a képek a helyükön maradnak.
Beállítani a **Beállítások** fülön vagy környezeti változóval lehet
(`HELP_MT_PROVIDER`, `HELP_MT_ENDPOINT`, `HELP_MT_KEY`). **Szolgáltató nélkül is használható**
a fül, csak a nyersfordítás gomb marad inaktív.

**Fizetős kulcs nélkül is megoldható.** A csomagban van egy saját, helyben futó fordító:

```bash
docker compose --profile mt up -d      # LibreTranslate, hu/en/de modellekkel
```

Utána a **Beállítások** fülön: Szolgáltató = *LibreTranslate*, Végpont = `http://libretranslate:5000`.
Az első indulás pár perc (letölti a nyelvi modelleket, ~1–2 GB), utána minden helyben fut,
nem megy ki adat a hálózatra. Ez nem indul el a szokásos `docker compose up`-pal.

**Automatikus fordítás**: a Beállítások fülön bekapcsolható, hogy minden új vagy Word-ből
importált magyar fejezetből rögtön készüljön angol és német **vázlat**. A gépi fordítás mindig
csak vázlatot készít — közzétenni ember dönt. A Fordítás fülön az `EN + DE egyben` gombbal
egy fejezetre kézzel is elindítható.

### Képernyők
Útvonal → fejezet hozzárendelés: ez mondja meg, az Infinity melyik képernyőjén a `?` gomb
melyik fejezetet nyissa meg.

### Képek, videók
A fájltár áttekintése, tömeges feltöltés, szűrés kép/videó szerint, törlés (csak olyan fájl
törölhető, amire egyetlen fejezet sem hivatkozik). **Szerkesztés közben nem kell ide jönni** —
a szerkesztőből közvetlenül tölthetsz fel. A fájlnév a tartalom hash-e, ezért ugyanaz a fájl
csak egyszer kerül a szerverre.

| | formátumok | méret |
|---|---|---|
| kép | PNG, JPG, GIF, WebP, SVG | max 25 MB |
| videó | MP4 (H.264), WebM, MOV | max 400 MB |

A videókat a böngésző saját lejátszója játssza le, tekeréssel (a szerver `Range` kéréseket is
kiszolgál). A Word-exportba a videó nem kerül bele — ott hivatkozás marad a helyén.

### Export
A teljes használati útmutató letölthető **bármelyik nyelven**, **tartalomjegyzékkel**:

- **Word (.docx)** — címlap, valódi Word-tartalomjegyzék (`TOC` mező, a Word felajánlja a
  frissítését, vagy `F9`), modulonként új oldal, beágyazott képek, táblázatok, Tipp/Figyelem dobozok.
- **Nyomtatható HTML** — ebből a böngésző *Nyomtatás → Mentés PDF-ként* funkciójával lesz PDF,
  külön eszköz nélkül.

Egy pipával a még nem közzétett (kikapcsolt) fejezetek is belevehetők — belső átnézésre hasznos.
Az export **mindig a friss adatbázis-tartalomból** dolgozik, nem egy korábbi pillanatképből.

### Felhasználók
Létrehozás, szerepkör, aktiválás/inaktiválás, jelszó beállítása. Új felhasználónak és
jelszó-visszaállítás után az első belépéskor kötelező jelszót cserélnie.

### Kuka
A törölt fejezetek és modulok teljes tartalma. Visszaállítás egy kattintással; véglegesen csak
innen törlődnek. A tömeges áthelyezés visszavonása is itt található.

### Beállítások
Saját jelszó cseréje, oldalcímek nyelvenként, gépi fordító, és a **kiadások** kezelése: a közzétételkor megadott
összefoglalók a nyitott kiadásba gyűlnek, a lezárás ad nekik dátumot és verziószámot.

---

## Mi van a repóban

```
db/                   adatbázis: séma + a teljes tartalom mind a 3 nyelven (init SQL)
site_dinamikus/       AJÁNLOTT: PHP + PDO, adatbázisból olvas
  index.php             az olvasói felület
  admin.php             a szerkesztői / adminisztrációs felület
  lib/                  segédkönyvtárak (docx, diff, fordítás, auth, HTML-tisztítás)
  assets/               app.css / app.js (olvasó), admin.css / admin.js (admin)
site_kod/             statikus, előre generált HTML változat (build_site.py készíti)
build_site.py         a statikus változat generálása
deploy/               éles nginx konfigok, a "?" gomb JS-e, példány-konfigok
media/                275 valódi képernyőkép
helyi_teszt/          a csomag eredeti, Yii2-alapú teszt-szkriptje (lásd lent)
docker-compose.yml    PostgreSQL 16 + PHP 8.3/Apache
docker/php/           a PHP image és az Apache vhost
```

### Statikus vagy dinamikus?

| | `site_kod/` (statikus) | `site_dinamikus/` (PHP + saját DB) |
|---|---|---|
| Frissítés | újra kell generálni és feltölteni | **azonnal látszik**, nincs build-lépés |
| Szerkesztő | nincs | **van** (`/admin.php`) |
| Szerver igénye | semmi (csak fájlkiszolgálás) | PHP + a külön súgó-adatbázis |
| Ha az Infinity áll | működik | működik — **saját, külön** adatbázisa van |

A **dinamikus** az ajánlott, és a Docker környezet is ezt futtatja.

---

## `db/` — az adatbázis

A fájlok ábécé-sorrendben futnak le (így teszi a postgres image is):

| fájl | mi ez |
|---|---|
| `01_help_articles_i18n.sql` | séma (`help_module`, `help_article`, `help_section`, `help_screen_map`, `help_changelog`, `help_feedback`) + a teljes tartalom 3 nyelven |
| `02_szerkeszto_migracio.sql` | szerkesztő: `draft_html`, revíziók, média-tábla, `help_publish()` |
| `03_changelog_migracio.sql` | kiadás-kezelés: `help_release`, bővített `help_publish()`, `help_news` nézet |
| `04_erp_norm_fix.sql` | **javítás** — lásd lent |
| `05_admin_felulet.sql` | az admin felület táblái (`help_user`, `help_setting`, `help_import`, `help_audit`), a fordítási állapot mezői, és **két hibajavítás** — lásd lent |
| `06_video_export_ujdonsag.sql` | videó a képek mellé (`help_media.kind`), újdonság-kiemelés (`help_article.highlight_until`, `help_whatsnew` nézet), automatikus fordítás kapcsolója |
| `07_visszavonas.sql` | Kuka (`help_trash`) a visszaállítható törléshez, `help_unpublish()` a közzététel visszavonásához |
| `99_docker_roles.sql` | a `help_ro` (olvasó) és `help_rw` (író) szerepkör, fejlesztői jelszóval |

Az első három fájl az eredeti csomag `01_infinity_fo_rendszerbe/sql/` mappájából származik,
változtatás nélkül — ugyanezek kellenek az éles Infinity-be is.

### `04_erp_norm_fix.sql` — mit javít

Az eredeti séma így definiálja a normalizáló függvényt:

```sql
SELECT lower(unaccent(coalesce(txt, '')));
```

Az `unaccent` itt nincs séma-minősítve. Mivel az `erp_norm(title)` **kifejezés-indexben**
is szerepel (`help_article_trgm_idx`, `help_section_trgm_idx`), az autovacuum/ANALYZE
munkafolyamat — ami üres `search_path`-tal fut — nem találja meg:

```
ERROR: function unaccent(text) does not exist
```

Ez a Docker indításkor valóban jelentkezett a naplóban. A javítás séma-minősíti, és a
kétargumentumos, valóban `IMMUTABLE` `unaccent(regdictionary, text)` formát használja.

### `05_admin_felulet.sql` — a két hibajavítás benne

1. **A közzététel összefoglalóval elhasalt volna.** A 003 migráció `help_publish()`
   függvénye `NULL`-t ír a `help_changelog.released_at` és `.doc_version` mezőkbe
   (helyesen — ezeket a kiadás lezárása tölti ki), csakhogy a 001-ben ezek `NOT NULL`-ok.
   Minden összefoglalóval történő közzététel *"null value in column released_at violates
   not-null constraint"* hibával állt volna meg. A migráció feloldja a megszorítást.
2. **A kereshető szöveg HTML-entitásokat tartalmazott.** A közzététel a `plain_text`-et
   `regexp_replace(draft_html, '<[^>]+>', ' ')` mintával állította elő, ami a `&nbsp;`,
   `&amp;` és társait benne hagyta. Egy `help_plain()` segédfüggvény ezt rendbe teszi,
   és a `help_publish()` mostantól ezt használja.

**Mindkét javítás az éles Infinity adatbázisban is érvényes.**

### Képek útvonala

Az adatbázis szándékosan úgy maradt, ahogy a csomagban volt: a `body_html` mezőkben
`src="media/..."` szerepel. Az `index.php` futásidőben írja át `/media/`-ra — így **nem kell**
`UPDATE`-tel hozzányúlni a tartalomhoz, és ugyanez a dump változtatás nélkül betölthető az
Infinity oldalára is (ahol `/help/media/` az útvonal).

---

## Éles telepítés

```bash
psql -d help_infinityhu -f db/01_help_articles_i18n.sql
psql -d help_infinityhu -f db/02_szerkeszto_migracio.sql
psql -d help_infinityhu -f db/03_changelog_migracio.sql
psql -d help_infinityhu -f db/04_erp_norm_fix.sql
psql -d help_infinityhu -f db/05_admin_felulet.sql
psql -d help_infinityhu -f db/06_video_export_ujdonsag.sql
psql -d help_infinityhu -f db/07_visszavonas.sql

# a szerepkoroket SAJAT jelszoval hozd letre, ne a 99-es fajllal:
psql -d help_infinityhu -c "CREATE ROLE help_ro LOGIN PASSWORD '...';"
psql -d help_infinityhu -c "CREATE ROLE help_rw LOGIN PASSWORD '...';"
psql -d help_infinityhu -c "GRANT USAGE ON SCHEMA public TO help_ro, help_rw;"
psql -d help_infinityhu -c "GRANT SELECT ON ALL TABLES IN SCHEMA public TO help_ro;"
psql -d help_infinityhu -c "GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO help_rw;"
psql -d help_infinityhu -c "GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO help_rw;"
```

Majd a `site_dinamikus/` tartalmát a webroot alá, a `media/` mappát mellé, és a
`deploy/help.infinityhu.eu.dinamikus.nginx.conf` vhost-ot az nginx alá (PHP-FPM kell hozzá).

Környezeti változók (a jelszavakat **ne** a `config.php`-ba írd):

| változó | mire |
|---|---|
| `HELP_DB_DSN` | az adatbázis DSN-je |
| `HELP_DB_USER` / `HELP_DB_PASS` | az **olvasó** felhasználó (nyilvános oldal) |
| `HELP_DB_ADMIN_USER` / `HELP_DB_ADMIN_PASS` | az **író** felhasználó (admin felület) |
| `HELP_MEDIA_DIR` | a képek mappája a lemezen (írhatónak kell lennie a Word-importhoz) |
| `HELP_MT_PROVIDER` / `HELP_MT_ENDPOINT` / `HELP_MT_KEY` | gépi fordító (nem kötelező) |

Az éles vhost tiltsa a `lib/` mappát és a `config.php`-t — a Dockerben lévő Apache-konfig
(`docker/php/help.conf`) megmutatja, hogyan.

**Az `/admin.php` HTTPS mögé való.** A munkamenet-süti `Secure` jelzőt csak HTTPS-en kap.

---

## `deploy/` — a „?" gomb és a példány-konfigok

```js
window.InfinityHelp.open("5-4-kintlevoseg-kezeles");          // beágyazott fiók (iframe)
window.InfinityHelp.open("5-4-kintlevoseg-kezeles", "full");  // új fülön
```

Minden Infinity-példány egynyelvű, ezért a nyelv telepítéskori döntés: a
`deploy/instance-config/` három kész fájlt tartalmaz (`infinity-help-config.hu/en/de.js`).

## A statikus változat frissítése

```bash
python3 build_site.py --out site --base "https://help.infinityhu.eu"
rsync -a --delete site/ szerver:/var/www/help.infinityhu.eu/site/
```

## `helyi_teszt/`

Az eredeti csomag Yii2-alapú teszt-szkriptje, referenciaként.

⚠️ **Ez a szkript önmagában itt nem fut le**: az `01_infinity_fo_rendszerbe/` mappából
másolja be a controllert, a modelleket és a nézeteket, az pedig nincs ebben a repóban.
A domain-oldal teszteléséhez nincs rá szükség — arra a `docker compose up` való.

---

## Logó

A fejlécben, a bejelentkezési oldalon és az adminban ugyanaz a logó jelenik meg.
A `site_dinamikus/lib/util.php` `help_logo()` függvénye **elsőként az
`site_dinamikus/assets/logo.svg` / `.png` / `.webp` / `.jpg` fájlt keresi** — ha ott van,
azt használja; ha nincs, egy beágyazott kék végtelen-jelet rajzol, hogy soha ne legyen
törött kép. A végleges márkajelet tehát elég bemásolni ebbe a mappába, kódot nem kell hozzányúlni.

---

## Mi van leellenőrizve

Az alábbiakat ténylegesen lefuttatva ellenőriztük a Docker környezetben, nem csak feltételezés:

**Adatbázis és olvasói oldal**
- az adatbázis nulláról hiba nélkül felépül mind a hat SQL-fájlból: **327 cikk**, 663 szakasz, 51 modul-sor
- kezdőlap, fejezet-oldal, beágyazott nézet, 404 — mind a várt válasszal
- a képek `/media/` alól, HTTP 200; a `lib/` és a `config.php` közvetlenül **nem kérhető le** (403)
- keresés ékezet-függetlenül: `?q=szamla` → „Számlás értékesítés", kiemelt találattal
- nyelvváltás: „Kintlévőség kezelés" / „Receivables management" / „Forderungsmanagement"
- sötét mód, betűméret-állítás, képnagyító — böngészőben megnézve

**Admin**
- belépés `admin` / `12345678` → **kényszerített jelszócsere**; gyenge jelszó elutasítva
- rossz jelszó elutasítva, a próbálkozások számolódnak
- vázlat mentése → a beküldött `<script>` és `javascript:` hivatkozás **kiszűrve**
- közzététel → verzió elmentve, szakaszok újraépítve, változásnapló-bejegyzés létrejött,
  és a nyilvános oldalon **azonnal az új tartalom** látszik
- korábbi változat visszatöltése → az eredeti tartalom visszaáll
- fejezet ki-/bekapcsolása → a nyilvános oldal `404` / `200`-at ad
- **Word import — a VALÓDI, 27 MB-os útmutatóval (`Infinity hasznalati utmutato 2026_v2.docx`)**:
  a rendszer **110 fejezetet** olvasott ki 0,4 másodperc alatt, és a jelenlegi tartalomhoz mérve
  **90 fejezet szó szerint azonos (100%)**, 19 apróságban tér el (98–99,8% egyezés),
  1 pedig új. A 9 modul-szintű fejezet („2 A keretrendszer”, „5 Pénzügy”, …) mind 100%-on
  párosult. A képeket felismerte, és mivel tartalom-hash a fájlnév, **egyet sem duplikált**.
  Az egyetlen „új” találat a Word-ben lévő **üres `5.1 Beállítások` címsor** — a dokumentumban
  nincs alatta szöveg, ezért a korábbi konverzió kihagyta; az import viszont jelzi, hogy döntsön róla ember.
- mind a tizenegy fül hibamentesen renderel (a PHP naplóban nincs warning)
- **tömeges műveletek**: 2 fejezet egyszerre kikapcsolva → a nyilvános oldal 404-et ad,
  visszakapcsolva 200-at; áthelyezés másik modulba és annak visszavonása is ellenőrizve
- **sorrend húzással**: 6 fejezet átrendezve, a `sort_order` újraszámozva; modulok ugyanígy
- **közzététel visszavonása**: a nyilvános oldalon azonnal visszaállt az előző szöveg,
  a szakaszok újraépültek, a változásnapló-bejegyzés eltűnt, a visszavont szöveg vázlatként megmaradt
- **Kuka**: törölt fejezet visszaállítva a szakaszaival együtt
- **parancspaletta**: `Ctrl+K`, „penz" keresésre 5 találat, első a „5 Pénzügy"
- **mobil nézet** (375 px): a táblázatok kártyákká alakulnak, minden mező a saját címkéjével
- **kép és videó feltöltése a szerkesztőből**: PNG feltöltve és beszúrva, MP4 felismerve
  (`video/mp4`), `vid_<hash>.mp4` néven eltárolva; nem támogatott fájltípus elutasítva
- a videó kiszolgálása `Content-Type: video/mp4` fejléccel és `Range` kérésekkel (HTTP 206),
  tehát a lejátszóban tekerhető
- **újdonság-kiemelés**: közzététel után a fejezet 30 napig „frissítve" jelölést kap, megjelenik
  a Mi újság lapon az összefoglalóval, a fejlécben a számláló 1-re vált, a fejezetfában zöld pont

**Export — valódi dokumentumon ellenőrizve**
- a teljes magyar útmutató Word-exportja: **17 MB**, 1,7 másodperc alatt
- benne **109 fejezet** (Heading2), 17 modul (Heading1), 199 szakasz (Heading3),
  **276 kép beágyazva**, 64 táblázat, valódi `TOC` mező
- a dokumentumot a **macOS saját Word-olvasója (textutil) hiba nélkül megnyitotta** és
  171 KB szöveget olvasott ki belőle — címlap, tartalomjegyzék, címsorok, táblázatok,
  Tipp/Figyelem dobozok, számozott listák mind a helyükön
- az angol nyomtatható HTML-export 109 fejezettel, kattintható tartalomjegyzékkel

**Amit nem tudtunk itt ellenőrizni**
- a gépi fordítás valódi szolgáltatóval (DeepL/LibreTranslate/Google kulcs nélkül) — a
  kódút és a hibakezelés megvan, de éles hívás nem futott
- valódi, kódolt videó lejátszása: a feltöltési út (felismerés, tárolás, beszúrás, kiszolgálás
  `Range`-dzsel) ellenőrizve, de kódolt MP4 nem volt kéznél a gépen
- az Infinity-n belüli Yii2 modul (`/help/admin`, Word/PDF export, RBAC) — az az eredeti
  csomag 1. részében van, és nem tárgya ennek a repónak
