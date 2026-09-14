<?php
/**
 * util.php - kozos apro segedek (adatbazis, HTML, slug, tisztitas).
 */
declare(strict_types=1);

/** Olvaso kapcsolat a nyilvanos oldalhoz. */
function help_db(array $cfg): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** Iro kapcsolat az admin felulethez. */
function help_db_rw(array $cfg): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO($cfg['admin_dsn'], $cfg['admin_user'], $cfg['admin_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** A body_html-ben a relativ media/ hivatkozasok abszolutta tetele. */
function fix_img_url(string $html): string
{
    return str_replace(['src="media/', "src='media/"], ['src="/media/', "src='/media/"], $html);
}

/** Ekezet-fuggetlen, kisbetus normalizalas (a Postgres erp_norm() PHP-s parja). */
function help_norm(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $map = [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ö'=>'o','ő'=>'o','ú'=>'u','ü'=>'u','ű'=>'u',
        'â'=>'a','ä'=>'a','à'=>'a','ã'=>'a','å'=>'a','ç'=>'c','ê'=>'e','ë'=>'e','è'=>'e',
        'î'=>'i','ï'=>'i','ì'=>'i','ô'=>'o','õ'=>'o','ò'=>'o','û'=>'u','ù'=>'u','ñ'=>'n','ß'=>'ss',
    ];
    return strtr($s, $map);
}

/** URL-baratsagos azonosito: "5.4 Kintlévőség kezelés" -> "5-4-kintlevoseg-kezeles" */
function help_slug(string $chapterNo, string $title): string
{
    $base = trim($chapterNo) !== '' ? $chapterNo . ' ' . $title : $title;
    $s = help_norm($base);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-');
}

/** HTML -> kereshetó sima szoveg. */
function help_plain(string $html): string
{
    $t = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
    $t = preg_replace('/<[^>]+>/', ' ', $t) ?? $t;
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
    return trim($t);
}

/**
 * Szerkesztobol vagy Wordbol jovo HTML megtisztitasa.
 * Engedelyezett elemek es attributumok fehérlistaval - minden mas kiesik,
 * igy sem <script>, sem on* esemenykezelo, sem javascript: URL nem marad benne.
 */
function help_clean_html(string $html): string
{
    if (trim($html) === '') { return ''; }

    $allowed = [
        'p' => ['class'], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
        's' => [], 'sub' => [], 'sup' => [], 'mark' => [],
        'h1' => ['id','class'], 'h2' => ['id','class'], 'h3' => ['id','class'],
        'h4' => ['id','class'], 'h5' => ['id','class'], 'h6' => ['id','class'],
        'ul' => ['class'], 'ol' => ['class','start'], 'li' => ['class'],
        'blockquote' => ['class'], 'pre' => ['class'], 'code' => ['class'], 'hr' => [],
        'table' => ['class'], 'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => ['class'], 'th' => ['class','colspan','rowspan','scope'], 'td' => ['class','colspan','rowspan'],
        'a' => ['href','title','target','rel'],
        'img' => ['src','alt','title','width','height','class','loading'],
        'video' => ['src','controls','preload','poster','width','height','class','muted','loop','playsinline'],
        'source' => ['src','type'],
        'track' => ['src','kind','srclang','label','default'],
        'figure' => ['class'], 'figcaption' => ['class'],
        'div' => ['class','id'], 'span' => ['class'],
        'section' => ['class','id'],
    ];

    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) { return ''; }

    $walk = function (DOMNode $node) use (&$walk, $allowed): void {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if (!$child) { continue; }

            if ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            /** @var DOMElement $child */
            $tag = strtolower($child->nodeName);

            if (!isset($allowed[$tag])) {
                // a tiltott elem tartalma megmarad, maga az elem eltunik
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            for ($a = $child->attributes->length - 1; $a >= 0; $a--) {
                $attr = $child->attributes->item($a);
                if (!$attr) { continue; }
                $name = strtolower($attr->nodeName);
                if (!in_array($name, $allowed[$tag], true)) {
                    $child->removeAttribute($attr->nodeName);
                    continue;
                }
                if ($name === 'href' || $name === 'src') {
                    $v = trim($attr->nodeValue ?? '');
                    $scheme = strtolower((string)parse_url($v, PHP_URL_SCHEME));
                    $ok = $scheme === '' || in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)
                          || ($name === 'src' && str_starts_with($v, 'data:image/'));
                    if (!$ok) { $child->removeAttribute($attr->nodeName); }
                }
            }
            if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                $child->setAttribute('rel', 'noopener noreferrer');
            }
            $walk($child);
        }
    };
    $walk($body);

    $out = '';
    foreach ($body->childNodes as $c) {
        $out .= $doc->saveHTML($c);
    }
    return trim($out);
}

