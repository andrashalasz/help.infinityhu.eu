# Infinity Súgó — telepítési útmutató

**help.infinityhu.eu** — a súgó nyilvános oldala és a hozzá tartozó szerkesztői felület.

Ez a dokumentum a rendszergazdának szól. Két telepítési módot ír le:

| | mikor |
|---|---|
| **A) Docker** | ha a szerveren futhat Docker. Egy paranccsal elindul, a legkevesebb kézi lépés. |
| **B) Klasszikus** | nginx + PHP-FPM + MariaDB a szerverre telepítve. Ez az ajánlott az éles `help.infinityhu.eu`-hoz, ha már van ilyen kiszolgáló. |

A kettő ugyanazt a kódot futtatja, ugyanazzal az adatbázissal. Váltani bármikor lehet.

---

## 0. Mi van a csomagban

```
db/                   az adatbázis teljes felépítése + a teljes tartalom (5 SQL fájl)
site_dinamikus/       maga az alkalmazás (PHP)
  index.php             a nyilvános súgó
  admin.php             a szerkesztői felület
  config.php            adatbázis-beállítások (környezeti változó felülírja)
  lib/                  segédkönyvtárak — a webről NEM érhető el
  assets/               CSS, JS, logó
media/                275 képernyőkép (+ később a feltöltött képek, videók)
site_kod/             statikus, előre generált HTML változat (alternatíva, nem kötelező)
deploy/               kész nginx konfigok, a „?" gomb JS-e
docker/, docker-compose.yml   nginx 1.28.3 + PHP 8.3 FPM + MariaDB 11.8.6
README.md             funkcionális leírás (mit tud a rendszer)
TELEPITES.md          ez a fájl
```

**Amire szükség lesz:**

| | |
|---|---|
| nginx | **1.28.3** (vagy újabb) |
| PHP | **8.1+** FPM, a `pdo_mysql`, `dom`, `zip`, `gd`, `mbstring`, `fileinfo`, `curl` kiterjesztésekkel |
| MariaDB | **11.8.6** (vagy újabb 11.x) |
| WeasyPrint | *opcionális* — a közvetlen PDF-letöltéshez |

A Docker változat mindezt hozza magával, pontosan ezekben a verziókban.

---

## A) Telepítés Dockerrel

```bash
cd infinity-sugo
docker compose up -d --build
```

Ennyi. Az első indulás kb. 1–2 perc (image-építés + az adatbázis feltöltése).

| | |
|---|---|
| Súgó | http://localhost:8080/ |
| Admin | http://localhost:8080/admin.php |
| MariaDB | `localhost:3307` |

**Éles használat előtt kötelezően cseréld a jelszavakat.** Másold a `.env.example` fájlt
`.env` néven, és írd át benne a `MARIADB_ROOT_PASSWORD`, `MARIADB_PASSWORD`, `HELP_DB_PASS`,
`HELP_DB_ADMIN_PASS` értékeket, majd:

```bash
docker compose down -v      # FIGYELEM: törli az adatbázis kötetét is
docker compose up -d --build
```

A `-v` azért kell, mert a jelszavak az adatbázis első felépítésekor rögzülnek.

A portok is átírhatók a `.env`-ben (`WEB_PORT`, `DB_PORT`).

Ha a Docker mögé fordított proxy (nginx) kerül, a vhost egyszerűen a `WEB_PORT`-ra
proxyzzon, és adja tovább a `X-Forwarded-Proto` fejlécet — ettől kapja meg a
munkamenet-süti a `Secure` jelzőt.

---

## B) Klasszikus telepítés (nginx + PHP-FPM + MariaDB)

### B1. Csomagok

```bash
sudo apt-get update
sudo apt-get install -y nginx mariadb-server \
     php-fpm php-mysql php-xml php-zip php-gd php-mbstring php-curl

# opcionalis, a kozvetlen PDF-letolteshez:
sudo apt-get install -y weasyprint

nginx -v                                                      # 1.28.3 vagy ujabb
mariadb --version                                             # 11.8.x
php -m | grep -E 'pdo_mysql|dom|zip|gd|mbstring|fileinfo'     # mind az ot legyen ott
```

