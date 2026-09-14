<?php
/**
 * admin.php - az Infinity Súgó szerkesztői és adminisztrációs felülete.
 *
 *   /admin.php                 -> Áttekintés (bejelentkezés után)
 *   /admin.php?p=articles      -> Fejezetek szerkesztése, közzététel, verziók
 *   /admin.php?p=modules       -> Modulok
 *   /admin.php?p=import        -> Word (.docx) betöltés összehasonlítással
 *   /admin.php?p=translate     -> Fordítás (HU -> EN/DE), gépi nyersfordítással
 *   /admin.php?p=screens       -> Képernyő -> fejezet hozzárendelés
 *   /admin.php?p=media         -> Képek
 *   /admin.php?p=users         -> Felhasználók
 *   /admin.php?p=settings      -> Beállítások, kiadás lezárása
 *
 * Az elso belepes: admin / 12345678 - utana a rendszer kotelezoen jelszot cserel.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/diff.php';
require __DIR__ . '/lib/docx.php';
require __DIR__ . '/lib/mt.php';
require __DIR__ . '/lib/admin_layout.php';
require __DIR__ . '/lib/admin_actions.php';
require __DIR__ . '/lib/admin_pages.php';
require __DIR__ . '/lib/admin_pages2.php';

$cfg = require __DIR__ . '/config.php';
auth_start($cfg);

header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

try {
    $db = help_db_rw($cfg);
} catch (PDOException $e) {
    http_response_code(503);
    admin_head('Adatbázishiba');
    echo '<div class="page" style="max-width:640px"><div class="msg msg--err">'
       . '<b>Az adatbázis nem érhető el.</b> Az admin felület írás-olvasás jogú kapcsolatot használ '
       . '(alapértelmezés szerint a <span class="mono">help_rw</span> felhasználót). Ellenőrizd a '
       . '<span class="mono">HELP_DB_ADMIN_USER</span> / <span class="mono">HELP_DB_ADMIN_PASS</span> beállítást.'
       . '</div></div>';
    admin_foot();
    exit;
}

// ------------------------------------------------------------ kijelentkezés
if (($_GET['a'] ?? '') === 'logout') {
    $u = auth_user();
    if ($u) { audit($db, (int)$u['id'], $u['username'], 'logout'); }
    auth_logout();
    auth_start($cfg);
    flash('ok', 'Kiléptél.');
    header('Location: ' . admin_url(['p' => 'login']), true, 303);
    exit;
}

// ------------------------------------------------------------ írási műveletek
$action = (string)($_POST['a'] ?? '');
if ($action !== '') {
    // a bejelentkezésen kívül mindenhez kell élő munkamenet
    if ($action !== 'login' && auth_user() === null) {
        flash('err', 'A munkamenet lejárt, jelentkezz be újra.');
        header('Location: ' . admin_url(['p' => 'login']), true, 303);
        exit;
    }
    admin_handle_action($action, $db, $cfg);
    exit;
}

// ------------------------------------------------------------ megjelenítés
$user = auth_user();
if ($user === null) {
    page_login();
    exit;
}
if ($user['must_change'] && ($_GET['p'] ?? '') !== 'chpw') {
    header('Location: ' . admin_url(['p' => 'chpw']), true, 303);
    exit;
}

$page = (string)($_GET['p'] ?? 'dashboard');
$lang = array_key_exists((string)($_GET['lang'] ?? ''), ADMIN_LANGS) ? (string)$_GET['lang'] : 'hu';

// a füleken megjelenő számok
$counts = [];
try {
    $c = $db->query("
        SELECT (SELECT count(*) FROM help_article WHERE draft_html IS NOT NULL) AS drafts,
               (SELECT count(*) FROM help_article WHERE lang = 'hu')            AS articles,
               (SELECT count(*) FROM help_module  WHERE lang = 'hu')            AS modules,
               (SELECT count(*) FROM help_screen_map)                           AS screens,
               (SELECT count(*) FROM help_user WHERE is_active)                 AS users
    ")->fetch();
    $counts = [
        'articles' => (int)$c['articles'],
        'modules'  => (int)$c['modules'],
        'screens'  => (int)$c['screens'],
        'users'    => (int)$c['users'],
    ];
    if ((int)$c['drafts'] > 0) { $counts['dashboard'] = (int)$c['drafts']; }
} catch (Throwable $e) {
    // a fulszamok hianya ne akassza meg a lapot
}

if ($page === 'chpw')    { page_chpw((bool)$user['must_change']); exit; }
if ($page === 'account') { page_account($db); exit; }

if (!auth_can($page) && $page !== 'dashboard') {
    http_response_code(403);
    admin_head('Nincs jogosultság', '', $counts);
    echo '<div class="page"><div class="msg msg--err"><b>Ehhez a felülethez nincs jogosultságod.</b> '
       . 'A szerepköröd: <b>' . h($user['role']) . '</b>.</div></div>';
    admin_foot();
    exit;
}

switch ($page) {
    case 'articles':
        page_articles($db, $lang, (int)($_GET['id'] ?? 0), $counts);
        break;
    case 'modules':
        page_modules($db, $lang, $counts);
        break;
    case 'import':
        page_import($db, $cfg, $lang, (int)($_GET['import'] ?? 0), $counts);
        break;
    case 'translate':
        page_translate($db, $cfg, (int)($_GET['src'] ?? 0), (string)($_GET['to'] ?? 'en'), $counts);
        break;
    case 'screens':
        page_screens($db, $counts);
        break;
    case 'media':
        page_media($db, $cfg, (int)($_GET['page'] ?? 1), $counts);
        break;
    case 'users':
        page_users($db, $counts);
        break;
    case 'settings':
        page_settings($db, $cfg, $counts);
        break;
    default:
        page_dashboard($db, $cfg, $counts);
}
