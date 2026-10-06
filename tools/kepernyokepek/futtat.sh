#!/usr/bin/env bash
# ============================================================
# Kepernyokepek ujragyartasa a sugohoz.
#
#   ./tools/kepernyokepek/futtat.sh
#
# A kepek a tools/kepernyokepek/kimenet/ mappaba kerulnek. Onnan a sugo admin
# "Kepek, videok" fulen tolthetok fel, vagy masolhatok a media/ konyvtarba.
#
# A belepesi adatokat kornyezeti valtozobol veszi, hogy ne kerüljenek a repoba:
#   ERP_USER=test ERP_PASS=... ./tools/kepernyokepek/futtat.sh
#
# A Playwright kontenerben fut, igy nem kell semmit telepiteni a gepre.
# ============================================================
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
KIMENET="${KIMENET:-$HERE/kimenet}"
CACHE="${CACHE:-$HERE/.node_modules}"
KEP="mcr.microsoft.com/playwright:v1.49.0-jammy"

: "${ERP_USER:?Add meg: ERP_USER=...}"
: "${ERP_PASS:?Add meg: ERP_PASS=...}"

mkdir -p "$KIMENET" "$CACHE"

echo "Kepek ide kerulnek: $KIMENET"
echo

docker run --rm \
  -v "$HERE":/munka:ro \
  -v "$KIMENET":/kimenet \
  -v "$CACHE":/modulok \
  -e ERP_URL="${ERP_URL:-https://release.infinityhu.eu}" \
  -e ERP_USER="$ERP_USER" \
  -e ERP_PASS="$ERP_PASS" \
  -e KIMENET=/kimenet \
  -e CSAK="${CSAK:-}" \
  -e KIVEVE="${KIVEVE:-}" \
  -e CSERE="${CSERE:-}" \
  -e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
  -e PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
  -w /modulok \
  "$KEP" \
  sh -c '
    if [ ! -d node_modules/playwright ]; then
      echo "Playwright telepitese (egyszeri, utana gyorsitotarbol megy)..."
      npm init -y >/dev/null 2>&1
      npm install --no-audit --no-fund playwright@1.49.0 >/dev/null 2>&1
    fi
    cp /munka/keszit.mjs /modulok/keszit.mjs
    node /modulok/keszit.mjs
  '
