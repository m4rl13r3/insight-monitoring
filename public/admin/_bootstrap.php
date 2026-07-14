<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

$insightAdminConfig = require dirname(__DIR__) . '/config/config.php';

date_default_timezone_set((string)($insightAdminConfig['timezone'] ?? 'Europe/Paris'));

function insight_admin_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value === false || trim((string)$value) === '') {
        return $default;
    }
    return trim((string)$value);
}

function insight_admin_env_int(string $name, int $default, int $minimum, int $maximum): int
{
    $value = filter_var(insight_admin_env($name, (string)$default), FILTER_VALIDATE_INT);
    if ($value === false) {
        return $default;
    }
    return max($minimum, min($maximum, (int)$value));
}

function insight_admin_env_bool(string $name, bool $default = false): bool
{
    $value = strtolower(insight_admin_env($name, $default ? '1' : '0'));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function insight_admin_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function insight_admin_auth_path(): string
{
    $default = dirname(__DIR__, 2) . '/data/auth.sqlite';
    return insight_admin_env('INSIGHT_AUTH_DB_PATH', $default);
}

function insight_admin_secure_cookie(): bool
{
    $configured = strtolower(insight_admin_env('INSIGHT_AUTH_COOKIE_SECURE', 'auto'));
    if (in_array($configured, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($configured, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $forwarded = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return ($https !== '' && $https !== 'off') || $forwarded === 'https';
}

function insight_admin_cookie_options(int $expires = 0): array
{
    $sameSite = ucfirst(strtolower(insight_admin_env('INSIGHT_AUTH_COOKIE_SAMESITE', 'Lax')));
    if (!in_array($sameSite, ['Lax', 'Strict'], true)) {
        $sameSite = 'Lax';
    }
    return [
        'expires' => $expires,
        'path' => '/admin',
        'secure' => insight_admin_secure_cookie(),
        'httponly' => true,
        'samesite' => $sameSite,
    ];
}

function insight_admin_nonce(): string
{
    static $nonce = null;
    if (!is_string($nonce)) {
        $nonce = base64_encode(random_bytes(18));
    }
    return $nonce;
}

function insight_admin_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    $nonce = insight_admin_nonce();
    header_remove('X-Powered-By');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cache-Control: no-store, max-age=0');
}

function insight_admin_prepare_storage(): array
{
    $databasePath = insight_admin_auth_path();
    $directory = dirname($databasePath);
    $sessionsPath = $directory . '/sessions';
    foreach ([$directory, $sessionsPath] as $path) {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create local administration storage.');
        }
        @chmod($path, 0700);
    }
    return [$databasePath, $sessionsPath];
}

function insight_admin_start_session(string $sessionsPath): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $rememberTtl = insight_admin_env_int('INSIGHT_AUTH_REMEMBER_TTL_SEC', 2592000, 86400, 31536000);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', (string)insight_admin_cookie_options()['samesite']);
    ini_set('session.gc_maxlifetime', (string)$rememberTtl);
    session_name('insight_admin');
    session_save_path($sessionsPath);
    $cookieOptions = insight_admin_cookie_options();
    unset($cookieOptions['expires']);
    $cookieOptions['lifetime'] = 0;
    session_set_cookie_params($cookieOptions);
    session_start();
}

function insight_auth_db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }
    $path = insight_admin_auth_path();
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('The PHP pdo_sqlite extension is required for local administration.');
    }
    $connection = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec('PRAGMA journal_mode = WAL');
    $connection->exec('PRAGMA foreign_keys = ON');
    $connection->exec('PRAGMA busy_timeout = 5000');
    $schema = file_get_contents(dirname(__DIR__, 2) . '/database/auth-schema.sql');
    if (!is_string($schema)) {
        throw new RuntimeException('The local administration schema was not found.');
    }
    $connection->exec($schema);
    $columns = [];
    foreach ($connection->query('PRAGMA table_info(auth_users)')->fetchAll() as $column) {
        $columns[(string)$column['name']] = true;
    }
    $authMigrations = [
        'totp_secret_ciphertext' => 'ALTER TABLE auth_users ADD COLUMN totp_secret_ciphertext TEXT NULL',
        'totp_enabled' => 'ALTER TABLE auth_users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0',
        'totp_last_counter' => 'ALTER TABLE auth_users ADD COLUMN totp_last_counter INTEGER NOT NULL DEFAULT 0',
        'recovery_codes_json' => "ALTER TABLE auth_users ADD COLUMN recovery_codes_json TEXT NOT NULL DEFAULT '[]'",
    ];
    foreach ($authMigrations as $column => $query) {
        if (!isset($columns[$column])) {
            $connection->exec($query);
        }
    }
    $statement = $connection->prepare('INSERT OR IGNORE INTO auth_meta (key, value) VALUES (:key, :value)');
    $statement->execute([
        'key' => 'rate_limit_secret',
        'value' => bin2hex(random_bytes(32)),
    ]);
    foreach (['headless_api_enabled', 'oauth_provider_enabled'] as $feature) {
        $statement->execute([
            'key' => $feature,
            'value' => '0',
        ]);
    }
    @chmod($path, 0600);
    return $connection;
}

