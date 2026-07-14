CREATE TABLE IF NOT EXISTS sites (
    id INT NOT NULL AUTO_INCREMENT,
    url VARCHAR(255) NOT NULL,
    name VARCHAR(160) NULL,
    probe_type VARCHAR(16) NOT NULL DEFAULT 'http',
    active TINYINT(1) NOT NULL DEFAULT 1,
    probe_interval_sec INT NOT NULL DEFAULT 60,
    timeout_sec SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    retry_count TINYINT UNSIGNED NOT NULL DEFAULT 2,
    failure_threshold TINYINT UNSIGNED NOT NULL DEFAULT 2,
    recovery_threshold TINYINT UNSIGNED NOT NULL DEFAULT 2,
    calc_method VARCHAR(24) NOT NULL DEFAULT 'inherit',
    http_methods VARCHAR(128) NOT NULL DEFAULT 'GET',
    http_redirect_modes VARCHAR(32) NOT NULL DEFAULT 'follow',
    http_primary_method VARCHAR(16) NOT NULL DEFAULT 'GET',
    http_primary_redirect VARCHAR(16) NOT NULL DEFAULT 'follow',
    accepted_status_codes VARCHAR(255) NOT NULL DEFAULT '200-399',
    keyword_text TEXT NULL,
    keyword_mode ENUM('none','contains','absent') NOT NULL DEFAULT 'none',
    json_path VARCHAR(500) NULL,
    json_expected_value TEXT NULL,
    request_headers_json TEXT NULL,
    request_body MEDIUMTEXT NULL,
    basic_auth_username VARCHAR(255) NULL,
    basic_auth_password_ciphertext TEXT NULL,
    probe_config_ciphertext LONGTEXT NULL,
    browser_script MEDIUMTEXT NULL,
    diagnostics_enabled TINYINT(1) NOT NULL DEFAULT 1,
    diagnostic_capture_body TINYINT(1) NOT NULL DEFAULT 0,
    tls_verify TINYINT(1) NOT NULL DEFAULT 1,
    tls_expiry_threshold_days SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    dns_record_type VARCHAR(12) NOT NULL DEFAULT 'A',
    dns_expected_value VARCHAR(500) NULL,
    heartbeat_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    heartbeat_grace_sec INT UNSIGNED NOT NULL DEFAULT 300,
    slo_target_percent DECIMAL(7,4) NOT NULL DEFAULT 99.9000,
    public_visible TINYINT(1) NOT NULL DEFAULT 1,
    probe_replication_factor SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    probe_success_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    probe_failure_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_sites_target_type (url, probe_type),
    UNIQUE KEY uniq_sites_heartbeat_token (heartbeat_token_hash),
    KEY idx_sites_active_type (active, probe_type)
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
    last_seen_at DATETIME(3) NULL DEFAULT NULL,
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

CREATE TABLE IF NOT EXISTS probe_diagnostics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id INT NOT NULL,
    probe_id BIGINT UNSIGNED NULL,
    status ENUM('online','offline','degraded','unknown') NOT NULL DEFAULT 'unknown',
    error_code VARCHAR(120) NULL,
    timing_json JSON NULL,
    response_headers_json JSON NULL,
    body_excerpt TEXT NULL,
    artifact_path VARCHAR(500) NULL,
    network_json JSON NULL,
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY idx_probe_diagnostics_site_time (site_id, created_at),
    KEY idx_probe_diagnostics_probe (probe_id),
    CONSTRAINT fk_probe_diagnostics_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
    CONSTRAINT fk_probe_diagnostics_probe FOREIGN KEY (probe_id) REFERENCES probes (id) ON DELETE SET NULL
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
    unknown_seconds INT NOT NULL DEFAULT 0,
    sample_count INT NOT NULL DEFAULT 0,
    response_time_sum DECIMAL(16,3) NOT NULL DEFAULT 0,
    availability_ratio DECIMAL(6,4) NULL,
    availability_basis_seconds INT NOT NULL DEFAULT 0,
    health_score DECIMAL(6,4) NULL,
    calc_method VARCHAR(24) NULL,
    method_details JSON NULL,
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
    unknown_seconds INT NOT NULL DEFAULT 0,
    sample_count INT NOT NULL DEFAULT 0,
    response_time_sum DECIMAL(16,3) NOT NULL DEFAULT 0,
    availability_ratio DECIMAL(6,4) NULL,
    availability_basis_seconds INT NOT NULL DEFAULT 0,
    health_score DECIMAL(6,4) NULL,
    calc_method VARCHAR(24) NULL,
    method_details JSON NULL,
    checked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_daily_site_date (site_id, date),
    KEY idx_daily_date (date),
    KEY idx_daily_site_checked (site_id, checked_at),
    CONSTRAINT fk_daily_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incident_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    title VARCHAR(200) NOT NULL,
    state ENUM('open','resolved') NOT NULL DEFAULT 'open',
    occurrence_count INT UNSIGNED NOT NULL DEFAULT 1,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_incident_groups_fingerprint (fingerprint),
    KEY idx_incident_groups_state_seen (state, last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS runbooks (
    id INT NOT NULL AUTO_INCREMENT,
    slug VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    content MEDIUMTEXT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_runbooks_slug (slug),
    KEY idx_runbooks_enabled (enabled, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incidents (
    id INT NOT NULL AUTO_INCREMENT,
    incident_group_id BIGINT UNSIGNED NULL,
    runbook_id INT NULL,
    site_id INT NULL,
    incident_code VARCHAR(64) NULL,
    title VARCHAR(200) NULL,
    summary TEXT NULL,
    metadata JSON NULL,
    severity ENUM('info','minor','major','critical') NOT NULL DEFAULT 'major',
    lifecycle_status ENUM('started','monitoring','acknowledged','resolved') NOT NULL DEFAULT 'started',
    started_at DATETIME NOT NULL,
    incident_date DATETIME NULL,
    ended_at DATETIME NULL,
    acknowledged_at DATETIME NULL,
    acknowledged_by VARCHAR(140) NULL,
    resolved_by VARCHAR(140) NULL,
    published TINYINT(1) NOT NULL DEFAULT 1,
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
    KEY idx_incidents_group (incident_group_id, started_at),
    KEY idx_incidents_runbook (runbook_id),
    KEY idx_incidents_lifecycle (lifecycle_status, severity, started_at),
    KEY idx_incidents_started (started_at),
    CONSTRAINT fk_incidents_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE SET NULL,
    CONSTRAINT fk_incidents_group FOREIGN KEY (incident_group_id) REFERENCES incident_groups (id) ON DELETE SET NULL,
    CONSTRAINT fk_incidents_runbook FOREIGN KEY (runbook_id) REFERENCES runbooks (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_reinforced_watch (
    site_id INT NOT NULL,
    incident_id INT NULL,
    source_mode VARCHAR(16) NOT NULL DEFAULT 'system',
    started_at DATETIME(3) NOT NULL,
    ends_at DATETIME(3) NOT NULL,
    interval_sec SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (site_id),
    KEY idx_monitoring_reinforced_watch_ends (ends_at),
    KEY idx_monitoring_reinforced_watch_incident (incident_id),
    CONSTRAINT fk_monitoring_reinforced_watch_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
    CONSTRAINT fk_monitoring_reinforced_watch_incident FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduled_maintenances (
    id INT NOT NULL AUTO_INCREMENT,
    site_id INT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    recurrence ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none',
    recurrence_interval SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    recurrence_until DATETIME NULL,
    last_occurrence_at DATETIME NULL,
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
    KEY idx_maintenance_recurrence (recurrence, recurrence_until),
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
    default_calc_method VARCHAR(24) NOT NULL DEFAULT 'interval_capped',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (singleton_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_aggregation_state (
    job_name VARCHAR(64) NOT NULL,
    last_success_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (job_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS insight_schema_migrations (
    version VARCHAR(128) NOT NULL,
    checksum CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
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

CREATE TABLE IF NOT EXISTS monitoring_check_state (
    site_id INT NOT NULL,
    effective_status ENUM('online','offline','degraded','unknown','paused') NOT NULL DEFAULT 'unknown',
    consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    consecutive_successes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_raw_status ENUM('online','offline','degraded','unknown') NOT NULL DEFAULT 'unknown',
    last_error VARCHAR(500) NULL,
    last_change_at DATETIME(3) NULL,
    last_heartbeat_at DATETIME(3) NULL,
    updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (site_id),
    KEY idx_monitoring_check_state_status (effective_status, updated_at),
    CONSTRAINT fk_monitoring_check_state_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incident_sites (
    incident_id INT NOT NULL,
    site_id INT NOT NULL,
    PRIMARY KEY (incident_id, site_id),
    KEY idx_incident_sites_site (site_id, incident_id),
    CONSTRAINT fk_incident_sites_incident FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE CASCADE,
    CONSTRAINT fk_incident_sites_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incident_updates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    incident_id INT NOT NULL,
    lifecycle_status ENUM('started','monitoring','acknowledged','resolved') NOT NULL DEFAULT 'monitoring',
    message TEXT NOT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 1,
    author_user_id INT NULL,
    author_name VARCHAR(140) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_incident_updates_incident (incident_id, created_at, id),
    CONSTRAINT fk_incident_updates_incident FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incident_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    incident_id INT NOT NULL,
    body TEXT NOT NULL,
    author_user_id INT NULL,
    author_name VARCHAR(140) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_incident_comments_incident (incident_id, created_at, id),
    CONSTRAINT fk_incident_comments_incident FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incident_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    incident_id INT NOT NULL,
    comment_id BIGINT UNSIGNED NULL,
    stored_name CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    media_type VARCHAR(120) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_incident_attachments_stored (stored_name),
    KEY idx_incident_attachments_incident (incident_id, created_at),
    CONSTRAINT fk_incident_attachments_incident FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE CASCADE,
    CONSTRAINT fk_incident_attachments_comment FOREIGN KEY (comment_id) REFERENCES incident_comments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_sites (
    maintenance_id INT NOT NULL,
    site_id INT NOT NULL,
    PRIMARY KEY (maintenance_id, site_id),
    KEY idx_maintenance_sites_site (site_id, maintenance_id),
    CONSTRAINT fk_maintenance_sites_maintenance FOREIGN KEY (maintenance_id) REFERENCES scheduled_maintenances (id) ON DELETE CASCADE,
    CONSTRAINT fk_maintenance_sites_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status_pages (
    id INT NOT NULL AUTO_INCREMENT,
    slug VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    custom_domain VARCHAR(255) NULL,
    visibility ENUM('public','private') NOT NULL DEFAULT 'public',
    access_policy ENUM('public','password','sso','ip_allowlist') NOT NULL DEFAULT 'public',
    password_hash VARCHAR(255) NULL,
    ip_allowlist TEXT NULL,
    theme ENUM('system','light','dark') NOT NULL DEFAULT 'system',
    accent_color CHAR(7) NOT NULL DEFAULT '#16a34a',
    logo_url VARCHAR(1000) NULL,
    favicon_url VARCHAR(1000) NULL,
    announcement VARCHAR(1000) NULL,
    announcement_url VARCHAR(1000) NULL,
    navigation_links_json TEXT NULL,
    custom_css MEDIUMTEXT NULL,
    history_days SMALLINT UNSIGNED NOT NULL DEFAULT 90,
    hide_from_search_engines TINYINT(1) NOT NULL DEFAULT 0,
    locale VARCHAR(8) NOT NULL DEFAULT 'auto',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_status_pages_slug (slug),
    UNIQUE KEY uniq_status_pages_domain (custom_domain),
    KEY idx_status_pages_enabled (enabled, visibility)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status_page_groups (
    id INT NOT NULL AUTO_INCREMENT,
    status_page_id INT NOT NULL,
    name VARCHAR(160) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    collapsed TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_status_page_groups_page (status_page_id, sort_order, id),
    CONSTRAINT fk_status_page_groups_page FOREIGN KEY (status_page_id) REFERENCES status_pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status_page_monitors (
    status_page_id INT NOT NULL,
    site_id INT NOT NULL,
    group_id INT NULL,
    display_name VARCHAR(160) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    visible TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (status_page_id, site_id),
    KEY idx_status_page_monitors_order (status_page_id, group_id, sort_order, site_id),
    KEY idx_status_page_monitors_site (site_id),
    CONSTRAINT fk_status_page_monitors_page FOREIGN KEY (status_page_id) REFERENCES status_pages (id) ON DELETE CASCADE,
    CONSTRAINT fk_status_page_monitors_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
    CONSTRAINT fk_status_page_monitors_group FOREIGN KEY (group_id) REFERENCES status_page_groups (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status_page_subscribers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    status_page_id INT NOT NULL,
    email VARCHAR(320) NOT NULL,
    locale VARCHAR(8) NOT NULL DEFAULT 'en',
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    confirmed_at DATETIME NULL,
    unsubscribed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_status_page_subscriber (status_page_id, email),
    UNIQUE KEY uniq_status_page_subscriber_token (token_hash),
    KEY idx_status_page_subscribers_active (status_page_id, confirmed_at, unsubscribed_at),
    CONSTRAINT fk_status_page_subscribers_page FOREIGN KEY (status_page_id) REFERENCES status_pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status_page_subscriber_sites (
    subscriber_id BIGINT UNSIGNED NOT NULL,
    site_id INT NOT NULL,
    PRIMARY KEY (subscriber_id, site_id),
    KEY idx_status_page_subscriber_sites_site (site_id, subscriber_id),
    CONSTRAINT fk_status_page_subscriber_sites_subscriber FOREIGN KEY (subscriber_id) REFERENCES status_page_subscribers (id) ON DELETE CASCADE,
    CONSTRAINT fk_status_page_subscriber_sites_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status_page_subscriber_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subscriber_id BIGINT UNSIGNED NOT NULL,
    event_key VARCHAR(40) NOT NULL,
    idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('sent','failed') NOT NULL,
    error_message VARCHAR(255) NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_status_page_subscriber_delivery (subscriber_id, idempotency_key),
    KEY idx_status_page_subscriber_deliveries_time (attempted_at),
    CONSTRAINT fk_status_page_subscriber_deliveries_subscriber FOREIGN KEY (subscriber_id) REFERENCES status_page_subscribers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status_page_subscription_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_page_subscription_attempts (identity_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS status_page_auth_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_page_auth_attempts (identity_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_channels (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    provider VARCHAR(40) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    config_ciphertext LONGTEXT NOT NULL,
    events_json TEXT NOT NULL,
    minimum_severity ENUM('info','minor','major','critical') NOT NULL DEFAULT 'info',
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
    idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    status ENUM('sent','failed','skipped') NOT NULL,
    title_rendered VARCHAR(500) NULL,
    error_message VARCHAR(255) NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notification_deliveries_channel (channel_id, attempted_at),
    KEY idx_notification_deliveries_status (status, attempted_at),
    UNIQUE KEY uniq_notification_delivery_idempotency (channel_id, idempotency_key),
    CONSTRAINT fk_notification_deliveries_channel FOREIGN KEY (channel_id) REFERENCES notification_channels (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_channel_sites (
    channel_id BIGINT UNSIGNED NOT NULL,
    site_id INT NOT NULL,
    PRIMARY KEY (channel_id, site_id),
    KEY idx_notification_channel_sites_site (site_id, channel_id),
    CONSTRAINT fk_notification_channel_sites_channel FOREIGN KEY (channel_id) REFERENCES notification_channels (id) ON DELETE CASCADE,
    CONSTRAINT fk_notification_channel_sites_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_worker_leases (
    lease_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_id VARCHAR(128) NOT NULL,
    acquired_at DATETIME(3) NOT NULL,
    heartbeat_at DATETIME(3) NOT NULL,
    expires_at DATETIME(3) NOT NULL,
    PRIMARY KEY (lease_name),
    KEY idx_monitoring_worker_leases_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oncall_schedules (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    escalation_delay_minutes INT UNSIGNED NOT NULL DEFAULT 5,
    repeat_interval_minutes INT UNSIGNED NOT NULL DEFAULT 15,
    maximum_repeats SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    minimum_severity ENUM('info','minor','major','critical') NOT NULL DEFAULT 'major',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oncall_members (
    id INT NOT NULL AUTO_INCREMENT,
    schedule_id INT NOT NULL,
    name VARCHAR(140) NOT NULL,
    email VARCHAR(320) NULL,
    phone VARCHAR(64) NULL,
    channel_id BIGINT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_oncall_members_schedule (schedule_id, active, sort_order, id),
    CONSTRAINT fk_oncall_members_schedule FOREIGN KEY (schedule_id) REFERENCES oncall_schedules (id) ON DELETE CASCADE,
    CONSTRAINT fk_oncall_members_channel FOREIGN KEY (channel_id) REFERENCES notification_channels (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oncall_shifts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id INT NOT NULL,
    member_id INT NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    recurrence ENUM('none','daily','weekly') NOT NULL DEFAULT 'weekly',
    PRIMARY KEY (id),
    KEY idx_oncall_shifts_active (schedule_id, starts_at, ends_at),
    CONSTRAINT fk_oncall_shifts_schedule FOREIGN KEY (schedule_id) REFERENCES oncall_schedules (id) ON DELETE CASCADE,
    CONSTRAINT fk_oncall_shifts_member FOREIGN KEY (member_id) REFERENCES oncall_members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oncall_schedule_sites (
    schedule_id INT NOT NULL,
    site_id INT NOT NULL,
    PRIMARY KEY (schedule_id, site_id),
    KEY idx_oncall_schedule_sites_site (site_id, schedule_id),
    CONSTRAINT fk_oncall_schedule_sites_schedule FOREIGN KEY (schedule_id) REFERENCES oncall_schedules (id) ON DELETE CASCADE,
    CONSTRAINT fk_oncall_schedule_sites_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oncall_escalation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    incident_id INT NOT NULL,
    schedule_id INT NOT NULL,
    member_id INT NOT NULL,
    sequence_no SMALLINT UNSIGNED NOT NULL,
    status ENUM('sent','failed') NOT NULL DEFAULT 'failed',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    last_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_oncall_escalation (incident_id, schedule_id, member_id, sequence_no),
    KEY idx_oncall_escalation_status (status, last_attempt_at),
    CONSTRAINT fk_oncall_escalation_incident FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE CASCADE,
    CONSTRAINT fk_oncall_escalation_schedule FOREIGN KEY (schedule_id) REFERENCES oncall_schedules (id) ON DELETE CASCADE,
    CONSTRAINT fk_oncall_escalation_member FOREIGN KEY (member_id) REFERENCES oncall_members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notification_templates (event_key, title_template, body_template) VALUES
    ('test', '[{{ app_name }}] Test from {{ channel_name }}', 'This is a test message sent by {{ app_name }} at {{ timestamp }}.'),
    ('monitor_down', '[{{ app_name }}] {{ domain }} is offline', '{{ count }} service{% if count > 1 %}s are{% else %} is{% endif %} unavailable: {{ sites }}. {{ message }}'),
    ('monitor_up', '[{{ app_name }}] {{ domain }} is back online', '{{ count }} service{% if count > 1 %}s are{% else %} is{% endif %} back online: {{ sites }}. {{ message }}'),
    ('incident_open', '[{{ app_name }}] Incident opened - {{ domain }}', 'An incident is open for {{ sites }}. {{ message }}'),
    ('incident_resolved', '[{{ app_name }}] Incident resolved - {{ domain }}', 'The incident affecting {{ sites }} is resolved. {{ message }}'),
    ('incident_update', '[{{ app_name }}] Incident update - {{ domain }}', 'A new update was published for {{ sites }}. {{ message }}'),
    ('incident_acknowledged', '[{{ app_name }}] Incident acknowledged - {{ domain }}', 'The incident affecting {{ sites }} was acknowledged. {{ message }}'),
    ('tls_expiring', '[{{ app_name }}] TLS certificate expires soon - {{ domain }}', 'The TLS certificate for {{ sites }} expires in {{ days_remaining }} days. {{ message }}'),
    ('tls_invalid', '[{{ app_name }}] Invalid TLS certificate - {{ domain }}', 'The TLS certificate for {{ sites }} is invalid. {{ message }}'),
    ('maintenance_started', '[{{ app_name }}] Maintenance started - {{ domain }}', 'Scheduled maintenance has started for {{ sites }}. {{ message }}'),
    ('maintenance_ended', '[{{ app_name }}] Maintenance completed - {{ domain }}', 'Scheduled maintenance has completed for {{ sites }}. {{ message }}')
ON DUPLICATE KEY UPDATE event_key = VALUES(event_key);

INSERT INTO status_pages (slug, name, description)
VALUES ('default', 'Insight', 'Public service status')
ON DUPLICATE KEY UPDATE slug = VALUES(slug);

INSERT INTO monitoring_public_runtime_state (singleton_id, service_name, active_engine)
VALUES (1, 'insight', 'unknown')
ON DUPLICATE KEY UPDATE singleton_id = VALUES(singleton_id);

INSERT INTO monitoring_calc_settings (singleton_id, switch_at, default_calc_method)
VALUES (1, CURRENT_TIMESTAMP, 'interval_capped')
ON DUPLICATE KEY UPDATE singleton_id = VALUES(singleton_id);
