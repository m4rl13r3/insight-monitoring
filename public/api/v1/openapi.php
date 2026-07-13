<?php

declare(strict_types=1);

define('INSIGHT_AUTH_STATELESS', true);
require_once dirname(__DIR__, 2) . '/admin/_access.php';

insight_access_apply_cors();
if (!insight_access_feature_enabled('headless_api_enabled')) {
    insight_access_json_response(['ok' => false, 'error' => 'api_disabled'], 404);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET, OPTIONS');
    insight_access_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$base = insight_access_public_url() . '/api/v1';
$responses = ['200' => ['description' => 'Insight JSON response']];
insight_access_json_response([
    'openapi' => '3.1.0',
    'info' => ['title' => 'Insight Headless API', 'version' => '1.0.0'],
    'servers' => [['url' => $base]],
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
        ],
    ],
    'security' => [['bearerAuth' => []]],
    'paths' => [
        '/status.php' => ['get' => ['summary' => 'Global status', 'x-insight-scope' => 'status:read', 'responses' => $responses]],
        '/monitors.php' => [
            'get' => ['summary' => 'List monitors', 'x-insight-scope' => 'monitors:read', 'responses' => $responses],
            'post' => ['summary' => 'Create a monitor', 'x-insight-scope' => 'monitors:write', 'responses' => $responses],
            'patch' => ['summary' => 'Edit a monitor', 'x-insight-scope' => 'monitors:write', 'responses' => $responses],
            'delete' => ['summary' => 'Delete a monitor', 'x-insight-scope' => 'monitors:write', 'responses' => $responses],
        ],
        '/incidents.php' => ['get' => ['summary' => 'List incidents', 'x-insight-scope' => 'incidents:read', 'responses' => $responses]],
        '/notifications.php' => [
            'get' => ['summary' => 'List alerts', 'x-insight-scope' => 'notifications:read', 'responses' => $responses],
            'post' => ['summary' => 'Create or test a channel', 'x-insight-scope' => 'notifications:write', 'responses' => $responses],
            'patch' => ['summary' => 'Edit a channel or message', 'x-insight-scope' => 'notifications:write', 'responses' => $responses],
            'delete' => ['summary' => 'Delete a channel', 'x-insight-scope' => 'notifications:write', 'responses' => $responses],
        ],
    ],
]);
