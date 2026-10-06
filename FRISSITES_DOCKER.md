# Frissítés Docker alatt

Ez a leírás a **konténerben futó** `help.infinityhu.eu`-ra vonatkozik.
A klasszikus (nginx + PHP-FPM a hoston) telepítést a `TELEPITES.md` írja le.

---

## A lényeg elöljáróban

> ### A frissítés nem írja felül a súgó szövegeit.
>
> Ellenőrizve: a migráció kétszeri lefuttatása után a teljes tartalomról vett
> ujjlenyomat (MD5 az összes fejezet azonosítójából, címéből, törzsszövegéből,
> vázlatából, fejezetszámából és URL-jéből) **betűre azonos**.

---

## Miért kellett ehhez hozzányúlni

A `db/` mappa a `docker-entrypoint-initdb.d`-be van csatolva, azt viszont a
MariaDB **csak akkor futtatja le, amikor üres adatkönyvtárral indul először**.

Egy már feltöltött adatbázis tehát soha nem kapná meg az újabb migrációkat:
egy `docker compose up -d --build` után a **kód** új lenne, a **séma** régi,
és a súgó elszállna a hiányzó oszlopokon. Egy konténer-újraindítás ugyanide
vezetne.

Ezért került be a `migracio` szolgáltatás.

---

## Hogyan frissíts

```bash
cd /utvonal/a/help.infinityhu.eu
git pull
docker compose up -d --build
```

Ennyi. A `migracio` szolgáltatás magától lefut, miután az adatbázis
egészségesre vált, és **csak utána** indul a `php`.

### Mit csinál a migrációs lépés

Lefuttatja a `db/migracio/` alatti fájlokat:

| fájl | mit csinál |
|---|---|
| `01_sema.sql` | új oszlopok, táblák, nézetek, tárolt eljárások |
| `02_felulet_forditasok.sql` | a **kezelőfelület** angol/német feliratai (1404 sor, `INSERT IGNORE`) |

Mindkettő **többször is futtatható**:

- a séma minden lépésnél ellenőrzi az `information_schema`-ban, kell-e még
- a feliratok `INSERT IGNORE`-ral mennek be, tehát amit a helyszínen már
  átírtak, azt **nem bántja**

Ha már minden a helyén van, a lépés nem csinál semmit. Ezért nyugodtan
újraindulhat a konténer: minden induláskor lefut, és nem árt.

### Az egyetlen tartalmi mező, amihez hozzányúl

A `01_sema.sql` végén egy `UPDATE help_article` áll, ami **kizárólag** a
`highlight_until` oszlopot állítja — ez az „új / frissítve" jelvény lejárati
dátuma a nyilvános oldalon. A fejezet címéhez, szövegéhez, vázlatához,
URL-jéhez nem nyúl. Ha nem kell, a parancs kivehető a fájl végéről; semmi
más nem múlik rajta.

---

## Frissítés után: a fejezetszámok helyretétele

Ezt **egyszer** kell lefuttatni, és nem automatikus — mert átírja a
fejezetszámokat és az URL-eket.

```bash
# 1. előbb csak MEGMUTATJA, mit csinálna — semmit nem ír:
docker compose exec php php /var/www/html/tools/szamozas_rendbetetel.php

# 2. ha a kimenet rendben van:
docker compose exec php php /var/www/html/tools/szamozas_rendbetetel.php --alkalmaz
```

Az első parancs kiírja a mostani és a leendő állapotot egymás alá. Mit tesz:

- **főfejezetek:** bezárja a hézagot (ha a 14-est törölték, a 15-ösből 14 lesz).
  A kezdőszám nem változik.
- **fejezetek:** főfejezetenként folyamatos számozás a megjelenítési sorrend
  szerint.
- **minden nyelven egyszerre** — a forrásnyelv sorrendje dönt.
- az **URL-ek is követik**, de a régi címek 301-gyel átirányítanak, tehát a
  kiadott hivatkozások nem törnek el.

A fejezetek szövegéhez nem nyúl.

---

## Visszaállítás

Mentés a frissítés előtt:

```bash
docker compose exec -T db mariadb-dump --single-transaction --routines --triggers \
  -u root -p"$MARIADB_ROOT_PASSWORD" help_infinityhu | gzip > mentes-$(date +%F).sql.gz
```

Vissza:

```bash
gunzip -c mentes-ÉÉÉÉ-HH-NN.sql.gz | \
  docker compose exec -T db mariadb -u root -p"$MARIADB_ROOT_PASSWORD" help_infinityhu
```

A kódhoz `git checkout <korábbi commit>` és újabb `docker compose up -d --build`.

---

## Ellenőrzés frissítés után

- a nyilvános súgó betölt-e mind a három nyelven (`/hu/`, `/en/`, `/de/`)
- az adminba be tudsz-e lépni
- a fejléc jobb szélén a **fogaskerék** menüt nyit-e: Gépi fordítás,
  A kezelőfelület szövegei, Nyelvek, Kiadások

Ha a fogaskerék helyett még külön „Modulok" és „Képernyők" fül látszik,
akkor a régi kód fut — a `php` konténer nem épült újra.
