<?php

declare(strict_types=1);

define('INSIGHT_AUTH_STATELESS', true);
require_once dirname(__DIR__, 2) . '/admin/_oauth.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    insight_oauth_error('invalid_request', 'Utilisez POST.', 405);
}

insight_access_json_response(insight_oauth_exchange_code());
