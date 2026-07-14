<?php

declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function insight_dashboard_slo(array $monitor): array
{
    $target = max(0.0, min(100.0, (float)($monitor['slo_target_percent'] ?? 99.9)));
    $observed = max(0, (int)($monitor['slo_observed_seconds'] ?? 0));
    $offline = max(0, min($observed, (int)($monitor['slo_offline_seconds'] ?? 0)));
    if ($observed <= 0) {
        return ['target' => $target, 'availability' => null, 'observed_seconds' => 0, 'offline_seconds' => 0, 'allowed_seconds' => 0, 'remaining_seconds' => 0, 'remaining_percent' => null, 'state' => 'no_data'];
    }
    $availability = (($observed - $offline) / $observed) * 100;
    $allowed = (int)round($observed * ((100 - $target) / 100));
    $remaining = $allowed - $offline;
    $remainingPercent = $allowed > 0 ? max(0.0, min(100.0, ($remaining / $allowed) * 100)) : ($offline === 0 ? 100.0 : 0.0);
    $state = $availability + 0.000001 < $target ? 'breached' : ($remainingPercent <= 25 ? 'at_risk' : 'met');
    return [
        'target' => $target,
        'availability' => $availability,
        'observed_seconds' => $observed,
        'offline_seconds' => $offline,
        'allowed_seconds' => $allowed,
        'remaining_seconds' => $remaining,
        'remaining_percent' => $remainingPercent,
        'state' => $state,
    ];
}
