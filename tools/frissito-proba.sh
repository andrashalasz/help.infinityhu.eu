#!/usr/bin/env bash
# ============================================================================
# Az eles frissito kiprobalasa HELYBEN, szandekosan elrontott frissitesekkel.
#
#   tools/frissito-proba.sh <munkamappa>
#
# A munkamappaban ezeknek kell lenniuk (lasd docs/FRISSITO.md, "Kiprobalas"):
#   origin.git/   bare tarolo - ez jatssza a GitHubot (a "main" ag)
#   szerver/      klon, ebbol fut a sugo:  docker compose -p sugoproba up -d --build
#                 (.env: WEB_PORT=18080, DB_PORT=13307)
#   fejleszto/    klon, ebbe irjuk a probavaltozasokat, es innen pusholunk
# es futnia kell a frissitonek a szerver/ mappara:
#   SUGO_REPO_DIR=.../szerver SUGO_COMPOSE_PROJECT=sugoproba \
#   SUGO_HEALTH_URL=http://127.0.0.1:18080/health.php SUGO_TICK=3 \
#   SUGO_FETCH_INTERVAL=30 SUGO_HEALTH_TIMEOUT=60 python3 deploy/sugo-frissito.py run
#
# A forgatokonyvek sorban (mindegyik egy uj commit a fo agon):
#   1. bukó teszt            -> FAILED, a futo rendszerhez nem nyult
#   2. rossz eletjel         -> ROLLED_BACK (adatbazis nelkul)
#   3. elrontott adatbazis   -> ROLLED_BACK + adatbazis-visszatoltes, az adat megvan
#   4. hibas SQL             -> ROLLED_BACK + adatbazis-visszatoltes
#   5. kezzel modositott fajl a szerveren -> FAILED, megnevezi a fajlt
#   6. jo frissites          -> SUCCEEDED, az uj verzio fut
# ============================================================================
set -euo pipefail

P=$(cd "${1:?Add meg a munkamappat}" && pwd)
CSAT="$P/szerver/frissites"
URL=http://127.0.0.1:18080/health.php
DC=(docker compose --project-directory "$P/szerver" -p sugoproba)
jo=0; rossz=0

say()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
pass() { printf '   \033[32mRENDBEN\033[0m %s\n' "$*"; jo=$((jo+1)); }
fail() { printf '   \033[31mHIBA\033[0m    %s\n' "$*"; rossz=$((rossz+1)); }

