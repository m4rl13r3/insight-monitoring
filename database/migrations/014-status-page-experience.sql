ALTER TABLE status_pages
    ADD COLUMN IF NOT EXISTS access_policy ENUM('public','password','sso','ip_allowlist') NOT NULL DEFAULT 'public' AFTER visibility,
    ADD COLUMN IF NOT EXISTS ip_allowlist TEXT NULL AFTER password_hash,
    ADD COLUMN IF NOT EXISTS logo_url VARCHAR(1000) NULL AFTER accent_color,
    ADD COLUMN IF NOT EXISTS favicon_url VARCHAR(1000) NULL AFTER logo_url,
    ADD COLUMN IF NOT EXISTS announcement VARCHAR(1000) NULL AFTER favicon_url,
    ADD COLUMN IF NOT EXISTS announcement_url VARCHAR(1000) NULL AFTER announcement,
    ADD COLUMN IF NOT EXISTS navigation_links_json TEXT NULL AFTER announcement_url,
    ADD COLUMN IF NOT EXISTS custom_css MEDIUMTEXT NULL AFTER navigation_links_json,
    ADD COLUMN IF NOT EXISTS history_days SMALLINT UNSIGNED NOT NULL DEFAULT 90 AFTER custom_css,
    ADD COLUMN IF NOT EXISTS hide_from_search_engines TINYINT(1) NOT NULL DEFAULT 0 AFTER history_days;

UPDATE status_pages
SET access_policy = IF(visibility = 'private', 'password', 'public')
WHERE access_policy = 'public' AND visibility = 'private';

CREATE TABLE IF NOT EXISTS status_page_subscriber_sites (
    subscriber_id BIGINT UNSIGNED NOT NULL,
    site_id INT NOT NULL,
    PRIMARY KEY (subscriber_id, site_id),
    KEY idx_status_page_subscriber_sites_site (site_id, subscriber_id),
    CONSTRAINT fk_status_page_subscriber_sites_subscriber FOREIGN KEY (subscriber_id) REFERENCES status_page_subscribers (id) ON DELETE CASCADE,
    CONSTRAINT fk_status_page_subscriber_sites_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
