<?php

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function hourly_bootstrap_context() {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL);
    mysqli_report(MYSQLI_REPORT_OFF);
    header('Content-Type: application/json; charset=utf-8');

    $rootDir = dirname(__DIR__, 2);
    $reportLogFile = $rootDir . '/logs/report.log';
    hourly_log_file($reportLogFile, "hourly_stats_report started");

    $allowedOrigins = [];
    $publicConfigPath = $rootDir . '/config/config.php';
    $publicConfig = is_file($publicConfigPath) ? include $publicConfigPath : [];
    $allowedOriginsRaw = is_array($publicConfig) ? trim((string)($publicConfig['allowed_origins'] ?? '')) : '';
    foreach (array_filter(array_map('trim', explode(',', $allowedOriginsRaw))) as $allowedOrigin) {
        $normalizedOrigin = hourly_normalize_origin($allowedOrigin);
        if ($normalizedOrigin !== '') {
            $allowedOrigins[] = $normalizedOrigin;
        }
    }

    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if (!empty($origin) && hourly_is_allowed_origin($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: " . $origin);
        header('Vary: Origin');
    }
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");

    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($requestMethod === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if ($requestMethod !== 'GET') {
        header('Allow: GET, OPTIONS');
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $apiVersion = '2026-03-03';
    $requestId = substr(hash('sha256', microtime(true) . '-' . mt_rand()), 0, 16);
    $contract = isset($_GET['contract']) ? strtolower(trim((string)$_GET['contract'])) : 'legacy';
    $useV2Contract = in_array($contract, ['v2', '2'], true);

    $ctx = [
        'rootDir' => $rootDir,
        'reportLogFile' => $reportLogFile,
        'apiVersion' => $apiVersion,
        'requestId' => $requestId,
        'contract' => $contract,
        'useV2Contract' => $useV2Contract
    ];
    hourly_log($ctx, "request_id={$requestId} contract={$contract}");

    $mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : 'stats';
    if (!in_array($mode, ['stats', 'incidents'], true)) {
        $mode = 'stats';
    }
    $responseFormat = isset($_GET['format']) ? strtolower(trim((string)$_GET['format'])) : 'json';
    if (!in_array($responseFormat, ['json', 'rss'], true)) {
        $responseFormat = 'json';
    }
    $includeDailyData = hourly_query_bool('include_daily', true);
    $includeIncidents = hourly_query_bool('include_incidents', true);
    $incidentsLimit = hourly_query_int('incidents_limit', 50, 1, 200);
    $incidentsOffset = hourly_query_int('incidents_offset', 0, 0, 1000000);
    $includeSitesFallback = isset($_GET['with_sites']) && $_GET['with_sites'] === '1';

    $requestedDate = null;
    if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])) {
        [$yy, $mm, $dd] = array_map('intval', explode('-', (string)$_GET['date']));
        if (checkdate($mm, $dd, $yy)) {
            $requestedDate = sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
        }
    }

    $requestedSiteUrls = isset($_GET['site_urls']) ? $_GET['site_urls'] : [];
    if (!is_array($requestedSiteUrls)) {
        $requestedSiteUrls = [];
    }
    $siteUrls = [];
    foreach ($requestedSiteUrls as $rawUrl) {
        $url = trim((string)$rawUrl);
        if ($url === '' || strlen($url) > 255) {
            continue;
        }
        $siteUrls[$url] = $url;
    }
    $siteUrls = array_values($siteUrls);

    $ctx['mode'] = $mode;
    $ctx['responseFormat'] = $responseFormat;
    $ctx['includeDailyData'] = $includeDailyData;
    $ctx['includeIncidents'] = $includeIncidents;
    $ctx['incidentsLimit'] = $incidentsLimit;
    $ctx['incidentsOffset'] = $incidentsOffset;
    $ctx['includeSitesFallback'] = $includeSitesFallback;
    $ctx['requestedDate'] = $requestedDate;
    $ctx['siteUrls'] = $siteUrls;

    $configPath = $rootDir . '/config/config.php';
    $config = null;
    if (
        is_array($publicConfig) &&
        !empty($publicConfig['servername']) &&
        !empty($publicConfig['username']) &&
        array_key_exists('password', $publicConfig) &&
        !empty($publicConfig['dbname'])
    ) {
        $config = $publicConfig;
    }

    if (!$config) {
        hourly_log($ctx, "No valid DB config found");
        hourly_send_api_response($ctx, $mode, [], 500, [
            'code' => 'config_missing',
            'message' => 'No valid DB config found.'
        ]);
        exit;
    }

    hourly_log($ctx, "Loaded DB config from: {$configPath}");
    $ctx['config'] = $config;
    $port = isset($config['port']) && is_numeric((string)$config['port']) ? (int)$config['port'] : 3306;
    $conn = @new mysqli($config['servername'], $config['username'], $config['password'], $config['dbname'], $port);
    if ($config['servername'] === 'localhost' && (!$conn || $conn->connect_errno)) {
        $conn = @new mysqli('127.0.0.1', $config['username'], $config['password'], $config['dbname'], $port);
    }

    if (!$conn || $conn->connect_errno) {
        $err = $conn ? $conn->connect_error : 'mysqli_init_failed';
        hourly_log($ctx, "DB connection failed: {$err}");
        hourly_send_api_response($ctx, $mode, [], 500, [
            'code' => 'db_connection_exception',
            'message' => 'Database connection failed.'
        ]);
        exit;
    }

    $conn->set_charset("utf8");
    hourly_log($ctx, "Connected to database");
    $ctx['conn'] = $conn;

    return $ctx;
}
