<?php
declare(strict_types=1);

/** Start the session once, with hardened cookie params. */
function session_boot(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $app = config('app');
    session_name($app['session_name'] ?? 'POOLSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => (bool) ($app['cookie_secure'] ?? false),
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** The logged-in user (from the session JWT), or null. */
function current_user(): ?array
{
    session_boot();
    if (empty($_SESSION['jwt'])) {
        return null;
    }
    if (!empty($_SESSION['jwt_exp']) && time() >= (int) $_SESSION['jwt_exp']) {
        $_SESSION = []; // token expired
        return null;
    }
    return [
        'username' => $_SESSION['uname'] ?? '',
        'role'     => $_SESSION['urole'] ?? 'readonly',
        'jwt'      => (string) $_SESSION['jwt'],
    ];
}

function current_jwt(): ?string
{
    return current_user()['jwt'] ?? null;
}

function is_admin(?array $u): bool
{
    return $u !== null && ($u['role'] ?? '') === 'admin';
}

/** Admins and standard users may issue commands; readonly may not. */
function can_control(?array $u): bool
{
    return $u !== null && in_array($u['role'] ?? '', ['admin', 'standard'], true);
}

/** Exchange credentials for a JWT via the API; store it in the session. */
function attempt_login(string $username, string $password): bool
{
    $res = api_post('/api/auth/login', ['username' => $username, 'password' => $password]);
    if ($res['status'] !== 200 || empty($res['data']['token'])) {
        return false;
    }
    session_boot();
    session_regenerate_id(true);
    $_SESSION['jwt']   = $res['data']['token'];
    $_SESSION['uname'] = $res['data']['username'] ?? $username;
    $_SESSION['urole'] = $res['data']['role'] ?? 'readonly';
    $exp = isset($res['data']['expiresAt']) ? strtotime((string) $res['data']['expiresAt']) : 0;
    $_SESSION['jwt_exp'] = $exp ?: (time() + 12 * 3600);
    return true;
}

function logout(): void
{
    session_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if (!is_admin($u)) {
        http_response_code(403);
        exit('Forbidden — admins only.');
    }
    return $u;
}

function require_login_api(): array
{
    $u = current_user();
    if (!$u) {
        json_out(['error' => 'unauthorized'], 401);
    }
    return $u;
}

function require_admin_api(): array
{
    $u = require_login_api();
    if (!is_admin($u)) {
        json_out(['error' => 'forbidden'], 403);
    }
    return $u;
}
