#!/usr/bin/env bash
# ============================================================
# Frissito figyelo - a GAZDAGEPEN fut, percenkent.
#
# MIERT IGY:
#   A sugo admin felulete a PHP kontenerben fut. A "git pull" es a
#   "docker compose up" viszont a gazdagepen tortenik. A kezenfekvo megoldas
#   az lenne, hogy becsatoljuk a Docker socketet a kontenerbe - DE AZ ROOT
#   JOGOT AD a gazdagepen, es onnantol a sugo admin felulete az egesz
#   szerverhez hozzaferest adna.
#
#   Ezert a web NEM FUTTAT SEMMIT. Csak ir egy kerelem-fajlt. Ez a szkript
#   figyeli, es o futtat - a kontenernek semmilyen emelt joga nincs.
#   A legrosszabb, ami egy feltort adminbol kovetkezhet: valaki elindit egy
#   frissitest. Nem az, hogy parancsot futtat a szerveren.
#
# TELEPITES (egyszer, a gazdagepen):
#   crontab -e
#   */1 * * * * /var/www/help.infinityhu.eu/tools/frissito-figyelo.sh
#
#   A frissites/ mappanak irhatonak kell lennie a kontener PHP
#   felhasznalojanak (jellemzoen uid 33) ES ennek a szkriptnek is:
#   sudo chown -R 33:33 frissites && sudo chmod 775 frissites
# ============================================================
set -uo pipefail

GYOKER="$(cd "$(dirname "$0")/.." && pwd)"
MAPPA="$GYOKER/frissites"
NAPLO="$MAPPA/naplo.txt"
ALLAPOT="$MAPPA/allapot.json"
VERZIO="$MAPPA/verzio.json"
KERELEM="$MAPPA/kerelem.json"
ZAR="$MAPPA/.zar"

mkdir -p "$MAPPA"
cd "$GYOKER" || exit 1

# --- csak egy futhat egyszerre -------------------------------------------
# mkdir-rel zarunk, nem flock-kal: a flock Linux-only, igy viszont a szkript
# macOS-en (fejlesztoi gepen) is tesztelheto. A mkdir atomi muvelet.
if ! mkdir "$ZAR" 2>/dev/null; then
  # ha egy ottragadt zar regebbi fel oranal, feltorjuk
  if [ -d "$ZAR" ] && [ -n "$(find "$ZAR" -maxdepth 0 -mmin +30 2>/dev/null)" ]; then
    rmdir "$ZAR" 2>/dev/null && mkdir "$ZAR" 2>/dev/null || exit 0
  else
    exit 0
  fi
fi
trap 'rmdir "$ZAR" 2>/dev/null' EXIT

json_mezo() { # fajl kulcs -> ertek (kulso fuggoseg nelkul)
  sed -n 's/.*"'"$2"'"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$1" 2>/dev/null | head -1
}

ir_allapot() {
  cat > "$ALLAPOT" <<EOF
{"allapot":"$1","muvelet":"${2:-}","uzenet":"${3:-}","ido":"$(date -Is)"}
EOF
  chmod 664 "$ALLAPOT" 2>/dev/null || true
}

# --- 1. verzio-informacio frissitese (minden futaskor) --------------------
git fetch --quiet origin 2>/dev/null
MOST=$(git rev-parse --short HEAD 2>/dev/null)
MOST_DATUM=$(git log -1 --format=%cI 2>/dev/null)
MOST_UZENET=$(git log -1 --format=%s 2>/dev/null)
AG=$(git rev-parse --abbrev-ref HEAD 2>/dev/null)
HATRA=$(git rev-list --count "HEAD..origin/$AG" 2>/dev/null || echo 0)

# A commit-uzenetekben lehet idezojel es visszaper - ezeket escapelni kell,
# kulonben a verzio.json ervenytelen lesz, es a Frissites lap nem tud
# megjelenni. A kulcsneveket NEM szabad escapelni, ezert mezonkent dolgozunk.
json_ertek() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\n\r\t'; }

elerheto_lista() {
  local elso=1 c u d ki=''
  while IFS=$'\x1f' read -r c u d; do
    [ -n "$c" ] || continue
    [ $elso -eq 1 ] || ki="$ki,"
    elso=0
    ki="$ki{\"c\":\"$(json_ertek "$c")\",\"u\":\"$(json_ertek "$u")\",\"d\":\"$(json_ertek "$d")\"}"
  done
  printf '%s' "$ki"
}

{
  printf '{"commit":"%s","datum":"%s","uzenet":"%s","ag":"%s","elerheto_db":%s,"elerheto":[' \
         "$(json_ertek "$MOST")" "$(json_ertek "$MOST_DATUM")" \
         "$(json_ertek "$MOST_UZENET")" "$(json_ertek "$AG")" "${HATRA:-0}"
  if [ "${HATRA:-0}" -gt 0 ]; then
    git log --reverse --format="%h$(printf '\x1f')%s$(printf '\x1f')%cI" "HEAD..origin/$AG" 2>/dev/null \
      | elerheto_lista
  fi
  printf ']}'
} > "$VERZIO"
chmod 664 "$VERZIO" 2>/dev/null || true

