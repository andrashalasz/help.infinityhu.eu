<?php
/**
 * util.php - kozos apro segedek (adatbazis, HTML, slug, tisztitas).
 */
declare(strict_types=1);


/** Kozos PDO beallitasok (MariaDB). */
function help_pdo_options(): array
{
    return [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci",
    ];
}

/**
 * Munkamenet-beallitasok. A STRICT mod azert kell, hogy a tul hosszu ertek
 * vagy a hibas datum hibat adjon, ne pedig csendben csonkoljon.
 */
function help_pdo_init(PDO $pdo): void
{
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
}

/** Olvaso kapcsolat a nyilvanos oldalhoz. */
function help_db(array $cfg): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], help_pdo_options());
        help_pdo_init($pdo);
    }
    return $pdo;
}

/** Iro kapcsolat az admin felulethez. */
function help_db_rw(array $cfg): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO($cfg['admin_dsn'], $cfg['admin_user'], $cfg['admin_pass'], help_pdo_options());
        help_pdo_init($pdo);
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

/**
 * Ekezet-fuggetlen, kisbetus normalizalas.
 *
 * Az adatbazisban ezt a utf8mb4_uca1400_ai_ci rendezes intezi; ez a PHP-s par
 * a kereso-kivonat kiemelesehez es a kliens oldali szurokhoz kell.
 */
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
        'p' => ['class','style'], 'br' => [], 'strong' => ['style'], 'b' => ['style'],
        'em' => ['style'], 'i' => ['style'], 'u' => [],
        's' => [], 'sub' => [], 'sup' => [], 'mark' => [],
        'h1' => ['id','class','style'], 'h2' => ['id','class','style'], 'h3' => ['id','class','style'],
        'h4' => ['id','class','style'], 'h5' => ['id','class','style'], 'h6' => ['id','class','style'],
        'ul' => ['class'], 'ol' => ['class','start'], 'li' => ['class','style'],
        'blockquote' => ['class'], 'pre' => ['class'], 'code' => ['class'], 'hr' => [],
        'table' => ['class','style'], 'thead' => ['class','style'], 'tbody' => ['class'], 'tfoot' => ['class'],
        'tr' => ['class','style'], 'th' => ['class','colspan','rowspan','scope','style'], 'td' => ['class','colspan','rowspan','style'],
        'a' => ['href','title','target','rel'],
        'img' => ['src','alt','title','width','height','class','loading','style'],
        'video' => ['src','controls','preload','poster','width','height','class','muted','loop','playsinline','style'],
        'source' => ['src','type'],
        'track' => ['src','kind','srclang','label','default'],
        'figure' => ['class','style'], 'figcaption' => ['class'],
        'div' => ['class','id'], 'span' => ['class','style'],
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
                if ($name === 'style') {
                    $clean = help_clean_style((string)$attr->nodeValue);
                    if ($clean === '') { $child->removeAttribute('style'); }
                    else { $child->setAttribute('style', $clean); }
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
 * Inline stilus szurese: CSAK meretezes marad benne.
 *
 * A szerkesztobol jovo kepek/videok meretet inline stilus hordozza (ez az egyetlen,
 * ami felul tudja irni a stiluslap "height: auto" szabalyat, amikor a szerkeszto
 * szandekosan kikapcsolja az aranytartast). Minden mas CSS-tulajdonsag kiesik,
 * igy a mezo nem valik altalanos stilus-becsatornazasi lehetoseggé.
 */
function help_clean_style(string $style): string
{
    $allowed = ['width', 'height', 'max-width', 'max-height', 'object-fit',
                'color', 'background-color', 'text-align'];
    $out = [];

    foreach (explode(';', $style) as $decl) {
        if (!str_contains($decl, ':')) { continue; }
        [$prop, $val] = explode(':', $decl, 2);
        $prop = strtolower(trim($prop));
        $val  = trim($val);

        if (!in_array($prop, $allowed, true)) { continue; }

        // Betu- es kiemeloszin. Csak onallo szinertek megy at - se url(),
        // se var(), se calc(), igy nem lehet vele kiszivarogtatni vagy betolteni.
        if ($prop === 'color' || $prop === 'background-color') {
            $c = help_clean_color($val);
            if ($c !== '') { $out[] = $prop . ':' . $c; }
            continue;
        }

        // szoveg igazitasa: balra / kozepre / jobbra / sorkizart
        if ($prop === 'text-align') {
            if (in_array($val, ['left', 'center', 'right', 'justify'], true)) {
                $out[] = $prop . ':' . $val;
            }
            continue;
        }

        if ($prop === 'object-fit') {
            if (in_array($val, ['fill', 'contain', 'cover', 'none', 'scale-down'], true)) {
                $out[] = $prop . ':' . $val;
            }
            continue;
        }
        // csak egyszeru meretertek: 320px, 50%, auto - se url(), se calc(), se valtozo
        if (preg_match('/^(auto|\d{1,5}(\.\d{1,2})?(px|%|em|rem|vw|vh))$/i', $val)) {
            $out[] = $prop . ':' . strtolower($val);
        }
    }
    return implode(';', $out);
}

/**
 * Egyetlen szinertek ellenorzese. Elfogadja a #rgb / #rrggbb alakot, az
 * rgb()/rgba() fuggvenyt es a szokasos nevesitett szineket. Minden mas
 * (url(), var(), calc(), expression()) kiesik.
 */
function help_clean_color(string $v): string
{
    $v = strtolower(trim($v));
    if ($v === '') { return ''; }

    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $v)) { return $v; }

    if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(0|1|0?\.\d{1,3})\s*)?\)$/', $v, $m)) {
        foreach ([1, 2, 3] as $i) {
            if ((int)$m[$i] > 255) { return ''; }
        }
        return $v;
    }

    $named = [
        'black', 'white', 'red', 'green', 'blue', 'yellow', 'orange', 'purple',
        'gray', 'grey', 'silver', 'maroon', 'olive', 'lime', 'teal', 'navy',
        'fuchsia', 'aqua', 'inherit', 'currentcolor', 'transparent',
    ];
    return in_array($v, $named, true) ? $v : '';
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
        '/<h([2-6])([^>]*)>(.*?)<\/h\1>/is',
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

