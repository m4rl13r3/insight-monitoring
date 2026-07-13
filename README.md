# Insight

Insight is a self-hosted open source status page with HTTP, ICMP, and TCP monitoring, uptime history, incidents, scheduled maintenance, TLS tracking, and a protected dashboard. Its monitoring engine is written in Python. Insight can also aggregate probes from one, two, three, or as many remote servers as required.

The public interface is available in French and English, detects the browser language, and requires no private service. The reference deployment uses Docker Compose with Nginx, PHP-FPM, a worker, and MariaDB.

The application is rendered with PHP and JavaScript. React only manages the language and theme controls. The Vite build follows shadcn conventions and uses Tailwind as a CSS compiler; browsers only load the local static assets generated in `public/assets`.

The Font Awesome Free icons used by the interface are bundled as local WOFF2 fonts in `public/assets`. Their notice and full license are included in `THIRD_PARTY_NOTICES.md` and `licenses/FONT-AWESOME-FREE.txt`.

## Quick start

Requirements: Docker with the Compose plugin and OpenSSL.

```bash
./scripts/install.sh
```

The script creates `.env`, generates the MariaDB passwords and agent master secret, builds the images, and starts Insight. Compose rejects empty passwords.

For manual configuration, copy `.env.example`, set `INSIGHT_DB_PASSWORD`, `INSIGHT_DB_ROOT_PASSWORD`, and `INSIGHT_NOTIFICATION_ENCRYPTION_KEY` with the output of `openssl rand -hex 32`, then run `docker compose up -d --build`.

Insight is then available at `http://localhost:8080`.

Do not keep local defaults on a public instance. The [Production guide](docs/production.md) covers HTTPS, the first account, real monitors, alert testing, remote agents, and the final `./scripts/production-check.sh --strict` validation.

Open `http://localhost:8080/admin/` to create the first administrator account. Like Uptime Kuma, Insight uses an account local to the instance: no identity provider or external server is required. An external OpenID Connect provider can later be enabled without removing this local fallback access. Accounts, sessions, tokens, and identity keys are stored in the private `insight_auth` volume, separately from MariaDB monitoring data.

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
- `php` runs the public page, APIs, local SQLite authentication, and the dashboard.
- `worker` runs probes according to `INSIGHT_MONITOR_INTERVAL_SEC`, followed by hourly and daily aggregation.
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
docker compose exec php php scripts/agent-key.php paris-1
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
| `INSIGHT_HOURLY_RETENTION_DAYS` | `365` | Hourly statistics retention |
| `INSIGHT_DAILY_RETENTION_DAYS` | `730` | Daily statistics retention |
| `INSIGHT_TLS_RETENTION_DAYS` | `365` | TLS check retention |
| `INSIGHT_HTTP_BIND` | `0.0.0.0` | Address published by Docker; use `127.0.0.1` behind a local HTTPS proxy |
| `INSIGHT_AGENT_MASTER_SECRET` | empty | Remote agent master secret |
| `INSIGHT_AGENT_REQUIRE_HTTPS` | `1` | Rejects distributed agents outside HTTPS |
| `INSIGHT_AGENT_DEFAULT_REPLICAS` | `3` | Agents assigned per target, or `0` for all |
| `INSIGHT_DISABLE_NOTIFICATIONS` | `1` | Disables automatic delivery, but not manual tests |
| `INSIGHT_NOTIFICATION_ENCRYPTION_KEY` | generated at installation | Encrypts channel secrets with SecretBox |
| `INSIGHT_ALLOWED_ORIGINS` | local URL | Comma-separated CORS origins |

Notifications are disabled by default with `INSIGHT_DISABLE_NOTIFICATIONS=1`. Configure and test channels in the dashboard, then set this variable to `0`. Configurations are encrypted before being written to the database, and their secrets are never returned to the interface. The legacy SMTP/SMS environment settings are only used when no modern channel is configured. The [Alerts and notifications guide](docs/notifications.md) describes Apprise services, Liquid templates, webhooks, and backups.

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

The **Access** menu enables an administration API independent of the dashboard. Tokens have scoped permissions, can expire, and can be revoked; their value is shown only once. Versioned routes cover global status, monitors, incidents, and alerts under `/api/v1/`.

Insight can authenticate another dashboard as an OpenID Connect provider, or delegate its own login to an external OIDC provider. Both directions use Authorization Code, PKCE S256, exact redirect URIs, and short-lived tokens. Read the [API and SSO guide](docs/api-and-sso.md) or open **Access -> Integration guide** in the instance.

When the local database contains no sites, the interface displays a preview with `example.com`, `status.example.com`, and `api.example.com` on `localhost`. The detail view also contains four sample incidents covering ongoing, resolved, assisted, and low-confidence states. Add `?incidents=off` to hide this sample data.

## Worker commands

```bash
docker compose exec worker php monitoring/monitoring.php
docker compose exec worker php monitoring/hourly.php
docker compose exec worker php monitoring/daily.php
docker compose exec worker php monitoring/retention.php
```

Aggregations remember their last successful run and recalculate at least the latest two hours. The daily cleanup works in batches and refuses to delete raw probes or hourly rows until the corresponding aggregations are current. Periods without observations remain unknown time and are not counted as successful availability. Daily response times are weighted by the actual sample count.

The Python CLI can also add, edit, delete, or manually test a monitor:

```bash
docker compose exec worker python3 monitoring/python_monitoring/cli.py --help
```

## Installation without Docker

Use PHP 8.2 or newer with `mysqli`, `pdo_sqlite`, `curl`, `mbstring`, `sodium`, and `xml`; Python 3.10 or newer; Node.js 22; MariaDB or MySQL; and Nginx or Apache. Import `database/schema.sql`, install dependencies and build the interface with `npm ci && npm run build`, install `monitoring/python_monitoring/requirements.txt`, expose only the `public/` directory, then regularly run `monitoring/monitoring.php`, `monitoring/hourly.php`, and `monitoring/daily.php`. The `database/auth-schema.sql` schema is applied automatically on the first visit to `/admin/`.

## Development

To open the entire administration interface without creating an account, start the dedicated development server:

```bash
./scripts/dev-server.sh
```

The bypass is active only when `INSIGHT_APP_ENV=development` and `INSIGHT_DEV_AUTH_BYPASS=1` are set together. It remains disabled by default in Docker and must never be used on an exposed instance.

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
php tests/distributed_consensus.php
python3 -m unittest discover -s tests -p 'test_*.py' -v
```

With Docker running, `./scripts/smoke-test.sh` validates the schema and MariaDB CRUD on a complete installation, then executes real HTTP, ICMP, and TCP probes.

To build the public archive from a validated commit:

```bash
./scripts/package-release.sh
```

The command reruns every check, exports only tracked files without the `.git` directory, searches for runtime data and legacy private dependencies, then writes the archive and its checksum to `dist/`.

The shadcn CLI can add a component to the repository with `npx shadcn@latest add component-name`. Compiled components do not depend on a remote service at runtime.

Read `CONTRIBUTING.md` to propose a change and `SECURITY.md` to report a vulnerability.

## License

Insight is distributed under the MIT License.
