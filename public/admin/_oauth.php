<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_access.php';

function insight_oauth_error(string $error, string $description, int $statusCode = 400): never
{
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    insight_access_json_response([
        'error' => $error,
        'error_description' => $description,
    ], $statusCode);
}

function insight_oauth_append_query(string $uri, array $parameters): string
{
    $separator = str_contains($uri, '?') ? '&' : '?';
    return $uri . $separator . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
}

function insight_oauth_client(string $clientId): ?array
{
    $statement = insight_auth_db()->prepare(
        'SELECT id, name, client_id, client_secret_hash, redirect_uris_json, scopes_json
         FROM auth_oauth_clients WHERE client_id = :client_id AND enabled = 1 LIMIT 1'
    );
    $statement->execute(['client_id' => $clientId]);
    $client = $statement->fetch();
    if (!is_array($client)) {
        return null;
    }
    $client['redirect_uris'] = insight_access_json_array($client['redirect_uris_json']);
    $client['scopes'] = insight_access_json_array($client['scopes_json']);
    return $client;
}

function insight_oauth_authorization_request(array $input): array
{
    if (!insight_access_feature_enabled('oauth_provider_enabled')) {
        return ['ok' => false, 'status_code' => 404, 'error' => 'provider_disabled'];
    }
    $clientId = trim((string)($input['client_id'] ?? ''));
    $client = insight_oauth_client($clientId);
    if ($client === null) {
        return ['ok' => false, 'status_code' => 400, 'error' => 'invalid_client'];
    }
    $redirectUri = trim((string)($input['redirect_uri'] ?? ''));
    if (!in_array($redirectUri, $client['redirect_uris'], true)) {
        return ['ok' => false, 'status_code' => 400, 'error' => 'invalid_redirect_uri'];
    }
    if ((string)($input['response_type'] ?? '') !== 'code') {
        return ['ok' => false, 'status_code' => 400, 'error' => 'unsupported_response_type', 'redirect_uri' => $redirectUri];
    }
    $scopes = insight_access_normalize_scopes((string)($input['scope'] ?? 'openid'), insight_access_oauth_scope_catalog());
    $requestedRaw = array_values(array_filter(preg_split('/\s+/', trim((string)($input['scope'] ?? 'openid'))) ?: []));
    if ($scopes === [] || count($scopes) !== count(array_unique($requestedRaw)) || !in_array('openid', $scopes, true)) {
        return ['ok' => false, 'status_code' => 400, 'error' => 'invalid_scope', 'redirect_uri' => $redirectUri];
    }
    if (!insight_access_scopes_cover((array)$client['scopes'], $scopes)) {
        return ['ok' => false, 'status_code' => 400, 'error' => 'invalid_scope', 'redirect_uri' => $redirectUri];
    }
    $state = (string)($input['state'] ?? '');
    $nonce = (string)($input['nonce'] ?? '');
    $challenge = (string)($input['code_challenge'] ?? '');
    if (strlen($state) < 8 || strlen($state) > 512 || preg_match('/[\r\n]/', $state) === 1) {
        return ['ok' => false, 'status_code' => 400, 'error' => 'invalid_request'];
    }
    if (strlen($nonce) < 8 || strlen($nonce) > 512 || preg_match('/[\r\n]/', $nonce) === 1) {
        return ['ok' => false, 'status_code' => 400, 'error' => 'invalid_request', 'redirect_uri' => $redirectUri, 'state' => $state];
    }
    if ((string)($input['code_challenge_method'] ?? '') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge) !== 1) {
        return ['ok' => false, 'status_code' => 400, 'error' => 'invalid_request', 'redirect_uri' => $redirectUri, 'state' => $state];
    }
    return [
        'ok' => true,
        'client' => $client,
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'scopes' => $scopes,
        'scope' => implode(' ', $scopes),
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ];
}

