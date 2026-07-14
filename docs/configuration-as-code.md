# Configuration as code

Insight can export, validate, and apply its non-secret configuration as YAML or JSON. This is useful for backups, reviewable infrastructure changes, and rebuilding an instance without depending on the dashboard.

## Export

```bash
docker compose exec -T worker python3 monitoring/python_monitoring/cli.py config export > insight.yml
docker compose exec -T worker python3 monitoring/python_monitoring/cli.py config export --format json > insight.json
```

The export contains monitors, runbooks, status pages, groups, and public presentation settings. Password hashes, heartbeat tokens, authentication headers, request bodies, browser scenarios, and encrypted advanced-probe settings are never exported. A monitor with protected values is marked with `protected_configuration_preserved: true`.

## Validate and preview

```bash
docker compose exec -T worker python3 monitoring/python_monitoring/cli.py config validate --file - < insight.yml
docker compose exec -T worker python3 monitoring/python_monitoring/cli.py config apply --file - --dry-run < insight.yml
```

Validation checks monitor types, intervals, calculation methods, unique identifiers, runbook slugs, and status-page references. A dry run reports planned create, update, and disable operations without writing to MariaDB.

## Apply

```bash
docker compose exec -T worker python3 monitoring/python_monitoring/cli.py config apply --file - < insight.yml
```

Applying a file is transactional. Existing protected monitor settings and status-page password hashes are preserved. A new password-protected status page must be created once in the dashboard so Insight can generate its password hash locally.

Use `--prune` to disable monitors, runbooks, and non-default status pages that are absent from the file:

```bash
docker compose exec -T worker python3 monitoring/python_monitoring/cli.py config apply --file - --prune < insight.yml
```

Pruning never deletes checks, aggregates, incidents, or other historical data. It only sets the corresponding resource to inactive.

## Monitor references

Status pages refer to monitors with a stable `type:target` key:

```yaml
version: 1
monitors:
  - target: https://example.com
    type: http
    interval_seconds: 60
status_pages:
  - slug: default
    name: Insight
    monitors:
      - http:https://example.com
```

The dashboard uses the automatic calculation by default and only exposes an optional strict switch for treating a missing reply as downtime. Configuration files and the API continue to support `time_weighted`, `sample_ratio`, and `strict_sla` for specialised integrations.