> Ha a disztribúció csomagja régebbi nginx-et vagy MariaDB-t ad, a gyártói tárolóból
> érdemes telepíteni: <https://nginx.org/en/linux_packages.html>,
> <https://mariadb.org/download/?t=repo-config>.

### B2. Adatbázis és felhasználók

A súgónak **saját adatbázisa** van, szándékosan nem az Infinity fő adatbázisa — így a
help.infinityhu.eu akkor is működik, ha az Infinity áll.

Két adatbázis-felhasználó kell:

| | jog | mire |
|---|---|---|
| `help_ro` | csak olvas | a nyilvános oldal (`index.php`) |
| `help_rw` | ír és olvas | a szerkesztői felület (`admin.php`) |

```bash
sudo mariadb <<'SQL'
CREATE DATABASE help_infinityhu
  CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;

CREATE USER 'help_ro'@'localhost' IDENTIFIED BY 'ide-egy-eros-jelszot';
CREATE USER 'help_rw'@'localhost' IDENTIFIED BY 'ide-egy-masik-eros-jelszot';

GRANT SELECT ON help_infinityhu.* TO 'help_ro'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON help_infinityhu.* TO 'help_rw'@'localhost';
GRANT EXECUTE ON help_infinityhu.* TO 'help_rw'@'localhost';
FLUSH PRIVILEGES;
SQL
```

> **A rendezés (`utf8mb4_uca1400_ai_ci`) fontos**: ez teszi a keresést ékezet-függetlenné.
> Ne a `..._hungarian_ai_ci`-t add meg — az az `o`/`ö`/`ő` és `u`/`ü`/`ű` párokat külön
> betűnek veszi, így a „penzugy" nem találná meg a „Pénzügy"-öt.

Majd a séma és a tartalom, **ebben a sorrendben**:

```bash
cd infinity-sugo
for f in db/01_sema.sql db/02_eljarasok.sql db/03_alapadatok.sql db/04_tartalom.sql; do
  echo ">>> $f"
  sudo mariadb help_infinityhu < "$f" || break
done
```

> A `db/05_jogosultsagok.sql`-t **NE** futtasd le: az csak a Docker-környezet fejlesztői
> jelszavait hozza létre. A jogosultságokat a fenti lépés már megadta.

A MariaDB-nek engednie kell a nagy tartalmi mezőket és a rövid keresőszavakat.
`/etc/mysql/mariadb.conf.d/60-help.cnf`:

```ini
[mariadb]
character-set-server        = utf8mb4
collation-server            = utf8mb4_uca1400_ai_ci
max_allowed_packet          = 256M
innodb_ft_min_token_size    = 2
```

```bash
sudo systemctl restart mariadb
```

> Az `innodb_ft_min_token_size` **módosítása után** a teljes szöveges indexet újra kell építeni,
> különben a rövid szavak nem kerülnek bele:
> ```bash
> sudo mariadb help_infinityhu -e "ALTER TABLE help_article DROP INDEX help_article_ft;
>   ALTER TABLE help_article ADD FULLTEXT KEY help_article_ft (title, plain_text);"
> ```

Ellenőrzés — 109 sort kell kapni nyelvenként:

```bash
sudo mariadb help_infinityhu -e "SELECT lang, COUNT(*) FROM help_article GROUP BY lang;"
```

### B3. Fájlok a helyükre

```bash
sudo mkdir -p /var/www/help.infinityhu.eu
sudo cp -r site_dinamikus /var/www/help.infinityhu.eu/
sudo cp -r media          /var/www/help.infinityhu.eu/

sudo chown -R root:www-data /var/www/help.infinityhu.eu
sudo find /var/www/help.infinityhu.eu -type d -exec chmod 750 {} \;
sudo find /var/www/help.infinityhu.eu -type f -exec chmod 640 {} \;

# a media mappába a szerkesztő ír (kép- és videófeltöltés, Word-import)
sudo chown -R www-data:www-data /var/www/help.infinityhu.eu/media
sudo chmod 2775 /var/www/help.infinityhu.eu/media
```

### B4. PHP-FPM pool

Az adatbázis-jelszavak **környezeti változóban** menjenek, ne a `config.php`-ba.

`/etc/php/8.3/fpm/pool.d/help.conf` (a PHP verziót igazítsd):

