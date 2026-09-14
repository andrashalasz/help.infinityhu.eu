<?php
/**
 * docx.php - Word (.docx) fajl beolvasasa fejezetekre bontva.
 *
 * A .docx valojaban egy ZIP, benne:
 *   word/document.xml        - a szoveg
 *   word/_rels/document.xml.rels - a kepek hivatkozasai (rId -> media/image1.png)
 *   word/media/*             - maguk a kepek
 *   word/numbering.xml       - lista-definiciok
 *   word/styles.xml          - stilusnevek (Heading 1 / Cimsor 1 / sajat)
 *
 * Amit csinalunk:
 *   1. kibontjuk a document.xml-t,
 *   2. bekezdesenkent vegigmegyunk rajta, a cimsor-szintek alapjan modult
 *      (1. szint) es fejezetet (2. szint) vagunk belole,
 *   3. a formazast (felkover, dolt, listak, tablazatok, kepek) HTML-re
 *      forditjuk - ugyanabba a formaba, amit az adatbazis mar tartalmaz,
 *   4. a kepeket a tartalom sha256-jarol nevezzuk el (img_<hash12>.png),
 *      igy egy kep akkor is egyszer keletkezik, ha tobb helyen szerepel.
 *
 * Szandekosan nem hasznal kulso konyvtarat: ZipArchive + DOMDocument eleg.
 */
declare(strict_types=1);

