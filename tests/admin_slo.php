<?php

declare(strict_types=1);

$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require dirname(__DIR__) . '/public/admin/_slo.php';

$noData = insight_dashboard_slo([
    'slo_target_percent' => 99.9,
    'slo_observed_seconds' => 0,
    'slo_offline_seconds' => 0,
]);
$healthy = insight_dashboard_slo([
    'slo_target_percent' => 99.9,
    'slo_observed_seconds' => 1000000,
    'slo_offline_seconds' => 100,
]);
$atRisk = insight_dashboard_slo([
    'slo_target_percent' => 99.9,
    'slo_observed_seconds' => 1000000,
    'slo_offline_seconds' => 800,
]);
$breached = insight_dashboard_slo([
    'slo_target_percent' => 99.9,
    'slo_observed_seconds' => 1000000,
    'slo_offline_seconds' => 1200,
]);

if (
    $noData['state'] !== 'no_data'
    || $healthy['state'] !== 'met'
    || $atRisk['state'] !== 'at_risk'
    || $breached['state'] !== 'breached'
    || (int)$atRisk['remaining_seconds'] !== 200
) {
    fwrite(STDERR, "SLO calculation failed.\n");
    exit(1);
}

echo "SLO calculation passed.\n";
