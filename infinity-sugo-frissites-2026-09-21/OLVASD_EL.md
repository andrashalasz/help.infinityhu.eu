# Infinity Súgó — frissítés

**Erről:** 2026-09-15-i telepítőcsomag → **Erre:** 2026-09-18-i változat

---

## A lényeg elöljáróban

> ### Ez a frissítés NEM írja felül a súgó szövegeit.
>
> A fejezetek címéhez, szövegéhez, vázlataihoz, URL-jeihez, a főfejezetek
> nevéhez, a képekhez és a felhasználókhoz **egyetlen parancs sem nyúl**.
>
> Amit az adatbázisban módosít:
>
> | mit | miért |
> |---|---|
> | új oszlopok, táblák, nézetek, tárolt eljárások | az új funkciókhoz |
> | `help_ui` tábla | a **kezelőfelület** angol/német feliratai — nem a súgó tartalma |
> | `help_lang` tábla | a nyelvek listája, csak ha még nincs benne (`INSERT IGNORE`) |
> | `help_article.highlight_until` | **egyetlen tartalmi mező**: az „új/frissítve" jelvény lejárati dátuma. A fejezet szövegéhez nem nyúl. Részletek lent. |
>
> Ezt ellenőrizve is: a frissítés előtt és után a teljes tartalomról vett
> ujjlenyomat (MD5 az összes fejezet azonosítójából, címéből, szövegéből,
> URL-jéből és fejezetszámából) **betűre azonos** — kétszeri futtatás után is.

---

## 1. Mi van a csomagban

```
frissites.sh                        a frissítő szkript (ezt kell futtatni)
adatbazis/
  01_sema_frissites.sql             szerkezeti frissítés — többször is futtatható
  02_felulet_forditasok.sql         a kezelőfelület angol/német feliratai (1388 sor)
site_dinamikus/                     az alkalmazás új fájljai
  config.php.minta                  MINTA — a meglévő config.php-t NEM cseréljük le
  tools/felulet_forditas.php        parancssori felület-fordító (később, ha kell)
OLVASD_EL.md                        ez a fájl
VALTOZASOK.md                       mi újság ebben a változatban
```

Ami **nincs** benne, és ezért nem is sérülhet:

- `db/01_sema.sql` … `db/05_jogosultsagok.sql` — ezek tartalmazzák a súgó
  szövegét. Az első telepítéshez valók, frissítéskor **nem szabad** futtatni.
- `config.php` — az adatbázis-jelszavakkal
- `media/` — a képek és videók

---

## 2. Frissítés egy paranccsal

```bash
tar -xzf infinity-sugo-frissites-2026-09-18.tar.gz
cd infinity-sugo-frissites-2026-09-18
./frissites.sh /var/www/help.infinityhu.eu
```

A szkript megkérdezi, indulhat-e, majd négy lépést tesz:

1. **Biztonsági mentés** az adatbázisról (`mentes-ÉÉÉÉHHNN-ÓÓPPMM.sql.gz` a
   súgó könyvtárába)
2. **Szerkezeti frissítés**
3. **A kezelőfelület feliratainak** betöltése
4. **Az alkalmazás fájljainak** cseréje — a régi `site_dinamikus/` megmarad
   `site_dinamikus-regi-ÉÉÉÉHHNN-ÓÓPPMM` néven

Az adatbázis adatait a `config.php`-ból olvassa ki. Ha máshonnan kell:

```bash
DB_NEV=help DB_USER=root DB_JELSZO=titok ./frissites.sh /var/www/help.infinityhu.eu
```

### Megszakadt? Nyugodtan indítsd újra

Mindkét SQL-fájl **többször is futtatható**: a szerkezeti rész minden
lépésnél ellenőrzi, kell-e még, a feliratok pedig `INSERT IGNORE`-ral
mennek be, tehát amit már beírtak a helyszínen, azt nem bántja.

---

## 3. Kézzel, ha nem akarsz szkriptet futtatni