final class DocxParser
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    /** @var array<string,string> rId -> zip-beli utvonal */
    private array $rels = [];
    /** @var array<string,int> stilusnev -> cimsor-szint */
    private array $headingStyles = [];
    /** @var array<string,array{name:string,bytes:string}> */
    private array $images = [];
    private ZipArchive $zip;
    private string $mediaDir;
    /** @var list<string> */
    public array $warnings = [];

    public function __construct(string $mediaDir)
    {
        $this->mediaDir = rtrim($mediaDir, '/');
    }

    /**
     * @return array{modules: list<array>, images: int, paragraphs: int}
     *   modules[] = ['no'=>'5','title'=>'Pénzügy','articles'=>[['no'=>'5.4','title'=>..,'html'=>..,'imgs'=>int], ...]]
     */
    public function parse(string $docxPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            throw new RuntimeException('A fájl nem nyitható meg .docx-ként (sérült vagy nem Word-fájl).');
        }
        $this->zip = $zip;

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            throw new RuntimeException('Hiányzik a word/document.xml – ez nem érvényes .docx fájl.');
        }

        $this->loadRels();
        $this->loadHeadingStyles();

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOENT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = $doc->getElementsByTagNameNS(self::W, 'body')->item(0);
        if (!$body) {
            $zip->close();
            throw new RuntimeException('A dokumentum üres.');
        }

        $modules   = [];
        $curModule = null;
        $curArt    = null;
        $paras     = 0;
        $html = '';
        $openLists = [];

        $closeLists = function (int $toDepth) use (&$openLists, &$html): void {
            while (count($openLists) > $toDepth) {
                $t = array_pop($openLists);
                $html .= '</' . $t . '>';
            }
        };

        foreach ($body->childNodes as $node) {
            if (!($node instanceof DOMElement)) { continue; }
            $name = $node->localName;

            if ($name === 'p') {
                $paras++;
                $level = $this->headingLevel($node);
                $text  = trim($this->plainOf($node));

                if ($level === 1 && $text !== '') {
                    // uj modul
                    $closeLists(0);
                    if ($curArt !== null && $curModule !== null) {
                        $curArt['html'] = trim($html);
                        $curModule['articles'][] = $curArt;
                        $curArt = null;
                        $html = '';
                    }
                    if ($curModule !== null) { $modules[] = $curModule; }
                    [$no, $title] = $this->splitNumber($text);
                    $curModule = ['no' => $no, 'title' => $title, 'articles' => []];
                    continue;
                }

                if ($level === 2 && $text !== '') {
                    if ($curModule === null) {
                        $curModule = ['no' => '', 'title' => 'Egyéb', 'articles' => []];
                    }
                    $closeLists(0);
                    if ($curArt !== null) {
                        $curArt['html'] = trim($html);
                        $curModule['articles'][] = $curArt;
                    }
                    $html = '';
                    [$no, $title] = $this->splitNumber($text);
                    $curArt = ['no' => $no, 'title' => $title, 'html' => '', 'imgs' => 0];
                    continue;
                }

                if ($curArt === null) {
                    // a fejezetek elotti bevezeto szoveg nem tartozik sehova
                    continue;
                }

                if ($level >= 3) {
                    $closeLists(0);
                    $tag = 'h' . min(4, $level);
                    $html .= '<' . $tag . '>' . $this->inlineOf($node, $curArt) . '</' . $tag . '>';
                    continue;
                }

                // listaelem?
                $li = $this->listInfo($node);
                if ($li !== null) {
                    $want = $li['level'] + 1;
                    $tag  = $li['ordered'] ? 'ol' : 'ul';
                    if (count($openLists) > $want) { $closeLists($want); }
                    while (count($openLists) < $want) { $openLists[] = $tag; $html .= '<' . $tag . '>'; }
                    if (end($openLists) !== $tag) {
                        $closeLists(count($openLists) - 1);
                        $openLists[] = $tag;
                        $html .= '<' . $tag . '>';
                    }
                    $html .= '<li>' . $this->inlineOf($node, $curArt) . '</li>';
                    continue;
                }

                $closeLists(0);
                $inner = $this->inlineOf($node, $curArt);
                if (trim(strip_tags($inner, '<img>')) === '' && !str_contains($inner, '<img')) {
                    continue;   // ures bekezdes
                }
                $html .= '<p>' . $inner . '</p>';
                continue;
            }

            if ($name === 'tbl') {
                if ($curArt === null) { continue; }
                $closeLists(0);
                $html .= $this->tableOf($node, $curArt);
            }
        }

        $closeLists(0);
        if ($curArt !== null && $curModule !== null) {
            $curArt['html'] = trim($html);
            $curModule['articles'][] = $curArt;
        }
        if ($curModule !== null) { $modules[] = $curModule; }

        $zip->close();

        $written = $this->writeImages();

        return ['modules' => $modules, 'images' => $written, 'paragraphs' => $paras];
    }

    /** "5.4 Kintlévőség kezelés" -> ['5.4', 'Kintlévőség kezelés'] */
    private function splitNumber(string $text): array
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;
        if (preg_match('/^(\d+(?:\.\d+)*)\.?\s+(.+)$/u', $text, $m)) {
            return [$m[1], trim($m[2])];
        }
        return ['', $text];
    }

    private function loadRels(): void
    {
        $xml = $this->zip->getFromName('word/_rels/document.xml.rels');
        if ($xml === false) { return; }
        $d = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $d->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        foreach ($d->getElementsByTagName('Relationship') as $rel) {
            /** @var DOMElement $rel */
            $target = $rel->getAttribute('Target');
            if ($target === '') { continue; }
            if (str_starts_with($target, '/')) { $target = ltrim($target, '/'); }
            elseif (!str_starts_with($target, 'word/')) { $target = 'word/' . $target; }
            $this->rels[$rel->getAttribute('Id')] = $target;
        }
    }

    /**
     * A cimsor-stilusok neve nyelvfuggo (Heading 1 / Cimsor 1 / Uberschrift 1),
     * ezert a styles.xml-bol olvassuk ki, melyik styleId melyik outline-szint.
     */
    private function loadHeadingStyles(): void
    {
        $xml = $this->zip->getFromName('word/styles.xml');
        if ($xml === false) { return; }
        $d = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $d->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        foreach ($d->getElementsByTagNameNS(self::W, 'style') as $st) {
            /** @var DOMElement $st */
            $id = $st->getAttributeNS(self::W, 'styleId');
            if ($id === '') { continue; }

            $level = 0;
            $ol = $st->getElementsByTagNameNS(self::W, 'outlineLvl')->item(0);
            if ($ol instanceof DOMElement) {
                $level = (int)$ol->getAttributeNS(self::W, 'val') + 1;
            }
            if ($level === 0) {
                $nameEl = $st->getElementsByTagNameNS(self::W, 'name')->item(0);
                $name = $nameEl instanceof DOMElement ? $nameEl->getAttributeNS(self::W, 'val') : $id;
                if (preg_match('/^(heading|c[ií]msor|[uü]berschrift|title\s*)\s*(\d)/iu', $name, $m)) {
                    $level = (int)$m[2];
                }
            }
            if ($level >= 1 && $level <= 6) {
                $this->headingStyles[$id] = $level;
            }
        }
    }

    private function headingLevel(DOMElement $p): int
    {
        $pPr = $p->getElementsByTagNameNS(self::W, 'pPr')->item(0);
        if (!$pPr instanceof DOMElement) { return 0; }

        $style = $pPr->getElementsByTagNameNS(self::W, 'pStyle')->item(0);
        if ($style instanceof DOMElement) {
            $id = $style->getAttributeNS(self::W, 'val');
            if (isset($this->headingStyles[$id])) { return $this->headingStyles[$id]; }
            if (preg_match('/^(?:Heading|C[ií]msor|[UÜ]berschrift)(\d)$/i', $id, $m)) { return (int)$m[1]; }
        }
        $ol = $pPr->getElementsByTagNameNS(self::W, 'outlineLvl')->item(0);
        if ($ol instanceof DOMElement) { return (int)$ol->getAttributeNS(self::W, 'val') + 1; }
        return 0;
    }

    /** @return array{level:int, ordered:bool}|null */
    private function listInfo(DOMElement $p): ?array
    {
        $pPr = $p->getElementsByTagNameNS(self::W, 'pPr')->item(0);
        if (!$pPr instanceof DOMElement) { return null; }
        $numPr = $pPr->getElementsByTagNameNS(self::W, 'numPr')->item(0);
        if (!$numPr instanceof DOMElement) {
            $style = $pPr->getElementsByTagNameNS(self::W, 'pStyle')->item(0);
            if ($style instanceof DOMElement && preg_match('/ListParagraph|Listaszerubekezdes/i', $style->getAttributeNS(self::W, 'val'))) {
                return ['level' => 0, 'ordered' => false];
            }
            return null;
        }
        $ilvl = $numPr->getElementsByTagNameNS(self::W, 'ilvl')->item(0);
        $numId = $numPr->getElementsByTagNameNS(self::W, 'numId')->item(0);
        $level = $ilvl instanceof DOMElement ? (int)$ilvl->getAttributeNS(self::W, 'val') : 0;
        $ordered = false;
        if ($numId instanceof DOMElement) {
            $ordered = $this->isOrdered((int)$numId->getAttributeNS(self::W, 'val'), $level);
        }
        return ['level' => min(3, $level), 'ordered' => $ordered];
    }

    private function isOrdered(int $numId, int $level): bool
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            $xml = $this->zip->getFromName('word/numbering.xml');
            if ($xml !== false) {
                $d = new DOMDocument();
                $prev = libxml_use_internal_errors(true);
                $d->loadXML($xml, LIBXML_NONET);
                libxml_clear_errors();
                libxml_use_internal_errors($prev);

                $abstractOf = [];
                foreach ($d->getElementsByTagNameNS(self::W, 'num') as $num) {
                    /** @var DOMElement $num */
                    $aid = $num->getElementsByTagNameNS(self::W, 'abstractNumId')->item(0);
                    if ($aid instanceof DOMElement) {
                        $abstractOf[(int)$num->getAttributeNS(self::W, 'numId')] = (int)$aid->getAttributeNS(self::W, 'val');
                    }
                }
                $fmt = [];
                foreach ($d->getElementsByTagNameNS(self::W, 'abstractNum') as $an) {
                    /** @var DOMElement $an */
                    $aid = (int)$an->getAttributeNS(self::W, 'abstractNumId');
                    foreach ($an->getElementsByTagNameNS(self::W, 'lvl') as $lvl) {
                        /** @var DOMElement $lvl */
                        $li = (int)$lvl->getAttributeNS(self::W, 'ilvl');
                        $nf = $lvl->getElementsByTagNameNS(self::W, 'numFmt')->item(0);
                        $fmt[$aid][$li] = $nf instanceof DOMElement ? $nf->getAttributeNS(self::W, 'val') : 'bullet';
                    }
                }
                $cache = ['abstract' => $abstractOf, 'fmt' => $fmt];
            } else {
                $cache = ['abstract' => [], 'fmt' => []];
            }
        }
        $aid = $cache['abstract'][$numId] ?? null;
        if ($aid === null) { return false; }
        $f = $cache['fmt'][$aid][$level] ?? ($cache['fmt'][$aid][0] ?? 'bullet');
        return $f !== 'bullet' && $f !== 'none';
    }

    private function plainOf(DOMElement $p): string
    {
        $out = '';
        foreach ($p->getElementsByTagNameNS(self::W, 't') as $t) {
            $out .= $t->textContent;
        }
        return $out;
    }

    /** Egy bekezdes belseje HTML-ben (felkover, dolt, kepek, hivatkozasok). */
    private function inlineOf(DOMElement $p, array &$art): string
    {
        $out = '';
        foreach ($p->childNodes as $child) {
            if (!($child instanceof DOMElement)) { continue; }
            if ($child->localName === 'hyperlink') {
                $rid  = $child->getAttributeNS(self::R, 'id');
                $href = $this->hyperlinkTarget($rid);
                $inner = '';
                foreach ($child->getElementsByTagNameNS(self::W, 'r') as $r) {
                    $inner .= $this->runOf($r, $art);
                }
                $out .= $href !== null
                    ? '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">' . $inner . '</a>'
                    : $inner;
                continue;
            }
            if ($child->localName === 'r') {
                $out .= $this->runOf($child, $art);
            }
        }
        return $out;
    }

    private function hyperlinkTarget(string $rid): ?string
    {
        if ($rid === '') { return null; }
        $xml = $this->zip->getFromName('word/_rels/document.xml.rels');
        if ($xml === false) { return null; }
        static $ext = null;
        if ($ext === null) {
            $ext = [];
            $d = new DOMDocument();
            $prev = libxml_use_internal_errors(true);
            $d->loadXML($xml, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            foreach ($d->getElementsByTagName('Relationship') as $rel) {
                /** @var DOMElement $rel */
                if ($rel->getAttribute('TargetMode') === 'External') {
                    $ext[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
                }
            }
        }
        $t = $ext[$rid] ?? null;
        if ($t === null) { return null; }
        $scheme = strtolower((string)parse_url($t, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto'], true) ? $t : null;
    }

    private function runOf(DOMElement $r, array &$art): string
    {
        // kep a futasban?
        $blips = $r->getElementsByTagNameNS(self::A, 'blip');
        if ($blips->length > 0) {
            $out = '';
            foreach ($blips as $blip) {
                /** @var DOMElement $blip */
                $rid = $blip->getAttributeNS(self::R, 'embed');
                $img = $this->stageImage($rid);
                if ($img !== null) {
                    $art['imgs']++;
                    $out .= '<img src="media/' . $img . '" alt="">';
                }
            }
            if ($out !== '') { return $out; }
        }

        $text = '';
        foreach ($r->childNodes as $n) {
            if (!($n instanceof DOMElement)) { continue; }
            if ($n->localName === 't') {
                $text .= htmlspecialchars($n->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } elseif ($n->localName === 'br') {
                $text .= '<br>';
            } elseif ($n->localName === 'tab') {
                $text .= ' ';
            }
        }
        if ($text === '') { return ''; }

        $rPr = $r->getElementsByTagNameNS(self::W, 'rPr')->item(0);
        if ($rPr instanceof DOMElement) {
            $on = function (string $tag) use ($rPr): bool {
                $el = $rPr->getElementsByTagNameNS(self::W, $tag)->item(0);
                if (!$el instanceof DOMElement) { return false; }
                $v = $el->getAttributeNS(self::W, 'val');
                return $v === '' || !in_array($v, ['0', 'false', 'none'], true);
            };
            if ($on('b'))      { $text = '<strong>' . $text . '</strong>'; }
            if ($on('i'))      { $text = '<em>' . $text . '</em>'; }
            if ($on('u'))      { $text = '<u>' . $text . '</u>'; }
            if ($on('strike')) { $text = '<s>' . $text . '</s>'; }
        }
        return $text;
    }

    /** A kepet elmentjuk a memoriaba, a vegleges nevet a tartalom hash-e adja. */
    private function stageImage(string $rid): ?string
    {
        if ($rid === '' || !isset($this->rels[$rid])) { return null; }
        $path = $this->rels[$rid];
        $bytes = $this->zip->getFromName($path);
        if ($bytes === false || $bytes === '') { return null; }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'png');
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            $this->warnings[] = "Nem támogatott képformátum kihagyva: .{$ext}";
            return null;
        }
        $name = 'img_' . substr(hash('sha256', $bytes), 0, 12) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $this->images[$name] = ['name' => $name, 'bytes' => $bytes];
        return $name;
    }

    /** A kepek kiirasa a lemezre. Ami mar ott van ugyanazzal a nevvel, azt nem irjuk felul. */
    private function writeImages(): int
    {
        if (!is_dir($this->mediaDir)) {
            if (!@mkdir($this->mediaDir, 0775, true) && !is_dir($this->mediaDir)) {
                throw new RuntimeException('A képek mappája nem hozható létre: ' . $this->mediaDir);
            }
        }
        if (!is_writable($this->mediaDir)) {
            throw new RuntimeException('A képek mappája nem írható: ' . $this->mediaDir);
        }
        $n = 0;
        foreach ($this->images as $img) {
            $target = $this->mediaDir . '/' . $img['name'];
            if (is_file($target)) { continue; }
            if (@file_put_contents($target, $img['bytes']) !== false) { $n++; }
        }
        return $n;
    }

    private function tableOf(DOMElement $tbl, array &$art): string
    {
        $out = '<table>';
        $first = true;
        foreach ($tbl->childNodes as $tr) {
            if (!($tr instanceof DOMElement) || $tr->localName !== 'tr') { continue; }
            $cellTag = $first ? 'th' : 'td';
            $out .= '<tr>';
            foreach ($tr->childNodes as $tc) {
                if (!($tc instanceof DOMElement) || $tc->localName !== 'tc') { continue; }
                $span = '';
                $gs = $tc->getElementsByTagNameNS(self::W, 'gridSpan')->item(0);
                if ($gs instanceof DOMElement) {
                    $n = (int)$gs->getAttributeNS(self::W, 'val');
                    if ($n > 1) { $span = ' colspan="' . $n . '"'; }
                }
                $inner = '';
                foreach ($tc->childNodes as $p) {
                    if ($p instanceof DOMElement && $p->localName === 'p') {
                        $piece = $this->inlineOf($p, $art);
                        if (trim(strip_tags($piece)) !== '' || str_contains($piece, '<img')) {
                            $inner .= ($inner === '' ? '' : '<br>') . $piece;
                        }
                    }
                }
                $out .= '<' . $cellTag . $span . '>' . $inner . '</' . $cellTag . '>';
            }
            $out .= '</tr>';
            $first = false;
        }
        return $out . '</table>';
    }
}
