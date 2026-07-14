ALTER TABLE sites
    ADD COLUMN IF NOT EXISTS name VARCHAR(160) NULL AFTER url,
    ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1 AFTER probe_type,
    ADD COLUMN IF NOT EXISTS timeout_sec SMALLINT UNSIGNED NOT NULL DEFAULT 10 AFTER probe_interval_sec,
    ADD COLUMN IF NOT EXISTS retry_count TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER timeout_sec,
    ADD COLUMN IF NOT EXISTS failure_threshold TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER retry_count,
    ADD COLUMN IF NOT EXISTS recovery_threshold TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER failure_threshold,
    ADD COLUMN IF NOT EXISTS accepted_status_codes VARCHAR(255) NOT NULL DEFAULT '200-399' AFTER http_primary_redirect,
    ADD COLUMN IF NOT EXISTS keyword_text TEXT NULL AFTER accepted_status_codes,
    ADD COLUMN IF NOT EXISTS keyword_mode ENUM('none','contains','absent') NOT NULL DEFAULT 'none' AFTER keyword_text,
    ADD COLUMN IF NOT EXISTS json_path VARCHAR(500) NULL AFTER keyword_mode,
    ADD COLUMN IF NOT EXISTS json_expected_value TEXT NULL AFTER json_path,
    ADD COLUMN IF NOT EXISTS request_headers_json TEXT NULL AFTER json_expected_value,
    ADD COLUMN IF NOT EXISTS request_body MEDIUMTEXT NULL AFTER request_headers_json,
    ADD COLUMN IF NOT EXISTS basic_auth_username VARCHAR(255) NULL AFTER request_body,
    ADD COLUMN IF NOT EXISTS basic_auth_password_ciphertext TEXT NULL AFTER basic_auth_username,
    ADD COLUMN IF NOT EXISTS tls_verify TINYINT(1) NOT NULL DEFAULT 1 AFTER basic_auth_password_ciphertext,
    ADD COLUMN IF NOT EXISTS tls_expiry_threshold_days SMALLINT UNSIGNED NOT NULL DEFAULT 14 AFTER tls_verify,
    ADD COLUMN IF NOT EXISTS dns_record_type VARCHAR(12) NOT NULL DEFAULT 'A' AFTER tls_expiry_threshold_days,
    ADD COLUMN IF NOT EXISTS dns_expected_value VARCHAR(500) NULL AFTER dns_record_type,
    ADD COLUMN IF NOT EXISTS heartbeat_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER dns_expected_value,
    ADD COLUMN IF NOT EXISTS heartbeat_grace_sec INT UNSIGNED NOT NULL DEFAULT 300 AFTER heartbeat_token_hash,
    ADD COLUMN IF NOT EXISTS slo_target_percent DECIMAL(7,4) NOT NULL DEFAULT 99.9000 AFTER heartbeat_grace_sec,
    ADD COLUMN IF NOT EXISTS public_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER slo_target_percent,
    ADD UNIQUE KEY IF NOT EXISTS uniq_sites_heartbeat_token (heartbeat_token_hash),
    ADD KEY IF NOT EXISTS idx_sites_active_type (active, probe_type);

ALTER TABLE sites
    MODIFY COLUMN http_methods VARCHAR(128) NOT NULL DEFAULT 'GET',
    MODIFY COLUMN http_redirect_modes VARCHAR(32) NOT NULL DEFAULT 'follow';

UPDATE sites
SET http_methods = 'GET', http_redirect_modes = 'follow'
WHERE http_methods = 'GET,POST,PUT,HEAD,DELETE,PATCH,OPTIONS'
  AND http_redirect_modes = 'follow,no_follow';

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

ALTER TABLE incidents
    ADD COLUMN IF NOT EXISTS title VARCHAR(200) NULL AFTER incident_code,
    ADD COLUMN IF NOT EXISTS summary TEXT NULL AFTER title,
    ADD COLUMN IF NOT EXISTS severity ENUM('info','minor','major','critical') NOT NULL DEFAULT 'major' AFTER summary,
    ADD COLUMN IF NOT EXISTS lifecycle_status ENUM('started','monitoring','acknowledged','resolved') NOT NULL DEFAULT 'started' AFTER severity,
    ADD COLUMN IF NOT EXISTS acknowledged_at DATETIME NULL AFTER ended_at,
    ADD COLUMN IF NOT EXISTS acknowledged_by VARCHAR(140) NULL AFTER acknowledged_at,
    ADD COLUMN IF NOT EXISTS resolved_by VARCHAR(140) NULL AFTER acknowledged_by,
    ADD COLUMN IF NOT EXISTS published TINYINT(1) NOT NULL DEFAULT 1 AFTER resolved_by,
    ADD KEY IF NOT EXISTS idx_incidents_lifecycle (lifecycle_status, severity, started_at);

