<?php

declare(strict_types=1);

define('INSIGHT_AUTH_STATELESS', true);
require_once dirname(__DIR__, 2) . '/admin/_headless.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
    header('Allow: GET, POST, PATCH, DELETE, OPTIONS');
    insight_access_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
$identity = insight_access_require_api([$method === 'GET' ? 'incidents:read' : 'incidents:write']);
if ($method === 'GET') {
    $limit = filter_var($_GET['limit'] ?? 100, FILTER_VALIDATE_INT);
    $result = insight_headless_incidents($insightAdminConfig, $limit === false ? 100 : (int)$limit);
} else {
    $raw = file_get_contents('php://input');
    $input = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($input)) {
        insight_access_json_response(['ok' => false, 'error' => 'invalid_payload'], 400);
    }
    $idValue = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $id = $idValue === false ? 0 : (int)$idValue;
    $apiUser = ['id' => 0, 'username' => (string)($identity['username'] ?? $identity['name'] ?? 'API'), 'source' => 'api'];
    try {
        $result = match ($method) {
            'POST' => insight_incidents_create($insightAdminConfig, $input, $apiUser),
            'DELETE' => insight_incidents_delete($insightAdminConfig, $id),
            default => insight_incidents_manage($insightAdminConfig, $id, $input, $apiUser),
        };
    } catch (Throwable) {
        $result = ['ok' => false, 'status_code' => 503, 'error' => 'database_unavailable'];
    }
    if ($result['ok'] ?? false) {
        insight_incidents_dispatch_notification($insightAdminConfig, $method, $input, $result, $id);
    }
}
$status = (int)($result['status_code'] ?? 200);
unset($result['status_code']);
insight_access_json_response($result, $status);
