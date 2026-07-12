<?php

declare(strict_types=1);

define('INSIGHT_AUTH_STATELESS', true);
require_once dirname(__DIR__, 2) . '/admin/_oauth.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    insight_access_json_response(['error' => 'method_not_allowed'], 405);
}
if (!insight_access_feature_enabled('oauth_provider_enabled')) {
    insight_access_json_response(['error' => 'provider_disabled'], 404);
}
header('Cache-Control: public, max-age=3600');
insight_access_json_response(insight_oauth_jwks());
