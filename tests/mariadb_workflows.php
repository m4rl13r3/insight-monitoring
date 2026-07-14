<?php

declare(strict_types=1);

require dirname(__DIR__) . '/public/admin/_status_pages.php';
require dirname(__DIR__) . '/public/admin/_incidents.php';
require dirname(__DIR__) . '/public/admin/_maintenances.php';
require dirname(__DIR__) . '/public/admin/_oncall.php';
require dirname(__DIR__) . '/public/_status_page.php';

$config = require dirname(__DIR__) . '/public/config/config.php';
$user = insight_auth_dev_user();
$suffix = strtolower(bin2hex(random_bytes(5)));
$siteId = 0;
$hiddenSiteId = 0;
$channelId = 0;
$pageId = 0;
$incidentId = 0;
$hiddenIncidentId = 0;
$unpublishedIncidentId = 0;
$maintenanceId = 0;
$scheduleId = 0;
$diagnosticId = 0;

function insight_workflow_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function insight_workflow_request(string $url, ?array $fields = null, string $cookie = ''): array
{
    $request = curl_init($url);
    insight_workflow_expect($request !== false, 'Unable to initialize the workflow request.');
    curl_setopt_array($request, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($fields !== null) {
        curl_setopt($request, CURLOPT_POST, true);
        curl_setopt($request, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    if ($cookie !== '') {
        curl_setopt($request, CURLOPT_COOKIE, $cookie);
    }
    $response = curl_exec($request);
    insight_workflow_expect(is_string($response), 'The workflow HTTP request failed.');
    $status = (int)curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($request, CURLINFO_HEADER_SIZE);
    curl_close($request);
    return [
        'status' => $status,
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

try {
    $database = insight_probes_database($config);
    $siteUrl = "https://workflow-{$suffix}.example.test";
    $siteName = "Workflow {$suffix}";
    $site = $database->prepare("INSERT INTO sites (url,name,probe_type,active,public_visible) VALUES (?,?,'http',1,1)");
    insight_workflow_expect($site instanceof mysqli_stmt, 'Unable to prepare the workflow monitor.');
    $site->bind_param('ss', $siteUrl, $siteName);
    insight_workflow_expect($site->execute(), 'Unable to create the workflow monitor.');
    $siteId = (int)$site->insert_id;
    $site->close();
    $hiddenSiteUrl = "https://hidden-{$suffix}.example.test";
    $hiddenSiteName = "Hidden {$suffix}";
    $hiddenSite = $database->prepare("INSERT INTO sites (url,name,probe_type,active,public_visible) VALUES (?,?,'http',1,1)");
    insight_workflow_expect($hiddenSite instanceof mysqli_stmt, 'Unable to prepare the hidden workflow monitor.');
    $hiddenSite->bind_param('ss', $hiddenSiteUrl, $hiddenSiteName);
    insight_workflow_expect($hiddenSite->execute(), 'Unable to create the hidden workflow monitor.');
    $hiddenSiteId = (int)$hiddenSite->insert_id;
    $hiddenSite->close();

    $channelName = "Workflow {$suffix}";
    $provider = 'webhook';
    $enabled = 0;
    $ciphertext = '{}';
    $events = '["incident_open"]';
    $severity = 'major';
    $channel = $database->prepare('INSERT INTO notification_channels (name,provider,enabled,config_ciphertext,events_json,minimum_severity) VALUES (?,?,?,?,?,?)');
    insight_workflow_expect($channel instanceof mysqli_stmt, 'Unable to prepare the workflow channel.');
    $channel->bind_param('ssisss', $channelName, $provider, $enabled, $ciphertext, $events, $severity);
    insight_workflow_expect($channel->execute(), 'Unable to create the workflow channel.');
    $channelId = (int)$channel->insert_id;
    $channel->close();
    $errorCode = 'connection_timeout';
    $timingJson = '{"connect_ms":10000}';
    $headersJson = '{"content-type":"text/plain"}';
    $bodyExcerpt = 'Redacted diagnostic excerpt.';
    $networkJson = '{"trace":["192.0.2.1"]}';
    $diagnostic = $database->prepare("INSERT INTO probe_diagnostics (site_id,status,error_code,timing_json,response_headers_json,body_excerpt,network_json) VALUES (?,'offline',?,?,?,?,?)");
    insight_workflow_expect($diagnostic instanceof mysqli_stmt, 'Unable to prepare the workflow diagnostic.');
    $diagnostic->bind_param('isssss', $siteId, $errorCode, $timingJson, $headersJson, $bodyExcerpt, $networkJson);
    insight_workflow_expect($diagnostic->execute(), 'Unable to create the workflow diagnostic.');
    $diagnosticId = (int)$diagnostic->insert_id;
    $diagnostic->close();
    $database->close();

    $diagnosticResponse = insight_workflow_request("http://web/admin/probe-diagnostic.php?id={$diagnosticId}");
    $diagnosticPayload = json_decode($diagnosticResponse['body'], true);
    insight_workflow_expect($diagnosticResponse['status'] === 200 && ($diagnosticPayload['ok'] ?? false) === true, 'The private diagnostic endpoint is unavailable.');
    insight_workflow_expect((float)($diagnosticPayload['diagnostic']['timing']['connect_ms'] ?? 0) === 10000.0, 'The diagnostic timing is incomplete.');
    insight_workflow_expect(!array_key_exists('artifact_path', $diagnosticPayload['diagnostic'] ?? []), 'The diagnostic endpoint exposed its private storage path.');

    $page = insight_status_pages_create($config, [
        'name' => "Workflow {$suffix}",
        'slug' => "workflow-{$suffix}",
        'description' => 'Private integration status page.',
        'access_policy' => 'password',
        'password' => "Insight-{$suffix}-password",
        'theme' => 'dark',
        'accent_color' => '#0f766e',
        'logo_url' => '/assets/workflow-logo.svg',
        'favicon_url' => '/assets/workflow-favicon.svg',
        'announcement' => 'Workflow maintenance window.',
        'announcement_url' => '/maintenance',
        'navigation_links' => "Documentation | https://docs.example.com\nSupport | /support",
        'custom_css' => '.status-shell { max-width: 72rem; }',
        'history_days' => 45,
        'hide_from_search_engines' => true,
        'locale' => 'en',
        'enabled' => true,
        'site_ids' => [],
        'groups' => [[
            'name' => 'Production',
            'collapsed' => false,
            'site_ids' => [$siteId],
        ]],
    ]);
    insight_workflow_expect(($page['ok'] ?? false) === true, 'Unable to create the status page.');
    $pageId = (int)$page['id'];
    $pages = insight_status_pages_list($config);
    $storedPage = array_values(array_filter($pages['pages'] ?? [], static fn(array $item): bool => (int)$item['id'] === $pageId))[0] ?? null;
    insight_workflow_expect(is_array($storedPage), 'The created status page is missing.');
    insight_workflow_expect(($storedPage['has_password'] ?? false) === true, 'The private status page password is missing.');
    insight_workflow_expect(($storedPage['access_policy'] ?? '') === 'password', 'The status page access policy is incomplete.');
    insight_workflow_expect(($storedPage['logo_url'] ?? '') === '/assets/workflow-logo.svg', 'The status page branding is incomplete.');
    insight_workflow_expect((int)($storedPage['history_days'] ?? 0) === 45, 'The status page history limit is incomplete.');
    insight_workflow_expect(($storedPage['navigation_links'][0]['label'] ?? '') === 'Documentation', 'The status page navigation is incomplete.');
    insight_workflow_expect(count($storedPage['groups'][0]['site_ids'] ?? []) === 1, 'The status page layout is incomplete.');
    $privatePage = insight_workflow_request("http://web/?page=workflow-{$suffix}");
    insight_workflow_expect($privatePage['status'] === 200 && str_contains($privatePage['body'], 'status-private-form'), 'The private status page gate is unavailable.');
    $invalidPassword = insight_workflow_request('http://web/status-page-auth.php', ['page' => "workflow-{$suffix}", 'password' => 'invalid password']);
    insight_workflow_expect($invalidPassword['status'] === 303 && str_contains($invalidPassword['headers'], 'auth=failed'), 'The private status page accepted an invalid password.');
    $validPassword = insight_workflow_request('http://web/status-page-auth.php', ['page' => "workflow-{$suffix}", 'password' => "Insight-{$suffix}-password"]);
    insight_workflow_expect($validPassword['status'] === 303, 'The private status page rejected its password.');
    preg_match('/^Set-Cookie:\s*([^;]+)/mi', $validPassword['headers'], $cookieMatch);
    $privateCookie = (string)($cookieMatch[1] ?? '');
    insight_workflow_expect($privateCookie !== '', 'The private status page cookie is missing.');
    $authorizedPage = insight_workflow_request("http://web/?page=workflow-{$suffix}", null, $privateCookie);
    insight_workflow_expect($authorizedPage['status'] === 200 && !str_contains($authorizedPage['body'], 'status-private-form'), 'The private status page session is invalid.');
    insight_workflow_expect(str_contains($authorizedPage['body'], 'Workflow maintenance window.'), 'The status page announcement is missing.');
    insight_workflow_expect(str_contains($authorizedPage['body'], 'https://docs.example.com'), 'The status page navigation was not rendered.');
    insight_workflow_expect(str_contains($authorizedPage['body'], 'max-width: 72rem'), 'The status page custom style was not rendered.');
    insight_workflow_expect(str_contains($authorizedPage['body'], 'noindex'), 'The private status page can be indexed.');
    $pageUpdate = insight_status_pages_update($config, $pageId, [
        'name' => "Workflow public {$suffix}",
        'slug' => "workflow-{$suffix}",
        'description' => 'Public integration status page.',
        'visibility' => 'public',
        'password' => '',
        'theme' => 'system',
        'accent_color' => '#16a34a',
        'locale' => 'auto',
        'enabled' => true,
        'site_ids' => [],
        'groups' => [],
    ]);
    insight_workflow_expect(($pageUpdate['ok'] ?? false) === true, 'Unable to update the status page.');
    $database = insight_probes_database($config);
    $emptyPageUrls = insight_status_page_site_urls($database, ['id' => $pageId, 'slug' => "workflow-{$suffix}"]);
    $database->close();
    insight_workflow_expect($emptyPageUrls === [], 'An empty custom status page exposed unrelated monitors.');
    $pageUpdate = insight_status_pages_update($config, $pageId, [
        'name' => "Workflow public {$suffix}",
        'slug' => "workflow-{$suffix}",
        'description' => 'Public integration status page.',
        'visibility' => 'public',
        'password' => '',
        'theme' => 'system',
        'accent_color' => '#16a34a',
        'locale' => 'auto',
        'enabled' => true,
        'site_ids' => [$siteId],
        'groups' => [],
    ]);
    insight_workflow_expect(($pageUpdate['ok'] ?? false) === true, 'Unable to assign the status page monitor.');

    $incident = insight_incidents_create($config, [
        'title' => 'Workflow incident',
        'summary' => 'The integration monitor is being investigated.',
        'severity' => 'critical',
        'published' => true,
        'site_ids' => [$siteId],
    ], $user);
    insight_workflow_expect(($incident['ok'] ?? false) === true, 'Unable to create the incident.');
    $incidentId = (int)$incident['incident']['id'];
    $hiddenIncident = insight_incidents_create($config, [
        'title' => 'Unrelated workflow incident',
        'summary' => 'This incident belongs to another status page.',
        'severity' => 'major',
        'published' => true,
        'site_ids' => [$hiddenSiteId],
    ], $user);
    insight_workflow_expect(($hiddenIncident['ok'] ?? false) === true, 'Unable to create the unrelated incident.');
    $hiddenIncidentId = (int)$hiddenIncident['incident']['id'];
    $unpublishedIncident = insight_incidents_create($config, [
        'title' => 'Internal workflow incident',
        'summary' => 'This incident must remain private.',
        'severity' => 'minor',
        'published' => false,
        'site_ids' => [$siteId],
    ], $user);
    insight_workflow_expect(($unpublishedIncident['ok'] ?? false) === true, 'Unable to create the unpublished incident.');
    $unpublishedIncidentId = (int)$unpublishedIncident['incident']['id'];
    foreach ([
        ['action' => 'acknowledge', 'message' => 'The issue has been identified.', 'published' => true],
        ['action' => 'monitoring', 'message' => 'A fix has been deployed and is being monitored.', 'published' => true],
        ['action' => 'resolve', 'message' => 'The monitor is operational again.', 'published' => true],
    ] as $incidentAction) {
        $managed = insight_incidents_manage($config, $incidentId, $incidentAction, $user);
        insight_workflow_expect(($managed['ok'] ?? false) === true, 'Unable to advance the incident lifecycle.');
    }
    $postmortem = insight_incidents_update($config, $incidentId, ['postmortem' => 'Root cause confirmed and corrective action completed.']);
    insight_workflow_expect(($postmortem['ok'] ?? false) === true, 'Unable to save the incident postmortem.');
    $publicIncidentFeed = insight_workflow_request("http://web/hourly_stats_report.php?contract=v2&mode=incidents&page=workflow-{$suffix}");
    insight_workflow_expect($publicIncidentFeed['status'] === 200, 'The scoped public incident feed is unavailable.');
    $publicIncidentPayload = json_decode($publicIncidentFeed['body'], true);
    insight_workflow_expect(is_array($publicIncidentPayload), 'The scoped public incident feed is invalid.');
    $publicIncidentIds = array_map(static fn(array $item): int => (int)($item['id'] ?? 0), $publicIncidentPayload['data']['items'] ?? []);
    insight_workflow_expect(in_array($incidentId, $publicIncidentIds, true), 'The public incident is missing from its status page.');
    insight_workflow_expect(!in_array($hiddenIncidentId, $publicIncidentIds, true), 'A status page exposed an unrelated incident.');
    insight_workflow_expect(!in_array($unpublishedIncidentId, $publicIncidentIds, true), 'A status page exposed an unpublished incident.');
    $publicIncident = array_values(array_filter($publicIncidentPayload['data']['items'] ?? [], static fn(array $item): bool => (int)($item['id'] ?? 0) === $incidentId))[0] ?? null;
    insight_workflow_expect(count($publicIncident['updates'] ?? []) === 4, 'The public incident updates are incomplete.');

    $startsAt = new DateTimeImmutable('+1 hour', new DateTimeZone('UTC'));
    $endsAt = $startsAt->modify('+2 hours');
    $maintenance = insight_maintenances_create($config, [
        'title' => 'Workflow maintenance',
        'description' => 'Integration maintenance window.',
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'ends_at' => $endsAt->format('Y-m-d H:i:s'),
        'timezone' => 'UTC',
        'recurrence' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_until' => $endsAt->modify('+2 weeks')->format('Y-m-d H:i:s'),
        'status' => 'planned',
        'notify_public' => true,
        'site_ids' => [$siteId],
    ], $user);
    insight_workflow_expect(($maintenance['ok'] ?? false) === true, 'Unable to create the maintenance window.');
    $maintenanceId = (int)$maintenance['id'];
    $maintenanceUpdate = insight_maintenances_update($config, $maintenanceId, [
        'title' => 'Workflow maintenance updated',
        'description' => 'Updated integration maintenance window.',
        'starts_at' => $startsAt->format('Y-m-d H:i:s'),
        'ends_at' => $endsAt->format('Y-m-d H:i:s'),
        'timezone' => 'UTC',
        'recurrence' => 'none',
        'recurrence_interval' => 1,
        'status' => 'planned',
        'notify_public' => false,
        'site_ids' => [$siteId],
    ]);
    insight_workflow_expect(($maintenanceUpdate['ok'] ?? false) === true, 'Unable to update the maintenance window.');

    $shiftStart = new DateTimeImmutable('-1 hour', new DateTimeZone('UTC'));
    $shiftEnd = $shiftStart->modify('+8 hours');
    $schedule = insight_oncall_save($config, 0, [
        'name' => "Workflow rotation {$suffix}",
        'timezone' => 'UTC',
        'enabled' => true,
        'escalation_delay_minutes' => 0,
        'repeat_interval_minutes' => 5,
        'maximum_repeats' => 2,
        'minimum_severity' => 'major',
        'site_ids' => [$siteId],
        'members' => [[
            'name' => 'Integration operator',
            'channel_id' => $channelId,
            'starts_at' => $shiftStart->format('Y-m-d H:i:s'),
            'ends_at' => $shiftEnd->format('Y-m-d H:i:s'),
            'recurrence' => 'daily',
        ]],
    ]);
    insight_workflow_expect(($schedule['ok'] ?? false) === true, 'Unable to create the on-call schedule.');
    $scheduleId = (int)$schedule['id'];
    $oncallState = insight_oncall_state($config);
    $storedSchedule = array_values(array_filter($oncallState['schedules'] ?? [], static fn(array $item): bool => (int)$item['id'] === $scheduleId))[0] ?? null;
    insight_workflow_expect(is_array($storedSchedule), 'The on-call schedule is missing.');
    insight_workflow_expect(($storedSchedule['site_ids'] ?? []) === [$siteId], 'The on-call route is incomplete.');
    $memberId = (int)($storedSchedule['members'][0]['id'] ?? 0);
    insight_workflow_expect($memberId > 0, 'The on-call member is missing.');
    $scheduleUpdate = insight_oncall_save($config, $scheduleId, [
        'name' => "Workflow rotation updated {$suffix}",
        'timezone' => 'UTC',
        'enabled' => true,
        'escalation_delay_minutes' => 1,
        'repeat_interval_minutes' => 10,
        'maximum_repeats' => 3,
        'minimum_severity' => 'minor',
        'site_ids' => [$siteId],
        'members' => [[
            'id' => $memberId,
            'name' => 'Integration operator updated',
            'channel_id' => $channelId,
            'starts_at' => $shiftStart->format('Y-m-d H:i:s'),
            'ends_at' => $shiftEnd->format('Y-m-d H:i:s'),
            'recurrence' => 'daily',
        ]],
    ]);
    insight_workflow_expect(($scheduleUpdate['ok'] ?? false) === true, 'Unable to update the on-call schedule.');
    $updatedState = insight_oncall_state($config);
    $updatedSchedule = array_values(array_filter($updatedState['schedules'] ?? [], static fn(array $item): bool => (int)$item['id'] === $scheduleId))[0] ?? null;
    insight_workflow_expect((int)($updatedSchedule['members'][0]['id'] ?? 0) === $memberId, 'The on-call member identity changed during the update.');

    $database = insight_probes_database($config);
    $verification = $database->query("SELECT i.lifecycle_status, i.postmortem, COUNT(u.id) AS updates_count FROM incidents i LEFT JOIN incident_updates u ON u.incident_id=i.id WHERE i.id={$incidentId} GROUP BY i.id");
    $storedIncident = $verification instanceof mysqli_result ? $verification->fetch_assoc() : null;
    insight_workflow_expect(($storedIncident['lifecycle_status'] ?? '') === 'resolved', 'The incident was not resolved.');
    insight_workflow_expect((int)($storedIncident['updates_count'] ?? 0) === 4, 'The public incident timeline is incomplete.');
    insight_workflow_expect(($storedIncident['postmortem'] ?? '') !== '', 'The incident postmortem is missing.');
    $database->close();
} finally {
    if ($scheduleId > 0) {
        insight_oncall_delete($config, $scheduleId);
    }
    if ($maintenanceId > 0) {
        insight_maintenances_delete($config, $maintenanceId);
    }
    if ($incidentId > 0) {
        insight_incidents_delete($config, $incidentId);
    }
    if ($hiddenIncidentId > 0) {
        insight_incidents_delete($config, $hiddenIncidentId);
    }
    if ($unpublishedIncidentId > 0) {
        insight_incidents_delete($config, $unpublishedIncidentId);
    }
    if ($pageId > 0) {
        insight_status_pages_delete($config, $pageId);
    }
    if ($channelId > 0 || $siteId > 0 || $hiddenSiteId > 0) {
        $database = insight_probes_database($config);
        if ($channelId > 0) {
            $database->query('DELETE FROM notification_channels WHERE id=' . $channelId);
        }
        if ($siteId > 0) {
            $database->query('DELETE FROM sites WHERE id=' . $siteId);
        }
        if ($hiddenSiteId > 0) {
            $database->query('DELETE FROM sites WHERE id=' . $hiddenSiteId);
        }
        $database->close();
    }
}

echo "MariaDB production workflows passed.\n";
