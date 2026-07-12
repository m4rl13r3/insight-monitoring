<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_oauth.php';

function insight_oidc_list_env(string $name): array
{
    return array_values(array_unique(array_filter(array_map(
        'trim',
        preg_split('/[\r\n,]+/', insight_admin_env($name)) ?: []
    ))));
}

function insight_oidc_config(): array
{
    $issuer = rtrim(insight_admin_env('INSIGHT_SSO_ISSUER_URL'), '/');
    $clientId = insight_admin_env('INSIGHT_SSO_CLIENT_ID');
    $clientSecret = insight_admin_env('INSIGHT_SSO_CLIENT_SECRET');
    $enabled = insight_admin_env_bool('INSIGHT_SSO_ENABLED');
    $scopes = array_values(array_unique(array_filter(preg_split(
        '/\s+/',
        insight_admin_env('INSIGHT_SSO_SCOPES', 'openid profile email')
    ) ?: [])));
    if (!in_array('openid', $scopes, true)) {
        array_unshift($scopes, 'openid');
    }
    $allowedEmails = array_map('strtolower', insight_oidc_list_env('INSIGHT_SSO_ALLOWED_EMAILS'));
    $allowedGroups = insight_oidc_list_env('INSIGHT_SSO_ALLOWED_GROUPS');
    $allowAll = insight_admin_env_bool('INSIGHT_SSO_ALLOW_ALL');
    $issuerHost = strtolower((string)(parse_url($issuer, PHP_URL_HOST) ?: ''));
    $allowedEndpointHosts = array_map('strtolower', insight_oidc_list_env('INSIGHT_SSO_ALLOWED_ENDPOINT_HOSTS'));
    if ($issuerHost !== '' && !in_array($issuerHost, $allowedEndpointHosts, true)) {
        array_unshift($allowedEndpointHosts, $issuerHost);
    }
    $environment = strtolower(insight_admin_env('INSIGHT_APP_ENV', 'production'));
    $issuerScheme = strtolower((string)(parse_url($issuer, PHP_URL_SCHEME) ?: ''));
    $httpLoopbackAllowed = $issuerScheme === 'http'
        && insight_access_is_loopback_host($issuerHost)
        && in_array($environment, ['development', 'dev', 'local', 'test', 'test-suite'], true);
    $valid = !$enabled || (
        insight_access_is_secure_url($issuer)
        && ($issuerScheme === 'https' || $httpLoopbackAllowed)
        && $clientId !== ''
        && $clientSecret !== ''
        && insight_access_issuer_ready()
        && ($allowAll || $allowedEmails !== [] || $allowedGroups !== [])
    );
    return [
        'enabled' => $enabled,
        'valid' => $valid,
        'provider_name' => insight_admin_env('INSIGHT_SSO_PROVIDER_NAME', 'SSO'),
        'issuer' => $issuer,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scopes' => $scopes,
        'username_claim' => insight_admin_env('INSIGHT_SSO_USERNAME_CLAIM', 'preferred_username'),
        'groups_claim' => insight_admin_env('INSIGHT_SSO_GROUPS_CLAIM', 'groups'),
        'allowed_emails' => $allowedEmails,
        'allowed_groups' => $allowedGroups,
        'admin_groups' => insight_oidc_list_env('INSIGHT_SSO_ADMIN_GROUPS'),
        'allowed_endpoint_hosts' => $allowedEndpointHosts,
        'require_verified_email' => insight_admin_env_bool('INSIGHT_SSO_REQUIRE_VERIFIED_EMAIL', true),
        'allow_all' => $allowAll,
        'auto_login' => insight_admin_env_bool('INSIGHT_SSO_AUTO_LOGIN'),
        'hide_local_login' => insight_admin_env_bool('INSIGHT_SSO_HIDE_LOCAL_LOGIN'),
        'callback_url' => insight_access_public_url() . '/admin/sso/callback.php',
    ];
}

