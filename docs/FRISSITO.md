# Éles frissítés az admin felületről

Az admin a súgó adminjában, a fogaskerék menü **Frissítés** lapján egy
gombnyomással kiteheti a legújabb verziót. Ez a leírás a **szervercsapatnak**
szól: egyszer kell beállítani, utána a frissítéshez nem kell a szerverre
belépni.

A felépítés a V40 Vital frissítőjéé (`andrashalasz/v40new`, `docs/FRISSITO.md`),
a súgó felépítéséhez igazítva.

## Hogyan működik

```
admin felület ──(kérésfájl)──▶   frissites/    ◀──(állapot, napló)── frissítő szolgáltatás
 (PHP-konténer)                 megosztott mappa                       (a gazdagépen, systemd)
```

- A **webalkalmazás semmit nem futtat**. A gomb egy négy mezős fájlt ír a
  megosztott mappába (`frissites/request.json`): melyik verziót kéri, ki, mikor.
- A **frissítő szolgáltatás** (`deploy/sugo-frissito.py`) a gazdagépen fut. Ő
  figyeli a GitHub fő ágát, és ő végzi a telepítést.
- A kérés **csak akkor érvényes**, ha a kért verzió betűre az, amit a frissítő
  maga lát legújabbként a fő ágon, és a most futó verzió folytatása. A kérésben
  nincs parancs, útvonal vagy kapcsoló – egy feltört admin-fiókból sem lesz
  több, mint a legújabb verzió kitelepítése.
- Frissítést csak **admin** szerepkörű felhasználó kérhet, és ehhez **újra be
  kell írnia a jelszavát**. A rossz jelszó a belépéssel közös zárolásba számít
  (5 hiba = 10 perc), és 15 percenként legfeljebb 5 kísérlet lehet. Minden
  kísérlet, elutasítás és kérés az auditnaplóba kerül.

### A telepítés lépései

| lépés | mit csinál | ha hibára fut |
|---|---|---|
| A kérés ellenőrzése | a kért verzió a legújabb-e és a futó folytatása-e, a forrásfa tiszta-e (nincs kézzel módosított követett fájl), fut-e a súgó, van-e elég hely | megáll, a futó rendszerhez nem nyúl |
| Az új verzió letöltése | a kért verzió tiszta példánya egy ideiglenes mappába (`git archive`) | megáll |
| Tesztek futtatása | `php tests/futtat.php` hálózat nélküli `php:8.3-cli` konténerben | megáll |
| Új verzió építése | az új PHP-képfájl a tiszta példányból épül (`docker build`), a futó forrásfához nem nyúl; a régi képfájl visszaút-címkét kap | megáll |
| Adatbázis-mentés | `mariadb-dump` a `frissito-mentesek/` mappába; ha az adatbázis módosulni fog, **előtte leáll a súgó**. A mentést **próbából vissza is tölti** egy ideiglenes adatbázisba, és összeveti az eredetivel (táblák, triggerek, eljárások; álló súgónál a sorok száma is). Vissza nem tölthető mentéssel nem megy tovább | visszaindítja a régit |
| Adatbázis frissítése | csak ha a `db/migracio/` változott: a forrás átáll, és lefut a `migracio` szolgáltatás | **visszatölti a mentést**, visszaindítja a régit |
| Átváltás | a forrás átáll (ha még nem), és `docker compose up -d --no-build --no-deps --force-recreate php web` | visszaáll a régi forrásra és képfájlra |
| Ellenőrzés | a `/health.php` kétszer egymás után 200-at ad-e, és a PHP-konténerben a kért verzió (`APP_COMMIT`) fut-e | visszaáll |
| Takarítás | régi mentések (az utolsó 14 marad), nem használt képfájlok | – |

Ha az adatbázis **nem** módosul, a kiesés néhány másodperc (a konténerek
újraindulása). Ha módosul, a súgó a mentés és a migráció idejére áll (pár
perc) – ezt az admin a gomb megnyomása előtt látja.

A visszaállás azért nem veszít adatot, mert adatbázis-módosításnál a súgó a
mentés **előtt** leáll: a mentés és a visszatöltés között senki nem írhat.

### Miben más, mint a V40

A súgó kódja nem a képfájlban van, hanem a forrásfából van becsatolva
(`./site_dinamikus`). Ha a frissítő az építéskor átállítaná a forrásfát, az
új kód azonnal élesbe kerülne a régi adatbázison. Ezért a forrásfa csak a
leállítás után (adatbázis-módosításnál), illetve közvetlenül az átváltás
előtt áll át; addig a régi verzió zavartalanul fut.

## Egyszeri beállítás

**Előfeltétel:** a szerveren már az a verzió fut, amelyikben ez a funkció
benne van. Az erre a verzióra való frissítés még kézzel megy:

```bash
cd /var/www/help.infinityhu.eu          # a kicsomagolt tároló
git pull --ff-only
docker compose up -d --build
```

A régi, percenként futó `tools/frissito-figyelo.sh` sort **ki kell venni a
crontabból** (`crontab -e`) – az új szolgáltatás váltja ki. A szkript ebben a
verzióban már nincs meg.

Kell hozzá: Python 3.8+, git, Docker a `docker compose` bővítménnyel – más nem.

