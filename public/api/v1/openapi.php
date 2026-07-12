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
$responses = ['200' => ['description' => 'Réponse JSON Insight']];
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
        '/status.php' => ['get' => ['summary' => 'État global', 'x-insight-scope' => 'status:read', 'responses' => $responses]],
        '/monitors.php' => [
            'get' => ['summary' => 'Lister les moniteurs', 'x-insight-scope' => 'monitors:read', 'responses' => $responses],
            'post' => ['summary' => 'Créer un moniteur', 'x-insight-scope' => 'monitors:write', 'responses' => $responses],
            'patch' => ['summary' => 'Modifier un moniteur', 'x-insight-scope' => 'monitors:write', 'responses' => $responses],
            'delete' => ['summary' => 'Supprimer un moniteur', 'x-insight-scope' => 'monitors:write', 'responses' => $responses],
        ],
        '/incidents.php' => ['get' => ['summary' => 'Lister les incidents', 'x-insight-scope' => 'incidents:read', 'responses' => $responses]],
        '/notifications.php' => [
            'get' => ['summary' => 'Lister les alertes', 'x-insight-scope' => 'notifications:read', 'responses' => $responses],
            'post' => ['summary' => 'Créer ou tester un canal', 'x-insight-scope' => 'notifications:write', 'responses' => $responses],
            'patch' => ['summary' => 'Modifier un canal ou un message', 'x-insight-scope' => 'notifications:write', 'responses' => $responses],
            'delete' => ['summary' => 'Supprimer un canal', 'x-insight-scope' => 'notifications:write', 'responses' => $responses],
        ],
    ],
]);
