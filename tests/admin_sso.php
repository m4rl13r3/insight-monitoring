<?php

declare(strict_types=1);

function insight_test_sso_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function insight_test_sso_remove_directory(string $directory): void
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

$temporaryDirectory = sys_get_temp_dir() . '/insight-sso-' . bin2hex(random_bytes(8));
putenv('INSIGHT_AUTH_DB_PATH=' . $temporaryDirectory . '/auth.sqlite');
putenv('INSIGHT_APP_ENV=test-suite');
putenv('INSIGHT_DEV_AUTH_BYPASS=0');
putenv('INSIGHT_PUBLIC_URL=http://127.0.0.1:8787');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require dirname(__DIR__) . '/public/admin/_oidc.php';

$socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
if (!is_resource($socket)) {
    fwrite(STDERR, "Impossible de réserver un port pour le test SSO.\n");
    exit(1);
}
$address = (string)stream_socket_get_name($socket, false);
$port = (int)substr(strrchr($address, ':'), 1);
fclose($socket);

$issuer = 'http://127.0.0.1:' . $port;
$clientId = 'insight-sso-test';
$clientSecret = insight_access_base64url_encode(random_bytes(32));
$state = insight_access_base64url_encode(random_bytes(24));
$nonce = insight_access_base64url_encode(random_bytes(24));
$verifier = insight_access_base64url_encode(random_bytes(64));
$code = insight_access_base64url_encode(random_bytes(24));
$now = time();
$idToken = insight_oauth_sign_jwt([
    'iss' => $issuer,
    'sub' => 'external-user-42',
    'aud' => $clientId,
    'iat' => $now,
    'exp' => $now + 300,
    'nonce' => $nonce,
    'preferred_username' => 'sso-admin',
    'email' => 'sso-admin@example.com',
    'email_verified' => true,
    'groups' => ['status-admins'],
]);
$providerConfig = [
    'issuer' => $issuer,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'code' => $code,
    'verifier' => $verifier,
    'id_token' => $idToken,
    'jwks' => insight_oauth_jwks(),
];
$configPath = $temporaryDirectory . '/provider.json';
if (!is_dir($temporaryDirectory)) {
    mkdir($temporaryDirectory, 0700, true);
}
file_put_contents($configPath, json_encode($providerConfig, JSON_UNESCAPED_SLASHES), LOCK_EX);

$router = __DIR__ . '/oidc_provider_router.php';
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $temporaryDirectory . '/provider.log', 'a'],
    2 => ['file', $temporaryDirectory . '/provider.log', 'a'],
];
$environment = getenv();
if (!is_array($environment)) {
    $environment = [];
}
$environment['INSIGHT_OIDC_TEST_CONFIG'] = $configPath;
$process = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], $descriptors, $pipes, __DIR__, $environment);
if (!is_resource($process)) {
    fwrite(STDERR, "Le fournisseur OIDC de test n’a pas démarré.\n");
    exit(1);
}
fclose($pipes[0]);

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $errorNumber, $errorMessage, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(20000);
    }
    insight_test_sso_assert($ready, 'Le fournisseur OIDC de test ne répond pas.');

    putenv('INSIGHT_SSO_ENABLED=1');
    putenv('INSIGHT_SSO_PROVIDER_NAME=Fournisseur de test');
    putenv('INSIGHT_SSO_ISSUER_URL=' . $issuer);
    putenv('INSIGHT_SSO_CLIENT_ID=' . $clientId);
    putenv('INSIGHT_SSO_CLIENT_SECRET=' . $clientSecret);
    putenv('INSIGHT_SSO_ALLOWED_GROUPS=status-admins');
    putenv('INSIGHT_SSO_ADMIN_GROUPS=status-admins');

    $config = insight_oidc_config();
    insight_test_sso_assert(($config['valid'] ?? false) === true, 'La configuration SSO valide a été refusée.');
    $discovery = insight_oidc_discovery($config);
    insight_test_sso_assert(($discovery['issuer'] ?? '') === $issuer, 'La découverte OIDC est invalide.');

    $_SESSION['oidc_login'] = [
        'state' => $state,
        'nonce' => $nonce,
        'verifier' => $verifier,
        'next' => '/admin/#account',
        'created_at' => time(),
    ];
    $result = insight_oidc_callback(['state' => $state, 'code' => $code]);
    insight_test_sso_assert(($result['next'] ?? '') === '/admin/#account', 'La destination SSO a été perdue.');
    $user = insight_auth_current_user();
    insight_test_sso_assert(
        ($user['username'] ?? '') === 'sso-admin' && ($user['source'] ?? '') === 'oidc',
        'La session SSO complète n’a pas été ouverte.'
    );

    $denied = false;
    try {
        insight_oidc_identity([
            'sub' => 'external-user-denied',
            'preferred_username' => 'denied',
            'groups' => ['other-group'],
        ], $config);
    } catch (RuntimeException $exception) {
        $denied = $exception->getMessage() === 'oidc_access_denied';
    }
    insight_test_sso_assert($denied, 'Une identité hors politique a été acceptée.');

    putenv('INSIGHT_SSO_ENABLED=0');
    insight_test_sso_assert(insight_auth_current_user() === null, 'La désactivation SSO n’a pas fermé la session fédérée.');

    echo "Connexion SSO OIDC validée.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    insight_test_sso_remove_directory($temporaryDirectory);
}
