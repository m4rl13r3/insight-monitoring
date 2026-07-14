<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/_python_engine.php';

function insight_agent_response(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode(
        array_merge([
            'api_version' => 'insight-agent.v1',
            'server_time' => gmdate('c'),
            'server_time_ms' => (int)round(microtime(true) * 1000),
        ], $payload),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function insight_agent_env_bool(string $name, bool $default = false): bool
{
    $raw = getenv($name);
    $value = strtolower(trim($raw === false || trim((string)$raw) === '' ? ($default ? '1' : '0') : (string)$raw));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function insight_agent_env_int(string $name, int $default, int $minimum, int $maximum): int
{
    $value = filter_var(getenv($name), FILTER_VALIDATE_INT);
    return $value === false ? $default : max($minimum, min($maximum, (int)$value));
}

function insight_agent_is_https(): bool
{
    $https = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
    $forwarded = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return ($https !== '' && $https !== 'off') || $forwarded === 'https';
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'POST') {
    header('Allow: POST');
    insight_agent_response(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}
if (insight_agent_env_bool('INSIGHT_AGENT_REQUIRE_HTTPS', true) && !insight_agent_is_https()) {
    insight_agent_response(426, ['status' => 'error', 'message' => 'HTTPS is required for agents.']);
}
$maximumBody = insight_agent_env_int('INSIGHT_AGENT_MAX_BODY_BYTES', 1048576, 16384, 10485760);
$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
if ($contentLength !== false && $contentLength !== null && $contentLength > $maximumBody) {
    insight_agent_response(413, ['status' => 'error', 'message' => 'Agent request is too large.']);
}
$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > $maximumBody) {
    insight_agent_response(400, ['status' => 'error', 'message' => 'Invalid agent request body.']);
}
$result = insight_python_engine([
    'agent-request',
    '--node-key',
    trim((string)($_SERVER['HTTP_X_INSIGHT_NODE'] ?? '')),
    '--timestamp',
    trim((string)($_SERVER['HTTP_X_INSIGHT_TIMESTAMP'] ?? '')),
    '--nonce',
    trim((string)($_SERVER['HTTP_X_INSIGHT_NONCE'] ?? '')),
    '--signature',
    trim((string)($_SERVER['HTTP_X_INSIGHT_SIGNATURE'] ?? '')),
    '--remote-address',
    trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')),
], $rawBody, 60);
if (!($result['ok'] ?? false)) {
    insight_agent_response((int)($result['status_code'] ?? 500), [
        'status' => 'error',
        'message' => (string)($result['message'] ?? 'Internal distributed ingestion error.'),
    ]);
}
insight_agent_response((int)($result['status_code'] ?? 200), (array)($result['payload'] ?? []));