function insight_auth_meta(string $key): string
{
    $statement = insight_auth_db()->prepare('SELECT value FROM auth_meta WHERE key = :key LIMIT 1');
    $statement->execute(['key' => $key]);
    $value = $statement->fetchColumn();
    return is_string($value) ? $value : '';
}

function insight_auth_user_count(): int
{
    return (int)insight_auth_db()->query('SELECT COUNT(*) FROM auth_users')->fetchColumn();
}

function insight_auth_dev_bypass_enabled(): bool
{
    $environment = strtolower(insight_admin_env('INSIGHT_APP_ENV', 'production'));
    return in_array($environment, ['development', 'dev', 'local', 'test'], true)
        && insight_admin_env_bool('INSIGHT_DEV_AUTH_BYPASS');
}

function insight_auth_dev_user(): array
{
    return [
        'id' => 0,
        'username' => 'developer',
        'role' => 'admin',
        'source' => 'development',
        'dev_bypass' => true,
    ];
}

function insight_auth_role(array $user): string
{
    $role = strtolower(trim((string)($user['role'] ?? 'viewer')));
    return in_array($role, ['admin', 'operator', 'viewer'], true) ? $role : 'viewer';
}

function insight_auth_can(array $user, string $permission): bool
{
    $role = insight_auth_role($user);
    if ($role === 'admin') {
        return true;
    }
    $operatorPermissions = [
        'dashboard:view',
        'monitors:write',
        'incidents:write',
        'maintenance:write',
        'notifications:write',
        'status_pages:write',
        'network:write',
    ];
    if ($role === 'operator') {
        return in_array($permission, $operatorPermissions, true);
    }
    return $permission === 'dashboard:view';
}

function insight_auth_is_configured(): bool
{
    return insight_auth_dev_bypass_enabled() || insight_auth_user_count() > 0;
}

function insight_auth_client_ip(): string
{
    return substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? 'local')), 0, 96);
}

function insight_auth_identity_hash(string $username): string
{
    $identity = strtolower(trim($username)) . '|' . insight_auth_client_ip();
    return hash_hmac('sha256', $identity, insight_auth_meta('rate_limit_secret'));
}

function insight_auth_ip_hash(): string
{
    return hash_hmac('sha256', insight_auth_client_ip(), insight_auth_meta('rate_limit_secret'));
}

function insight_auth_audit(string $event, ?int $userId = null): void
{
    if ($userId !== null) {
        $owner = insight_auth_db()->prepare('SELECT id FROM auth_users WHERE id = :id LIMIT 1');
        $owner->execute(['id' => $userId]);
        if ($owner->fetchColumn() === false) {
            $userId = null;
        }
    }
    $statement = insight_auth_db()->prepare(
        'INSERT INTO auth_audit_log (user_id, event, ip_hash) VALUES (:user_id, :event, :ip_hash)'
    );
    $statement->execute([
        'user_id' => $userId,
        'event' => substr($event, 0, 64),
        'ip_hash' => insight_auth_ip_hash(),
    ]);
    if (random_int(1, 50) === 1) {
        insight_auth_db()->exec("DELETE FROM auth_audit_log WHERE created_at < datetime('now', '-90 days')");
    }
}

