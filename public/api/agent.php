<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/monitoring/distributed.php';

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

function insight_agent_connect(): mysqli
{
    $config = require dirname(__DIR__) . '/config/config.php';
    mysqli_report(MYSQLI_REPORT_OFF);
    $connection = mysqli_init();
    if (!$connection instanceof mysqli) {
        throw new RuntimeException('Initialisation MariaDB impossible.');
    }
    $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $connected = @$connection->real_connect(
        (string)($config['servername'] ?? 'db'),
        (string)($config['username'] ?? ''),
        (string)($config['password'] ?? ''),
        (string)($config['dbname'] ?? ''),
        (int)($config['port'] ?? 3306)
    );
    if (!$connected) {
        throw new RuntimeException('Connexion MariaDB impossible.');
    }
    $connection->set_charset('utf8mb4');
    return $connection;
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
    insight_agent_response(405, ['status' => 'error', 'message' => 'Méthode non autorisée.']);
}
if (insight_dist_env_bool('INSIGHT_AGENT_REQUIRE_HTTPS') && !insight_agent_is_https()) {
    insight_agent_response(426, ['status' => 'error', 'message' => 'HTTPS est requis pour les agents.']);
}
$maximumBody = insight_dist_env_int('INSIGHT_AGENT_MAX_BODY_BYTES', 1048576, 16384, 10485760);
$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
if ($contentLength !== false && $contentLength !== null && $contentLength > $maximumBody) {
    insight_agent_response(413, ['status' => 'error', 'message' => 'Requête agent trop volumineuse.']);
}
$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > $maximumBody) {
    insight_agent_response(400, ['status' => 'error', 'message' => 'Corps de requête agent invalide.']);
}
$body = json_decode($rawBody, true);
if (!is_array($body)) {
    insight_agent_response(400, ['status' => 'error', 'message' => 'JSON agent invalide.']);
}
$action = strtolower(trim((string)($body['action'] ?? '')));
if (!in_array($action, ['heartbeat', 'config', 'ingest'], true)) {
    insight_agent_response(400, ['status' => 'error', 'message' => 'Action agent invalide.']);
}
$nodeKey = trim((string)($_SERVER['HTTP_X_INSIGHT_NODE'] ?? ''));
$timestamp = trim((string)($_SERVER['HTTP_X_INSIGHT_TIMESTAMP'] ?? ''));
$nonce = trim((string)($_SERVER['HTTP_X_INSIGHT_NONCE'] ?? ''));
$signature = trim((string)($_SERVER['HTTP_X_INSIGHT_SIGNATURE'] ?? ''));

$connection = null;
try {
    insight_dist_verify_signature($nodeKey, $timestamp, $nonce, $signature, $rawBody);
    $connection = insight_agent_connect();
    insight_dist_ensure_schema($connection);
    $node = insight_dist_register_node($connection, $nodeKey, $body);
    insight_dist_remember_nonce($connection, (int)$node['id'], $nonce);
    if ($action === 'heartbeat') {
        insight_agent_response(200, [
            'status' => 'success',
            'message' => 'Heartbeat reçu.',
            'node' => [
                'node_key' => (string)$node['node_key'],
                'status' => (string)$node['status'],
            ],
            'summary' => insight_dist_summary($connection),
        ]);
    }
    if ($action === 'config') {
        insight_agent_response(200, [
            'status' => 'success',
            'message' => 'Configuration distribuée générée.',
            'node' => [
                'node_key' => (string)$node['node_key'],
                'status' => (string)$node['status'],
            ],
            'config' => insight_dist_config_for_node($connection, $node),
        ]);
    }
    $result = insight_dist_ingest_batch($connection, $node, $body, hash('sha256', $rawBody));
    insight_agent_response(200, [
        'status' => 'success',
        'message' => 'Lot agent traité.',
        'result' => $result,
    ]);
} catch (InsightDistributedException $exception) {
    insight_agent_response($exception->statusCode, [
        'status' => 'error',
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    $errorId = bin2hex(random_bytes(6));
    error_log('Insight agent API [' . $errorId . ']: ' . $exception->getMessage());
    insight_agent_response(500, [
        'status' => 'error',
        'message' => 'Erreur interne de l’ingestion distribuée.',
        'error_id' => $errorId,
    ]);
} finally {
    if ($connection instanceof mysqli) {
        $connection->close();
    }
}
