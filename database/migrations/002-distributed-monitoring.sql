ALTER TABLE sites
    ADD COLUMN IF NOT EXISTS probe_replication_factor SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER http_primary_redirect,
    ADD COLUMN IF NOT EXISTS probe_success_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER probe_replication_factor,
    ADD COLUMN IF NOT EXISTS probe_failure_quorum SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER probe_success_quorum;

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
    KEY idx_monitoring_assignments_active (active, site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS monitoring_agent_requests (
    node_id BIGINT UNSIGNED NOT NULL,
    nonce_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    received_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (node_id, nonce_hash),
    KEY idx_monitoring_agent_requests_received (received_at)
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
    KEY idx_monitoring_agent_batches_received (received_at)
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
    KEY idx_monitoring_observations_received (received_at)
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
    KEY idx_monitoring_consensus_status (status, evaluated_at)
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
    KEY idx_monitoring_consensus_snapshots_bucket (bucket_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