function insight_oauth_subject(array $user): string
{
    $source = (string)($user['source'] ?? 'local');
    $identity = match ($source) {
        'oidc' => 'oidc|' . (string)($user['issuer'] ?? '') . '|' . (string)($user['subject'] ?? ''),
        'development' => 'development|developer',
        default => 'local|' . (string)($user['id'] ?? 0),
    };
    return insight_access_base64url_encode(hash_hmac('sha256', $identity, insight_auth_meta('rate_limit_secret'), true));
}

function insight_oauth_create_code(array $request, array $user): string
{
    $code = insight_access_base64url_encode(random_bytes(32));
    $statement = insight_auth_db()->prepare(
        'INSERT INTO auth_oauth_codes
         (code_hash, client_id, redirect_uri, scopes_json, code_challenge, nonce,
          subject, username, email, role, expires_at)
         VALUES (:hash, :client_id, :redirect_uri, :scopes, :challenge, :nonce,
                 :subject, :username, :email, :role, :expires_at)'
    );
    $statement->execute([
        'hash' => hash('sha256', $code),
        'client_id' => (string)$request['client_id'],
        'redirect_uri' => (string)$request['redirect_uri'],
        'scopes' => json_encode($request['scopes'], JSON_UNESCAPED_SLASHES),
        'challenge' => (string)$request['code_challenge'],
        'nonce' => (string)$request['nonce'],
        'subject' => insight_oauth_subject($user),
        'username' => (string)$user['username'],
        'email' => trim((string)($user['email'] ?? '')) ?: null,
        'role' => (string)($user['role'] ?? 'admin'),
        'expires_at' => time() + 300,
    ]);
    return $code;
}

function insight_oauth_client_credentials(): array
{
    $clientId = '';
    $clientSecret = '';
    if (isset($_SERVER['PHP_AUTH_USER'])) {
        $clientId = (string)$_SERVER['PHP_AUTH_USER'];
        $clientSecret = (string)($_SERVER['PHP_AUTH_PW'] ?? '');
    } else {
        $header = insight_access_authorization_header();
        if (preg_match('/^Basic\s+(.+)$/i', $header, $matches) === 1) {
            $decoded = base64_decode((string)$matches[1], true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$encodedId, $encodedSecret] = explode(':', $decoded, 2);
                $clientId = rawurldecode($encodedId);
                $clientSecret = rawurldecode($encodedSecret);
            }
        }
    }
    if ($clientId === '') {
        $clientId = trim((string)($_POST['client_id'] ?? ''));
        $clientSecret = (string)($_POST['client_secret'] ?? '');
    }
    return [$clientId, $clientSecret];
}

function insight_oauth_signing_key_path(): string
{
    return dirname(insight_admin_auth_path()) . '/oidc-provider-private.pem';
}

function insight_oauth_private_key(): OpenSSLAsymmetricKey
{
    $path = insight_oauth_signing_key_path();
    if (is_file($path)) {
        $key = openssl_pkey_get_private((string)file_get_contents($path));
        if ($key instanceof OpenSSLAsymmetricKey) {
            return $key;
        }
        throw new RuntimeException('invalid_signing_key');
    }
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 3072]);
    if (!$key instanceof OpenSSLAsymmetricKey) {
        throw new RuntimeException('signing_key_generation_failed');
    }
    $privatePem = '';
    if (!openssl_pkey_export($key, $privatePem)) {
        throw new RuntimeException('signing_key_export_failed');
    }
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, $privatePem, LOCK_EX) === false) {
        throw new RuntimeException('signing_key_write_failed');
    }
    @chmod($temporary, 0600);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        if (is_file($path)) {
            $existing = openssl_pkey_get_private((string)file_get_contents($path));
            if ($existing instanceof OpenSSLAsymmetricKey) {
                return $existing;
            }
        }
        throw new RuntimeException('signing_key_write_failed');
    }
    @chmod($path, 0600);
    return $key;
}