UPDATE incidents
SET lifecycle_status = IF(ended_at IS NULL AND (resolved IS NULL OR resolved = 0), 'started', 'resolved')
WHERE lifecycle_status = 'started' AND ended_at IS NOT NULL;

CREATE TABLE IF NOT EXISTS incident_sites (
    incident_id INT NOT NULL,
    site_id INT NOT NULL,
    PRIMARY KEY (incident_id, site_id),
    KEY idx_incident_sites_site (site_id, incident_id),
    CONSTRAINT fk_incident_sites_incident FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE CASCADE,
    CONSTRAINT fk_incident_sites_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO incident_sites (incident_id, site_id)
SELECT id, site_id FROM incidents WHERE site_id IS NOT NULL;

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

ALTER TABLE scheduled_maintenances
    ADD COLUMN IF NOT EXISTS timezone VARCHAR(64) NOT NULL DEFAULT 'UTC' AFTER ends_at,
    ADD COLUMN IF NOT EXISTS recurrence ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none' AFTER timezone,
    ADD COLUMN IF NOT EXISTS recurrence_interval SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER recurrence,
    ADD COLUMN IF NOT EXISTS recurrence_until DATETIME NULL AFTER recurrence_interval,
    ADD COLUMN IF NOT EXISTS last_occurrence_at DATETIME NULL AFTER recurrence_until,
    ADD KEY IF NOT EXISTS idx_maintenance_recurrence (recurrence, recurrence_until);

CREATE TABLE IF NOT EXISTS maintenance_sites (
    maintenance_id INT NOT NULL,
    site_id INT NOT NULL,
    PRIMARY KEY (maintenance_id, site_id),
    KEY idx_maintenance_sites_site (site_id, maintenance_id),
    CONSTRAINT fk_maintenance_sites_maintenance FOREIGN KEY (maintenance_id) REFERENCES scheduled_maintenances (id) ON DELETE CASCADE,
    CONSTRAINT fk_maintenance_sites_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO maintenance_sites (maintenance_id, site_id)
SELECT id, site_id FROM scheduled_maintenances WHERE site_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS status_pages (
    id INT NOT NULL AUTO_INCREMENT,
    slug VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    custom_domain VARCHAR(255) NULL,
    visibility ENUM('public','private') NOT NULL DEFAULT 'public',
    password_hash VARCHAR(255) NULL,
    theme ENUM('system','light','dark') NOT NULL DEFAULT 'system',
    accent_color CHAR(7) NOT NULL DEFAULT '#16a34a',
    locale VARCHAR(8) NOT NULL DEFAULT 'auto',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_status_pages_slug (slug),
    UNIQUE KEY uniq_status_pages_domain (custom_domain),
    KEY idx_status_pages_enabled (enabled, visibility)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO status_pages (slug, name, description)
VALUES ('default', 'Insight', 'Public service status')
ON DUPLICATE KEY UPDATE slug = VALUES(slug);

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

ALTER TABLE notification_channels
    ADD COLUMN IF NOT EXISTS minimum_severity ENUM('info','minor','major','critical') NOT NULL DEFAULT 'info' AFTER events_json;

ALTER TABLE notification_deliveries
    ADD COLUMN IF NOT EXISTS idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER event_key,
    ADD UNIQUE KEY IF NOT EXISTS uniq_notification_delivery_idempotency (channel_id, idempotency_key);

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

INSERT INTO notification_templates (event_key, title_template, body_template) VALUES
    ('incident_update', '[{{ app_name }}] Incident update - {{ domain }}', 'A new update was published for {{ sites }}. {{ message }}'),
    ('incident_acknowledged', '[{{ app_name }}] Incident acknowledged - {{ domain }}', 'The incident affecting {{ sites }} was acknowledged. {{ message }}'),
    ('tls_expiring', '[{{ app_name }}] TLS certificate expires soon - {{ domain }}', 'The TLS certificate for {{ sites }} expires in {{ days_remaining }} days. {{ message }}'),
    ('tls_invalid', '[{{ app_name }}] Invalid TLS certificate - {{ domain }}', 'The TLS certificate for {{ sites }} is invalid. {{ message }}'),
    ('maintenance_started', '[{{ app_name }}] Maintenance started - {{ domain }}', 'Scheduled maintenance has started for {{ sites }}. {{ message }}'),
    ('maintenance_ended', '[{{ app_name }}] Maintenance completed - {{ domain }}', 'Scheduled maintenance has completed for {{ sites }}. {{ message }}')
ON DUPLICATE KEY UPDATE event_key = VALUES(event_key);
