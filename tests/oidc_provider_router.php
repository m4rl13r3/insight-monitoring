<?php

declare(strict_types=1);

$configPath = (string)getenv('INSIGHT_OIDC_TEST_CONFIG');
$config = json_decode((string)file_get_contents($configPath), true);
if (!is_array($config)) {
    http_response_code(500);
    exit;
}

$path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($path === '/.well-known/openid-configuration') {
    echo json_encode([
        'issuer' => $config['issuer'],
        'authorization_endpoint' => $config['issuer'] . '/authorize',
        'token_endpoint' => $config['issuer'] . '/token',
        'jwks_uri' => $config['issuer'] . '/jwks',
        'id_token_signing_alg_values_supported' => ['RS256'],
        'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($path === '/jwks') {
    echo json_encode($config['jwks'], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($path === '/token' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $valid = hash_equals((string)$config['client_id'], (string)($_SERVER['PHP_AUTH_USER'] ?? ''))
        && hash_equals((string)$config['client_secret'], (string)($_SERVER['PHP_AUTH_PW'] ?? ''))
        && hash_equals((string)$config['code'], (string)($_POST['code'] ?? ''))
        && hash_equals((string)$config['verifier'], (string)($_POST['code_verifier'] ?? ''));
    if (!$valid) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_client']);
        exit;
    }
    echo json_encode([
        'token_type' => 'Bearer',
        'access_token' => 'external-access-token',
        'id_token' => $config['id_token'],
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not_found']);
