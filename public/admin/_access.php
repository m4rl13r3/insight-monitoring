<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';

function insight_access_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function insight_access_base64url_decode(string $value): string|false
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        return false;
    }
    $padding = (4 - strlen($value) % 4) % 4;
    return base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
}

function insight_access_json_array(mixed $value): array
{
    if (is_array($value)) {
        return array_values($value);
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? array_values($decoded) : [];
}

function insight_access_json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function insight_access_public_url(): string
{
    global $insightAdminConfig;
    return rtrim((string)($insightAdminConfig['public_url'] ?? insight_admin_env('INSIGHT_PUBLIC_URL')), '/');
}

function insight_access_is_loopback_host(string $host): bool
{
    $host = strtolower(trim($host, '[]'));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function insight_access_is_secure_url(string $url, bool $allowLoopback = true): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        return false;
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = (string)($parts['host'] ?? '');
    if ($host === '') {
        return false;
    }
    return $scheme === 'https' || ($allowLoopback && $scheme === 'http' && insight_access_is_loopback_host($host));
}

function insight_access_issuer_ready(): bool
{
    return insight_access_is_secure_url(insight_access_public_url());
}

function insight_access_scope_catalog(): array
{
    return [
        'status:read' => 'Global status and engine',
        'monitors:read' => 'Read monitors',
        'monitors:write' => 'Monitor creation, editing, and deletion',
        'incidents:read' => 'Read incidents',
        'incidents:write' => 'Create, acknowledge, update, and resolve incidents',
        'maintenances:read' => 'Read scheduled maintenance',
        'maintenances:write' => 'Create, edit, and cancel scheduled maintenance',
        'status-pages:read' => 'Read public status page settings',
        'status-pages:write' => 'Manage public status pages and subscribers',
        'notifications:read' => 'Read channels and masked messages',
        'notifications:write' => 'Manage alert channels and messages',
    ];
}

function insight_access_oauth_scope_catalog(): array
{
    return [
        'openid' => 'OpenID Connect identity',
        'profile' => 'Name and role',
        'email' => 'Email address',
        'status:read' => 'Global status and engine',
        'monitors:read' => 'Read monitors',
        'incidents:read' => 'Read incidents',
        'maintenances:read' => 'Read scheduled maintenance',
        'status-pages:read' => 'Read public status page settings',
        'notifications:read' => 'Read channels and masked messages',
    ];
}

function insight_access_scope_i18n_key(string $scope): string
{
    return 'admin.access.scope.' . str_replace(':', '_', $scope);
}

function insight_access_normalize_scopes(mixed $value, array $catalog): array
{
    $items = is_string($value) ? preg_split('/[\s,]+/', trim($value)) : $value;
    if (!is_array($items)) {
        return [];
    }
    $allowed = array_keys($catalog);
    $scopes = [];
    foreach ($items as $item) {
        $scope = trim((string)$item);
        if ($scope !== '' && in_array($scope, $allowed, true) && !in_array($scope, $scopes, true)) {
            $scopes[] = $scope;
        }
    }
    return $scopes;
}

function insight_access_feature_enabled(string $feature): bool
{
    return insight_auth_meta($feature) === '1';
}

function insight_access_set_feature(string $feature, bool $enabled): void
{
    if (!in_array($feature, ['headless_api_enabled', 'oauth_provider_enabled'], true)) {
        throw new InvalidArgumentException('Unknown feature.');
    }
    if ($enabled && $feature === 'oauth_provider_enabled' && !insight_access_issuer_ready()) {
        throw new RuntimeException('admin.access.errorPublicUrl');
    }
    $statement = insight_auth_db()->prepare(
        'INSERT INTO auth_meta (key, value) VALUES (:key, :value)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $statement->execute(['key' => $feature, 'value' => $enabled ? '1' : '0']);
    if (!$enabled && $feature === 'oauth_provider_enabled') {
        $now = time();
        insight_auth_db()->prepare('UPDATE auth_oauth_access_tokens SET revoked_at = :now WHERE revoked_at IS NULL')
            ->execute(['now' => $now]);
        insight_auth_db()->exec('DELETE FROM auth_oauth_codes');
    }
}

function insight_access_local_owner_id(array $user): ?int
{
    return insight_auth_local_user_id($user);
}

function insight_access_create_token(array $input, array $user): array
{
    $name = trim((string)($input['name'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.access.errorName'];
    }
    $scopes = insight_access_normalize_scopes($input['scopes'] ?? [], insight_access_scope_catalog());
    if ($scopes === []) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.access.errorScopes'];
    }
    $expiresInDays = filter_var($input['expires_in_days'] ?? 90, FILTER_VALIDATE_INT);
    if ($expiresInDays === false || $expiresInDays < 0 || $expiresInDays > 3650) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.access.errorExpiry'];
    }
    $secret = insight_access_base64url_encode(random_bytes(32));
    $token = 'insight_pat_' . $secret;
    $prefix = substr($token, 0, 24);
    $expiresAt = $expiresInDays === 0 ? null : time() + (int)$expiresInDays * 86400;
    $statement = insight_auth_db()->prepare(
        'INSERT INTO auth_api_tokens
         (name, token_prefix, token_hash, scopes_json, expires_at, created_by_user_id)
         VALUES (:name, :prefix, :hash, :scopes, :expires_at, :owner)'
    );
    $statement->execute([
        'name' => $name,
        'prefix' => $prefix,
        'hash' => hash('sha256', $token),
        'scopes' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
        'expires_at' => $expiresAt,
        'owner' => insight_access_local_owner_id($user),
    ]);
    $tokenId = (int)insight_auth_db()->lastInsertId();
    insight_auth_audit('api_token_created', insight_access_local_owner_id($user));
    return [
        'ok' => true,
        'status_code' => 201,
        'token' => $token,
        'item' => [
            'id' => $tokenId,
            'name' => $name,
            'prefix' => $prefix . '…',
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ],
    ];
}

function insight_access_revoke_token(int $id, array $user): array
{
    if ($id < 1) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.access.errorNotFound'];
    }
    $statement = insight_auth_db()->prepare(
        'UPDATE auth_api_tokens SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL'
    );
    $statement->execute(['now' => time(), 'id' => $id]);
    if ($statement->rowCount() < 1) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.access.errorNotFound'];
    }
    insight_auth_audit('api_token_revoked', insight_access_local_owner_id($user));
    return ['ok' => true, 'status_code' => 200];
}

