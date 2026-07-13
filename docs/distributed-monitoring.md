# Distributed monitoring

Insight can run alone on one server or aggregate observations from as many remote agents as required. Agents never connect to MariaDB: they retrieve their configuration from the hub, execute probes, and send signed batches to the Insight API.

## Architecture

```mermaid
flowchart LR
    A1[Paris agent] -->|HTTPS + HMAC| H[Insight hub]
    A2[Frankfurt agent] -->|HTTPS + HMAC| H
    AN[Agent N] -->|HTTPS + HMAC| H
    B[Optional Blackbox Exporter] --> A1
    H --> R[(Raw observations)]
    R --> C[Consensus]
    C --> P[(Canonical probes)]
    P --> S[Statistics and incidents]
    H --> M[/metrics]
```

The system adopts proven principles from the open source ecosystem: OpenTelemetry's agent/hub separation, Prometheus Remote Write's persistent queue and idempotent replay, Blackbox Exporter's multi-target probing, and connectivity checks inspired by Gatus. Insight keeps its own minimal protocol so it remains deployable without Prometheus or an external service.

Each agent has:

- a stable identifier, region, and zone;
- a persistent SQLite queue that survives restarts and hub outages;
- native HTTP, ICMP, and TCP probes;
- an optional adapter for Prometheus Blackbox Exporter HTTP, ICMP, TCP, DNS, and gRPC probes;
- a short local retry before confirming failure;
- an optional connectivity check that delays probes when the local network is unavailable;
- batched delivery with byte-for-byte replay, exponential backoff, and jitter.

The hub stores raw observations separately from the canonical result. Only consensus feeds `probes`, hourly statistics, incidents, and the public page.

## Assignment and quorum

Targets are distributed through rendezvous hashing. Assignment remains deterministic when agent order changes and moves only a portion of targets when an agent is added or removed.

`INSIGHT_AGENT_DEFAULT_REPLICAS=3` assigns each target to at most three agents. A value of `0` uses all active agents. A target can override this setting with `sites.probe_replication_factor`.

Success and failure quorums default to `floor(n / 2) + 1`. The `probe_success_quorum` and `probe_failure_quorum` columns support a different policy for each target. A value of `0` keeps the automatic majority.

| Expected agents | Fresh observations | Canonical state |
| --- | --- | --- |
| 1 | 1 online | `online` |
| 1 | 1 offline | `offline` |
| 2 | 2 online | `online` |
| 2 | 1 online, 1 offline | `degraded` |
| 2 | 2 offline | `offline` |
| 3 | 3 online | `online` |
| 3 | 2 online, 1 missing | `online`, 67% confidence |
| 3 | 2 online, 1 offline | `degraded` |
| 3 | 1 online, 2 missing | `unknown` |
| 3 | 2 offline | `offline` |

An explicit minority failure therefore remains visible as `degraded`, even when the majority responds. Missing data becomes `unknown` and is not counted as downtime in aggregates.

An active but silent agent keeps its assignments and counts as missing. Explicitly pause or revoke it to redistribute its targets.

## Configure the hub

For a new installation, generate secrets with the installation script, then enable hub mode in `.env`:

```dotenv
INSIGHT_DISTRIBUTED_MODE=hub
INSIGHT_AGENT_MASTER_SECRET=a_random_64_character_hexadecimal_value
INSIGHT_AGENT_REQUIRE_HTTPS=1
INSIGHT_AGENT_DEFAULT_REPLICAS=3
```

The secret can also be generated manually:

```bash
openssl rand -hex 32
docker compose up -d --build
```

The worker then runs `monitoring/distributed_consensus.php` instead of central probes. Hourly and daily aggregation continues normally.

For an existing Insight database, hub mode automatically applies missing tables. The migration can also be run explicitly:

```bash
docker compose exec -T db sh -lc 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' < database/migrations/002-distributed-monitoring.sql
```

