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

    public function __construct(string $mediaDir)
    {
        $this->mediaDir = rtrim($mediaDir, '/');
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
                              WHERE lang = ?' . ($onlyPublished ? ' AND is_published' : '') . '
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
        $out  = '<w:p><w:pPr><w:spacing w:before="2400" w:after="240"/><w:jc w:val="center"/></w:pPr>'
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

        $sect = '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
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
function export_print_html(PDO $db, array $cfg, string $lang, bool $onlyPublished = true): string
{
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
                          WHERE lang = ?' . ($onlyPublished ? ' AND is_published' : '') . '
                       ORDER BY sort_order, id');
    $art->execute([$lang]);
    $byModule = [];
    foreach ($art->fetchAll() as $a) { $byModule[(int)$a['module_id']][] = $a; }

    $company = admin_setting($db, 'export_company', 'Infinity ERP');
    $version = (string)$db->query("SELECT coalesce(max(doc_version), '') FROM help_article")->fetchColumn();

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
body{margin:0;font:11pt/1.55 "Inter","Segoe UI",system-ui,sans-serif;color:#1f2a36;background:#fff}
.wrap{max-width:190mm;margin:0 auto;padding:18mm 14mm}
.cover{text-align:center;padding:60mm 0 0}
.cover h1{font-size:30pt;color:#1b3a6b;margin:0 0 6mm}
.cover p{color:#6b7280;margin:0 0 2mm;font-size:12pt}
.toc{page-break-after:always}
.toc h2{font-size:18pt;color:#1b3a6b;border-bottom:2px solid #0a6ed1;padding-bottom:3mm}
.toc ul{list-style:none;padding-left:0;margin:0}
.toc ul ul{padding-left:8mm}
.toc li{margin:1mm 0;font-size:10.5pt}
.toc li.m{margin-top:3mm;font-size:11.5pt}
.toc a{color:#1f2a36;text-decoration:none}
.toc b{color:#0a6ed1;display:inline-block;min-width:14mm}
.mod{page-break-before:always}
h1{font-size:22pt;color:#1b3a6b;margin:0 0 8mm;padding-bottom:3mm;border-bottom:2px solid #0a6ed1}
h1 span{color:#0a6ed1}
h2{font-size:15pt;margin:10mm 0 4mm;page-break-after:avoid}
h2 span{color:#0a6ed1}
article h2:first-of-type{margin-top:6mm}
article h2,article h3,article h4{page-break-after:avoid}
.body h2,h3{font-size:12.5pt;margin:6mm 0 2mm}
h4{font-size:11.5pt;margin:5mm 0 2mm}
p{margin:0 0 3mm}
ul,ol{margin:0 0 3mm;padding-left:7mm}
img{max-width:100%;height:auto;border:1px solid #d9dee5;border-radius:2mm;page-break-inside:avoid;margin:2mm 0}
video{display:none}
table{border-collapse:collapse;width:100%;font-size:9.5pt;margin:0 0 4mm;page-break-inside:avoid}
th,td{border:1px solid #d9dee5;padding:1.5mm 2mm;text-align:left;vertical-align:top}
th,tr.header td{background:#f1f3f6;font-weight:600}
.hno{color:#0a6ed1;font-weight:700;margin-right:2mm}
.call{padding:3mm 4mm;border-left:1mm solid #0a6ed1;background:#e8f1fb;border-radius:0 1mm 1mm 0;margin:0 0 4mm;page-break-inside:avoid}
.call.warn{border-left-color:#b8681a;background:#fdf3e7}
.call strong{display:block;font-size:9.5pt;text-transform:uppercase;letter-spacing:.4pt;color:#0854a0;margin-bottom:1mm}
.call.warn strong{color:#b8681a}
.tblwrap{overflow:visible}
@page{size:A4;margin:16mm 14mm}
@media print{.wrap{max-width:none;padding:0}.noprint{display:none}}
.noprint{position:fixed;top:0;left:0;right:0;background:#1c2a3a;color:#fff;padding:8px 14px;font-size:13px;text-align:center;z-index:9}
.noprint button{font:inherit;background:#0a6ed1;color:#fff;border:0;border-radius:4px;padding:5px 12px;margin-left:10px;cursor:pointer}
body{padding-top:44px}
@media print{body{padding-top:0}}
CSS;

    return '<!doctype html><html lang="' . h($lang) . '"><head><meta charset="utf-8">'
         . '<meta name="viewport" content="width=device-width,initial-scale=1">'
         . '<title>' . h($L['title']) . '</title><style>' . $css . '</style></head><body>'
         . '<div class="noprint">A PDF-hez: <b>Nyomtatás → Mentés PDF-ként</b>'
         . '<button onclick="window.print()">Nyomtatás</button></div>'
         . '<div class="wrap">'
         . '<div class="cover"><h1>' . h($L['title']) . '</h1><p>' . h($company) . '</p>'
         . ($version !== '' ? '<p>' . h($version) . '</p>' : '')
         . '<p>' . h($L['gen'] . ': ' . date('Y-m-d')) . '</p></div>'
         . '<div class="toc"><h2>' . h($L['toc']) . '</h2><ul>' . $toc . '</ul></div>'
         . $body
         . '</div></body></html>';
}
