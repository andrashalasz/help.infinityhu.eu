#!/usr/bin/env bash
# ============================================================================
# Infinity Sugo - HELYI TESZT telepito
#
# Ez PONTOSAN azt a recepet automatizalja, amivel a fejlesztes soran
# valoban felallitottunk egy futo peldanyt: Yii2 keret + HtmlPurifier
# kozvetlenul GitHub-rol (Composer/Packagist nelkul), valodi PostgreSQL,
# es a mellekelt HelpController/modellek/nezetek VALTOZTATAS NELKUL.
#
# NEM ez a modszer az eles Infinity-be valo beepitesre! Ott mar fut a
# Yii2 (Composer-rel telepitve) - oda a fejlesztoi_csomag.zip fajljait
# egyszeruen bemasoljak (lasd OLVASD_EL.md). Ez a script kizarolag arra
# valo, hogy EGY KULON, ONALLO TESZTPELDANYON lasd mukodni az egeszet,
# mielott az eles Infinitybe kerulne.
#
# Hasznalat:
#   chmod +x helyi_teszt_inditasa.sh
#   ./helyi_teszt_inditasa.sh
#
# Elofeltetel: PHP 8.1+ (mbstring, pgsql kiterjesztessel), PostgreSQL,
# git. Ubuntu/Debian-on:
#   sudo apt-get install -y php-cli php-mbstring php-pgsql postgresql git
# ============================================================================
set -e
# A script sajat mappaja (03_helyi_teszt) es a csomag gyokere (egy szinttel
# feljebb, ahol a 01_infinity_fo_rendszerbe testver-mappa is van).
SELF="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HERE="$(cd "$SELF/.." && pwd)"
DB=infinity_teszt
DBPASS=teszt123
PORT=8080

echo "== 1. PostgreSQL adatbazis =="
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='$DB'" | grep -q 1 \
  || sudo -u postgres psql -c "CREATE DATABASE $DB;"
sudo -u postgres psql -c "ALTER USER postgres PASSWORD '$DBPASS';" >/dev/null

echo "== 2. SQL betoltese (sema + tartalom mind a 3 nyelven) =="
sudo -u postgres psql -d "$DB" -f "$HERE/01_infinity_fo_rendszerbe/sql/help_articles_i18n.sql" >/dev/null
sudo -u postgres psql -d "$DB" -f "$HERE/01_infinity_fo_rendszerbe/sql/002_szerkeszto_migracio.sql" >/dev/null
sudo -u postgres psql -d "$DB" -f "$HERE/01_infinity_fo_rendszerbe/sql/003_changelog_migracio.sql" >/dev/null
sudo -u postgres psql -d "$DB" -c "UPDATE help_article SET body_html = replace(body_html, 'src=\"media/', 'src=\"/help/media/');" >/dev/null
echo "   -> $(sudo -u postgres psql -d "$DB" -tc 'SELECT count(*) FROM help_article') fejezet betoltve (109 x 3 nyelv)"

echo "== 3. Yii2 keret es HtmlPurifier letoltese (GitHub, Composer nelkul) =="
mkdir -p "$HERE/03_helyi_teszt/_vendor"
[ -d "$HERE/03_helyi_teszt/_vendor/yii2fw" ] || git clone --depth 1 -q https://github.com/yiisoft/yii2.git "$HERE/03_helyi_teszt/_vendor/yii2fw"
[ -d "$HERE/03_helyi_teszt/_vendor/htmlpurifier" ] || git clone --depth 1 -q https://github.com/ezyang/htmlpurifier.git "$HERE/03_helyi_teszt/_vendor/htmlpurifier"

echo "== 4. Sajat kod bekotese =="
rm -rf "$HERE/03_helyi_teszt/app"
mkdir -p "$HERE/03_helyi_teszt/app"/{controllers,models,views/help,views/layouts,components,assets,widgets,web/css,web/js,runtime,web/assets}
cp "$HERE/01_infinity_fo_rendszerbe/yii2/controllers/HelpController.php" "$HERE/03_helyi_teszt/app/controllers/"
cp "$HERE/01_infinity_fo_rendszerbe/yii2/models/"*.php "$HERE/03_helyi_teszt/app/models/"
cp "$HERE/01_infinity_fo_rendszerbe/yii2/components/HelpHtml.php" "$HERE/03_helyi_teszt/app/components/"
cp "$HERE/01_infinity_fo_rendszerbe/yii2/assets/"*.php "$HERE/03_helyi_teszt/app/assets/"
cp "$HERE/01_infinity_fo_rendszerbe/yii2/widgets/"*.php "$HERE/03_helyi_teszt/app/widgets/"
cp "$HERE/01_infinity_fo_rendszerbe/yii2/views/help/"*.php "$HERE/03_helyi_teszt/app/views/help/"
cp "$HERE/01_infinity_fo_rendszerbe/yii2/web/css/"*.css "$HERE/03_helyi_teszt/app/web/css/"
cp "$HERE/01_infinity_fo_rendszerbe/yii2/web/js/"*.js "$HERE/03_helyi_teszt/app/web/js/"
cp "$HERE/03_helyi_teszt/layout_main.php.reference" "$HERE/03_helyi_teszt/app/views/layouts/main.php"
ln -sfn "$HERE/01_infinity_fo_rendszerbe/media" "$HERE/03_helyi_teszt/media"

echo "== 5. Inditas =="
echo "   http://127.0.0.1:$PORT/help"
cd "$HERE/03_helyi_teszt"
php -S 127.0.0.1:$PORT router.php
