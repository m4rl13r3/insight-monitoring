CREATE TABLE IF NOT EXISTS sites (
    id INT NOT NULL AUTO_INCREMENT,
    url VARCHAR(255) NOT NULL,
    probe_type VARCHAR(16) NOT NULL DEFAULT 'http',
    probe_interval_sec INT NOT NULL DEFAULT 60,
    calc_method VARCHAR(24) NOT NULL DEFAULT 'inherit',
    http_methods VARCHAR(128) NOT NULL DEFAULT 'GET,POST,PUT,HEAD,DELETE,PATCH,OPTIONS',
    http_redirect_modes VARCHAR(32) NOT NULL DEFAULT 'follow,no_follow',
    http_primary_method VARCHAR(16) NOT NULL DEFAULT 'GET',
    http_primary_redirect VARCHAR(16) NOT NULL DEFAULT 'follow',
    probe_replication_factor SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    probe_success_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    probe_failure_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_sites_url (url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_nodes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    node_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    region VARCHAR(64) NULL,
    zone VARCHAR(64) NULL,
    version VARCHAR(32) NULL,
    status ENUM('active','paused','revoked') NOT NULL DEFAULT 'active',
    capabilities JSON NULL,
    connectivity_status ENUM('online','offline','unknown') NOT NULL DEFAULT 'unknown',
    clock_skew_ms INT NOT NULL DEFAULT 0,
    last_ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    first_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    last_seen_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    last_config_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uniq_monitoring_nodes_key (node_key),
    KEY idx_monitoring_nodes_status_seen (status, last_seen_at),
    KEY idx_monitoring_nodes_region (region, zone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_assignments (
    site_id INT NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    assigned_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (site_id, node_id),
    KEY idx_monitoring_assignments_node (node_id, active),
    KEY idx_monitoring_assignments_active (active, site_id),
    CONSTRAINT fk_monitoring_assignments_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
    CONSTRAINT fk_monitoring_assignments_node FOREIGN KEY (node_id) REFERENCES monitoring_nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_agent_requests (
    node_id BIGINT UNSIGNED NOT NULL,
    nonce_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (node_id, nonce_hash),
    KEY idx_monitoring_agent_requests_received (received_at),
    CONSTRAINT fk_monitoring_agent_requests_node FOREIGN KEY (node_id) REFERENCES monitoring_nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_agent_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    node_id BIGINT UNSIGNED NOT NULL,
    batch_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sample_count INT UNSIGNED NOT NULL DEFAULT 0,
    accepted_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
    rejected_count INT UNSIGNED NOT NULL DEFAULT 0,
    received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uniq_monitoring_agent_batch (node_id, batch_id),
    KEY idx_monitoring_agent_batches_received (received_at),
    CONSTRAINT fk_monitoring_agent_batches_node FOREIGN KEY (node_id) REFERENCES monitoring_nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    sample_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    batch_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status ENUM('online','offline','degraded','unknown') NOT NULL DEFAULT 'unknown',
    response_time_ms DECIMAL(12,3) NULL,
    http_code INT NULL,
    error_code VARCHAR(64) NULL,
    error_message VARCHAR(255) NULL,
    metadata JSON NULL,
    observed_at DATETIME(3) NOT NULL,
    received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uniq_monitoring_observation_sample (node_id, sample_id),
    KEY idx_monitoring_observations_site_time (site_id, observed_at),
    KEY idx_monitoring_observations_node_time (node_id, observed_at),
    KEY idx_monitoring_observations_received (received_at),
    CONSTRAINT fk_monitoring_observations_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
    CONSTRAINT fk_monitoring_observations_node FOREIGN KEY (node_id) REFERENCES monitoring_nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_consensus_current (
    site_id INT NOT NULL,
    status ENUM('online','offline','degraded','unknown') NOT NULL DEFAULT 'unknown',
    nodes_expected SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nodes_fresh SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nodes_online SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nodes_offline SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nodes_degraded SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nodes_missing SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    success_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    failure_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    confidence DECIMAL(8,5) NOT NULL DEFAULT 0,
    response_median_ms DECIMAL(12,3) NULL,
    response_p95_ms DECIMAL(12,3) NULL,
    window_started_at DATETIME(3) NULL,
    last_observation_at DATETIME(3) NULL,
    evaluated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (site_id),
    KEY idx_monitoring_consensus_status (status, evaluated_at),
    CONSTRAINT fk_monitoring_consensus_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_consensus_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT NOT NULL,
    bucket_at DATETIME NOT NULL,
    status ENUM('online','offline','degraded','unknown') NOT NULL DEFAULT 'unknown',
    nodes_expected SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nodes_fresh SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nodes_online SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nodes_offline SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nodes_degraded SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    nodes_missing SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    success_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    failure_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    confidence DECIMAL(8,5) NOT NULL DEFAULT 0,
    response_median_ms DECIMAL(12,3) NULL,
    response_p95_ms DECIMAL(12,3) NULL,
    evaluated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uniq_monitoring_consensus_bucket (site_id, bucket_at),
    KEY idx_monitoring_consensus_snapshots_bucket (bucket_at),
    CONSTRAINT fk_monitoring_consensus_snapshot_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS probes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT NOT NULL,
    probe_type VARCHAR(16) NOT NULL DEFAULT 'http',
    status VARCHAR(16) NOT NULL DEFAULT 'offline',
    response_time DECIMAL(10,3) NULL,
    http_code INT NULL,
    checked_by VARCHAR(3) NOT NULL DEFAULT 'pyt',
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    source_node VARCHAR(64) NULL,
    source_probe_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_probes_site_checked (site_id, checked_at),
    KEY idx_probes_checked_at (checked_at),
    UNIQUE KEY uniq_probes_source (source_node, source_probe_id),
    CONSTRAINT fk_probes_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hourly_stats (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT NOT NULL,
    date DATE NOT NULL,
    hour TINYINT UNSIGNED NOT NULL,
    avg_response_time DECIMAL(10,3) NULL,
    minutes_offline INT NOT NULL DEFAULT 0,
    binary_sequence VARCHAR(60) NULL,
    total_seconds INT NOT NULL DEFAULT 3600,
    offline_seconds INT NOT NULL DEFAULT 0,
    degraded_seconds INT NOT NULL DEFAULT 0,
    maintenance_seconds INT NOT NULL DEFAULT 0,
    availability_ratio DECIMAL(6,4) NULL,
    health_score DECIMAL(6,4) NULL,
    calc_method VARCHAR(24) NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_hourly_site_slot (site_id, date, hour),
    KEY idx_hourly_date (date, hour),
    KEY idx_hourly_site_checked (site_id, checked_at),
    CONSTRAINT fk_hourly_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_stats (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT NOT NULL,
    date DATE NOT NULL,
    avg_response_time DECIMAL(10,3) NULL,
    minutes_offline INT NOT NULL DEFAULT 0,
    total_seconds INT NOT NULL DEFAULT 86400,
    offline_seconds INT NOT NULL DEFAULT 0,
    degraded_seconds INT NOT NULL DEFAULT 0,
    maintenance_seconds INT NOT NULL DEFAULT 0,
    availability_ratio DECIMAL(6,4) NULL,
    health_score DECIMAL(6,4) NULL,
    calc_method VARCHAR(24) NULL,
    checked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_daily_site_date (site_id, date),
    KEY idx_daily_date (date),
    KEY idx_daily_site_checked (site_id, checked_at),
    CONSTRAINT fk_daily_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incidents (
    id INT NOT NULL AUTO_INCREMENT,
    site_id INT NULL,
    incident_code VARCHAR(64) NULL,
    started_at DATETIME NOT NULL,
    incident_date DATETIME NULL,
    ended_at DATETIME NULL,
    http_code INT NULL,
    postmortem MEDIUMTEXT NULL,
    ai_created TINYINT(1) NOT NULL DEFAULT 0,
    source_mode ENUM('manual','ai','system') NULL,
    site_label VARCHAR(255) NULL,
    resolved TINYINT(1) NULL,
    status TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_incident_code (incident_code),
    KEY idx_incidents_site_status (site_id, status),
    KEY idx_incidents_started (started_at),
    CONSTRAINT fk_incidents_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduled_maintenances (
    id INT NOT NULL AUTO_INCREMENT,
    site_id INT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status ENUM('planned','cancelled','completed') NOT NULL DEFAULT 'planned',
    notify_public TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id INT NULL,
    created_by_name VARCHAR(140) NULL,
    cancelled_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_maintenance_site (site_id),
    KEY idx_maintenance_status (status),
    KEY idx_maintenance_dates (starts_at, ends_at),
    CONSTRAINT fk_maintenance_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ssl_checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT NOT NULL,
    host VARCHAR(255) NOT NULL,
    port INT NOT NULL DEFAULT 443,
    is_valid TINYINT(1) NULL,
    valid_from DATETIME NULL,
    valid_to DATETIME NULL,
    days_remaining INT NULL,
    issuer_name VARCHAR(255) NULL,
    issuer_cn VARCHAR(255) NULL,
    subject_cn VARCHAR(255) NULL,
    san TEXT NULL,
    tls_version VARCHAR(32) NULL,
    cipher_name VARCHAR(64) NULL,
    error_message VARCHAR(255) NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ssl_site_checked (site_id, checked_at),
    KEY idx_ssl_valid_to (valid_to),
    CONSTRAINT fk_ssl_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_public_runtime_state (
    singleton_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    service_name VARCHAR(64) NOT NULL DEFAULT 'insight',
    service_timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Paris',
    app_env VARCHAR(32) NOT NULL DEFAULT 'production',
    is_degraded TINYINT(1) NOT NULL DEFAULT 0,
    active_engine VARCHAR(16) NOT NULL DEFAULT 'unknown',
    monitor_last_ok TINYINT(1) NOT NULL DEFAULT 0,
    monitor_last_message VARCHAR(255) NULL,
    monitor_python_error TEXT NULL,
    monitor_fallback_message TEXT NULL,
    monitor_checked_by VARCHAR(8) NOT NULL DEFAULT 'unknown',
    sites_checked INT NOT NULL DEFAULT 0,
    errors_count INT NOT NULL DEFAULT 0,
    incidents_opened INT NOT NULL DEFAULT 0,
    incidents_closed INT NOT NULL DEFAULT 0,
    hourly_last_ok TINYINT(1) NOT NULL DEFAULT 0,
    hourly_processed INT NOT NULL DEFAULT 0,
    hourly_bad_data INT NOT NULL DEFAULT 0,
    hourly_engine VARCHAR(16) NOT NULL DEFAULT 'unknown',
    daily_last_ok TINYINT(1) NOT NULL DEFAULT 0,
    daily_processed INT NOT NULL DEFAULT 0,
    daily_bad_data INT NOT NULL DEFAULT 0,
    daily_engine VARCHAR(16) NOT NULL DEFAULT 'unknown',
    last_monitor_at DATETIME NULL,
    last_hourly_at DATETIME NULL,
    last_daily_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    public_payload LONGTEXT NULL,
    PRIMARY KEY (singleton_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_calc_settings (
    singleton_id TINYINT NOT NULL DEFAULT 1,
    switch_at DATETIME NOT NULL,
    default_calc_method VARCHAR(24) NOT NULL DEFAULT 'time_weighted',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (singleton_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert (
    id INT NOT NULL,
    site_url VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'online',
    alert_sent TINYINT(1) NOT NULL DEFAULT 0,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_alert_status (status),
    KEY idx_alert_timestamp (timestamp),
    CONSTRAINT fk_alert_site FOREIGN KEY (id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_channels (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    provider VARCHAR(40) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    config_ciphertext LONGTEXT NOT NULL,
    events_json TEXT NOT NULL,
    last_test_at DATETIME NULL,
    last_status VARCHAR(16) NOT NULL DEFAULT 'unknown',
    last_error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notification_channels_enabled (enabled, provider),
    KEY idx_notification_channels_status (last_status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_templates (
    event_key VARCHAR(40) NOT NULL,
    title_template VARCHAR(500) NOT NULL,
    body_template TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id BIGINT UNSIGNED NULL,
    event_key VARCHAR(40) NOT NULL,
    status ENUM('sent','failed','skipped') NOT NULL,
    title_rendered VARCHAR(500) NULL,
    error_message VARCHAR(255) NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notification_deliveries_channel (channel_id, attempted_at),
    KEY idx_notification_deliveries_status (status, attempted_at),
    CONSTRAINT fk_notification_deliveries_channel FOREIGN KEY (channel_id) REFERENCES notification_channels (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notification_templates (event_key, title_template, body_template) VALUES
    ('test', '[{{ app_name }}] Test de {{ channel_name }}', 'Ceci est un message de test envoyé par {{ app_name }} à {{ timestamp }}.'),
    ('monitor_down', '[{{ app_name }}] {{ domain }} est hors ligne', '{{ count }} service{% if count > 1 %}s sont{% else %} est{% endif %} indisponible{% if count > 1 %}s{% endif %} : {{ sites }}. {{ message }}'),
    ('monitor_up', '[{{ app_name }}] {{ domain }} est rétabli', '{{ count }} service{% if count > 1 %}s sont{% else %} est{% endif %} de retour en ligne : {{ sites }}. {{ message }}'),
    ('incident_open', '[{{ app_name }}] Incident ouvert · {{ domain }}', 'Un incident est ouvert pour {{ sites }}. {{ message }}'),
    ('incident_resolved', '[{{ app_name }}] Incident résolu · {{ domain }}', 'L’incident concernant {{ sites }} est résolu. {{ message }}')
ON DUPLICATE KEY UPDATE event_key = VALUES(event_key);

INSERT INTO monitoring_public_runtime_state (singleton_id, service_name, active_engine)
VALUES (1, 'insight', 'unknown')
ON DUPLICATE KEY UPDATE singleton_id = VALUES(singleton_id);

INSERT INTO monitoring_calc_settings (singleton_id, switch_at, default_calc_method)
VALUES (1, CURRENT_TIMESTAMP, 'time_weighted')
ON DUPLICATE KEY UPDATE singleton_id = VALUES(singleton_id);
