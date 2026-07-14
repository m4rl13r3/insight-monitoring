<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/_oncall.php';

function insight_oncall_api_response(array $payload, int $statusCode): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = insight_auth_require_user();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    $result = insight_oncall_state($insightAdminConfig);
    insight_oncall_api_response($result, (int)($result['status_code'] ?? 500));
}
if (!in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
    header('Allow: GET, POST, PATCH, DELETE');
    insight_oncall_api_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
if (!insight_auth_can($user, 'notifications:write')) {
    insight_oncall_api_response(['ok' => false, 'error' => 'admin.auth.errorForbidden'], 403);
}
if (!insight_auth_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    insight_oncall_api_response(['ok' => false, 'error' => 'admin.auth.errorCsrf'], 403);
}
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    insight_oncall_api_response(['ok' => false, 'error' => 'admin.oncall.errorPayload'], 400);
}
$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
$scheduleId = $id === false ? 0 : (int)$id;
$result = $method === 'DELETE'
    ? insight_oncall_delete($insightAdminConfig, $scheduleId)
    : insight_oncall_save($insightAdminConfig, $method === 'PATCH' ? $scheduleId : 0, $input);
if (($result['ok'] ?? false) && !insight_auth_dev_bypass_enabled()) {
    insight_auth_audit($method === 'DELETE' ? 'oncall_schedule_deleted' : ($method === 'PATCH' ? 'oncall_schedule_updated' : 'oncall_schedule_created'), insight_auth_local_user_id($user));
}
insight_oncall_api_response($result, (int)($result['status_code'] ?? 500));
