#!/usr/bin/env bash
# ============================================================
# A frissítőcsomag összeállítása — macOS-szemét nélkül.
#
# Miért kell külön szkript: a macOS tar alapból beleteszi a kiterjesztett
# attribútumokat (com.apple.provenance, com.apple.macl) pax fejlécként, és
# ettől a Linuxos GNU tar kicsomagoláskor ilyeneket panaszol:
#
#   tar: Ignoring unknown extended header keyword 'SCHILY.xattr.com.apple.provenance'
#
# A fájlokról letörölni nem elég (a másolás újra ráteszi), ezért a tar-nak
# mondjuk meg, hogy ne tárolja őket.
#
# Használat:  tools/csomagol.sh [nev]
# ============================================================
set -euo pipefail

GYOKER="$(cd "$(dirname "$0")/.." && pwd)"
NEV="${1:-infinity-sugo-frissites-$(date +%Y-%m-%d)}"
MUNKA="$(mktemp -d)"
trap 'rm -rf "$MUNKA"' EXIT

cp -a "$GYOKER/frissitocsomag" "$MUNKA/$NEV"
xattr -cr "$MUNKA/$NEV" 2>/dev/null || true
find "$MUNKA/$NEV" \( -name '.DS_Store' -o -name '._*' \) -delete

# --no-xattrs      : a com.apple.* attribútumok ne kerüljenek bele
# --format ustar   : a legrégebbi, mindenhol érthető formátum — pax fejléc
#                    nem is fér bele, tehát biztosan nem lesz benne
# --uid/--gid/...  : root:root, ne a fejlesztő gépének felhasználója
COPYFILE_DISABLE=1 tar \
  --no-xattrs --no-acls --no-fflags \
  --uid 0 --gid 0 --uname root --gname root \
  --format ustar \
  --exclude '.DS_Store' --exclude '._*' --exclude '__MACOSX' \
  -czf "$GYOKER/$NEV.tar.gz" -C "$MUNKA" "$NEV"

echo "kész: $GYOKER/$NEV.tar.gz  ($(du -h "$GYOKER/$NEV.tar.gz" | cut -f1))"

# --- ellenőrzés: maradt-e bármi macOS-specifikus -------------------------
SZEMET=$(gunzip -c "$GYOKER/$NEV.tar.gz" | strings \
         | grep -cE 'SCHILY|LIBARCHIVE|PaxHeader|com\.apple' || true)
if [ "$SZEMET" -gt 0 ]; then
  echo "FIGYELEM: $SZEMET macOS-nyom maradt a csomagban!"
  exit 1
fi
echo "ellenőrizve: nincs benne macOS-attribútum, pax fejléc és ._ fájl"