/**
 * Horgonyok (id) pótlása a cikk címsoraira, hogy az oldalon belüli
 * tartalomjegyzék és a "hivatkozás másolása" működjön.
 * Visszaadja a [html, szakaszok] parost.
 */
function help_anchorize(string $html): array
{
    $sections = [];
    $used = [];
    $html = preg_replace_callback(
        '/<h([2-4])([^>]*)>(.*?)<\/h\1>/is',
        function (array $m) use (&$sections, &$used): string {
            $level = (int)$m[1];
            $attrs = $m[2];
            $inner = $m[3];
            $text  = help_plain($inner);

            if (preg_match('/\bid\s*=\s*"([^"]+)"/i', $attrs, $idm)) {
                $id = $idm[1];
            } else {
                $id = help_slug('', $text);
                if ($id === '') { $id = 'sec'; }
                $n = 1;
                $base = $id;
                while (isset($used[$id])) { $id = $base . '-' . (++$n); }
                $attrs .= ' id="' . h($id) . '"';
            }
            $used[$id] = true;

            // "5.4.1 Cím" -> szám és cím külön
            $no = '';
            if (preg_match('/^\s*(\d+(?:\.\d+)*)\.?\s+(.*)$/u', $text, $tm)) {
                $no = $tm[1];
                $text = $tm[2];
            }
            $sections[] = ['level' => $level, 'anchor' => $id, 'chapter_no' => $no, 'title' => $text];
            return '<h' . $level . $attrs . '>' . $inner . '</h' . $level . '>';
        },
        $html
    ) ?? $html;

    return [$html, $sections];
}

function help_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function help_bytes(int $n): string
{
    $u = ['B', 'kB', 'MB', 'GB'];
    $i = 0;
    $f = (float)$n;
    while ($f >= 1024 && $i < count($u) - 1) { $f /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$f : number_format($f, 1, ',', ' ')) . ' ' . $u[$i];
}

/**
 * Az Infinity logo.
 *
 * Ha van kepfajl az assets/ mappaban (logo.png / .webp / .svg / .jpg), azt hasznaljuk -
 * igy a vegleges markat eleg bemasolni, kodot nem kell hozza irni.
 * Amig nincs ott, egy beagyazott SVG vegtelen-jel all a helyen, hogy sose
 * legyen torott kep a fejlecben.
 */
function help_logo(int $size = 30, string $idSuffix = ''): string
{
    static $file = null;
    if ($file === null) {
        $file = '';
        foreach (['logo.svg', 'logo.png', 'logo.webp', 'logo.jpg'] as $name) {
            if (is_file(__DIR__ . '/../assets/' . $name)) {
                $file = '/assets/' . $name . '?v=' . substr((string)@filemtime(__DIR__ . '/../assets/' . $name), -6);
                break;
            }
        }
    }
    if ($file !== '') {
        // a magassagot adjuk meg, a szelesseg a kep aranyabol jon - igy nem torzul
        return '<img class="logo-img" src="' . h($file) . '" alt="Infinity" height="' . $size . '" decoding="async">';
    }

    $h  = (int)round($size * 0.5);
    $id = 'inf-grad' . $idSuffix;
    return '<svg class="logo-inf" width="' . $size . '" height="' . $h . '" viewBox="0 0 64 32" '
         . 'role="img" aria-label="Infinity" focusable="false">'
         . '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="1" y2="1">'
         . '<stop offset="0%" stop-color="#1b6fd6"/>'
         . '<stop offset="55%" stop-color="#0a9fe8"/>'
         . '<stop offset="100%" stop-color="#37d0f5"/>'
         . '</linearGradient></defs>'
         . '<path d="M32 16C26 4.6 12 4.6 12 16s14 11.4 20 0 20-11.4 20 0-14 11.4-20 0Z" '
         . 'fill="none" stroke="url(#' . $id . ')" stroke-width="5.6" stroke-linecap="round"/>'
         . '</svg>';
}
