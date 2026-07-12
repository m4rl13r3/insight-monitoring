<?php

declare(strict_types=1);

require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_probes.php';
require_once __DIR__ . '/_notifications.php';
require_once __DIR__ . '/_oidc.php';

$user = insight_auth_require_user();

function insight_dashboard_rows(mysqli $database, string $query): array
{
    $result = $database->query($query);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Lecture des données impossible.');
    }
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    return $rows;
}

function insight_dashboard_table_exists(mysqli $database, string $table): bool
{
    $escaped = $database->real_escape_string($table);
    $result = $database->query("SHOW TABLES LIKE '{$escaped}'");
    if (!$result instanceof mysqli_result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function insight_dashboard_demo_data(): array
{
    $now = new DateTimeImmutable('now');
    $data = [
        'mode' => 'preview',
        'monitors' => [
            [
                'id' => 1,
                'url' => 'https://example.com',
                'probe_type' => 'http',
                'probe_interval_sec' => 60,
                'status' => 'online',
                'response_time' => 84,
                'http_code' => 200,
                'checked_at' => $now->modify('-28 seconds')->format(DATE_ATOM),
            ],
            [
                'id' => 2,
                'url' => 'https://status.example.com',
                'probe_type' => 'http',
                'probe_interval_sec' => 60,
                'status' => 'online',
                'response_time' => 121,
                'http_code' => 200,
                'checked_at' => $now->modify('-41 seconds')->format(DATE_ATOM),
            ],
            [
                'id' => 3,
                'url' => 'https://api.example.com',
                'probe_type' => 'http',
                'probe_interval_sec' => 30,
                'status' => 'degraded',
                'response_time' => 742,
                'http_code' => 503,
                'checked_at' => $now->modify('-17 seconds')->format(DATE_ATOM),
            ],
        ],
        'servers' => [
            [
                'id' => 4,
                'url' => 'edge-paris.example.com',
                'probe_type' => 'icmp',
                'probe_interval_sec' => 60,
                'status' => 'online',
                'response_time' => 14,
                'http_code' => null,
                'checked_at' => $now->modify('-19 seconds')->format(DATE_ATOM),
            ],
            [
                'id' => 5,
                'url' => 'backup.example.com:22',
                'probe_type' => 'tcp',
                'probe_interval_sec' => 60,
                'status' => 'offline',
                'response_time' => null,
                'http_code' => null,
                'checked_at' => $now->modify('-34 seconds')->format(DATE_ATOM),
            ],
        ],
        'incidents' => [
            [
                'id' => 101,
                'url' => 'https://api.example.com',
                'started_at' => $now->modify('-42 minutes')->format(DATE_ATOM),
                'ended_at' => null,
                'http_code' => 503,
                'source_mode' => 'system',
                'postmortem' => 'Des réponses intermittentes sont en cours d’analyse.',
            ],
            [
                'id' => 100,
                'url' => 'https://status.example.com',
                'started_at' => $now->modify('-5 hours')->format(DATE_ATOM),
                'ended_at' => $now->modify('-4 hours 18 minutes')->format(DATE_ATOM),
                'http_code' => 522,
                'source_mode' => 'ai',
                'postmortem' => 'Le temps de réponse est revenu à son niveau habituel.',
            ],
        ],
        'open_incidents' => 1,
        'maintenances' => [
            [
                'id' => 12,
                'url' => 'https://example.com',
                'title' => 'Mise à jour applicative',
                'description' => 'Déploiement planifié de la prochaine version.',
                'starts_at' => $now->modify('+1 day 2 hours')->format(DATE_ATOM),
                'ends_at' => $now->modify('+1 day 2 hours 30 minutes')->format(DATE_ATOM),
                'status' => 'planned',
            ],
        ],
        'runtime' => [
            'active_engine' => 'consensus',
            'is_degraded' => 0,
            'monitor_last_ok' => 1,
            'sites_checked' => 5,
            'errors_count' => 1,
            'last_monitor_at' => $now->modify('-17 seconds')->format(DATE_ATOM),
            'last_hourly_at' => $now->modify('-18 minutes')->format(DATE_ATOM),
            'last_daily_at' => $now->setTime(0, 4)->format(DATE_ATOM),
        ],
        'distributed_mode' => 'hub',
        'nodes' => [
            [
                'node_key' => 'paris-1',
                'display_name' => 'Paris 1',
                'region' => 'fr-par',
                'zone' => 'fr-par-1',
                'status' => 'active',
                'connectivity_status' => 'online',
                'is_live' => 1,
                'assignments' => 3,
                'last_seen_at' => $now->modify('-8 seconds')->format(DATE_ATOM),
            ],
            [
                'node_key' => 'frankfurt-1',
                'display_name' => 'Francfort 1',
                'region' => 'de-fra',
                'zone' => 'de-fra-1',
                'status' => 'active',
                'connectivity_status' => 'online',
                'is_live' => 1,
                'assignments' => 3,
                'last_seen_at' => $now->modify('-13 seconds')->format(DATE_ATOM),
            ],
            [
                'node_key' => 'montreal-1',
                'display_name' => 'Montréal 1',
                'region' => 'ca-yul',
                'zone' => 'ca-yul-1',
                'status' => 'active',
                'connectivity_status' => 'online',
                'is_live' => 1,
                'assignments' => 3,
                'last_seen_at' => $now->modify('-21 seconds')->format(DATE_ATOM),
            ],
        ],
        'consensus' => [
            [
                'site_id' => 1,
                'url' => 'https://example.com',
                'status' => 'online',
                'nodes_expected' => 3,
                'nodes_fresh' => 3,
                'nodes_online' => 3,
                'nodes_offline' => 0,
                'nodes_degraded' => 0,
                'nodes_missing' => 0,
                'confidence' => 1,
                'response_median_ms' => 84,
                'evaluated_at' => $now->modify('-8 seconds')->format(DATE_ATOM),
            ],
            [
                'site_id' => 2,
                'url' => 'https://status.example.com',
                'status' => 'online',
                'nodes_expected' => 3,
                'nodes_fresh' => 3,
                'nodes_online' => 3,
                'nodes_offline' => 0,
                'nodes_degraded' => 0,
                'nodes_missing' => 0,
                'confidence' => 1,
                'response_median_ms' => 121,
                'evaluated_at' => $now->modify('-13 seconds')->format(DATE_ATOM),
            ],
            [
                'site_id' => 3,
                'url' => 'https://api.example.com',
                'status' => 'degraded',
                'nodes_expected' => 3,
                'nodes_fresh' => 3,
                'nodes_online' => 2,
                'nodes_offline' => 1,
                'nodes_degraded' => 0,
                'nodes_missing' => 0,
                'confidence' => 0.66667,
                'response_median_ms' => 742,
                'evaluated_at' => $now->modify('-17 seconds')->format(DATE_ATOM),
            ],
        ],
    ];
    foreach (insight_probes_preview_rows() as $probe) {
        $probeType = strtolower((string)($probe['probe_type'] ?? 'http'));
        $bucket = in_array($probeType, ['icmp', 'ping', 'tcp'], true) ? 'servers' : 'monitors';
        $data[$bucket][] = $probe;
    }
    return $data;
}

function insight_dashboard_load_data(array $config): array
{
    if (!extension_loaded('mysqli')) {
        return insight_dashboard_demo_data();
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $database = mysqli_init();
    if (!$database instanceof mysqli) {
        return insight_dashboard_demo_data();
    }
    $database->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
    $connected = @$database->real_connect(
        (string)$config['servername'],
        (string)$config['username'],
        (string)$config['password'],
        (string)$config['dbname'],
        (int)$config['port']
    );
    if (!$connected) {
        $database->close();
        return insight_dashboard_demo_data();
    }
    try {
        $database->set_charset('utf8mb4');
        $allMonitors = insight_dashboard_rows($database, "
            SELECT
                s.id,
                s.url,
                s.probe_type,
                s.probe_interval_sec,
                p.status,
                p.response_time,
                p.http_code,
                p.checked_at
            FROM sites s
            LEFT JOIN probes p ON p.id = (
                SELECT p2.id
                FROM probes p2
                WHERE p2.site_id = s.id
                ORDER BY p2.checked_at DESC, p2.id DESC
                LIMIT 1
            )
            ORDER BY s.id ASC
        ");
        if ($allMonitors === []) {
            $database->close();
            return insight_dashboard_demo_data();
        }
        $serverProbeTypes = ['icmp', 'ping', 'tcp'];
        $servers = array_values(array_filter(
            $allMonitors,
            static fn(array $monitor): bool => in_array(strtolower((string)($monitor['probe_type'] ?? '')), $serverProbeTypes, true)
        ));
        $monitors = array_values(array_filter(
            $allMonitors,
            static fn(array $monitor): bool => !in_array(strtolower((string)($monitor['probe_type'] ?? '')), $serverProbeTypes, true)
        ));
        $incidents = insight_dashboard_rows($database, "
            SELECT
                i.id,
                COALESCE(s.url, i.site_label, 'Service') AS url,
                i.started_at,
                i.ended_at,
                i.http_code,
                i.source_mode,
                i.postmortem
            FROM incidents i
            LEFT JOIN sites s ON s.id = i.site_id
            ORDER BY i.started_at DESC
            LIMIT 6
        ");
        $openIncidentsResult = $database->query(
            'SELECT COUNT(*) FROM incidents WHERE ended_at IS NULL AND (resolved IS NULL OR resolved = 0)'
        );
        $openIncidents = $openIncidentsResult instanceof mysqli_result
            ? (int)$openIncidentsResult->fetch_row()[0]
            : 0;
        if ($openIncidentsResult instanceof mysqli_result) {
            $openIncidentsResult->free();
        }
        $maintenances = insight_dashboard_rows($database, "
            SELECT
                m.id,
                COALESCE(s.url, 'Tous les services') AS url,
                m.title,
                m.description,
                m.starts_at,
                m.ends_at,
                m.status
            FROM scheduled_maintenances m
            LEFT JOIN sites s ON s.id = m.site_id
            WHERE m.status = 'planned' AND m.ends_at >= NOW()
            ORDER BY m.starts_at ASC
            LIMIT 5
        ");
        $runtimeRows = insight_dashboard_rows(
            $database,
            'SELECT * FROM monitoring_public_runtime_state WHERE singleton_id = 1 LIMIT 1'
        );
        $nodes = [];
        $consensus = [];
        if (
            insight_dashboard_table_exists($database, 'monitoring_nodes')
            && insight_dashboard_table_exists($database, 'monitoring_assignments')
        ) {
            $nodeTtl = max(30, min(86400, (int)(getenv('INSIGHT_AGENT_NODE_TTL_SEC') ?: 180)));
            $nodes = insight_dashboard_rows($database, "
                SELECT
                    n.node_key,
                    n.display_name,
                    COALESCE(n.region, '') AS region,
                    COALESCE(n.zone, '') AS zone,
                    n.status,
                    n.connectivity_status,
                    n.last_seen_at,
                    IF(n.status = 'active' AND n.last_seen_at >= DATE_SUB(NOW(), INTERVAL {$nodeTtl} SECOND), 1, 0) AS is_live,
                    (SELECT COUNT(*) FROM monitoring_assignments a WHERE a.node_id = n.id AND a.active = 1) AS assignments
                FROM monitoring_nodes n
                ORDER BY is_live DESC, n.display_name ASC
            ");
        }
        if (insight_dashboard_table_exists($database, 'monitoring_consensus_current')) {
            $consensus = insight_dashboard_rows($database, "
                SELECT
                    c.site_id,
                    s.url,
                    c.status,
                    c.nodes_expected,
                    c.nodes_fresh,
                    c.nodes_online,
                    c.nodes_offline,
                    c.nodes_degraded,
                    c.nodes_missing,
                    c.confidence,
                    c.response_median_ms,
                    c.evaluated_at
                FROM monitoring_consensus_current c
                INNER JOIN sites s ON s.id = c.site_id
                ORDER BY s.id
            ");
        }
        $database->close();
        return [
            'mode' => 'database',
            'monitors' => $monitors,
            'servers' => $servers,
            'incidents' => $incidents,
            'open_incidents' => $openIncidents,
            'maintenances' => $maintenances,
            'runtime' => $runtimeRows[0] ?? [],
            'distributed_mode' => trim((string)(getenv('INSIGHT_DISTRIBUTED_MODE') ?: 'standalone')),
            'nodes' => $nodes,
            'consensus' => $consensus,
        ];
    } catch (Throwable) {
        $database->close();
        return insight_dashboard_demo_data();
    }
}

function insight_dashboard_status(string $status): array
{
    return match (strtolower(trim($status))) {
        'online', 'up', 'operational' => ['operational', 'state.operational'],
        'degraded', 'warning', 'partial' => ['degraded', 'state.degraded'],
        'maintenance', 'planned' => ['maintenance', 'state.maintenance'],
        'offline', 'down', 'critical' => ['offline', 'state.offline'],
        default => ['unknown', 'state.unknown'],
    };
}

function insight_dashboard_server_status(string $status): array
{
    return match (strtolower(trim($status))) {
        'online', 'up', 'operational' => ['operational', 'admin.servers.up'],
        'offline', 'down', 'critical' => ['offline', 'admin.servers.down'],
        default => ['unknown', 'admin.servers.unknown'],
    };
}

function insight_dashboard_node_status(array $node): array
{
    if (($node['status'] ?? '') === 'revoked') {
        return ['offline', 'admin.network.revoked'];
    }
    if ((int)($node['is_live'] ?? 0) !== 1) {
        return ['unknown', 'state.disconnected'];
    }
    if (($node['connectivity_status'] ?? '') === 'offline') {
        return ['degraded', 'admin.network.networkIssue'];
    }
    return ['operational', 'admin.network.active'];
}

function insight_dashboard_host(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) && $host !== '' ? $host : $url;
}

function insight_dashboard_iso(?string $value): string
{
    global $insightAdminConfig;
    if ($value === null || trim($value) === '') {
        return '';
    }
    try {
        $timezone = new DateTimeZone((string)($insightAdminConfig['timezone'] ?? 'Europe/Paris'));
        return (new DateTimeImmutable($value, $timezone))->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    } catch (Throwable) {
        return '';
    }
}

function insight_dashboard_utc_iso(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format(DATE_ATOM);
    } catch (Throwable) {
        return '';
    }
}

function insight_dashboard_number(mixed $value, int $precision = 0): string
{
    return number_format((float)$value, $precision, ',', ' ');
}

$dashboard = insight_dashboard_load_data($insightAdminConfig);
$notificationState = insight_notifications_state($insightAdminConfig);
$accessState = insight_access_state();
$ssoState = insight_oidc_public_state();
$monitors = is_array($dashboard['monitors'] ?? null) ? $dashboard['monitors'] : [];
$servers = is_array($dashboard['servers'] ?? null) ? $dashboard['servers'] : [];
$incidents = is_array($dashboard['incidents'] ?? null) ? $dashboard['incidents'] : [];
$maintenances = is_array($dashboard['maintenances'] ?? null) ? $dashboard['maintenances'] : [];
$runtime = is_array($dashboard['runtime'] ?? null) ? $dashboard['runtime'] : [];
$nodes = is_array($dashboard['nodes'] ?? null) ? $dashboard['nodes'] : [];
$consensus = is_array($dashboard['consensus'] ?? null) ? $dashboard['consensus'] : [];
$notificationChannels = is_array($notificationState['channels'] ?? null) ? $notificationState['channels'] : [];
$notificationTemplates = is_array($notificationState['templates'] ?? null) ? $notificationState['templates'] : insight_notifications_templates();
$notificationDeliveries = is_array($notificationState['deliveries'] ?? null) ? $notificationState['deliveries'] : [];
$notificationCatalog = is_array($notificationState['catalog'] ?? null) ? $notificationState['catalog'] : insight_notifications_provider_catalog();
$notificationsDisabled = (bool)($notificationState['notifications_disabled'] ?? true);
$isPreview = ($dashboard['mode'] ?? 'preview') !== 'database';
$isDevBypass = insight_auth_dev_bypass_enabled();
$operational = 0;
$issues = 0;
foreach (array_merge($monitors, $servers) as $monitor) {
    [$statusClass] = insight_dashboard_status((string)($monitor['status'] ?? 'unknown'));
    if ($statusClass === 'operational') {
        $operational++;
    } else {
        $issues++;
    }
}
$openIncidents = (int)($dashboard['open_incidents'] ?? 0);
$totalMonitors = count($monitors) + count($servers);
$serversUp = count(array_filter($servers, static function (array $server): bool {
    [$statusClass] = insight_dashboard_server_status((string)($server['status'] ?? 'unknown'));
    return $statusClass === 'operational';
}));
$liveNodes = count(array_filter($nodes, static fn(array $node): bool => (int)($node['is_live'] ?? 0) === 1));
$healthyConsensus = count(array_filter($consensus, static fn(array $current): bool => ($current['status'] ?? '') === 'online'));
$distributedMode = trim((string)($dashboard['distributed_mode'] ?? 'standalone')) ?: 'standalone';
$auditEvents = [];
if (!$isDevBypass) {
    if (($user['source'] ?? 'local') === 'local') {
        $auditStatement = insight_auth_db()->prepare(
            'SELECT event, created_at FROM auth_audit_log WHERE user_id = :user_id ORDER BY id DESC LIMIT 4'
        );
        $auditStatement->execute(['user_id' => (int)$user['id']]);
    } else {
        $auditStatement = insight_auth_db()->prepare(
            "SELECT event, created_at FROM auth_audit_log
             WHERE user_id IS NULL AND event LIKE 'sso_%' ORDER BY id DESC LIMIT 4"
        );
        $auditStatement->execute();
    }
    $auditEvents = $auditStatement->fetchAll();
}
$appName = (string)($insightAdminConfig['app_name'] ?? 'Insight');
$runtimeEngine = trim((string)($runtime['active_engine'] ?? 'unknown')) ?: 'unknown';
if ($distributedMode === 'hub') {
    $runtimeEngine = 'consensus';
}
$runtimeDegraded = (int)($runtime['is_degraded'] ?? 0) === 1;
$runtimeOperational = (int)($runtime['monitor_last_ok'] ?? 0) === 1
    || in_array(strtolower($runtimeEngine), ['python', 'consensus'], true);
$runtimeStatusClass = $runtimeDegraded ? 'degraded' : ($runtimeOperational ? 'operational' : 'unknown');
$runtimeStatusKey = $runtimeDegraded ? 'state.degraded' : ($runtimeOperational ? 'state.operational' : 'state.unknown');

insight_admin_page_start('admin.meta.dashboardTitle', 'admin.meta.dashboardDescription', 'admin-dashboard-page');
?>
  <div class="admin-shell">
    <aside class="admin-sidebar" aria-label="Navigation principale" data-i18n-aria-label="admin.nav.main">
      <a class="admin-sidebar-brand" href="/admin/">
        <img src="/favicons/favicon.svg" alt="" width="30" height="30">
        <span><?= insight_admin_escape($appName) ?></span>
      </a>
      <nav class="admin-nav">
        <a class="is-active" href="#overview" data-admin-route="overview" aria-current="page"><i class="fa-solid fa-chart-line" aria-hidden="true"></i><span data-i18n="admin.nav.overview">Vue d’ensemble</span></a>
        <a href="#monitors" data-admin-route="monitors"><i class="fa-solid fa-heart-pulse" aria-hidden="true"></i><span data-i18n="admin.nav.monitors">Moniteurs</span></a>
        <a href="#servers" data-admin-route="servers"><i class="fa-solid fa-server" aria-hidden="true"></i><span data-i18n="admin.nav.servers">Serveurs</span></a>
        <a href="#network" data-admin-route="network"><i class="fa-solid fa-code-branch" aria-hidden="true"></i><span data-i18n="admin.nav.network">Réseau</span></a>
        <a href="#incidents" data-admin-route="incidents"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span data-i18n="admin.nav.incidents">Incidents</span></a>
        <a href="#notifications" data-admin-route="notifications"><i class="fa-regular fa-bell" aria-hidden="true"></i><span data-i18n="admin.nav.notifications">Alertes</span></a>
        <a href="#maintenance" data-admin-route="maintenance"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i><span data-i18n="admin.nav.maintenance">Maintenance</span></a>
        <a class="admin-nav-account" href="#account" data-admin-route="account"><i class="fa-solid fa-fingerprint" aria-hidden="true"></i><span data-i18n="admin.nav.account">Accès</span></a>
      </nav>
      <a class="admin-sidebar-public" href="/"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span data-i18n="admin.publicStatus">Page de statut publique</span></a>
    </aside>
    <main class="admin-main">
      <header class="admin-toolbar">
        <div class="admin-toolbar-title">
          <span data-i18n="admin.dashboard.title">Dashboard</span>
          <?php if ($isDevBypass): ?>
            <span class="admin-source-badge" data-source="development"><span aria-hidden="true"></span><span data-i18n="admin.devBadge">Mode dev</span></span>
          <?php elseif ($isPreview): ?>
            <span class="admin-source-badge"><span aria-hidden="true"></span><span data-i18n="admin.previewBadge">Aperçu local</span></span>
          <?php endif; ?>
        </div>
        <div class="admin-toolbar-actions">
          <div id="insight-controls-root"></div>
          <a class="admin-user" href="#account" data-admin-route="account" aria-label="Ouvrir les accès" data-i18n-aria-label="admin.nav.account"><i class="fa-regular fa-user" aria-hidden="true"></i><span><?= insight_admin_escape((string)$user['username']) ?></span></a>
          <?php if (!$isDevBypass): ?>
            <form method="post" action="/admin/logout.php">
              <input type="hidden" name="csrf_token" value="<?= insight_admin_escape(insight_auth_csrf_token()) ?>">
              <button class="admin-icon-button" type="submit" aria-label="Se déconnecter" title="Se déconnecter" data-i18n-aria-label="admin.logout" data-i18n-title="admin.logout"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i></button>
            </form>
          <?php endif; ?>
        </div>
      </header>
      <div class="admin-content">
        <section id="overview" class="admin-overview" data-admin-view="overview">
          <div class="admin-page-heading">
            <div>
              <p class="admin-eyebrow" data-i18n="admin.dashboard.eyebrow">Pilotage local</p>
              <h1 data-i18n="admin.dashboard.heading">État de l’instance</h1>
              <p data-i18n="admin.dashboard.description">Suivez les moniteurs et les événements qui demandent votre attention.</p>
            </div>
            <a class="admin-secondary-button" href="/" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i><span data-i18n="admin.dashboard.openPublic">Ouvrir la page publique</span></a>
          </div>
          <?php if ($isDevBypass): ?>
            <div class="admin-dev-notice" role="status">
              <i class="fa-solid fa-unlock" aria-hidden="true"></i>
              <div><strong data-i18n="admin.dev.title">Authentification contournée</strong><span data-i18n="admin.dev.description">Mode réservé au développement local. Aucun compte n’est nécessaire pour parcourir l’administration.</span></div>
            </div>
          <?php endif; ?>
          <?php if ($isPreview): ?>
            <div class="admin-preview-notice" role="status">
              <i class="fa-solid fa-flask" aria-hidden="true"></i>
              <div><strong data-i18n="admin.preview.title">Données de démonstration</strong><span data-i18n="admin.preview.description">MariaDB est vide ou indisponible. Ces données permettent de prévisualiser le dashboard.</span></div>
            </div>
          <?php endif; ?>
          <div class="admin-metrics" aria-label="Indicateurs" data-i18n-aria-label="admin.metrics.label">
            <div class="admin-metric">
              <span><i class="fa-solid fa-heart-pulse" aria-hidden="true"></i><span data-i18n="admin.metrics.monitors">Moniteurs</span></span>
              <strong><?= $totalMonitors ?></strong>
            </div>
            <div class="admin-metric is-positive">
              <span><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span data-i18n="admin.metrics.operational">Opérationnels</span></span>
              <strong><?= $operational ?></strong>
            </div>
            <div class="admin-metric<?= $issues > 0 ? ' is-warning' : '' ?>">
              <span><i class="fa-solid fa-wave-square" aria-hidden="true"></i><span data-i18n="admin.metrics.issues">À vérifier</span></span>
              <strong><?= $issues ?></strong>
            </div>
            <div class="admin-metric<?= $openIncidents > 0 ? ' is-negative' : '' ?>">
              <span><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span data-i18n="admin.metrics.openIncidents">Incidents ouverts</span></span>
              <strong><?= $openIncidents ?></strong>
            </div>
          </div>
        </section>

        <section id="monitors" class="admin-section admin-route-section" data-admin-view="monitors" hidden>
          <div class="admin-section-heading">
            <div><p class="admin-eyebrow" data-i18n="admin.monitors.eyebrow">Supervision</p><h1 data-i18n="admin.monitors.title">Moniteurs</h1></div>
            <div class="admin-section-actions"><span class="admin-section-count"><?= count($monitors) ?></span><button class="admin-primary-button" type="button" data-probe-create="http" aria-label="Nouvelle sonde" title="Nouvelle sonde" data-i18n-aria-label="admin.probes.createMonitor" data-i18n-title="admin.probes.createMonitor"><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.probes.createMonitor">Nouvelle sonde</span></button></div>
          </div>
          <div class="admin-monitor-list">
            <div class="admin-monitor-header" aria-hidden="true">
              <span data-i18n="admin.monitors.service">Service</span><span data-i18n="admin.monitors.state">État</span><span data-i18n="admin.monitors.response">Réponse</span><span>HTTP</span><span data-i18n="admin.monitors.lastCheck">Dernière sonde</span><span></span>
            </div>
            <?php foreach ($monitors as $monitor): ?>
              <?php [$statusClass, $statusKey] = insight_dashboard_status((string)($monitor['status'] ?? 'unknown')); ?>
              <?php $checkedAt = insight_dashboard_iso(isset($monitor['checked_at']) ? (string)$monitor['checked_at'] : null); ?>
              <article class="admin-monitor-row">
                <div class="admin-monitor-identity">
                  <span class="admin-monitor-icon"><i class="fa-solid fa-globe" aria-hidden="true"></i></span>
                  <div><strong><?= insight_admin_escape(insight_dashboard_host((string)$monitor['url'])) ?></strong><span><?= insight_admin_escape((string)$monitor['url']) ?></span></div>
                </div>
                <span class="admin-status-badge" data-status="<?= insight_admin_escape($statusClass) ?>"><span aria-hidden="true"></span><span data-i18n="<?= insight_admin_escape($statusKey) ?>"><?= insight_admin_escape($statusClass) ?></span></span>
                <span class="admin-monitor-value"><small data-i18n="admin.monitors.response">Réponse</small><?= isset($monitor['response_time']) ? insight_dashboard_number($monitor['response_time']) . ' ms' : '<span data-i18n="admin.common.notAvailable">N/D</span>' ?></span>
                <span class="admin-monitor-value"><small>HTTP</small><?= isset($monitor['http_code']) ? (int)$monitor['http_code'] : '<span data-i18n="admin.common.notAvailable">N/D</span>' ?></span>
                <span class="admin-monitor-time"><small data-i18n="admin.monitors.lastCheck">Dernière sonde</small><?php if ($checkedAt !== ''): ?><time datetime="<?= insight_admin_escape($checkedAt) ?>"></time><?php else: ?><span data-i18n="admin.common.never">Jamais</span><?php endif; ?></span>
                <?php if (!$isPreview || (int)($monitor['id'] ?? 0) >= 900000): ?>
                  <div class="admin-row-actions">
                    <button class="admin-icon-button" type="button" data-probe-edit data-probe-id="<?= (int)($monitor['id'] ?? 0) ?>" data-probe-target="<?= insight_admin_escape((string)$monitor['url']) ?>" data-probe-type="<?= insight_admin_escape((string)($monitor['probe_type'] ?? 'http')) ?>" data-probe-interval="<?= (int)($monitor['probe_interval_sec'] ?? 60) ?>" aria-label="Modifier la sonde" title="Modifier la sonde" data-i18n-aria-label="admin.probes.edit" data-i18n-title="admin.probes.edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                    <button class="admin-icon-button is-destructive" type="button" data-probe-delete data-probe-id="<?= (int)($monitor['id'] ?? 0) ?>" data-probe-target="<?= insight_admin_escape((string)$monitor['url']) ?>" aria-label="Supprimer la sonde" title="Supprimer la sonde" data-i18n-aria-label="admin.probes.delete" data-i18n-title="admin.probes.delete"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section id="servers" class="admin-section admin-route-section" data-admin-view="servers" hidden>
          <div class="admin-section-heading">
            <div><p class="admin-eyebrow" data-i18n="admin.servers.eyebrow">Disponibilité réseau</p><h1 data-i18n="admin.servers.title">Serveurs</h1></div>
            <div class="admin-section-actions"><span class="admin-section-count"><?= $serversUp ?>/<?= count($servers) ?></span><button class="admin-primary-button" type="button" data-probe-create="server" aria-label="Ajouter un serveur" title="Ajouter un serveur" data-i18n-aria-label="admin.probes.createServer" data-i18n-title="admin.probes.createServer"><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.probes.createServer">Ajouter un serveur</span></button></div>
          </div>
          <div class="admin-server-list">
            <div class="admin-server-header" aria-hidden="true">
              <span data-i18n="admin.servers.server">Serveur</span><span data-i18n="admin.servers.state">État</span><span data-i18n="admin.servers.check">Contrôle</span><span data-i18n="admin.servers.latency">Latence</span><span data-i18n="admin.servers.lastCheck">Dernière vérification</span><span></span>
            </div>
            <?php if ($servers === []): ?>
              <div class="admin-empty"><i class="fa-solid fa-server" aria-hidden="true"></i><span data-i18n="admin.servers.empty">Aucun serveur configuré.</span></div>
            <?php endif; ?>
            <?php foreach ($servers as $server): ?>
              <?php [$serverStatusClass, $serverStatusKey] = insight_dashboard_server_status((string)($server['status'] ?? 'unknown')); ?>
              <?php $serverCheckedAt = insight_dashboard_iso(isset($server['checked_at']) ? (string)$server['checked_at'] : null); ?>
              <?php $serverProbeType = strtolower(trim((string)($server['probe_type'] ?? 'icmp'))); ?>
              <article class="admin-server-row">
                <div class="admin-server-identity">
                  <span class="admin-server-icon"><i class="fa-solid fa-server" aria-hidden="true"></i></span>
                  <div><strong><?= insight_admin_escape(insight_dashboard_host((string)$server['url'])) ?></strong><span><?= insight_admin_escape((string)$server['url']) ?></span></div>
                </div>
                <span class="admin-status-badge" data-status="<?= insight_admin_escape($serverStatusClass) ?>"><span aria-hidden="true"></span><span data-i18n="<?= insight_admin_escape($serverStatusKey) ?>"><?= insight_admin_escape($serverStatusClass) ?></span></span>
                <span class="admin-server-value"><small data-i18n="admin.servers.check">Contrôle</small><?= insight_admin_escape(strtoupper($serverProbeType === 'ping' ? 'icmp' : $serverProbeType)) ?></span>
                <span class="admin-server-value"><small data-i18n="admin.servers.latency">Latence</small><?= isset($server['response_time']) ? insight_dashboard_number($server['response_time']) . ' ms' : '<span data-i18n="admin.common.notAvailable">N/D</span>' ?></span>
                <span class="admin-server-time"><small data-i18n="admin.servers.lastCheck">Dernière vérification</small><?php if ($serverCheckedAt !== ''): ?><time datetime="<?= insight_admin_escape($serverCheckedAt) ?>"></time><?php else: ?><span data-i18n="admin.common.never">Jamais</span><?php endif; ?></span>
                <?php if (!$isPreview || (int)($server['id'] ?? 0) >= 900000): ?>
                  <div class="admin-row-actions">
                    <button class="admin-icon-button" type="button" data-probe-edit data-probe-id="<?= (int)($server['id'] ?? 0) ?>" data-probe-target="<?= insight_admin_escape((string)$server['url']) ?>" data-probe-type="<?= insight_admin_escape((string)($server['probe_type'] ?? 'icmp')) ?>" data-probe-interval="<?= (int)($server['probe_interval_sec'] ?? 60) ?>" aria-label="Modifier la sonde" title="Modifier la sonde" data-i18n-aria-label="admin.probes.edit" data-i18n-title="admin.probes.edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                    <button class="admin-icon-button is-destructive" type="button" data-probe-delete data-probe-id="<?= (int)($server['id'] ?? 0) ?>" data-probe-target="<?= insight_admin_escape((string)$server['url']) ?>" aria-label="Supprimer la sonde" title="Supprimer la sonde" data-i18n-aria-label="admin.probes.delete" data-i18n-title="admin.probes.delete"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </section>

        <section id="network" class="admin-section admin-route-section" data-admin-view="network" hidden>
          <div class="admin-section-heading">
            <div><p class="admin-eyebrow" data-i18n="admin.network.eyebrow">Distribution</p><h1 data-i18n="admin.network.title">Réseau de sondes</h1></div>
            <span class="admin-section-count"><?= $liveNodes ?>/<?= count($nodes) ?></span>
          </div>
          <div class="admin-network-summary" aria-label="Résumé du monitoring distribué" data-i18n-aria-label="admin.network.summaryLabel">
            <div><span data-i18n="admin.network.mode">Mode</span><strong data-i18n="<?= $distributedMode === 'hub' ? 'admin.network.modeHub' : 'admin.network.modeStandalone' ?>"><?= $distributedMode === 'hub' ? 'Hub distribué' : 'Autonome' ?></strong></div>
            <div><span data-i18n="admin.network.liveNodes">Nœuds actifs</span><strong><?= $liveNodes ?>/<?= count($nodes) ?></strong></div>
            <div><span data-i18n="admin.network.healthyConsensus">Consensus sains</span><strong><?= $healthyConsensus ?>/<?= count($consensus) ?></strong></div>
            <div><span data-i18n="admin.network.defaultReplication">Réplication par défaut</span><strong><?= max(0, (int)(getenv('INSIGHT_AGENT_DEFAULT_REPLICAS') ?: 3)) ?></strong></div>
          </div>
          <div class="admin-network-grid">
            <div class="admin-network-column">
              <div class="admin-network-column-heading"><strong data-i18n="admin.network.nodes">Agents</strong><span><?= count($nodes) ?></span></div>
              <div class="admin-node-list">
                <?php if ($nodes === []): ?>
                  <div class="admin-empty"><i class="fa-solid fa-server" aria-hidden="true"></i><span data-i18n="admin.network.noNodes">Aucun agent enregistré.</span></div>
                <?php endif; ?>
                <?php foreach ($nodes as $node): ?>
                  <?php [$nodeStatusClass, $nodeStatusKey] = insight_dashboard_node_status($node); ?>
                  <?php $lastSeenAt = insight_dashboard_iso(isset($node['last_seen_at']) ? (string)$node['last_seen_at'] : null); ?>
                  <?php $nodeLocation = implode(' · ', array_filter([trim((string)($node['region'] ?? '')), trim((string)($node['zone'] ?? ''))])); ?>
                  <article class="admin-node-row">
                    <span class="admin-node-icon"><i class="fa-solid fa-server" aria-hidden="true"></i></span>
                    <div class="admin-node-copy">
                      <strong><?= insight_admin_escape((string)($node['display_name'] ?? $node['node_key'] ?? 'Agent')) ?></strong>
                      <span><?= insight_admin_escape($nodeLocation !== '' ? $nodeLocation : (string)($node['node_key'] ?? '')) ?></span>
                    </div>
                    <div class="admin-node-state">
                      <span class="admin-status-badge" data-status="<?= insight_admin_escape($nodeStatusClass) ?>"><span aria-hidden="true"></span><span data-i18n="<?= insight_admin_escape($nodeStatusKey) ?>"><?= insight_admin_escape($nodeStatusClass) ?></span></span>
                      <small><?= (int)($node['assignments'] ?? 0) ?> <span data-i18n="admin.network.targets">cibles</span><?php if ($lastSeenAt !== ''): ?> · <time datetime="<?= insight_admin_escape($lastSeenAt) ?>"></time><?php endif; ?></small>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="admin-network-column">
              <div class="admin-network-column-heading"><strong data-i18n="admin.network.consensus">Consensus courant</strong><span><?= count($consensus) ?></span></div>
              <div class="admin-consensus-list">
                <?php if ($consensus === []): ?>
                  <div class="admin-empty"><i class="fa-solid fa-code-branch" aria-hidden="true"></i><span data-i18n="admin.network.noConsensus">Aucun consensus calculé.</span></div>
                <?php endif; ?>
                <?php foreach ($consensus as $current): ?>
                  <?php [$consensusStatusClass, $consensusStatusKey] = insight_dashboard_status((string)($current['status'] ?? 'unknown')); ?>
                  <article class="admin-consensus-row">
                    <div class="admin-consensus-copy">
                      <strong><?= insight_admin_escape(insight_dashboard_host((string)($current['url'] ?? 'Service'))) ?></strong>
                      <span><?= (int)($current['nodes_fresh'] ?? 0) ?>/<?= (int)($current['nodes_expected'] ?? 0) ?> <span data-i18n="admin.network.responses">réponses</span> · <?= insight_dashboard_number(((float)($current['confidence'] ?? 0)) * 100) ?>%</span>
                    </div>
                    <div class="admin-consensus-counts" aria-label="Répartition des réponses" data-i18n-aria-label="admin.network.distributionLabel">
                      <span data-kind="online"><i aria-hidden="true"></i><?= (int)($current['nodes_online'] ?? 0) ?></span>
                      <span data-kind="degraded"><i aria-hidden="true"></i><?= (int)($current['nodes_degraded'] ?? 0) ?></span>
                      <span data-kind="offline"><i aria-hidden="true"></i><?= (int)($current['nodes_offline'] ?? 0) ?></span>
                      <span data-kind="missing"><i aria-hidden="true"></i><?= (int)($current['nodes_missing'] ?? 0) ?></span>
                    </div>
                    <span class="admin-status-badge" data-status="<?= insight_admin_escape($consensusStatusClass) ?>"><span aria-hidden="true"></span><span data-i18n="<?= insight_admin_escape($consensusStatusKey) ?>"><?= insight_admin_escape($consensusStatusClass) ?></span></span>
                  </article>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </section>

        <div class="admin-view-fragments">
          <section id="incidents" class="admin-section admin-detail-section admin-route-section" data-admin-view="incidents" hidden>
            <div class="admin-section-heading">
              <div><p class="admin-eyebrow" data-i18n="admin.incidents.eyebrow">Événements</p><h1 data-i18n="admin.incidents.title">Incidents récents</h1></div>
              <span class="admin-section-count"><?= count($incidents) ?></span>
            </div>
            <div class="admin-event-list">
              <?php if ($incidents === []): ?>
                <div class="admin-empty"><i class="fa-regular fa-circle-check" aria-hidden="true"></i><span data-i18n="admin.incidents.empty">Aucun incident récent.</span></div>
              <?php endif; ?>
              <?php foreach ($incidents as $incident): ?>
                <?php $isOpen = empty($incident['ended_at']); ?>
                <?php $startedAt = insight_dashboard_iso(isset($incident['started_at']) ? (string)$incident['started_at'] : null); ?>
                <article class="admin-event-row">
                  <span class="admin-event-marker" data-event="<?= $isOpen ? 'open' : 'resolved' ?>"><i class="fa-solid <?= $isOpen ? 'fa-triangle-exclamation' : 'fa-check' ?>" aria-hidden="true"></i></span>
                  <div class="admin-event-copy">
                    <div><strong><?= insight_admin_escape(insight_dashboard_host((string)$incident['url'])) ?></strong><span class="admin-event-state" data-event="<?= $isOpen ? 'open' : 'resolved' ?>" data-i18n="<?= $isOpen ? 'admin.incidents.ongoing' : 'admin.incidents.resolved' ?>"><?= $isOpen ? 'En cours' : 'Résolu' ?></span></div>
                    <p><?= insight_admin_escape(trim((string)($incident['postmortem'] ?? '')) ?: 'Incident détecté par Insight.') ?></p>
                    <span class="admin-event-meta"><?php if ($startedAt !== ''): ?><time datetime="<?= insight_admin_escape($startedAt) ?>"></time><?php endif; ?><?php if (isset($incident['http_code'])): ?><span>HTTP <?= (int)$incident['http_code'] ?></span><?php endif; ?></span>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>

          <section id="notifications" class="admin-section admin-route-section" data-admin-view="notifications" hidden>
            <div class="admin-section-heading">
              <div><p class="admin-eyebrow" data-i18n="admin.notifications.eyebrow">Diffusion</p><h1 data-i18n="admin.notifications.title">Alertes</h1><p class="admin-section-description" data-i18n="admin.notifications.description">Prévenez les bonnes personnes dès qu’un service change d’état.</p></div>
              <div class="admin-section-actions"><span class="admin-section-count" data-notification-count><?= count($notificationChannels) ?></span><button class="admin-primary-button" type="button" data-notification-create><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.notifications.newChannel">Nouveau canal</span></button></div>
            </div>
            <?php if ($notificationsDisabled): ?>
              <div class="admin-notification-notice" data-notification-disabled role="status">
                <i class="fa-solid fa-pause" aria-hidden="true"></i>
                <div><strong data-i18n="admin.notifications.disabledTitle">Envois automatiques désactivés</strong><span data-i18n="admin.notifications.disabledDescription">Les tests restent disponibles. Activez INSIGHT_DISABLE_NOTIFICATIONS=0 pour diffuser les alertes du moteur.</span></div>
              </div>
            <?php endif; ?>
            <div class="admin-page-feedback" data-notification-page-feedback role="status" hidden></div>
            <div class="admin-notification-grid">
              <section class="admin-tool-panel" aria-labelledby="notification-channels-title">
                <div class="admin-tool-panel-heading"><div><h2 id="notification-channels-title" data-i18n="admin.notifications.channels">Canaux</h2><span data-i18n="admin.notifications.channelsHint">Une même alerte peut partir vers plusieurs destinations.</span></div><i class="fa-solid fa-tower-broadcast" aria-hidden="true"></i></div>
                <div class="admin-notification-list" data-notification-list>
                  <?php if ($notificationChannels === []): ?>
                    <div class="admin-empty admin-notification-empty" data-notification-empty><i class="fa-regular fa-bell-slash" aria-hidden="true"></i><span data-i18n="admin.notifications.empty">Aucun canal configuré.</span></div>
                  <?php endif; ?>
                  <?php foreach ($notificationChannels as $channel): ?>
                    <?php $channelJson = json_encode($channel, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                    <?php $channelStatus = (string)($channel['last_status'] ?? 'unknown'); ?>
                    <article class="admin-notification-row" data-notification-channel data-channel-id="<?= (int)$channel['id'] ?>" data-channel-json="<?= insight_admin_escape((string)$channelJson) ?>">
                      <span class="admin-notification-icon"><i class="<?= insight_admin_escape((string)$channel['provider_icon']) ?>" aria-hidden="true"></i></span>
                      <div class="admin-notification-copy"><strong><?= insight_admin_escape((string)$channel['name']) ?></strong><span><?= insight_admin_escape((string)$channel['provider_label']) ?> · <?= count((array)$channel['events']) ?> <span data-i18n="admin.notifications.eventsShort">événements</span></span></div>
                      <span class="admin-status-badge" data-status="<?= $channel['enabled'] ? ($channelStatus === 'error' ? 'offline' : ($channelStatus === 'success' ? 'operational' : 'unknown')) : 'unknown' ?>"><span aria-hidden="true"></span><span data-i18n="<?= $channel['enabled'] ? ($channelStatus === 'error' ? 'admin.notifications.error' : 'admin.notifications.active') : 'admin.notifications.inactive' ?>"><?= $channel['enabled'] ? ($channelStatus === 'error' ? 'Erreur' : 'Actif') : 'Inactif' ?></span></span>
                      <div class="admin-row-actions">
                        <button class="admin-icon-button" type="button" data-notification-test aria-label="Tester le canal" title="Tester le canal" data-i18n-aria-label="admin.notifications.test" data-i18n-title="admin.notifications.test"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
                        <button class="admin-icon-button" type="button" data-notification-edit aria-label="Modifier le canal" title="Modifier le canal" data-i18n-aria-label="admin.notifications.edit" data-i18n-title="admin.notifications.edit"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                        <button class="admin-icon-button is-destructive" type="button" data-notification-delete aria-label="Supprimer le canal" title="Supprimer le canal" data-i18n-aria-label="admin.notifications.delete" data-i18n-title="admin.notifications.delete"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              </section>
              <section class="admin-tool-panel" aria-labelledby="notification-templates-title">
                <div class="admin-tool-panel-heading"><div><h2 id="notification-templates-title" data-i18n="admin.notifications.templates">Messages</h2><span data-i18n="admin.notifications.templatesHint">Chaque type d’événement possède son propre texte.</span></div><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></div>
                <form class="admin-template-form" data-notification-template-form>
                  <label class="admin-field">
                    <span data-i18n="admin.notifications.event">Événement</span>
                    <span class="admin-select-wrap"><i class="fa-solid fa-wave-square" aria-hidden="true"></i><select name="event"><option value="monitor_down" data-i18n="admin.notifications.eventMonitorDown">Moniteur hors ligne</option><option value="monitor_up" data-i18n="admin.notifications.eventMonitorUp">Moniteur rétabli</option><option value="incident_open" data-i18n="admin.notifications.eventIncidentOpen">Incident ouvert</option><option value="incident_resolved" data-i18n="admin.notifications.eventIncidentResolved">Incident résolu</option></select></span>
                  </label>
                  <label class="admin-field">
                    <span data-i18n="admin.notifications.subject">Titre</span>
                    <span class="admin-input-wrap"><i class="fa-solid fa-heading" aria-hidden="true"></i><input type="text" name="title" maxlength="500" required value="<?= insight_admin_escape((string)($notificationTemplates['monitor_down']['title'] ?? '')) ?>"></span>
                  </label>
                  <label class="admin-field">
                    <span data-i18n="admin.notifications.message">Message</span>
                    <textarea class="admin-textarea" name="body" maxlength="10000" rows="7" required><?= insight_admin_escape((string)($notificationTemplates['monitor_down']['body'] ?? '')) ?></textarea>
                  </label>
                  <div class="admin-template-tokens" aria-label="Variables disponibles" data-i18n-aria-label="admin.notifications.variables"><button type="button" data-template-token="{{ app_name }}">app_name</button><button type="button" data-template-token="{{ domain }}">domain</button><button type="button" data-template-token="{{ sites }}">sites</button><button type="button" data-template-token="{{ status }}">status</button><button type="button" data-template-token="{{ message }}">message</button><button type="button" data-template-token="{{ timestamp }}">timestamp</button></div>
                  <div class="admin-probe-feedback" data-notification-template-feedback role="alert" hidden></div>
                  <div class="admin-template-actions"><span data-i18n="admin.notifications.liquidHint">Syntaxe Liquid compatible Uptime Kuma.</span><button class="admin-secondary-button" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.notifications.saveMessage">Enregistrer</span></button></div>
                </form>
              </section>
            </div>
            <section class="admin-delivery-panel" aria-labelledby="notification-deliveries-title">
              <div class="admin-tool-panel-heading"><div><h2 id="notification-deliveries-title" data-i18n="admin.notifications.history">Derniers envois</h2><span data-i18n="admin.notifications.historyHint">Les livraisons sont conservées pendant 90 jours.</span></div><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></div>
              <div class="admin-delivery-list" data-notification-deliveries>
                <?php if ($notificationDeliveries === []): ?>
                  <div class="admin-empty" data-delivery-empty><i class="fa-regular fa-clock" aria-hidden="true"></i><span data-i18n="admin.notifications.noHistory">Aucun envoi pour le moment.</span></div>
                <?php endif; ?>
                <?php foreach ($notificationDeliveries as $delivery): ?>
                  <?php $deliveryTime = insight_dashboard_iso(isset($delivery['attempted_at']) ? (string)$delivery['attempted_at'] : null); ?>
                  <div class="admin-delivery-row"><span class="admin-delivery-state" data-status="<?= ($delivery['status'] ?? '') === 'sent' ? 'success' : 'error' ?>"><i class="fa-solid <?= ($delivery['status'] ?? '') === 'sent' ? 'fa-check' : 'fa-xmark' ?>" aria-hidden="true"></i></span><div><strong><?= insight_admin_escape((string)($delivery['channel_name'] ?? 'Canal')) ?></strong><span><?= insight_admin_escape((string)($delivery['title_rendered'] ?? $delivery['event_key'] ?? '')) ?></span></div><span class="admin-delivery-event" data-i18n="admin.notifications.event.<?= insight_admin_escape((string)($delivery['event_key'] ?? 'test')) ?>"><?= insight_admin_escape((string)($delivery['event_key'] ?? 'test')) ?></span><?php if ($deliveryTime !== ''): ?><time datetime="<?= insight_admin_escape($deliveryTime) ?>"></time><?php endif; ?></div>
                <?php endforeach; ?>
              </div>
            </section>
          </section>

          <section id="maintenance" class="admin-section admin-detail-section admin-route-section" data-admin-view="maintenance" hidden>
            <div class="admin-section-heading">
              <div><p class="admin-eyebrow" data-i18n="admin.maintenance.eyebrow">Planning</p><h1 data-i18n="admin.maintenance.title">Maintenances</h1></div>
              <span class="admin-section-count"><?= count($maintenances) ?></span>
            </div>
            <div class="admin-event-list">
              <?php if ($maintenances === []): ?>
                <div class="admin-empty"><i class="fa-regular fa-calendar" aria-hidden="true"></i><span data-i18n="admin.maintenance.empty">Aucune maintenance à venir.</span></div>
              <?php endif; ?>
              <?php foreach ($maintenances as $maintenance): ?>
                <?php $startsAt = insight_dashboard_iso(isset($maintenance['starts_at']) ? (string)$maintenance['starts_at'] : null); ?>
                <?php $endsAt = insight_dashboard_iso(isset($maintenance['ends_at']) ? (string)$maintenance['ends_at'] : null); ?>
                <article class="admin-event-row">
                  <span class="admin-event-marker" data-event="maintenance"><i class="fa-solid fa-wrench" aria-hidden="true"></i></span>
                  <div class="admin-event-copy">
                    <div><strong><?= insight_admin_escape((string)$maintenance['title']) ?></strong><span class="admin-event-state" data-event="maintenance" data-i18n="state.scheduledMaintenance">Planifiée</span></div>
                    <p><?= insight_admin_escape(trim((string)($maintenance['description'] ?? '')) ?: insight_dashboard_host((string)$maintenance['url'])) ?></p>
                    <span class="admin-event-meta"><?php if ($startsAt !== ''): ?><time datetime="<?= insight_admin_escape($startsAt) ?>"></time><?php endif; ?><?php if ($endsAt !== ''): ?><span><span data-i18n="admin.maintenance.until">jusqu’au</span> <time datetime="<?= insight_admin_escape($endsAt) ?>"></time></span><?php endif; ?></span>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        </div>

        <div class="admin-view-fragments">
          <section class="admin-section admin-detail-section" data-admin-view="overview">
            <div class="admin-section-heading"><div><p class="admin-eyebrow" data-i18n="admin.runtime.eyebrow">Exécution</p><h2 data-i18n="admin.runtime.title">Moteur de supervision</h2></div><span class="admin-status-badge" data-status="<?= insight_admin_escape($runtimeStatusClass) ?>"><span aria-hidden="true"></span><span data-i18n="<?= insight_admin_escape($runtimeStatusKey) ?>"><?= insight_admin_escape($runtimeStatusClass) ?></span></span></div>
            <dl class="admin-runtime-list">
              <div><dt data-i18n="admin.runtime.engine">Moteur actif</dt><dd><i class="fa-solid fa-code" aria-hidden="true"></i><?= insight_admin_escape($runtimeEngine) ?></dd></div>
              <div><dt data-i18n="admin.runtime.checked">Sites vérifiés</dt><dd><?= (int)($runtime['sites_checked'] ?? $totalMonitors) ?></dd></div>
              <div><dt data-i18n="admin.runtime.errors">Erreurs au dernier passage</dt><dd><?= (int)($runtime['errors_count'] ?? 0) ?></dd></div>
              <div><dt data-i18n="admin.runtime.lastRun">Dernier passage</dt><dd><?php $lastMonitor = insight_dashboard_iso(isset($runtime['last_monitor_at']) ? (string)$runtime['last_monitor_at'] : null); ?><?php if ($lastMonitor !== ''): ?><time datetime="<?= insight_admin_escape($lastMonitor) ?>"></time><?php else: ?><span data-i18n="admin.common.never">Jamais</span><?php endif; ?></dd></div>
            </dl>
          </section>

          <section id="account" class="admin-section admin-detail-section admin-route-section" data-admin-view="account" hidden>
            <div class="admin-section-heading"><div><p class="admin-eyebrow" data-i18n="admin.access.eyebrow">Sécurité et intégrations</p><h1 data-i18n="admin.access.title">Accès</h1><p class="admin-section-description" data-i18n="admin.access.description">Choisissez qui se connecte à Insight et comment vos outils le pilotent.</p></div><a class="admin-secondary-button" href="/admin/integrations.php"><i class="fa-solid fa-book" aria-hidden="true"></i><span data-i18n="admin.access.integrationGuide">Guide d’intégration</span></a></div>
            <div class="admin-access-identity">
              <span class="admin-account-icon"><i class="fa-solid <?= ($user['source'] ?? 'local') === 'oidc' ? 'fa-building-shield' : ($isDevBypass ? 'fa-unlock' : 'fa-user-shield') ?>" aria-hidden="true"></i></span>
              <div><strong><?= insight_admin_escape((string)$user['username']) ?></strong><span data-i18n="<?= ($user['source'] ?? 'local') === 'oidc' ? 'admin.account.ssoAdmin' : ($isDevBypass ? 'admin.account.devAdmin' : 'admin.account.localAdmin') ?>"><?= ($user['source'] ?? 'local') === 'oidc' ? 'Administrateur SSO' : ($isDevBypass ? 'Administrateur virtuel de développement' : 'Administrateur local') ?></span></div>
              <span class="admin-status-badge" data-status="operational"><span aria-hidden="true"></span><span data-i18n="admin.access.sessionActive">Session active</span></span>
            </div>
            <div class="admin-access-mode-map" aria-label="Modes d’accès" data-i18n-aria-label="admin.access.modesLabel">
              <div><span class="admin-access-mode-icon"><i class="fa-solid fa-terminal" aria-hidden="true"></i></span><div><strong data-i18n="admin.access.modeApiTitle">Automatiser Insight</strong><span data-i18n="admin.access.modeApiDescription">Scripts, CI/CD et backends utilisent des jetons API.</span></div><code>API</code></div>
              <div><span class="admin-access-mode-icon"><i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i></span><div><strong data-i18n="admin.access.modeProviderTitle">Connecter une autre application</strong><span data-i18n="admin.access.modeProviderDescription">Insight devient son fournisseur de connexion.</span></div><code>OIDC</code></div>
              <div><span class="admin-access-mode-icon"><i class="fa-solid fa-building-shield" aria-hidden="true"></i></span><div><strong data-i18n="admin.access.modeSsoTitle">Se connecter à Insight</strong><span data-i18n="admin.access.modeSsoDescription">Votre fournisseur d’identité protège ce dashboard.</span></div><code>SSO</code></div>
            </div>
            <div class="admin-page-feedback" data-access-feedback role="status" hidden></div>
            <div class="admin-access-grid">
              <section class="admin-tool-panel" aria-labelledby="access-api-title">
                <div class="admin-tool-panel-heading"><div><h2 id="access-api-title" data-i18n="admin.access.apiTitle">Piloter Insight par API</h2><span data-i18n="admin.access.apiHint">Pour les scripts, CI/CD et services backend.</span></div><div class="admin-access-heading-meta"><span class="admin-status-badge" data-access-feature-status="api" data-status="<?= ($accessState['api_enabled'] ?? false) ? 'operational' : 'unknown' ?>"><span aria-hidden="true"></span><span data-access-feature-label data-i18n="<?= ($accessState['api_enabled'] ?? false) ? 'admin.access.active' : 'admin.access.inactive' ?>"><?= ($accessState['api_enabled'] ?? false) ? 'Actif' : 'Inactif' ?></span></span><i class="fa-solid fa-code" aria-hidden="true"></i></div></div>
                <div class="admin-access-panel-body">
                  <label class="admin-switch"><input type="checkbox" data-access-toggle="api"<?= ($accessState['api_enabled'] ?? false) ? ' checked' : '' ?>><span aria-hidden="true"></span><span><strong data-i18n="admin.access.apiEnabled">Autoriser les jetons API</strong><small data-i18n="admin.access.apiEnabledHint">La désactivation coupe immédiatement tous les accès.</small></span></label>
                  <div class="admin-access-endpoint"><span data-i18n="admin.access.baseUrl">URL de base</span><code data-access-api-url><?= insight_admin_escape((string)$accessState['api_base_url']) ?></code><button class="admin-icon-button" type="button" data-copy-value="<?= insight_admin_escape((string)$accessState['api_base_url']) ?>" aria-label="Copier" title="Copier" data-i18n-aria-label="admin.access.copy" data-i18n-title="admin.access.copy"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div>
                  <div class="admin-access-list-heading"><strong data-i18n="admin.access.tokens">Jetons</strong><button class="admin-secondary-button" type="button" data-access-create-token><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.access.newToken">Nouveau jeton</span></button></div>
                  <div class="admin-access-list" data-access-token-list></div>
                </div>
              </section>
              <section class="admin-tool-panel" aria-labelledby="access-oauth-title">
                <div class="admin-tool-panel-heading"><div><h2 id="access-oauth-title" data-i18n="admin.access.oauthTitle">Connecter une autre application</h2><span data-i18n="admin.access.oauthHint">Insight authentifie ses utilisateurs avec OpenID Connect.</span></div><div class="admin-access-heading-meta"><span class="admin-status-badge" data-access-feature-status="oauth" data-status="<?= ($accessState['oauth_provider_enabled'] ?? false) ? 'operational' : 'unknown' ?>"><span aria-hidden="true"></span><span data-access-feature-label data-i18n="<?= ($accessState['oauth_provider_enabled'] ?? false) ? 'admin.access.active' : 'admin.access.inactive' ?>"><?= ($accessState['oauth_provider_enabled'] ?? false) ? 'Actif' : 'Inactif' ?></span></span><i class="fa-solid fa-link" aria-hidden="true"></i></div></div>
                <div class="admin-access-panel-body">
                  <label class="admin-switch"><input type="checkbox" data-access-toggle="oauth"<?= ($accessState['oauth_provider_enabled'] ?? false) ? ' checked' : '' ?><?= ($accessState['issuer_ready'] ?? false) ? '' : ' disabled' ?>><span aria-hidden="true"></span><span><strong data-i18n="admin.access.oauthEnabled">Autoriser les connexions OIDC</strong><small data-i18n="admin.access.oauthEnabledHint">Chaque application reçoit son propre identifiant et secret.</small></span></label>
                  <div class="admin-access-endpoint"><span data-i18n="admin.access.discoveryUrl">Découverte</span><code data-access-discovery-url><?= insight_admin_escape((string)$accessState['discovery_url']) ?></code><button class="admin-icon-button" type="button" data-copy-value="<?= insight_admin_escape((string)$accessState['discovery_url']) ?>" aria-label="Copier" title="Copier" data-i18n-aria-label="admin.access.copy" data-i18n-title="admin.access.copy"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div>
                  <?php if (!($accessState['issuer_ready'] ?? false)): ?><div class="admin-access-warning"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span data-i18n="admin.access.publicUrlRequired">Définissez une URL publique HTTPS avant l’activation.</span></div><?php endif; ?>
                  <div class="admin-access-list-heading"><strong data-i18n="admin.access.clients">Applications</strong><button class="admin-secondary-button" type="button" data-access-create-client><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.access.newClient">Nouveau dashboard</span></button></div>
                  <div class="admin-access-list" data-access-client-list></div>
                </div>
              </section>
              <section class="admin-tool-panel admin-access-sso-panel" aria-labelledby="access-sso-title">
                <div class="admin-tool-panel-heading"><div><h2 id="access-sso-title" data-i18n="admin.access.ssoTitle">Connexion de ce dashboard</h2><span data-i18n="admin.access.ssoHint">Vos utilisateurs se connectent avec le fournisseur d’identité de l’organisation.</span></div><i class="fa-solid fa-building-shield" aria-hidden="true"></i></div>
                <div class="admin-access-panel-body admin-access-sso-body">
                  <div class="admin-access-sso-status">
                    <span class="admin-status-badge" data-status="<?= ($ssoState['enabled'] ?? false) ? (($ssoState['valid'] ?? false) ? 'operational' : 'offline') : 'unknown' ?>"><span aria-hidden="true"></span><span data-i18n="<?= ($ssoState['enabled'] ?? false) ? (($ssoState['valid'] ?? false) ? 'admin.access.configured' : 'admin.access.invalid') : 'admin.access.disabled' ?>"><?= ($ssoState['enabled'] ?? false) ? (($ssoState['valid'] ?? false) ? 'Configuré' : 'Configuration invalide') : 'Désactivé' ?></span></span>
                    <div><strong><?= insight_admin_escape((string)($ssoState['provider_name'] ?? 'SSO')) ?></strong><span><?= insight_admin_escape((string)($ssoState['issuer'] ?? '')) ?></span></div>
                  </div>
                  <div class="admin-access-endpoint"><span data-i18n="admin.access.callbackUrl">URL de retour</span><code><?= insight_admin_escape((string)$ssoState['callback_url']) ?></code><button class="admin-icon-button" type="button" data-copy-value="<?= insight_admin_escape((string)$ssoState['callback_url']) ?>" aria-label="Copier" title="Copier" data-i18n-aria-label="admin.access.copy" data-i18n-title="admin.access.copy"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div>
                  <div class="admin-access-config-note"><i class="fa-solid fa-sliders" aria-hidden="true"></i><div><strong data-i18n="admin.access.ssoConfigTitle">Configuration dans .env</strong><span data-i18n="admin.access.ssoConfigDescription">Issuer, client OIDC et règles d’accès restent côté serveur.</span></div></div>
                </div>
              </section>
              <section class="admin-tool-panel admin-access-audit-panel" aria-labelledby="access-audit-title">
                <div class="admin-tool-panel-heading"><div><h2 id="access-audit-title" data-i18n="admin.access.auditTitle">Activité de sécurité</h2><span data-i18n="admin.access.auditHint">Derniers événements de cette identité locale.</span></div><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
                <div class="admin-audit-list">
                  <?php if ($isDevBypass): ?>
                    <div><span><i class="fa-solid fa-unlock" aria-hidden="true"></i><span data-i18n="admin.dev.audit">Contrôle d’accès désactivé par la configuration locale</span></span></div>
                  <?php else: ?>
                    <?php foreach ($auditEvents as $event): ?>
                      <?php $eventTime = insight_dashboard_utc_iso((string)($event['created_at'] ?? '')); ?>
                      <?php $eventKey = match ((string)($event['event'] ?? '')) { 'setup_completed' => 'admin.audit.setup', 'login_succeeded', 'sso_login_succeeded' => 'admin.audit.login', 'logout' => 'admin.audit.logout', default => 'admin.audit.activity' }; ?>
                      <div><span><i class="fa-solid fa-shield" aria-hidden="true"></i><span data-i18n="<?= insight_admin_escape($eventKey) ?>">Activité locale</span></span><?php if ($eventTime !== ''): ?><time datetime="<?= insight_admin_escape($eventTime) ?>"></time><?php endif; ?></div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </section>
            </div>
          </section>
        </div>
      </div>
    </main>
  </div>
  <dialog class="admin-access-dialog" data-access-token-dialog aria-labelledby="admin-access-token-title">
    <form class="admin-access-form" data-access-token-form>
      <div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-key" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.access.apiTitle">API headless</p><h2 id="admin-access-token-title" data-i18n="admin.access.createTokenTitle">Créer un jeton</h2></div><button class="admin-icon-button" type="button" data-access-dialog-close aria-label="Fermer" title="Fermer" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
      <label class="admin-field"><span data-i18n="admin.access.name">Nom</span><span class="admin-input-wrap"><i class="fa-solid fa-tag" aria-hidden="true"></i><input type="text" name="name" minlength="2" maxlength="120" required autocomplete="off" placeholder="Automatisation production" data-i18n-placeholder="admin.access.tokenNamePlaceholder"></span></label>
      <label class="admin-field"><span data-i18n="admin.access.expiry">Expiration</span><span class="admin-select-wrap"><i class="fa-regular fa-clock" aria-hidden="true"></i><select name="expires_in_days"><option value="30" data-i18n="admin.access.days30">30 jours</option><option value="90" selected data-i18n="admin.access.days90">90 jours</option><option value="365" data-i18n="admin.access.year1">1 an</option><option value="0" data-i18n="admin.access.never">Jamais</option></select></span></label>
      <fieldset class="admin-access-scopes"><legend data-i18n="admin.access.permissions">Permissions</legend><?php foreach (insight_access_scope_catalog() as $scope => $label): ?><label><input type="checkbox" name="scopes" value="<?= insight_admin_escape($scope) ?>"<?= in_array($scope, ['status:read', 'monitors:read', 'incidents:read'], true) ? ' checked' : '' ?>><span><code><?= insight_admin_escape($scope) ?></code><small data-i18n="<?= insight_admin_escape(insight_access_scope_i18n_key($scope)) ?>"><?= insight_admin_escape($label) ?></small></span></label><?php endforeach; ?></fieldset>
      <div class="admin-probe-feedback" data-access-token-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-access-dialog-close data-i18n="admin.probes.cancel">Annuler</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i><span data-i18n="admin.access.create">Créer</span></button></div>
    </form>
  </dialog>
  <dialog class="admin-access-dialog" data-access-client-dialog aria-labelledby="admin-access-client-title">
    <form class="admin-access-form" data-access-client-form>
      <div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-link" aria-hidden="true"></i></span><div><p class="admin-eyebrow">OpenID Connect</p><h2 id="admin-access-client-title" data-i18n="admin.access.createClientTitle">Connecter un dashboard</h2></div><button class="admin-icon-button" type="button" data-access-dialog-close aria-label="Fermer" title="Fermer" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
      <label class="admin-field"><span data-i18n="admin.access.name">Nom</span><span class="admin-input-wrap"><i class="fa-solid fa-tag" aria-hidden="true"></i><input type="text" name="name" minlength="2" maxlength="120" required autocomplete="off" placeholder="Dashboard opérations" data-i18n-placeholder="admin.access.clientNamePlaceholder"></span></label>
      <label class="admin-field"><span data-i18n="admin.access.redirectUris">URI de retour exactes</span><textarea class="admin-textarea admin-code-textarea" name="redirect_uris" rows="3" maxlength="10000" required placeholder="https://dashboard.example.com/auth/callback"></textarea></label>
      <fieldset class="admin-access-scopes"><legend data-i18n="admin.access.permissions">Permissions</legend><?php foreach (insight_access_oauth_scope_catalog() as $scope => $label): ?><label><input type="checkbox" name="scopes" value="<?= insight_admin_escape($scope) ?>"<?= in_array($scope, ['openid', 'profile'], true) ? ' checked' : '' ?><?= $scope === 'openid' ? ' disabled' : '' ?>><span><code><?= insight_admin_escape($scope) ?></code><small data-i18n="<?= insight_admin_escape(insight_access_scope_i18n_key($scope)) ?>"><?= insight_admin_escape($label) ?></small></span></label><?php endforeach; ?></fieldset>
      <div class="admin-probe-feedback" data-access-client-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-access-dialog-close data-i18n="admin.probes.cancel">Annuler</button><button class="admin-primary-button" type="submit"><i class="fa-solid fa-link" aria-hidden="true"></i><span data-i18n="admin.access.create">Créer</span></button></div>
    </form>
  </dialog>
  <dialog class="admin-access-dialog" data-access-secret-dialog aria-labelledby="admin-access-secret-title">
    <div class="admin-access-secret-content"><div class="admin-probe-dialog-heading"><span class="admin-probe-dialog-icon"><i class="fa-solid fa-key" aria-hidden="true"></i></span><div><p class="admin-eyebrow" data-i18n="admin.access.secretEyebrow">Secret créé</p><h2 id="admin-access-secret-title" data-i18n="admin.access.secretTitle">Conservez ces identifiants</h2></div><button class="admin-icon-button" type="button" data-access-secret-close aria-label="Fermer" title="Fermer" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><p data-i18n="admin.access.secretOnce">Ils ne seront plus affichés après la fermeture.</p><div class="admin-access-secret-values" data-access-secret-values></div><div class="admin-probe-form-actions"><button class="admin-primary-button" type="button" data-access-secret-close><i class="fa-solid fa-check" aria-hidden="true"></i><span data-i18n="admin.access.done">Terminé</span></button></div></div>
  </dialog>
  <dialog class="admin-probe-dialog" data-probe-dialog aria-labelledby="admin-probe-dialog-title">
    <form class="admin-probe-form" data-probe-form>
      <div class="admin-probe-dialog-heading">
        <span class="admin-probe-dialog-icon"><i class="fa-solid fa-heart-pulse" aria-hidden="true" data-probe-dialog-icon></i></span>
        <div><p class="admin-eyebrow" data-i18n="admin.probes.eyebrow">Nouvelle cible</p><h2 id="admin-probe-dialog-title" data-probe-dialog-title>Créer une sonde</h2></div>
        <button class="admin-icon-button" type="button" data-probe-close aria-label="Fermer" title="Fermer" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>
      <div class="admin-probe-type-field" data-probe-type-field hidden>
        <span data-i18n="admin.probes.type">Type de contrôle</span>
        <div class="admin-probe-type-control">
          <label><input type="radio" name="probe_type" value="icmp" checked><span><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i><span>ICMP</span></span></label>
          <label><input type="radio" name="probe_type" value="tcp"><span><i class="fa-solid fa-network-wired" aria-hidden="true"></i><span>TCP</span></span></label>
        </div>
      </div>
      <label class="admin-field">
        <span data-probe-target-label data-i18n="admin.probes.url">URL du service</span>
        <span class="admin-input-wrap"><i class="fa-solid fa-link" aria-hidden="true" data-probe-target-icon></i><input type="text" name="target" required maxlength="255" autocomplete="off" spellcheck="false" data-probe-target></span>
        <span class="admin-field-hint" data-probe-target-hint></span>
      </label>
      <label class="admin-field">
        <span data-i18n="admin.probes.interval">Fréquence de contrôle</span>
        <span class="admin-select-wrap"><i class="fa-regular fa-clock" aria-hidden="true"></i><select name="interval_sec"><option value="60" data-i18n="admin.probes.everyMinute">Toutes les minutes</option><option value="120" data-i18n="admin.probes.everyTwoMinutes">Toutes les 2 minutes</option><option value="300" data-i18n="admin.probes.everyFiveMinutes">Toutes les 5 minutes</option><option value="600" data-i18n="admin.probes.everyTenMinutes">Toutes les 10 minutes</option><option value="1800" data-i18n="admin.probes.everyThirtyMinutes">Toutes les 30 minutes</option></select></span>
      </label>
      <div class="admin-probe-feedback" data-probe-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions">
        <button class="admin-secondary-button" type="button" data-probe-close data-i18n="admin.probes.cancel">Annuler</button>
        <button class="admin-primary-button" type="submit" data-probe-submit><i class="fa-solid fa-plus" aria-hidden="true" data-probe-submit-icon></i><span data-i18n="admin.probes.submit" data-probe-submit-label>Créer la sonde</span></button>
      </div>
    </form>
  </dialog>
  <dialog class="admin-confirm-dialog" data-probe-delete-dialog aria-labelledby="admin-probe-delete-title">
    <div class="admin-confirm-content">
      <span class="admin-confirm-icon"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></span>
      <div><p class="admin-eyebrow" data-i18n="admin.probes.deleteEyebrow">Suppression</p><h2 id="admin-probe-delete-title" data-i18n="admin.probes.deleteTitle">Supprimer cette sonde ?</h2><p data-i18n="admin.probes.deleteDescription">Son historique, ses incidents et ses statistiques seront également supprimés.</p><strong data-probe-delete-target></strong></div>
      <div class="admin-probe-feedback" data-probe-delete-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions">
        <button class="admin-secondary-button" type="button" data-probe-delete-close data-i18n="admin.probes.cancel">Annuler</button>
        <button class="admin-primary-button is-destructive" type="button" data-probe-delete-confirm><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span data-i18n="admin.probes.confirmDelete">Supprimer</span></button>
      </div>
    </div>
  </dialog>
  <dialog class="admin-notification-dialog" data-notification-dialog aria-labelledby="admin-notification-dialog-title">
    <form class="admin-notification-form" data-notification-form>
      <div class="admin-probe-dialog-heading">
        <span class="admin-probe-dialog-icon"><i class="fa-regular fa-bell" aria-hidden="true" data-notification-dialog-icon></i></span>
        <div><p class="admin-eyebrow" data-i18n="admin.notifications.channelEyebrow">Destination</p><h2 id="admin-notification-dialog-title" data-notification-dialog-title data-i18n="admin.notifications.createTitle">Créer un canal</h2></div>
        <button class="admin-icon-button" type="button" data-notification-close aria-label="Fermer" title="Fermer" data-i18n-aria-label="common.close" data-i18n-title="common.close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
      </div>
      <div class="admin-notification-primary-fields">
        <label class="admin-field"><span data-i18n="admin.notifications.name">Nom</span><span class="admin-input-wrap"><i class="fa-solid fa-tag" aria-hidden="true"></i><input type="text" name="name" maxlength="120" required autocomplete="off" placeholder="Astreinte principale" data-i18n-placeholder="admin.notifications.namePlaceholder"></span></label>
        <label class="admin-field"><span data-i18n="admin.notifications.provider">Service</span><span class="admin-select-wrap"><i class="fa-solid fa-plug" aria-hidden="true"></i><select name="provider" data-notification-provider><?php foreach ($notificationCatalog as $provider): ?><option value="<?= insight_admin_escape((string)$provider['id']) ?>" data-mode="<?= insight_admin_escape((string)$provider['mode']) ?>" data-icon="<?= insight_admin_escape((string)$provider['icon']) ?>"><?= insight_admin_escape((string)$provider['label']) ?></option><?php endforeach; ?></select></span></label>
      </div>
      <div class="admin-notification-config" data-notification-config="smtp">
        <div class="admin-notification-field-grid is-three">
          <label class="admin-field"><span data-i18n="admin.notifications.smtpHost">Serveur SMTP</span><span class="admin-input-wrap"><i class="fa-solid fa-server" aria-hidden="true"></i><input type="text" name="smtp_host" maxlength="255" autocomplete="off" placeholder="smtp.example.com"></span></label>
          <label class="admin-field"><span data-i18n="admin.notifications.smtpPort">Port</span><span class="admin-input-wrap"><i class="fa-solid fa-hashtag" aria-hidden="true"></i><input type="number" name="smtp_port" min="1" max="65535" value="465"></span></label>
          <label class="admin-field"><span data-i18n="admin.notifications.smtpEncryption">Chiffrement</span><span class="admin-select-wrap"><i class="fa-solid fa-lock" aria-hidden="true"></i><select name="smtp_encryption"><option value="ssl">SSL</option><option value="tls">STARTTLS</option><option value="none" data-i18n="admin.notifications.none">Aucun</option></select></span></label>
        </div>
        <div class="admin-notification-field-grid">
          <label class="admin-field"><span data-i18n="admin.notifications.username">Identifiant</span><span class="admin-input-wrap"><i class="fa-regular fa-user" aria-hidden="true"></i><input type="text" name="smtp_username" maxlength="255" autocomplete="username"></span></label>
          <label class="admin-field"><span data-i18n="admin.notifications.password">Mot de passe</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="password" name="smtp_password" maxlength="2000" autocomplete="new-password" data-secret-field></span></label>
        </div>
        <div class="admin-notification-field-grid">
          <label class="admin-field"><span data-i18n="admin.notifications.fromEmail">Adresse d’envoi</span><span class="admin-input-wrap"><i class="fa-solid fa-at" aria-hidden="true"></i><input type="email" name="smtp_from_email" maxlength="320" autocomplete="email"></span></label>
          <label class="admin-field"><span data-i18n="admin.notifications.fromName">Nom d’envoi</span><span class="admin-input-wrap"><i class="fa-solid fa-signature" aria-hidden="true"></i><input type="text" name="smtp_from_name" maxlength="120" value="<?= insight_admin_escape($appName) ?>"></span></label>
        </div>
        <label class="admin-field"><span data-i18n="admin.notifications.recipients">Destinataires</span><textarea class="admin-textarea" name="smtp_to" rows="2" maxlength="2000" placeholder="ops@example.com, admin@example.com"></textarea></label>
      </div>
      <div class="admin-notification-config" data-notification-config="webhook" hidden>
        <div class="admin-notification-field-grid is-endpoint">
          <label class="admin-field"><span data-i18n="admin.notifications.webhookUrl">URL du webhook</span><span class="admin-input-wrap"><i class="fa-solid fa-link" aria-hidden="true"></i><input type="password" name="webhook_url" maxlength="4000" autocomplete="new-password" data-secret-field placeholder="https://hooks.example.com/…"></span></label>
          <label class="admin-field"><span data-i18n="admin.notifications.method">Méthode</span><span class="admin-select-wrap"><i class="fa-solid fa-arrow-right" aria-hidden="true"></i><select name="webhook_method"><option>POST</option><option>PUT</option><option>PATCH</option></select></span></label>
        </div>
        <label class="admin-field"><span data-i18n="admin.notifications.headers">En-têtes JSON</span><textarea class="admin-textarea" name="webhook_headers" rows="3" maxlength="10000" data-secret-field placeholder='{"Authorization":"Bearer …"}'></textarea></label>
        <label class="admin-field"><span data-i18n="admin.notifications.payload">Corps JSON personnalisé</span><textarea class="admin-textarea admin-code-textarea" name="webhook_payload" rows="5" maxlength="20000" placeholder='{"title":"{{ title }}","message":"{{ body }}"}'></textarea></label>
      </div>
      <div class="admin-notification-config" data-notification-config="free_mobile" hidden>
        <div class="admin-notification-field-grid">
          <label class="admin-field"><span data-i18n="admin.notifications.freeUser">Identifiant Free Mobile</span><span class="admin-input-wrap"><i class="fa-regular fa-user" aria-hidden="true"></i><input type="text" name="free_user" maxlength="120" autocomplete="off"></span></label>
          <label class="admin-field"><span data-i18n="admin.notifications.freePassword">Clé d’identification</span><span class="admin-input-wrap"><i class="fa-solid fa-key" aria-hidden="true"></i><input type="password" name="free_password" maxlength="2000" autocomplete="new-password" data-secret-field></span></label>
        </div>
      </div>
      <div class="admin-notification-config" data-notification-config="apprise" hidden>
        <label class="admin-field"><span data-i18n="admin.notifications.appriseUrls">URL de notification Apprise</span><textarea class="admin-textarea admin-code-textarea" name="apprise_urls" rows="4" maxlength="30000" data-secret-field placeholder="discord://…&#10;ntfys://…"></textarea><span class="admin-field-hint" data-i18n="admin.notifications.appriseHint">Une URL par ligne. Les 138+ services Apprise sont acceptés.</span></label>
      </div>
      <fieldset class="admin-notification-events">
        <legend data-i18n="admin.notifications.triggerOn">Déclencher pour</legend>
        <label><input type="checkbox" name="events" value="monitor_down" checked><span><i class="fa-solid fa-arrow-trend-down" aria-hidden="true"></i><span data-i18n="admin.notifications.eventMonitorDown">Moniteur hors ligne</span></span></label>
        <label><input type="checkbox" name="events" value="monitor_up" checked><span><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i><span data-i18n="admin.notifications.eventMonitorUp">Moniteur rétabli</span></span></label>
        <label><input type="checkbox" name="events" value="incident_open" checked><span><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span data-i18n="admin.notifications.eventIncidentOpen">Incident ouvert</span></span></label>
        <label><input type="checkbox" name="events" value="incident_resolved" checked><span><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span data-i18n="admin.notifications.eventIncidentResolved">Incident résolu</span></span></label>
      </fieldset>
      <label class="admin-switch"><input type="checkbox" name="enabled" checked><span aria-hidden="true"></span><span><strong data-i18n="admin.notifications.enabled">Canal actif</strong><small data-i18n="admin.notifications.enabledHint">Reçoit les alertes sélectionnées.</small></span></label>
      <div class="admin-probe-feedback" data-notification-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions">
        <button class="admin-secondary-button" type="button" data-notification-close data-i18n="admin.probes.cancel">Annuler</button>
        <button class="admin-primary-button" type="submit" data-notification-submit><i class="fa-solid fa-check" aria-hidden="true"></i><span data-notification-submit-label data-i18n="admin.notifications.create">Créer le canal</span></button>
      </div>
    </form>
  </dialog>
  <dialog class="admin-confirm-dialog" data-notification-delete-dialog aria-labelledby="admin-notification-delete-title">
    <div class="admin-confirm-content">
      <span class="admin-confirm-icon"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></span>
      <div><p class="admin-eyebrow" data-i18n="admin.notifications.deleteEyebrow">Suppression</p><h2 id="admin-notification-delete-title" data-i18n="admin.notifications.deleteTitle">Supprimer ce canal ?</h2><p data-i18n="admin.notifications.deleteDescription">Les prochains événements ne seront plus envoyés vers cette destination.</p><strong data-notification-delete-target></strong></div>
      <div class="admin-probe-feedback" data-notification-delete-feedback role="alert" hidden></div>
      <div class="admin-probe-form-actions"><button class="admin-secondary-button" type="button" data-notification-delete-close data-i18n="admin.probes.cancel">Annuler</button><button class="admin-primary-button is-destructive" type="button" data-notification-delete-confirm><i class="fa-regular fa-trash-can" aria-hidden="true"></i><span data-i18n="admin.notifications.confirmDelete">Supprimer</span></button></div>
    </div>
  </dialog>
<?php insight_admin_page_end(); ?>