function insight_oauth_key_details(): array
{
    $details = openssl_pkey_get_details(insight_oauth_private_key());
    if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'], $details['key'])) {
        throw new RuntimeException('invalid_signing_key');
    }
    $kid = substr(hash('sha256', (string)$details['key']), 0, 24);
    return ['details' => $details, 'kid' => $kid];
}

function insight_oauth_jwks(): array
{
    $key = insight_oauth_key_details();
    return [
        'keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $key['kid'],
            'n' => insight_access_base64url_encode((string)$key['details']['rsa']['n']),
            'e' => insight_access_base64url_encode((string)$key['details']['rsa']['e']),
        ]],
    ];
}

function insight_oauth_sign_jwt(array $claims): string
{
    $key = insight_oauth_key_details();
    $header = ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $key['kid']];
    $segments = [
        insight_access_base64url_encode((string)json_encode($header, JSON_UNESCAPED_SLASHES)),
        insight_access_base64url_encode((string)json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    ];
    $input = implode('.', $segments);
    $signature = '';
    if (!openssl_sign($input, $signature, insight_oauth_private_key(), OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('jwt_signing_failed');
    }
    $segments[] = insight_access_base64url_encode($signature);
    return implode('.', $segments);
}

function insight_oauth_exchange_code(): array
{
    if (!insight_access_feature_enabled('oauth_provider_enabled')) {
        insight_oauth_error('invalid_request', 'The OpenID Connect provider is disabled.', 404);
    }
    if ((string)($_POST['grant_type'] ?? '') !== 'authorization_code') {
        insight_oauth_error('unsupported_grant_type', 'Only authorization_code is accepted.');
    }
    [$clientId, $clientSecret] = insight_oauth_client_credentials();
    $rate = insight_auth_rate_limit('oauth-client:' . $clientId);
    if ($rate['blocked']) {
        header('Retry-After: ' . (string)$rate['retry_after']);
        insight_oauth_error('temporarily_unavailable', 'Too many attempts for this client.', 429);
    }
    $client = insight_oauth_client($clientId);
    if ($client === null || $clientSecret === '' || !password_verify($clientSecret, (string)$client['client_secret_hash'])) {
        insight_auth_record_attempt('oauth-client:' . $clientId, false);
        header('WWW-Authenticate: Basic realm="Insight OAuth"');
        insight_oauth_error('invalid_client', 'Identifiants client invalides.', 401);
    }
    insight_auth_record_attempt('oauth-client:' . $clientId, true);
    $grantRate = insight_auth_rate_limit('oauth-grant:' . $clientId);
    if ($grantRate['blocked']) {
        header('Retry-After: ' . (string)$grantRate['retry_after']);
        insight_oauth_error('temporarily_unavailable', 'Too many invalid codes for this client.', 429);
    }
    $code = (string)($_POST['code'] ?? '');
    $redirectUri = (string)($_POST['redirect_uri'] ?? '');
    $verifier = (string)($_POST['code_verifier'] ?? '');
    if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1 || $code === '' || $redirectUri === '') {
        insight_oauth_error('invalid_grant', 'Invalid code, redirect URI, or PKCE verifier.');
    }
    insight_oauth_key_details();
    $database = insight_auth_db();
    try {
        $database->exec('BEGIN IMMEDIATE');
        $statement = $database->prepare(
            'SELECT * FROM auth_oauth_codes
             WHERE code_hash = :hash AND client_id = :client_id AND used_at IS NULL LIMIT 1'
        );
        $statement->execute(['hash' => hash('sha256', $code), 'client_id' => $clientId]);
        $authorization = $statement->fetch();
        $expectedChallenge = insight_access_base64url_encode(hash('sha256', $verifier, true));
        if (
            !is_array($authorization)
            || (int)$authorization['expires_at'] <= time()
            || !hash_equals((string)$authorization['redirect_uri'], $redirectUri)
            || !hash_equals((string)$authorization['code_challenge'], $expectedChallenge)
        ) {
            $database->exec('ROLLBACK');
            insight_auth_record_attempt('oauth-grant:' . $clientId, false);
            insight_oauth_error('invalid_grant', 'The code is invalid, expired, or already used.');
        }
        insight_auth_record_attempt('oauth-grant:' . $clientId, true);
        $scopes = insight_access_json_array($authorization['scopes_json']);
        $now = time();
        $expiresAt = $now + 3600;
        $accessToken = 'insight_oat_' . insight_access_base64url_encode(random_bytes(32));
        $prefix = substr($accessToken, 0, 24);
        $idTokenClaims = [
            'iss' => insight_access_public_url(),
            'sub' => (string)$authorization['subject'],
            'aud' => $clientId,
            'iat' => $now,
            'exp' => $now + 300,
            'auth_time' => $now,
            'nonce' => (string)$authorization['nonce'],
            'preferred_username' => (string)$authorization['username'],
            'role' => (string)$authorization['role'],
        ];
        if (in_array('email', $scopes, true) && trim((string)($authorization['email'] ?? '')) !== '') {
            $idTokenClaims['email'] = (string)$authorization['email'];
        }
        $idToken = insight_oauth_sign_jwt($idTokenClaims);
        $database->prepare('UPDATE auth_oauth_codes SET used_at = :now WHERE id = :id')
            ->execute(['now' => $now, 'id' => (int)$authorization['id']]);
        $database->prepare(
            'INSERT INTO auth_oauth_access_tokens
             (token_prefix, token_hash, client_id, scopes_json, subject, username, email, role, expires_at)
             VALUES (:prefix, :hash, :client_id, :scopes, :subject, :username, :email, :role, :expires_at)'
        )->execute([
            'prefix' => $prefix,
            'hash' => hash('sha256', $accessToken),
            'client_id' => $clientId,
            'scopes' => json_encode($scopes, JSON_UNESCAPED_SLASHES),
            'subject' => (string)$authorization['subject'],
            'username' => (string)$authorization['username'],
            'email' => trim((string)($authorization['email'] ?? '')) ?: null,
            'role' => (string)$authorization['role'],
            'expires_at' => $expiresAt,
        ]);
        $database->prepare('UPDATE auth_oauth_clients SET last_used_at = :now WHERE client_id = :client_id')
            ->execute(['now' => $now, 'client_id' => $clientId]);
        $database->exec('COMMIT');
        return [
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'scope' => implode(' ', $scopes),
            'id_token' => $idToken,
        ];
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->exec('ROLLBACK');
        }
        insight_oauth_error('server_error', 'The token could not be issued.', 500);
    }
}