```ini
[help]
user = www-data
group = www-data
listen = /run/php/php-help.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4

; --- adatbázis ---
env[HELP_DB_DSN]        = "mysql:host=127.0.0.1;port=3306;dbname=help_infinityhu;charset=utf8mb4"
env[HELP_DB_USER]       = "help_ro"
env[HELP_DB_PASS]       = "ide-egy-eros-jelszot"
env[HELP_DB_ADMIN_USER] = "help_rw"
env[HELP_DB_ADMIN_PASS] = "ide-egy-masik-eros-jelszot"

; --- a képek/videók mappája a lemezen ---
env[HELP_MEDIA_DIR]     = "/var/www/help.infinityhu.eu/media"

; --- Word-import és videófeltöltés miatt bőven kell ---
php_admin_value[upload_max_filesize] = 512M
php_admin_value[post_max_size]       = 512M
php_admin_value[memory_limit]        = 1024M
php_admin_value[max_execution_time]  = 600
php_admin_value[max_file_uploads]    = 60
php_admin_flag[display_errors]       = off
php_admin_value[expose_php]          = 0
```

```bash
sudo chmod 640 /etc/php/8.3/fpm/pool.d/help.conf     # a jelszavak miatt
sudo systemctl restart php8.3-fpm
```

### B5. nginx

Kész konfig: `deploy/help.infinityhu.eu.dinamikus.nginx.conf` — másold be, és igazítsd
benne a `fastcgi_pass` socketet a fenti poolra (`unix:/run/php/php-help.sock`).

A lényeg, ha kézzel írnád:

```nginx
server {
    listen 443 ssl;
    http2 on;                               # nginx 1.25.1 ota kulon direktiva
    server_name help.infinityhu.eu;

    root  /var/www/help.infinityhu.eu/site_dinamikus;
    index index.php;
    charset utf-8;
    server_tokens off;
    client_max_body_size 512M;              # a Word-import és a videók miatt

    ssl_certificate     /etc/letsencrypt/live/help.infinityhu.eu/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/help.infinityhu.eu/privkey.pem;

    # a lib/ mappa és a config.php SOHA nem kérhető le közvetlenül
    location ^~ /lib/           { deny all; }
    location = /config.php      { deny all; }

    # Képek és videók a media mappából, PHP megkerülésével.
    # A "^~" FONTOS: nélküle az alatta lévő reguláris kifejezéses szabály
    # nyerne, és a /media/*.png kérések 404-et kapnának.
    location ^~ /media/ {
        alias /var/www/help.infinityhu.eu/media/;
        add_header Cache-Control "public, max-age=604800" always;
        try_files $uri =404;
    }

    # statikus eszközök
    location ~* \.(css|js|png|jpe?g|webp|gif|svg|ico)$ {
        add_header Cache-Control "public, max-age=604800" always;
        try_files $uri =404;
    }

    # a beágyazható (iframe) nézet — ide írd be a valódi Infinity domaineket
    location ~ ^/(hu|en|de)/embed/ {
        add_header Content-Security-Policy "frame-ancestors https://*.infinityhu.eu" always;
        try_files $uri /index.php$is_args$args;
    }

    location / {
        add_header X-Frame-Options "DENY" always;
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php-help.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTPS on;
    }
}

server {
    listen 80;
    server_name help.infinityhu.eu;
    return 301 https://$host$request_uri;
}
```