function insight_auth_local_user_id(array $user): ?int
{
    return ($user['source'] ?? 'local') === 'local' && (int)($user['id'] ?? 0) > 0
        ? (int)$user['id']
        : null;
}

function insight_auth_rate_limit(string $username): array
{
    $database = insight_auth_db();
    $now = time();
    $window = insight_admin_env_int('INSIGHT_AUTH_WINDOW_SEC', 900, 60, 86400);
    $maximum = insight_admin_env_int('INSIGHT_AUTH_MAX_ATTEMPTS', 5, 3, 50);
    $database->prepare('DELETE FROM auth_login_attempts WHERE attempted_at < :threshold')
        ->execute(['threshold' => $now - 86400]);
    $statement = $database->prepare(
        'SELECT COUNT(*) AS failures, MIN(attempted_at) AS first_attempt
         FROM auth_login_attempts
         WHERE key_hash = :key_hash AND success = 0 AND attempted_at >= :threshold'
    );
    $statement->execute([
        'key_hash' => insight_auth_identity_hash($username),
        'threshold' => $now - $window,
    ]);
    $result = $statement->fetch() ?: [];
    $failures = (int)($result['failures'] ?? 0);
    $firstAttempt = (int)($result['first_attempt'] ?? $now);
    return [
        'blocked' => $failures >= $maximum,
        'retry_after' => max(1, $firstAttempt + $window - $now),
    ];
}

function insight_auth_record_attempt(string $username, bool $success): void
{
    $keyHash = insight_auth_identity_hash($username);
    if ($success) {
        $statement = insight_auth_db()->prepare('DELETE FROM auth_login_attempts WHERE key_hash = :key_hash');
        $statement->execute(['key_hash' => $keyHash]);
        return;
    }
    $statement = insight_auth_db()->prepare(
        'INSERT INTO auth_login_attempts (key_hash, attempted_at, success) VALUES (:key_hash, :attempted_at, 0)'
    );
    $statement->execute([
        'key_hash' => $keyHash,
        'attempted_at' => time(),
    ]);
}

function insight_auth_password_hash(string $password): string
{
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 2,
        ]);
    }
    return password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
}

function insight_auth_password_needs_rehash(string $hash): bool
{
    if (defined('PASSWORD_ARGON2ID')) {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 2,
        ]);
    }
    return password_needs_rehash($hash, PASSWORD_DEFAULT, ['cost' => 12]);
}

function insight_auth_encryption_key(): string
{
    $raw = insight_admin_env('INSIGHT_AUTH_ENCRYPTION_KEY', insight_admin_env('INSIGHT_NOTIFICATION_ENCRYPTION_KEY'));
    if (strlen($raw) < 32 && insight_auth_dev_bypass_enabled()) {
        $path = dirname(insight_admin_auth_path()) . '/auth-encryption.key';
        if (is_readable($path)) {
            $raw = trim((string)file_get_contents($path));
        }
        if (strlen($raw) < 32) {
            $raw = bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            if (file_put_contents($path, $raw . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('admin.security.errorEncryptionKey');
            }
            @chmod($path, 0600);
        }
    }
    if (strlen($raw) < 32 || !extension_loaded('sodium')) {
        throw new RuntimeException('admin.security.errorEncryptionKey');
    }
    if (strlen($raw) === 64 && ctype_xdigit($raw)) {
        $decoded = hex2bin($raw);
        if (is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $decoded;
        }
    }
    try {
        $decoded = sodium_base642bin($raw, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        if (strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $decoded;
        }
    } catch (Throwable) {
    }
    return hash('sha256', $raw, true);
}

function insight_auth_encrypt(string $value): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'v1:' . sodium_bin2base64(
        $nonce . sodium_crypto_secretbox($value, $nonce, insight_auth_encryption_key()),
        SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING
    );
}

function insight_auth_decrypt(string $value): string
{
    if (!str_starts_with($value, 'v1:')) {
        throw new RuntimeException('admin.security.errorTotpSecret');
    }
    $payload = sodium_base642bin(substr($value, 3), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    if (strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('admin.security.errorTotpSecret');
    }
    $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, insight_auth_encryption_key());
    if (!is_string($plain)) {
        throw new RuntimeException('admin.security.errorTotpSecret');
    }
    return $plain;
}

function insight_auth_base32_encode(string $value): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($value) as $character) {
        $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
    }
    $encoded = '';
    foreach (str_split($bits, 5) as $chunk) {
        $encoded .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $encoded;
}

