ALTER TABLE oncall_schedules
    ADD COLUMN IF NOT EXISTS escalation_delay_minutes INT UNSIGNED NOT NULL DEFAULT 5 AFTER enabled,
    ADD COLUMN IF NOT EXISTS repeat_interval_minutes INT UNSIGNED NOT NULL DEFAULT 15 AFTER escalation_delay_minutes,
    ADD COLUMN IF NOT EXISTS maximum_repeats SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER repeat_interval_minutes,
    ADD COLUMN IF NOT EXISTS minimum_severity ENUM('info','minor','major','critical') NOT NULL DEFAULT 'major' AFTER maximum_repeats;

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