function insight_oidc_public_state(): array
{
    $config = insight_oidc_config();
    return [
        'enabled' => (bool)$config['enabled'],
        'valid' => (bool)$config['valid'],
        'provider_name' => (string)$config['provider_name'],
        'issuer' => (string)$config['issuer'],
        'callback_url' => (string)$config['callback_url'],
        'auto_login' => (bool)$config['auto_login'],
        'hide_local_login' => (bool)$config['hide_local_login'],
        'policy_configured' => (bool)$config['allow_all'] || $config['allowed_emails'] !== [] || $config['allowed_groups'] !== [],
    ];
}

function insight_oidc_cache_path(string $kind, string $url): string
{
    return dirname(insight_admin_auth_path()) . '/oidc-' . $kind . '-' . substr(hash('sha256', $url), 0, 20) . '.json';
}

function insight_oidc_http_json(string $url, ?array $form = null, ?array $basic = null): array
{
    if (!extension_loaded('curl') || !insight_access_is_secure_url($url)) {
        throw new RuntimeException('oidc_endpoint_invalid');
    }
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('oidc_request_failed');
    }
    $headers = ['Accept: application/json'];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Insight-OIDC/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS') && defined('CURLPROTO_HTTP')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP;
    }
    if ($form !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER] = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];
    }
    if ($basic !== null) {
        $options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $options[CURLOPT_USERPWD] = (string)$basic[0] . ':' . (string)$basic[1];
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($curl);
    curl_close($curl);
    if (!is_string($body) || $error !== 0 || $status < 200 || $status >= 300 || strlen($body) > 1048576) {
        throw new RuntimeException('oidc_request_failed');
    }
    $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('oidc_response_invalid');
    }
    return $decoded;
}

function insight_oidc_cached_json(string $kind, string $url, int $ttl): array
{
    $path = insight_oidc_cache_path($kind, $url);
    if (is_file($path) && filemtime($path) !== false && filemtime($path) >= time() - $ttl) {
        $cached = json_decode((string)file_get_contents($path), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $value = insight_oidc_http_json($url);
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($encoded)) {
        file_put_contents($path, $encoded, LOCK_EX);
        @chmod($path, 0600);
    }
    return $value;
}

function insight_oidc_discovery(array $config): array
{
    if (!($config['enabled'] ?? false) || !($config['valid'] ?? false)) {
        throw new RuntimeException('oidc_configuration_invalid');
    }
    $url = (string)$config['issuer'] . '/.well-known/openid-configuration';
    $discovery = insight_oidc_cached_json('discovery', $url, 300);
    if (!hash_equals((string)$config['issuer'], rtrim((string)($discovery['issuer'] ?? ''), '/'))) {
        throw new RuntimeException('oidc_issuer_mismatch');
    }
    foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
        $endpoint = (string)($discovery[$field] ?? '');
        $endpointHost = strtolower((string)(parse_url($endpoint, PHP_URL_HOST) ?: ''));
        if (
            !insight_access_is_secure_url($endpoint)
            || !in_array($endpointHost, (array)$config['allowed_endpoint_hosts'], true)
        ) {
            throw new RuntimeException('oidc_endpoint_invalid');
        }
    }
    $algorithms = (array)($discovery['id_token_signing_alg_values_supported'] ?? []);
    if ($algorithms !== [] && !in_array('RS256', $algorithms, true)) {
        throw new RuntimeException('oidc_algorithm_unsupported');
    }
    return $discovery;
}

