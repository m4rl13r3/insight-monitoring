<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/_incidents.php';
require_once dirname(__DIR__) . '/_notifications.php';

function insight_incidents_api_response(array $payload, int $statusCode): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = insight_auth_require_user();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['POST', 'PATCH', 'DELETE'], true)) {
    header('Allow: POST, PATCH, DELETE');
    insight_incidents_api_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
if (!insight_auth_can($user, 'incidents:write')) {
    insight_incidents_api_response(['ok' => false, 'error' => 'admin.auth.errorForbidden'], 403);
}
if (!insight_auth_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    insight_incidents_api_response(['ok' => false, 'error' => 'admin.auth.errorCsrf'], 403);
}
$raw = file_get_contents('php://input');
$input = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($input)) {
    insight_incidents_api_response(['ok' => false, 'error' => 'admin.incidents.errorPayload'], 400);
}
$idValue = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
$incidentId = $idValue === false ? 0 : (int)$idValue;
$resource = strtolower(trim((string)($input['resource'] ?? 'incident')));
try {
    if ($resource === 'runbook') {
        $result = insight_incidents_manage_runbook($insightAdminConfig, $method, $incidentId, $input);
    } else {
        $result = match ($method) {
            'POST' => strtolower((string)($input['action'] ?? '')) === 'comment'
                ? insight_incidents_manage($insightAdminConfig, $incidentId, $input, $user)
                : insight_incidents_create($insightAdminConfig, $input, $user),
            'DELETE' => insight_incidents_delete($insightAdminConfig, $incidentId),
            default => insight_incidents_manage($insightAdminConfig, $incidentId, $input, $user),
        };
    }
} catch (Throwable) {
    $result = ['ok' => false, 'status_code' => 503, 'error' => 'admin.incidents.errorDatabase'];
}
if ($result['ok'] ?? false) {
    $resolvedId = (int)($result['incident']['id'] ?? $incidentId);
    if ($resource === 'incident') {
        insight_incidents_dispatch_notification($insightAdminConfig, $method, $input, $result, $resolvedId);
    }
    if (!insight_auth_dev_bypass_enabled()) {
        insight_auth_audit('incident_' . strtolower($method) . '_' . strtolower((string)($input['action'] ?? 'create')), insight_auth_local_user_id($user));
    }
}
insight_incidents_api_response($result, (int)($result['status_code'] ?? 500));
