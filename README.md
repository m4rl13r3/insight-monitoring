# Insight

Insight is a self-hosted open source status page with HTTP, ICMP, TCP, DNS, and heartbeat monitoring, content assertions, uptime history, incidents, scheduled maintenance, TLS tracking, service objectives, on-call escalation, and a protected dashboard. Its monitoring engine is written in Python. Insight can also aggregate probes from one, two, three, or as many remote servers as required.

The public interface is available in French and English, detects the browser language, and requires no private service. The reference deployment uses Docker Compose with Nginx, PHP-FPM, a worker, and MariaDB.

The application is rendered with PHP and JavaScript. React only manages the language and theme controls. The Vite build follows shadcn conventions and uses Tailwind as a CSS compiler; browsers only load the local static assets generated in `public/assets`.

The Font Awesome Free icons used by the interface are bundled as local WOFF2 fonts in `public/assets`. Their notice and full license are included in `THIRD_PARTY_NOTICES.md` and `licenses/FONT-AWESOME-FREE.txt`.

## Quick start

Requirements: Docker with the Compose plugin and OpenSSL.

```bash
./scripts/install.sh
```

The script creates `.env`, generates the instance secrets, builds the images, applies migrations, and starts Insight. On the first run it also creates the local `admin` account with a generated password printed once in the terminal. Compose rejects empty passwords.

To choose the initial credentials yourself, provide them with the installation command:

```bash
INSIGHT_ADMIN_USERNAME=owner INSIGHT_ADMIN_PASSWORD='choose-a-long-unique-password' ./scripts/install.sh
```

The installer never changes an existing administrator account.

## Try Insight

