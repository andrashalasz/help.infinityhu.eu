# Telepítés – 2026-10-07

Ez a lap egyetlen alkalomra szól: a `d964827` változat kitétele az élesre.
Négy lépés, mind a **szerveren**, a súgó mappájában. Utána törölhető.

A lépések sorrendje számít: a 2. nélkül a képfeltöltés és a Word-import
továbbra sem működne.

---

## 1. Kód frissítése

```bash
git pull --ff-only
docker compose up -d --build
```

## 2. A képek mappájának jogosultsága

Ez a régóta nyitott hiba: a konténer PHP-ja (uid 33) nem tud írni a
`media` mappába, ezért a feltöltés és a Word-import képkibontása elszáll
(„A képek mappája nem írható: /srv/media").

```bash
sudo chown -R 33:33 media
sudo chmod 775 media
```

Ellenőrzés: a súgó adminban a **Képek, videók** lapon nem szabad megjelennie
a piros „A fájlok mappája nem írható" sávnak.

## 3. A frissítő figyelő beüzemelése

Innentől a súgó admin **Frissítés** lapjáról indítható a frissítés. A web
semmit nem futtat, csak kérelmet ír – a munkát ez a szkript végzi a
gazdagépen.

```bash
sudo mkdir -p frissites
sudo chown -R 33:33 frissites
sudo chmod 775 frissites
```

Majd `crontab -e`, és egy sor:

```
*/1 * * * * /var/www/help.infinityhu.eu/tools/frissito-figyelo.sh
```

> A `/var/www/help.infinityhu.eu` helyére a tényleges útvonal kell.

Ellenőrzés: egy perc múlva keletkeznie kell egy `frissites/verzio.json`
fájlnak, és az adminban a **Frissítés** lapnak ki kell írnia a jelenlegi
változatot.

## 4. Képernyőképek feltöltése

A 32 kép a `tools/kepernyokepek/kimenet/` mappában van, a végleges nevükön.
A fejezetek szövege már ezekre a nevekre hivatkozik, tehát csak be kell
másolni őket:

```bash
cp tools/kepernyokepek/kimenet/*.png media/
sudo chown 33:33 media/*.png
```

---

## Amit NEM kell csinálni

- **Az adatbázist nem kell visszatölteni.** A súgó szövegei az adatbázisban
  vannak, a `git pull` nem nyúl hozzájuk.
- A `frissites/` mappa tartalma szándékosan nincs verziókezelve.

## Ha valami elromlik

A frissítő minden futás előtt menti az adatbázist ide:
`frissites/mentesek/mentes-<időbélyeg>.sql.gz` (a legutóbbi 10 marad meg).

Visszaállás az előző változatra az admin **Frissítés** lapjáról, vagy kézzel:

```bash
git checkout $(cat frissites/elozo-commit.txt)
docker compose up -d --build
```
