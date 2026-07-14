ALTER TABLE sites
    ADD COLUMN IF NOT EXISTS probe_config_ciphertext LONGTEXT NULL AFTER basic_auth_password_ciphertext,
    ADD COLUMN IF NOT EXISTS browser_script MEDIUMTEXT NULL AFTER probe_config_ciphertext,
    ADD COLUMN IF NOT EXISTS diagnostics_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER browser_script,
    ADD COLUMN IF NOT EXISTS diagnostic_capture_body TINYINT(1) NOT NULL DEFAULT 0 AFTER diagnostics_enabled;

ALTER TABLE sites
    DROP INDEX IF EXISTS uniq_sites_url,
    ADD UNIQUE KEY IF NOT EXISTS uniq_sites_target_type (url, probe_type);

ALTER TABLE hourly_stats
    ADD COLUMN IF NOT EXISTS availability_basis_seconds INT NOT NULL DEFAULT 0 AFTER availability_ratio,
    ADD COLUMN IF NOT EXISTS method_details JSON NULL AFTER calc_method;

ALTER TABLE daily_stats
    ADD COLUMN IF NOT EXISTS availability_basis_seconds INT NOT NULL DEFAULT 0 AFTER availability_ratio,
    ADD COLUMN IF NOT EXISTS method_details JSON NULL AFTER calc_method;

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
