<?php
/**
 * docx_export.php - a teljes hasznalati utmutato eloallitasa Word (.docx) fajlkent,
 * kivalasztott nyelven, tartalomjegyzekkel.
 *
 * Az adatbazis a forras: minden exportnal a FRISSEN kozzetett tartalombol dolgozik,
 * nem egy korabbi pillanatkepbol.
 *
 * A .docx egy ZIP, amiben OOXML resz-fajlok vannak. Nem hasznalunk kulso
 * konyvtarat, csak a PHP zip kiterjeszteset - ugyanugy, mint a beolvasasnal.
 *
 * A tartalomjegyzek valodi Word-mezo (TOC), plusz egy elore kiszamolt, kattinthato
 * fejezetlista - igy akkor is hasznalhato, ha valaki nem frissiti a mezoket.
 */
declare(strict_types=1);

final class DocxExport
{
    private const EMU_PER_PX = 9525;
    private const MAX_W_EMU  = 5_580_000;   // ~15,5 cm hasznos szelesseg A4-en

    private array $rels = [];      // rId => ['target'=>, 'type'=>]
    private array $images = [];    // zip-utvonal => bajtok
    private array $bookmarks = []; // slug => bookmark id
    private int $relSeq = 100;
    private int $bmSeq  = 1;
    private string $mediaDir;

    /** A logo fajlja (assets/logo.png vagy .jpg) - a fejlecbe es a cimlapra kerul. */
    private ?string $logoPath = null;
    private array $logoSize = [0, 0];

    public function __construct(string $mediaDir, ?string $assetsDir = null)
    {
        $this->mediaDir = rtrim($mediaDir, '/');

        $dir = $assetsDir !== null ? rtrim($assetsDir, '/') : __DIR__ . '/../assets';
        foreach (['logo.png', 'logo.jpg', 'logo.jpeg'] as $name) {
            $f = $dir . '/' . $name;
            if (is_file($f)) {
                $i = @getimagesize($f);
                if (is_array($i) && in_array($i['mime'], ['image/png', 'image/jpeg'], true)) {
                    $this->logoPath = $f;
                    $this->logoSize = [(int)$i[0], (int)$i[1]];
                }
                break;
            }
        }
    }