/**
 * A cikkben levo "Tipp" es "Figyelem" dobozok kigyujtese a jobb savhoz.
 *
 * Minden dobozhoz eltesszuk a legkozelebbi elotte allo cimsor horgonyat is,
 * igy a jobb savban levo hivatkozas oda ugrik, ahol a doboz all.
 *
 * @return list<array{kind:string,title:string,text:string,anchor:string}>
 */
function help_extract_callouts(string $html): array
{
    if (trim($html) === '') { return []; }

    $prev = libxml_use_internal_errors(true);
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->loadHTML(
        '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $body = $doc->getElementsByTagName('body')->item(0);
    if (!$body) { return []; }

    $out = [];
    $lastAnchor = '';

    $walk = function (DOMNode $node) use (&$walk, &$out, &$lastAnchor): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) { continue; }
            /** @var DOMElement $child */
            $tag = strtolower($child->nodeName);

            if (preg_match('/^h[1-6]$/', $tag)) {
                $id = $child->getAttribute('id');
                if ($id !== '') { $lastAnchor = $id; }
                continue;
            }

            $class = ' ' . $child->getAttribute('class') . ' ';
            if ($tag === 'div' && str_contains($class, ' call ')) {
                $kind = str_contains($class, ' crit ') ? 'crit'
                      : (str_contains($class, ' warn ') ? 'warn' : 'tip');

                $title = '';
                $strong = $child->getElementsByTagName('strong')->item(0);
                if ($strong) {
                    $title = trim($strong->textContent);
                    $strong->parentNode?->removeChild($strong);
                }

                $text = help_plain($child->textContent);
                if ($text !== '' || $title !== '') {
                    $out[] = [
                        'kind'   => $kind,
                        'title'  => $title,
                        'text'   => $text,
                        'anchor' => $lastAnchor,
                    ];
                }
                continue;
            }
            $walk($child);
        }
    };
    $walk($body);

    return $out;
}

