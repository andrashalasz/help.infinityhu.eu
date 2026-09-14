<?php
/**
 * mt.php - gepi forditas (machine translation).
 *
 * Harom szolgaltatot ismer, mindegyik opcionalis:
 *   deepl   - https://api-free.deepl.com  vagy  https://api.deepl.com   (kulcs kell)
 *   libre   - LibreTranslate, sajat szerveren is futtathato             (kulcs nem mindig kell)
 *   google  - Google Cloud Translation v2                              (kulcs kell)
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
    public string $provider;
    private string $endpoint;
    private string $key;

    public function __construct(string $provider, string $endpoint, string $key)
    {
        $this->provider = $provider !== '' ? $provider : 'none';
        $this->endpoint = rtrim($endpoint, '/');
        $this->key      = $key;
    }

    /** A config es az adatbazis-beallitasok osszefesulese (a kornyezeti valtozo eros). */
    public static function fromConfig(array $cfg, ?PDO $db = null): self
    {
        $provider = $cfg['mt_provider'] ?? '';
        $endpoint = $cfg['mt_endpoint'] ?? '';
        $key      = $cfg['mt_key'] ?? '';

        if ($db !== null && ($provider === '' || $key === '')) {
            try {
                $rows = $db->query("SELECT key, value FROM help_setting WHERE key IN ('mt_provider','mt_endpoint','mt_key')")->fetchAll();
                $s = [];
                foreach ($rows as $r) { $s[$r['key']] = (string)$r['value']; }
                if ($provider === '') { $provider = $s['mt_provider'] ?? 'none'; }
                if ($endpoint === '') { $endpoint = $s['mt_endpoint'] ?? ''; }
                if ($key === '')      { $key      = $s['mt_key'] ?? ''; }
            } catch (Throwable $e) {
                // beallitas-tabla nelkul is mukodjon
            }
        }
        return new self($provider ?: 'none', $endpoint, $key);
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
            'deepl'  => 'DeepL',
            'libre'  => 'LibreTranslate',
            'google' => 'Google Translate',
            default  => 'nincs beállítva',
        };
    }

    /**
     * HTML forditasa. Hiba eseten RuntimeException.
     */
    public function translateHtml(string $html, string $from, string $to): string
    {
        if (trim($html) === '') { return ''; }
        if (!$this->isConfigured()) {
            throw new RuntimeException('Nincs beállítva gépi fordító. Beállítások → Gépi fordítás.');
        }
        return match ($this->provider) {
            'deepl'  => $this->deepl($html, $from, $to),
            'libre'  => $this->libre($html, $from, $to),
            'google' => $this->google($html, $from, $to),
            default  => throw new RuntimeException('Ismeretlen fordító: ' . $this->provider),
        };
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

    private function post(string $url, array $fields, array $headers = [], bool $json = false): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('A PHP cURL kiterjesztés hiányzik, enélkül nincs gépi fordítás.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json ? json_encode($fields, JSON_UNESCAPED_UNICODE) : http_build_query($fields),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('A fordítószolgáltatás nem érhető el: ' . $err);
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
        throw new RuntimeException('Nincs ilyen forrásfejezet: ' . $srcId);
    }

    $t = $db->prepare('SELECT id FROM help_article WHERE slug = ? AND lang = ?');
    $t->execute([$src['slug'], $to]);
    $targetId = (int)($t->fetchColumn() ?: 0);

    if ($targetId === 0) {
        // celnyelvi modul: ugyanaz a chapter_no; ha nincs, atmasoljuk
        $mst = $db->prepare('SELECT m2.id FROM help_module m1 JOIN help_module m2
                                ON m2.chapter_no = m1.chapter_no AND m2.lang = ?
                              WHERE m1.id = ?');
        $mst->execute([$to, $src['module_id']]);
        $moduleId = (int)($mst->fetchColumn() ?: 0);
        if ($moduleId === 0) {
            $mi = $db->prepare('INSERT INTO help_module (chapter_no, slug, title, lang, sort_order)
                                SELECT chapter_no, slug, title, ?, sort_order FROM help_module WHERE id = ?
                                RETURNING id');
            $mi->execute([$to, $src['module_id']]);
            $moduleId = (int)$mi->fetchColumn();
        }
        $ai = $db->prepare('INSERT INTO help_article
                (module_id, chapter_no, slug, title, lang, body_html, plain_text, doc_version,
                 updated_at, content_hash, sort_order, is_published, draft_html, draft_title,
                 draft_by, draft_at, source)
                VALUES (?,?,?,?,?,\'\',\'\',?, CURRENT_DATE, md5(?), ?, false, ?, ?, ?, now(), \'editor\')
                RETURNING id');
        $ai->execute([
            $moduleId, $src['chapter_no'], $src['slug'], $title !== '' ? $title : $src['title'], $to,
            $src['doc_version'], $src['slug'] . $to, (int)$src['sort_order'],
            $html, $title !== '' ? $title : null, $userId,
        ]);
        $targetId = (int)$ai->fetchColumn();
    } else {
        $db->prepare('UPDATE help_article SET draft_html = ?, draft_title = ?, draft_by = ?, draft_at = now() WHERE id = ?')
           ->execute([$html, $title !== '' ? $title : null, $userId, $targetId]);
    }

    $db->prepare('UPDATE help_article SET translated_from_hash = ?, translated_by = ?, translated_at = now() WHERE id = ?')
       ->execute([$src['content_hash'], mb_substr($how, 0, 16), $targetId]);

    if ($publish) {
        $db->prepare('SELECT help_publish(?, ?, ?, ?, NULL, true)')
           ->execute([$targetId, $userId, null, 'mod']);
        $b = $db->prepare('SELECT body_html FROM help_article WHERE id = ?');
        $b->execute([$targetId]);
        sections_rebuild($db, $targetId, (string)$b->fetchColumn());
    }

    return $targetId;
}

/** Be van-e kapcsolva az automatikus forditas, es van-e mivel forditani? */
function mt_auto_on(PDO $db, array $cfg): bool
{
    try {
        $v = (string)$db->query("SELECT value FROM help_setting WHERE key = 'mt_auto'")->fetchColumn();
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
function mt_auto_translate(PDO $db, array $cfg, int $srcId, ?int $userId, array $targets = ['en', 'de']): array
{
    $done = []; $failed = [];
    $tr = Translator::fromConfig($cfg, $db);

    $st = $db->prepare("SELECT lang, title, body_html FROM help_article WHERE id = ?");
    $st->execute([$srcId]);
    $src = $st->fetch();
    if (!$src || $src['lang'] !== 'hu') { return ['done' => [], 'failed' => []]; }

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
    return ['done' => $done, 'failed' => $failed];
}
