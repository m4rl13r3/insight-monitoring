<?php

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

function hourly_normalize_origin($origin) {
    $parts = parse_url(trim((string)$origin));
    if (!is_array($parts)) {
        return '';
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return '';
    }
    $port = isset($parts['port']) ? (int)$parts['port'] : 0;
    $defaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    return $scheme . '://' . $host . ($port > 0 && !$defaultPort ? ':' . $port : '');
}

function hourly_is_allowed_origin($origin, $allowedOrigins) {
    $normalized = hourly_normalize_origin($origin);
    if ($normalized === '') {
        return false;
    }
    foreach ($allowedOrigins as $allowedOrigin) {
        if (hash_equals((string)$allowedOrigin, $normalized)) {
            return true;
        }
    }
    return false;
}

function hourly_query_bool($key, $default = false) {
    if (!isset($_GET[$key])) {
        return $default;
    }
    $value = strtolower(trim((string)$_GET[$key]));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function hourly_query_int($key, $default, $min, $max) {
    if (!isset($_GET[$key])) {
        return $default;
    }
    $value = filter_var($_GET[$key], FILTER_VALIDATE_INT);
    if ($value === false) {
        return $default;
    }
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }
    return $value;
}

function hourly_incident_duration_label(?int $durationSeconds): string {
    if ($durationSeconds === null || $durationSeconds < 0) {
        return 'unknown duration';
    }
    if ($durationSeconds < 60) {
        return $durationSeconds . ' s';
    }
    $minutes = (int)floor($durationSeconds / 60);
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    $hours = (int)floor($minutes / 60);
    $remainingMinutes = $minutes % 60;
    return $remainingMinutes > 0 ? $hours . ' h ' . $remainingMinutes . ' min' : $hours . ' h';
}

function hourly_incident_confidence_fields(array $incident): array {
    $sourceMode = strtolower(trim((string)($incident['source_mode'] ?? 'system')));
    if (!in_array($sourceMode, ['manual', 'ai', 'system'], true)) {
        $sourceMode = !empty($incident['ai_created']) ? 'ai' : 'system';
    }

    $score = 35;
    $facts = [];
    if ($sourceMode === 'manual') {
        $score += 30;
        $facts[] = 'manual source';
    } elseif ($sourceMode === 'system') {
        $score += 25;
        $facts[] = 'system source';
    } else {
        $score += 15;
        $facts[] = 'AI source';
    }

    $httpCode = isset($incident['http_code']) && is_numeric($incident['http_code']) ? (int)$incident['http_code'] : null;
    if ($httpCode !== null && $httpCode >= 500) {
        $score += 15;
        $facts[] = 'code HTTP ' . $httpCode;
    } elseif ($httpCode !== null && $httpCode >= 400) {
        $score += 10;
        $facts[] = 'code HTTP ' . $httpCode;
    } elseif ($httpCode !== null && $httpCode > 0) {
        $score += 4;
        $facts[] = 'code HTTP ' . $httpCode;
    } else {
        $score -= 5;
        $facts[] = 'missing HTTP code';
    }

    $startedTs = null;
    $endedTs = null;
    $startedAt = trim((string)($incident['started_at'] ?? ''));
    $endedAt = trim((string)($incident['ended_at'] ?? ''));
    if ($startedAt !== '') {
        $tmp = strtotime($startedAt);
        if ($tmp !== false) {
            $startedTs = (int)$tmp;
        }
    }
    if ($endedAt !== '') {
        $tmp = strtotime($endedAt);
        if ($tmp !== false) {
            $endedTs = (int)$tmp;
        }
    }

    $durationSeconds = null;
    if ($startedTs !== null) {
        $durationSeconds = ($endedTs ?? time()) - $startedTs;
        if ($durationSeconds >= 3600) {
            $score += 15;
        } elseif ($durationSeconds >= 300) {
            $score += 10;
        } elseif ($durationSeconds >= 60) {
            $score += 5;
        }
        $facts[] = 'duration ' . hourly_incident_duration_label($durationSeconds);
    }

    $hasPostmortem = !empty($incident['has_postmortem']) || trim((string)($incident['postmortem'] ?? '')) !== '';
    if ($hasPostmortem) {
        $score += 10;
        $facts[] = 'postmortem available';
    }

    $score = max(0, min(100, $score));
    $confidence = $score >= 75 ? 'high' : ($score >= 50 ? 'medium' : 'low');
    $lastSeenAt = trim((string)($incident['last_seen_at'] ?? ''));
    if ($lastSeenAt === '') {
        $lastSeenAt = trim((string)($incident['updated_at'] ?? ''));
    }
    if ($lastSeenAt === '') {
        $lastSeenAt = $endedAt !== '' ? $endedAt : $startedAt;
    }

    return [
        'incident_confidence' => $confidence,
        'incident_confidence_score' => $score,
        'reason' => implode(' · ', $facts),
        'source_count' => 1,
        'last_seen_at' => $lastSeenAt !== '' ? $lastSeenAt : null
    ];
}

