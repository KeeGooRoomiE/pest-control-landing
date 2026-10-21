<?php
declare(strict_types=1);
defined('ADMIN_APP') || exit;

/** Конфиг лежит вне webroot: <домашняя папка>/private/admin-config.php (пример: server/admin-config.example.php). */
function load_config(): ?array
{
    $path = getenv('ADMIN_CONFIG') ?: dirname(__DIR__, 3) . '/private/admin-config.php';
    if (!is_file($path)) {
        return null;
    }
    $config = require $path;
    foreach (['admin_user', 'admin_password_hash', 'github_token', 'github_repo'] as $key) {
        if (!is_array($config) || !is_string($config[$key] ?? null) || $config[$key] === '') {
            return null;
        }
    }
    return $config;
}

function state_dir(array $config): string
{
    $dir = (string) ($config['state_dir'] ?? dirname(__DIR__, 3) . '/private/admin-state');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        $dir = sys_get_temp_dir() . '/qaz-admin-' . hash('sha256', __DIR__);
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function session_boot(array $config): void
{
    $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $sessions = state_dir($config) . '/sessions';
    if (is_dir($sessions) || @mkdir($sessions, 0700, true)) {
        session_save_path($sessions);
    }
    $lifetime = (int) ($config['session_lifetime'] ?? 8 * 3600);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    session_name('qazadm');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    if (isset($_SESSION['last']) && time() - (int) $_SESSION['last'] > $lifetime) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    if (!empty($_SESSION['user'])) {
        $_SESSION['last'] = time();
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(): bool
{
    $sent = (string) ($_POST['csrf'] ?? '');
    return $sent !== '' && hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent);
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/** Неудачные попытки входа: не больше 5 с одного IP за 15 минут и 40 всего за час. */
function login_attempts(string $file, int $windowSec): int
{
    $stored = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $now = time();
    return count(array_filter(is_array($stored) ? $stored : [], fn($t) => is_int($t) && $t > $now - $windowSec));
}

function record_failure(string $file): void
{
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return;
    }
    flock($fp, LOCK_EX);
    $stored = json_decode((string) stream_get_contents($fp), true);
    $now = time();
    $hits = array_values(array_filter(is_array($stored) ? $stored : [], fn($t) => is_int($t) && $t > $now - 86400));
    $hits[] = $now;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($hits));
    flock($fp, LOCK_UN);
    fclose($fp);
}

function login_blocked(array $config): bool
{
    $dir = state_dir($config);
    return login_attempts($dir . '/login_ip_' . hash('sha256', client_ip()) . '.json', 900) >= 5
        || login_attempts($dir . '/login_all.json', 3600) >= 40;
}

function try_login(array $config, string $user, string $password): bool
{
    $dir = state_dir($config);
    // password_verify выполняем всегда — чтобы время ответа не выдавало верность логина
    $hash = hash_equals($config['admin_user'], $user) ? $config['admin_password_hash'] : password_hash('dummy', PASSWORD_DEFAULT);
    $ok = password_verify($password, $hash) && hash_equals($config['admin_user'], $user);
    if (!$ok) {
        record_failure($dir . '/login_ip_' . hash('sha256', client_ip()) . '.json');
        record_failure($dir . '/login_all.json');
        usleep(400_000);
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['last'] = time();
    unset($_SESSION['csrf']);
    return true;
}