function insight_access_normalize_redirect_uris(mixed $value): array
{
    $items = is_string($value) ? preg_split('/[\r\n,]+/', $value) : $value;
    if (!is_array($items)) {
        return [];
    }
    $uris = [];
    foreach ($items as $item) {
        $uri = trim((string)$item);
        if ($uri !== '' && !in_array($uri, $uris, true)) {
            $uris[] = $uri;
        }
    }
    return $uris;
}

function insight_access_create_oauth_client(array $input, array $user): array
{
    $name = trim((string)($input['name'] ?? ''));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.access.errorName'];
    }
    $redirectUris = insight_access_normalize_redirect_uris($input['redirect_uris'] ?? []);
    if ($redirectUris === [] || count($redirectUris) > 10) {
        return ['ok' => false, 'status_code' => 422, 'error' => 'admin.access.errorRedirectUri'];
    }
    foreach ($redirectUris as $uri) {
        if (strlen($uri) > 2048 || !insight_access_is_secure_url($uri)) {
            return ['ok' => false, 'status_code' => 422, 'error' => 'admin.access.errorRedirectUri'];
        }
    }
    $scopes = insight_access_normalize_scopes($input['scopes'] ?? ['openid', 'profile'], insight_access_oauth_scope_catalog());
    if (!in_array('openid', $scopes, true)) {
        array_unshift($scopes, 'openid');
    }
    $clientId = 'insight_' . insight_access_base64url_encode(random_bytes(18));
    $clientSecret = 'insight_oauth_' . insight_access_base64url_encode(random_bytes(32));
    $statement = insight_auth_db()->prepare(
        'INSERT INTO auth_oauth_clients
         (name, client_id, client_secret_hash, redirect_uris_json, scopes_json, created_by_user_id)
         VALUES (:name, :client_id, :secret_hash, :redirects, :scopes, :owner)'
    );
    $statement->execute([
        'name' => $name,
        'client_id' => $clientId,
        'secret_hash' => insight_auth_password_hash($clientSecret),
        'redirects' => json_encode($redirectUris, JSON_UNESCAPED_SLASHES),
        'scopes' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
        'owner' => insight_access_local_owner_id($user),
    ]);
    $clientDatabaseId = (int)insight_auth_db()->lastInsertId();
    insight_auth_audit('oauth_client_created', insight_access_local_owner_id($user));
    return [
        'ok' => true,
        'status_code' => 201,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'item' => [
            'id' => $clientDatabaseId,
            'name' => $name,
            'client_id' => $clientId,
            'redirect_uris' => $redirectUris,
            'scopes' => $scopes,
            'enabled' => true,
        ],
    ];
}

