<?php

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function hourly_fetch_latest_ssl_by_site(mysqli $conn, array $siteIds) {
    $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
    if (count($siteIds) === 0) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
    $types = str_repeat('i', count($siteIds));
    $latestBySite = [];

    $sql = "
        SELECT
            sc.site_id,
            sc.host,
            sc.port,
            sc.is_valid,
            sc.valid_from,
            sc.valid_to,
            sc.days_remaining,
            sc.issuer_name,
            sc.issuer_cn,
            sc.subject_cn,
            sc.san,
            sc.tls_version,
            sc.cipher_name,
            sc.error_message,
            sc.checked_at
        FROM ssl_checks sc
        INNER JOIN (
            SELECT site_id, MAX(id) AS max_id
            FROM ssl_checks
            WHERE site_id IN ($placeholders)
            GROUP BY site_id
        ) latest ON latest.site_id = sc.site_id AND latest.max_id = sc.id
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$siteIds);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $sid = (int)$row['site_id'];
        $issuerName = $row['issuer_name'];
        if (($issuerName === null || $issuerName === '') && !empty($row['issuer_cn'])) {
            $issuerName = $row['issuer_cn'];
        }
        $latestBySite[$sid] = [
            'host' => $row['host'],
            'port' => (int)$row['port'],
            'is_valid' => $row['is_valid'] === null ? null : (bool)$row['is_valid'],
            'valid_from' => $row['valid_from'],
            'valid_to' => $row['valid_to'],
            'days_remaining' => $row['days_remaining'] === null ? null : (int)$row['days_remaining'],
            'issuer_name' => $issuerName,
            'issuer_cn' => $row['issuer_cn'],
            'subject_cn' => $row['subject_cn'],
            'san' => $row['san'],
            'tls_version' => $row['tls_version'],
            'cipher_name' => $row['cipher_name'],
            'error_message' => $row['error_message'],
            'checked_at' => $row['checked_at']
        ];
    }
    $stmt->close();

    return $latestBySite;
}

function hourly_has_table(mysqli $conn, string $tableName): bool {
    static $cache = [];
    if (array_key_exists($tableName, $cache)) {
        return (bool)$cache[$tableName];
    }

    $stmt = $conn->prepare("
        SELECT 1 AS present
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        $cache[$tableName] = false;
        return false;
    }

    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = (bool)($res ? $res->fetch_assoc() : false);
    $stmt->close();

    $cache[$tableName] = $exists;
    return $exists;
}

function hourly_has_column(mysqli $conn, string $tableName, string $columnName): bool {
    static $cache = [];
    $cacheKey = $tableName . '.' . $columnName;
    if (array_key_exists($cacheKey, $cache)) {
        return (bool)$cache[$cacheKey];
    }

    $stmt = $conn->prepare("
        SELECT 1 AS present
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        $cache[$cacheKey] = false;
        return false;
    }

    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = (bool)($res ? $res->fetch_assoc() : false);
    $stmt->close();

    $cache[$cacheKey] = $exists;
    return $exists;
}

function hourly_state_from_metrics($minutesOffline, $availabilityRatio, $totalSeconds, $maintenanceSeconds): string {
    if ($availabilityRatio !== null && is_numeric($availabilityRatio)) {
        $ratio = (float)$availabilityRatio;
        $totalSec = ($totalSeconds !== null && is_numeric($totalSeconds)) ? (int)$totalSeconds : null;
        $maintenanceSec = ($maintenanceSeconds !== null && is_numeric($maintenanceSeconds)) ? (int)$maintenanceSeconds : 0;
        if ($totalSec !== null && ($totalSec - $maintenanceSec) <= 0) {
            return 'MAINTENANCE';
        }
        if ($ratio >= 0.9999) {
            return 'YES';
        }
        if ($ratio <= 0.0001) {
            return 'NO';
        }
        return 'PARTIALLY';
    }

    if ($minutesOffline !== null && is_numeric($minutesOffline)) {
        $mins = (int)$minutesOffline;
        if ($mins <= 0) {
            return 'YES';
        }
        if ($mins >= 60) {
            return 'NO';
        }
        return 'PARTIALLY';
    }
    return 'UNKNOWN';
}

function hourly_maintenance_state(array $row): string {
    $status = strtolower(trim((string)($row['status'] ?? 'planned')));
    if ($status === 'cancelled') {
        return 'cancelled';
    }

    $startsTs = strtotime((string)($row['starts_at'] ?? ''));
    $endsTs = strtotime((string)($row['ends_at'] ?? ''));
    $nowTs = time();

    if ($startsTs !== false && $endsTs !== false && $startsTs <= $nowTs && $endsTs >= $nowTs) {
        return 'active';
    }
    if ($endsTs !== false && $endsTs < $nowTs) {
        return 'completed';
    }
    return 'planned';
}

function hourly_fetch_maintenance_windows(
    mysqli $conn,
    array $siteIds,
    string $rangeStart,
    string $rangeEnd,
    bool $publicOnly
): array {
    if (!hourly_has_table($conn, 'scheduled_maintenances')) {
        return [];
    }

    $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
    if (count($siteIds) === 0) {
        return [];
    }

    $where = [
        "m.status <> 'cancelled'",
        "m.ends_at >= ?",
        "m.starts_at <= ?",
    ];
    $types = 'ss';
    $params = [$rangeStart, $rangeEnd];

    if ($publicOnly) {
        $where[] = 'm.notify_public = 1';
    }

    $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
    $where[] = "(m.site_id IS NULL OR m.site_id IN ($placeholders))";
    $types .= str_repeat('i', count($siteIds));
    foreach ($siteIds as $siteId) {
        $params[] = $siteId;
    }

    $sql = "
        SELECT
            m.id,
            m.site_id,
            m.title,
            m.description,
            m.starts_at,
            m.ends_at,
            m.status,
            m.notify_public
        FROM scheduled_maintenances m
        WHERE " . implode(' AND ', $where) . "
        ORDER BY m.starts_at ASC, m.id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $res = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $startsTs = strtotime((string)($row['starts_at'] ?? ''));
        $endsTs = strtotime((string)($row['ends_at'] ?? ''));
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'site_id' => isset($row['site_id']) ? (int)$row['site_id'] : null,
            'title' => (string)($row['title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'starts_at' => (string)($row['starts_at'] ?? ''),
            'ends_at' => (string)($row['ends_at'] ?? ''),
            'status' => (string)($row['status'] ?? 'planned'),
            'state' => hourly_maintenance_state($row),
            'notify_public' => (int)($row['notify_public'] ?? 1) === 1,
            'starts_ts' => $startsTs === false ? null : (int)$startsTs,
            'ends_ts' => $endsTs === false ? null : (int)$endsTs,
            'is_global' => empty($row['site_id']),
        ];
    }
    $stmt->close();

    return $rows;
}

