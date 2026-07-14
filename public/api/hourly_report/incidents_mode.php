<?php

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function hourly_handle_incidents_mode(array $ctx) {
    if ($ctx['mode'] !== 'incidents') {
        return false;
    }

    $conn = $ctx['conn'];
    $siteUrls = $ctx['siteUrls'];
    $incidentsLimit = $ctx['incidentsLimit'];
    $incidentsOffset = $ctx['incidentsOffset'];

    $restrictSiteUrls = !empty($ctx['restrictSiteUrls']);
    $sitePlaceholders = implode(',', array_fill(0, count($siteUrls), '?'));
    $where = ['i.published = 1'];
    $siteParams = [];
    if ($restrictSiteUrls) {
        if ($siteUrls === []) {
            $where[] = '1 = 0';
        } else {
            $where[] = "(s.url IN ($sitePlaceholders) OR affected.url IN ($sitePlaceholders))";
            $siteParams = array_merge($siteUrls, $siteUrls);
        }
    }
    $siteFilter = 'WHERE ' . implode(' AND ', $where);
    $hasSourceMode = false;
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
    $hasIncidentCode = false;
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
    $hasUpdatedAt = false;
    $updatedAtProbe = $conn->query("
        SELECT 1 AS present
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'incidents'
          AND COLUMN_NAME = 'updated_at'
        LIMIT 1
    ");
    if ($updatedAtProbe) {
        $hasUpdatedAt = (bool)$updatedAtProbe->fetch_assoc();
        $updatedAtProbe->free();
    }
    $sourceModeSelect = $hasSourceMode ? ", i.source_mode" : ", NULL AS source_mode";
    $incidentCodeSelect = $hasIncidentCode ? ", i.incident_code" : ", NULL AS incident_code";
    $updatedAtSelect = $hasUpdatedAt ? ", i.updated_at" : ", NULL AS updated_at";
    $incidentsSql = "
        SELECT
            s.url AS primary_url,
            GROUP_CONCAT(DISTINCT affected.url ORDER BY affected.id SEPARATOR ',') AS affected_urls,
            i.id,
            i.title,
            i.summary,
            i.severity,
            i.lifecycle_status,
            i.started_at,
            i.ended_at,
            i.http_code,
            i.postmortem,
            i.ai_created
            $incidentCodeSelect
            $sourceModeSelect
            $updatedAtSelect
        FROM incidents i
        LEFT JOIN sites s ON i.site_id = s.id
        LEFT JOIN incident_sites mapping ON mapping.incident_id = i.id
        LEFT JOIN sites affected ON affected.id = mapping.site_id
        $siteFilter
        GROUP BY i.id
        ORDER BY i.started_at DESC
        LIMIT ? OFFSET ?
    ";

    $incStmt = $conn->prepare($incidentsSql);
    if (!$incStmt) {
        hourly_log($ctx, "incidents prepare failed: {$conn->error}");
        hourly_send_api_response($ctx, 'incidents', [
            'items' => [],
            'has_more' => false,
            'next_offset' => $incidentsOffset
        ], 500, [
            'code' => 'incidents_prepare_failed',
            'message' => 'Failed to prepare incidents query.'
        ]);
        return true;
    }

    $types = str_repeat('s', count($siteParams)) . 'ii';
    $fetchLimitPlusOne = $incidentsLimit + 1;
    $params = array_merge($siteParams, [$fetchLimitPlusOne, $incidentsOffset]);
    $incStmt->bind_param($types, ...$params);
    if (!$incStmt->execute()) {
        hourly_log($ctx, "incidents execute failed: {$incStmt->error}");
        $incStmt->close();
        hourly_send_api_response($ctx, 'incidents', [
            'items' => [],
            'has_more' => false,
            'next_offset' => $incidentsOffset
        ], 500, [
            'code' => 'incidents_execute_failed',
            'message' => 'Failed to execute incidents query.'
        ]);
        return true;
    }

    $incResult = $incStmt->get_result();
    $incidents = [];
    $incidentIndexes = [];
    $allowedSiteUrls = array_fill_keys(array_map(static fn($url): string => (string)$url, $siteUrls), true);
    while ($incRow = $incResult->fetch_assoc()) {
        $incidentId = (int)$incRow['id'];
        $incidentCode = trim((string)($incRow['incident_code'] ?? ''));
        if ($incidentCode === '') {
            $incidentCode = 'INC-' . str_pad((string)$incidentId, 6, '0', STR_PAD_LEFT);
        }

        $postmortem = (string)($incRow['postmortem'] ?? '');
        $hasPostmortem = trim($postmortem) !== '';
        $aiCreated = (bool)($incRow['ai_created'] ?? false);
        $rawSourceMode = strtolower(trim((string)($incRow['source_mode'] ?? '')));
        if (!in_array($rawSourceMode, ['manual', 'ai', 'system'], true)) {
            $rawSourceMode = $aiCreated ? 'ai' : 'system';
        }

        $incidentDate = trim((string)($incRow['started_at'] ?? ''));
        if ($incidentDate === '') {
            $incidentDate = trim((string)($incRow['ended_at'] ?? ''));
        }
        $candidateUrls = array_values(array_filter(array_unique(array_merge(
            [(string)($incRow['primary_url'] ?? '')],
            explode(',', (string)($incRow['affected_urls'] ?? ''))
        ))));
        if ($restrictSiteUrls) {
            $candidateUrls = array_values(array_filter($candidateUrls, static fn(string $url): bool => isset($allowedSiteUrls[$url])));
        }
        $incidentPayload = [
            'url' => $candidateUrls[0] ?? 'All services',
            'id' => $incidentId,
            'incident_code' => $incidentCode,
            'title' => (string)($incRow['title'] ?? ''),
            'summary' => (string)($incRow['summary'] ?? ''),
            'severity' => (string)($incRow['severity'] ?? 'major'),
            'lifecycle_status' => (string)($incRow['lifecycle_status'] ?? ''),
            'affected_sites' => $candidateUrls,
            'updates' => [],
            'started_at' => $incRow['started_at'],
            'ended_at' => $incRow['ended_at'],
            'updated_at' => $incRow['updated_at'] ?? null,
            'incident_date' => $incidentDate,
            'http_code' => isset($incRow['http_code']) ? (int)$incRow['http_code'] : null,
            'postmortem' => $postmortem,
            'has_postmortem' => $hasPostmortem,
            'postmortem_state' => $hasPostmortem ? 'written' : 'unwritten',
            'ai_created' => $aiCreated,
            'source_mode' => $rawSourceMode,
        ];
        $incidentIndexes[$incidentId] = count($incidents);
        $incidents[] = array_merge($incidentPayload, hourly_incident_confidence_fields($incidentPayload));
    }
    $incStmt->close();

    if ($incidentIndexes !== []) {
        $incidentIds = array_keys($incidentIndexes);
        $updatePlaceholders = implode(',', array_fill(0, count($incidentIds), '?'));
        $updatesStatement = $conn->prepare("SELECT incident_id,id,lifecycle_status AS status,message,author_name,created_at FROM incident_updates WHERE is_public=1 AND incident_id IN ($updatePlaceholders) ORDER BY created_at ASC,id ASC");
        if ($updatesStatement) {
            $updatesStatement->bind_param(str_repeat('i', count($incidentIds)), ...$incidentIds);
            $updatesStatement->execute();
            $updatesResult = $updatesStatement->get_result();
            while ($updatesResult && ($update = $updatesResult->fetch_assoc())) {
                $targetIndex = $incidentIndexes[(int)$update['incident_id']] ?? null;
                unset($update['incident_id']);
                if ($targetIndex !== null) {
                    $incidents[$targetIndex]['updates'][] = $update;
                }
            }
            $updatesStatement->close();
        }
    }

    $hasMore = count($incidents) > $incidentsLimit;
    if ($hasMore) {
        $incidents = array_slice($incidents, 0, $incidentsLimit);
    }

    $nextOffset = $incidentsOffset + count($incidents);
    if (($ctx['responseFormat'] ?? 'json') === 'rss') {
        hourly_send_incidents_rss_response($ctx, $incidents);
        hourly_log($ctx, "incidents rss mode: returned " . count($incidents) . " rows");
        return true;
    }
    hourly_send_api_response($ctx, 'incidents', [
        'items' => $incidents,
        'has_more' => $hasMore,
        'next_offset' => $nextOffset
    ], 200, null, [
        'limit' => $incidentsLimit,
        'offset' => $incidentsOffset,
        'returned' => count($incidents),
        'has_more' => $hasMore,
        'next_offset' => $nextOffset
    ]);
    hourly_log($ctx, "incidents mode: returned " . count($incidents) . " rows");
    return true;
}
