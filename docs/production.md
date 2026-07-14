# Production deployment

This guide assumes a Linux server with Docker Engine, Docker Compose, OpenSSL, and a domain name. Place Insight behind an HTTPS proxy and bind the container HTTP port to the local interface.

## Configuration

```bash
git clone https://github.com/m4rl13r3/insight-monitoring.git insight
cd insight
./scripts/install.sh
```

Then edit `.env`:

```dotenv
INSIGHT_APP_ENV=production
INSIGHT_DEV_AUTH_BYPASS=0
INSIGHT_PUBLIC_URL=https://status.example.com
INSIGHT_CONTACT_EMAIL=technical@example.com
INSIGHT_HTTP_BIND=127.0.0.1
INSIGHT_ALLOWED_ORIGINS=https://status.example.com
INSIGHT_API_ALLOWED_ORIGINS=https://status.example.com
INSIGHT_AUTH_COOKIE_SECURE=1
```

Keep `.env` with `600` permissions and store a copy in a separate secret vault. It contains MariaDB passwords, the alert encryption key, and the agent master secret in distributed mode.

Start the stack and apply every pending migration before opening the instance:

```bash
docker compose up -d --build --wait
./scripts/migrate.sh
```

## HTTPS

With Caddy installed on the host:

```caddyfile
status.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Reload Caddy, then verify that `https://status.example.com/` and `https://status.example.com/api/public_runtime_state.php` respond. Do not directly expose port `8080` through the firewall.

## First production start

1. Open `/admin/` from a trusted network and create the first administrator account.
2. Create the required HTTP, ICMP, TCP, DNS, or heartbeat monitors.
3. Set an SLO target for each monitor and let the worker complete at least one monitor, hourly, and daily cycle.
4. Create a channel under **Alerts**, complete a successful test delivery, then set `INSIGHT_DISABLE_NOTIFICATIONS=0`.
5. Configure on-call rotations for critical services and verify their timezone, active shift, severity threshold, and destination.
6. Create the required public or private status pages and verify their monitor scope from an unauthenticated browser.
7. Restart affected services with `docker compose up -d`.
8. Run `./scripts/production-check.sh --strict`. Production is validated only when the command finishes without errors.

The strict check rejects, among other conditions, demo domains, an instance without an administrator, an inactive worker, untested notifications, non-HTTPS origins, and the development authentication bypass.

`./scripts/smoke-test.sh` can be run before this checklist on an existing instance. It restores the previous API and OAuth feature state and removes its temporary tokens, audit entries, monitors, and measurements on success or failure.

Public email subscriptions are disabled by default. Enable them only after a successful SMTP channel test:

```dotenv
INSIGHT_STATUS_SUBSCRIPTIONS_ENABLED=1
INSIGHT_STATUS_SUBSCRIBER_SECRET=a_separate_random_secret_of_at_least_32_characters
INSIGHT_STATUS_SUBSCRIBER_SMTP_CHANNEL_ID=1
```

The channel identifier is optional when only one tested SMTP channel exists. Use `openssl rand -hex 32` for the subscriber secret. Private status page sessions are signed, expire after 24 hours, are invalidated when the page password changes, and are rate-limited independently from dashboard login. Set a separate `INSIGHT_STATUS_PAGE_COOKIE_SECRET` with the same minimum length when you do not want it to reuse the notification encryption key.

## Distributed agents

In `hub` mode, first derive each agent key with `python3 monitoring/python_monitoring/cli.py node-secret --node-key <node-key>`. Confirm their presence under **Network**, then set:

```dotenv
INSIGHT_AGENT_REQUIRE_HTTPS=1
INSIGHT_AGENT_AUTO_REGISTER=0
```

The production check requires these values and a master secret of at least 32 characters. Agents must point to the hub's public HTTPS URL.

## Backups

First test backup and restore as described in the README. For a daily backup retained for 30 days:

```cron
17 2 * * * cd /opt/insight && INSIGHT_BACKUP_RETENTION_DAYS=30 ./scripts/backup-scheduled.sh >> /var/log/insight-backup.log 2>&1
```

An optional remote copy can be sent to an rclone destination:

```dotenv
INSIGHT_BACKUP_RCLONE_DEST=s3-insight:production
```

Regularly test restoration on an isolated instance. The presence of an archive alone does not guarantee that it is usable.

## Updates

```bash
./scripts/update.sh --check
./scripts/update.sh --apply
```

The script creates a backup, runs each migration once, validates service recovery, and restores the previous code on failure. Volumes and `.env` are preserved. Read `CHANGELOG.md` before updating and the [Update guide](updates.md) to enable the optional systemd timer.