function hourly_window_overlaps_slot(array $window, int $slotStartTs, int $slotEndTs): bool {
    $startsTs = isset($window['starts_ts']) ? (int)$window['starts_ts'] : null;
    $endsTs = isset($window['ends_ts']) ? (int)$window['ends_ts'] : null;
    if (!$startsTs || !$endsTs) {
        return false;
    }
    return $startsTs < $slotEndTs && $endsTs > $slotStartTs;
}

function hourly_export_maintenance_window(array $window): array {
    return [
        'id' => (int)($window['id'] ?? 0),
        'site_id' => isset($window['site_id']) ? (int)$window['site_id'] : null,
        'is_global' => !empty($window['is_global']),
        'title' => (string)($window['title'] ?? ''),
        'description' => (string)($window['description'] ?? ''),
        'starts_at' => (string)($window['starts_at'] ?? ''),
        'ends_at' => (string)($window['ends_at'] ?? ''),
        'status' => (string)($window['status'] ?? 'planned'),
        'state' => (string)($window['state'] ?? 'planned'),
        'notify_public' => !empty($window['notify_public']),
    ];
}

function hourly_stats_site_data_quality(array $site, bool $usedSitesFallback, $requestedDate): array {
    $slots = isset($site['data']) && is_array($site['data']) ? $site['data'] : [];
    $expectedSlots = max(24, count($slots));
    $knownSlots = 0;
    $latestTs = null;
    $sources = [];
    $siteCalcMethod = trim((string)($site['calc_method'] ?? ''));
    if ($siteCalcMethod !== '') {
        $sources[$siteCalcMethod] = true;
    }

    foreach ($slots as $slot) {
        if (!is_array($slot)) {
            continue;
        }
        $state = strtoupper(trim((string)($slot['hasBeenOnline'] ?? 'UNKNOWN')));
        if ($state !== '' && $state !== 'UNKNOWN') {
            $knownSlots++;
        }
        $calcMethod = trim((string)($slot['calc_method'] ?? ''));
        if ($calcMethod !== '') {
            $sources[$calcMethod] = true;
        }
        $checkedAt = trim((string)($slot['checked_at'] ?? ''));
        if ($checkedAt !== '') {
            $ts = strtotime($checkedAt);
            if ($ts !== false && ($latestTs === null || $ts > $latestTs)) {
                $latestTs = (int)$ts;
            }
        }
    }

    $coveragePercent = $expectedSlots > 0 ? round(($knownSlots / $expectedSlots) * 100, 2) : 0.0;
    $sourceNames = array_keys($sources);
    $sourceEngine = count($sourceNames) === 0 ? 'hourly_stats' : (count($sourceNames) === 1 ? $sourceNames[0] : 'mixed');
    if ($usedSitesFallback && $knownSlots === 0) {
        $sourceEngine = 'sites_fallback';
    }

    $status = 'unknown';
    if ($usedSitesFallback && $knownSlots === 0) {
        $status = 'fallback';
    } elseif ($knownSlots === 0) {
        $status = 'unknown';
    } elseif (!$requestedDate && $latestTs !== null && $latestTs < (time() - (3 * 3600))) {
        $status = 'stale';
    } elseif ($coveragePercent < 100) {
        $status = 'partial';
    } else {
        $status = 'fresh';
    }

    return [
        'status' => $status,
        'coverage_percent' => $coveragePercent,
        'latest_checked_at' => $latestTs === null ? null : date('Y-m-d H:i:s', $latestTs),
        'source_engine' => $sourceEngine,
        'known_slots' => $knownSlots,
        'expected_slots' => $expectedSlots
    ];
}

function hourly_stats_global_data_quality(array $sites): array {
    $count = count($sites);
    if ($count === 0) {
        return [
            'status' => 'unknown',
            'coverage_percent' => 0.0,
            'latest_checked_at' => null,
            'source_engine' => 'unknown',
            'site_count' => 0
        ];
    }

    $coverageTotal = 0.0;
    $latestTs = null;
    $sources = [];
    $statuses = [];

    foreach ($sites as $site) {
        $quality = isset($site['data_quality']) && is_array($site['data_quality']) ? $site['data_quality'] : [];
        $coverageTotal += (float)($quality['coverage_percent'] ?? 0);
        $status = (string)($quality['status'] ?? 'unknown');
        $statuses[$status] = true;
        $source = trim((string)($quality['source_engine'] ?? ''));
        if ($source !== '') {
            $sources[$source] = true;
        }
        $checkedAt = trim((string)($quality['latest_checked_at'] ?? ''));
        if ($checkedAt !== '') {
            $ts = strtotime($checkedAt);
            if ($ts !== false && ($latestTs === null || $ts > $latestTs)) {
                $latestTs = (int)$ts;
            }
        }
    }

    if (isset($statuses['stale'])) {
        $status = 'stale';
    } elseif (isset($statuses['partial'])) {
        $status = 'partial';
    } elseif (isset($statuses['fallback']) && count($statuses) === 1) {
        $status = 'fallback';
    } elseif (isset($statuses['unknown']) && count($statuses) === 1) {
        $status = 'unknown';
    } elseif (isset($statuses['fallback']) || isset($statuses['unknown'])) {
        $status = 'partial';
    } else {
        $status = 'fresh';
    }

    $sourceNames = array_keys($sources);

    return [
        'status' => $status,
        'coverage_percent' => round($coverageTotal / $count, 2),
        'latest_checked_at' => $latestTs === null ? null : date('Y-m-d H:i:s', $latestTs),
        'source_engine' => count($sourceNames) === 0 ? 'unknown' : (count($sourceNames) === 1 ? $sourceNames[0] : 'mixed'),
        'site_count' => $count
    ];
}