function insight_auth_base32_decode(string $value): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $normalized = strtoupper((string)preg_replace('/[^A-Z2-7]/i', '', $value));
    $bits = '';
    foreach (str_split($normalized) as $character) {
        $position = strpos($alphabet, $character);
        if ($position === false) {
            throw new RuntimeException('admin.security.errorTotpSecret');
        }
        $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
    }
    $decoded = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $decoded .= chr(bindec($chunk));
        }
    }
    return $decoded;
}

function insight_auth_totp_code(string $secret, int $counter): string
{
    $binary = insight_auth_base32_decode($secret);
    $counterBytes = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
    $hash = hash_hmac('sha1', $counterBytes, $binary, true);
    $offset = ord($hash[19]) & 15;
    $number = ((ord($hash[$offset]) & 127) << 24)
        | ((ord($hash[$offset + 1]) & 255) << 16)
        | ((ord($hash[$offset + 2]) & 255) << 8)
        | (ord($hash[$offset + 3]) & 255);
    return str_pad((string)($number % 1000000), 6, '0', STR_PAD_LEFT);
}

function insight_auth_totp_counter(string $secret, string $code, int $lastCounter = 0): ?int
{
    $normalized = preg_replace('/\D+/', '', $code) ?? '';
    if (strlen($normalized) !== 6) {
        return null;
    }
    $current = intdiv(time(), 30);
    for ($offset = -1; $offset <= 1; $offset++) {
        $counter = $current + $offset;
        if ($counter > $lastCounter && hash_equals(insight_auth_totp_code($secret, $counter), $normalized)) {
            return $counter;
        }
    }
    return null;
}

function insight_auth_recovery_hash(string $code): string
{
    $normalized = strtoupper((string)preg_replace('/[^A-Z0-9]/', '', $code));
    return hash_hmac('sha256', $normalized, insight_auth_encryption_key());
}

function insight_auth_recovery_codes(): array
{
    $plain = [];
    $hashes = [];
    for ($index = 0; $index < 8; $index++) {
        $raw = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
        $code = substr($raw, 0, 5) . '-' . substr($raw, 5);
        $plain[] = $code;
        $hashes[] = insight_auth_recovery_hash($code);
    }
    return ['plain' => $plain, 'hashes' => $hashes];
}

function insight_auth_verify_second_factor(array $user, string $code): bool
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1 || (int)($user['totp_enabled'] ?? 0) !== 1) {
        return false;
    }
    $database = insight_auth_db();
    $normalizedRecovery = strtoupper((string)preg_replace('/[^A-Z0-9]/', '', $code));
    if (strlen($normalizedRecovery) === 10) {
        $hashes = json_decode((string)($user['recovery_codes_json'] ?? '[]'), true);
        $hashes = is_array($hashes) ? array_values(array_filter($hashes, 'is_string')) : [];
        $candidate = insight_auth_recovery_hash($normalizedRecovery);
        foreach ($hashes as $index => $hash) {
            if (hash_equals($hash, $candidate)) {
                unset($hashes[$index]);
                $statement = $database->prepare('UPDATE auth_users SET recovery_codes_json=:codes,updated_at=CURRENT_TIMESTAMP WHERE id=:id');
                $statement->execute(['codes' => json_encode(array_values($hashes), JSON_THROW_ON_ERROR), 'id' => $userId]);
                return true;
            }
        }
        return false;
    }
    try {
        $secret = insight_auth_decrypt((string)($user['totp_secret_ciphertext'] ?? ''));
    } catch (Throwable) {
        return false;
    }
    $counter = insight_auth_totp_counter($secret, $code, (int)($user['totp_last_counter'] ?? 0));
    if ($counter === null) {
        return false;
    }
    $statement = $database->prepare('UPDATE auth_users SET totp_last_counter=:counter,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND totp_last_counter<:counter');
    $statement->execute(['counter' => $counter, 'id' => $userId]);
    return $statement->rowCount() === 1;
}

