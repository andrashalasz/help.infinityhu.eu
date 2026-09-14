# help.infinityhu.eu — Infinity Súgó, önálló domain

A súgó nyilvános oldala: **109 fejezet, 3 nyelven (HU/EN/DE), 275 valódi képernyőképpel**.

Ez a repó az `infinity_sugo_KOMPLETT` csomag **2. és 3. részét** tartalmazza (az önálló
domain + a helyi teszt), plusz egy kész **Docker** környezetet, amivel egy paranccsal
elindul a teljes súgó saját PostgreSQL adatbázissal.

> Az `01_infinity_fo_rendszerbe/` rész (a Yii2 beépülő modul, ami magába az Infinity-be
> kerül) **szándékosan nincs** ebben a repóban — az SQL sémán kívül, ami nélkül nem lenne
> miből felépíteni az adatbázist. Lásd [`db/`](db/).

---

## Gyors indítás

```bash
docker compose up -d --build
```

Ezután: **http://localhost:8080/**

Az első indításkor a PostgreSQL automatikusan lefuttatja a [`db/`](db/) alatti SQL-fájlokat,
és feltölti az adatbázist (327 cikk = 109 fejezet × 3 nyelv, 663 szakasz, 51 modul-sor).
Ez kb. 20–30 másodperc; addig a webes felület még `503`-at adhat.

Leállítás, illetve teljes törlés (adatbázissal együtt):

```bash
docker compose down          # csak leállít
docker compose down -v       # az adatbázis kötetét is törli -> újraindításkor újratöltődik
```

### Mit kapsz

| | |
|---|---|
| http://localhost:8080/ | nyelv-észlelés, átirányítás `/hu/`, `/en/` vagy `/de/` alá |
| http://localhost:8080/hu/ | kezdőlap, teljes fejezetfával |
| http://localhost:8080/hu/5-4-kintlevoseg-kezeles | konkrét fejezet |
| http://localhost:8080/hu/embed/5-4-kintlevoseg-kezeles | beágyazható (iframe) változat |
| http://localhost:8080/search?lang=hu&q=szamla | JSON keresés (ékezet-független) |
| `localhost:5433` | maga a PostgreSQL (user `help`, jelszó `help`, db `help_infinityhu`) |

Az alapértelmezett portok és jelszavak felülírhatók: másold a `.env.example` fájlt `.env` néven.

```bash
psql -h localhost -p 5433 -U help help_infinityhu
```

---

## Mi van a repóban

```
db/                   adatbázis: séma + a teljes tartalom mind a 3 nyelven (init SQL)
site_dinamikus/       AJÁNLOTT: egyetlen index.php + PDO, adatbázisból olvas
site_kod/             statikus, előre generált HTML változat (build_site.py készíti)
build_site.py         a statikus változat generálása az adatbázisból
deploy/               éles nginx konfigok, a "?" gomb JS-e, példány-konfigok
media/                275 valódi képernyőkép (mindkét változat ugyanezt használja)
helyi_teszt/          a csomag eredeti, Yii2-alapú teszt-szkriptje (lásd lent)
docker-compose.yml    PostgreSQL 16 + PHP 8.3/Apache
docker/php/           a PHP image és az Apache vhost
```

### Statikus vagy dinamikus?

| | `site_kod/` (statikus) | `site_dinamikus/` (PHP + saját DB) |
|---|---|---|
| Frissítés | újra kell generálni és feltölteni | **azonnal látszik**, nincs build-lépés |
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
| `99_docker_readonly_user.sql` | a `help_ro` olvasó szerepkör (csak fejlesztői jelszóval) |

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
kétargumentumos, valóban `IMMUTABLE` `unaccent(regdictionary, text)` formát használja —
ez a Postgres által ajánlott forma, ha az eredmény indexbe kerül.

**Ezt az éles Infinity adatbázisban is érdemes egyszer lefuttatni.**

### Képek útvonala

Az adatbázis szándékosan úgy maradt, ahogy a csomagban volt: a `body_html` mezőkben
`src="media/..."` szerepel. A `site_dinamikus/index.php` a `fixImgUrl()` függvényben
futásidőben írja át `/media/`-ra — így **nem kell** `UPDATE`-tel hozzányúlni a tartalomhoz,
és ugyanez a dump változtatás nélkül betölthető az Infinity oldalára is (ahol viszont
`/help/media/` az útvonal, lásd az eredeti csomag `OLVASD_EL.md`-jét).

---

## `site_dinamikus/` — a futó oldal

