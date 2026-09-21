#!/usr/bin/env bash
# ============================================================
# Infinity Súgó — frissítés a 2026-09-18-i változatra
#
#   *** A SÚGÓ SZÖVEGEIHEZ NEM NYÚL. ***
#
# Amit csinál:
#   1. biztonsági mentést készít az adatbázisról
#   2. lefuttatja a szerkezeti frissítést (új oszlopok, táblák, nézetek)
#   3. betölti a kezelőfelület angol/német feliratait
#   4. lecseréli az alkalmazás fájljait (a config.php-t és a media/-t NEM)
#
# Bármikor megszakítható: a 2. lépés többször is futtatható, a 3. pedig
# csak azt írja be, ami még nincs meg.
#
# Használat:
#   ./frissites.sh /var/www/help.infinityhu.eu
#
# Ha az adatbázis eléréséhez más adatok kellenek, mint amit a célkönyvtár
# config.php-ja tartalmaz, add meg őket környezeti változóban:
#   DB_NEV=help DB_USER=root DB_JELSZO=titok ./frissites.sh /var/www/help
# ============================================================
set -euo pipefail

CEL="${1:-}"
if [ -z "$CEL" ]; then
  echo "Használat: $0 <a súgó könyvtára>"
  echo "Példa:     $0 /var/www/help.infinityhu.eu"
  exit 1
fi
if [ ! -d "$CEL" ]; then
  echo "HIBA: nincs ilyen könyvtár: $CEL"
  exit 1
fi

FORRAS="$(cd "$(dirname "$0")" && pwd)"
CONFIG="$CEL/site_dinamikus/config.php"

if [ ! -f "$CONFIG" ]; then
  echo "HIBA: nem találom a config.php-t itt: $CONFIG"
  echo "Biztosan a súgó könyvtárát adtad meg?"
  exit 1
fi

# --- adatbázis-adatok: környezeti változó, különben a config.php-ból -------
olvas_configbol() {
  php -r '
    $c = require $argv[1];
    $dsn = $c["admin_dsn"] ?? $c["dsn"] ?? "";
    preg_match("/dbname=([^;]+)/", $dsn, $m);
    echo ($m[1] ?? ""), "\n";
    echo ($c["admin_user"] ?? $c["user"] ?? ""), "\n";
    echo ($c["admin_pass"] ?? $c["pass"] ?? ""), "\n";
    preg_match("/host=([^;]+)/", $dsn, $h);
    echo ($h[1] ?? "localhost"), "\n";
  ' "$CONFIG"
}

if [ -z "${DB_NEV:-}" ]; then
  mapfile -t _db < <(olvas_configbol)
  DB_NEV="${DB_NEV:-${_db[0]:-}}"
  DB_USER="${DB_USER:-${_db[1]:-}}"
  DB_JELSZO="${DB_JELSZO:-${_db[2]:-}}"
  DB_HOST="${DB_HOST:-${_db[3]:-localhost}}"
fi
DB_HOST="${DB_HOST:-localhost}"

if [ -z "${DB_NEV:-}" ]; then
  echo "HIBA: nem sikerült kiolvasni az adatbázis nevét a config.php-ból."
  echo "Add meg kézzel: DB_NEV=... DB_USER=... DB_JELSZO=... $0 $CEL"
  exit 1
fi

MYSQL=(mysql -h "$DB_HOST" -u "$DB_USER")
DUMP=(mysqldump -h "$DB_HOST" -u "$DB_USER")
if [ -n "${DB_JELSZO:-}" ]; then
  MYSQL+=(-p"$DB_JELSZO")
  DUMP+=(-p"$DB_JELSZO")
fi

echo "============================================================"
echo " Infinity Súgó frissítés"
echo "   célkönyvtár : $CEL"
echo "   adatbázis   : $DB_NEV @ $DB_HOST"
echo "============================================================"
echo
read -r -p "Indulhat? [i/N] " valasz
case "$valasz" in [iIyY]*) ;; *) echo "Megszakítva."; exit 0 ;; esac

# --- 1. biztonsági mentés -------------------------------------------------
IDO="$(date +%Y%m%d-%H%M%S)"
MENTES="$CEL/mentes-$IDO.sql"
echo
echo "[1/4] Biztonsági mentés: $MENTES"
"${DUMP[@]}" --single-transaction --routines --triggers "$DB_NEV" > "$MENTES"
gzip "$MENTES"
echo "      kész ($(du -h "$MENTES.gz" | cut -f1))"

# --- 2. szerkezeti frissítés ---------------------------------------------
echo "[2/4] Adatbázis szerkezete…"
"${MYSQL[@]}" "$DB_NEV" < "$FORRAS/adatbazis/01_sema_frissites.sql"
echo "      kész"

# --- 3. felületszövegek ---------------------------------------------------
echo "[3/4] A kezelőfelület angol/német feliratai…"
"${MYSQL[@]}" "$DB_NEV" < "$FORRAS/adatbazis/02_felulet_forditasok.sql"
DB_UI=$("${MYSQL[@]}" -N -B -e "SELECT COUNT(*) FROM help_ui;" "$DB_NEV")
echo "      kész ($DB_UI felirat a táblában)"

# --- 4. alkalmazás fájljai ------------------------------------------------
echo "[4/4] Alkalmazás fájljai…"
REGI="$CEL/site_dinamikus-regi-$IDO"
cp -a "$CEL/site_dinamikus" "$REGI"
echo "      a régi változat itt maradt: $REGI"

# a config.php és a media/ érintetlen marad
rsync -a --exclude 'config.php' --exclude 'media/' \
      "$FORRAS/site_dinamikus/" "$CEL/site_dinamikus/"
rm -f "$CEL/site_dinamikus/config.php.minta"

# a jogosultságokat a régi könyvtárról örököltetjük
TULAJ="$(stat -c '%U:%G' "$REGI" 2>/dev/null || stat -f '%Su:%Sg' "$REGI")"
chown -R "$TULAJ" "$CEL/site_dinamikus" 2>/dev/null || true
echo "      kész"

echo
echo "============================================================"
echo " KÉSZ."
echo
echo " Mentés:        $MENTES.gz"
echo " Régi fájlok:   $REGI"
echo
echo " Ellenőrizd a böngészőben:"
echo "   - a nyilvános súgó betölt-e (mindhárom nyelven)"
echo "   - az admin felületre be tudsz-e lépni"
echo "   - a Fejezetek fülön ott vannak-e a fejezetek"
echo
echo " Ha baj van, visszaállítás:"
echo "   rm -rf $CEL/site_dinamikus && mv $REGI $CEL/site_dinamikus"
echo "   gunzip -c $MENTES.gz | mysql -u $DB_USER -p $DB_NEV"
echo "============================================================"