## Deploy an agent

Choose a unique lowercase key, then derive its secret from the hub:

```bash
docker compose exec php php scripts/agent-key.php paris-1
```

On the remote server:

```bash
cp .env.agent.example .env.agent
```

Set at least:

```dotenv
INSIGHT_HUB_URL=https://status.example.com
INSIGHT_AGENT_NODE_KEY=paris-1
INSIGHT_AGENT_SECRET=secret_generated_by_the_hub
INSIGHT_AGENT_DISPLAY_NAME=Paris 1
INSIGHT_AGENT_REGION=fr-par
INSIGHT_AGENT_ZONE=fr-par-1
```

Then start the agent:

```bash
docker compose --env-file .env.agent -f docker-compose.agent.yml up -d --build
```

The `insight_agent_spool` volume contains the local queue. Do not remove it during a hub outage, or observations that have not yet been acknowledged will be lost.

To test one cycle without Docker:

```bash
INSIGHT_HUB_URL=https://status.example.com \
INSIGHT_AGENT_NODE_KEY=paris-1 \
INSIGHT_AGENT_SECRET=secret_generated_by_the_hub \
INSIGHT_AGENT_SPOOL_PATH=./data/agent.sqlite \
python3 monitoring/agent/agent.py --once
```

## Blackbox Exporter

The native agent is sufficient for HTTP, ICMP, and TCP. Blackbox Exporter additionally provides DNS, gRPC, finer TLS checks, and configurable HTTP scenarios.

In `.env.agent`:

```dotenv
INSIGHT_AGENT_BLACKBOX_URL=http://blackbox:9115
INSIGHT_AGENT_BLACKBOX_HTTP_MODULE=http_2xx
INSIGHT_AGENT_BLACKBOX_ICMP_MODULE=icmp
INSIGHT_AGENT_BLACKBOX_TCP_MODULE=tcp_connect
INSIGHT_AGENT_BLACKBOX_DNS_MODULE=dns
INSIGHT_AGENT_BLACKBOX_GRPC_MODULE=grpc
INSIGHT_AGENT_BLACKBOX_FALLBACK_NATIVE=1
```

Then start the provided profile:

```bash
docker compose --env-file .env.agent -f docker-compose.agent.yml --profile blackbox up -d --build
```

When Blackbox is unavailable, native fallback remains enabled by default for HTTP, ICMP, and TCP. DNS and gRPC require Blackbox. The configuration in `docker/agent/blackbox.yml` covers all five protocols. Its DNS module queries `example.com` with type A; adapt `query_name`, transport, and validations to your environment.

Each failure is retried once after 500 ms by default. Adjust `INSIGHT_AGENT_PROBE_RETRIES` and `INSIGHT_AGENT_PROBE_RETRY_DELAY_MS` on agents without exceeding the target interval.

## Local connectivity

`INSIGHT_AGENT_CONNECTIVITY_TARGET` accepts `host:port` or a URL. Use a gateway, DNS resolver, or service you control:

```dotenv
INSIGHT_AGENT_CONNECTIVITY_TARGET=dns.internal.example:53
```

If this target stops responding, the agent reports local connectivity as offline and delays its probes. It does not send a series of false failures for every target.

Leave the variable empty to run probes without this safeguard.

## Operate nodes

List agents and their assignments:

```bash
docker compose exec php php scripts/node-admin.php list
```

Change their state:

```bash
docker compose exec php php scripts/node-admin.php pause paris-1
docker compose exec php php scripts/node-admin.php activate paris-1
docker compose exec php php scripts/node-admin.php revoke paris-1
```

`pause` and `revoke` remove the agent from assignments during the next calculation. `revoke` also blocks future requests until explicit reactivation.

Customize a target:

```sql
UPDATE sites
SET probe_replication_factor = 5,
    probe_success_quorum = 3,
    probe_failure_quorum = 3
WHERE id = 1;
```