function insight_auth_csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function insight_auth_csrf_valid(mixed $token): bool
{
    return is_string($token) && hash_equals(insight_auth_csrf_token(), $token);
}

function insight_auth_safe_next(mixed $value, string $default = '/admin/'): string
{
    if (!is_string($value)) {
        return $default;
    }
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '//') || !str_starts_with($value, '/admin')) {
        return $default;
    }
    return str_replace(["\r", "\n"], '', $value);
}

function insight_auth_redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function insight_auth_open_session(array $user, bool $remember): void
{
    session_regenerate_id(true);
    $now = time();
    $_SESSION['auth_user'] = [
        'id' => (int)$user['id'],
        'username' => (string)$user['username'],
        'role' => (string)$user['role'],
        'source' => (string)($user['source'] ?? 'local'),
        'issuer' => (string)($user['issuer'] ?? ''),
        'subject' => (string)($user['subject'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
    ];
    $_SESSION['auth_issued_at'] = $now;
    $_SESSION['auth_last_seen'] = $now;
    $_SESSION['auth_remember'] = $remember;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    if ($remember) {
        $ttl = insight_admin_env_int('INSIGHT_AUTH_REMEMBER_TTL_SEC', 2592000, 86400, 31536000);
        setcookie(session_name(), session_id(), insight_admin_cookie_options($now + $ttl));
    }
}

function insight_auth_destroy_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        setcookie(session_name(), '', insight_admin_cookie_options(time() - 3600));
        session_destroy();
    }
}

function insight_auth_current_user(): ?array
{
    if (insight_auth_dev_bypass_enabled()) {
        return insight_auth_dev_user();
    }
    $sessionUser = $_SESSION['auth_user'] ?? null;
    if (!is_array($sessionUser) || !isset($sessionUser['id'])) {
        return null;
    }
    $now = time();
    $remember = (bool)($_SESSION['auth_remember'] ?? false);
    $ttl = $remember
        ? insight_admin_env_int('INSIGHT_AUTH_REMEMBER_TTL_SEC', 2592000, 86400, 31536000)
        : insight_admin_env_int('INSIGHT_AUTH_SESSION_TTL_SEC', 43200, 900, 604800);
    $lastSeen = (int)($_SESSION['auth_last_seen'] ?? 0);
    if ($lastSeen < $now - $ttl) {
        insight_auth_destroy_session();
        return null;
    }
    $source = (string)($sessionUser['source'] ?? 'local');
    if ($source === 'oidc') {
        $expectedIssuer = rtrim(insight_admin_env('INSIGHT_SSO_ISSUER_URL'), '/');
        if (!insight_admin_env_bool('INSIGHT_SSO_ENABLED') || $expectedIssuer === '') {
            insight_auth_destroy_session();
            return null;
        }
        $statement = insight_auth_db()->prepare(
            'SELECT id, username, email, role, issuer, subject FROM auth_sso_identities
             WHERE id = :id AND active = 1 LIMIT 1'
        );
    } else {
        $statement = insight_auth_db()->prepare(
            'SELECT id, username, role FROM auth_users WHERE id = :id AND active = 1 LIMIT 1'
        );
    }
    $statement->execute(['id' => (int)$sessionUser['id']]);
    $user = $statement->fetch();
    if (!is_array($user)) {
        insight_auth_destroy_session();
        return null;
    }
    if ($source === 'oidc' && !hash_equals($expectedIssuer, rtrim((string)($user['issuer'] ?? ''), '/'))) {
        insight_auth_destroy_session();
        return null;
    }
    $_SESSION['auth_last_seen'] = $now;
    $user['source'] = $source;
    $_SESSION['auth_user'] = array_merge($sessionUser, $user);
    return $user;
}

function insight_auth_require_user(): array
{
    if (!insight_auth_is_configured()) {
        insight_auth_redirect('/admin/setup.php');
    }
    $user = insight_auth_current_user();
    if ($user === null) {
        $requestUri = insight_auth_safe_next($_SERVER['REQUEST_URI'] ?? '/admin/');
        insight_auth_redirect('/admin/login.php?next=' . rawurlencode($requestUri));
    }
    return $user;
}

