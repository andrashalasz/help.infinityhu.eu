<?php
/**
 * media.php - kepek es videok tarolasa.
 *
 * A fajlnev mindig a tartalom sha256-janak elso 12 jegye, ezert ugyanaz a
 * fajl csak egyszer kerul a lemezre, barhany fejezet hivatkozik ra.
 * A szerkeszto ugyanezt a fuggvenyt hasznalja a kozvetlen feltolteshez.
 */
declare(strict_types=1);

const MEDIA_IMAGE_TYPES = [
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    'image/svg+xml' => 'svg',
];

const MEDIA_VIDEO_TYPES = [
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/quicktime' => 'mov',
];

const MEDIA_MAX_IMAGE = 25 * 1024 * 1024;    // 25 MB
const MEDIA_MAX_VIDEO = 400 * 1024 * 1024;   // 400 MB

function media_dir(array $cfg): string
{
    return rtrim($cfg['media_dir'], '/');
}

/** A bongeszotol jovo tipus nem megbizhato - a fajl tartalmabol allapitjuk meg. */
function media_detect(string $path): string
{
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        if ($f !== false) {
            $m = finfo_file($f, $path);
            finfo_close($f);
            if (is_string($m) && $m !== '') { return $m; }
        }
    }
    $i = @getimagesize($path);
    return is_array($i) && isset($i['mime']) ? (string)$i['mime'] : 'application/octet-stream';
}

/**
 * Egy feltoltott fajl eltarolasa.
 *
 * @return array{ok:bool, error?:string, filename?:string, url?:string, kind?:string,
 *                width?:int, height?:int, bytes?:int, existed?:bool}
 */
function media_store(PDO $db, array $cfg, string $tmpPath, string $originalName, ?int $userId): array
{
    if (!is_file($tmpPath)) {
        return ['ok' => false, 'error' => 'A feltöltött fájl nem érhető el.'];
    }

    $dir = media_dir($cfg);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'A képek mappája nem hozható létre: ' . $dir];
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'error' => 'A képek mappája nem írható: ' . $dir];
    }

    $mime = media_detect($tmpPath);
    $size = (int)filesize($tmpPath);

    if (isset(MEDIA_IMAGE_TYPES[$mime])) {
        $kind = 'image';
        $ext  = MEDIA_IMAGE_TYPES[$mime];
        $max  = MEDIA_MAX_IMAGE;
    } elseif (isset(MEDIA_VIDEO_TYPES[$mime])) {
        $kind = 'video';
        $ext  = MEDIA_VIDEO_TYPES[$mime];
        $max  = MEDIA_MAX_VIDEO;
    } else {
        return ['ok' => false, 'error' => 'Nem támogatott fájltípus: ' . $mime
            . '. Kép: PNG, JPG, GIF, WebP, SVG — videó: MP4, WebM, MOV.'];
    }

    if ($size > $max) {
        return ['ok' => false, 'error' => 'A fájl túl nagy (' . help_bytes($size)
            . '), a megengedett legfeljebb ' . help_bytes($max) . '.'];
    }

    $sha  = hash_file('sha256', $tmpPath);
    if ($sha === false) {
        return ['ok' => false, 'error' => 'A fájl nem olvasható.'];
    }
    $name   = ($kind === 'video' ? 'vid_' : 'img_') . substr($sha, 0, 12) . '.' . $ext;
    $target = $dir . '/' . $name;
    $existed = is_file($target);

    if (!$existed) {
        // move_uploaded_file csak valodi feltoltesre mukodik, ezert elagazunk
        $moved = is_uploaded_file($tmpPath)
            ? @move_uploaded_file($tmpPath, $target)
            : @copy($tmpPath, $target);
        if (!$moved) {
            return ['ok' => false, 'error' => 'A fájl mentése nem sikerült.'];
        }
        @chmod($target, 0664);
    }

    $w = null; $h = null;
    if ($kind === 'image' && $ext !== 'svg') {
        $i = @getimagesize($target);
        if (is_array($i)) { $w = (int)$i[0]; $h = (int)$i[1]; }
    }

    try {
        $db->prepare('INSERT INTO help_media (filename, sha256, mime, bytes, width, height, kind, title, uploaded_by)
                      VALUES (?,?,?,?,?,?,?,?,?)
                      ON CONFLICT (sha256) DO UPDATE SET filename = excluded.filename')
           ->execute([$name, $sha, $mime, $size, $w, $h, $kind,
                      mb_substr(pathinfo($originalName, PATHINFO_FILENAME), 0, 255), $userId]);
    } catch (Throwable $e) {
        // a nyilvantartas hianya ne akadalyozza a hasznalatot - a fajl mar a helyen van
    }

    return [
        'ok'       => true,
        'filename' => $name,
        'url'      => '/media/' . $name,
        'kind'     => $kind,
        'width'    => $w,
        'height'   => $h,
        'bytes'    => $size,
        'existed'  => $existed,
    ];
}

/** A szerkesztobe beszurando HTML-reszlet. */
function media_snippet(array $stored, string $alt = ''): string
{
    $url = h($stored['url']);
    if ($stored['kind'] === 'video') {
        return '<figure class="vid"><video controls preload="metadata" src="' . $url . '"></video>'
             . ($alt !== '' ? '<figcaption>' . h($alt) . '</figcaption>' : '') . '</figure>';
    }
    $size = '';
    if (!empty($stored['width']) && !empty($stored['height'])) {
        $size = ' width="' . (int)$stored['width'] . '" height="' . (int)$stored['height'] . '"';
    }
    return '<p><img src="' . $url . '" class="shot" alt="' . h($alt) . '"' . $size . ' loading="lazy"></p>';
}

/** A PHP feltoltesi hibakodok emberi nyelven. */
function media_upload_error(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'A fájl nagyobb, mint amennyit a szerver elfogad (upload_max_filesize / post_max_size).',
        UPLOAD_ERR_PARTIAL    => 'A feltöltés félbeszakadt.',
        UPLOAD_ERR_NO_FILE    => 'Nem választottál fájlt.',
        UPLOAD_ERR_NO_TMP_DIR => 'Hiányzik az ideiglenes mappa a szerveren.',
        UPLOAD_ERR_CANT_WRITE => 'A szerver nem tudta lemezre írni a fájlt.',
        UPLOAD_ERR_EXTENSION  => 'Egy PHP-kiterjesztés megállította a feltöltést.',
        default               => 'A feltöltés nem sikerült.',
    };
}
