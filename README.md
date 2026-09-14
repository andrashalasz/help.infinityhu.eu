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

**Az első belépés után a rendszer kötelezően jelszócserét kér** — a `12345678` csak
kezdőjelszó. Ha elrontanád: 5 sikertelen próbálkozás után a fiók 10 percre zárolódik.

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
| **Egyéb** | olvasási csík, aktív szakasz követése, címsor-horgonyok másolása, előző/következő fejezet, nyomtatási nézet, mobilnézet |

Útvonalak:

| | |
|---|---|
| `/` | nyelv-észlelés, átirányítás `/hu/`, `/en/` vagy `/de/` alá |
| `/hu/` | kezdőlap, modul-csempékkel |
| `/hu/5-4-kintlevoseg-kezeles` | konkrét fejezet |
| `/hu/embed/5-4-kintlevoseg-kezeles` | beágyazható (iframe) változat |
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
- **Fejezet ki-/bekapcsolása** egyetlen gombbal: ha egy fejezet még nincs kész, kapcsold ki —
  eltűnik a nyilvános oldalról, de a tartalma és a vázlata megmarad. A bal oldali listában
  szürke pont jelzi a kikapcsolt, sárga a vázlattal rendelkező fejezeteket.
- **Adatok**: fejezetszám, cím, URL-azonosító, modul, sorrend, jogosultság.
- **Korábbi változatok**: minden közzététel előtti állapot megmarad, egy kattintással visszatölthető vázlatként.
- Új fejezet létrehozása, fejezet törlése.

A beküldött HTML fehérlistás tisztításon megy át (`<script>`, `on*` eseménykezelő,
`javascript:` hivatkozás nem maradhat benne), és a címsorok automatikusan horgonyt kapnak,
amiből az olvasói oldal tartalomjegyzéke épül.

### Modulok
A felső szint: szám, név, URL-azonosító, sorrend — nyelvenként. Üres modul törölhető.

### Word import
Feltöltesz egy **.docx**-et, és a rendszer:

1. fejezetekre bontja (**Címsor 1** = modul, **Címsor 2** = fejezet, **Címsor 3–4** = szakasz),
2. a formázást (félkövér, dőlt, listák, táblázatok, hivatkozások) HTML-re fordítja,
3. a képeket kibontja és a tartalmuk hash-éről nevezi el (`img_<hash>.png`) — ugyanaz a kép nem duplikálódik,
4. a fejezetszám alapján **párosítja a meglévő fejezethez**, és **szó szintű összehasonlítást**
   mutat: mi került bele, mi maradt ki, hány százalék az egyezés,
5. fejezetenként te döntesz, mit vesz át.

Az átvett tartalom **vázlat** lesz — közzétenni külön kell (vagy egy pipával rögtön az átvételkor).
Az új fejezetek kikapcsolt állapotban jönnek létre, amíg közzé nem teszed őket.
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

### Képernyők
Útvonal → fejezet hozzárendelés: ez mondja meg, az Infinity melyik képernyőjén a `?` gomb
melyik fejezetet nyissa meg.

### Képek
A `media/` mappa tartalma, feltöltéssel. A fájlnév itt is a tartalom hash-e.

### Felhasználók
Létrehozás, szerepkör, aktiválás/inaktiválás, jelszó beállítása. Új felhasználónak és
jelszó-visszaállítás után az első belépéskor kötelező jelszót cserélnie.

### Beállítások
Oldalcímek nyelvenként, gépi fordító, és a **kiadások** kezelése: a közzétételkor megadott
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
- **Word import**: valódi .docx-ből 2 fejezet (1 meglévő + 1 új) és 1 kép kibontva,
  a meglévőhöz szó szintű diff (19,7% egyezés), átvétel után vázlat, illetve új,
  kikapcsolt fejezet jött létre
- mind a kilenc fül hibamentesen renderel (a PHP naplóban nincs warning)

**Amit nem tudtunk itt ellenőrizni**
- a gépi fordítás valódi szolgáltatóval (DeepL/LibreTranslate/Google kulcs nélkül) — a
  kódút és a hibakezelés megvan, de éles hívás nem futott
- az Infinity-n belüli Yii2 modul (`/help/admin`, Word/PDF export, RBAC) — az az eredeti
  csomag 1. részében van, és nem tárgya ennek a repónak