function insight_oidc_start(string $next): never
{
    $config = insight_oidc_config();
    $discovery = insight_oidc_discovery($config);
    $verifier = insight_access_base64url_encode(random_bytes(64));
    $state = insight_access_base64url_encode(random_bytes(32));
    $nonce = insight_access_base64url_encode(random_bytes(32));
    $_SESSION['oidc_login'] = [
        'state' => $state,
        'nonce' => $nonce,
        'verifier' => $verifier,
        'next' => insight_auth_safe_next($next),
        'created_at' => time(),
    ];
    $parameters = [
        'response_type' => 'code',
        'client_id' => (string)$config['client_id'],
        'redirect_uri' => (string)$config['callback_url'],
        'scope' => implode(' ', (array)$config['scopes']),
        'state' => $state,
        'nonce' => $nonce,
        'code_challenge' => insight_access_base64url_encode(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
    ];
    insight_auth_redirect(insight_oauth_append_query((string)$discovery['authorization_endpoint'], $parameters));
}

function insight_oidc_der_length(int $length): string
{
    if ($length < 128) {
        return chr($length);
    }
    $bytes = '';
    while ($length > 0) {
        $bytes = chr($length & 0xff) . $bytes;
        $length >>= 8;
    }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

function insight_oidc_der(int $tag, string $value): string
{
    return chr($tag) . insight_oidc_der_length(strlen($value)) . $value;
}

function insight_oidc_der_integer(string $value): string
{
    $value = ltrim($value, "\x00");
    if ($value === '') {
        $value = "\x00";
    }
    if ((ord($value[0]) & 0x80) !== 0) {
        $value = "\x00" . $value;
    }
    return insight_oidc_der(0x02, $value);
}

function insight_oidc_jwk_public_key(array $jwk): string
{
    if (
        ($jwk['kty'] ?? '') !== 'RSA'
        || (($jwk['alg'] ?? 'RS256') !== 'RS256')
        || (isset($jwk['use']) && $jwk['use'] !== 'sig')
        || (isset($jwk['key_ops']) && (!is_array($jwk['key_ops']) || !in_array('verify', $jwk['key_ops'], true)))
    ) {
        throw new RuntimeException('oidc_key_invalid');
    }
    if (isset($jwk['x5c'][0]) && is_string($jwk['x5c'][0])) {
        $certificate = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split($jwk['x5c'][0], 64, "\n")
            . "-----END CERTIFICATE-----\n";
        $key = openssl_pkey_get_public($certificate);
        $details = $key instanceof OpenSSLAsymmetricKey ? openssl_pkey_get_details($key) : false;
        if (is_array($details) && isset($details['key']) && (int)($details['bits'] ?? 0) >= 2048) {
            return (string)$details['key'];
        }
    }
    $modulus = insight_access_base64url_decode((string)($jwk['n'] ?? ''));
    $exponent = insight_access_base64url_decode((string)($jwk['e'] ?? ''));
    if (!is_string($modulus) || !is_string($exponent)) {
        throw new RuntimeException('oidc_key_invalid');
    }
    $rsa = insight_oidc_der(0x30, insight_oidc_der_integer($modulus) . insight_oidc_der_integer($exponent));
    $algorithm = hex2bin('300d06092a864886f70d0101010500');
    if (!is_string($algorithm)) {
        throw new RuntimeException('oidc_key_invalid');
    }
    $spki = insight_oidc_der(0x30, $algorithm . insight_oidc_der(0x03, "\x00" . $rsa));
    $pem = "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($spki), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
    $key = openssl_pkey_get_public($pem);
    $details = $key instanceof OpenSSLAsymmetricKey ? openssl_pkey_get_details($key) : false;
    if (!is_array($details) || (int)($details['bits'] ?? 0) < 2048) {
        throw new RuntimeException('oidc_key_invalid');
    }
    return $pem;
}

function insight_oidc_decode_and_verify(string $jwt, array $config, array $discovery, string $nonce): array
{
    $segments = explode('.', $jwt);
    if (count($segments) !== 3) {
        throw new RuntimeException('oidc_token_invalid');
    }
    [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;
    $headerJson = insight_access_base64url_decode($encodedHeader);
    $payloadJson = insight_access_base64url_decode($encodedPayload);
    $signature = insight_access_base64url_decode($encodedSignature);
    if (!is_string($headerJson) || !is_string($payloadJson) || !is_string($signature)) {
        throw new RuntimeException('oidc_token_invalid');
    }
    $header = json_decode($headerJson, true, 32, JSON_THROW_ON_ERROR);
    $claims = json_decode($payloadJson, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256') {
        throw new RuntimeException('oidc_token_invalid');
    }
    $jwks = insight_oidc_cached_json('jwks', (string)$discovery['jwks_uri'], 300);
    $keys = is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [];
    $kid = (string)($header['kid'] ?? '');
    $matches = array_values(array_filter($keys, static fn(mixed $key): bool => is_array($key) && ($kid === '' || (string)($key['kid'] ?? '') === $kid)));
    if (count($matches) !== 1) {
        @unlink(insight_oidc_cache_path('jwks', (string)$discovery['jwks_uri']));
        $jwks = insight_oidc_cached_json('jwks', (string)$discovery['jwks_uri'], 0);
        $keys = is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [];
        $matches = array_values(array_filter($keys, static fn(mixed $key): bool => is_array($key) && ($kid === '' || (string)($key['kid'] ?? '') === $kid)));
    }
    if (count($matches) !== 1) {
        throw new RuntimeException('oidc_key_not_found');
    }
    $publicKey = insight_oidc_jwk_public_key($matches[0]);
    if (openssl_verify($encodedHeader . '.' . $encodedPayload, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('oidc_signature_invalid');
    }
    $now = time();
    if (!hash_equals((string)$config['issuer'], rtrim((string)($claims['iss'] ?? ''), '/'))) {
        throw new RuntimeException('oidc_issuer_mismatch');
    }
    $audience = $claims['aud'] ?? null;
    $audiences = is_array($audience) ? array_map('strval', $audience) : [(string)$audience];
    if (!in_array((string)$config['client_id'], $audiences, true)) {
        throw new RuntimeException('oidc_audience_invalid');
    }
    if (count($audiences) > 1 && !hash_equals((string)$config['client_id'], (string)($claims['azp'] ?? ''))) {
        throw new RuntimeException('oidc_audience_invalid');
    }
    if ((int)($claims['exp'] ?? 0) <= $now - 60 || (int)($claims['iat'] ?? PHP_INT_MAX) > $now + 60) {
        throw new RuntimeException('oidc_token_expired');
    }
    if (isset($claims['nbf']) && (int)$claims['nbf'] > $now + 60) {
        throw new RuntimeException('oidc_token_invalid');
    }
    $subject = trim((string)($claims['sub'] ?? ''));
    if (!hash_equals($nonce, (string)($claims['nonce'] ?? '')) || $subject === '' || strlen($subject) > 512) {
        throw new RuntimeException('oidc_token_invalid');
    }
    return $claims;
}

function insight_oidc_claim(array $claims, string $path): mixed
{
    $value = $claims;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }
    return $value;
}

function insight_oidc_groups(array $claims, string $claim): array
{
    $value = insight_oidc_claim($claims, $claim);
    if (is_string($value)) {
        return array_values(array_filter(preg_split('/[\s,]+/', trim($value)) ?: []));
    }
    return is_array($value) ? array_values(array_filter(array_map('strval', $value))) : [];
}

function insight_oidc_policy_allows(array $claims, array $config): bool
{
    if ($config['allow_all']) {
        $allowed = true;
    } else {
        $email = strtolower(trim((string)($claims['email'] ?? '')));
        $groups = insight_oidc_groups($claims, (string)$config['groups_claim']);
        $emailVerified = ($claims['email_verified'] ?? false) === true
            || ($claims['email_verified'] ?? '') === 'true';
        $allowedEmail = $email !== ''
            && in_array($email, $config['allowed_emails'], true)
            && (!($config['require_verified_email'] ?? true) || $emailVerified);
        $allowed = $allowedEmail
            || array_intersect($groups, $config['allowed_groups']) !== [];
    }
    if (!$allowed) {
        return false;
    }
    $adminGroups = (array)$config['admin_groups'];
    return $adminGroups === []
        || array_intersect(insight_oidc_groups($claims, (string)$config['groups_claim']), $adminGroups) !== [];
}

function insight_oidc_identity(array $claims, array $config): array
{
    if (!insight_oidc_policy_allows($claims, $config)) {
        throw new RuntimeException('oidc_access_denied');
    }
    $subject = trim((string)$claims['sub']);
    $username = trim((string)(insight_oidc_claim($claims, (string)$config['username_claim']) ?? ''));
    $email = trim((string)($claims['email'] ?? ''));
    if ($username === '') {
        $username = $email !== '' ? $email : 'sso-' . substr(hash('sha256', $subject), 0, 12);
    }
    $database = insight_auth_db();
    $statement = $database->prepare(
        'INSERT INTO auth_sso_identities
         (issuer, subject, username, email, role, last_login_at)
         VALUES (:issuer, :subject, :username, :email, :role, CURRENT_TIMESTAMP)
         ON CONFLICT(issuer, subject) DO UPDATE SET
             username = excluded.username,
             email = excluded.email,
             role = excluded.role,
             updated_at = CURRENT_TIMESTAMP,
             last_login_at = CURRENT_TIMESTAMP'
    );
    $statement->execute([
        'issuer' => (string)$config['issuer'],
        'subject' => $subject,
        'username' => mb_substr($username, 0, 160),
        'email' => $email === '' ? null : mb_substr($email, 0, 320),
        'role' => 'admin',
    ]);
    $select = $database->prepare(
        'SELECT id, issuer, subject, username, email, role FROM auth_sso_identities
         WHERE issuer = :issuer AND subject = :subject AND active = 1 LIMIT 1'
    );
    $select->execute(['issuer' => (string)$config['issuer'], 'subject' => $subject]);
    $identity = $select->fetch();
    if (!is_array($identity)) {
        throw new RuntimeException('oidc_identity_disabled');
    }
    $identity['source'] = 'oidc';
    return $identity;
}

function insight_oidc_callback(array $query): array
{
    $flow = $_SESSION['oidc_login'] ?? null;
    unset($_SESSION['oidc_login']);
    if (!is_array($flow) || (int)($flow['created_at'] ?? 0) < time() - 600) {
        throw new RuntimeException('oidc_session_expired');
    }
    if (!hash_equals((string)$flow['state'], (string)($query['state'] ?? ''))) {
        throw new RuntimeException('oidc_state_invalid');
    }
    if (isset($query['error'])) {
        throw new RuntimeException((string)$query['error'] === 'access_denied' ? 'oidc_access_denied' : 'oidc_provider_error');
    }
    $code = trim((string)($query['code'] ?? ''));
    if ($code === '' || strlen($code) > 4096) {
        throw new RuntimeException('oidc_code_invalid');
    }
    $config = insight_oidc_config();
    $discovery = insight_oidc_discovery($config);
    $form = [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => (string)$config['callback_url'],
        'client_id' => (string)$config['client_id'],
        'code_verifier' => (string)$flow['verifier'],
    ];
    $methods = (array)($discovery['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic']);
    if (in_array('client_secret_basic', $methods, true)) {
        $token = insight_oidc_http_json(
            (string)$discovery['token_endpoint'],
            $form,
            [(string)$config['client_id'], (string)$config['client_secret']]
        );
    } else {
        $form['client_secret'] = (string)$config['client_secret'];
        $token = insight_oidc_http_json((string)$discovery['token_endpoint'], $form);
    }
    $idToken = (string)($token['id_token'] ?? '');
    if ($idToken === '') {
        throw new RuntimeException('oidc_token_invalid');
    }
    $claims = insight_oidc_decode_and_verify($idToken, $config, $discovery, (string)$flow['nonce']);
    $identity = insight_oidc_identity($claims, $config);
    insight_auth_open_session($identity, false);
    insight_auth_audit('sso_login_succeeded');
    return ['user' => $identity, 'next' => insight_auth_safe_next($flow['next'] ?? '/admin/')];
}

function insight_oidc_error_key(Throwable $exception): string
{
    return match ($exception->getMessage()) {
        'oidc_configuration_invalid' => 'admin.sso.errorConfiguration',
        'oidc_access_denied', 'access_denied' => 'admin.sso.errorDenied',
        'oidc_session_expired', 'oidc_state_invalid' => 'admin.sso.errorSession',
        default => 'admin.sso.errorGeneric',
    };
}