> **Figyelem a `SCRIPT_FILENAME` sorra:** a súgó minden útvonalat az `index.php`-val szolgál
> ki, **kivéve** az `/admin.php`-t — ezért kell az adminnak külön `location` blokk. A csomagban
> lévő `deploy/help.infinityhu.eu.dinamikus.nginx.conf` **ezt már tartalmazza**; ha kézzel írod
> a konfigot, ne felejtsd ki:
>
> ```nginx
> location = /admin.php {
>     include fastcgi_params;
>     fastcgi_pass unix:/run/php/php-help.sock;
>     fastcgi_param SCRIPT_FILENAME $document_root/admin.php;
>     fastcgi_param HTTPS on;
> }
> ```
>
> A mellékelt konfig szintaxisát `nginx -t`-vel ellenőriztük.

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d help.infinityhu.eu        # ha még nincs tanúsítvány
```

---

## 1. Első belépés

Nyisd meg: **https://help.infinityhu.eu/admin.php**

| | |
|---|---|
| felhasználó | `admin` |
| jelszó | `12345678` |

**Első dolog: cseréld le a jelszót** — *Beállítások → Saját jelszó*. (A rendszer nem
kényszeríti ki, de a kezdőjelszó nyilvános, ebben a dokumentumban is benne van.)

Utána hozz létre saját fiókokat a *Felhasználók* fülön. Három szerepkör van:

| | mit érhet el |
|---|---|
| `admin` | mindent, beleértve a felhasználókat és a beállításokat |
| `editor` | fejezetek, modulok, Word-import, fordítás, képernyők, képek, export |
| `translator` | csak a Fordítás fül |

Az `/admin.php` címre **a nyilvános súgóból nem vezet link** — a címet ismerni kell.
Ha extra védelem kell, tehető elé nginx `auth_basic`, vagy IP-korlátozás:

```nginx
location = /admin.php {
    allow 10.0.0.0/8;
    deny all;
    # ... a fenti fastcgi blokk ...
}
```

---

## 2. Ellenőrző lista telepítés után

```bash
curl -I https://help.infinityhu.eu/hu/                      # 200
curl -I https://help.infinityhu.eu/hu/5-4-kintlevoseg-kezeles   # 200
curl -I https://help.infinityhu.eu/admin.php                # 200
curl -I https://help.infinityhu.eu/lib/util.php             # 403 vagy 404  ← FONTOS
curl -I https://help.infinityhu.eu/config.php               # 403 vagy 404  ← FONTOS
curl -s  "https://help.infinityhu.eu/search?lang=hu&q=szamla" | head -c 200   # JSON találatok
```

A böngészőben nézd meg:

- [ ] a kezdőlapon 17 modul-csempe látszik
- [ ] egy fejezetben megjelennek a képernyőképek
- [ ] a képre kattintva megnyílik a nagyító
- [ ] a HU / EN / DE váltás működik
- [ ] az adminban a *Export* fülön a **Word (.docx)** letöltődik, és Wordben megnyílik
- [ ] a **PDF (nyomtatás)** gomb új lapot nyit és felhozza a nyomtatás ablakot
- [ ] az adminban a *Képek, videók* fülön a feltöltés működik (ha nem: a `media` mappa jogai)

---

## 3. Üzemeltetés

### Mentés

Az adatbázis és a `media` mappa együtt adja a teljes tartalmat:

```bash
# napi mentés (a tarolt eljarasokkal es a nezetekkel egyutt)
mariadb-dump --single-transaction --routines --events \
  help_infinityhu | gzip > /backup/help_$(date +%F).sql.gz
