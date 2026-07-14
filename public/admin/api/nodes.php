<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/_python_engine.php';

function insight_nodes_api_response(array $payload, int $statusCode): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user = insight_auth_require_user();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST', 'PATCH'], true)) {
    header('Allow: GET, POST, PATCH');
    insight_nodes_api_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
if ($method === 'GET') {
    $result = insight_python_engine(['nodes', 'list']);
    insight_nodes_api_response($result, ($result['ok'] ?? false) ? 200 : (int)($result['status_code'] ?? 503));
}
if (!insight_auth_can($user, 'network:write')) {
    insight_nodes_api_response(['ok' => false, 'error' => 'admin.auth.errorForbidden'], 403);
}
if (!insight_auth_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    insight_nodes_api_response(['ok' => false, 'error' => 'admin.auth.errorCsrf'], 403);
}
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    insight_nodes_api_response(['ok' => false, 'error' => 'admin.network.errorPayload'], 400);
}
$nodeKey = trim((string)($input['node_key'] ?? ''));
if ($method === 'POST') {
    $result = insight_python_engine([
        'nodes',
        'provision',
        '--node-key',
        $nodeKey,
        '--display-name',
        trim((string)($input['display_name'] ?? '')),
        '--region',
        trim((string)($input['region'] ?? '')),
        '--zone',
        trim((string)($input['zone'] ?? '')),
    ]);
    if ($result['ok'] ?? false) {
        $hubUrl = rtrim((string)($insightAdminConfig['public_url'] ?? ''), '/');
        $result['agent_env'] = implode("\n", [
            'INSIGHT_HUB_URL=' . $hubUrl,
            'INSIGHT_AGENT_NODE_KEY=' . (string)$result['node_key'],
            'INSIGHT_AGENT_SECRET=' . (string)$result['secret'],
            'INSIGHT_AGENT_DISPLAY_NAME=' . (string)$result['display_name'],
            'INSIGHT_AGENT_REGION=' . (string)$result['region'],
            'INSIGHT_AGENT_ZONE=' . (string)$result['zone'],
        ]);
        $result['start_command'] = 'docker compose --env-file .env.agent -f docker-compose.agent.yml up -d --build';
    }
} else {
    $status = strtolower(trim((string)($input['status'] ?? '')));
    $action = match ($status) {
        'active' => 'activate',
        'paused' => 'pause',
        'revoked' => 'revoke',
        default => '',
    };
    $result = $action === ''
        ? ['ok' => false, 'status_code' => 422, 'message' => 'Invalid agent status.']
        : insight_python_engine(['nodes', $action, '--node-key', $nodeKey]);
}
if (($result['ok'] ?? false) && !insight_auth_dev_bypass_enabled()) {
    insight_auth_audit($method === 'POST' ? 'agent_provisioned' : 'agent_status_changed', insight_auth_local_user_id($user));
}
insight_nodes_api_response(
    ($result['ok'] ?? false) ? $result : ['ok' => false, 'error' => (string)($result['message'] ?? 'admin.network.errorGeneric')],
    ($result['ok'] ?? false) ? ($method === 'POST' ? 201 : 200) : (int)($result['status_code'] ?? 503)
);
