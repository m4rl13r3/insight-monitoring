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
    if (!function_exists('public_state_write_daily')) {
        function public_state_write_daily(array $payload): bool
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

$result = ['ok' => false, 'status_code' => 503, 'message' => 'Python bypassed (forced PHP fallback).'];
$ok = false;
if (!$shouldForceFallback) {
    $result = run_monitoring_python(['daily']);
    $ok = !empty($result['ok']);
}

if (!$ok) {
    if (!$shouldDisableFallback) {
        $fallback = run_daily_php_fallback();
        if (!empty($fallback['ok'])) {
            public_state_write_daily([
                'service_name' => 'insight',
                'is_degraded' => 1,
                'active_engine' => 'php',
                'daily_last_ok' => 1,
                'daily_processed' => (int)($fallback['processed'] ?? 0),
                'daily_bad_data' => (int)($fallback['bad_data'] ?? 0),
                'daily_engine' => 'php',
            ]);
            echo 'Mode degrade: php_fallback' . PHP_EOL;
            echo 'Total processed: ' . (int)($fallback['processed'] ?? 0) . PHP_EOL;
            echo 'Total bad data entries: ' . (int)($fallback['bad_data'] ?? 0) . PHP_EOL;
            if (PHP_SAPI === 'cli') {
                exit(0);
            }
            return;
        }
    }

    if (PHP_SAPI !== 'cli') {
        http_response_code((int)($result['status_code'] ?? 500));
    }
    $message = $result['message'] ?? 'Erreur monitoring daily.';
    if (!empty($result['raw'])) {
        $message .= "\n" . $result['raw'];
    }
    $message .= "\n" . ($shouldDisableFallback ? 'Fallback PHP daily désactivé.' : ($fallback['message'] ?? 'Fallback PHP daily indisponible.'));
    public_state_write_daily([
        'service_name' => 'insight',
        'is_degraded' => 1,
        'active_engine' => 'unknown',
        'daily_last_ok' => 0,
        'daily_engine' => 'unknown',
    ]);
    echo $message . PHP_EOL;
    if (PHP_SAPI === 'cli') {
        exit(1);
    }
    return;
}

public_state_write_daily([
    'service_name' => 'insight',
    'is_degraded' => 0,
    'active_engine' => 'pyt',
    'daily_last_ok' => 1,
    'daily_processed' => (int)($result['processed'] ?? 0),
    'daily_bad_data' => (int)($result['bad_data'] ?? 0),
    'daily_engine' => 'pyt',
]);

echo 'Total processed: ' . (int)($result['processed'] ?? 0) . PHP_EOL;
echo 'Total bad data entries: ' . (int)($result['bad_data'] ?? 0) . PHP_EOL;
echo 'Engine: pyt' . PHP_EOL;

if (PHP_SAPI === 'cli') {
    exit(0);
}