function insight_auth_validate_credentials(string $username, string $password, string $confirmation): ?string
{
    if (preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username) !== 1) {
        return 'admin.auth.errorUsername';
    }
    $length = strlen($password);
    if ($length < 12 || $length > 1024) {
        return 'admin.auth.errorPasswordLength';
    }
    if (!hash_equals($password, $confirmation)) {
        return 'admin.auth.errorPasswordMatch';
    }
    return null;
}

function insight_auth_create_first_admin(
    string $username,
    string $password,
    string $confirmation,
    bool $remember
): array {
    $username = trim($username);
    $error = insight_auth_validate_credentials($username, $password, $confirmation);
    if ($error !== null) {
        return ['ok' => false, 'error' => $error];
    }
    $database = insight_auth_db();
    try {
        $database->exec('BEGIN IMMEDIATE');
        if ((int)$database->query('SELECT COUNT(*) FROM auth_users')->fetchColumn() > 0) {
            $database->exec('ROLLBACK');
            return ['ok' => false, 'error' => 'admin.auth.errorAlreadyConfigured'];
        }
        $statement = $database->prepare(
            'INSERT INTO auth_users (username, password_hash, role) VALUES (:username, :password_hash, :role)'
        );
        $statement->execute([
            'username' => $username,
            'password_hash' => insight_auth_password_hash($password),
            'role' => 'admin',
        ]);
        $userId = (int)$database->lastInsertId();
        $database->exec('COMMIT');
        $user = ['id' => $userId, 'username' => $username, 'role' => 'admin'];
        insight_auth_open_session($user, $remember);
        insight_auth_audit('setup_completed', $userId);
        return ['ok' => true, 'user' => $user];
    } catch (Throwable $exception) {
        try {
            $database->exec('ROLLBACK');
        } catch (Throwable) {
        }
        return ['ok' => false, 'error' => 'admin.auth.errorSetup'];
    }
}

function insight_auth_finish_login(array $user, bool $remember): array
{
    $update = insight_auth_db()->prepare(
        'UPDATE auth_users SET last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
    );
    $update->execute(['id' => (int)$user['id']]);
    insight_auth_record_attempt((string)$user['username'], true);
    unset($_SESSION['auth_pending_user'], $_SESSION['auth_pending_remember'], $_SESSION['auth_pending_expires'], $_SESSION['auth_pending_attempts']);
    insight_auth_open_session($user, $remember);
    insight_auth_audit('login_succeeded', (int)$user['id']);
    return ['ok' => true, 'user' => $user];
}

function insight_auth_totp_pending(): bool
{
    return isset($_SESSION['auth_pending_user'], $_SESSION['auth_pending_expires'])
        && is_array($_SESSION['auth_pending_user'])
        && (int)$_SESSION['auth_pending_expires'] >= time();
}

function insight_auth_cancel_pending(): void
{
    unset($_SESSION['auth_pending_user'], $_SESSION['auth_pending_remember'], $_SESSION['auth_pending_expires'], $_SESSION['auth_pending_attempts']);
}

function insight_auth_complete_totp(string $code): array
{
    if (!insight_auth_totp_pending()) {
        insight_auth_cancel_pending();
        return ['ok' => false, 'error' => 'admin.auth.errorTotpExpired'];
    }
    $pending = $_SESSION['auth_pending_user'];
    $statement = insight_auth_db()->prepare(
        'SELECT id,username,password_hash,role,totp_enabled,totp_secret_ciphertext,totp_last_counter,recovery_codes_json FROM auth_users WHERE id=:id AND active=1 LIMIT 1'
    );
    $statement->execute(['id' => (int)($pending['id'] ?? 0)]);
    $user = $statement->fetch();
    if (!is_array($user) || !insight_auth_verify_second_factor($user, $code)) {
        $_SESSION['auth_pending_attempts'] = (int)($_SESSION['auth_pending_attempts'] ?? 0) + 1;
        insight_auth_record_attempt((string)($pending['username'] ?? ''), false);
        insight_auth_audit('login_totp_failed', isset($user['id']) ? (int)$user['id'] : null);
        if ((int)$_SESSION['auth_pending_attempts'] >= 5) {
            insight_auth_cancel_pending();
            return ['ok' => false, 'error' => 'admin.auth.errorTotpExpired'];
        }
        return ['ok' => false, 'error' => 'admin.auth.errorTotp'];
    }
    $remember = (bool)($_SESSION['auth_pending_remember'] ?? false);
    return insight_auth_finish_login($user, $remember);
}