function insight_access_delete_oauth_client(int $id, array $user): array
{
    if ($id < 1) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.access.errorNotFound'];
    }
    $statement = insight_auth_db()->prepare('DELETE FROM auth_oauth_clients WHERE id = :id');
    $statement->execute(['id' => $id]);
    if ($statement->rowCount() < 1) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'admin.access.errorNotFound'];
    }
    insight_auth_audit('oauth_client_deleted', insight_access_local_owner_id($user));
    return ['ok' => true, 'status_code' => 200];
}

function insight_access_tokens(): array
{
    $rows = insight_auth_db()->query(
        'SELECT id, name, token_prefix, scopes_json, expires_at, last_used_at, revoked_at, created_at
         FROM auth_api_tokens ORDER BY id DESC'
    )->fetchAll();
    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'prefix' => (string)$row['token_prefix'] . '…',
            'scopes' => insight_access_json_array($row['scopes_json']),
            'expires_at' => $row['expires_at'] === null ? null : (int)$row['expires_at'],
            'last_used_at' => $row['last_used_at'] === null ? null : (int)$row['last_used_at'],
            'revoked_at' => $row['revoked_at'] === null ? null : (int)$row['revoked_at'],
            'created_at' => (string)$row['created_at'],
        ];
    }, $rows);
}

function insight_access_oauth_clients(): array
{
    $rows = insight_auth_db()->query(
        'SELECT id, name, client_id, redirect_uris_json, scopes_json, enabled, last_used_at, created_at
         FROM auth_oauth_clients ORDER BY id DESC'
    )->fetchAll();
    return array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'client_id' => (string)$row['client_id'],
            'redirect_uris' => insight_access_json_array($row['redirect_uris_json']),
            'scopes' => insight_access_json_array($row['scopes_json']),
            'enabled' => (int)$row['enabled'] === 1,
            'last_used_at' => $row['last_used_at'] === null ? null : (int)$row['last_used_at'],
            'created_at' => (string)$row['created_at'],
        ];
    }, $rows);
}

function insight_access_cleanup(): void
{
    if (random_int(1, 40) !== 1) {
        return;
    }
    $threshold = time() - 86400;
    $database = insight_auth_db();
    $database->prepare('DELETE FROM auth_oauth_codes WHERE expires_at < :threshold OR used_at IS NOT NULL')
        ->execute(['threshold' => $threshold]);
    $database->prepare('DELETE FROM auth_oauth_access_tokens WHERE expires_at < :threshold OR revoked_at IS NOT NULL')
        ->execute(['threshold' => $threshold]);
}

function insight_access_state(): array
{
    insight_access_cleanup();
    return [
        'ok' => true,
        'api_enabled' => insight_access_feature_enabled('headless_api_enabled'),
        'oauth_provider_enabled' => insight_access_feature_enabled('oauth_provider_enabled'),
        'issuer_ready' => insight_access_issuer_ready(),
        'issuer' => insight_access_public_url(),
        'api_base_url' => insight_access_public_url() . '/api/v1',
        'discovery_url' => insight_access_public_url() . '/.well-known/openid-configuration',
        'scope_catalog' => insight_access_scope_catalog(),
        'oauth_scope_catalog' => insight_access_oauth_scope_catalog(),
        'tokens' => insight_access_tokens(),
        'oauth_clients' => insight_access_oauth_clients(),
    ];
}

function insight_access_authorization_header(): string
{
    $value = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($value !== '') {
        return $value;
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $headerValue) {
            if (strcasecmp((string)$name, 'Authorization') === 0) {
                return trim((string)$headerValue);
            }
        }
    }
    return '';
}

function insight_access_bearer_token(): string
{
    $header = insight_access_authorization_header();
    return preg_match('/^Bearer\s+([^\s]+)$/i', $header, $matches) === 1 ? (string)$matches[1] : '';
}

