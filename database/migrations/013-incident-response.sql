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

ALTER TABLE incidents
    ADD COLUMN IF NOT EXISTS incident_group_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS runbook_id INT NULL AFTER incident_group_id,
    ADD COLUMN IF NOT EXISTS metadata JSON NULL AFTER summary,
    ADD KEY IF NOT EXISTS idx_incidents_group (incident_group_id, started_at),
    ADD KEY IF NOT EXISTS idx_incidents_runbook (runbook_id);

SET @statement = IF(
    EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='incidents' AND COLUMN_NAME='incident_group_id' AND REFERENCED_TABLE_NAME='incident_groups'),
    'SELECT 1',
    'ALTER TABLE incidents ADD CONSTRAINT fk_incidents_group FOREIGN KEY (incident_group_id) REFERENCES incident_groups (id) ON DELETE SET NULL'
);
PREPARE insight_migration FROM @statement;
EXECUTE insight_migration;
DEALLOCATE PREPARE insight_migration;

SET @statement = IF(
    EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='incidents' AND COLUMN_NAME='runbook_id' AND REFERENCED_TABLE_NAME='runbooks'),
    'SELECT 1',
    'ALTER TABLE incidents ADD CONSTRAINT fk_incidents_runbook FOREIGN KEY (runbook_id) REFERENCES runbooks (id) ON DELETE SET NULL'
);
PREPARE insight_migration FROM @statement;
EXECUTE insight_migration;
DEALLOCATE PREPARE insight_migration;

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
