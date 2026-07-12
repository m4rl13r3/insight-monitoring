ALTER TABLE hourly_stats
    ADD COLUMN IF NOT EXISTS unknown_seconds INT NOT NULL DEFAULT 0 AFTER maintenance_seconds,
    ADD COLUMN IF NOT EXISTS sample_count INT NOT NULL DEFAULT 0 AFTER unknown_seconds,
    ADD COLUMN IF NOT EXISTS response_time_sum DECIMAL(16,3) NOT NULL DEFAULT 0 AFTER sample_count;

ALTER TABLE daily_stats
    ADD COLUMN IF NOT EXISTS unknown_seconds INT NOT NULL DEFAULT 0 AFTER maintenance_seconds,
    ADD COLUMN IF NOT EXISTS sample_count INT NOT NULL DEFAULT 0 AFTER unknown_seconds,
    ADD COLUMN IF NOT EXISTS response_time_sum DECIMAL(16,3) NOT NULL DEFAULT 0 AFTER sample_count;

CREATE TABLE IF NOT EXISTS monitoring_aggregation_state (
    job_name VARCHAR(64) NOT NULL,
    last_success_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (job_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
