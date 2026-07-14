<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/_notifications.php';

function insight_notifications_api_response(array $payload, int $statusCode): never
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
    insight_notifications_api_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

if ($method === 'GET') {
    $result = insight_notifications_state($insightAdminConfig);
    insight_notifications_api_response($result, (int)($result['status_code'] ?? 500));
}
if (!insight_auth_can($user, 'notifications:write')) {
    insight_notifications_api_response(['ok' => false, 'error' => 'admin.auth.errorForbidden'], 403);
}

if (!insight_auth_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    insight_notifications_api_response(['ok' => false, 'error' => 'admin.auth.errorCsrf'], 403);
}

$raw = file_get_contents('php://input');
$input = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($input)) {
    insight_notifications_api_response(['ok' => false, 'error' => 'admin.notifications.errorPayload'], 400);
}

$action = strtolower(trim((string)($input['action'] ?? 'channel')));
$channelId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
$id = $channelId === false ? 0 : (int)$channelId;

if ($method === 'POST' && $action === 'test') {
    $result = insight_notifications_test($insightAdminConfig, $id);
} elseif ($method === 'PATCH' && $action === 'template') {
    $result = insight_notifications_update_template($insightAdminConfig, $input);
} elseif ($method === 'PATCH') {
    $result = insight_notifications_update($insightAdminConfig, $id, $input);
} elseif ($method === 'DELETE') {
    $result = insight_notifications_delete($insightAdminConfig, $id);
} else {
    $result = insight_notifications_create($insightAdminConfig, $input);
}

if (($result['ok'] ?? false) && !insight_auth_dev_bypass_enabled()) {
    $auditEvent = match (true) {
        $action === 'test' => 'notification_tested',
        $action === 'template' => 'notification_template_updated',
        $method === 'PATCH' => 'notification_updated',
        $method === 'DELETE' => 'notification_deleted',
        default => 'notification_created',
    };
    insight_auth_audit($auditEvent, insight_auth_local_user_id($user));
}

insight_notifications_api_response($result, (int)($result['status_code'] ?? 500));
