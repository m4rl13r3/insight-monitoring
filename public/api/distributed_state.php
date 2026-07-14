<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/_python_engine.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = insight_python_engine(['distributed-summary']);
if (!($result['ok'] ?? false)) {
    http_response_code((int)($result['status_code'] ?? 503));
    echo json_encode([
        'ok' => false,
        'message' => 'Distributed monitoring is not initialized yet.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok' => true,
    'mode' => (string)($result['mode'] ?? 'standalone'),
    'data' => (array)($result['data'] ?? []),
    'updated_at' => gmdate('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
