<?php

declare(strict_types=1);

define('INSIGHT_AUTH_STATELESS', true);
require_once dirname(__DIR__, 2) . '/admin/_headless.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
    header('Allow: GET, POST, PATCH, DELETE, OPTIONS');
    insight_access_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

insight_access_require_api([$method === 'GET' ? 'monitors:read' : 'monitors:write']);

if ($method === 'GET') {
    $result = insight_headless_monitors($insightAdminConfig);
} else {
    $raw = file_get_contents('php://input');
    $input = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($input)) {
        insight_access_json_response(['ok' => false, 'error' => 'invalid_payload'], 400);
    }
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
    $result = match ($method) {
        'PATCH' => insight_probes_update($insightAdminConfig, $id === false ? 0 : (int)$id, $input),
        'DELETE' => insight_probes_delete($insightAdminConfig, $id === false ? 0 : (int)$id),
        default => insight_probes_create($insightAdminConfig, $input),
    };
}

$status = (int)($result['status_code'] ?? 200);
unset($result['status_code']);
insight_access_json_response($result, $status);
