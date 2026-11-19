<?php
/**
 * A PHP-FPM worker-keszlet (docker/php/zz-pool.conf).
 *
 * A hivatalos php:8.3-fpm kep alapertelmezese pm.max_children = 5. A gepi
 * forditas percekig foglal egy workert, es ot ilyen mellett a nyilvanos sugo is
 * 503-at ad (2026-10-07-en negyszer allt le emiatt). A teszt azt rogziti, hogy
 * a megemelt beallitas megvan, a kepbe bekerul, es a php-fpm altal is
 * elfogadott: ha az ertekek ellentmondanak, a php-fpm el sem indul.
 */
declare(strict_types=1);

const POOL_CONF   = GYOKER . '/docker/php/zz-pool.conf';
const PHP_DOCKER  = GYOKER . '/docker/php/Dockerfile';

/** @return array<string,string> a [www] szakasz kulcsai */
function pool_ertekek(): array
{
    $ini = parse_ini_file(POOL_CONF, true, INI_SCANNER_RAW);
    ok(is_array($ini), 'a zz-pool.conf nem olvashato INI-kent');
    ok(isset($ini['www']) && is_array($ini['www']), 'nincs [www] szakasz');
    return $ini['www'];
}

t('PHP-FPM pool: dinamikus, es sokkal tobb worker, mint az alapertelmezett 5', function () {
    $p = pool_ertekek();
    eq('dynamic', $p['pm'] ?? null, 'pm');
    ok((int)($p['pm.max_children'] ?? 0) >= 10,
       'a pm.max_children legalabb 10 kell legyen (az alap 5: ot hosszu forditas mar mindent leallit)');
});

t('PHP-FPM pool: az ertekek nem mondanak ellent egymasnak (kulonben a php-fpm nem indul)', function () {
    $p = pool_ertekek();
    $max   = (int)$p['pm.max_children'];
    $start = (int)$p['pm.start_servers'];
    $minSp = (int)$p['pm.min_spare_servers'];
    $maxSp = (int)$p['pm.max_spare_servers'];
    ok($minSp >= 1, 'min_spare_servers >= 1');
    ok($minSp <= $start && $start <= $maxSp, 'a start_servers a min es a max spare kozott legyen');
    ok($maxSp <= $max, 'a max_spare_servers nem lehet tobb, mint a max_children');
});

t('PHP-FPM pool: a Dockerfile bemasolja, es a fajl a www.conf UTAN toltodik be', function () {
    $d = (string)file_get_contents(PHP_DOCKER);
    ok(preg_match('~^COPY\s+zz-pool\.conf\s+(/usr/local/etc/php-fpm\.d/\S+)\s*$~m', $d, $m) === 1,
       'a Dockerfile nem masolja be a zz-pool.conf-ot a php-fpm.d mappaba');
    // a php-fpm.d/*.conf beturendben toltodik; a kesobb olvasott felulirja az elozot
    ok(strcmp(basename($m[1]), 'www.conf') > 0, basename($m[1]) . ' a www.conf elott toltodne be, igy nem irna felul');
});

t('PHP-FPM pool: a fajl a build-kontextusban van', function () {
    $c = (string)file_get_contents(GYOKER . '/docker-compose.yml');
    ok(str_contains($c, 'build: ./docker/php'), 'a php szolgaltatas build-kontextusa nem a docker/php');
    ok(is_file(POOL_CONF), 'a docker/php/zz-pool.conf hianyzik');
});
