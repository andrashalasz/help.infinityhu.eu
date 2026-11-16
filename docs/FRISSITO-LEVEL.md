**Tárgy:** help.infinityhu.eu – új, admin felületről indítható frissítő (egyszeri beállítás, kb. 20 perc)

Sziasztok!

A súgóba (help.infinityhu.eu) bekerült ugyanaz az admin felületről indítható
éles frissítő, ami a V40 Vitalban már működik. Ha egyszer beállítjátok,
utána a frissítéshez nem kell a szerverre belépni, és a régi, percenként
futó cron-szkript is megszűnik. Egyszeri beállítás kell hozzá, lépésenként
lent.

**Mit csinál, röviden**
- Az admin a Frissítés lapon, a jelszava újbóli megadásával kéri a GitHub fő
  ágán lévő legújabb verziót. A webalkalmazás semmit nem futtat, csak egy
  kérésfájlt ír a `frissites/` mappába.
- A tényleges munkát egy systemd-szolgáltatás végzi a gazdagépen
  (`deploy/sugo-frissito.py`, csak Python standard könyvtár). Sorrend:
  ellenőrzés, letöltés, tesztek, építés, adatbázis-mentés, adatbázis-módosítás,
  átkapcsolás, működésellenőrzés, takarítás.
- A mentést próbából visszatölti egy ideiglenes adatbázisba
  (`sugo_frissito_ellenorzes`), mielőtt bármihez nyúlna. Vissza nem tölthető
  mentéssel nem indul.
- Ha bármi elromlik, magától visszaáll az előző verzióra (forrás +
  PHP-képfájl, és ha kellett, az adatbázis a mentésből).
- Ha a szerveren kézzel módosított követett fájl van, nem telepít, és
  megnevezi a fájlt.

**Teendők (a tároló mappájában, nálunk eddig `/var/www/help.infinityhu.eu`)**

1. Erre a verzióra még kézzel frissítsetek:
   ```bash
   git pull --ff-only
   docker compose up -d --build
   ```
2. A régi cron-sort vegyétek ki (`crontab -e`):
   `*/1 * * * * …/tools/frissito-figyelo.sh` – a szkript már nincs a tárolóban.
3. A szolgáltatás:
   ```bash
   sudo install -m 0755 deploy/sugo-frissito.py          /usr/local/bin/sugo-frissito
   sudo install -m 0644 deploy/sugo-frissito.service     /etc/systemd/system/
   sudo install -m 0640 deploy/sugo-frissito.env.example /etc/sugo-frissito.env
   sudoedit /etc/sugo-frissito.env                    # SUGO_REPO_DIR, és ha nem 8080: SUGO_HEALTH_URL
   sudoedit /etc/systemd/system/sugo-frissito.service # User= a tároló tulajdonosa (docker csoport tagja)
   ```
4. Ellenőrzés ugyanazzal a felhasználóval, akivel futni fog:
   ```bash
   sudo -u <felhasználó> env $(grep -v '^#' /etc/sugo-frissito.env | xargs) sugo-frissito check
   ```
   Minden sornak `[rendben]`-nek kell lennie. A leggyakoribb hibák:
   - a `git fetch` jelszót kér (kell egy csak olvasási jogú deploy-kulcs),
   - a felhasználó nincs a `docker` csoportban,
   - kézzel módosított követett fájl van a tárolóban (a `check` megnevezi;
     a helyi eltérés helye a `.env` vagy egy külön `docker-compose.local.yml`).
5. Indítás:
   ```bash
   sudo systemctl daemon-reload
   sudo systemctl enable --now sugo-frissito
   journalctl -u sugo-frissito -f
   ```
   Néhány másodpercen belül az admin Frissítés lapján a frissítő állapota
   „Működik"-re vált.

**Amit érdemes tudni**
- Helyigény: a mentések a `frissito-mentesek/` mappába kerülnek (az utolsó 14
  marad), a telepítéshez legalább 3 GB szabad hely kell.
- A feltöltött képeket (`media/`) a frissítő nem menti és nem módosítja.
- A frissítő a `php` és a `web` konténert indítja újra; a MariaDB-hez csak a
  mentés, a próba-visszatöltés és szükség esetén a visszatöltés miatt nyúl.
- Adatbázis-módosítással járó frissítésnél a súgó pár percre leáll (ezt az
  admin a gomb előtt látja); egyébként a kiesés néhány másodperc.
- Kézi visszaállás, ha a magától történő is elbukna: `docs/FRISSITO.md`,
  „Ha baj van" rész.

A teljes leírás a tárolóban: `docs/FRISSITO.md`. Helyben, szándékosan
elrontott frissítésekkel (bukó teszt, rossz életjel, elrontott és hibás
adatbázis-módosítás, kézzel módosított fájl) kipróbáltuk; mindegyiknél
megállt vagy visszaállt, adat nem veszett el.

Ha bármi nem stimmel, szóljatok!

Köszönöm,
András