/**
 * Statikus fajl hivatkozasa a fajl modositasi idejevel, hogy a bongeszo
 * ne a regi, gyorsitotarazott valtozatot hozza (a kiszolgalo egy hetre
 * cache-eli a css/js fajlokat).
 */
function asset_url(string $path): string
{
    static $cache = [];
    if (!isset($cache[$path])) {
        $file = __DIR__ . '/..' . $path;
        $t = @filemtime($file);
        $cache[$path] = $path . ($t ? '?v=' . $t : '');
    }
    return $cache[$path];
}

/**
 * Egy fejezetszam merulesi melysege a modulon belul.
 *
 * A modul "1", akkor az "1.3" az elso szint, az "1.3.1" a masodik, es igy
 * tovabb. Ebbol lesz a behuzas a bal oldali listakban, hogy az alfejezet
 * lathatoan a szulője ala tartozzon.
 */
function help_chapter_depth(string $moduleNo, string $chapterNo): int
{
    $chapterNo = trim($chapterNo);
    if ($chapterNo === '') { return 1; }

    $moduleParts  = $moduleNo === '' ? 1 : count(explode('.', trim($moduleNo)));
    $chapterParts = count(explode('.', $chapterNo));

    return max(1, min(4, $chapterParts - $moduleParts + 1));
}

/**
 * Verziocimke nemzetkozi, "kicsitol a nagyig" sorrendben.
 *
 *   v2026.09      ->  v.09.2026
 *   v2026.08.26   ->  v.26.08.2026
 *
 * A tarolt ertek NEM valtozik - csak a megjelenitest forditjuk meg, hogy
 * ne kelljen ev-ho sorrendet olvasni.
 */
function help_version_label(string $version): string
{
    $v = trim($version);
    if ($v === '') { return ''; }

    $body = ltrim($v, 'vV.');
    $parts = explode('.', $body);

    // csak akkor rendezzuk at, ha az elso tag evszamnak latszik
    if (count($parts) < 2 || !preg_match('/^\d{4}$/', $parts[0])) { return $v; }
    foreach ($parts as $p) {
        if (!preg_match('/^\d+$/', $p)) { return $v; }
    }

    return 'v.' . implode('.', array_reverse($parts));
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

/**
 * Kereso-kivonat: a talalat koruli par szo, a keresett kifejezes kiemelve.
 *
 * A PostgreSQL-valtozatban ezt a ts_headline() csinalta; a MariaDB-ben nincs
 * ilyen, ezert itt allitjuk elo. Ekezet-fuggetlenul keres (help_norm), de a
 * kiemeles az EREDETI szoveget mutatja, ekezetekkel egyutt.
 */
function help_snippet(string $plain, string $query, int $words = 22): string
{
    $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? '');
    if ($plain === '') { return ''; }

    $terms = array_values(array_filter(
        preg_split('/\s+/u', $query) ?: [],
        static fn($t) => mb_strlen($t) >= 2
    ));
    if (!$terms) {
        return mb_substr($plain, 0, 160) . (mb_strlen($plain) > 160 ? '…' : '');
    }

    $normPlain = help_norm($plain);
    $pos = false;
    $hit = '';
    foreach ($terms as $t) {
        $p = mb_strpos($normPlain, help_norm($t));
        if ($p !== false && ($pos === false || $p < $pos)) { $pos = $p; $hit = $t; }
    }

    if ($pos === false) {
        return mb_substr($plain, 0, 160) . (mb_strlen($plain) > 160 ? '…' : '');
    }

    // a talalat koruli ablak szohataron
    $before = mb_substr($plain, 0, $pos);
    $tail   = mb_substr($plain, $pos);
    $lead   = preg_split('/\s+/u', $before) ?: [];
    $lead   = array_slice($lead, -(int)floor($words / 3));
    $rest   = preg_split('/\s+/u', $tail) ?: [];
    $rest   = array_slice($rest, 0, $words);

    $text = trim(implode(' ', $lead) . ' ' . implode(' ', $rest));
    $out  = h($text);

    // minden keresett szo kiemelese (ekezet-fuggetlenul, de az eredetit mutatva)
    foreach ($terms as $t) {
        $out = help_mark($out, $t);
    }

    return (count($lead) ? '… ' : '') . $out . (count($rest) >= $words ? ' …' : '');
}