json() { python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(eval(sys.argv[2], {}, {"d": d}))' "$CSAT/status.json" "$1"; }

sql() { "${DC[@]}" exec -T db sh -c 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb -uroot -N -B help_infinityhu' <<<"$1"; }

push() { # uzenet
  git -C "$P/fejleszto" add -A
  git -C "$P/fejleszto" commit -q -m "$1"
  git -C "$P/fejleszto" push -q origin main
  git -C "$P/fejleszto" rev-parse HEAD
}

# Megvarja, amig a frissito latja az uj verziot, kerest ir (pontosan ugy, mint a PHP),
# es megvarja a futas veget. Kiirja a vegallapotot.
telepit() { # commit
  local c=$1 i id
  for i in $(seq 1 60); do
    [ "$(json 'd["available"] and d["available"]["commit"]')" = "$c" ] && break
    sleep 2
  done
  [ "$(json 'd["available"] and d["available"]["commit"]')" = "$c" ] || { fail "a frissito nem latta meg: ${c:0:7}"; echo NONE; return; }
  id=$(python3 - "$CSAT" "$c" <<'EOF'
import json, os, sys, uuid
from datetime import datetime, timezone
d, c = sys.argv[1], sys.argv[2]
rid = str(uuid.uuid4())
body = json.dumps({"id": rid, "commit": c, "requestedAt": datetime.now(timezone.utc).isoformat(), "requestedBy": 1}) + "\n"
tmp = os.path.join(d, ".request-%s.tmp" % rid)
with open(tmp, "x") as f: f.write(body)
os.rename(tmp, os.path.join(d, "request.json"))
print(rid)
EOF
)
  for i in $(seq 1 400); do
    if [ "$(json 'd["run"] and d["run"]["id"]')" = "$id" ] && [ "$(json 'd["run"]["state"]')" != "RUNNING" ]; then break; fi
    sleep 3
  done
  json 'd["run"]["state"]'
}

fut() { "${DC[@]}" exec -T php sh -c 'echo "$APP_COMMIT"' 2>/dev/null; }
eletjel() { curl -s -o /dev/null -w '%{http_code}' "$URL"; }

ELOTTE_CIKK=$(sql 'SELECT COUNT(*) FROM help_article')
say "Kiindulas: fut=$(fut | cut -c1-7)  eletjel=$(eletjel)  fejezetek=$ELOTTE_CIKK"

# ---------------------------------------------------------------------------
say "1. Bukó teszt"
ELOZO=$(fut)
cat > "$P/fejleszto/tests/proba_bukas_test.php" <<'EOF'
<?php
t('szandekosan elrontott teszt (frissito-proba)', function () { eq(1, 2); });
EOF
C=$(push "proba: bukó teszt")
S=$(telepit "$C")
[ "$S" = FAILED ] && pass "FAILED" || fail "allapot: $S"
[ "$(json '[s["state"] for s in d["run"]["steps"] if s["key"]=="tests"][0]')" = FAILED ] && pass "a tesztek lepes bukott" || fail "nem a tesztek lepes bukott"
[ "$(fut)" = "$ELOZO" ] && pass "a regi verzio fut tovabb" || fail "mas fut: $(fut)"
[ "$(eletjel)" = 200 ] && pass "a sugo valaszol" || fail "eletjel: $(eletjel)"
git -C "$P/fejleszto" rm -q tests/proba_bukas_test.php

# ---------------------------------------------------------------------------
say "2. Rossz életjel az új verzióban"
cp "$P/fejleszto/site_dinamikus/health.php" "$P/health.php.jo"
printf '<?php\nhttp_response_code(503); echo "{\\"ok\\":false}";\n' > "$P/fejleszto/site_dinamikus/health.php"
C=$(push "proba: az eletjel mindig 503")
S=$(telepit "$C")
[ "$S" = ROLLED_BACK ] && pass "ROLLED_BACK" || fail "allapot: $S"
[ "$(fut)" = "$ELOZO" ] && pass "az elozo verzio fut" || fail "mas fut: $(fut)"
[ "$(git -C "$P/szerver" rev-parse HEAD)" = "$ELOZO" ] && pass "a forras is visszaallt" || fail "a forras: $(git -C "$P/szerver" rev-parse --short HEAD)"
[ "$(eletjel)" = 200 ] && pass "a sugo valaszol" || fail "eletjel: $(eletjel)"
cp "$P/health.php.jo" "$P/fejleszto/site_dinamikus/health.php"

# ---------------------------------------------------------------------------
say "3. Elrontott adatbázis-módosítás (eltűnik a fejezetek táblája)"
echo 'RENAME TABLE help_article TO help_article_elrontva;' > "$P/fejleszto/db/migracio/99_proba.sql"
C=$(push "proba: a migracio atnevezi a help_article tablat")
S=$(telepit "$C")
[ "$S" = ROLLED_BACK ] && pass "ROLLED_BACK" || fail "allapot: $S"
[ "$(json '[s["state"] for s in d["run"]["steps"] if s["key"]=="rollback_db"][0]')" = DONE ] && pass "az adatbazis visszatoltve" || fail "nem volt adatbazis-visszatoltes"
[ "$(sql 'SELECT COUNT(*) FROM help_article')" = "$ELOTTE_CIKK" ] && pass "mind a $ELOTTE_CIKK fejezet megvan" || fail "fejezetek: $(sql 'SELECT COUNT(*) FROM help_article' 2>&1)"
[ "$(fut)" = "$ELOZO" ] && pass "az elozo verzio fut" || fail "mas fut: $(fut)"
[ "$(eletjel)" = 200 ] && pass "a sugo valaszol" || fail "eletjel: $(eletjel)"

# ---------------------------------------------------------------------------
say "4. Hibás SQL az adatbázis-módosításban"
echo 'ALTER TABLE help_nincs_ilyen ADD COLUMN x INT;' > "$P/fejleszto/db/migracio/99_proba.sql"
C=$(push "proba: hibas SQL a migracioban")
S=$(telepit "$C")
[ "$S" = ROLLED_BACK ] && pass "ROLLED_BACK" || fail "allapot: $S"
[ "$(json '[s["state"] for s in d["run"]["steps"] if s["key"]=="migrate"][0]')" = FAILED ] && pass "a migracio lepes bukott" || fail "nem a migracio bukott"
[ "$(sql 'SELECT COUNT(*) FROM help_article')" = "$ELOTTE_CIKK" ] && pass "az adat megvan" || fail "adat"
[ "$(eletjel)" = 200 ] && pass "a sugo valaszol" || fail "eletjel: $(eletjel)"
git -C "$P/fejleszto" rm -q db/migracio/99_proba.sql

# ---------------------------------------------------------------------------
say "5. Kézzel módosított fájl a szerveren"
echo "kezi modositas" >> "$P/szerver/README.md"
echo "<!-- frissito-proba: jo valtozas -->" >> "$P/fejleszto/uj_fejezetek/README.md"
C=$(push "proba: jo valtozas")
S=$(telepit "$C")
[ "$S" = FAILED ] && pass "FAILED" || fail "allapot: $S"
json 'd["run"]["error"]' | grep -q 'README.md' && pass "megnevezi a fajlt (README.md)" || fail "nem nevezi meg: $(json 'd["run"]["error"]')"
[ "$(fut)" = "$ELOZO" ] && pass "a futo rendszerhez nem nyult" || fail "mas fut"
git -C "$P/szerver" checkout -q -- README.md

# ---------------------------------------------------------------------------
say "6. Jó frissítés (ugyanaz a verzió, a kézi módosítás visszavonva)"
S=$(telepit "$C")
[ "$S" = SUCCEEDED ] && pass "SUCCEEDED" || fail "allapot: $S"
[ "$(fut)" = "$C" ] && pass "az uj verzio fut (${C:0:7})" || fail "fut: $(fut)"
[ "$(git -C "$P/szerver" rev-parse HEAD)" = "$C" ] && pass "a forras az uj" || fail "forras"
[ "$(eletjel)" = 200 ] && pass "a sugo valaszol" || fail "eletjel: $(eletjel)"
ls "$P/szerver/frissito-mentesek/" | grep -q '^sugo-.*\.sql\.gz$' && pass "a mentesek megvannak" || fail "nincs mentes"

say "Összesen: $jo rendben, $rossz hiba"
[ "$rossz" -eq 0 ]
