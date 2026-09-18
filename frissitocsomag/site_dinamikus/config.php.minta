<?php
/**
 * config.php - a sugo sajat, kulon adatbazisahoz tartozo beallitasok.
 *
 * Ez SZANDEKOSAN nem ugyanaz az adatbazis, mint az Infinity fo rendszere -
 * igy a help.infinityhu.eu teljesen fuggetlenul frissul/all/esik, nem
 * oszt semmit az eles Infinity-vel.
 *
 * Adatbazis: MariaDB 11.8+.
 * A sema es a teljes tartalom a db/ mappa SQL fajljaibol epul fel:
 *   mariadb -u root -p help_infinityhu < db/01_sema.sql        (es a tobbi, sorrendben)
 *
 * KET adatbazis-felhasznalo van:
 *   - olvaso (help_ro): a nyilvanos oldal (index.php) ezt hasznalja
 *   - iro    (help_rw): az admin felulet (admin.php) ezt hasznalja
 * Eles kornyezetben a jelszavakat KORNYEZETI VALTOZOKENT add at, ne ird
 * bele ebbe a fajlba.
 */
return [
    // --- nyilvanos oldal: csak olvas ---
    'dsn'  => getenv('HELP_DB_DSN')  ?: 'mysql:host=127.0.0.1;port=3306;dbname=help_infinityhu;charset=utf8mb4',
    'user' => getenv('HELP_DB_USER') ?: 'help_ro',
    'pass' => getenv('HELP_DB_PASS') ?: 'change-me',

    // --- admin felulet: ir is ---
    'admin_dsn'  => getenv('HELP_DB_ADMIN_DSN')  ?: (getenv('HELP_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=help_infinityhu;charset=utf8mb4'),
    'admin_user' => getenv('HELP_DB_ADMIN_USER') ?: 'help_rw',
    'admin_pass' => getenv('HELP_DB_ADMIN_PASS') ?: 'change-me',

    // --- kepek a lemezen (ide kerulnek a Word-bol kibontott kepernyokepek) ---
    'media_dir' => getenv('HELP_MEDIA_DIR') ?: '/srv/media',

    // --- gepi fordito (opcionalis) ---
    // provider: none | deepl | libre | google
    // Ha ures, az adatbazis help_setting tablajabol olvassuk.
    'mt_provider' => getenv('HELP_MT_PROVIDER') ?: '',
    'mt_endpoint' => getenv('HELP_MT_ENDPOINT') ?: '',
    'mt_key'      => getenv('HELP_MT_KEY')      ?: '',

    // --- admin felulet ---
    'session_name'     => 'help_admin',
    'session_lifetime' => 8 * 3600,
];