function hourly_log_file($logFile, $message) {
    $dir = dirname((string)$logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    file_put_contents($logFile, "[" . date("Y-m-d H:i:s") . "] " . $message . "\n", FILE_APPEND);
}

function hourly_log(array $ctx, $message) {
    if (isset($ctx['reportLogFile'])) {
        hourly_log_file($ctx['reportLogFile'], $message);
    }
}

function hourly_send_api_response(array $ctx, $mode, $legacyPayload, $statusCode = 200, $error = null, $meta = []) {
    $useV2Contract = !empty($ctx['useV2Contract']);
    if (!$useV2Contract) {
        http_response_code(200);
        echo json_encode($legacyPayload, JSON_PRETTY_PRINT);
        return;
    }

    http_response_code($statusCode);
    $response = [
        'contract' => 'v2',
        'version' => $ctx['apiVersion'],
        'request_id' => $ctx['requestId'],
        'mode' => $mode,
        'success' => $error === null,
        'data' => $legacyPayload,
        'meta' => empty($meta) ? (object)[] : $meta
    ];
    if ($error !== null) {
        $response['error'] = $error;
    }

    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function hourly_xml_escape($value) {
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function hourly_rss_date($value) {
    $timestamp = strtotime((string)$value);
    if (!$timestamp) {
        $timestamp = time();
    }
    return date(DATE_RSS, $timestamp);
}

function hourly_send_incidents_rss_response(array $ctx, array $incidents) {
    http_response_code(200);
    header('Content-Type: application/rss+xml; charset=utf-8');
    $requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/hourly_stats_report.php';
    $cfg = is_array($ctx['config'] ?? null) ? $ctx['config'] : [];
    $publicUrl = is_array($cfg) ? rtrim((string)($cfg['public_url'] ?? ''), '/') : '';
    if ($publicUrl === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $publicUrl = $scheme . '://' . $host;
    }
    $appName = is_array($cfg) ? trim((string)($cfg['app_name'] ?? 'Insight')) : 'Insight';
    $channelLink = $publicUrl . $requestUri;
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<rss version=\"2.0\">\n";
    echo "  <channel>\n";
    echo "    <title>Incidents " . hourly_xml_escape($appName) . "</title>\n";
    echo "    <link>" . hourly_xml_escape($channelLink) . "</link>\n";
    echo "    <description>Public incident feed for monitored services.</description>\n";
    echo "    <lastBuildDate>" . date(DATE_RSS) . "</lastBuildDate>\n";
    foreach ($incidents as $incident) {
        $id = isset($incident['id']) ? (int)$incident['id'] : 0;
        $code = trim((string)($incident['incident_code'] ?? ''));
        $url = trim((string)($incident['url'] ?? 'Service'));
        $startedAt = (string)($incident['started_at'] ?? '');
        $endedAt = trim((string)($incident['ended_at'] ?? ''));
        $state = $endedAt !== '' ? 'Resolved' : 'Ongoing';
        $title = trim($code . ' - ' . $state . ' - ' . preg_replace('#^https?://#', '', $url));
        $description = trim((string)($incident['postmortem'] ?? ''));
        if ($description === '') {
            $description = $state === 'Resolved' ? 'Incident resolved.' : 'Incident under investigation.';
        }
        $guid = $publicUrl . '/incidents/' . ($id > 0 ? $id : sha1($title . $startedAt));
        echo "    <item>\n";
        echo "      <title>" . hourly_xml_escape($title) . "</title>\n";
        echo "      <link>" . hourly_xml_escape($channelLink) . "</link>\n";
        echo "      <guid isPermaLink=\"false\">" . hourly_xml_escape($guid) . "</guid>\n";
        echo "      <pubDate>" . hourly_rss_date($startedAt) . "</pubDate>\n";
        echo "      <description>" . hourly_xml_escape($description) . "</description>\n";
        echo "    </item>\n";
    }
    echo "  </channel>\n";
    echo "</rss>\n";
}
