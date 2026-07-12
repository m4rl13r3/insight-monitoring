<?php

declare(strict_types=1);

define('INSIGHT_AUTH_STATELESS', true);
require_once dirname(__DIR__, 2) . '/admin/_oauth.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    insight_oauth_error('invalid_request', 'Utilisez GET.', 405);
}
if (!insight_access_feature_enabled('oauth_provider_enabled')) {
    insight_oauth_error('invalid_request', 'Le fournisseur OpenID Connect est désactivé.', 404);
}
$identity = insight_access_authenticate_bearer(['openid'], ['oauth']);
if ($identity === null) {
    header('WWW-Authenticate: Bearer realm="Insight OpenID Connect"');
    insight_oauth_error('invalid_token', 'Jeton invalide ou expiré.', 401);
}
if (($identity['forbidden'] ?? false) === true) {
    insight_oauth_error('insufficient_scope', 'Le scope openid est requis.', 403);
}
insight_access_json_response(insight_oauth_userinfo($identity));