[![Open in GitHub Codespaces](https://github.com/codespaces/badge.svg)](https://codespaces.new/m4rl13r3/insight-monitoring)

Codespaces starts a temporary private Insight instance with Docker Compose and opens it on port `8080`. It runs in development mode with test-only credentials and data, so it is suitable for trying Insight or contributing but must not be used for a public demo or real monitoring. A user can open the **Ports** panel and copy the private URL after the stack is ready.

## Prebuilt container images

Every Git tag matching `v*` publishes the `php`, `worker`, and `web` images to GitHub Container Registry. A release such as `v0.1.4` produces:

```text
ghcr.io/m4rl13r3/insight-monitoring/php:v0.1.4
ghcr.io/m4rl13r3/insight-monitoring/worker:v0.1.4
ghcr.io/m4rl13r3/insight-monitoring/web:v0.1.4
```

To install a published release without building locally, create `.env` as usual, then run:

```bash
export INSIGHT_IMAGE_PREFIX=ghcr.io/m4rl13r3/insight-monitoring
export INSIGHT_IMAGE_TAG=v0.1.4
docker compose pull
docker compose up -d --no-build --wait --wait-timeout 180
./scripts/migrate.sh
```

The registry package must be public for anonymous pulls. The workflow publishes both the immutable release tag and `latest`.

For manual configuration, copy `.env.example`, set `INSIGHT_DB_PASSWORD`, `INSIGHT_DB_ROOT_PASSWORD`, and `INSIGHT_NOTIFICATION_ENCRYPTION_KEY` with the output of `openssl rand -hex 32`, then run `docker compose up -d --build`.

Insight is then available at `http://localhost:8080`.

Do not keep local defaults on a public instance. The [Production guide](docs/production.md) covers HTTPS, the first account, real monitors, alert testing, remote agents, and the final `./scripts/production-check.sh --strict` validation.

Open `http://localhost:8080/admin/` and sign in with the initial administrator account printed by the installer. Like Uptime Kuma, Insight uses an account local to the instance: no identity provider or external server is required. An external OpenID Connect provider can later be enabled without removing this local fallback access. Accounts, sessions, tokens, and identity keys are stored in the private `insight_auth` volume, separately from MariaDB monitoring data.

Add a first site:

```bash
docker compose exec worker python3 monitoring/python_monitoring/cli.py actions add --site-url https://example.com --probe-type http
```

To monitor only whether a server is online, use ICMP or a TCP port:

```bash
docker compose exec worker python3 monitoring/python_monitoring/cli.py actions add --site-url server.example.com --probe-type icmp
docker compose exec worker python3 monitoring/python_monitoring/cli.py actions add --site-url server.example.com:22 --probe-type tcp
```

Insight then records only the online or offline state, latency, and last check time. No system metrics agent is required.

The same actions are available in the dashboard under **Monitors -> New monitor** for HTTP/HTTPS and **Servers -> Add server** for ICMP or TCP. Each monitor can then be edited or deleted. In development mode without MariaDB, created targets are stored locally in `data/`, which is already excluded from release archives.

Alerts are configured under **Alerts**. Insight directly supports SMTP, HTTP webhooks, and Free Mobile, and uses Apprise for Discord, Telegram, Slack, Teams, ntfy, Gotify, PagerDuty, Opsgenie, Matrix, Signal, and more than 138 services. Each channel selects the events it receives and provides a test action. Titles and messages can be customized with Liquid variables.

The same screen configures recurring on-call rotations. An unacknowledged incident is routed to the active shift after a configurable delay, repeated a bounded number of times, filtered by severity and monitor, and stopped as soon as the incident is acknowledged or resolved. Every monitor also has a 30-day SLO target and error budget in the overview.

Under **Status pages**, separate public or password-protected pages can be created for different audiences, with monitor groups, custom domains, themes, languages, and email subscriptions. Subscription delivery reuses a tested SMTP alert channel and remains disabled until notifications are enabled explicitly.

List configured sites:

```bash
docker compose exec worker python3 monitoring/python_monitoring/cli.py actions list
```

The SQL schema is imported automatically when MariaDB starts for the first time. To completely reset a development instance:

```bash
docker compose down -v
docker compose up -d --build
```

This command deletes all local data.

## Docker services

- `web` serves public files with Nginx and forwards PHP scripts to PHP-FPM.
- `php` runs the public page, HTTP adapters, local SQLite authentication, and the dashboard.
- `worker` runs the Python monitoring engine, distributed consensus, retention, and hourly and daily aggregation.
- `db` stores sites, probes, statistics, incidents, and maintenance windows in MariaDB.

## Backup and restore

Create a consistent archive of MariaDB, local accounts, API clients, and the OIDC private key:

```bash
./scripts/backup.sh
```

The archive and its SHA-256 checksum are written to `backups/`, which is excluded from Git. The `.env` file is never included: store it separately in a secret vault because it contains, among other values, the key protecting alert channel credentials.

To restore an archive, first provide the correct `.env`, start the stack, and explicitly confirm the operation:

```bash
INSIGHT_RESTORE_CONFIRM=1 ./scripts/restore.sh backups/insight-YYYYmmddTHHMMSSZ.tar.gz
```

The script verifies the checksum when available, creates a safety backup, pauses the worker and web services, restores both databases, checks their integrity, and restarts the stack. Dashboard sessions are invalidated after a restore. Use `INSIGHT_COMPOSE_ENV_FILE` and `INSIGHT_COMPOSE_PROJECT_NAME` for a custom Compose environment file or project name.

Use `scripts/backup-scheduled.sh` to automate backups. Local retention is controlled by `INSIGHT_BACKUP_RETENTION_DAYS`, with an optional remote copy configured through `INSIGHT_BACKUP_RCLONE_DEST`.

## Updates without reinstallation

Insight can find and install a stable release without recreating its configuration or volumes:

```bash
./scripts/update.sh --check
./scripts/update.sh --apply
```

The deployment creates a backup, applies only new migrations, rebuilds the images, and validates the stack. On failure, it automatically returns to the previous code without overwriting data. On Linux with systemd, `./scripts/install-auto-update.sh` enables a daily check. The [Update guide](docs/updates.md) describes the stable channel, signed tags, timer, and rollback process.

## Distributed monitoring

The default `standalone` mode runs probes from the worker. The `hub` mode receives observations from independent agents, calculates a quorum for each target, and publishes only the consensus. Each agent has a persistent SQLite queue and can use native probes or Prometheus Blackbox Exporter.

Minimal hub configuration:

```dotenv
INSIGHT_DISTRIBUTED_MODE=hub
INSIGHT_AGENT_MASTER_SECRET=replace_with_openssl_rand_hex_32_output
INSIGHT_AGENT_REQUIRE_HTTPS=1
```

Then generate a secret for each agent and deploy the dedicated image:

```bash
docker compose exec worker python3 monitoring/python_monitoring/cli.py node-secret --node-key paris-1
cp .env.agent.example .env.agent
docker compose --env-file .env.agent -f docker-compose.agent.yml up -d --build
```

The dashboard displays agents, regions, assignments, missing responses, and consensus confidence. The [Distributed monitoring guide](docs/distributed-monitoring.md) covers 1, 2, 3, and N-server scenarios, quorums, Blackbox Exporter, Prometheus, and secret rotation.

## Configuration

Copy `.env.example` to `.env`, then replace at least the database passwords. Never publish the `.env` file.

Main variables:

| Variable | Default | Purpose |
| --- | --- | --- |
| `INSIGHT_APP_NAME` | `Insight` | Publicly displayed name |
| `INSIGHT_PUBLIC_URL` | `http://localhost:8080` | Canonical instance URL |
| `INSIGHT_CONTACT_EMAIL` | `contact@example.com` | Contact shown on the status page |
| `INSIGHT_TIMEZONE` | `Europe/Paris` | Service timezone |
| `INSIGHT_DEFAULT_LOCALE` | `auto` | Initial language or browser detection |
| `INSIGHT_SUPPORTED_LOCALES` | `en,fr` | Comma-separated available catalogues |
| `INSIGHT_APP_ENV` | `production` | Active environment |
| `INSIGHT_DEV_AUTH_BYPASS` | `0` | Local authentication bypass for development |
| `INSIGHT_AUTH_DB_PATH` | `/var/lib/insight-auth/auth.sqlite` | Private local account database |
| `INSIGHT_AUTH_SESSION_TTL_SEC` | `43200` | Standard session inactivity lifetime |
| `INSIGHT_AUTH_REMEMBER_TTL_SEC` | `2592000` | Remembered session lifetime |
| `INSIGHT_AUTH_MAX_ATTEMPTS` | `5` | Allowed failures during the login window |
| `INSIGHT_AUTH_WINDOW_SEC` | `900` | Login rate-limit window |
| `INSIGHT_AUTH_COOKIE_SECURE` | `auto` | Secure cookie detection from HTTPS |
| `INSIGHT_AUTH_COOKIE_SAMESITE` | `Lax` | Compatible with OIDC callbacks; use `Strict` without external SSO |
| `INSIGHT_API_ALLOWED_ORIGINS` | local URL | Origins allowed for the headless API |
| `INSIGHT_SSO_ENABLED` | `0` | Enables dashboard login through external OIDC |
| `INSIGHT_SSO_ISSUER_URL` | empty | Exact identity provider issuer |
| `INSIGHT_SSO_ALLOWED_ENDPOINT_HOSTS` | issuer host | Explicitly allowed additional OIDC hosts |
| `INSIGHT_SSO_CLIENT_ID` | empty | Insight OIDC client identifier |
| `INSIGHT_SSO_ALLOWED_EMAILS` | empty | Allowed emails, requiring a verified claim by default |
| `INSIGHT_SSO_ALLOWED_GROUPS` | empty | Groups allowed to open the dashboard |
| `INSIGHT_DB_*` | see `.env.example` | MariaDB connection |
| `INSIGHT_MONITOR_INTERVAL_SEC` | `60` | Worker frequency in seconds |
| `INSIGHT_DISTRIBUTED_MODE` | `standalone` | Local probes or hub consensus |
| `INSIGHT_AGGREGATION_REPROCESS_HOURS` | `2` | Window recalculated on each run, automatically extended after interruption |
| `INSIGHT_PROBE_RETENTION_DAYS` | `30` | Raw check retention after aggregation |
| `INSIGHT_DIAGNOSTIC_RETENTION_DAYS` | `14` | Private failure diagnostic retention |
| `INSIGHT_DATA_DIR` | `/var/lib/insight` | Private diagnostic and browser artifact directory |
| `INSIGHT_DIAGNOSTICS_NETWORK` | `1` | Enables bounded network diagnostics after failures |
| `INSIGHT_DOCKER_SOCKET_ENABLED` | `0` | Explicitly permits Docker Engine probes |
| `INSIGHT_DOCKER_SOCKET_PATH` | `/var/run/docker.sock` | Authorized Docker socket path |
| `INSIGHT_HOURLY_RETENTION_DAYS` | `365` | Hourly statistics retention |
| `INSIGHT_DAILY_RETENTION_DAYS` | `730` | Daily statistics retention |
| `INSIGHT_TLS_RETENTION_DAYS` | `365` | TLS check retention |
| `INSIGHT_REINFORCED_MONITORING_ENABLED` | `1` | Enables faster checks after incident recovery |
| `INSIGHT_REINFORCED_MONITORING_DURATION_SEC` | `900` | Reinforced monitoring duration |
| `INSIGHT_REINFORCED_MONITOR_INTERVAL_SEC` | `10` | Temporary probe and consensus interval |
| `INSIGHT_HTTP_BIND` | `0.0.0.0` | Address published by Docker; use `127.0.0.1` behind a local HTTPS proxy |
| `INSIGHT_AGENT_MASTER_SECRET` | empty | Remote agent master secret |
| `INSIGHT_AGENT_REQUIRE_HTTPS` | `1` | Rejects distributed agents outside HTTPS |
| `INSIGHT_AGENT_DEFAULT_REPLICAS` | `3` | Agents assigned per target, or `0` for all |
| `INSIGHT_DISABLE_NOTIFICATIONS` | `1` | Disables automatic delivery, but not manual tests |
| `INSIGHT_NOTIFICATION_ENCRYPTION_KEY` | generated at installation | Encrypts channel secrets with SecretBox |
| `INSIGHT_STATUS_SUBSCRIPTIONS_ENABLED` | `0` | Enables confirmed public email subscriptions when a tested SMTP channel exists |
| `INSIGHT_STATUS_SUBSCRIBER_SECRET` | notification encryption key | Signs confirmation and unsubscribe links |
| `INSIGHT_STATUS_SUBSCRIBER_SMTP_CHANNEL_ID` | first tested SMTP channel | Selects the SMTP channel used for public subscribers |
| `INSIGHT_STATUS_PAGE_COOKIE_SECRET` | notification encryption key | Signs private status page sessions |
| `INSIGHT_STATUS_PAGE_AUTH_MAX_ATTEMPTS` | `5` | Password attempts allowed for a private status page during the rate-limit window |
| `INSIGHT_ALLOWED_ORIGINS` | local URL | Comma-separated CORS origins |

Notifications are disabled by default with `INSIGHT_DISABLE_NOTIFICATIONS=1`. Configure and test channels in the dashboard, then set this variable to `0`. Configurations are encrypted before being written to the database, and their secrets are never returned to the interface. The legacy SMTP/SMS environment settings are only used when no modern channel is configured. The [Alerts and notifications guide](docs/notifications.md) describes Apprise services, Liquid templates, webhooks, and backups.

Monitors, runbooks, and status pages can also be exported, validated, and applied as YAML or JSON. Protected values remain encrypted in MariaDB and pruning only disables absent resources. See [Configuration as code](docs/configuration-as-code.md).

Availability uses the interval-capped method by default: after two missed checks, Insight marks the remaining time as unknown instead of carrying a stale result indefinitely. Time-weighted, sample-ratio, and strict-SLA calculations remain available for specific needs. See [Availability calculation](docs/availability-calculation.md).

HTTP, browser, WebSocket, ICMP, TCP, DNS, heartbeat, MQTT, SQL, and Docker probes share the same incident and aggregation pipeline. Advanced credentials remain encrypted, diagnostics stay private, and Docker socket access is disabled by default. See [Probe types](docs/probes.md).

## Internationalization

Catalogues are stored in `public/locales`. English is the fallback language. The selected language is saved in the browser and can also be forced with `?lang=fr` or `?lang=en`.

To add a language, duplicate an existing catalogue, translate every key, add its code to `INSIGHT_SUPPORTED_LOCALES`, then test the page on desktop and mobile. Dates, numbers, durations, and timezones automatically use the active locale through `Intl`.

## Public API

- `GET /`: public Insight status page.
- `GET /hourly_stats_report.php?contract=v2`: availability and services.
- `GET /api/public_runtime_state.php`: active engine state.
- `GET /api/distributed_state.php`: public agent network and consensus summary.
- `GET /metrics`: Prometheus metrics, disabled by default.
- `GET /hourly_stats_report.php?contract=v2&mode=incidents`: incidents as JSON.
- `GET /hourly_stats_report.php?contract=v2&mode=incidents&format=rss`: incidents as RSS.
- `GET /admin/`: protected local dashboard or first-account setup.

## Headless API and SSO

The **Access** menu enables an administration API independent of the dashboard. Tokens have scoped permissions, can expire, and can be revoked; their value is shown only once. Versioned routes cover global status, monitors, incidents, maintenance, status pages, alert channels, and on-call rotations under `/api/v1/`.

Insight can authenticate another dashboard as an OpenID Connect provider, or delegate its own login to an external OIDC provider. Both directions use Authorization Code, PKCE S256, exact redirect URIs, and short-lived tokens. Read the [API and SSO guide](docs/api-and-sso.md) or open **Access -> Integration guide** in the instance.

When the local database contains no sites, the interface displays a preview with `example.com`, `status.example.com`, and `api.example.com` on `localhost`. The detail view includes sample incident lifecycles and public updates. Add `?incidents=off` to hide this sample data.

## Worker commands

```bash
docker compose exec worker python3 monitoring/python_monitoring/cli.py monitor
docker compose exec worker python3 monitoring/python_monitoring/cli.py hourly
docker compose exec worker python3 monitoring/python_monitoring/cli.py daily
docker compose exec worker python3 monitoring/python_monitoring/cli.py retention
```

Aggregations remember their last successful run and recalculate at least the latest two hours. The daily cleanup works in batches and refuses to delete raw probes or hourly rows until the corresponding aggregations are current. Periods without observations remain unknown time and are not counted as successful availability. Daily response times are weighted by the actual sample count.

After an incident is resolved, reinforced monitoring checks the restored target every 10 seconds for 15 minutes by default. The state is persisted in MariaDB, survives worker restarts, applies to local and distributed probes, shortens distributed consensus windows, and expires automatically. Its active state is available from the runtime API, the network dashboard, and Prometheus metrics.

The Python CLI can also add, edit, delete, or manually test a monitor:

```bash
docker compose exec worker python3 monitoring/python_monitoring/cli.py --help
```

## Installation without Docker

Use PHP 8.2 or newer with `mysqli`, `pdo_sqlite`, `curl`, `mbstring`, `sodium`, and `xml`; Python 3.10 or newer; Node.js 22; MariaDB or MySQL; and Nginx or Apache. Import `database/schema.sql`, install dependencies and build the interface with `npm ci && npm run build`, install `monitoring/python_monitoring/requirements.txt`, expose only the `public/` directory, then run `monitoring/python_monitoring/scheduler.py` as a supervised service. The `database/auth-schema.sql` schema is applied automatically on the first visit to `/admin/`.

## Development

To open the entire administration interface without creating an account, start the dedicated development server:

```bash
./scripts/dev-server.sh
```

For the same local development profile with Docker, use:

```bash
./scripts/dev-compose.sh
```

It creates an isolated `insight-development` Compose project, listens only on `127.0.0.1:8080`, and enables the development administrator automatically. The command refuses non-local hosts. Use `INSIGHT_DEV_PORT=18080 ./scripts/dev-compose.sh` to select another local port.

The bypass is active only when `INSIGHT_APP_ENV=development` and `INSIGHT_DEV_AUTH_BYPASS=1` are set together. The development commands set both variables automatically. Docker remains in production mode by default and must never expose the bypass on an Internet-facing instance.

```bash
npm ci
npm run build
npm run check
find . -name '*.php' -print0 | xargs -0 -n1 php -l
python3 -m py_compile monitoring/python_monitoring/*.py
php tests/admin_probes.php
php tests/admin_notifications.php
php tests/admin_auth.php
php tests/admin_access.php
php tests/public_api.php
python3 -m unittest discover -s tests -p 'test_*.py' -v
```

With Docker running, `./scripts/smoke-test.sh` validates migrations, private and public status pages, scoped APIs, incident timelines, maintenance, on-call rotations, SLO calculations, and MariaDB CRUD, then executes real HTTP, ICMP, and TCP probes. It restores the previous API and OAuth settings and removes every token, audit entry, monitor, and measurement created by the test, including after a failure.

To build the public archive from a validated commit:

```bash
./scripts/package-release.sh
```

The command reruns every check, exports only tracked files without the `.git` directory, searches for runtime data and legacy private dependencies, then writes the archive and its checksum to `dist/`.

The shadcn CLI can add a component to the repository with `npx shadcn@latest add component-name`. Compiled components do not depend on a remote service at runtime.

Read `CONTRIBUTING.md` to propose a change and `SECURITY.md` to report a vulnerability.

## License

Insight is distributed under the MIT License.
