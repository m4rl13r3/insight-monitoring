# Probe types

Insight executes checks in the Python worker. Every result enters the same observation, incident, aggregation, diagnostic, retention, and notification pipeline regardless of probe type.

## Network and application probes

| Type | Target | Notes |
| --- | --- | --- |
| HTTP | `https://example.com/health` | Methods, redirects, status ranges, body keywords, JSON values, Basic Auth, request headers, request body, and TLS validation |
| Browser | `https://example.com/login` | Declarative Chromium scenario with encrypted variables and optional screenshots |
| WebSocket | `wss://stream.example.com/socket` | Optional headers, initial payload, and expected response text |
| ICMP | `server.example.com` | Host reachability only |
| TCP | `server.example.com:443` | Port reachability only |
| DNS | `example.com` | A, AAAA, CNAME, MX, NS, or TXT resolution with an optional expected value |
| Heartbeat | `nightly-backup` | A generated URL that an external job calls before its grace period expires |
| MQTT | `mqtts://broker.example.com:8883/insight/health` | Topic subscription with optional credentials, QoS, and expected payload |
| SQL | `postgresql://db.example.com/application` | MySQL, MariaDB, or PostgreSQL with a read-only query and optional expected first value |
| Docker | `docker://local/api` | Container running and health state through an explicitly authorized Docker endpoint |
| gRPC | `grpcs://api.example.com:443` | Standard gRPC Health Checking Protocol, optionally scoped to one service |
| Redis | `rediss://cache.example.com:6380/0` | Authenticated `PING`, with optional TLS and database number |
| SMTP | `smtp://mail.example.com:587` | EHLO, optional STARTTLS or TLS, optional authentication, and NOOP |
| RabbitMQ | `amqps://mq.example.com:5671/production` | Authenticated AMQP connection to one virtual host |
| SNMP | `snmp://switch.example.com:161` | Read-only SNMP v2c GET for one OID |
| Local service | `agent://paris-1/systemd/nginx.service` | Exact systemd or PM2 process state from one explicitly named agent |

Advanced credentials and variables are encrypted in MariaDB. Configuration exports only report that protected values exist; they never include their plaintext or ciphertext.

## Service and network device checks

SNMP checks use v2c and one read-only OID. Create a dedicated read-only community on the device, restrict it to the agent or worker address, and avoid the default `public` community on a real network.

Local service checks are intentionally agent-only. A target selects exactly one agent and one permitted service: `agent://paris-1/systemd/nginx.service` or `agent://paris-1/pm2/api`. Enable this only for a host-installed agent, then explicitly allow each service:

```bash
INSIGHT_AGENT_ENABLE_SERVICE_CHECKS=1
INSIGHT_AGENT_SERVICE_ALLOWLIST=nginx.service,api
```

The container agent does not receive host systemd access by default. This is deliberate: mounting the host D-Bus would be a privilege boundary, not a convenience setting.

## Browser scenarios

A browser scenario is a JSON array with at most fifty steps. Supported actions are `goto`, `click`, `fill`, `press`, `wait_for`, `expect_text`, `expect_url`, `evaluate`, and `screenshot`. Use `{{VARIABLE_NAME}}` in scenario values and store the matching values in the encrypted variables field.

Browser screenshots and network diagnostics are private dashboard artifacts under `INSIGHT_DATA_DIR`. They are deleted by retention and are never exposed through the public status API.

## SQL safety

SQL probes accept one `SELECT`, `SHOW`, `WITH`, or `EXPLAIN` statement. Multiple statements and write operations are rejected before storage. Use a dedicated database account with read access limited to the health data required by the check.

## Docker socket opt-in

Insight does not mount the host Docker socket by default. Local Docker probes require an explicit override:

```bash
cp docker-compose.docker-probes.yml.example docker-compose.override.yml
socket_gid="$(stat -c %g /var/run/docker.sock)"
sed -i "s/^INSIGHT_DOCKER_SOCKET_ENABLED=.*/INSIGHT_DOCKER_SOCKET_ENABLED=1/" .env
sed -i "s/^INSIGHT_DOCKER_SOCKET_GID=.*/INSIGHT_DOCKER_SOCKET_GID=${socket_gid}/" .env
docker compose up -d --build worker
```

`docker-compose.override.yml` is ignored by Git and automatically reused by the backup, update, migration, and health-check commands.

Access to the Docker daemon is effectively privileged host access even when the socket mount is marked read-only. Enable it only on a trusted host and never expose an unauthenticated Docker TCP endpoint. Keep `INSIGHT_DOCKER_SOCKET_ENABLED=0` when Docker probes are not required.

## Diagnostics and retention

Failed checks can retain timing, sanitized response headers, a redacted body excerpt, DNS resolution, and a bounded MTR or traceroute result. Browser failures can also retain a PNG. Diagnostic collection and response capture are configured per monitor, while `INSIGHT_DIAGNOSTIC_RETENTION_DAYS` controls deletion globally.
