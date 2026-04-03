<?php

declare(strict_types=1);

function app_load_env(string $filePath, bool $override = false): void
{
    static $loaded = [];

    if (!$override && isset($loaded[$filePath])) {
        return;
    }

    if (!is_file($filePath) || !is_readable($filePath)) {
        $loaded[$filePath] = true;
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        $loaded[$filePath] = true;
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $name = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            continue;
        }

        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
                if ($first === '"') {
                    $value = str_replace(['\\n', '\\r', '\\t', '\\\\', '\\"'], ["\n", "\r", "\t", "\\", '"'], $value);
                }
            }
        }

        if (!$override && getenv($name) !== false) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    $loaded[$filePath] = true;
}

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        app_load_env(__DIR__ . '/.env');
        $config = require __DIR__ . '/config.php';
    }

    return $config;
}

function app_db(): PDO
{
    $config = app_config();
    $dbPath = $config['db_path'];

    if (!is_dir(dirname($dbPath))) {
        mkdir(dirname($dbPath), 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function app_init(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        long_url TEXT NOT NULL,
        clicks INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        last_clicked_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        ip_address TEXT NOT NULL,
        attempted_at INTEGER NOT NULL
    )');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_user_ip_time ON login_attempts (username, ip_address, attempted_at)');

    $config = app_config();
    $find = $pdo->prepare('SELECT id FROM admin_users WHERE username = :username LIMIT 1');
    $find->execute([':username' => $config['admin_username']]);

    if (!$find->fetchColumn()) {
        $insert = $pdo->prepare('INSERT INTO admin_users (username, password_hash, created_at) VALUES (:username, :password_hash, :created_at)');
        $insert->execute([
            ':username' => $config['admin_username'],
            ':password_hash' => password_hash($config['admin_password'], PASSWORD_DEFAULT),
            ':created_at' => gmdate('c'),
        ]);
    }
}

function app_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $cookiePath = app_base_path();

        ini_set('session.use_strict_mode', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath === '' ? '/' : $cookiePath,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}

function app_send_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

function app_turnstile_site_key(): string
{
    return trim((string) (app_config()['turnstile_site_key'] ?? ''));
}

function app_turnstile_secret_key(): string
{
    return trim((string) (app_config()['turnstile_secret_key'] ?? ''));
}

function app_turnstile_is_configured(): bool
{
    return app_turnstile_site_key() !== '' && app_turnstile_secret_key() !== '';
}

function app_http_post_form(string $url, array $data, int $timeoutSeconds = 8): ?array
{
    $body = http_build_query($data);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $code < 200 || $code >= 300) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => $timeoutSeconds,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw)) {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

function app_verify_turnstile(?string $token, string $ip): bool
{
    if (!app_turnstile_is_configured()) {
        return false;
    }

    $token = trim((string) $token);
    if ($token === '') {
        return false;
    }

    $response = app_http_post_form('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'secret' => app_turnstile_secret_key(),
        'response' => $token,
        'remoteip' => $ip,
    ]);

    return is_array($response) && !empty($response['success']);
}

function app_csrf(): string
{
    app_start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function app_check_csrf(?string $token): bool
{
    app_start_session();

    return is_string($token) && hash_equals($_SESSION['csrf'] ?? '', $token);
}

function app_flash(?string $message = null, string $type = 'ok'): ?array
{
    app_start_session();

    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }

    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $f;
}

function app_is_admin(): bool
{
    app_start_session();

    return !empty($_SESSION['admin']);
}

function app_require_admin(): void
{
    if (!app_is_admin()) {
        header('Location: ' . app_url('/admin'));
        exit;
    }
}

function app_login(PDO $pdo, string $username, string $password): bool
{
    $stmt = $pdo->prepare('SELECT id, password_hash FROM admin_users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    app_start_session();
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['admin_id'] = (int) $user['id'];

    return true;
}

function app_logout(): void
{
    app_start_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
    }

    session_destroy();
}

function app_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_base_path(): string
{
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = str_replace('\\', '/', dirname($scriptName));

    if ($basePath === '/' || $basePath === '.') {
        return '';
    }

    return rtrim($basePath, '/');
}

function app_request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $basePath = app_base_path();

    if ($basePath !== '') {
        if ($path === $basePath) {
            return '/';
        }

        $prefix = $basePath . '/';
        if (str_starts_with($path, $prefix)) {
            $trimmed = substr($path, strlen($basePath));
            return $trimmed === '' ? '/' : $trimmed;
        }
    }

    if ($path === '/index.php') {
        return '/';
    }

    if (str_starts_with($path, '/index.php/')) {
        return substr($path, strlen('/index.php')) ?: '/';
    }

    return $path;
}

function app_url(string $path = '/'): string
{
    $path = '/' . ltrim($path, '/');

    return app_base_path() . ($path === '/' ? '' : $path);
}

function app_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1:8000';

    return $scheme . '://' . $host . app_base_path();
}

function app_short_url(string $code): string
{
    return app_base_url() . '/' . rawurlencode($code);
}

function app_normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (!preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url)) {
        $url = 'https://' . $url;
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme'])) {
        return '';
    }

    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

function app_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

    return substr($ip, 0, 64);
}

function app_login_rate_limit_max_attempts(): int
{
    return max(1, (int) (app_config()['login_max_attempts'] ?? 8));
}

