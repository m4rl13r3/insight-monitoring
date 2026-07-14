<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/_oidc.php';

function insight_access_api_state(): array
{
    $state = insight_access_state();
    $state['sso'] = insight_oidc_public_state();
    return $state;
}

$user = insight_auth_require_user();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!insight_auth_can($user, 'access:write')) {
    insight_access_json_response(['ok' => false, 'error' => 'admin.auth.errorForbidden'], 403);
}

if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    header('Allow: GET, POST, DELETE');
    insight_access_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

if ($method === 'GET') {
    insight_access_json_response(insight_access_api_state());
}

if (!insight_auth_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    insight_access_json_response(['ok' => false, 'error' => 'admin.auth.errorCsrf'], 403);
}

$raw = file_get_contents('php://input');
$input = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($input)) {
    insight_access_json_response(['ok' => false, 'error' => 'admin.access.errorPayload'], 400);
}

$action = strtolower(trim((string)($input['action'] ?? '')));
try {
    $result = match ($action) {
        'set_api_enabled' => (function () use ($input, $user): array {
            insight_access_set_feature('headless_api_enabled', (bool)($input['enabled'] ?? false));
            insight_auth_audit('headless_api_toggled', insight_access_local_owner_id($user));
            return ['ok' => true, 'status_code' => 200];
        })(),
        'set_oauth_provider_enabled' => (function () use ($input, $user): array {
            insight_access_set_feature('oauth_provider_enabled', (bool)($input['enabled'] ?? false));
            insight_auth_audit('oauth_provider_toggled', insight_access_local_owner_id($user));
            return ['ok' => true, 'status_code' => 200];
        })(),
        'create_token' => insight_access_create_token($input, $user),
        'revoke_token' => insight_access_revoke_token((int)($input['id'] ?? 0), $user),
        'create_oauth_client' => insight_access_create_oauth_client($input, $user),
        'delete_oauth_client' => insight_access_delete_oauth_client((int)($input['id'] ?? 0), $user),
        default => ['ok' => false, 'status_code' => 422, 'error' => 'admin.access.errorAction'],
    };
} catch (Throwable $exception) {
    $known = $exception->getMessage();
    $error = str_starts_with($known, 'admin.access.') ? $known : 'admin.access.errorGeneric';
    $result = ['ok' => false, 'status_code' => $error === 'admin.access.errorGeneric' ? 500 : 422, 'error' => $error];
}

$status = (int)($result['status_code'] ?? 500);
unset($result['status_code']);
if (($result['ok'] ?? false) === true && !isset($result['state'])) {
    $result['state'] = insight_access_api_state();
}
insight_access_json_response($result, $status);