```bash
# 1. mentés (ezt semmiképp ne hagyd ki)
mysqldump --single-transaction --routines --triggers -u USER -p ADATBAZIS \
  | gzip > mentes-$(date +%F).sql.gz

# 2. adatbázis
mysql -u USER -p ADATBAZIS < adatbazis/01_sema_frissites.sql
mysql -u USER -p ADATBAZIS < adatbazis/02_felulet_forditasok.sql

# 3. fájlok — a config.php-t kihagyva
cp -a /var/www/help/site_dinamikus /var/www/help/site_dinamikus-regi
rsync -a --exclude 'config.php' --exclude 'media/' \
      site_dinamikus/ /var/www/help/site_dinamikus/
rm -f /var/www/help/site_dinamikus/config.php.minta
chown -R www-data:www-data /var/www/help/site_dinamikus
```

---

## 4. Az egyetlen tartalmi mező, amihez hozzányúl

Az `01_sema_frissites.sql` végén ez az egy parancs áll:

```sql
UPDATE help_article
   SET highlight_until = DATE '9999-12-31'
 WHERE is_published = 1
   AND highlight_until IS NOT NULL
   AND highlight_until >= CURRENT_DATE;
```

**Mit csinál:** a nyilvános oldalon az újonnan közzétett fejezetek „új" /
„frissítve" jelvényt kapnak. Eddig ez 30 nap után magától eltűnt. Mostantól
a **kiadás lezárásáig** marad kint — ez volt az egyik kért változtatás. Ez a
parancs a *már kint lévő* jelzéseket állítja át az új rendszerre.

**Mit NEM csinál:** nem nyúl a fejezet címéhez, szövegéhez, vázlatához,
URL-jéhez, sem a fejezetszámhoz.

**Ha nem kéred:** vedd ki ezt a parancsot a fájl végéről. Semmi más nem
múlik rajta — a jelzések egyszerűen a régi dátumukkal tűnnek majd el.

---

## 5. Frissítés után

Nyisd meg a böngészőben:

- **a nyilvános súgót** mind a három nyelven (`/hu/`, `/en/`, `/de/`) —
  a fejezetek a helyükön vannak-e
- **az admin felületet** — be tudsz-e lépni, a Fejezetek fülön ott
  vannak-e a fejezetek
- a fejléc jobb szélén a **fogaskereket** — menüt nyit: Gépi fordítás,
  A kezelőfelület szövegei, Nyelvek, Kiadások

A kezelőfelület nyelvét a fejlécben, a nyelvkóddal (HU / EN / DE) állítod.
Angolra vagy németre váltva a feliratoknak le kell fordulniuk — ha valahol
magyar maradt, az a **Kezelőfelület szövegei** lapon javítható, akár
kézzel, akár a „Hiányzó szövegek gépi fordítása" gombbal.

---

## 6. Ha baj van — visszaállítás

A szkript mindkét irányban hagyott mentést:

```bash
# fájlok
rm -rf /var/www/help/site_dinamikus
mv /var/www/help/site_dinamikus-regi-ÉÉÉÉHHNN-ÓÓPPMM /var/www/help/site_dinamikus

# adatbázis
gunzip -c /var/www/help/mentes-ÉÉÉÉHHNN-ÓÓPPMM.sql.gz | mysql -u USER -p ADATBAZIS
```

---

## 7. Amire figyelni kell

**PHP-kiterjesztések.** Ugyanaz kell, mint eddig: `pdo_mysql`, `dom`, `zip`,
`gd`, `mbstring`, `fileinfo`, `curl`. A `curl` a gépi fordításhoz kell — ha
nincs, minden más működik, csak a fordítógomb nem.

**Gépi fordítás.** A Beállítások → Gépi fordítás lapon adható meg a
szolgáltató és a kulcs. Enélkül a kézi fordítás és a teljes felület
működik; a csomagban lévő angol/német feliratokhoz sem kell kulcs.

**Nincs új PHP- vagy MariaDB-követelmény.** PHP 8.1+, MariaDB 11.x — mint
eddig.