function insight_access_scopes_cover(array $granted, array $required): bool
{
    foreach ($required as $scope) {
        if (!in_array($scope, $granted, true)) {
            return false;
        }
    }
    return true;
}

function insight_access_authenticate_bearer(array $requiredScopes, array $kinds = ['pat', 'oauth']): ?array
{
    $token = insight_access_bearer_token();
    if ($token === '') {
        return null;
    }
    $hash = hash('sha256', $token);
    $now = time();
    if (in_array('pat', $kinds, true)) {
        $statement = insight_auth_db()->prepare(
            'SELECT id, name, scopes_json, expires_at FROM auth_api_tokens
             WHERE token_hash = :hash AND revoked_at IS NULL LIMIT 1'
        );
        $statement->execute(['hash' => $hash]);
        $row = $statement->fetch();
        if (is_array($row) && ($row['expires_at'] === null || (int)$row['expires_at'] > $now)) {
            $scopes = insight_access_json_array($row['scopes_json']);
            if (!insight_access_scopes_cover($scopes, $requiredScopes)) {
                return ['forbidden' => true, 'scopes' => $scopes];
            }
            insight_auth_db()->prepare(
                'UPDATE auth_api_tokens SET last_used_at = :now, last_ip_hash = :ip_hash WHERE id = :id'
            )->execute(['now' => $now, 'ip_hash' => insight_auth_ip_hash(), 'id' => (int)$row['id']]);
            return [
                'kind' => 'pat',
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'scopes' => $scopes,
            ];
        }
    }
    if (in_array('oauth', $kinds, true)) {
        $statement = insight_auth_db()->prepare(
            'SELECT id, client_id, scopes_json, subject, username, email, role, expires_at
             FROM auth_oauth_access_tokens
             WHERE token_hash = :hash AND revoked_at IS NULL LIMIT 1'
        );
        $statement->execute(['hash' => $hash]);
        $row = $statement->fetch();
        if (is_array($row) && (int)$row['expires_at'] > $now) {
            $scopes = insight_access_json_array($row['scopes_json']);
            if (!insight_access_scopes_cover($scopes, $requiredScopes)) {
                return ['forbidden' => true, 'scopes' => $scopes];
            }
            insight_auth_db()->prepare(
                'UPDATE auth_oauth_access_tokens SET last_used_at = :now WHERE id = :id'
            )->execute(['now' => $now, 'id' => (int)$row['id']]);
            return [
                'kind' => 'oauth',
                'id' => (int)$row['id'],
                'client_id' => (string)$row['client_id'],
                'subject' => (string)$row['subject'],
                'username' => (string)$row['username'],
                'email' => (string)($row['email'] ?? ''),
                'role' => (string)$row['role'],
                'scopes' => $scopes,
            ];
        }
    }
    return null;
}

function insight_access_allowed_origins(): array
{
    $configured = insight_admin_env('INSIGHT_API_ALLOWED_ORIGINS', insight_admin_env('INSIGHT_ALLOWED_ORIGINS'));
    return array_values(array_unique(array_filter(array_map(
        static fn(string $origin): string => rtrim(trim($origin), '/'),
        explode(',', $configured)
    ))));
}

function insight_access_apply_cors(): void
{
    $origin = rtrim(trim((string)($_SERVER['HTTP_ORIGIN'] ?? '')), '/');
    if ($origin === '') {
        return;
    }
    if (!in_array($origin, insight_access_allowed_origins(), true)) {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
            insight_access_json_response(['ok' => false, 'error' => 'origin_not_allowed'], 403);
        }
        return;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}

function insight_access_require_api(array $requiredScopes): array
{
    insight_access_apply_cors();
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (!insight_access_feature_enabled('headless_api_enabled')) {
        insight_access_json_response(['ok' => false, 'error' => 'api_disabled'], 404);
    }
    $identity = insight_access_authenticate_bearer($requiredScopes);
    if ($identity === null) {
        header('WWW-Authenticate: Bearer realm="Insight API"');
        insight_access_json_response(['ok' => false, 'error' => 'invalid_token'], 401);
    }
    if (($identity['forbidden'] ?? false) === true) {
        header('WWW-Authenticate: Bearer error="insufficient_scope", scope="' . implode(' ', $requiredScopes) . '"');
        insight_access_json_response(['ok' => false, 'error' => 'insufficient_scope'], 403);
    }
    return $identity;
}