tar czf /backup/help_media_$(date +%F).tar.gz -C /var/www/help.infinityhu.eu media
```

Visszaállítás:

```bash
zcat /backup/help_2026-09-15.sql.gz | mariadb help_infinityhu
tar xzf /backup/help_media_2026-09-15.tar.gz -C /var/www/help.infinityhu.eu
```

### Frissítés (új kódverzió)

```bash
sudo cp -r site_dinamikus/* /var/www/help.infinityhu.eu/site_dinamikus/
# ha jött új db/NN_*.sql, azt is futtasd le, sorrendben
sudo systemctl reload php8.3-fpm
```

A `media` mappához és az adatbázishoz frissítéskor nem kell hozzányúlni.

### Gépi fordítás (nem kötelező)

A Fordítás fül kulcs nélkül is használható (kézi fordítás). Ha kell gépi nyersfordítás,
két út van:

1. **DeepL vagy Google kulcs** — *Beállítások → Gépi fordítás*.
2. **Saját, helyben futó fordító**, kulcs nélkül és külső hálózat nélkül:

```bash
docker compose --profile mt up -d      # LibreTranslate, hu/en/de modellekkel
```

majd *Beállítások → Gépi fordítás*: szolgáltató = LibreTranslate, végpont =
`http://libretranslate:5000` (Docker) vagy `http://127.0.0.1:5050` (kívülről).
Az első indulás pár perc, letölti a nyelvi modelleket (~1–2 GB).

### Naplók

| | |
|---|---|
| nginx | `/var/log/nginx/help.infinityhu.eu.{access,error}.log` |
| PHP | a PHP-FPM error logja |
| alkalmazás | az adminban *Áttekintés → Napló* (ki mit csinált), illetve a `help_audit` tábla |

---

## 4. Ha valami nem megy

| tünet | ok / megoldás |
|---|---|
| „A súgó átmenetileg nem érhető el" | az `index.php` nem tud csatlakozni. Ellenőrizd a `HELP_DB_*` értékeket a PHP-FPM poolban, és hogy a `help_ro` tud-e belépni: `mariadb -h 127.0.0.1 -u help_ro -p help_infinityhu` |
| Az adminban „Az adatbázis nem érhető el" | ugyanez, de a `HELP_DB_ADMIN_USER` / `HELP_DB_ADMIN_PASS` párossal (`help_rw`) |
| Minden oldal 404 | hiányzik a `try_files ... /index.php` szabály, vagy rossz a `root` |
| Az `/admin.php` a nyilvános súgót adja | a `SCRIPT_FILENAME` fixen az `index.php`-ra mutat — kell a külön `location = /admin.php` blokk (lásd B5) |
| Kép/videó feltöltés nem sikerül | a `media` mappa nem írható a `www-data` számára, vagy kicsi az `upload_max_filesize` / `client_max_body_size` |
| Word-import: „A fájl nem nyitható meg .docx-ként" | hiányzik a PHP `zip` kiterjesztés (`php -m \| grep zip`) |
| Word-import nagy fájlnál megáll | kicsi a `max_allowed_packet` a MariaDB-ben (lásd B2) |
| Az ékezetes keresés nem talál | rossz a rendezés. `SHOW CREATE TABLE help_article` — `utf8mb4_uca1400_ai_ci` kell, nem `..._hungarian_...` és nem `..._bin` |
| A rövid szavakra nincs találat | `innodb_ft_min_token_size` = 2, és utána **újra kell építeni** a FULLTEXT indexet (lásd B2) |
| Word-import 0 fejezetet talál | a dokumentumban nem címsorstílusok tagolnak. Címsor 1 = modul, Címsor 2 = fejezet, Címsor 3–4 = szakasz. A Word automatikus címsor-számozását a rendszer felismeri. |
| A képek nem látszanak a fejezetekben | a `/media/` location hiányzik vagy rossz az `alias` (a végén a `/` is számít) |
| Bejelentkezés után rögtön kidob | a munkamenet-süti `Secure`, de a kapcsolat HTTP. Vagy tedd HTTPS-re, vagy add át a `fastcgi_param HTTPS on;` sort proxy mögött |
| „Túl sok sikertelen próbálkozás" | 5 hibás jelszó után 10 perc zárolás. Feloldás: `mariadb help_infinityhu -e "UPDATE help_user SET failed_logins=0, locked_until=NULL WHERE username='admin';"` |
| A PDF-letöltés gomb nem látszik | nincs telepítve a WeasyPrint. `apt-get install -y weasyprint`, majd PHP-FPM újraindítás |

---

## 5. Biztonsági ellenőrzés élesítés előtt

- [ ] az `admin` jelszava lecserélve
- [ ] a `help_ro` és `help_rw` **saját, erős** jelszót kapott (nem a Dockerben lévő fejlesztőit)
- [ ] a `db/05_jogosultsagok.sql` **nem** futott le éles adatbázison
- [ ] a `/lib/` és a `/config.php` nem kérhető le (403/404)
- [ ] a PHP-FPM pool fájl jogosultsága 640, mert jelszavakat tartalmaz
- [ ] HTTPS él, a HTTP átirányít
- [ ] `display_errors = off`
- [ ] a `media` mappában nincs PHP-futtatás engedélyezve (a fenti nginx konfig statikusan szolgálja ki)
- [ ] napi adatbázis-mentés beállítva (`--routines`-szal, hogy a tárolt eljárások is benne legyenek)

---


*Kérdés esetén a `README.md` írja le részletesen, mit tud a rendszer és hogyan működik
a szerkesztő, a Word-import, a fordítás és az export.*
