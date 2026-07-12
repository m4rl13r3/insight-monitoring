<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require __DIR__ . '/python_bridge.php';
require __DIR__ . '/php_fallback.php';

$publicStateLib = __DIR__ . '/public_runtime_state.php';
if (is_file($publicStateLib)) {
    require $publicStateLib;
} else {
    @error_log('[' . date('Y-m-d H:i:s') . "] public_runtime_state.php introuvable\n", 3, __DIR__ . '/logs/public_runtime_state.log');
    if (!function_exists('public_state_write_monitor')) {
        function public_state_write_monitor(array $payload): bool
        {
            return false;
        }
    }
}

$forceFallback = getenv('INSIGHT_FORCE_PHP_FALLBACK');
if ($forceFallback === false || trim((string)$forceFallback) === '') {
    $forceFallback = getenv('MONITORING_FORCE_PHP_FALLBACK');
}
$shouldForceFallback = !empty($forceFallback) && !in_array(strtolower((string)$forceFallback), ['0', 'false', 'no', 'off'], true);
$disableFallback = getenv('INSIGHT_DISABLE_PHP_FALLBACK');
if ($disableFallback === false || trim((string)$disableFallback) === '') {
    $disableFallback = getenv('MONITORING_DISABLE_PHP_FALLBACK');
}
$shouldDisableFallback = !empty($disableFallback) && !in_array(strtolower((string)$disableFallback), ['0', 'false', 'no', 'off'], true);

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
}

$result = ['ok' => false, 'status_code' => 503, 'message' => 'Python bypassed (forced PHP fallback).'];
$ok = false;
if (!$shouldForceFallback) {
    $result = run_monitoring_python(['monitor']);
    $ok = !empty($result['ok']);
}

if (!$ok) {
    if (!$shouldDisableFallback) {
        $fallback = run_monitoring_php_fallback();
        if (!empty($fallback['ok'])) {
            $response = [
                'ok' => true,
                'degraded' => true,
                'engine' => 'php_fallback',
                'python_error' => $result['message'] ?? 'Erreur monitoring worker Python.',
                'sites_checked' => (int)($fallback['sites_checked'] ?? 0),
                'errors' => (int)($fallback['errors'] ?? 0),
                'incidents_opened' => (int)($fallback['incidents_opened'] ?? 0),
                'incidents_closed' => (int)($fallback['incidents_closed'] ?? 0),
            ];
            public_state_write_monitor([
                'service_name' => 'insight',
                'is_degraded' => 1,
                'active_engine' => 'php',
                'monitor_last_ok' => 1,
                'monitor_last_message' => 'Mode degrade actif (fallback PHP).',
                'monitor_python_error' => $response['python_error'],
                'monitor_fallback_message' => null,
                'monitor_checked_by' => 'php',
                'sites_checked' => (int)$response['sites_checked'],
                'errors_count' => (int)$response['errors'],
                'incidents_opened' => (int)$response['incidents_opened'],
                'incidents_closed' => (int)$response['incidents_closed'],
                'hourly_engine' => 'php',
                'daily_engine' => 'php',
            ]);
            echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (PHP_SAPI === 'cli') {
                echo PHP_EOL;
                exit(0);
            }
            return;
        }
    }

    if (PHP_SAPI !== 'cli') {
        http_response_code((int)($result['status_code'] ?? 500));
    }
    $response = [
        'ok' => false,
        'message' => $result['message'] ?? 'Erreur monitoring worker.',
        'raw' => $result['raw'] ?? null,
        'fallback_message' => $shouldDisableFallback ? 'Fallback PHP désactivé.' : ($fallback['message'] ?? 'Fallback PHP indisponible.'),
    ];
    public_state_write_monitor([
        'service_name' => 'insight',
        'is_degraded' => 1,
        'active_engine' => 'unknown',
        'monitor_last_ok' => 0,
        'monitor_last_message' => $shouldDisableFallback ? 'Echec monitor (Python, fallback désactivé).' : 'Echec monitor (Python + fallback).',
        'monitor_python_error' => $response['message'],
        'monitor_fallback_message' => $response['fallback_message'],
        'monitor_checked_by' => 'unknown',
    ]);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (PHP_SAPI === 'cli') {
        echo PHP_EOL;
        exit(1);
    }
    return;
}

public_state_write_monitor([
    'service_name' => 'insight',
    'is_degraded' => 0,
    'active_engine' => 'pyt',
    'monitor_last_ok' => 1,
    'monitor_last_message' => 'Monitor Python OK.',
    'monitor_python_error' => null,
    'monitor_fallback_message' => null,
    'monitor_checked_by' => 'pyt',
    'sites_checked' => (int)($result['sites_checked'] ?? 0),
    'errors_count' => (int)($result['errors'] ?? 0),
    'incidents_opened' => (int)($result['incidents_opened'] ?? 0),
    'incidents_closed' => (int)($result['incidents_closed'] ?? 0),
    'hourly_engine' => 'pyt',
    'daily_engine' => 'pyt',
]);

echo json_encode([
    'ok' => true,
    'degraded' => false,
    'engine' => 'pyt',
    'sites_checked' => (int)($result['sites_checked'] ?? 0),
    'errors' => (int)($result['errors'] ?? 0),
    'incidents_opened' => (int)($result['incidents_opened'] ?? 0),
    'incidents_closed' => (int)($result['incidents_closed'] ?? 0),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (PHP_SAPI === 'cli') {
    echo PHP_EOL;
    exit(0);
}