function app_login_rate_limit_window_seconds(): int
{
    return max(60, (int) (app_config()['login_window_seconds'] ?? 900));
}

function app_login_cleanup_attempts(PDO $pdo): void
{
    $cutoff = time() - app_login_rate_limit_window_seconds();
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :cutoff');
    $stmt->execute([':cutoff' => $cutoff]);
}

function app_login_attempt_count(PDO $pdo, string $username, string $ip): int
{
    app_login_cleanup_attempts($pdo);

    $cutoff = time() - app_login_rate_limit_window_seconds();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = :username AND ip_address = :ip_address AND attempted_at >= :cutoff');
    $stmt->execute([
        ':username' => trim($username),
        ':ip_address' => $ip,
        ':cutoff' => $cutoff,
    ]);

    return (int) $stmt->fetchColumn();
}

function app_login_is_blocked(PDO $pdo, string $username, string $ip): bool
{
    return app_login_attempt_count($pdo, $username, $ip) >= app_login_rate_limit_max_attempts();
}

function app_login_record_failure(PDO $pdo, string $username, string $ip): void
{
    $stmt = $pdo->prepare('INSERT INTO login_attempts (username, ip_address, attempted_at) VALUES (:username, :ip_address, :attempted_at)');
    $stmt->execute([
        ':username' => trim($username),
        ':ip_address' => $ip,
        ':attempted_at' => time(),
    ]);
}

function app_login_clear_failures(PDO $pdo, string $username, string $ip): void
{
    $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE username = :username AND ip_address = :ip_address');
    $stmt->execute([
        ':username' => trim($username),
        ':ip_address' => $ip,
    ]);
}

function app_validate_code(string $code): bool
{
    $code = trim($code);
    if ($code === '') {
        return false;
    }

    $reserved = app_config()['reserved_codes'];

    return !in_array(strtolower($code), $reserved, true);
}

function app_generate_code(PDO $pdo, int $length = 6): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $size = strlen($alphabet);

    for ($i = 0; $i < 20; $i++) {
        $code = '';
        for ($j = 0; $j < $length; $j++) {
            $code .= $alphabet[random_int(0, $size - 1)];
        }

        if (!app_code_exists($pdo, $code)) {
            return $code;
        }
    }

    throw new RuntimeException('短链码生成失败');
}

function app_code_exists(PDO $pdo, string $code): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM links WHERE code = :code LIMIT 1');
    $stmt->execute([':code' => $code]);

    return (bool) $stmt->fetchColumn();
}

function app_create_link(PDO $pdo, string $longUrl, string $customCode = ''): array
{
    $longUrl = app_normalize_url($longUrl);
    if ($longUrl === '') {
        return ['ok' => false, 'message' => '请输入有效链接'];
    }

    $customCode = trim($customCode);
    if ($customCode !== '') {
        if (!app_validate_code($customCode)) {
                return ['ok' => false, 'message' => '短链码不能为空，且不能与系统保留路径冲突'];
        }

        if (app_code_exists($pdo, $customCode)) {
            return ['ok' => false, 'message' => '短链码已存在'];
        }

        $code = $customCode;
    } else {
        $code = app_generate_code($pdo);
    }

    $stmt = $pdo->prepare('INSERT INTO links (code, long_url, clicks, created_at) VALUES (:code, :long_url, 0, :created_at)');
    $stmt->execute([
        ':code' => $code,
        ':long_url' => $longUrl,
        ':created_at' => gmdate('c'),
    ]);

    return ['ok' => true, 'code' => $code, 'short_url' => app_short_url($code), 'long_url' => $longUrl];
}

function app_delete_link(PDO $pdo, string $code): void
{
    $stmt = $pdo->prepare('DELETE FROM links WHERE code = :code');
    $stmt->execute([':code' => $code]);
}

function app_redirect_by_code(PDO $pdo, string $code): void
{
    $stmt = $pdo->prepare('SELECT long_url FROM links WHERE code = :code LIMIT 1');
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo 'not found';
        exit;
    }

    $update = $pdo->prepare('UPDATE links SET clicks = clicks + 1, last_clicked_at = :last_clicked_at WHERE code = :code');
    $update->execute([
        ':last_clicked_at' => gmdate('c'),
        ':code' => $code,
    ]);

    header('Location: ' . $row['long_url'], true, 302);
    exit;
}

function app_links(PDO $pdo): array
{
    return $pdo->query('SELECT code, long_url, clicks, created_at FROM links ORDER BY datetime(created_at) DESC, id DESC')->fetchAll();
}

function app_stats(PDO $pdo): array
{
    $row = $pdo->query('SELECT COUNT(*) AS c, COALESCE(SUM(clicks), 0) AS s FROM links')->fetch();

    return [
        'count' => (int) ($row['c'] ?? 0),
        'clicks' => (int) ($row['s'] ?? 0),
    ];
}

function app_change_password(PDO $pdo, int $userId, string $oldPassword, string $newPassword): array
{
    if (strlen($newPassword) < 6) {
        return ['ok' => false, 'message' => '新密码长度不能少于 6 位'];
    }

    $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($oldPassword, $user['password_hash'])) {
        return ['ok' => false, 'message' => '旧密码不正确'];
    }

    $update = $pdo->prepare('UPDATE admin_users SET password_hash = :password_hash WHERE id = :id');
    $update->execute([
        ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ':id' => $userId,
    ]);

    return ['ok' => true, 'message' => '密码已修改'];
}