Egyetlen `index.php`, nincs Yii2, nincs Composer — csak PHP + PDO.

Az `assets/help.css` és `assets/help-reader-shell.css` az eredeti csomag
`01_infinity_fo_rendszerbe/yii2/web/css/` mappájából került ide: az `index.php` hivatkozik
rájuk, enélkül stílus nélkül jelenne meg az oldal. Ugyanaz a stíluslap, amit az Infinity-n
belüli olvasó nézet is használ — így a két felület egyformán néz ki.

Az adatbázis-kapcsolat a `config.php`-ban van, de **környezeti változóval felülírható**
(`HELP_DB_DSN`, `HELP_DB_USER`, `HELP_DB_PASS`) — a Docker is ezt használja, és éles
szerveren is így add meg, ne írj bele jelszót a fájlba.

### Éles telepítés

```bash
psql -d help_infinityhu -f db/01_help_articles_i18n.sql
psql -d help_infinityhu -f db/02_szerkeszto_migracio.sql
psql -d help_infinityhu -f db/03_changelog_migracio.sql
psql -d help_infinityhu -f db/04_erp_norm_fix.sql
# a help_ro szerepkort SAJAT jelszoval hozd letre, ne a 99-es fajllal:
psql -d help_infinityhu -c "CREATE ROLE help_ro LOGIN PASSWORD '...';"
psql -d help_infinityhu -c "GRANT USAGE ON SCHEMA public TO help_ro;"
psql -d help_infinityhu -c "GRANT SELECT ON ALL TABLES IN SCHEMA public TO help_ro;"
```

Majd a `site_dinamikus/` tartalmát a webroot alá, a `media/` mappát mellé, és a
`deploy/help.infinityhu.eu.dinamikus.nginx.conf` vhost-ot az nginx alá (PHP-FPM kell hozzá).

---

## `deploy/` — a „?" gomb és a példány-konfigok

A `deploy/help-button.js` az Infinity JS-fájljai közé kerül:

```js
window.InfinityHelp.open("5-4-kintlevoseg-kezeles");          // beágyazott fiók (iframe)
window.InfinityHelp.open("5-4-kintlevoseg-kezeles", "full");  // új fülön
```

Minden Infinity-példány egynyelvű, ezért a nyelv telepítéskori döntés: a
`deploy/instance-config/` három kész fájlt tartalmaz (`infinity-help-config.hu/en/de.js`),
példányonként egyet kell betenni.

---

## A statikus változat frissítése

```bash
python3 build_site.py --out site --base "https://help.infinityhu.eu"
rsync -a --delete site/ szerver:/var/www/help.infinityhu.eu/site/
```

---

## `helyi_teszt/`

Az eredeti csomag Yii2-alapú teszt-szkriptje (`helyi_teszt_inditasa.sh`), referenciaként.

⚠️ **Ez a szkript önmagában itt nem fut le**: az `01_infinity_fo_rendszerbe/` mappából
másolja be a controllert, a modelleket és a nézeteket, az pedig nincs ebben a repóban.
Ha a Yii2-oldalt akarod tesztelni, tedd a teljes eredeti csomagot egy mappába, és onnan
futtasd. A domain-oldal (`site_dinamikus/`) teszteléséhez **nincs rá szükség** — arra a
fenti `docker compose up` való.

---

## Mi van leellenőrizve ebben a Docker környezetben

Az alábbiakat ténylegesen lefuttatva ellenőriztük, nem csak feltételezés:

- az adatbázis hiba nélkül felépül: **327 cikk** (109 × 3 nyelv), 663 szakasz, 51 modul-sor
- kezdőlap: mind a 109 fejezet megjelenik a bal oldali fában
- fejezet-oldal: `/hu/5-4-kintlevoseg-kezeles` — a 4 valódi képernyőkép `/media/` alól, HTTP 200
- keresés ékezet-függetlenül: `?q=szamla` → „Számlás értékesítés", kiemelt találattal
- beágyazott nézet (`/hu/embed/...`) → HTTP 200
- nyelvváltás: ugyanaz a slug HU/EN/DE alatt → „Kintlévőség kezelés" / „Receivables
  management" / „Forderungsmanagement"
- CSS és képek közvetlenül, PHP megkerülésével szolgálódnak ki

Amit **nem** fed le ez a repó: az Infinity-n belüli Yii2 modul (`/help/admin`, szerkesztő,
Word/PDF export, RBAC) — az az eredeti csomag 1. részében van.
