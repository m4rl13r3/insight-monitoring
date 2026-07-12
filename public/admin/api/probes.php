<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/_probes.php';

function insight_probes_api_response(array $payload, int $statusCode): never
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
    insight_probes_api_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

if (!insight_auth_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    insight_probes_api_response(['ok' => false, 'error' => 'admin.auth.errorCsrf'], 403);
}

$raw = file_get_contents('php://input');
$input = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($input)) {
    insight_probes_api_response(['ok' => false, 'error' => 'admin.probes.errorPayload'], 400);
}

$probeId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
$result = match ($method) {
    'PATCH' => insight_probes_update($insightAdminConfig, $probeId === false ? 0 : (int)$probeId, $input),
    'DELETE' => insight_probes_delete($insightAdminConfig, $probeId === false ? 0 : (int)$probeId),
    default => insight_probes_create($insightAdminConfig, $input),
};
if (($result['ok'] ?? false) && !insight_auth_dev_bypass_enabled()) {
    $auditEvent = match ($method) {
        'PATCH' => 'probe_updated',
        'DELETE' => 'probe_deleted',
        default => 'probe_created',
    };
    insight_auth_audit($auditEvent, insight_auth_local_user_id($user));
}
insight_probes_api_response($result, (int)($result['status_code'] ?? 500));
