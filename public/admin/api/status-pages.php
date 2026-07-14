<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/_status_pages.php';

function insight_status_pages_api_response(array $payload, int $statusCode): never
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
    insight_status_pages_api_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
if ($method === 'GET') {
    try {
        $result = insight_status_pages_list($insightAdminConfig);
    } catch (Throwable) {
        $result = ['ok' => false, 'status_code' => 503, 'error' => 'admin.statusPages.errorDatabase'];
    }
    insight_status_pages_api_response($result, (int)($result['status_code'] ?? 500));
}
if (!insight_auth_can($user, 'status_pages:write')) {
    insight_status_pages_api_response(['ok' => false, 'error' => 'admin.auth.errorForbidden'], 403);
}
if (!insight_auth_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    insight_status_pages_api_response(['ok' => false, 'error' => 'admin.auth.errorCsrf'], 403);
}
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    insight_status_pages_api_response(['ok' => false, 'error' => 'admin.statusPages.errorPayload'], 400);
}
$idValue = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
$id = $idValue === false ? 0 : (int)$idValue;
try {
    $result = match ($method) {
        'POST' => insight_status_pages_create($insightAdminConfig, $input),
        'PATCH' => insight_status_pages_update($insightAdminConfig, $id, $input),
        default => insight_status_pages_delete($insightAdminConfig, $id),
    };
} catch (Throwable) {
    $result = ['ok' => false, 'status_code' => 503, 'error' => 'admin.statusPages.errorDatabase'];
}
if (($result['ok'] ?? false) && !insight_auth_dev_bypass_enabled()) {
    insight_auth_audit('status_page_' . strtolower($method), insight_auth_local_user_id($user));
}
insight_status_pages_api_response($result, (int)($result['status_code'] ?? 500));
