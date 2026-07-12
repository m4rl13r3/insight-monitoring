<?php

declare(strict_types=1);

define('INSIGHT_AUTH_STATELESS', true);
require_once dirname(__DIR__, 2) . '/admin/_access.php';
require_once dirname(__DIR__, 2) . '/admin/_notifications.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
    header('Allow: GET, POST, PATCH, DELETE, OPTIONS');
    insight_access_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

insight_access_require_api([$method === 'GET' ? 'notifications:read' : 'notifications:write']);

if ($method === 'GET') {
    $result = insight_notifications_state($insightAdminConfig);
} else {
    $raw = file_get_contents('php://input');
    $input = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($input)) {
        insight_access_json_response(['ok' => false, 'error' => 'invalid_payload'], 400);
    }
    $action = strtolower(trim((string)($input['action'] ?? 'channel')));
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $channelId = $id === false ? 0 : (int)$id;
    $result = match (true) {
        $method === 'POST' && $action === 'test' => insight_notifications_test($insightAdminConfig, $channelId),
        $method === 'PATCH' && $action === 'template' => insight_notifications_update_template($insightAdminConfig, $input),
        $method === 'PATCH' => insight_notifications_update($insightAdminConfig, $channelId, $input),
        $method === 'DELETE' => insight_notifications_delete($insightAdminConfig, $channelId),
        default => insight_notifications_create($insightAdminConfig, $input),
    };
}

$status = (int)($result['status_code'] ?? 200);
unset($result['status_code']);
insight_access_json_response($result, $status);