/** Egy kifejezes osszes elofordulasanak <mark>-kal jelolese, ekezet-fuggetlenul. */
function help_mark(string $escapedHtml, string $term): string
{
    $needle = help_norm($term);
    if ($needle === '') { return $escapedHtml; }

    $out = '';
    $i = 0;
    $len = mb_strlen($escapedHtml);
    $normHay = help_norm($escapedHtml);

    while ($i < $len) {
        $p = mb_strpos($normHay, $needle, $i);
        if ($p === false) { $out .= mb_substr($escapedHtml, $i); break; }
        // ne toljuk szet a HTML-entitasokat (&amp; stb.)
        $chunk = mb_substr($escapedHtml, $p, mb_strlen($needle));
        if (str_contains($chunk, '&') || str_contains($chunk, ';')) {
            $out .= mb_substr($escapedHtml, $i, $p - $i + mb_strlen($needle));
            $i = $p + mb_strlen($needle);
            continue;
        }
        $out .= mb_substr($escapedHtml, $i, $p - $i) . '<mark>' . $chunk . '</mark>';
        $i = $p + mb_strlen($needle);
    }
    return $out;
}

/**
 * A kereses SQL-feltetele MariaDB-hez.
 *
 * Ket dolgot fesulunk ossze:
 *   1. InnoDB FULLTEXT (MATCH ... AGAINST BOOLEAN MODE) - ez adja a rangsort,
 *   2. LIKE a cimre es a szovegre - ez fogja meg a rovid szavakat (a FULLTEXT
 *      alapbol csak a 3+ karakteres szavakat indexeli) es a szo belseji talalatot.
 * Az ekezet-fuggetlenseget a tabla utf8mb4_uca1400_ai_ci rendezese adja.
 *
 * @return array{where:string, order:string, params:array<string,string>}
 */
function help_search_sql(string $q): array
{
    $terms = array_values(array_filter(
        preg_split('/\s+/u', trim($q)) ?: [],
        static fn($t) => mb_strlen($t) >= 2
    ));

    // BOOLEAN MODE kifejezes: minden szo kotelezo, elore-illesztessel
    $bool = '';
    foreach ($terms as $t) {
        $clean = preg_replace('/[+\-><()~*"@]+/u', ' ', $t) ?? $t;
        $clean = trim($clean);
        if ($clean === '') { continue; }
        $bool .= '+' . $clean . '* ';
    }
    $bool = trim($bool);

    return [
        'where'  => '(MATCH(a.title, a.plain_text) AGAINST (:ft IN BOOLEAN MODE)'
                  . ' OR a.title LIKE :like OR a.plain_text LIKE :like2)',
        // A cimben levo talalat mindig elozze meg a szovegtorzsben levot: egy
        // sugoban a "Penzugy" fejezetet keresik, nem azt, amelyik a legtobbszor
        // emliti. Azon belul rangsorol a FULLTEXT pontszam.
        'order'  => '(a.title LIKE :like3) DESC,'
                  . ' MATCH(a.title, a.plain_text) AGAINST (:ft2 IN BOOLEAN MODE) DESC,'
                  . ' a.sort_order',
        'params' => [
            'ft'    => $bool !== '' ? $bool : $q,
            'ft2'   => $bool !== '' ? $bool : $q,
            'like'  => '%' . $q . '%',
            'like2' => '%' . $q . '%',
            'like3' => '%' . $q . '%',
        ],
    ];
}