# --- 2. van-e kerelem? ---------------------------------------------------
[ -f "$KERELEM" ] || exit 0

MUVELET=$(json_mezo "$KERELEM" muvelet)
KI=$(json_mezo "$KERELEM" ki)
CEL=$(json_mezo "$KERELEM" cel)
rm -f "$KERELEM"           # egyszer fusson le, akkor is, ha elszall

case "$MUVELET" in
  frissites|visszaallitas) ;;
  *) ir_allapot hiba "$MUVELET" "Ismeretlen muvelet."; exit 0 ;;
esac

: > "$NAPLO"; chmod 664 "$NAPLO" 2>/dev/null || true
exec > >(tee -a "$NAPLO") 2>&1
ir_allapot fut "$MUVELET" "Folyamatban."

echo "=== $(date '+%Y-%m-%d %H:%M:%S')  $MUVELET  (kerte: ${KI:-?}) ==="
echo "Jelenlegi valtozat: $MOST  ($MOST_UZENET)"
echo

hiba() { echo; echo "!!! MEGSZAKADT: $1"; ir_allapot hiba "$MUVELET" "$1"; exit 1; }

# --- 3. adatbazis-mentes -------------------------------------------------
echo "--- Biztonsagi mentes az adatbazisrol ---"
IDO=$(date +%Y%m%d-%H%M%S)
MENTESEK="$MAPPA/mentesek"
mkdir -p "$MENTESEK"
MENTES="$MENTESEK/mentes-$IDO.sql.gz"
if docker compose exec -T db sh -c 'mariadb-dump --single-transaction --routines --triggers -u root -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"' 2>/dev/null | gzip > "$MENTES"; then
  echo "    kesz: $(basename "$MENTES")  ($(du -h "$MENTES" | cut -f1))"
else
  rm -f "$MENTES"
  hiba "Az adatbazis-mentes nem sikerult. Frissites nelkul kileptunk."
fi

# A visszaallas celpontjat MOST olvassuk ki - azutan irjuk felul. Forditva
# a "visszaallitas cel nelkul" oda allna vissza, ahol eppen vagyunk.
ELOZO=$(cat "$MAPPA/elozo-commit.txt" 2>/dev/null)
echo "$MOST" > "$MAPPA/elozo-commit.txt"
chmod 664 "$MAPPA/elozo-commit.txt" 2>/dev/null || true

# --- 4. kod frissitese ---------------------------------------------------
echo
if [ "$MUVELET" = "frissites" ]; then
  # Egy korabbi visszaallitas utan a HEAD levalt (detached), ilyenkor a
  # git pull elszall. Eloszor visszalepunk az agra.
  if [ "$AG" = "HEAD" ]; then
    AG=$(git symbolic-ref --quiet --short refs/remotes/origin/HEAD 2>/dev/null | sed 's|^origin/||')
    AG=${AG:-main}
    echo "--- Levalt HEAD, visszalepes az agra: $AG ---"
    git checkout "$AG" || hiba "Nem sikerult visszalepni a(z) $AG agra."
  fi
  echo "--- Kod letoltese ---"
  git pull --ff-only || hiba "A git pull nem sikerult (helyi modositas lehet a fakban)."
else
  CEL=${CEL:-$ELOZO}
  [ -n "$CEL" ] || hiba "Nincs megadva, melyik valtozatra alljunk vissza."
  echo "--- Visszaallas erre: $CEL ---"
  git checkout "$CEL" || hiba "A visszaallas nem sikerult."
fi

# --- 5. ujraepites -------------------------------------------------------
echo
echo "--- Kontenerek ujraepitese ---"
docker compose up -d --build || hiba "A docker compose up nem sikerult."

echo
echo "--- Ellenorzes ---"
docker compose ps --format '    {{.Service}}  {{.Status}}' 2>/dev/null

# A regi mentesek elfogyasztjak a lemezt - a legutobbi 10-et tartjuk meg.
# (A szerveren 20 GB van osszesen, egy dump tobb szaz MB is lehet.)
ls -1t "$MENTESEK"/mentes-*.sql.gz 2>/dev/null | tail -n +11 | while read -r regi; do
  echo "    regi mentes torolve: $(basename "$regi")"
  rm -f "$regi"
done

UJ=$(git rev-parse --short HEAD)
echo
echo "=== KESZ.  $MOST -> $UJ ==="
echo "Mentes: $(basename "$MENTES")"
ir_allapot kesz "$MUVELET" "$MOST -> $UJ"
