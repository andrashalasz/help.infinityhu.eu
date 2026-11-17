<?php
/**
 * Biztonsagi javitasok: a PDF-export kepforrasainak szurese, az nginx /media/
 * fejlece es az, hogy az adatbazis es a fordito ne legyen kivulrol elerheto.
 *
 * Az SVG, a "/media/../" es a belso cimre mutato kep mind olyan hiba, ami csak
 * akkor tunik fel, ha valaki ra is kerdez - ezert ezek itt rogzitve vannak, es
 * a frissito minden telepites elott lefuttatja oket.
 */
declare(strict_types=1);

require_once GYOKER . '/site_dinamikus/lib/util.php';

const MEDIA = '/srv/media';

function pdf_forras(string $tag): string
{
    return help_pdf_sources($tag, MEDIA);
}

t('PDF: a media-kep file:// utvonalra irodik, a mappa vegi / nem szamit', function () {
    eq('<img src="file:///srv/media/img_0123456789ab.png">',
       pdf_forras('<img src="/media/img_0123456789ab.png">'));
    eq('<img src="file:///srv/media/vid_0123456789ab.mp4">',
       help_pdf_sources('<img src="/media/vid_0123456789ab.mp4">', '/srv/media/'));
    eq("<img src=\"file:///srv/media/img_0123456789ab.png\">",
       pdf_forras("<img src='/media/img_0123456789ab.png'>"), 'aposztrofos');
});

t('PDF: a media mappabol kilepo hivatkozas kiurul', function () {
    foreach (['/media/../../etc/passwd', '/media/../config.php', '/media/img_0123456789ab.png/../../x',
              '/media/%2e%2e/x', '/media//etc/passwd', '/media/img_nemhex.png'] as $v) {
        eq('<img src="">', pdf_forras('<img src="' . $v . '">'), $v);
    }
});

t('PDF: file://, http(s) es belso cim nem toltodhet be', function () {
    foreach (['file:///etc/passwd', 'http://169.254.169.254/latest/meta-data/',
              'https://example.org/x.png', '//example.org/x.png', 'ftp://x/y', 'javascript:alert(1)'] as $v) {
        eq('<img src="">', pdf_forras('<img src="' . $v . '">'), $v);
    }
});

t('PDF: entitassal rejtett forras is kiurul', function () {
    // &#102;ile = "file", &#46;&#46; = ".."
    eq('<img src="">', pdf_forras('<img src="&#102;ile:///etc/passwd">'));
    eq('<img src="">', pdf_forras('<img src="/media/&#46;&#46;/x">'));
});

t('PDF: idezojel nelkuli src sem megy at', function () {
    eq('<img src="">', pdf_forras('<img src=http://belso.host/x.png>'));
    eq('<img src="file:///srv/media/img_0123456789ab.png">',
       pdf_forras('<img src=/media/img_0123456789ab.png>'));
});

t('PDF: a beagyazott logo (data:image) es az idegen attributumok erintetlenek', function () {
    $logo = '<img class="logo" src="data:image/png;base64,iVBORw0KGgo=" alt="Infinity">';
    eq($logo, pdf_forras($logo));
    $mas = '<img data-src="http://x/y.png" alt="a">';
    eq($mas, pdf_forras($mas), 'a data-src nem src');
});

t('PDF: egy lapon a jo megmarad, a rossz kiurul', function () {
    $be  = '<p>x</p><img src="/media/img_0123456789ab.png"><img src="http://belso/x"><img src="/media/../a">';
    $ki  = '<p>x</p><img src="file:///srv/media/img_0123456789ab.png"><img src=""><img src="">';
    eq($ki, pdf_forras($be));
});

// --- nginx: a feltoltott fajl kozvetlen megnyitva ne futtathasson szkriptet ----
foreach (['docker/nginx/help.conf', 'deploy/help.infinityhu.eu.dinamikus.nginx.conf'] as $conf) {
    t('nginx /media/ CSP: ' . $conf, function () use ($conf) {
        $s = (string)file_get_contents(GYOKER . '/' . $conf);
        $i = strpos($s, 'location ^~ /media/');
        ok($i !== false, 'nincs /media/ blokk');
        // a blokk vege: a kovetkezo, nem beagyazott location elott
        $blokk = substr($s, $i, 900);
        ok(str_contains($blokk, "Content-Security-Policy"), 'hianyzik a Content-Security-Policy');
        ok(str_contains($blokk, "default-src 'none'"), "hianyzik a default-src 'none'");
        ok(str_contains($blokk, 'sandbox'), 'hianyzik a sandbox');
    });
}

// --- compose: az adatbazis es a fordito csak a gepen belulrol erheto el --------
t('compose: az adatbazis es a fordito portja alapbol 127.0.0.1-re van kotve', function () {
    $s = (string)file_get_contents(GYOKER . '/docker-compose.yml');
    ok(str_contains($s, '"${DB_BIND:-127.0.0.1}:${DB_PORT:-3307}:3306"'), 'az adatbazis kotese');
    ok(str_contains($s, '"${MT_BIND:-127.0.0.1}:${MT_PORT:-5050}:5000"'), 'a fordito kotese');
    no(str_contains($s, '"${DB_PORT'), 'az adatbazis port kotes nelkul maradt');
    no(str_contains($s, '"${MT_PORT'), 'a fordito port kotes nelkul maradt');
});