function hourly_probe_error_type($status, $httpCode): ?string {
    $state = strtolower(trim((string)$status));
    $code = ($httpCode !== null && $httpCode !== '' && is_numeric($httpCode)) ? (int)$httpCode : null;

    if (in_array($state, ['online', 'up', 'ok', 'success'], true) && ($code === null || $code < 400)) {
        return null;
    }
    if ($code !== null) {
        if ($code >= 500) {
            return 'http_5xx';
        }
        if ($code >= 400) {
            return 'http_4xx';
        }
        if ($code >= 300) {
            return 'redirect';
        }
        if ($code === 0) {
            return 'network_error';
        }
        if ($state !== '' && !in_array($state, ['online', 'up', 'ok', 'success'], true)) {
            return 'unknown';
        }
        return null;
    }
    if (in_array($state, ['timeout'], true)) {
        return 'timeout';
    }
    if (in_array($state, ['offline', 'down', 'error', 'failed', 'ko'], true)) {
        return 'network_error';
    }
    return null;
}

function hourly_fetch_latest_probe_meta_by_site(mysqli $conn, array $siteIds): array {
    if (!hourly_has_table($conn, 'probes')) {
        return [];
    }

    $siteIds = array_values(array_unique(array_map('intval', $siteIds)));
    if (count($siteIds) === 0) {
        return [];
    }

    $hasProbeType = hourly_has_column($conn, 'probes', 'probe_type');
    $hasStatus = hourly_has_column($conn, 'probes', 'status');
    $hasResponseTime = hourly_has_column($conn, 'probes', 'response_time');
    $hasHttpCode = hourly_has_column($conn, 'probes', 'http_code');
    $hasCheckedBy = hourly_has_column($conn, 'probes', 'checked_by');
    $hasSourceNode = hourly_has_column($conn, 'probes', 'source_node');
    $hasCheckedAt = hourly_has_column($conn, 'probes', 'checked_at');

    $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
    $types = str_repeat('i', count($siteIds));
    $sql = "
        SELECT
            p.site_id,
            " . ($hasProbeType ? "p.probe_type" : "NULL AS probe_type") . ",
            " . ($hasStatus ? "p.status AS probe_status" : "NULL AS probe_status") . ",
            " . ($hasResponseTime ? "p.response_time" : "NULL AS response_time") . ",
            " . ($hasHttpCode ? "p.http_code" : "NULL AS http_code") . ",
            " . ($hasCheckedBy ? "p.checked_by" : "NULL AS checked_by") . ",
            " . ($hasSourceNode ? "p.source_node" : "NULL AS source_node") . ",
            " . ($hasCheckedAt ? "p.checked_at" : "NULL AS checked_at") . "
        FROM probes p
        INNER JOIN (
            SELECT site_id, MAX(id) AS max_id
            FROM probes
            WHERE site_id IN ($placeholders)
            GROUP BY site_id
        ) latest ON latest.site_id = p.site_id AND latest.max_id = p.id
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param($types, ...$siteIds);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $res = $stmt->get_result();
    $bySite = [];
    while ($row = $res->fetch_assoc()) {
        $sid = (int)($row['site_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $httpCode = ($row['http_code'] !== null && $row['http_code'] !== '' && is_numeric($row['http_code'])) ? (int)$row['http_code'] : null;
        $responseTime = ($row['response_time'] !== null && $row['response_time'] !== '' && is_numeric($row['response_time'])) ? (float)$row['response_time'] : null;
        $bySite[$sid] = [
            'probe_type' => trim((string)($row['probe_type'] ?? '')) !== '' ? trim((string)$row['probe_type']) : null,
            'last_probe_status' => trim((string)($row['probe_status'] ?? '')) !== '' ? trim((string)$row['probe_status']) : null,
            'last_probe_response_time' => $responseTime,
            'last_probe_http_code' => $httpCode,
            'checked_by' => trim((string)($row['checked_by'] ?? '')) !== '' ? trim((string)$row['checked_by']) : null,
            'source_node' => trim((string)($row['source_node'] ?? '')) !== '' ? trim((string)$row['source_node']) : null,
            'last_probe_at' => trim((string)($row['checked_at'] ?? '')) !== '' ? trim((string)$row['checked_at']) : null,
            'last_error_type' => hourly_probe_error_type($row['probe_status'] ?? null, $httpCode)
        ];
    }
    $stmt->close();

    return $bySite;
}

function hourly_stats_site_probe_meta(array $site, array $latestProbe): array {
    $probeInterval = null;
    if (isset($site['probe_interval_sec']) && $site['probe_interval_sec'] !== null && is_numeric($site['probe_interval_sec'])) {
        $probeInterval = (int)$site['probe_interval_sec'];
    }
    $calcMethod = trim((string)($site['calc_method'] ?? ''));
    $calcMethod = $calcMethod !== '' ? strtolower($calcMethod) : null;

    return [
        'probe_interval_sec' => $probeInterval,
        'calc_method' => $calcMethod,
        'checked_by' => $latestProbe['checked_by'] ?? null,
        'source_node' => $latestProbe['source_node'] ?? null,
        'last_error_type' => $latestProbe['last_error_type'] ?? null,
        'last_probe_at' => $latestProbe['last_probe_at'] ?? null,
        'last_probe_status' => $latestProbe['last_probe_status'] ?? null,
        'last_probe_http_code' => $latestProbe['last_probe_http_code'] ?? null,
        'last_probe_response_time' => $latestProbe['last_probe_response_time'] ?? null,
        'probe_type' => $latestProbe['probe_type'] ?? null
    ];
}

function hourly_handle_stats_mode(array $ctx) {
    $conn = $ctx['conn'];
    $requestedDate = $ctx['requestedDate'];
    $includeSitesFallback = $ctx['includeSitesFallback'];
    $includeDailyData = $ctx['includeDailyData'];
    $includeIncidents = $ctx['includeIncidents'];

    $currentDate = date('Y-m-d');
    $currentHour = (int)date('G');
    $previousHour = $currentHour - 1;
    if ($previousHour < 0) {
        $currentDate = date('Y-m-d', strtotime('-1 day'));
        $previousHour = 23;
    }
    $rollingAnchor = DateTime::createFromFormat(
        'Y-m-d H:i:s',
        $currentDate . ' ' . str_pad((string)$previousHour, 2, '0', STR_PAD_LEFT) . ':00:00'
    );
    if (!$rollingAnchor) {
        $rollingAnchor = new DateTime($currentDate . ' ' . str_pad((string)$previousHour, 2, '0', STR_PAD_LEFT) . ':00:00');
    }

    $hasHourlyTotalSeconds = hourly_has_column($conn, 'hourly_stats', 'total_seconds');
    $hasHourlyOfflineSeconds = hourly_has_column($conn, 'hourly_stats', 'offline_seconds');
    $hasHourlyDegradedSeconds = hourly_has_column($conn, 'hourly_stats', 'degraded_seconds');
    $hasHourlyMaintenanceSeconds = hourly_has_column($conn, 'hourly_stats', 'maintenance_seconds');
    $hasHourlyAvailabilityRatio = hourly_has_column($conn, 'hourly_stats', 'availability_ratio');
    $hasHourlyHealthScore = hourly_has_column($conn, 'hourly_stats', 'health_score');
    $hasHourlyCalcMethod = hourly_has_column($conn, 'hourly_stats', 'calc_method');
    $hasDailyTotalSeconds = hourly_has_column($conn, 'daily_stats', 'total_seconds');
    $hasDailyOfflineSeconds = hourly_has_column($conn, 'daily_stats', 'offline_seconds');
    $hasDailyDegradedSeconds = hourly_has_column($conn, 'daily_stats', 'degraded_seconds');
    $hasDailyMaintenanceSeconds = hourly_has_column($conn, 'daily_stats', 'maintenance_seconds');
    $hasDailyAvailabilityRatio = hourly_has_column($conn, 'daily_stats', 'availability_ratio');
    $hasDailyHealthScore = hourly_has_column($conn, 'daily_stats', 'health_score');
    $hasDailyCalcMethod = hourly_has_column($conn, 'daily_stats', 'calc_method');
    $hasSitesProbeInterval = hourly_has_column($conn, 'sites', 'probe_interval_sec');
    $hasSitesCalcMethod = hourly_has_column($conn, 'sites', 'calc_method');

    $hourlyExtraSelect = "
                " . ($hasHourlyTotalSeconds ? "h.total_seconds" : "NULL AS total_seconds") . ",
                " . ($hasHourlyOfflineSeconds ? "h.offline_seconds" : "NULL AS offline_seconds") . ",
                " . ($hasHourlyDegradedSeconds ? "h.degraded_seconds" : "NULL AS degraded_seconds") . ",
                " . ($hasHourlyMaintenanceSeconds ? "h.maintenance_seconds" : "NULL AS maintenance_seconds") . ",
                " . ($hasHourlyAvailabilityRatio ? "h.availability_ratio" : "NULL AS availability_ratio") . ",
                " . ($hasHourlyHealthScore ? "h.health_score" : "NULL AS health_score") . ",
                " . ($hasHourlyCalcMethod ? "h.calc_method" : "NULL AS calc_method") . ",
                " . ($hasSitesProbeInterval ? "s.probe_interval_sec" : "NULL AS probe_interval_sec") . ",
                " . ($hasSitesCalcMethod ? "s.calc_method AS site_calc_method" : "NULL AS site_calc_method") . ",
    ";

    if ($requestedDate) {
        $sql = "
            SELECT
                s.id AS site_id,
                s.url,
                h.date,
                h.hour,
                h.avg_response_time,
                h.minutes_offline,
                h.binary_sequence,
                $hourlyExtraSelect
                h.checked_at
            FROM
                hourly_stats h
            JOIN
                sites s ON h.site_id = s.id
            WHERE
                h.date = ?
            ORDER BY
                s.url ASC, h.hour ASC
        ";
    } else {
        $sql = "
            SELECT
                s.id AS site_id,
                s.url,
                h.date,
                h.hour,
                h.avg_response_time,
                h.minutes_offline,
                h.binary_sequence,
                $hourlyExtraSelect
                h.checked_at
            FROM
                hourly_stats h
            JOIN
                sites s ON h.site_id = s.id
            WHERE
                CONCAT(h.date, ' ', LPAD(h.hour, 2, '0'), ':00:00') <= CONCAT(?, ' ', LPAD(?, 2, '0'), ':00:00')
            AND
                CONCAT(h.date, ' ', LPAD(h.hour, 2, '0'), ':00:00') >= CONCAT(DATE_SUB(?, INTERVAL 1 DAY), ' ', LPAD(?, 2, '0'), ':00:00')
            ORDER BY
                s.url ASC, h.date DESC, h.hour DESC
        ";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        hourly_log($ctx, "Hourly query prepare failed: {$conn->error}");
        hourly_send_api_response($ctx, 'stats', [], 500, [
            'code' => 'hourly_prepare_failed',
            'message' => 'Failed to prepare hourly query.'
        ]);
        return;
    }

    if ($requestedDate) {
        $stmt->bind_param("s", $requestedDate);
    } else {
        $stmt->bind_param("sisi", $currentDate, $previousHour, $currentDate, $previousHour);
    }
    if (!$stmt->execute()) {
        hourly_log($ctx, "Hourly query execute failed: {$stmt->error}");
        hourly_send_api_response($ctx, 'stats', [], 500, [
            'code' => 'hourly_execute_failed',
            'message' => 'Failed to execute hourly query.'
        ]);
        $stmt->close();
        return;
    }
    hourly_log($ctx, "Executed hourly data query");
    $result = $stmt->get_result();

    $sitesData = [];
    $hoursInDay = range(0, 23);
    $siteIds = [];
    $usedSitesFallback = false;

    while ($row = $result->fetch_assoc()) {
        $url = (string)($row['url'] ?? '');
        if ($url === '') {
            continue;
        }
        $siteId = (int)($row['site_id'] ?? 0);
        $hourInt = isset($row['hour']) ? (int)$row['hour'] : null;
        if ($hourInt === null || $hourInt < 0 || $hourInt > 23) {
            continue;
        }

        if (!isset($sitesData[$url])) {
            $siteProbeInterval = null;
            $probeIntervalRaw = $row['probe_interval_sec'] ?? null;
            if ($probeIntervalRaw !== null && $probeIntervalRaw !== '' && is_numeric($probeIntervalRaw)) {
                $siteProbeInterval = (int)$probeIntervalRaw;
            }
            $siteCalcMethod = null;
            $siteCalcMethodRaw = array_key_exists('site_calc_method', $row) ? $row['site_calc_method'] : null;
            if ($siteCalcMethodRaw !== null && $siteCalcMethodRaw !== '') {
                $siteCalcMethod = strtolower(trim((string)$siteCalcMethodRaw));
            }
            $sitesData[$url] = [
                'url' => $url,
                'site_id' => $siteId,
                'probe_interval_sec' => $siteProbeInterval,
                'calc_method' => $siteCalcMethod,
                'data' => []
            ];
        } else {
            if (!isset($sitesData[$url]['probe_interval_sec']) || $sitesData[$url]['probe_interval_sec'] === null) {
                $probeIntervalRaw = $row['probe_interval_sec'] ?? null;
                if ($probeIntervalRaw !== null && $probeIntervalRaw !== '' && is_numeric($probeIntervalRaw)) {
                    $sitesData[$url]['probe_interval_sec'] = (int)$probeIntervalRaw;
                }
            }
            if (!isset($sitesData[$url]['calc_method']) || $sitesData[$url]['calc_method'] === null || $sitesData[$url]['calc_method'] === '') {
                $siteCalcMethodRaw = array_key_exists('site_calc_method', $row) ? $row['site_calc_method'] : null;
                if ($siteCalcMethodRaw !== null && $siteCalcMethodRaw !== '') {
                    $sitesData[$url]['calc_method'] = strtolower(trim((string)$siteCalcMethodRaw));
                }
            }
        }

        $rowDateRaw = isset($row['date']) ? (string)$row['date'] : '';
        $rowDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rowDateRaw) ? $rowDateRaw : null;
        if ($rowDate === null) {
            continue;
        }

        $rowDateTime = DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $rowDate . ' ' . str_pad((string)$hourInt, 2, '0', STR_PAD_LEFT) . ':00:00'
        );
        if (!$rowDateTime) {
            continue;
        }

        $relativeHour = $hourInt;
        $dataKey = $hourInt;
        if (!$requestedDate) {
            $secondsDiff = $rollingAnchor->getTimestamp() - $rowDateTime->getTimestamp();
            if ($secondsDiff < 0) {
                continue;
            }
            $relativeHour = (int)floor($secondsDiff / 3600);
            if ($relativeHour < 0 || $relativeHour > 23) {
                continue;
            }
            $dataKey = $relativeHour;
        }

        $minutesOffline = null;
        if ($row['minutes_offline'] !== null && $row['minutes_offline'] !== '' && is_numeric($row['minutes_offline'])) {
            $minutesOffline = (int)$row['minutes_offline'];
            if ($minutesOffline < 0) {
                $minutesOffline = 0;
            } elseif ($minutesOffline > 60) {
                $minutesOffline = 60;
            }
        }

        $totalSeconds = null;
        if ($row['total_seconds'] !== null && $row['total_seconds'] !== '' && is_numeric($row['total_seconds'])) {
            $totalSeconds = (int)$row['total_seconds'];
        }
        $offlineSeconds = null;
        if ($row['offline_seconds'] !== null && $row['offline_seconds'] !== '' && is_numeric($row['offline_seconds'])) {
            $offlineSeconds = (int)$row['offline_seconds'];
        }
        $degradedSeconds = null;
        if ($row['degraded_seconds'] !== null && $row['degraded_seconds'] !== '' && is_numeric($row['degraded_seconds'])) {
            $degradedSeconds = (int)$row['degraded_seconds'];
        }
        $maintenanceSeconds = null;
        if ($row['maintenance_seconds'] !== null && $row['maintenance_seconds'] !== '' && is_numeric($row['maintenance_seconds'])) {
            $maintenanceSeconds = (int)$row['maintenance_seconds'];
        }
        $availabilityRatio = null;
        if ($row['availability_ratio'] !== null && $row['availability_ratio'] !== '' && is_numeric($row['availability_ratio'])) {
            $availabilityRatio = (float)$row['availability_ratio'];
        }
        $healthScore = null;
        if ($row['health_score'] !== null && $row['health_score'] !== '' && is_numeric($row['health_score'])) {
            $healthScore = (float)$row['health_score'];
        }
        $entryCalcMethod = null;
        if ($row['calc_method'] !== null && $row['calc_method'] !== '') {
            $entryCalcMethod = strtolower(trim((string)$row['calc_method']));
        }
        $hasBeenOnline = hourly_state_from_metrics($minutesOffline, $availabilityRatio, $totalSeconds, $maintenanceSeconds);

        $avgResponse = null;
        if ($row['avg_response_time'] !== null && $row['avg_response_time'] !== '' && is_numeric($row['avg_response_time'])) {
            $avgResponse = (float)$row['avg_response_time'];
        }

        $checkedAtTs = 0;
        if (!empty($row['checked_at'])) {
            $tmp = strtotime((string)$row['checked_at']);
            if ($tmp !== false) {
                $checkedAtTs = (int)$tmp;
            }
        }

        if (isset($sitesData[$url]['data'][$dataKey])) {
            $existing = $sitesData[$url]['data'][$dataKey];
            $existingTs = 0;
            if (!empty($existing['checked_at'])) {
                $tmp = strtotime((string)$existing['checked_at']);
                if ($tmp !== false) {
                    $existingTs = (int)$tmp;
                }
            }
            if ($existingTs >= $checkedAtTs && $existingTs > 0) {
                continue;
            }
        }

        $sitesData[$url]['data'][$dataKey] = [
            'date' => $rowDate,
            'hour' => $hourInt,
            'relative_hour' => $relativeHour,
            'avg_response_time' => $avgResponse,
            'minutes_offline' => $minutesOffline,
            'binary_sequence' => $row['binary_sequence'],
            'total_seconds' => $totalSeconds,
            'offline_seconds' => $offlineSeconds,
            'degraded_seconds' => $degradedSeconds,
            'maintenance_seconds' => $maintenanceSeconds,
            'availability_ratio' => $availabilityRatio,
            'health_score' => $healthScore,
            'calc_method' => $entryCalcMethod,
            'checked_at' => $row['checked_at'],
            'hasBeenOnline' => $hasBeenOnline,
            'maintenance' => false
        ];
    }
    $stmt->close();

    if ($includeSitesFallback && count($sitesData) === 0) {
        $usedSitesFallback = true;
        $siteFallbackSelect = "
            SELECT
                id,
                url,
                " . ($hasSitesProbeInterval ? "probe_interval_sec" : "NULL AS probe_interval_sec") . ",
                " . ($hasSitesCalcMethod ? "calc_method" : "NULL AS calc_method") . "
            FROM sites
            ORDER BY url ASC
        ";
        $sitesRes = $conn->query($siteFallbackSelect);
        if ($sitesRes) {
            while ($siteRow = $sitesRes->fetch_assoc()) {
                $url = $siteRow['url'];
                $sitesData[$url] = [
                    'url' => $url,
                    'site_id' => (int)$siteRow['id'],
                    'probe_interval_sec' => ($siteRow['probe_interval_sec'] !== null && $siteRow['probe_interval_sec'] !== '' && is_numeric($siteRow['probe_interval_sec'])) ? (int)$siteRow['probe_interval_sec'] : null,
                    'calc_method' => ($siteRow['calc_method'] !== null && $siteRow['calc_method'] !== '') ? strtolower(trim((string)$siteRow['calc_method'])) : null,
                    'data' => []
                ];
            }
        }
    }

    if (count($sitesData) > 0) {
        foreach ($sitesData as &$site) {
            if ($requestedDate) {
                foreach ($hoursInDay as $hour) {
                    if (!isset($site['data'][$hour])) {
                        $site['data'][$hour] = [
                            'date' => $requestedDate,
                            'hour' => $hour,
                            'relative_hour' => $hour,
                            'avg_response_time' => null,
                            'minutes_offline' => null,
                            'binary_sequence' => null,
                            'total_seconds' => null,
                            'offline_seconds' => null,
                            'degraded_seconds' => null,
                            'maintenance_seconds' => null,
                            'availability_ratio' => null,
                            'health_score' => null,
                            'calc_method' => null,
                            'checked_at' => null,
                            'hasBeenOnline' => "UNKNOWN",
                            'maintenance' => false
                        ];
                    }
                }
                ksort($site['data']);
            } else {
                foreach (range(0, 23) as $relativeHour) {
                    if (isset($site['data'][$relativeHour])) {
                        continue;
                    }

                    $slotTime = clone $rollingAnchor;
                    if ($relativeHour > 0) {
                        $slotTime->modify('-' . $relativeHour . ' hour');
                    }

                    $site['data'][$relativeHour] = [
                        'date' => $slotTime->format('Y-m-d'),
                        'hour' => (int)$slotTime->format('G'),
                        'relative_hour' => $relativeHour,
                        'avg_response_time' => null,
                        'minutes_offline' => null,
                        'binary_sequence' => null,
                        'total_seconds' => null,
                        'offline_seconds' => null,
                        'degraded_seconds' => null,
                        'maintenance_seconds' => null,
                        'availability_ratio' => null,
                        'health_score' => null,
                        'calc_method' => null,
                        'checked_at' => null,
                        'hasBeenOnline' => "UNKNOWN",
                        'maintenance' => false
                    ];
                }
                ksort($site['data']);
            }

            $site['data'] = array_values($site['data']);
            $siteIds[] = $site['site_id'];
        }
        unset($site);
        $siteIds = array_values(array_unique($siteIds));

        $maintenanceOverrideGlobal = [];
        $maintenanceOverrideBySite = [];
        $maintenancePublicGlobal = [];
        $maintenancePublicBySite = [];

        if (count($siteIds) > 0) {
            if ($requestedDate) {
                $rangeStart = DateTime::createFromFormat('Y-m-d H:i:s', $requestedDate . ' 00:00:00');
                $rangeEnd = DateTime::createFromFormat('Y-m-d H:i:s', $requestedDate . ' 23:59:59');
            } else {
                $rangeEnd = clone $rollingAnchor;
                $rangeEnd->modify('+1 hour');
                $rangeStart = clone $rollingAnchor;
                $rangeStart->modify('-23 hours');
            }
            if (!$rangeStart) {
                $rangeStart = new DateTime('-24 hours');
            }
            if (!$rangeEnd) {
                $rangeEnd = new DateTime();
            }

            $overrideWindows = hourly_fetch_maintenance_windows(
                $conn,
                $siteIds,
                $rangeStart->format('Y-m-d H:i:s'),
                $rangeEnd->format('Y-m-d H:i:s'),
                false
            );
            foreach ($overrideWindows as $window) {
                $sid = isset($window['site_id']) ? (int)$window['site_id'] : 0;
                if ($sid > 0) {
                    if (!isset($maintenanceOverrideBySite[$sid])) {
                        $maintenanceOverrideBySite[$sid] = [];
                    }
                    $maintenanceOverrideBySite[$sid][] = $window;
                } else {
                    $maintenanceOverrideGlobal[] = $window;
                }
            }

            $publicRangeStart = date('Y-m-d H:i:s', time() - 3600);
            $publicRangeEnd = date('Y-m-d H:i:s', time() + (30 * 24 * 3600));
            $publicWindows = hourly_fetch_maintenance_windows(
                $conn,
                $siteIds,
                $publicRangeStart,
                $publicRangeEnd,
                true
            );
            foreach ($publicWindows as $window) {
                $sid = isset($window['site_id']) ? (int)$window['site_id'] : 0;
                if ($sid > 0) {
                    if (!isset($maintenancePublicBySite[$sid])) {
                        $maintenancePublicBySite[$sid] = [];
                    }
                    $maintenancePublicBySite[$sid][] = $window;
                } else {
                    $maintenancePublicGlobal[] = $window;
                }
            }
        }

        foreach ($sitesData as &$site) {
            $sid = (int)($site['site_id'] ?? 0);
            $overrideWindows = $maintenanceOverrideGlobal;
            if ($sid > 0 && isset($maintenanceOverrideBySite[$sid])) {
                $overrideWindows = array_merge($overrideWindows, $maintenanceOverrideBySite[$sid]);
            }

            if (!empty($overrideWindows)) {
                foreach ($site['data'] as $slotIndex => $slot) {
                    $slotDate = (string)($slot['date'] ?? '');
                    $slotHour = isset($slot['hour']) ? (int)$slot['hour'] : null;
                    if ($slotDate === '' || $slotHour === null || $slotHour < 0 || $slotHour > 23) {
                        continue;
                    }
                    $slotStartTs = strtotime($slotDate . ' ' . str_pad((string)$slotHour, 2, '0', STR_PAD_LEFT) . ':00:00');
                    if ($slotStartTs === false) {
                        continue;
                    }
                    $slotEndTs = $slotStartTs + 3600;
                    foreach ($overrideWindows as $window) {
                        if (!hourly_window_overlaps_slot($window, (int)$slotStartTs, (int)$slotEndTs)) {
                            continue;
                        }
                        $site['data'][$slotIndex]['hasBeenOnline'] = 'MAINTENANCE';
                        $site['data'][$slotIndex]['minutes_offline'] = 0;
                        $site['data'][$slotIndex]['offline_seconds'] = 0;
                        $site['data'][$slotIndex]['maintenance'] = true;
                        break;
                    }
                }
            }

            $publicWindowsForSite = $maintenancePublicGlobal;
            if ($sid > 0 && isset($maintenancePublicBySite[$sid])) {
                $publicWindowsForSite = array_merge($publicWindowsForSite, $maintenancePublicBySite[$sid]);
            }
            usort($publicWindowsForSite, static function (array $a, array $b): int {
                $aStart = (int)($a['starts_ts'] ?? 0);
                $bStart = (int)($b['starts_ts'] ?? 0);
                if ($aStart === $bStart) {
                    return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
                }
                return $aStart <=> $bStart;
            });

            $nowTs = time();
            $currentWindow = null;
            $upcomingWindow = null;
            foreach ($publicWindowsForSite as $window) {
                $startsTs = isset($window['starts_ts']) ? (int)$window['starts_ts'] : 0;
                $endsTs = isset($window['ends_ts']) ? (int)$window['ends_ts'] : 0;
                if ($startsTs <= 0 || $endsTs <= 0 || $endsTs < $nowTs) {
                    continue;
                }
                if ($startsTs <= $nowTs && $endsTs >= $nowTs && $currentWindow === null) {
                    $currentWindow = $window;
                }
                if ($startsTs > $nowTs && $upcomingWindow === null) {
                    $upcomingWindow = $window;
                }
                if ($currentWindow !== null && $upcomingWindow !== null) {
                    break;
                }
            }

            $site['maintenance_active'] = $currentWindow !== null;
            $site['maintenance_current'] = $currentWindow ? hourly_export_maintenance_window($currentWindow) : null;
            $site['maintenance_upcoming'] = $upcomingWindow ? hourly_export_maintenance_window($upcomingWindow) : null;
            $site['maintenance_windows'] = array_map(
                static fn(array $window): array => hourly_export_maintenance_window($window),
                array_values($publicWindowsForSite)
            );
        }
        unset($site);

        if (count($siteIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
            $types = str_repeat('i', count($siteIds));
            $dailyDataBySite = [];
            $incidentsBySite = [];
            $sslBySite = hourly_fetch_latest_ssl_by_site($conn, $siteIds);
            $probeMetaBySite = hourly_fetch_latest_probe_meta_by_site($conn, $siteIds);

            if ($includeDailyData) {
                $dailyExtraSelect = "
                    , " . ($hasDailyTotalSeconds ? "ds.total_seconds" : "NULL AS total_seconds") . "
                    , " . ($hasDailyOfflineSeconds ? "ds.offline_seconds" : "NULL AS offline_seconds") . "
                    , " . ($hasDailyDegradedSeconds ? "ds.degraded_seconds" : "NULL AS degraded_seconds") . "
                    , " . ($hasDailyMaintenanceSeconds ? "ds.maintenance_seconds" : "NULL AS maintenance_seconds") . "
                    , " . ($hasDailyAvailabilityRatio ? "ds.availability_ratio" : "NULL AS availability_ratio") . "
                    , " . ($hasDailyHealthScore ? "ds.health_score" : "NULL AS health_score") . "
                    , " . ($hasDailyCalcMethod ? "ds.calc_method" : "NULL AS calc_method") . "
                ";
                $dailySql = "
                    SELECT ds.site_id, ds.date, ds.avg_response_time, ds.minutes_offline $dailyExtraSelect
                    FROM daily_stats ds
                    WHERE ds.site_id IN ($placeholders)
                    AND ds.date < CURDATE()
                    AND ds.date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)
                    ORDER BY ds.date DESC
                ";

                $dailyStmt = $conn->prepare($dailySql);
                if (!$dailyStmt) {
                    hourly_log($ctx, "Daily query prepare failed: {$conn->error}");
                } else {
                    $dailyStmt->bind_param($types, ...$siteIds);
                    if (!$dailyStmt->execute()) {
                        hourly_log($ctx, "Daily query execute failed: {$dailyStmt->error}");
                    } else {
                        $dailyResult = $dailyStmt->get_result();
                        while ($drow = $dailyResult->fetch_assoc()) {
                            $sid = (int)$drow['site_id'];
                            if (!isset($dailyDataBySite[$sid])) {
                                $dailyDataBySite[$sid] = [];
                            }
                            $dailyDataBySite[$sid][] = [
                                'date' => $drow['date'],
                                'avg_response_time' => (float)$drow['avg_response_time'],
                                'minutes_offline' => (int)$drow['minutes_offline'],
                                'total_seconds' => ($drow['total_seconds'] !== null && $drow['total_seconds'] !== '' && is_numeric($drow['total_seconds'])) ? (int)$drow['total_seconds'] : null,
                                'offline_seconds' => ($drow['offline_seconds'] !== null && $drow['offline_seconds'] !== '' && is_numeric($drow['offline_seconds'])) ? (int)$drow['offline_seconds'] : null,
                                'degraded_seconds' => ($drow['degraded_seconds'] !== null && $drow['degraded_seconds'] !== '' && is_numeric($drow['degraded_seconds'])) ? (int)$drow['degraded_seconds'] : null,
                                'maintenance_seconds' => ($drow['maintenance_seconds'] !== null && $drow['maintenance_seconds'] !== '' && is_numeric($drow['maintenance_seconds'])) ? (int)$drow['maintenance_seconds'] : null,
                                'availability_ratio' => ($drow['availability_ratio'] !== null && $drow['availability_ratio'] !== '' && is_numeric($drow['availability_ratio'])) ? (float)$drow['availability_ratio'] : null,
                                'health_score' => ($drow['health_score'] !== null && $drow['health_score'] !== '' && is_numeric($drow['health_score'])) ? (float)$drow['health_score'] : null,
                                'calc_method' => ($drow['calc_method'] !== null && $drow['calc_method'] !== '') ? strtolower(trim((string)$drow['calc_method'])) : null
                            ];
                        }
                    }
                    $dailyStmt->close();
                }
            }

            if ($includeIncidents) {
                $hasSourceMode = false;
                $hasIncidentCode = false;
                $sourceModeProbe = $conn->query("
                    SELECT 1 AS present
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'incidents'
                      AND COLUMN_NAME = 'source_mode'
                    LIMIT 1
                ");
                if ($sourceModeProbe) {
                    $hasSourceMode = (bool)$sourceModeProbe->fetch_assoc();
                    $sourceModeProbe->free();
                }
                $incidentCodeProbe = $conn->query("
                    SELECT 1 AS present
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME = 'incidents'
                      AND COLUMN_NAME = 'incident_code'
                    LIMIT 1
                ");
                if ($incidentCodeProbe) {
                    $hasIncidentCode = (bool)$incidentCodeProbe->fetch_assoc();
                    $incidentCodeProbe->free();
                }
                $hasIncidentUpdatedAt = hourly_has_column($conn, 'incidents', 'updated_at');
                $sourceModeSelect = $hasSourceMode ? ', source_mode' : ', NULL AS source_mode';
                $incidentCodeSelect = $hasIncidentCode ? ', incident_code' : ', NULL AS incident_code';
                $incidentUpdatedAtSelect = $hasIncidentUpdatedAt ? ', updated_at' : ', NULL AS updated_at';
                $incidentsSql = "
                    SELECT site_id, id, started_at, ended_at, http_code, postmortem, ai_created
                    $incidentCodeSelect
                    $sourceModeSelect
                    $incidentUpdatedAtSelect
                    FROM incidents
                    WHERE site_id IN ($placeholders)
                    ORDER BY site_id ASC, started_at DESC
                ";
                $incidentsStmt = $conn->prepare($incidentsSql);
                if (!$incidentsStmt) {
                    hourly_log($ctx, "Incidents query prepare failed: {$conn->error}");
                } else {
                    $incidentsStmt->bind_param($types, ...$siteIds);
                    if (!$incidentsStmt->execute()) {
                        hourly_log($ctx, "Incidents query execute failed: {$incidentsStmt->error}");
                    } else {
                        $incidentsResult = $incidentsStmt->get_result();
                        while ($incRow = $incidentsResult->fetch_assoc()) {
                            $sid = (int)$incRow['site_id'];
                            $incidentId = (int)$incRow['id'];
                            $incidentCode = trim((string)($incRow['incident_code'] ?? ''));
                            if ($incidentCode === '') {
                                $incidentCode = 'INC-' . str_pad((string)$incidentId, 6, '0', STR_PAD_LEFT);
                            }
                            if (!isset($incidentsBySite[$sid])) {
                                $incidentsBySite[$sid] = [];
                            }
                            $rawSourceMode = strtolower(trim((string)($incRow['source_mode'] ?? '')));
                            if (!in_array($rawSourceMode, ['manual', 'ai', 'system'], true)) {
                                $rawSourceMode = !empty($incRow['ai_created']) ? 'ai' : 'system';
                            }
                            $incidentPayload = [
                                'id' => $incidentId,
                                'incident_code' => $incidentCode,
                                'started_at' => $incRow['started_at'],
                                'ended_at' => $incRow['ended_at'],
                                'updated_at' => $incRow['updated_at'] ?? null,
                                'http_code' => isset($incRow['http_code']) ? (int)$incRow['http_code'] : null,
                                'postmortem' => $incRow['postmortem'],
                                'has_postmortem' => trim((string)($incRow['postmortem'] ?? '')) !== '',
                                'postmortem_state' => trim((string)($incRow['postmortem'] ?? '')) !== '' ? 'written' : 'unwritten',
                                'ai_created' => (bool)$incRow['ai_created'],
                                'source_mode' => $rawSourceMode
                            ];
                            $incidentsBySite[$sid][] = array_merge($incidentPayload, hourly_incident_confidence_fields($incidentPayload));
                        }
                    }
                    $incidentsStmt->close();
                }
            }

            foreach ($sitesData as &$site) {
                $sid = (int)$site['site_id'];
                $probeMeta = hourly_stats_site_probe_meta($site, $probeMetaBySite[$sid] ?? []);
                $site['probe_meta'] = $probeMeta;
                $site['checked_by'] = $probeMeta['checked_by'];
                $site['source_node'] = $probeMeta['source_node'];
                $site['last_error_type'] = $probeMeta['last_error_type'];
                $site['data_quality'] = hourly_stats_site_data_quality($site, $usedSitesFallback, $requestedDate);
                $site['daily_data'] = isset($dailyDataBySite[$sid]) ? $dailyDataBySite[$sid] : [];
                $site['incidents'] = isset($incidentsBySite[$sid]) ? $incidentsBySite[$sid] : [];
                $site['ssl'] = isset($sslBySite[$sid]) ? $sslBySite[$sid] : null;
                unset($site['site_id']);
            }
            unset($site);
        }

        hourly_log($ctx, "Returning data for " . count($sitesData) . " sites");
        $sitesPayload = array_values($sitesData);
        hourly_send_api_response($ctx, 'stats', $sitesPayload, 200, null, [
            'count' => count($sitesData),
            'include_daily' => $includeDailyData,
            'include_incidents' => $includeIncidents,
            'date' => $requestedDate,
            'data_quality' => hourly_stats_global_data_quality($sitesPayload)
        ]);
        return;
    }

    hourly_send_api_response($ctx, 'stats', [], 200, null, [
        'count' => 0,
        'include_daily' => $includeDailyData,
        'include_incidents' => $includeIncidents,
        'date' => $requestedDate,
        'data_quality' => hourly_stats_global_data_quality([])
    ]);
}