Assignments are refreshed on the next agent configuration request or worker run.

## Incidents

A single negative batch does not immediately open an incident. By default, Insight requires two offline consensus windows and two online windows to confirm recovery:

```dotenv
INSIGHT_CONSENSUS_FAILURE_WINDOWS=2
INSIGHT_CONSENSUS_RECOVERY_WINDOWS=2
INSIGHT_DISTRIBUTED_INCIDENTS=1
```

The `degraded` status reports regional disagreement without automatically opening a global incident. `unknown` values neither open nor resolve an incident.

## Prometheus and VictoriaMetrics

The `/metrics` endpoint is disabled by default. Enable it and protect it with a token:

```dotenv
INSIGHT_METRICS_ENABLED=1
INSIGHT_METRICS_TOKEN=a_random_token
```

Minimal Prometheus configuration:

```yaml
scrape_configs:
  - job_name: insight
    metrics_path: /metrics
    authorization:
      credentials: a_random_token
    static_configs:
      - targets: [status.example.com]
```

Exposed series cover agent presence and clock skew, local connectivity, observations by target and region, consensus, confidence, and expected, fresh, or missing response counts.

## Security

The hub derives a distinct secret for each node key with HMAC-SHA256. It does not store these secrets in the database. Every request includes a timestamp, unique nonce, body digest, and signature. Previously seen nonces are rejected.

- Use HTTPS and `INSIGHT_AGENT_REQUIRE_HTTPS=1` whenever the hub is exposed.
- Keep `INSIGHT_AGENT_MASTER_SECRET` only on the hub.
- Give each agent a unique key and never reuse its secret.
- Disable `INSIGHT_AGENT_AUTO_REGISTER` after enrollment when no new node is expected.
- Synchronize clocks with NTP; the HMAC window defaults to 300 seconds.
- Rotating the master secret invalidates every agent secret. Update each `.env.agent` without removing its SQLite volume.

## Retention and capacity

Raw observations and batches are retained for seven days by default. Consensus snapshots are retained for 90 days. Canonical probes and aggregates follow Insight's historical retention policies.

For N agents and M targets, raw volume is approximately `N x M x 1440` observations per day at one-minute intervals when every target uses every agent. Keep three replicas for general use and increase them only for critical services or geographic analysis.

Useful variables:

| Variable | Default | Purpose |
| --- | --- | --- |
| `INSIGHT_AGENT_DEFAULT_REPLICAS` | `3` | Agents assigned per target, `0` for all |
| `INSIGHT_AGENT_NODE_TTL_SEC` | `180` | Delay before an agent is shown as silent |
| `INSIGHT_CONSENSUS_FRESHNESS_SEC` | `180` | Minimum observation freshness |
| `INSIGHT_CONSENSUS_BUCKET_SEC` | `60` | Canonical window size |
| `INSIGHT_AGENT_BATCH_SIZE` | `200` | Maximum observations per batch |
| `INSIGHT_AGENT_RAW_RETENTION_DAYS` | `7` | Raw observation retention |
| `INSIGHT_AGENT_BATCH_RETENTION_DAYS` | `7` | Batch receipt retention |
| `INSIGHT_CONSENSUS_RETENTION_DAYS` | `90` | Aggregated snapshot retention |

## Technical references

- [Prometheus Remote Write](https://prometheus.io/docs/specs/prw/remote_write_spec/)
- [Prometheus Multi-target Exporter Pattern](https://prometheus.io/docs/guides/multi-target-exporter/)
- [Prometheus Blackbox Exporter](https://github.com/prometheus/blackbox_exporter)
- [OpenTelemetry Agent to Gateway](https://opentelemetry.io/docs/collector/deploy/other/agent-to-gateway/)
- [Gatus](https://github.com/TwiN/gatus)
- [VictoriaMetrics deduplication](https://docs.victoriametrics.com/victoriametrics/cluster-victoriametrics/)
