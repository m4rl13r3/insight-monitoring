<?php

declare(strict_types=1);

define('INSIGHT_AUTH_STATELESS', true);
require_once dirname(__DIR__, 2) . '/admin/_access.php';
require_once dirname(__DIR__, 2) . '/admin/_oncall.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
    header('Allow: GET, POST, PATCH, DELETE, OPTIONS');
    insight_access_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

insight_access_require_api([$method === 'GET' ? 'notifications:read' : 'notifications:write']);

if ($method === 'GET') {
    $result = insight_oncall_state($insightAdminConfig);
} else {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        insight_access_json_response(['ok' => false, 'error' => 'invalid_payload'], 400);
    }
    $idValue = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $id = $idValue === false ? 0 : (int)$idValue;
    $result = $method === 'DELETE'
        ? insight_oncall_delete($insightAdminConfig, $id)
        : insight_oncall_save($insightAdminConfig, $method === 'PATCH' ? $id : 0, $input);
}

$status = (int)($result['status_code'] ?? 200);
unset($result['status_code']);
insight_access_json_response($result, $status);
