<?php
/**
 * Router a PHP beepitett dev-szerverehez: ha a keresett URL egy valodi
 * statikus fajlnak felel meg (css/js/kep), azt kozvetlenul kiszolgaljuk;
 * minden mas kerest az index.php (Yii2 app) kap meg.
 *
 * Eles kornyezetben ez nem kell - ott Apache/nginx szolgalja ki a
 * /web/css, /web/js utvonalakat statikusan, a PHP beepitett szervere
 * csak ehhez a helyi teszthez kellett.
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

$map = [
    '/css/' => __DIR__ . '/app/web/css/',
    '/js/'  => __DIR__ . '/app/web/js/',
    '/help/media/' => __DIR__ . '/media/',
];
foreach ($map as $prefix => $dir) {
    if (strpos($uri, $prefix) === 0) {
        $file = $dir . substr($uri, strlen($prefix));
        if (is_file($file)) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            $types = ['css' => 'text/css', 'js' => 'application/javascript',
                      'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
            header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
            readfile($file);
            return true;
        }
    }
}
require __DIR__ . '/index.php';
