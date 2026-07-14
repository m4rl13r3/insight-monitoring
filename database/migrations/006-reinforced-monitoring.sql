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
