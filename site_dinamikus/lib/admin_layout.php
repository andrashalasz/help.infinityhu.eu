<?php
/**
 * admin_layout.php - az admin felulet kozos kerete es apro segedei.
 */
declare(strict_types=1);

const ADMIN_TABS = [
    'dashboard' => 'Áttekintés',
    'articles'  => 'Fejezetek',
    'modules'   => 'Modulok',
    'import'    => 'Word import',
    'translate' => 'Fordítás',
    'screens'   => 'Képernyők',
    'media'     => 'Képek',
    'users'     => 'Felhasználók',
    'settings'  => 'Beállítások',
];

const ADMIN_LANGS = ['hu' => 'Magyar', 'en' => 'English', 'de' => 'Deutsch'];

function flash(string $type, string $text): void
{
    $_SESSION['flash'][] = ['type' => $type, 'text' => $text];
}

function flash_render(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $out .= '<div class="msg msg--' . h($f['type']) . '">' . $f['text'] . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

function admin_url(array $params = []): string
{
    return 'admin.php' . ($params ? '?' . http_build_query($params) : '');
}

function admin_head(string $title, string $page = '', array $counts = []): void
{
    $u = auth_user();
    ?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($title) ?> — Infinity Súgó admin</title>
<meta name="color-scheme" content="light dark">
<script>(function(){try{
  var t=localStorage.getItem('help.theme');
  if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}
  var f=parseFloat(localStorage.getItem('help.fs'));
  if(f>=0.85&&f<=1.6){document.documentElement.style.setProperty('--fs',String(f));}
}catch(e){}})();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="admin">
<?php if ($u !== null): ?>
<header class="ashell">
  <a class="ashell__brand" href="<?= h(admin_url()) ?>">
    <span class="ashell__logo"><?= help_logo(28) ?></span> Infinity Súgó
  </a>
  <span class="ashell__tag">admin</span>
  <span class="ashell__spacer"></span>

  <a class="sbtn" href="/hu/" target="_blank" rel="noopener" title="A súgó megnyitása új lapon">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14 21 3"/></svg>
  </a>
  <button class="sbtn" id="theme-toggle" title="Világos / sötét téma"></button>

  <span class="ashell__user">
    <span class="ashell__av"><?= h(mb_strtoupper(mb_substr($u['display_name'], 0, 1))) ?></span>
    <span><?= h($u['display_name']) ?> · <?= h($u['role']) ?></span>
  </span>
  <a class="sbtn" href="<?= h(admin_url(['p' => 'account'])) ?>" title="Saját fiók, jelszócsere">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>
  </a>
  <a class="sbtn" href="<?= h(admin_url(['a' => 'logout'])) ?>" title="Kilépés">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v2"/><path d="M19 12H9m10 0-3-3m3 3-3 3"/></svg>
  </a>
</header>

<nav class="tabs">
  <?php foreach (ADMIN_TABS as $key => $label): ?>
    <?php if (!auth_can($key)) { continue; } ?>
    <a class="<?= $page === $key ? 'on' : '' ?>" href="<?= h(admin_url(['p' => $key])) ?>">
      <?= h($label) ?><?php if (isset($counts[$key])): ?><span class="n"><?= (int)$counts[$key] ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>
<?php endif;
}

function admin_foot(): void
{
    ?>
<script src="/assets/admin.js" defer></script>
</body>
</html>
    <?php
}

/**
 * A cikk szakaszainak (help_section) ujraepitese a kozzetett HTML-bol.
 * Ezt hasznalja az olvasoi oldal jobb oldali tartalomjegyzeke, ezert minden
 * kozzetetel utan frissiteni kell.
 */
function sections_rebuild(PDO $db, int $articleId, string $html): int
{
    [, $sections] = help_anchorize($html);

    $db->prepare('DELETE FROM help_section WHERE article_id = ?')->execute([$articleId]);
    if (!$sections) { return 0; }

    $st = $db->prepare('INSERT INTO help_section (article_id, chapter_no, anchor, title, level, plain_text, sort_order)
                        VALUES (?,?,?,?,?,?,?)');
    $i = 0;
    foreach ($sections as $s) {
        $st->execute([
            $articleId,
            mb_substr($s['chapter_no'], 0, 16),
            mb_substr($s['anchor'], 0, 160),
            mb_substr($s['title'], 0, 255),
            $s['level'],
            '',
            $i++,
        ]);
    }
    return $i;
}

/** Modulok + cikkeik egy nyelven, az admin listakhoz. */
function admin_tree(PDO $db, string $lang): array
{
    $mods = $db->prepare('SELECT id, chapter_no, title, slug, sort_order FROM help_module WHERE lang = ? ORDER BY sort_order, id');
    $mods->execute([$lang]);
    $modules = $mods->fetchAll();
    if (!$modules) { return []; }

    $st = $db->prepare('SELECT id, module_id, chapter_no, slug, title, sort_order, is_published,
                               draft_html IS NOT NULL AS has_draft, updated_at
                          FROM help_article WHERE lang = ? ORDER BY sort_order, id');
    $st->execute([$lang]);
    $by = [];
    foreach ($st->fetchAll() as $a) { $by[(int)$a['module_id']][] = $a; }

    foreach ($modules as &$m) { $m['articles'] = $by[(int)$m['id']] ?? []; }
    unset($m);
    return $modules;
}

function admin_setting(PDO $db, string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($db->query('SELECT key, value FROM help_setting')->fetchAll() as $r) {
            $cache[$r['key']] = (string)$r['value'];
        }
    }
    return $cache[$key] ?? $default;
}
