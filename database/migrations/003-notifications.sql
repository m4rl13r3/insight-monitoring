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