    /**
     * @param array $opts  ['company'=>string,'footer'=>string,'version'=>string,'modules'=>?list<int>]
     * @return array{path:string, filename:string, chapters:int, images:int}
     */
    public function build(PDO $db, string $lang, array $opts = []): array
    {
        $L = self::labels($lang);
        $company = (string)($opts['company'] ?? 'Infinity ERP');
        $version = (string)($opts['version'] ?? '');
        $onlyPublished = ($opts['only_published'] ?? true) !== false;

        $mods = $db->prepare('SELECT id, chapter_no, title FROM help_module WHERE lang = ? ORDER BY sort_order, id');
        $mods->execute([$lang]);
        $modules = $mods->fetchAll();

        $art = $db->prepare('SELECT id, module_id, chapter_no, title, body_html
                               FROM help_article
                              WHERE lang = ?' . ($onlyPublished ? ' AND is_published = 1' : '') . '
                           ORDER BY sort_order, id');
        $art->execute([$lang]);
        $byModule = [];
        foreach ($art->fetchAll() as $a) { $byModule[(int)$a['module_id']][] = $a; }

        $body = '';
        $body .= $this->titlePage($L, $company, $version);
        $body .= $this->tocField($L);

        $chapters = 0;
        foreach ($modules as $m) {
            $list = $byModule[(int)$m['id']] ?? [];
            if (!$list) { continue; }

            $body .= $this->pageBreak();
            $body .= $this->heading(1, trim($m['chapter_no'] . '  ' . $m['title']));

            foreach ($list as $a) {
                $chapters++;
                $body .= $this->heading(2, trim($a['chapter_no'] . '  ' . $a['title']));
                $body .= $this->htmlToOoxml((string)$a['body_html']);
            }
        }

        $doc = $this->documentXml($body);
        $path = $this->writeZip($doc, $L, $company, $version, $lang);

        return [
            'path'     => $path,
            'filename' => sprintf('Infinity_%s_%s.docx', $L['file'], date('Y-m-d')),
            'chapters' => $chapters,
            'images'   => count($this->images),
        ];
    }

    // ------------------------------------------------------------ szovegek
    private static function labels(string $lang): array
    {
        return match ($lang) {
            'en' => ['title' => 'Infinity — User Guide', 'toc' => 'Table of contents',
                     'file' => 'User_Guide', 'gen' => 'Generated', 'page' => 'Page',
                     'note' => 'This document was generated from the Infinity help database.'],
            'de' => ['title' => 'Infinity — Benutzerhandbuch', 'toc' => 'Inhaltsverzeichnis',
                     'file' => 'Benutzerhandbuch', 'gen' => 'Erstellt', 'page' => 'Seite',
                     'note' => 'Dieses Dokument wurde aus der Infinity-Hilfedatenbank erzeugt.'],
            default => ['title' => 'Infinity — Használati útmutató', 'toc' => 'Tartalomjegyzék',
                        'file' => 'Hasznalati_utmutato', 'gen' => 'Készült', 'page' => 'Oldal',
                        'note' => 'Ez a dokumentum az Infinity Súgó adatbázisából készült.'],
        };
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ------------------------------------------------------------ elemek
    private function titlePage(array $L, string $company, string $version): string
    {
        $out = '';
        // logo a cimlap tetejen, kozepen
        if ($this->logoPath !== null) {
            $h = 1_100_000;                                   // ~2,9 cm magas
            $w = (int)round($h * $this->logoSize[0] / max(1, $this->logoSize[1]));
            $out .= '<w:p><w:pPr><w:spacing w:before="1400" w:after="0"/><w:jc w:val="center"/></w:pPr>'
                  . $this->logoRun('rIdLogoDoc', $w, $h) . '</w:p>';
        }
        $out .= '<w:p><w:pPr><w:spacing w:before="' . ($this->logoPath !== null ? '360' : '2400') . '" w:after="240"/><w:jc w:val="center"/></w:pPr>'
              . '<w:r><w:rPr><w:b/><w:sz w:val="64"/><w:color w:val="1B3A6B"/></w:rPr>'
              . '<w:t xml:space="preserve">' . self::esc($L['title']) . '</w:t></w:r></w:p>';

        $out .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="120"/></w:pPr>'
              . '<w:r><w:rPr><w:sz w:val="28"/><w:color w:val="6B7280"/></w:rPr>'
              . '<w:t xml:space="preserve">' . self::esc($company) . '</w:t></w:r></w:p>';
        if ($version !== '') {
            $out .= '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
                  . '<w:r><w:rPr><w:sz w:val="22"/><w:color w:val="9CA3AF"/></w:rPr>'
                  . '<w:t xml:space="preserve">' . self::esc($version) . '</w:t></w:r></w:p>';
        }
        $out .= '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="240"/></w:pPr>'
              . '<w:r><w:rPr><w:sz w:val="20"/><w:color w:val="9CA3AF"/></w:rPr>'
              . '<w:t xml:space="preserve">' . self::esc($L['gen'] . ': ' . date('Y-m-d')) . '</w:t></w:r></w:p>';
        $out .= '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
              . '<w:r><w:rPr><w:sz w:val="18"/><w:i/><w:color w:val="9CA3AF"/></w:rPr>'
              . '<w:t xml:space="preserve">' . self::esc($L['note']) . '</w:t></w:r></w:p>';
        return $out;
    }

    /** Valodi Word TOC-mezo: megnyitaskor a Word felajanlja a frissiteset. */
    private function tocField(array $L): string
    {
        $out  = $this->pageBreak();
        $out .= '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t xml:space="preserve">'
              . self::esc($L['toc']) . '</w:t></w:r></w:p>';
        $out .= '<w:p><w:pPr><w:pStyle w:val="TOC1"/></w:pPr>'
              . '<w:r><w:fldChar w:fldCharType="begin" w:dirty="true"/></w:r>'
              . '<w:r><w:instrText xml:space="preserve"> TOC \\o "1-3" \\h \\z \\u </w:instrText></w:r>'
              . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
              . '<w:r><w:rPr><w:i/><w:color w:val="6B7280"/></w:rPr><w:t xml:space="preserve">'
              . self::esc($L['toc']) . ' — F9 / Frissítés</w:t></w:r>'
              . '<w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>';
        return $out;
    }

    private function pageBreak(): string
    {
        return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    private function heading(int $level, string $text): string
    {
        $bm = $this->bmSeq++;
        return '<w:p><w:pPr><w:pStyle w:val="Heading' . $level . '"/></w:pPr>'
             . '<w:bookmarkStart w:id="' . $bm . '" w:name="_Toc' . (900000 + $bm) . '"/>'
             . '<w:r><w:t xml:space="preserve">' . self::esc($text) . '</w:t></w:r>'
             . '<w:bookmarkEnd w:id="' . $bm . '"/></w:p>';
    }

    // ------------------------------------------------------------ HTML -> OOXML
    private function htmlToOoxml(string $html): string
    {
        if (trim($html) === '') { return ''; }

        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = $doc->getElementsByTagName('body')->item(0);
        return $body ? $this->nodes($body, []) : '';
    }

    private function nodes(DOMNode $parent, array $ctx): string
    {
        $out = '';
        foreach ($parent->childNodes as $n) {
            $out .= $this->node($n, $ctx);
        }
        return $out;
    }

    private function node(DOMNode $n, array $ctx): string
    {
        if ($n->nodeType === XML_TEXT_NODE) {
            return trim($n->textContent) === '' ? '' : $this->para($this->runs($n, []));
        }
        if (!($n instanceof DOMElement)) { return ''; }

        $tag = strtolower($n->nodeName);
        switch ($tag) {
            case 'h1': case 'h2':
                return $this->heading(3, help_plain($this->innerHtml($n)));
            case 'h3': case 'h4': case 'h5': case 'h6':
                return $this->heading(4, help_plain($this->innerHtml($n)));

            case 'p':
                $runs = $this->runs($n, []);
                return trim(strip_tags($runs)) === '' && !str_contains($runs, '<w:drawing>')
                    ? '' : $this->para($runs);

            case 'ul': case 'ol':
                $out = '';
                $numId = $tag === 'ol' ? 2 : 1;
                foreach ($n->getElementsByTagName('li') as $li) {
                    if ($li->parentNode !== $n) { continue; }
                    $out .= '<w:p><w:pPr><w:pStyle w:val="ListParagraph"/>'
                          . '<w:numPr><w:ilvl w:val="' . (int)($ctx['depth'] ?? 0) . '"/>'
                          . '<w:numId w:val="' . $numId . '"/></w:numPr></w:pPr>'
                          . $this->runs($li, []) . '</w:p>';
                    foreach ($li->childNodes as $sub) {
                        if ($sub instanceof DOMElement && in_array(strtolower($sub->nodeName), ['ul', 'ol'], true)) {
                            $out .= $this->node($sub, ['depth' => (int)($ctx['depth'] ?? 0) + 1]);
                        }
                    }
                }
                return $out;

            case 'table':
                return $this->table($n);

            case 'img':
                return $this->para($this->image($n));

            case 'video':
                // videot nem lehet Word-be tenni: hivatkozas kerul a helyere
                $src = $n->getAttribute('src');
                return $this->para('<w:r><w:rPr><w:i/><w:color w:val="6B7280"/></w:rPr>'
                    . '<w:t xml:space="preserve">[videó: ' . self::esc($src) . ']</w:t></w:r>');

            case 'div':
                $cls = $n->getAttribute('class');
                if (str_contains($cls, 'call')) {
                    return $this->callout($n, str_contains($cls, 'warn'));
                }
                return $this->nodes($n, $ctx);

            case 'figure': case 'section': case 'tbody': case 'thead':
                return $this->nodes($n, $ctx);

            case 'figcaption':
                return '<w:p><w:pPr><w:spacing w:after="240"/></w:pPr>'
                     . '<w:r><w:rPr><w:i/><w:sz w:val="18"/><w:color w:val="6B7280"/></w:rPr>'
                     . '<w:t xml:space="preserve">' . self::esc(help_plain($this->innerHtml($n))) . '</w:t></w:r></w:p>';

            case 'hr':
                return '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:color="D9DEE5"/></w:pBdr></w:pPr></w:p>';

            case 'blockquote': case 'pre':
                return '<w:p><w:pPr><w:ind w:left="360"/><w:pBdr>'
                     . '<w:left w:val="single" w:sz="18" w:space="8" w:color="0A6ED1"/></w:pBdr></w:pPr>'
                     . $this->runs($n, []) . '</w:p>';

            default:
                return $this->nodes($n, $ctx);
        }
    }

    private function para(string $runs, string $extraPr = ''): string
    {
        if (trim($runs) === '') { return ''; }
        return '<w:p>' . ($extraPr !== '' ? '<w:pPr>' . $extraPr . '</w:pPr>' : '') . $runs . '</w:p>';
    }

    /** Egy elem belsejenek osszes futasa (szoveg + formazas + kepek). */
    private function runs(DOMNode $node, array $fmt): string
    {
        $out = '';
        foreach ($node->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE) {
                $t = preg_replace('/\s+/u', ' ', $c->textContent) ?? '';
                if ($t === '') { continue; }
                $out .= '<w:r>' . $this->rPr($fmt) . '<w:t xml:space="preserve">' . self::esc($t) . '</w:t></w:r>';
                continue;
            }
            if (!($c instanceof DOMElement)) { continue; }
            $tag = strtolower($c->nodeName);

            $f = $fmt;
            if (in_array($tag, ['strong', 'b'], true))  { $f['b'] = true; }
            if (in_array($tag, ['em', 'i'], true))      { $f['i'] = true; }
            if ($tag === 'u')                           { $f['u'] = true; }
            if ($tag === 's')                           { $f['strike'] = true; }
            if ($tag === 'code')                        { $f['mono'] = true; }
            if ($tag === 'a')                           { $f['link'] = true; }

            if ($tag === 'br') { $out .= '<w:r><w:br/></w:r>'; continue; }
            if ($tag === 'img') { $out .= $this->image($c); continue; }
            if ($tag === 'span' && str_contains($c->getAttribute('class'), 'hno')) {
                $out .= '<w:r><w:rPr><w:b/><w:color w:val="0A6ED1"/></w:rPr><w:t xml:space="preserve">'
                      . self::esc(trim($c->textContent)) . ' </w:t></w:r>';
                continue;
            }
            $out .= $this->runs($c, $f);
        }
        return $out;
    }

    private function rPr(array $f): string
    {
        if (!$f) { return ''; }
        $p = '';
        if (!empty($f['b']))      { $p .= '<w:b/>'; }
        if (!empty($f['i']))      { $p .= '<w:i/>'; }
        if (!empty($f['u']))      { $p .= '<w:u w:val="single"/>'; }
        if (!empty($f['strike'])) { $p .= '<w:strike/>'; }
        if (!empty($f['mono']))   { $p .= '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/><w:sz w:val="18"/>'; }
        if (!empty($f['link']))   { $p .= '<w:color w:val="0A6ED1"/><w:u w:val="single"/>'; }
        return $p === '' ? '' : '<w:rPr>' . $p . '</w:rPr>';
    }

    private function callout(DOMElement $n, bool $warn): string
    {
        $bg   = $warn ? 'FDF3E7' : 'E8F1FB';
        $line = $warn ? 'B8681A' : '0A6ED1';
        $out = '';
        foreach ($n->childNodes as $c) {
            if (!($c instanceof DOMElement)) { continue; }
            $pr = '<w:shd w:val="clear" w:fill="' . $bg . '"/>'
                . '<w:pBdr><w:left w:val="single" w:sz="18" w:space="6" w:color="' . $line . '"/></w:pBdr>'
                . '<w:ind w:left="200" w:right="200"/><w:spacing w:before="60" w:after="60"/>';
            $bold = strtolower($c->nodeName) === 'strong';
            $out .= '<w:p><w:pPr>' . $pr . '</w:pPr>' . $this->runs($c, $bold ? ['b' => true] : []) . '</w:p>';
        }
        return $out;
    }

    private function table(DOMElement $tbl): string
    {
        $rows = '';
        $first = true;
        foreach ($tbl->getElementsByTagName('tr') as $tr) {
            $cells = '';
            foreach ($tr->childNodes as $td) {
                if (!($td instanceof DOMElement) || !in_array(strtolower($td->nodeName), ['td', 'th'], true)) { continue; }
                $isHead = strtolower($td->nodeName) === 'th' || $first;
                $span = (int)($td->getAttribute('colspan') ?: 1);
                $cells .= '<w:tc><w:tcPr>'
                        . ($span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '')
                        . ($isHead ? '<w:shd w:val="clear" w:fill="F1F3F6"/>' : '')
                        . '</w:tcPr>'
                        . '<w:p><w:pPr><w:spacing w:before="20" w:after="20"/></w:pPr>'
                        . $this->runs($td, $isHead ? ['b' => true] : []) . '</w:p></w:tc>';
            }
            if ($cells !== '') { $rows .= '<w:tr>' . $cells . '</w:tr>'; }
            $first = false;
        }
        if ($rows === '') { return ''; }
        return '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/>'
             . '<w:tblW w:w="5000" w:type="pct"/>'
             . '<w:tblBorders>'
             . '<w:top w:val="single" w:sz="4" w:color="D9DEE5"/><w:left w:val="single" w:sz="4" w:color="D9DEE5"/>'
             . '<w:bottom w:val="single" w:sz="4" w:color="D9DEE5"/><w:right w:val="single" w:sz="4" w:color="D9DEE5"/>'
             . '<w:insideH w:val="single" w:sz="4" w:color="D9DEE5"/><w:insideV w:val="single" w:sz="4" w:color="D9DEE5"/>'
             . '</w:tblBorders></w:tblPr>' . $rows . '</w:tbl><w:p/>';
    }

    private function image(DOMElement $img): string
    {
        $src = $img->getAttribute('src');
        $name = basename(parse_url($src, PHP_URL_PATH) ?: '');
        if ($name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) { return ''; }

        $file = $this->mediaDir . '/' . $name;
        if (!is_file($file)) { return ''; }

        $info = @getimagesize($file);
        if ($info === false) { return ''; }
        $ext = match ($info['mime']) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', default => null,
        };
        if ($ext === null) { return ''; }   // a Word a WebP-t nem szereti

        $zipPath = 'word/media/' . $name;
        if (!isset($this->images[$zipPath])) {
            $bytes = @file_get_contents($file);
            if ($bytes === false) { return ''; }
            $this->images[$zipPath] = $bytes;
            $this->rels['rId' . (++$this->relSeq)] = [
                'target' => 'media/' . $name,
                'type'   => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image',
            ];
        }
        // a mar meglevo kephez tartozo rId megkeresese
        $rid = '';
        foreach ($this->rels as $id => $r) {
            if ($r['target'] === 'media/' . $name) { $rid = $id; break; }
        }
        if ($rid === '') { return ''; }

        $cx = (int)($info[0] * self::EMU_PER_PX);
        $cy = (int)($info[1] * self::EMU_PER_PX);
        if ($cx > self::MAX_W_EMU) {
            $cy = (int)round($cy * self::MAX_W_EMU / $cx);
            $cx = self::MAX_W_EMU;
        }
        $id = $this->bmSeq++;

        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
             . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
             . '<wp:docPr id="' . (5000 + $id) . '" name="Kep' . $id . '"/>'
             . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
             . '<pic:pic><pic:nvPicPr><pic:cNvPr id="' . (5000 + $id) . '" name="' . self::esc($name) . '"/>'
             . '<pic:cNvPicPr/></pic:nvPicPr>'
             . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
             . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
             . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
             . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }

    /** Egy kepet megjelenito futas adott rId-vel es EMU-merettel (a logohoz). */
    private function logoRun(string $rid, int $cx, int $cy): string
    {
        $id = 9000 + $this->bmSeq++;
        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
             . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
             . '<wp:docPr id="' . $id . '" name="Infinity logo"/>'
             . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
             . '<pic:pic><pic:nvPicPr><pic:cNvPr id="' . $id . '" name="logo"/><pic:cNvPicPr/></pic:nvPicPr>'
             . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
             . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
             . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
             . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }

    /** Ures fejlec/lablec a cimlapra (a <w:titlePg/> miatt kulon resz kell). */
    private function emptyPartXml(string $tag): string
    {
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<w:' . $tag . ' ' . $ns . '><w:p/></w:' . $tag . '>';
    }

    /**
     * Oldallablec: balra az eppen aktualis modul neve (STYLEREF mezo a
     * Cimsor 1 stilusra), jobbra "oldal / osszes" (PAGE es NUMPAGES mezok).
     * A Word ezeket lapozaskor magatol frissiti.
     */
    private function footerXml(): string
    {
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

        $fld = static function (string $instr, string $placeholder): string {
            return '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
                 . '<w:r><w:instrText xml:space="preserve"> ' . $instr . ' </w:instrText></w:r>'
                 . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
                 . '<w:r><w:t>' . $placeholder . '</w:t></w:r>'
                 . '<w:r><w:fldChar w:fldCharType="end"/></w:r>';
        };

        $rpr = '<w:rPr><w:sz w:val="16"/><w:color w:val="6B7280"/></w:rPr>';

        $body = '<w:p><w:pPr>'
              . '<w:pBdr><w:top w:val="single" w:sz="4" w:space="4" w:color="D9DEE5"/></w:pBdr>'
              . '<w:tabs><w:tab w:val="right" w:pos="9638"/></w:tabs>'
              . '<w:spacing w:before="60" w:after="0"/>'
              . $rpr . '</w:pPr>'
              . $fld('STYLEREF 1 \\* MERGEFORMAT', 'modul')
              . '<w:r>' . $rpr . '<w:tab/></w:r>'
              . $fld('PAGE \\* MERGEFORMAT', '1')
              . '<w:r>' . $rpr . '<w:t xml:space="preserve"> / </w:t></w:r>'
              . $fld('NUMPAGES \\* MERGEFORMAT', '1')
              . '</w:p>';

        // a mezok koruli futasok is halvanyak legyenek
        $body = str_replace('<w:r><w:fldChar', '<w:r>' . $rpr . '<w:fldChar', $body);
        $body = str_replace('<w:r><w:instrText', '<w:r>' . $rpr . '<w:instrText', $body);
        $body = str_replace('<w:r><w:t>', '<w:r>' . $rpr . '<w:t>', $body);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<w:ftr ' . $ns . '>' . $body . '</w:ftr>';
    }

    /**
     * Oldalfejlec a logoval, jobbra zarva - ugyanugy, ahogy az eredeti
     * Word-utmutatoban van (ott ~2,4 x 1,15 cm meretben, a lap tetejen jobbra).
     */
    private function headerXml(): string
    {
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';

        $body = '<w:p><w:pPr><w:jc w:val="right"/><w:spacing w:after="0"/></w:pPr>';
        if ($this->logoPath !== null) {
            $h = 437_040;                                     // ~1,15 cm - mint az eredetiben
            $w = (int)round($h * $this->logoSize[0] / max(1, $this->logoSize[1]));
            $body .= $this->logoRun('rIdLogoHdr', $w, $h);
        }
        $body .= '</w:p>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:hdr ' . $ns . '>' . $body . '</w:hdr>';
    }

    private function logoName(): string
    {
        $ext = strtolower(pathinfo((string)$this->logoPath, PATHINFO_EXTENSION));
        return 'infinity-logo.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    }

    private function innerHtml(DOMElement $el): string
    {
        $out = '';
        foreach ($el->childNodes as $c) {
            $out .= $el->ownerDocument->saveHTML($c);
        }
        return $out;
    }

    // ------------------------------------------------------------ csomagolas
    private function documentXml(string $body): string
    {
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"';

        $sect = '<w:sectPr>'
              . ($this->logoPath !== null ? '<w:headerReference w:type="default" r:id="rIdHdr"/>' : '')
              . '<w:headerReference w:type="first" r:id="rIdHdrFirst"/>'
              . '<w:footerReference w:type="default" r:id="rIdFtr"/>'
              . '<w:footerReference w:type="first" r:id="rIdFtrFirst"/>'
              . '<w:titlePg/>'                       // a cimlapon nincs fejlec/lablec
              . '<w:pgSz w:w="11906" w:h="16838"/>'
              . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" '
              . 'w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<w:document ' . $ns . '><w:body>' . $body . $sect . '</w:body></w:document>';
    }

    private function stylesXml(): string
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $h = static function (int $n, int $sz, string $color, int $before): string {
            return '<w:style w:type="paragraph" w:styleId="Heading' . $n . '">'
                 . '<w:name w:val="heading ' . $n . '"/><w:basedOn w:val="Normal"/><w:qFormat/>'
                 . '<w:pPr><w:keepNext/><w:outlineLvl w:val="' . ($n - 1) . '"/>'
                 . '<w:spacing w:before="' . $before . '" w:after="120"/></w:pPr>'
                 . '<w:rPr><w:b/><w:sz w:val="' . $sz . '"/><w:color w:val="' . $color . '"/></w:rPr></w:style>';
        };

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles ' . $w . '>'
             . '<w:docDefaults><w:rPrDefault><w:rPr>'
             . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/><w:color w:val="1F2A36"/>'
             . '</w:rPr></w:rPrDefault>'
             . '<w:pPrDefault><w:pPr><w:spacing w:after="140" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
             . '</w:docDefaults>'
             . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>'
             . $h(1, 40, '1B3A6B', 480) . $h(2, 32, '0854A0', 360) . $h(3, 26, '1F2A36', 280) . $h(4, 23, '475467', 220)
             . '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/>'
             . '<w:basedOn w:val="Normal"/><w:qFormat/><w:pPr><w:ind w:left="720"/>'
             . '<w:spacing w:after="60"/><w:contextualSpacing/></w:pPr></w:style>'
             . '<w:style w:type="paragraph" w:styleId="TOC1"><w:name w:val="toc 1"/><w:basedOn w:val="Normal"/></w:style>'
             . '<w:style w:type="paragraph" w:styleId="TOC2"><w:name w:val="toc 2"/><w:basedOn w:val="Normal"/>'
             . '<w:pPr><w:ind w:left="240"/></w:pPr></w:style>'
             . '<w:style w:type="paragraph" w:styleId="TOC3"><w:name w:val="toc 3"/><w:basedOn w:val="Normal"/>'
             . '<w:pPr><w:ind w:left="480"/></w:pPr></w:style>'
             . '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/></w:style>'
             . '</w:styles>';
    }

    private function numberingXml(): string
    {
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $lvls = static function (string $fmt, string $text): string {
            $out = '';
            for ($i = 0; $i < 4; $i++) {
                $t = $fmt === 'bullet' ? $text : '%' . ($i + 1) . '.';
                $out .= '<w:lvl w:ilvl="' . $i . '"><w:start w:val="1"/>'
                      . '<w:numFmt w:val="' . $fmt . '"/>'
                      . '<w:lvlText w:val="' . $t . '"/><w:lvlJc w:val="left"/>'
                      . '<w:pPr><w:ind w:left="' . (720 + $i * 360) . '" w:hanging="360"/></w:pPr>'
                      . ($fmt === 'bullet' ? '<w:rPr><w:rFonts w:ascii="Symbol" w:hAnsi="Symbol"/></w:rPr>' : '')
                      . '</w:lvl>';
            }
            return $out;
        };
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering ' . $w . '>'
             . '<w:abstractNum w:abstractNumId="1">' . $lvls('bullet', '') . '</w:abstractNum>'
             . '<w:abstractNum w:abstractNumId="2">' . $lvls('decimal', '') . '</w:abstractNum>'
             . '<w:num w:numId="1"><w:abstractNumId w:val="1"/></w:num>'
             . '<w:num w:numId="2"><w:abstractNumId w:val="2"/></w:num>'
             . '</w:numbering>';
    }

    private function writeZip(string $document, array $L, string $company, string $version, string $lang): string
    {
        $path = tempnam(sys_get_temp_dir(), 'helpdoc') . '.docx';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('A .docx fájl nem hozható létre.');
        }

        $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
               . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
               . '<Default Extension="xml" ContentType="application/xml"/>'
               . '<Default Extension="png" ContentType="image/png"/>'
               . '<Default Extension="jpg" ContentType="image/jpeg"/>'
               . '<Default Extension="jpeg" ContentType="image/jpeg"/>'
               . '<Default Extension="gif" ContentType="image/gif"/>'
               . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
               . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
               . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'
               . ($this->logoPath !== null
                    ? '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
                    : '')
               . '<Override PartName="/word/header2.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
               . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
               . '<Override PartName="/word/footer2.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
               . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
               . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                  . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                  . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                  . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                  . '</Relationships>';

        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                 . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                 . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                 . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>';
        if ($this->logoPath !== null) {
            $docRels .= '<Relationship Id="rIdHdr" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>'
                      . '<Relationship Id="rIdLogoDoc" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . self::esc($this->logoName()) . '"/>';
        }
        $docRels .= '<Relationship Id="rIdHdrFirst" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header2.xml"/>'
                  . '<Relationship Id="rIdFtr" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
                  . '<Relationship Id="rIdFtrFirst" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer2.xml"/>';
        foreach ($this->rels as $id => $r) {
            $docRels .= '<Relationship Id="' . $id . '" Type="' . $r['type'] . '" Target="' . self::esc($r['target']) . '"/>';
        }
        $docRels .= '</Relationships>';

        $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
              . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
              . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
              . '<dc:title>' . self::esc($L['title']) . '</dc:title>'
              . '<dc:creator>' . self::esc($company) . '</dc:creator>'
              . '<cp:revision>' . self::esc($version !== '' ? $version : '1') . '</cp:revision>'
              . '<dc:language>' . self::esc($lang) . '</dc:language>'
              . '<dcterms:created xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:created>'
              . '</cp:coreProperties>';

        $zip->addFromString('[Content_Types].xml', $types);
        $zip->addFromString('_rels/.rels', $rootRels);
        $zip->addFromString('docProps/core.xml', $core);
        $zip->addFromString('word/document.xml', $document);
        $zip->addFromString('word/styles.xml', $this->stylesXml());
        $zip->addFromString('word/numbering.xml', $this->numberingXml());
        $zip->addFromString('word/_rels/document.xml.rels', $docRels);

        // cimlap: ures fejlec es lablec; tobbi lap: logos fejlec + oldalszamos lablec
        $zip->addFromString('word/header2.xml', $this->emptyPartXml('hdr'));
        $zip->addFromString('word/footer2.xml', $this->emptyPartXml('ftr'));
        $zip->addFromString('word/footer1.xml', $this->footerXml());

        if ($this->logoPath !== null) {
            $logo = (string)file_get_contents($this->logoPath);
            $zip->addFromString('word/media/' . $this->logoName(), $logo);
            $zip->addFromString('word/header1.xml', $this->headerXml());
            $zip->addFromString('word/_rels/header1.xml.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rIdLogoHdr" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" '
                . 'Target="media/' . self::esc($this->logoName()) . '"/></Relationships>');
        }
        foreach ($this->images as $zipPath => $bytes) {
            $zip->addFromString($zipPath, $bytes);
        }
        $zip->close();

        return $path;
    }
}

