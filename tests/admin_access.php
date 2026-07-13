<?php

declare(strict_types=1);

function insight_test_access_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function insight_test_access_remove_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

$temporaryDirectory = sys_get_temp_dir() . '/insight-access-' . bin2hex(random_bytes(8));
putenv('INSIGHT_AUTH_DB_PATH=' . $temporaryDirectory . '/auth.sqlite');
putenv('INSIGHT_APP_ENV=test-suite');
putenv('INSIGHT_DEV_AUTH_BYPASS=0');
putenv('INSIGHT_PUBLIC_URL=http://127.0.0.1:8787');
putenv('INSIGHT_SSO_ENABLED=0');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/admin/_oidc.php';

try {
    insight_test_access_assert(!insight_access_feature_enabled('headless_api_enabled'), 'The API should be disabled by default.');
    insight_test_access_assert(!insight_access_feature_enabled('oauth_provider_enabled'), 'OIDC should be disabled by default.');

    $password = 'Insight-access-2026!';
    $createdAdmin = insight_auth_create_first_admin('admin', $password, $password, false);
    insight_test_access_assert(($createdAdmin['ok'] ?? false) === true, 'The test account was not created.');
    $user = insight_auth_current_user();
    insight_test_access_assert(is_array($user), 'La session locale est absente.');

    $createdToken = insight_access_create_token([
        'name' => 'CI locale',
        'scopes' => ['status:read', 'monitors:read'],
        'expires_in_days' => 30,
    ], $user);
    insight_test_access_assert(($createdToken['ok'] ?? false) === true, 'The API token was not created.');
    $plainToken = (string)$createdToken['token'];
    $storedToken = insight_auth_db()->query('SELECT token_hash FROM auth_api_tokens LIMIT 1')->fetchColumn();
    insight_test_access_assert($storedToken === hash('sha256', $plainToken), 'The API token hash is invalid.');
    insight_test_access_assert(!str_contains((string)$storedToken, $plainToken), 'The API token was stored in plain text.');

    insight_access_set_feature('headless_api_enabled', true);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $plainToken;
    $apiIdentity = insight_access_authenticate_bearer(['status:read']);
    insight_test_access_assert(($apiIdentity['kind'] ?? '') === 'pat', 'A valid API token was rejected.');
    $forbidden = insight_access_authenticate_bearer(['monitors:write']);
    insight_test_access_assert(($forbidden['forbidden'] ?? false) === true, 'A missing permission was granted.');

    $revoked = insight_access_revoke_token((int)$createdToken['item']['id'], $user);
    insight_test_access_assert(($revoked['ok'] ?? false) === true, 'Token revocation failed.');
    insight_test_access_assert(insight_access_authenticate_bearer(['status:read']) === null, 'The revoked token still works.');

    $createdClient = insight_access_create_oauth_client([
        'name' => 'Dashboard de test',
        'redirect_uris' => ['http://127.0.0.1:9000/callback'],
        'scopes' => ['openid', 'profile', 'status:read'],
    ], $user);
    insight_test_access_assert(($createdClient['ok'] ?? false) === true, 'The OAuth client was not created.');
    $clientId = (string)$createdClient['client_id'];
    $clientSecret = (string)$createdClient['client_secret'];
    $storedSecret = insight_auth_db()->query('SELECT client_secret_hash FROM auth_oauth_clients LIMIT 1')->fetchColumn();
    insight_test_access_assert(password_verify($clientSecret, (string)$storedSecret), 'The OAuth secret is not correctly hashed.');

    insight_access_set_feature('oauth_provider_enabled', true);
    $verifier = insight_access_base64url_encode(random_bytes(64));
    $challenge = insight_access_base64url_encode(hash('sha256', $verifier, true));
    $request = insight_oauth_authorization_request([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => 'http://127.0.0.1:9000/callback',
        'scope' => 'openid profile status:read',
        'state' => insight_access_base64url_encode(random_bytes(20)),
        'nonce' => insight_access_base64url_encode(random_bytes(20)),
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);
    insight_test_access_assert(($request['ok'] ?? false) === true, 'A valid OAuth request was rejected.');
    $invalidRedirect = insight_oauth_authorization_request(array_merge($request, [
        'response_type' => 'code',
        'redirect_uri' => 'http://127.0.0.1:9000/other',
    ]));
    insight_test_access_assert(($invalidRedirect['ok'] ?? true) === false, 'An unregistered redirect URI was accepted.');

    $code = insight_oauth_create_code($request, $user);
    $_SERVER['PHP_AUTH_USER'] = $clientId;
    $_SERVER['PHP_AUTH_PW'] = $clientSecret;
    $_POST = [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => 'http://127.0.0.1:9000/callback',
        'code_verifier' => $verifier,
    ];
    $tokens = insight_oauth_exchange_code();
    insight_test_access_assert(str_starts_with((string)$tokens['access_token'], 'insight_oat_'), 'The OAuth token is missing.');
    insight_test_access_assert(substr_count((string)$tokens['id_token'], '.') === 2, 'L’ID Token n’est pas un JWT.');
    $usedAt = insight_auth_db()->query('SELECT used_at FROM auth_oauth_codes LIMIT 1')->fetchColumn();
    insight_test_access_assert((int)$usedAt > 0, 'The OAuth code was not consumed.');

    [$header, $payload, $signature] = explode('.', (string)$tokens['id_token']);
    $decodedSignature = insight_access_base64url_decode($signature);
    $jwk = insight_oauth_jwks()['keys'][0];
    insight_test_access_assert(
        is_string($decodedSignature)
        && openssl_verify($header . '.' . $payload, $decodedSignature, insight_oidc_jwk_public_key($jwk), OPENSSL_ALGO_SHA256) === 1,
        'The ID Token RS256 signature is invalid.'
    );
    $claimsJson = insight_access_base64url_decode($payload);
    $claims = is_string($claimsJson) ? json_decode($claimsJson, true) : null;
    insight_test_access_assert(is_array($claims) && ($claims['aud'] ?? '') === $clientId, 'The ID Token audience is invalid.');

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$tokens['access_token'];
    $oauthIdentity = insight_access_authenticate_bearer(['openid'], ['oauth']);
    insight_test_access_assert(($oauthIdentity['kind'] ?? '') === 'oauth', 'A valid OAuth token was rejected.');
    insight_test_access_assert((insight_oauth_userinfo($oauthIdentity)['sub'] ?? '') !== '', 'Le profil OpenID est incomplet.');

    $ssoConfig = [
        'issuer' => 'https://id.example.com',
        'username_claim' => 'preferred_username',
        'groups_claim' => 'groups',
        'allowed_emails' => [],
        'allowed_groups' => ['status-admins'],
        'admin_groups' => ['status-admins'],
        'allow_all' => false,
    ];
    $ssoIdentity = insight_oidc_identity([
        'sub' => 'user-123',
        'preferred_username' => 'alice',
        'email' => 'alice@example.com',
        'groups' => ['status-admins'],
    ], $ssoConfig);
    insight_test_access_assert(($ssoIdentity['source'] ?? '') === 'oidc', 'The authorized SSO identity was not created.');
    putenv('INSIGHT_SSO_ENABLED=1');
    putenv('INSIGHT_SSO_ISSUER_URL=https://id.example.com');
    insight_auth_open_session($ssoIdentity, false);
    insight_test_access_assert((insight_auth_current_user()['username'] ?? '') === 'alice', 'La session SSO n’est pas relue depuis SQLite.');

    insight_access_set_feature('oauth_provider_enabled', false);
    insight_test_access_assert(
        insight_access_authenticate_bearer(['openid'], ['oauth']) === null,
        'Disabling OIDC did not revoke active tokens.'
    );
    $deletedClient = insight_access_delete_oauth_client((int)$createdClient['item']['id'], $user);
    insight_test_access_assert(($deletedClient['ok'] ?? false) === true, 'The OAuth client was not deleted.');
    $oauthTokenCount = (int)insight_auth_db()->query('SELECT COUNT(*) FROM auth_oauth_access_tokens')->fetchColumn();
    insight_test_access_assert($oauthTokenCount === 0, 'Tokens for the deleted client still exist.');

    insight_auth_destroy_session();
    echo "API, OAuth, and SSO validated.\n";
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    insight_test_access_remove_directory($temporaryDirectory);
}
