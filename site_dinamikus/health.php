<?php
/**
 * health.php - eletjel az eles frissitonek (deploy/sugo-frissito.py).
 *
 * 200 csak akkor, ha a PHP fut, az adatbazis valaszol, es a sugo
 * nelkulozhetetlen tablai megvannak es nem uresek. Egy elrontott
 * adatbazis-modositas (eltunt vagy kiurult tabla) igy 503-at ad, es a frissito
 * visszaall.
 *
 * Verziot, utvonalat, hibauzenetet szandekosan NEM ad ki: barki elerheti.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$valasz = static function (int $kod, array $adat): void {
    http_response_code($kod);
    echo json_encode($adat);
    exit;
};

try {
    $cfg = require __DIR__ . '/config.php';
    require_once __DIR__ . '/lib/util.php';
    $t0 = microtime(true);
    $db = help_db($cfg);
    $db->query('SELECT 1')->fetchColumn();
    $ms = (int)round((microtime(true) - $t0) * 1000);

    // A sugo nem mukodik, ha ezek barmelyike hianyzik vagy ures.
    foreach (['help_module', 'help_article'] as $tabla) {
        if ((int)$db->query("SELECT COUNT(*) FROM {$tabla}")->fetchColumn() < 1) {
            $valasz(503, ['ok' => false, 'db' => true, 'schema' => false]);
        }
    }
    // A felhasznalok tablaja az admin belepeshez kell (lehet ures egy friss telepitesen).
    $db->query('SELECT 1 FROM help_user LIMIT 1')->fetchAll();

    $valasz(200, ['ok' => true, 'db' => true, 'schema' => true, 'dbLatencyMs' => $ms]);
} catch (Throwable $e) {
    $valasz(503, ['ok' => false, 'db' => false, 'schema' => false]);
}
