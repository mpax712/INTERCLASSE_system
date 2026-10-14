<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        enforce_session_timeout();
        return;
    }

    $secure = (bool) config()['app']['secure_cookies'];
    $sessionPath = session_save_path();
    if ($sessionPath && !is_writable($sessionPath) && is_writable(sys_get_temp_dir())) {
        session_save_path(sys_get_temp_dir());
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    enforce_session_timeout();
}

function enforce_session_timeout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['user_id'])) {
        return;
    }

    $now = time();
    $lastActivity = (int) ($_SESSION['last_activity_at'] ?? $now);

    if (($now - $lastActivity) > 3600) {
        $_SESSION = [];
        session_destroy();
        return;
    }

    $_SESSION['last_activity_at'] = $now;
}

function current_user(): ?array
{
    static $cachedUser = null;
    static $loaded = false;

    if ($loaded) {
        return $cachedUser;
    }

    $loaded = true;
    start_app_session();

    if (empty($_SESSION['user_id'])) {
        $cachedUser = null;
        return null;
    }

    $stmt = db()->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user) {
        $user['modalities'] = [];
        if ($user['role'] === 'professor') {
            try {
                if (function_exists('ensure_interclasse_schema_updates')) {
                    ensure_interclasse_schema_updates();
                }
                $modalitiesStmt = db()->prepare('SELECT modality FROM user_modalities WHERE user_id = ? ORDER BY modality');
                $modalitiesStmt->execute([(int) $user['id']]);
                $user['modalities'] = array_column($modalitiesStmt->fetchAll(), 'modality');
            } catch (Throwable $exception) {
                $user['modalities'] = [];
            }
        }

        $user['role_label'] = [
            'admin' => 'Administrador',
            'professor' => 'Mesário',
            'representante' => 'Representante',
        ][$user['role']] ?? ucfirst($user['role']);
    }

    $cachedUser = $user ?: null;
    return $cachedUser;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function has_role(array $roles): bool
{
    $user = current_user();
    return $user !== null && in_array($user['role'], $roles, true);
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('index.php?page=login');
    }
}

function require_roles(array $roles): void
{
    require_login();

    if (!has_role($roles)) {
        http_response_code(403);
        exit('<div class="container py-4"><div class="alert alert-warning border"><strong>Acesso restrito.</strong><div>Seu perfil não tem permissão para abrir esta área.</div><a class="btn btn-sm btn-outline-secondary mt-3" href="index.php">Voltar ao início</a></div></div>');
    }
}

function login_user(string $email, string $password): bool
{
    // Rate limiting check
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!check_rate_limit('login_' . $ip, 5, 300)) {
        sleep(2); // Slow down brute force attempts
        return false;
    }

    // Input validation
    if (!validate_email($email)) {
        return false;
    }

    if (!validate_length($password, 6, 128)) {
        return false;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        return false;
    }

    $storedHash = $user['password_hash'];
    $validPassword = false;

    if (password_verify($password, $storedHash)) {
        $validPassword = true;
    }

    if (!$validPassword) {
        return false;
    }

    if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
        $storedHash = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $updateStmt->execute([$storedHash, $user['id']]);
    }

    start_app_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_activity_at'] = time();

    return true;
}

function logout_user(): void
{
    start_app_session();
    $_SESSION = [];
    session_destroy();
}