```bash
cd /var/www/help.infinityhu.eu

# 1. A program és a szolgáltatás
sudo install -m 0755 deploy/sugo-frissito.py          /usr/local/bin/sugo-frissito
sudo install -m 0644 deploy/sugo-frissito.service     /etc/systemd/system/
sudo install -m 0640 deploy/sugo-frissito.env.example /etc/sugo-frissito.env

# 2. Beállítás: a tároló mappája, és ha kell, a port / a compose-fájlok
sudoedit /etc/sugo-frissito.env
sudoedit /etc/systemd/system/sugo-frissito.service     # User= a tároló tulajdonosa

# 3. Ellenőrzés – ugyanazzal a felhasználóval, akivel a szolgáltatás futni fog
sudo -u sugo env $(grep -v '^#' /etc/sugo-frissito.env | xargs) sugo-frissito check

# 4. Indítás
sudo systemctl daemon-reload
sudo systemctl enable --now sugo-frissito
journalctl -u sugo-frissito -f
```

A `check` minden feltételt kiír: elérhető-e a GitHub, használható-e a Docker,
be van-e csatolva a csatorna a PHP-konténerbe, válaszol-e a `/health.php`.
Amíg hibát jelez, a szolgáltatást nem érdemes elindítani.

Indítás után néhány másodpercen belül az admin Frissítés lapján a frissítő
állapota **Működik**-re vált.

### Amire figyelni kell

- **A GitHub elérése.** A szolgáltatás felhasználójának jelszó nélkül kell
  tudnia `git fetch`-et futtatni (deploy-kulcs, csak olvasási joggal).
- **Helyi eltérés ne legyen a verziókövetett fájlokban.** Ha a szerveren egy
  követett fájl kézzel át van írva, a frissítő nem telepít, és **megnevezi a
  fájlt** – nem írja felül a kezetek munkáját. A compose helyi eltérése (más
  port, más kötet) egy külön, nem követett fájlba való:

  ```yaml
  # docker-compose.local.yml  (nincs verziókövetve)
  services:
    web:
      ports: !override
        - "127.0.0.1:8081:80"
  ```

  és az `/etc/sugo-frissito.env`-ben:
  `SUGO_COMPOSE_FILES=docker-compose.yml:docker-compose.local.yml`,
  `SUGO_HEALTH_URL=http://127.0.0.1:8081/health.php`.
  A portot a `.env` fájl `WEB_PORT` változója is megadhatja – az nincs
  verziókövetve, nem zavarja a frissítőt.
- **Mit NEM ment a frissítő:** a feltöltött képeket (`media/`). Ezekhez a
  telepítés nem nyúl; a rendszeres mentésük továbbra is külön feladat.
- **Csak előre, csak a PHP és a web.** A frissítő régebbi verzióra nem lép
  vissza (csak hiba esetén, magától), és csak a `php` és a `web`
  szolgáltatást indítja újra. A MariaDB verzióváltása vagy a LibreTranslate
  cseréje kézi munka marad.
- **A csatorna jogai.** A `frissites/` mappát a frissítő ragadós bittel állítja
  be (mint a `/tmp`): a PHP (`www-data`, 33) letehet kérést, de a frissítő
  fájljait nem törölheti. Ha a szolgáltatás felhasználója éppen a 33-as
  azonosítójú, ez a védelem nem érvényesül – a telepítés biztonságát ez nem
  érinti (a frissítő a saját állapotát a csatornán kívül, a `.frissito/`
  mappában tartja), csak az oldalon megjelenő állapot hitelességét.
- **A gépi fordítás.** Egy frissítés nem indít fordítást. Ha közben épp fut
  egy közzététel miatti fordítás, az átváltás megszakítja – a fejezet vázlata
  megmarad, a fordítás újraindítható.

## Hol mit találok

| hely | mi van ott |
|---|---|
| `frissites/status.json` | a frissítő állapota – ezt mutatja az admin lap |
| `frissites/log/<azonosító>.log` | egy telepítés teljes naplója (az utolsó 40) |
| `frissito-mentesek/sugo-…-elotte-<verzió>.sql.gz` | a telepítés előtti adatbázis-mentés (az utolsó 14) |
| `.frissito/` | a frissítő saját állapota és előzményei |
| `journalctl -u sugo-frissito` | a szolgáltatás naplója |

`sugo-frissito status` – ugyanaz, mint amit az admin lap lát, a parancssorból.

## Ha baj van

- **A lap szerint a frissítő nem fut:** `systemctl status sugo-frissito`,
  `journalctl -u sugo-frissito -n 100`.
- **A telepítés visszaállt:** a súgó a régi verzióval megy. Az ok a lapon és a
  naplóban van; a hibát a fejlesztőnek kell javítania, utána újra indítható.
- **„A visszaállás sem sikerült":** a súgó valószínűleg áll. A napló
  megmondja, melyik lépésnél. Kézi visszaállás:

  ```bash
  cd /var/www/help.infinityhu.eu
  git reset --hard <az előző verzió azonosítója>        # a lap „előtte:" mezője
  docker tag sugo-frissito-rollback:previous <a PHP képfájl neve>   # docker compose images php
  # csak ha az adatbázis is módosult – a napló megmondja:
  ( echo 'DROP DATABASE IF EXISTS `help_infinityhu`;'; gunzip -c frissito-mentesek/<mentés>.sql.gz ) | \
    docker compose exec -T db sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot'
  docker compose up -d --no-build --no-deps --force-recreate php web
  ```
- **Kézi frissítés továbbra is lehetséges** (`git pull` + `docker compose up -d
  --build`). Ha a frissítő fut, előtte érdemes megállítani (`systemctl stop
  sugo-frissito`), utána elindítani – a futó verziót magától felismeri.

## Kipróbálás helyben

`tools/frissito-proba.sh` egy helyi „GitHub"-bal (bare tároló) és egy külön
„szerver"-klónnal végigjátssza a jó és a szándékosan elrontott frissítéseket
(bukó teszt, hibás életjel, elrontott adatbázis-módosítás, kézzel módosított
fájl), és ellenőrzi, hogy a frissítő megáll vagy visszaáll. Lásd a szkript
fejlécét.
