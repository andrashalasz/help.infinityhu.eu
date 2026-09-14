<?php
/**
 * auth.php - bejelentkezes, munkamenet, jelszo, jogosultsag, audit.
 *
 * Szerepkorok:
 *   admin      - mindent
 *   editor     - fejezetek, modulok, import, kepernyok, media
 *   translator - csak a Forditas ful
 */
declare(strict_types=1);

const HELP_ROLES = ['admin', 'editor', 'translator'];

function auth_start(array $cfg): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_name($cfg['session_name'] ?? 'help_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    ]);
    session_start();

    $max = (int)($cfg['session_lifetime'] ?? 28800);
    if (isset($_SESSION['seen']) && (time() - (int)$_SESSION['seen']) > $max) {
        auth_logout();
        session_start();
    }
    $_SESSION['seen'] = time();

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function csrf_token(): string
{
    return (string)($_SESSION['csrf'] ?? '');
}

function csrf_check(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals(csrf_token(), $token);
}

function auth_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function auth_is(string ...$roles): bool
{
    $u = auth_user();
    return $u !== null && in_array($u['role'], $roles, true);
}

/** Jogosultsag-ellenorzes fulenkent. */
function auth_can(string $area): bool
{
    $u = auth_user();
    if ($u === null) { return false; }
    if ($u['role'] === 'admin') { return true; }
    if ($u['role'] === 'editor') {
        return in_array($area, ['dashboard', 'articles', 'modules', 'import', 'translate', 'screens', 'media', 'export', 'trash'], true);
    }
    if ($u['role'] === 'translator') {
        return in_array($area, ['dashboard', 'translate', 'export'], true);
    }
    return false;
}

/**
 * Bejelentkezes. Visszaad: ['ok'=>bool, 'error'=>?string].
 * Ot sikertelen probalkozas utan 10 percre zarolja a fiokot.
 */
function auth_login(PDO $db, string $username, string $password): array
{
    $st = $db->prepare('SELECT * FROM help_user WHERE lower(username) = lower(?) LIMIT 1');
    $st->execute([$username]);
    $u = $st->fetch();

    // Idozites-kiegyenlites: ismeretlen felhasznalonal is futtatunk egy hash-ellenorzest.
    if (!$u) {
        password_verify($password, '$2y$10$usesomesillystringforsalt0000000000000000000000000000000');
        return ['ok' => false, 'error' => 'Hibás felhasználónév vagy jelszó.'];
    }

    if (!$u['is_active']) {
        return ['ok' => false, 'error' => 'Ez a fiók inaktív.'];
    }
    if ($u['locked_until'] !== null && strtotime((string)$u['locked_until']) > time()) {
        $mins = max(1, (int)ceil((strtotime((string)$u['locked_until']) - time()) / 60));
        return ['ok' => false, 'error' => "Túl sok sikertelen próbálkozás. Próbáld újra {$mins} perc múlva."];
    }

    if (!password_verify($password, $u['password_hash'])) {
        $fails = (int)$u['failed_logins'] + 1;
        $lock  = $fails >= 5 ? "now() + interval '10 minutes'" : 'NULL';
        $db->prepare("UPDATE help_user SET failed_logins = ?, locked_until = {$lock} WHERE id = ?")
           ->execute([$fails, $u['id']]);
        audit($db, null, $username, 'login.fail', 'user:' . $username, "sikertelen probalkozas #{$fails}");
        return ['ok' => false, 'error' => 'Hibás felhasználónév vagy jelszó.'];
    }

    // A bcrypt-koltseg emelese eseten frissitjuk a hasht.
    if (password_needs_rehash($u['password_hash'], PASSWORD_BCRYPT, ['cost' => 10])) {
        $db->prepare('UPDATE help_user SET password_hash = ? WHERE id = ?')
           ->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]), $u['id']]);
    }

    $db->prepare('UPDATE help_user SET failed_logins = 0, locked_until = NULL, last_login_at = now() WHERE id = ?')
       ->execute([$u['id']]);

    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['user'] = [
        'id'           => (int)$u['id'],
        'username'     => $u['username'],
        'display_name' => $u['display_name'] !== '' ? $u['display_name'] : $u['username'],
        'role'         => $u['role'],
        'must_change'  => (bool)$u['must_change_pw'],
    ];
    audit($db, (int)$u['id'], $u['username'], 'login', 'user:' . $u['username'], null);
    return ['ok' => true, 'error' => null];
}

function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Jelszo-erosseg. Visszaad: null = rendben, kulonben a hibauzenet. */
function auth_password_problem(string $pw, string $username = ''): ?string
{
    if (mb_strlen($pw) < 8) {
        return 'A jelszó legyen legalább 8 karakter.';
    }
    if (mb_strlen($pw) > 200) {
        return 'A jelszó túl hosszú (legfeljebb 200 karakter).';
    }
    if ($username !== '' && mb_strtolower($pw) === mb_strtolower($username)) {
        return 'A jelszó ne egyezzen meg a felhasználónévvel.';
    }
    $weak = ['12345678', '123456789', '1234567890', 'password', 'jelszo123', 'qwertyui', 'admin123', 'infinity'];
    if (in_array(mb_strtolower($pw), $weak, true)) {
        return 'Ez a jelszó túl könnyen kitalálható, válassz másikat.';
    }
    if (!preg_match('/[A-Za-zÁÉÍÓÖŐÚÜŰáéíóöőúüű]/u', $pw) || !preg_match('/\d/', $pw)) {
        return 'A jelszó tartalmazzon betűt és számot is.';
    }
    return null;
}

function auth_set_password(PDO $db, int $userId, string $newPassword): void
{
    $db->prepare('UPDATE help_user SET password_hash = ?, must_change_pw = false WHERE id = ?')
       ->execute([password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 10]), $userId]);
    if (isset($_SESSION['user']) && (int)$_SESSION['user']['id'] === $userId) {
        $_SESSION['user']['must_change'] = false;
    }
}

function audit(PDO $db, ?int $userId, ?string $username, string $action, ?string $object = null, ?string $detail = null): void
{
    try {
        $db->prepare('INSERT INTO help_audit (user_id, username, action, object, detail, ip) VALUES (?,?,?,?,?,?)')
           ->execute([$userId, $username, $action, $object, $detail, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        // az audit sosem akaszthatja meg a muveletet
    }
}

function audit_me(PDO $db, string $action, ?string $object = null, ?string $detail = null): void
{
    $u = auth_user();
    audit($db, $u['id'] ?? null, $u['username'] ?? null, $action, $object, $detail);
}