/**
 * Nyomtatasra kesz, egyetlen HTML fajl a teljes utmutatobol, tartalomjegyzekkel.
 * A bongeszo "Nyomtatas -> Mentes PDF-kent" funkciojaval lesz belole PDF.
 */
function export_print_html(PDO $db, array $cfg, string $lang, bool $onlyPublished = true, bool $autoPrint = false, bool $forPdf = false): string
{
    // A logot beagyazzuk a fajlba (data: URI), igy a mentett HTML onmagaban is
    // teljes - nem hivatkozik kifele, es a PDF-be is belekerul.
    $logoTag = '';
    foreach (['logo.png', 'logo.jpg', 'logo.jpeg', 'logo.svg'] as $name) {
        $f = __DIR__ . '/../assets/' . $name;
        if (!is_file($f)) { continue; }
        $bytes = @file_get_contents($f);
        if ($bytes === false) { break; }
        $mime = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'svg' => 'image/svg+xml', default => 'image/jpeg',
        };
        $logoTag = '<img class="logo" src="data:' . $mime . ';base64,' . base64_encode($bytes) . '" alt="Infinity">';
        break;
    }

    $L = match ($lang) {
        'en' => ['title' => 'Infinity — User Guide', 'toc' => 'Table of contents', 'gen' => 'Generated'],
        'de' => ['title' => 'Infinity — Benutzerhandbuch', 'toc' => 'Inhaltsverzeichnis', 'gen' => 'Erstellt'],
        default => ['title' => 'Infinity — Használati útmutató', 'toc' => 'Tartalomjegyzék', 'gen' => 'Készült'],
    };

    $mods = $db->prepare('SELECT id, chapter_no, title FROM help_module WHERE lang = ? ORDER BY sort_order, id');
    $mods->execute([$lang]);
    $modules = $mods->fetchAll();

    $art = $db->prepare('SELECT id, module_id, chapter_no, slug, title, body_html
                           FROM help_article
                          WHERE lang = ?' . ($onlyPublished ? ' AND is_published = 1' : '') . '
                       ORDER BY sort_order, id');
    $art->execute([$lang]);
    $byModule = [];
    foreach ($art->fetchAll() as $a) { $byModule[(int)$a['module_id']][] = $a; }

    $company = admin_setting($db, 'export_company', 'Infinity ERP');
    $version = (string)$db->query("SELECT COALESCE(MAX(doc_version), '') FROM help_article")->fetchColumn();

    $toc = '';
    $body = '';
    foreach ($modules as $m) {
        $list = $byModule[(int)$m['id']] ?? [];
        if (!$list) { continue; }
        $mid = 'm-' . (int)$m['id'];
        $toc .= '<li class="m"><a href="#' . h($mid) . '"><b>' . h($m['chapter_no']) . '</b> ' . h($m['title']) . '</a><ul>';
        $body .= '<section class="mod"><h1 id="' . h($mid) . '"><span>' . h($m['chapter_no']) . '</span> ' . h($m['title']) . '</h1>';
        foreach ($list as $a) {
            $aid = 'a-' . (int)$a['id'];
            $toc .= '<li><a href="#' . h($aid) . '"><b>' . h($a['chapter_no']) . '</b> ' . h($a['title']) . '</a></li>';
            $body .= '<article><h2 id="' . h($aid) . '"><span>' . h($a['chapter_no']) . '</span> ' . h($a['title']) . '</h2>'
                   . fix_img_url((string)$a['body_html']) . '</article>';
        }
        $toc .= '</ul></li>';
        $body .= '</section>';
    }

    $css = <<<'CSS'
*{box-sizing:border-box}
body{margin:0;font:10.5pt/1.5 "Inter","Segoe UI",system-ui,sans-serif;color:#1f2a36;background:#fff}

/* ---------- oldalbeállítás ---------- */
@page{
  size:A4;
  margin:24mm 18mm 20mm;
  /* futó fejléc: a logó jobbra */
  @top-right{content:element(runlogo);vertical-align:bottom;}
  /* futó lábléc: balra az aktuális modul, jobbra az oldalszám */
  @bottom-left{
    content:string(modul);
    font:8.5pt "Inter",system-ui,sans-serif;color:#6b7280;
    vertical-align:top;padding-top:4mm;
  }
  @bottom-right{
    content:counter(page) " / " counter(pages);
    font:8.5pt "Inter",system-ui,sans-serif;color:#6b7280;
    vertical-align:top;padding-top:4mm;
  }
}
/* a címlapon nincs se fejléc, se lábléc, se oldalszám */
@page :first{
  margin:0;
  @top-right{content:none}
  @bottom-left{content:none}
  @bottom-right{content:none}
}
/* a tartalomjegyzék lapjain a lábléc szövege sem kell */
@page toc{
  @bottom-left{content:none}
}

.runhead{position:running(runlogo)}
.runhead .logo{height:10mm;width:auto;display:block}

/* ---------- címlap ---------- */
.cover{page:cover;page-break-after:always;text-align:center;padding:62mm 22mm 0}
.cover__logo{margin:0 0 12mm}
.cover__logo .logo{height:38mm;width:auto;display:inline-block}
.cover h1{font-size:28pt;color:#1b3a6b;margin:0 0 6mm;letter-spacing:-.5pt;line-height:1.2;border:0;padding:0}
.cover .rule{width:52mm;height:1.2mm;background:#0a6ed1;border-radius:1mm;margin:0 auto 9mm}
.cover p{color:#6b7280;margin:0 0 2.5mm;font-size:12pt}
.cover .ver{font-size:10.5pt;color:#9ca3af}

/* ---------- tartalomjegyzék ---------- */
.toc{page:toc;page-break-after:always}
.toc h2{font-size:20pt;color:#1b3a6b;margin:0 0 6mm;padding-bottom:3mm;border-bottom:1.5pt solid #0a6ed1}
.toc ul{list-style:none;padding-left:0;margin:0}
.toc ul ul{padding-left:7mm;margin:1mm 0 2mm}
.toc li{margin:.8mm 0;font-size:10pt;line-height:1.45}
.toc li.m{margin-top:3.5mm;font-size:11pt;font-weight:600}
.toc a{color:#1f2a36;text-decoration:none;display:block}
.toc b{color:#0a6ed1;display:inline-block;min-width:13mm;font-weight:700}
/* pontsor és oldalszám a tartalomjegyzékben (PDF-motorral) */
.toc a::after{content:leader('.') target-counter(attr(href url),page);color:#9ca3af;font-weight:400}

/* ---------- modulok, fejezetek ---------- */
.mod{page-break-before:always}
/* a modul címe adja a lábléc szövegét */
.mod>h1{string-set:modul content(text)}
h1{font-size:20pt;color:#1b3a6b;margin:0 0 7mm;padding-bottom:3mm;border-bottom:1.5pt solid #0a6ed1;line-height:1.25}
h1 span{color:#0a6ed1}
h2{font-size:14pt;margin:9mm 0 3mm;color:#1f2a36;page-break-after:avoid;line-height:1.3}
h2 span{color:#0a6ed1;font-weight:700}
article{page-break-inside:auto}
article+article{margin-top:2mm}
.body h2,h3{font-size:11.5pt;margin:5mm 0 2mm;page-break-after:avoid}
h4{font-size:11pt;margin:4mm 0 1.5mm;page-break-after:avoid}
p{margin:0 0 2.6mm;orphans:2;widows:2}
ul,ol{margin:0 0 3mm;padding-left:7mm}
li{margin-bottom:1mm}
strong{font-weight:600}

img{max-width:100%;height:auto;border:.3mm solid #d9dee5;border-radius:1.5mm;page-break-inside:avoid;margin:2mm 0}
video{display:none}

table{border-collapse:collapse;width:100%;font-size:9pt;margin:0 0 4mm;page-break-inside:avoid}
th,td{border:.25mm solid #d9dee5;padding:1.4mm 2mm;text-align:left;vertical-align:top}
th,tr.header td{background:#f1f3f6;font-weight:600}
.tblwrap{overflow:visible}

.hno{color:#0a6ed1;font-weight:700;margin-right:2mm}
.call{padding:3mm 4mm;border-left:1mm solid #0a6ed1;background:#e8f1fb;border-radius:0 1.5mm 1.5mm 0;margin:0 0 3.5mm;page-break-inside:avoid}
.call.warn{border-left-color:#b8681a;background:#fdf3e7}
.call strong{display:block;font-size:8.5pt;text-transform:uppercase;letter-spacing:.3pt;color:#0854a0;margin-bottom:1mm}
.call.warn strong{color:#b8681a}

/* ---------- képernyőn (nem nyomtatáskor) ---------- */
@media screen{
  .wrap{max-width:200mm;margin:0 auto;padding:44px 16mm 20mm}
  .runhead{position:static;text-align:right;opacity:.9}
  .runhead .logo{height:12mm;margin-left:auto}
  .cover{height:auto;padding:20mm 0}
  .toc a::after{content:''}
}
@media print{
  .wrap{max-width:none;padding:0}
  .noprint{display:none}
}
.noprint{position:fixed;top:0;left:0;right:0;background:#1c2a3a;color:#fff;padding:8px 14px;font-size:13px;text-align:center;z-index:9;display:flex;gap:12px;align-items:center;justify-content:center;flex-wrap:wrap}
.noprint button{font:inherit;background:#0a6ed1;color:#fff;border:0;border-radius:4px;padding:5px 12px;cursor:pointer}
.noprint a{color:#9fd0ff}
CSS;

    return '<!doctype html><html lang="' . h($lang) . '"><head><meta charset="utf-8">'
         . '<meta name="viewport" content="width=device-width,initial-scale=1">'
         . '<title>' . h($L['title']) . '</title><style>' . $css . '</style></head><body>'
         . ($forPdf ? '' :
             '<div class="noprint">'
             . '<span>A PDF-hez: <b>Nyomtatás → Cél: Mentés PDF-ként</b></span>'
             . '<button type="button" onclick="window.print()">Nyomtatás / PDF mentése</button>'
             . '</div>')
         . ($logoTag !== '' ? '<div class="runhead">' . $logoTag . '</div>' : '')
         . '<div class="wrap">'
         . '<div class="cover">' . ($logoTag !== '' ? '<div class="cover__logo">' . $logoTag . '</div>' : '')
         . '<h1>' . h($L['title']) . '</h1><div class="rule"></div><p>' . h($company) . '</p>'
         . ($version !== '' ? '<p class="ver">' . h($version) . '</p>' : '')
         . '<p class="ver">' . h($L['gen'] . ': ' . date('Y-m-d')) . '</p></div>'
         . '<div class="toc"><h2>' . h($L['toc']) . '</h2><ul>' . $toc . '</ul></div>'
         . $body
         . '</div>'
         . ($autoPrint ? '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},400);});</script>' : '')
         . '</body></html>';
}

/**
 * Van-e a szerveren PDF-motor?
 *
 * A bongeszo "Nyomtatas -> Mentes PDF-kent" utja mindig mukodik, de a bongeszo
 * NEM tud oldalszamot es futo lablecet tenni a lapokra (a CSS @page margo-dobozait
 * egyik bongeszo sem tamogatja). Ezert ha a szerveren ott a WeasyPrint, azzal
 * keszitunk igazi, konyv-szeru PDF-et: cimlap fejlec/lablec nelkul, utana minden
 * lapon a logo, alul a modul neve es az oldalszam, a tartalomjegyzekben
 * oldalszamokkal.
 */
function pdf_engine(): ?string
{
    static $found = false, $path = null;
    if ($found) { return $path; }
    $found = true;

    foreach (['/usr/bin/weasyprint', '/usr/local/bin/weasyprint', 'weasyprint'] as $cand) {
        $which = @shell_exec('command -v ' . escapeshellarg($cand) . ' 2>/dev/null');
        if (is_string($which) && trim($which) !== '') {
            $path = trim($which);
            return $path;
        }
    }
    return null;
}

/**
 * Valodi PDF eloallitasa. Hiba eseten RuntimeException.
 *
 * @return array{path:string, filename:string, bytes:int}
 */
function export_pdf(PDO $db, array $cfg, string $lang, bool $onlyPublished = true): array
{
    $bin = pdf_engine();
    if ($bin === null) {
        throw new RuntimeException(
            'Nincs PDF-motor a szerveren. Telepítés: apt-get install -y weasyprint — '
            . 'addig a „PDF (nyomtatás)" gomb használható, ott a böngésző készíti a PDF-et.');
    }

    $html = export_print_html($db, $cfg, $lang, $onlyPublished, false, true);

    // A kepek /media/... alakban hivatkozottak; a PDF-motor a lemezrol olvassa oket.
    $dir = rtrim($cfg['media_dir'], '/');
    $html = str_replace(['src="/media/', "src='/media/"],
                        ['src="file://' . $dir . '/', "src='file://" . $dir . '/'],
                        $html);

    $out = tempnam(sys_get_temp_dir(), 'helppdf') . '.pdf';
    $cmd = escapeshellarg($bin) . ' --encoding utf-8 - ' . escapeshellarg($out) . ' 2>&1';

    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new RuntimeException('A PDF-motor nem indítható el.');
    }
    fwrite($pipes[0], $html);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);

    if (!is_file($out) || filesize($out) < 1000) {
        @unlink($out);
        $msg = trim((string)$stderr) !== '' ? $stderr : $stdout;
        throw new RuntimeException('A PDF elkészítése nem sikerült (kód ' . $code . '): '
            . mb_substr(trim((string)$msg), 0, 300));
    }

    $names = ['en' => 'User_Guide', 'de' => 'Benutzerhandbuch'];
    return [
        'path'     => $out,
        'filename' => sprintf('Infinity_%s_%s.pdf', $names[$lang] ?? 'Hasznalati_utmutato', date('Y-m-d')),
        'bytes'    => (int)filesize($out),
    ];
}
