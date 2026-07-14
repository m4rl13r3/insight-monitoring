<?php

declare(strict_types=1);

define('INSIGHT_AUTH_STATELESS', true);
require_once dirname(__DIR__, 2) . '/admin/_access.php';
require_once dirname(__DIR__, 2) . '/admin/_status_pages.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
    header('Allow: GET, POST, PATCH, DELETE, OPTIONS');
    insight_access_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

insight_access_require_api([$method === 'GET' ? 'status-pages:read' : 'status-pages:write']);

if ($method === 'GET') {
    try {
        $result = insight_status_pages_list($insightAdminConfig);
    } catch (Throwable) {
        $result = ['ok' => false, 'status_code' => 503, 'error' => 'database_unavailable'];
    }
} else {
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        insight_access_json_response(['ok' => false, 'error' => 'invalid_payload'], 400);
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
        $result = ['ok' => false, 'status_code' => 503, 'error' => 'database_unavailable'];
    }
}

$status = (int)($result['status_code'] ?? 200);
unset($result['status_code']);
insight_access_json_response($result, $status);
