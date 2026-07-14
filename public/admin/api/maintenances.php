<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/_maintenances.php';

function insight_maintenances_api_response(array $payload, int $statusCode): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = insight_auth_require_user();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
    header('Allow: GET, POST, PATCH, DELETE');
    insight_maintenances_api_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
if ($method === 'GET') {
    try {
        $result = insight_maintenances_list($insightAdminConfig);
    } catch (Throwable) {
        $result = ['ok' => false, 'status_code' => 503, 'error' => 'admin.maintenance.errorDatabase'];
    }
    insight_maintenances_api_response($result, (int)($result['status_code'] ?? 500));
}
if (!insight_auth_can($user, 'maintenance:write')) {
    insight_maintenances_api_response(['ok' => false, 'error' => 'admin.auth.errorForbidden'], 403);
}
if (!insight_auth_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    insight_maintenances_api_response(['ok' => false, 'error' => 'admin.auth.errorCsrf'], 403);
}
$raw = file_get_contents('php://input');
$input = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($input)) {
    insight_maintenances_api_response(['ok' => false, 'error' => 'admin.maintenance.errorPayload'], 400);
}
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
try {
    $result = match ($method) {
        'PATCH' => insight_maintenances_update($insightAdminConfig, $id === false ? 0 : (int)$id, $input),
        'DELETE' => insight_maintenances_delete($insightAdminConfig, $id === false ? 0 : (int)$id),
        default => insight_maintenances_create($insightAdminConfig, $input, $user),
    };
} catch (Throwable) {
    $result = ['ok' => false, 'status_code' => 503, 'error' => 'admin.maintenance.errorDatabase'];
}
if (($result['ok'] ?? false) && !insight_auth_dev_bypass_enabled()) {
    insight_auth_audit('maintenance_' . strtolower($method), insight_auth_local_user_id($user));
}
insight_maintenances_api_response($result, (int)($result['status_code'] ?? 500));
