<?php

declare(strict_types=1);

define('INSIGHT_AUTH_STATELESS', true);
require_once dirname(__DIR__, 2) . '/admin/_headless.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'OPTIONS'], true)) {
    header('Allow: GET, OPTIONS');
    insight_access_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

insight_access_require_api(['incidents:read']);
$limit = filter_var($_GET['limit'] ?? 100, FILTER_VALIDATE_INT);
$result = insight_headless_incidents($insightAdminConfig, $limit === false ? 100 : (int)$limit);
$status = (int)($result['status_code'] ?? 200);
unset($result['status_code']);
insight_access_json_response($result, $status);
