<?php
/**
 * mt.php - gepi forditas (machine translation).
 *
 * Negy szolgaltatot ismer, mindegyik opcionalis:
 *   claude  - Anthropic Claude (Messages API)                          (kulcs kell)
 *   deepl   - https://api-free.deepl.com  vagy  https://api.deepl.com   (kulcs kell)
 *   libre   - LibreTranslate, sajat szerveren is futtathato             (kulcs nem mindig kell)
 *   google  - Google Cloud Translation v2                              (kulcs kell)
 *
 * A Claude annyiban mas a tobbinel, hogy nem szotarazo fordito, hanem
 * utasithato: kap egy szakszotarat es a megtartando jelolesek listajat,
 * ezert az ERP-szakkifejezeseket is helyesen forditja. Ezt a kulonbseget
 * a LibreTranslate-tel osszehasonlitva latni a legjobban:
 *   "Kintlevoseg kezeles" -> LibreTranslate: "Capacity management" (hibas)
 *                          -> Claude:         "Receivables management"
 *
 * Beallitas: kornyezeti valtozoval (HELP_MT_PROVIDER / HELP_MT_ENDPOINT /
 * HELP_MT_KEY), vagy az admin felulet Beallitasok fulen (help_setting tabla).
 *
 * Ha egyik sincs beallitva ('none'), a Forditas ful ettol meg mukodik:
 * a ket nyelv egymas mellett latszik, a forditast kezzel is be lehet irni.
 *
 * A HTML-t nem daraboljuk szet: a szolgaltatoknak HTML-kent adjuk at, igy a
 * <strong>, a listak, a tablazatok es kulonosen a <img src="media/..."> valtozatlanul
 * atmennek. (DeepL: tag_handling=html, Google: format=html, LibreTranslate: format=html.)
 */
declare(strict_types=1);

final class Translator
{
    /**
     * A valaszthato Claude modellek, es amit tudni erdemes roluk.
     *
     * Forditasra a Sonnet eleg: a sugo szakszotara (mt_glossary) amugy is
     * megadja a kotott kifejezeseket, ezert a modelltol nem kell szakmai
     * dontes. Az Opus nagysagrenddel dragabb - egy teljes sugo-ujraforditas
     * (114 fejezet, ket nyelv) vele ~40 USD, Sonnettel ennek toredeke.
     *
     * ar = USD / 1M token (bemenet / kimenet), 2026-os listaar
     */
    public const MODELLEK = [
        'claude-sonnet-5-5' => ['nev' => 'Sonnet 5.5 — ajánlott fordításra', 'ar' => '3 / 15'],
        'claude-opus-5-5'   => ['nev' => 'Opus 5.5 — a legpontosabb, drága',  'ar' => '5 / 25'],
        'claude-haiku-4-5-20251001' => ['nev' => 'Haiku 4.5 — a leggyorsabb és legolcsóbb', 'ar' => '1 / 5'],
    ];

    /** Ha nincs beallitva semmi, ezzel forditunk. */
    public const ALAP_MODELL = 'claude-sonnet-5-5';

    public string $provider;
    private string $endpoint;
    private string $key;
    private string $glossary = '';
    private string $model    = self::ALAP_MODELL;

    public function __construct(string $provider, string $endpoint, string $key, string $model = '')
    {
        $this->provider = $provider !== '' ? $provider : 'none';
        $this->endpoint = rtrim($endpoint, '/');
        $this->key      = $key;
        $this->setModel($model);
    }

    /** Ismeretlen vagy ures modellnev eseten az alapertelmezett marad. */
    public function setModel(string $model): void
    {
        $this->model = isset(self::MODELLEK[$model]) ? $model : self::ALAP_MODELL;
    }

    public function model(): string { return $this->model; }