function insight_auth_login(string $username, string $password, bool $remember): array
{
    $username = trim($username);
    $rate = insight_auth_rate_limit($username);
    if ($rate['blocked']) {
        header('Retry-After: ' . (string)$rate['retry_after']);
        insight_auth_audit('login_rate_limited');
        return ['ok' => false, 'error' => 'admin.auth.errorRateLimit'];
    }
    $statement = insight_auth_db()->prepare(
        'SELECT id, username, password_hash, role, totp_enabled, totp_secret_ciphertext, totp_last_counter, recovery_codes_json FROM auth_users
         WHERE username = :username COLLATE NOCASE AND active = 1 LIMIT 1'
    );
    $statement->execute(['username' => $username]);
    $user = $statement->fetch();
    $dummyHash = '$2y$12$2DC4J0Nr20DBbV057po08.X.QAeqOM8BHP208QUi4swI06lWQyX1.';
    $hash = is_array($user) ? (string)$user['password_hash'] : $dummyHash;
    $valid = password_verify($password, $hash);
    if (!$valid || !is_array($user)) {
        insight_auth_record_attempt($username, false);
        insight_auth_audit('login_failed');
        return ['ok' => false, 'error' => 'admin.auth.errorInvalid'];
    }
    if (insight_auth_password_needs_rehash($hash)) {
        $rehash = insight_auth_db()->prepare(
            'UPDATE auth_users SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $rehash->execute([
            'password_hash' => insight_auth_password_hash($password),
            'id' => (int)$user['id'],
        ]);
    }
    if ((int)($user['totp_enabled'] ?? 0) === 1) {
        $_SESSION['auth_pending_user'] = ['id' => (int)$user['id'], 'username' => (string)$user['username']];
        $_SESSION['auth_pending_remember'] = $remember;
        $_SESSION['auth_pending_expires'] = time() + 300;
        $_SESSION['auth_pending_attempts'] = 0;
        return ['ok' => false, 'requires_totp' => true];
    }
    return insight_auth_finish_login($user, $remember);
}

function insight_auth_error_message(?string $key): string
{
    return match ($key) {
        'admin.auth.errorCsrf' => 'The session expired. Reload the page and try again.',
        'admin.auth.errorUsername' => 'Use 3 to 64 letters, digits, periods, hyphens, or underscores.',
        'admin.auth.errorPasswordLength' => 'The password must contain at least 12 characters.',
        'admin.auth.errorPasswordMatch' => 'The passwords do not match.',
        'admin.auth.errorAlreadyConfigured' => 'This instance already has an administrator account.',
        'admin.auth.errorSetup' => 'The administrator account could not be created.',
        'admin.auth.errorRateLimit' => 'Too many attempts. Try again in a few minutes.',
        'admin.auth.errorInvalid' => 'Incorrect username or password.',
        'admin.auth.errorTotp' => 'The verification code is invalid or has already been used.',
        'admin.auth.errorTotpExpired' => 'The verification request expired. Sign in again.',
        'admin.sso.errorConfiguration' => 'The SSO configuration is incomplete or insecure.',
        'admin.sso.errorDenied' => 'Your SSO identity is not allowed on this instance.',
        'admin.sso.errorSession' => 'The SSO request expired. Start the login again.',
        'admin.sso.errorGeneric' => 'The SSO login could not be verified.',
        default => '',
    };
}

[$insightAuthDatabasePath, $insightAuthSessionsPath] = insight_admin_prepare_storage();
if (!defined('INSIGHT_AUTH_STATELESS') || INSIGHT_AUTH_STATELESS !== true) {
    insight_admin_start_session($insightAuthSessionsPath);
}
insight_admin_security_headers();
if (!insight_auth_dev_bypass_enabled() || (defined('INSIGHT_AUTH_STATELESS') && INSIGHT_AUTH_STATELESS === true)) {
    insight_auth_db();
}
