<?php
/**
 * db.php - PDO kapcsolat a sugo SAJAT, kulon adatbazisahoz.
 *
 * Ez SZANDEKOSAN nem ugyanaz az adatbazis, mint az Infinity fo rendszere -
 * igy a help.infinityhu.eu teljesen fuggetlenul frissul/all/esik, nem
 * oszt semmit az eles Infinity-vel.
 *
 * A sema pontosan ugyanaz, mint a fo rendszerben (help_article, help_module,
 * help_screen_map, help_section) - ugyanazokkal az SQL fajlokkal toltodik fel:
 *   psql -d help_infinityhu -f help_articles_i18n.sql
 */
return [
    'dsn'  => getenv('HELP_DB_DSN')  ?: 'pgsql:host=127.0.0.1;port=5432;dbname=help_infinityhu',
    'user' => getenv('HELP_DB_USER') ?: 'help_ro',       // csak olvasasi jog eleg ide
    'pass' => getenv('HELP_DB_PASS') ?: 'change-me',
];