    /** A config es az adatbazis-beallitasok osszefesulese (a kornyezeti valtozo eros). */
    public static function fromConfig(array $cfg, ?PDO $db = null): self
    {
        $provider = $cfg['mt_provider'] ?? '';
        $endpoint = $cfg['mt_endpoint'] ?? '';
        $key      = $cfg['mt_key'] ?? '';
        $model    = $cfg['mt_model'] ?? '';

        $glossary = '';
        if ($db !== null) {
            try {
                $rows = $db->query("SELECT `key`, value FROM help_setting
                                     WHERE `key` IN ('mt_provider','mt_endpoint','mt_key','mt_glossary','mt_model')")->fetchAll();
                $s = [];
                foreach ($rows as $r) { $s[$r['key']] = (string)$r['value']; }
                if ($provider === '') { $provider = $s['mt_provider'] ?? 'none'; }
                if ($endpoint === '') { $endpoint = $s['mt_endpoint'] ?? ''; }
                if ($key === '')      { $key      = $s['mt_key'] ?? ''; }
                $glossary = $s['mt_glossary'] ?? '';
                if ($model === '') { $model = $s['mt_model'] ?? ''; }
            } catch (Throwable $e) {
                // beallitas-tabla nelkul is mukodjon
            }
        }
        $t = new self($provider ?: 'none', $endpoint, $key, $model);
        $t->setGlossary($glossary);
        return $t;
    }

    public function isConfigured(): bool
    {
        if ($this->provider === 'none' || $this->provider === '') { return false; }
        if ($this->provider === 'libre') { return $this->endpoint !== ''; }
        return $this->key !== '';
    }

    public function label(): string
    {
        return match ($this->provider) {
            'claude' => 'Claude (Anthropic)',
            'deepl'  => 'DeepL',
            'libre'  => 'LibreTranslate',
            'google' => 'Google Translate',
            default  => t('nincs beállítva'),
        };
    }

    /** Sajat szakszotar - a Claude ezt kapja meg utasitaskent. */
    public function setGlossary(string $text): void
    {
        $this->glossary = trim($text);
    }

    /**
     * HTML forditasa. Hiba eseten RuntimeException.
     */
    public function translateHtml(string $html, string $from, string $to): string
    {
        if (trim($html) === '') { return ''; }
        if (!$this->isConfigured()) {
            throw new RuntimeException(t('Nincs beállítva gépi fordító. Beállítások → Gépi fordítás.'));
        }
        return match ($this->provider) {
            'claude' => $this->claude($html, $from, $to),
            'deepl'  => $this->deepl($html, $from, $to),
            'libre'  => $this->libre($html, $from, $to),
            'google' => $this->google($html, $from, $to),
            default  => throw new RuntimeException('Ismeretlen fordító: ' . $this->provider),
        };
    }

    /**
     * A KEZELOFELULET szovegeinek forditasa, kotegelve.
     *
     * Mas feladat, mint a fejezetek forditasa: itt rovid gombfeliratok es
     * mondatok vannak, tobb szaz darab. Egyesevel kuldve tobb szaz keres
     * lenne, ezert egy hivasban megy 40-50 szoveg, szamozott listaban.
     *
     * A {helyorzoket} es a HTML-jelolest valtozatlanul kell hagyni - ezek
     * nem szoveg, hanem a mondatba beillesztett ertekek.
     *
     * @param array<int,string> $texts
     * @return array<int,string> ugyanannyi elem, ugyanabban a sorrendben
     */
    public function translateUi(array $texts, string $from, string $to): array
    {
        $texts = array_values($texts);
        if (!$texts) { return []; }
        if (!$this->isConfigured()) {
            throw new RuntimeException(t('Nincs beállítva gépi fordító. Beállítások → Gépi fordítás.'));
        }
        // A tobbi szolgaltato nem tud utasitast fogadni, ott marad az
        // egyesevel valo forditas (ok HTML-toredeket kapnak).
        if ($this->provider !== 'claude') {
            $out = [];
            foreach ($texts as $t) { $out[] = $this->translateHtml($t, $from, $to); }
            return $out;
        }

        $langName = ['hu' => 'Hungarian', 'en' => 'English', 'de' => 'German'];
        $src = $langName[$from] ?? $from;
        $dst = $langName[$to] ?? $to;

        $system = <<<TXT
        You translate the user interface of "Infinity Súgó", the help system of a
        Hungarian business management (ERP) product. Translate from {$src} to {$dst}.

        You receive a JSON array of interface strings. Return ONLY a JSON array of
        the same length, in the same order, with each string translated.

        Rules you must follow exactly:
        1. Keep every {placeholder} in curly braces EXACTLY as it is. They are values
           inserted at runtime (names, counts, URLs) - never translate or reorder them.
        2. Keep HTML tags (<b>, <br>, <a href="{url}">, <span class="...">) exactly as
           they are, including attributes. Translate only the text between tags.
        3. Keep leading and trailing spaces, punctuation and symbols (✕ ⠿ ● ○ ↕ ⊟ + ✎).
        4. These are BUTTON LABELS, TOOLTIPS and SHORT MESSAGES. Use the wording a
           native {$dst} software interface would use, not a literal translation.
        5. Product names stay: Infinity, Infinity Súgó, Word, PDF, Claude, DeepL.
        6. Return the JSON array and nothing else - no explanation, no code fence.
        TXT;

        $base = $this->endpoint !== '' ? $this->endpoint : 'https://api.anthropic.com';
        $res = $this->post($base . '/v1/messages', [
            'model'         => $this->model,
            'max_tokens'    => 32000,
            'output_config' => ['effort' => 'medium'],
            'system'        => $system,
            'messages'      => [['role' => 'user',
                                 'content' => json_encode($texts, JSON_UNESCAPED_UNICODE)]],
        ], [
            'content-type: application/json',
            'x-api-key: ' . $this->key,
            'anthropic-version: 2023-06-01',
        ], true, 300);

        $j = json_decode($res, true);
        if (($j['stop_reason'] ?? '') === 'refusal') {
            throw new RuntimeException(t('A Claude elutasította a kérést: ')
                . (string)($j['stop_details']['explanation'] ?? t('nincs indoklás')));
        }
        if (($j['stop_reason'] ?? '') === 'max_tokens') {
            throw new RuntimeException(t('mt.ui.tul.hosszu'));
        }
        // A valasz TOBB BLOKKBOL allhat (gondolkodas + szoveg), ezert nem a
        // nulladikat vesszuk, hanem osszefuzzuk a szoveges blokkokat. Enelkul
        // minden olyan koteg "ures valaszt" adott, ahol a modell elobb
        // gondolkodott.
        $txt = '';
        foreach ((array)($j['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') { $txt .= (string)($block['text'] ?? ''); }
        }
        $txt = trim($txt);
        if ($txt === '') { throw new RuntimeException(t('A Claude üres választ adott.')); }

        // ha kodkeretbe tette volna, leszedjuk
        $txt = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $txt);
        $out = json_decode((string)$txt, true);
        if (!is_array($out) || count($out) !== count($texts)) {
            throw new RuntimeException(t('mt.ui.rossz.valasz', ['kert' => count($texts),
                                                               'kapott' => is_array($out) ? count($out) : 0]));
        }
        return array_map(static fn($v): string => (string)$v, $out);
    }

    /**
     * Forditas Claude-dal (Anthropic Messages API).
     *
     * A tobbi szolgaltatotol elteroen itt UTASITAST adunk, nem csak szoveget:
     * a rendszeruzenet leirja, mit KELL valtozatlanul hagyni (HTML-jeloles,
     * kepek utvonala, a cimsorok sorszama), es atadja a sajat szakszotarat is.
     */
    private function claude(string $html, string $from, string $to): string
    {
        $langName = ['hu' => 'Hungarian', 'en' => 'English', 'de' => 'German'];
        $src = $langName[$from] ?? $from;
        $dst = $langName[$to] ?? $to;

        $system = <<<TXT
        You translate technical documentation for "Infinity", a Hungarian business
        management (ERP) system. Translate from {$src} to {$dst}.

        Rules you must follow exactly:
        1. The input is an HTML fragment. Return the SAME HTML structure, with only
           the human-readable text translated. Do not add, remove or reorder tags.
        2. Never change any attribute value: keep every src, href, class, id, style
           and colspan exactly as given. Image paths such as media/img_1a2b3c.png
           must stay byte-for-byte identical.
        3. <span class="hno">5.4.1</span> holds a chapter number - keep the digits
           unchanged, never translate or renumber them.
        4. Keep the user-interface labels of the Infinity system recognisable: these
           are menu items and button captions the reader sees on screen. Prefer the
           established accounting/ERP term in {$dst} over a literal word-by-word
           rendering.
        5. Do not translate proper nouns, product names, file names or code.
        6. Output ONLY the translated HTML fragment. No explanation, no markdown
           code fence, no surrounding prose.
        TXT;

        if ($this->glossary !== '') {
            $system .= "\n\nUse this glossary. The left side is the Hungarian term, "
                     . "the right side is how it must be translated:\n" . $this->glossary;
        }

        $base = $this->endpoint !== '' ? $this->endpoint : 'https://api.anthropic.com';

        $res = $this->post($base . '/v1/messages', [
            'model'      => $this->model,
            'max_tokens' => 32000,
            // A forditas szoveg-atalakitas, nem gondolkodtato feladat: kozepes
            // rafordital jo minoseget ad, es toredeke a koltsege a magasnak.
            'output_config' => ['effort' => 'medium'],
            'system'     => $system,
            'messages'   => [
                ['role' => 'user', 'content' => $html],
            ],
        ], [
            'Content-Type: application/json',
            'x-api-key: ' . $this->key,
            'anthropic-version: 2023-06-01',
        ], true, 300);

        $j = json_decode($res, true);
        if (!is_array($j)) {
            throw new RuntimeException('A Claude váratlan választ adott: ' . mb_substr($res, 0, 200));
        }
        if (($j['stop_reason'] ?? '') === 'refusal') {
            throw new RuntimeException('A Claude elutasította a kérést: '
                . (string)($j['stop_details']['explanation'] ?? t('nincs indoklás')));
        }
        if (!isset($j['content']) || !is_array($j['content'])) {
            throw new RuntimeException('A Claude váratlan választ adott: ' . mb_substr($res, 0, 200));
        }

        // A valasz tobb blokkbol allhat (pl. gondolkodas + szoveg) - minket
        // csak a szoveges reszek erdekelnek.
        $out = '';
        foreach ($j['content'] as $block) {
            if (($block['type'] ?? '') === 'text') { $out .= (string)($block['text'] ?? ''); }
        }
        $out = trim($out);

        if (($j['stop_reason'] ?? '') === 'max_tokens') {
            throw new RuntimeException('A fejezet túl hosszú volt egy menetben — '
                . t('a fordítás félbeszakadt. Bontsd rövidebb fejezetekre.'));
        }
        if ($out === '') {
            throw new RuntimeException(t('A Claude üres választ adott.'));
        }

        // Ha megis kodblokkba tette volna, lehantjuk
        if (preg_match('/^```(?:html)?\s*(.*?)\s*```$/is', $out, $m)) { $out = $m[1]; }

        return $out;
    }

    private function deepl(string $html, string $from, string $to): string
    {
        $base = $this->endpoint !== ''
            ? $this->endpoint
            : (str_ends_with($this->key, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com');

        $res = $this->post($base . '/v2/translate', [
            'text'         => $html,
            'source_lang'  => strtoupper($from),
            'target_lang'  => strtoupper($to === 'en' ? 'EN-GB' : $to),
            'tag_handling' => 'html',
        ], ['Authorization: DeepL-Auth-Key ' . $this->key]);

        $j = json_decode($res, true);
        if (!is_array($j) || !isset($j['translations'][0]['text'])) {
            throw new RuntimeException('A DeepL váratlan választ adott: ' . mb_substr($res, 0, 200));
        }
        return (string)$j['translations'][0]['text'];
    }

    private function libre(string $html, string $from, string $to): string
    {
        $payload = ['q' => $html, 'source' => $from, 'target' => $to, 'format' => 'html'];
        if ($this->key !== '') { $payload['api_key'] = $this->key; }

        $res = $this->post($this->endpoint . '/translate', $payload, ['Content-Type: application/json'], true);
        $j = json_decode($res, true);
        if (!is_array($j) || !isset($j['translatedText'])) {
            throw new RuntimeException('A LibreTranslate váratlan választ adott: ' . mb_substr($res, 0, 200));
        }
        return (string)$j['translatedText'];
    }

    private function google(string $html, string $from, string $to): string
    {
        $base = $this->endpoint !== '' ? $this->endpoint : 'https://translation.googleapis.com';
        $res = $this->post($base . '/language/translate/v2?key=' . urlencode($this->key), [
            'q'      => $html,
            'source' => $from,
            'target' => $to,
            'format' => 'html',
        ]);
        $j = json_decode($res, true);
        if (!is_array($j) || !isset($j['data']['translations'][0]['translatedText'])) {
            throw new RuntimeException('A Google Translate váratlan választ adott: ' . mb_substr($res, 0, 200));
        }
        return html_entity_decode((string)$j['data']['translations'][0]['translatedText'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function post(string $url, array $fields, array $headers = [], bool $json = false,
                         int $timeout = 60): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException(t('A PHP cURL kiterjesztés hiányzik, enélkül nincs gépi fordítás.'));
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json ? json_encode($fields, JSON_UNESCAPED_UNICODE) : http_build_query($fields),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException(t('mt.nem.erheto', ['reszlet' => $err]));
        }
        if ($code >= 400) {
            throw new RuntimeException('A fordítószolgáltatás hibát adott (HTTP ' . $code . '): ' . mb_substr((string)$body, 0, 200));
        }
        return (string)$body;
    }
}

/**
 * Egy forditas eltarolasa a celnyelvi cikk vazlatakent.
 * Ha meg nincs celnyelvi valtozat, letrehozza (ugyanazzal a slug-gal).
 *
 * @return int a celnyelvi cikk azonositoja
 */
function translate_store(
    PDO $db, int $srcId, string $to, string $html, string $title,
    string $how, ?int $userId, bool $publish = false
): int {
    $st = $db->prepare('SELECT * FROM help_article WHERE id = ?');
    $st->execute([$srcId]);
    $src = $st->fetch();
    if (!$src) {
        throw new RuntimeException(t('Nincs ilyen forrásfejezet.'));
    }

    // A nyelvi valtozatot elsosorban a FEJEZETSZAM koti a forrashoz, mert a
    // slug mostantol nyelvenkent elter (az angol oldal angol cimet kap).
    // A regi, kozos slugra epulo parokat a masodik keres talalja meg.
    $targetId = 0;
    if ((string)$src['chapter_no'] !== '') {
        $t = $db->prepare('SELECT id FROM help_article WHERE chapter_no = ? AND lang = ? LIMIT 1');
        $t->execute([$src['chapter_no'], $to]);
        $targetId = (int)($t->fetchColumn() ?: 0);
    }
    if ($targetId === 0) {
        $t = $db->prepare('SELECT id FROM help_article WHERE slug = ? AND lang = ? LIMIT 1');
        $t->execute([$src['slug'], $to]);
        $targetId = (int)($t->fetchColumn() ?: 0);
    }

    // Az uj celnyelvi cikk a SAJAT, leforditott cimebol kapja az URL-jet.
    // Ha nincs forditott cim, marad a forras slugja.
    $newSlug = $title !== '' ? help_slug((string)$src['chapter_no'], $title) : (string)$src['slug'];
    if ($newSlug === '') { $newSlug = (string)$src['slug']; }
    $probe = $db->prepare('SELECT 1 FROM help_article WHERE slug = ? AND lang = ?');
    $probe->execute([$newSlug, $to]);
    if ($probe->fetchColumn()) { $newSlug = (string)$src['slug']; }

    if ($targetId === 0) {
        // celnyelvi modul: ugyanaz a chapter_no; ha nincs, atmasoljuk
        $mst = $db->prepare('SELECT m2.id FROM help_module m1 JOIN help_module m2
                                ON m2.chapter_no = m1.chapter_no AND m2.lang = ?
                              WHERE m1.id = ?');
        $mst->execute([$to, $src['module_id']]);
        $moduleId = (int)($mst->fetchColumn() ?: 0);
        if ($moduleId === 0) {
            $mi = $db->prepare('INSERT INTO help_module (chapter_no, slug, title, lang, sort_order)
                                SELECT chapter_no, slug, title, ?, sort_order FROM help_module WHERE id = ?');
            $mi->execute([$to, $src['module_id']]);
            $moduleId = (int)$db->lastInsertId();
        }
        $ai = $db->prepare("INSERT INTO help_article
                (module_id, chapter_no, slug, title, lang, body_html, plain_text, doc_version,
                 updated_at, content_hash, sort_order, is_published, draft_html, draft_title,
                 draft_by, draft_at, source)
                VALUES (?,?,?,?,?,'','',?, CURRENT_DATE, MD5(?), ?, 0, ?, ?, ?, NOW(), 'editor')");
        $ai->execute([
            $moduleId, $src['chapter_no'], $newSlug, $title !== '' ? $title : $src['title'], $to,
            $src['doc_version'], $src['slug'] . $to, (int)$src['sort_order'],
            $html, $title !== '' ? $title : null, $userId,
        ]);
        $targetId = (int)$db->lastInsertId();
    } else {
        $db->prepare('UPDATE help_article SET draft_html = ?, draft_title = ?, draft_by = ?, draft_at = now() WHERE id = ?')
           ->execute([$html, $title !== '' ? $title : null, $userId, $targetId]);
    }

    $db->prepare('UPDATE help_article SET translated_from_hash = ?, translated_by = ?, translated_at = now() WHERE id = ?')
       ->execute([$src['content_hash'], mb_substr($how, 0, 16), $targetId]);

    if ($publish) {
        $pub = $db->prepare('CALL help_publish(?, ?, ?, ?, NULL, 1)');
        $pub->execute([$targetId, $userId, null, 'mod']);
        $pub->closeCursor();
        $b = $db->prepare('SELECT body_html FROM help_article WHERE id = ?');
        $b->execute([$targetId]);
        sections_rebuild($db, $targetId, (string)$b->fetchColumn());
    }

    return $targetId;
}

/** Be van-e kapcsolva az automatikus forditas, es van-e mivel forditani? */
/**
 * A felulet-szovegek kotegekre bontasa a HOSSZUK szerint.
 *
 * Fix darabszammal nem mukodik: a legtobb szoveg ket szo, de akad 240
 * karakteres sugoszoveg is. Negyven ilyenbol mar akkora keres lesz, hogy a
 * valasz nem fer a keretbe - es akkor uresen jon vissza.
 *
 * @param array<string,string> $todo kulcs => forrasszoveg
 * @return array<int, array<int,string>> kulcskotegek
 */
function mt_ui_chunks(array $todo, int $maxChars = 4000, int $maxItems = 40): array
{
    $chunks = [];
    $cur = [];
    $len = 0;
    foreach ($todo as $key => $src) {
        $l = mb_strlen((string)$src) + 8;          // + a JSON idezojelek, vesszo
        if ($cur && ($len + $l > $maxChars || count($cur) >= $maxItems)) {
            $chunks[] = $cur;
            $cur = [];
            $len = 0;
        }
        $cur[] = $key;
        $len += $l;
    }
    if ($cur) { $chunks[] = $cur; }
    return $chunks;
}

function mt_auto_on(PDO $db, array $cfg): bool
{
    try {
        $v = (string)$db->query("SELECT value FROM help_setting WHERE `key` = 'mt_auto'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
    return $v === '1' && Translator::fromConfig($cfg, $db)->isConfigured();
}

/**
 * Egy frissen importalt/letrehozott MAGYAR fejezet automatikus leforditasa
 * a tobbi nyelvre. Csak vazlatot keszit - kozzetenni ember dont.
 *
 * @return array{done: list<string>, failed: array<string,string>}
 */
function mt_auto_translate(PDO $db, array $cfg, int $srcId, ?int $userId, ?array $targets = null): array
{
    // alapbol MINDEN celnyelv (az admin_langs()-bol), hogy uj nyelv felvetelekor
    // ne kelljen ezt a helyet is megkeresni
    $targets ??= function_exists('admin_target_langs') ? array_keys(admin_target_langs()) : ['en', 'de'];
    $done = []; $failed = [];
    $tr = Translator::fromConfig($cfg, $db);

    $st = $db->prepare("SELECT lang, title, body_html, draft_html, draft_title
                          FROM help_article WHERE id = ?");
    $st->execute([$srcId]);
    $src = $st->fetch();
    if (!$src)                 { return ['done' => [], 'failed' => [], 'why' => 'nincs']; }
    if ($src['lang'] !== 'hu') { return ['done' => [], 'failed' => [], 'why' => 'nem-magyar']; }

    // A meg kozze nem tett vazlatot is forditjuk: egy frissen megirt fejezetnek
    // a body_html-je ures, a szoveg a draft_html-ben all.
    if (trim(help_plain((string)$src['body_html'])) === '' && $src['draft_html'] !== null) {
        $src['body_html'] = $src['draft_html'];
        if ((string)($src['draft_title'] ?? '') !== '') { $src['title'] = $src['draft_title']; }
    }
    if (trim(help_plain((string)$src['body_html'])) === '') {
        return ['done' => [], 'failed' => [], 'why' => 'ures'];
    }

    foreach ($targets as $to) {
        try {
            $html  = $tr->translateHtml((string)$src['body_html'], 'hu', $to);
            $title = trim(help_plain($tr->translateHtml(
                '<p>' . htmlspecialchars((string)$src['title'], ENT_QUOTES, 'UTF-8') . '</p>', 'hu', $to)));
            $clean = help_clean_html($html);
            [$clean] = help_anchorize($clean);
            translate_store($db, $srcId, $to, $clean, $title, $tr->provider, $userId, false);
            $done[] = $to;
        } catch (Throwable $e) {
            $failed[$to] = $e->getMessage();
        }
    }
    return ['done' => $done, 'failed' => $failed, 'why' => ''];
}