function insight_oauth_userinfo(array $identity): array
{
    $claims = [
        'sub' => (string)$identity['subject'],
        'preferred_username' => (string)$identity['username'],
        'role' => (string)$identity['role'],
    ];
    if (in_array('email', (array)$identity['scopes'], true) && trim((string)$identity['email']) !== '') {
        $claims['email'] = (string)$identity['email'];
    }
    return $claims;
}

function insight_oauth_discovery(): array
{
    $issuer = insight_access_public_url();
    return [
        'issuer' => $issuer,
        'authorization_endpoint' => $issuer . '/admin/oauth/authorize.php',
        'token_endpoint' => $issuer . '/api/oauth/token.php',
        'userinfo_endpoint' => $issuer . '/api/oauth/userinfo.php',
        'jwks_uri' => $issuer . '/api/oauth/jwks.php',
        'response_types_supported' => ['code'],
        'response_modes_supported' => ['query'],
        'grant_types_supported' => ['authorization_code'],
        'subject_types_supported' => ['public'],
        'id_token_signing_alg_values_supported' => ['RS256'],
        'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
        'scopes_supported' => array_keys(insight_access_oauth_scope_catalog()),
        'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'preferred_username', 'email', 'role'],
        'code_challenge_methods_supported' => ['S256'],
    ];
}
