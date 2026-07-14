# Changelog

All notable changes to Insight are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [0.1.4] - 2026-07-14

### Added

- GitHub Container Registry publication for versioned PHP, worker, and web images.
- A private Docker-based GitHub Codespaces environment for trying Insight without local installation.

### Changed

- Docker Compose can use published images without rebuilding them locally.
- GitHub Actions now use the current checkout, Node.js, and Python setup actions.
- The PHP runtime is now based on PHP 8.5.

### Security

- The Codespaces profile uses isolated test-only data and keeps its forwarded port private by default.

## [0.1.3] - 2026-07-13

### Added

- Persistent post-recovery reinforced monitoring with faster local and distributed probes, shorter consensus windows, dashboard visibility, runtime state, and Prometheus metrics.
- Multi-page public publishing with monitor groups, custom domains, private password access, and confirmed email subscriptions.
- Scheduled maintenance recurrence, full incident lifecycle updates, publication controls, and postmortem management.
- Thirty-day service objectives with weighted availability and remaining error budgets.
- Timezone-aware on-call rotations with monitor routing, severity thresholds, bounded repeats, retries, and delivery history.
- Headless API coverage for maintenance, status pages, and on-call rotations.

### Changed

- Public documentation, contribution guidance, source comments, operational output, API descriptions, and test diagnostics now use English.
- English is now the source fallback for translatable interface markup while the complete French catalogue remains available.
- Default notification templates now use English; the migration preserves every customized template.
- Monitor management, local checks, distributed consensus, hourly and daily aggregation, retention, and runtime state now execute directly in Python.
- Newly created monitors appear in the public API before their first hourly aggregation.
- Distributed agents accept observations only for their active assignments, and consensus creates the same incidents and notifications as standalone monitoring.
- Public data is strictly scoped to the selected status page and excludes unpublished incidents, internal updates, and private maintenance announcements.

### Removed

- Obsolete PHP monitoring wrappers, duplicated report code, shadow comparison tables, and the shell scheduler.

### Security

- Private status page sessions are signed, expire server-side, are invalidated by password changes, and have independent brute-force protection.
- Internal on-call escalations cannot notify public subscribers.
- Upgraded distributed installations now enforce the same cascading foreign keys as fresh databases.

## [0.1.2] - 2026-07-13

### Added

- Manual or scheduled updates from stable Git tags without recreating configuration or volumes.
- Pre-update backup, idempotent SQL migrations, health checks, and automatic rollback to the previous code.
- Optional user-level systemd timer and dedicated operations guide.

## [0.1.1] - 2026-07-13

### Added

- Incremental aggregation watermarks and configurable retention for probes, statistics, and TLS checks.
- Explicit preservation of time without observations in hourly and daily aggregates.
- MariaDB tests for precision, cleanup, and cascading deletion of monitoring data.

### Changed

- Aggregations recalculate a recent window or the period since the last successful run instead of rescanning the entire history.
- Cleanup works in batches and remains blocked when source aggregations are not current.

## [0.1.0] - 2026-07-13

### Added

- Bilingual public status page with light, dark, and system themes.
- HTTP, ICMP, and TCP probes with hourly and daily statistics.
- Automatic incidents, scheduled maintenance, and TLS tracking.
- Local administration with authentication and monitor creation, editing, and deletion.
- Encrypted multi-channel alerts with SMTP, webhooks, Free Mobile, and more than 138 Apprise services.
- Customizable Liquid notification templates and delivery history.
- Distributed hub/agent mode with signed observations and configurable consensus.
- Docker Compose deployment, smoke tests, and CI validation.
- Controlled backup and restore for MariaDB and local identity data.
- Reproducible release export without Git history or runtime data.
- Versioned headless API with scoped, expiring, and revocable tokens.
- OpenID Connect provider for authenticating third-party dashboards.
- Dashboard login through external OIDC SSO with email and group policies.
- Access screen and integration guide for API, OAuth 2.0, and SSO.
- Strict production validation and scheduled backups with retention and optional rclone copies.

### Changed

- Python is now the only local monitoring engine.
- Distributed consensus publishes its state directly to the public API.
- Monitor, hourly, and daily tasks independently preserve their latest state.

### Removed

- Legacy PHP fallback engine and its configuration variables.

### Security

- Secrets supplied exclusively through environment variables.
- Administration API protected by sessions and CSRF tokens.
- Signed distributed agents with replay protection and HTTPS enforcement by default.
- Application containers run without root privileges.
- Standalone agent image with pinned Python dependencies.
- Channel secrets encrypted with libsodium SecretBox and masked in the administration API.
- Authorization Code with PKCE S256, exact redirect URIs, single-use codes, and RS256 ID Tokens.
- API and OAuth secrets shown once and then stored only as hashes.
