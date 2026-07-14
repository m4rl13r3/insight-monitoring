<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/_security.php';

function insight_security_response(array $payload, int $status): never
{
    unset($payload['status_code']);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = insight_auth_require_user();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $result = insight_security_state($user);
    insight_security_response($result, 200);
}
if ($method !== 'POST') {
    header('Allow: GET, POST');
    insight_security_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
if (!insight_auth_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    insight_security_response(['ok' => false, 'error' => 'admin.auth.errorCsrf'], 403);
}
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    insight_security_response(['ok' => false, 'error' => 'admin.security.errorPayload'], 400);
}
$action = strtolower(trim((string)($input['action'] ?? '')));
try {
    $result = match ($action) {
        'totp_begin' => insight_security_begin_totp($user),
        'totp_confirm' => insight_security_confirm_totp($user, (string)($input['code'] ?? '')),
        'totp_disable' => insight_security_disable_totp($user, (string)($input['code'] ?? '')),
        'recovery_regenerate' => insight_security_regenerate_recovery($user, (string)($input['code'] ?? '')),
        'password_change' => insight_security_change_password($user, $input),
        'user_create' => insight_security_create_user($input, $user),
        'user_update' => insight_security_update_user($input, $user),
        'user_delete' => insight_security_delete_user((int)($input['id'] ?? 0), $user),
        default => ['ok' => false, 'status_code' => 422, 'error' => 'admin.security.errorAction'],
    };
} catch (Throwable $exception) {
    $key = str_starts_with($exception->getMessage(), 'admin.') ? $exception->getMessage() : 'admin.security.errorGeneric';
    $result = ['ok' => false, 'status_code' => 500, 'error' => $key];
}
if (($result['ok'] ?? false) && !isset($result['users'])) {
    $state = insight_security_state($user);
    $result['state'] = $state;
}
insight_security_response($result, (int)($result['status_code'] ?? 500));
