# Alerts and notifications

Insight sends state changes from the Python engine or distributed consensus to configured channels. Channels, subscriptions, templates, and deliveries are stored in MariaDB. Secrets are encrypted before storage with libsodium SecretBox.

## Setup

The `./scripts/install.sh` script automatically generates the key. For a manual installation:

```bash
openssl rand -hex 32
```

Place the result in `INSIGHT_NOTIFICATION_ENCRYPTION_KEY`, start Insight, open **Administration -> Alerts**, create a channel, and use its test button. Once testing succeeds:

```dotenv
INSIGHT_DISABLE_NOTIFICATIONS=0
```

Changing `.env` requires restarting the `php` and `worker` services.

## Channels

Insight directly supports three transports:

- SMTP with SSL, STARTTLS, or an unencrypted connection for a local relay.
- HTTP webhooks using `POST`, `PUT`, or `PATCH`, with optional headers and JSON body.
- Free Mobile SMS API.

All other services use [Apprise](https://github.com/caronc/apprise). The dashboard catalogue lists common destinations, while **Apprise - 138+ services** accepts any supported scheme. Enter one URL per line; the [Apprise service documentation](https://appriseit.com/services/) provides provider-specific formats.

Apprise URLs often contain a token. Insight treats them as secrets: they remain empty while editing, and an empty value preserves the current configuration.

## Events

Each channel can independently subscribe to:

- `monitor_down`: one or more targets become unavailable.
- `monitor_up`: targets respond again.
- `incident_open`: Insight opens an incident.
- `incident_update`: an operator publishes an incident update.
- `incident_acknowledged`: an operator acknowledges an incident.
- `incident_resolved`: Insight resolves an incident.
- `tls_expiring`: a certificate approaches its configured expiration threshold.
- `tls_invalid`: a certificate cannot be validated.
- `maintenance_started`: a scheduled maintenance window starts.
- `maintenance_ended`: a scheduled maintenance window ends.

Simultaneous changes within the same domain are grouped to avoid a burst of messages.

## On-call escalation

An on-call rotation maps recurring or one-time shifts to existing notification channels. Each rotation defines its timezone, monitor scope, minimum severity, first delay, repeat interval, and maximum number of alerts. Only incidents in `started` or `monitoring` state are escalated; acknowledging or resolving the incident stops the sequence. Failed deliveries are retried three times, while idempotency keys prevent duplicate successful deliveries.

On-call messages are internal. They never trigger public subscriber email, even though they reuse the incident templates and delivery engine.

## Status page subscribers

Public subscriptions require `INSIGHT_STATUS_SUBSCRIPTIONS_ENABLED=1`, automatic notifications enabled, `INSIGHT_STATUS_SUBSCRIBER_SECRET` with at least 32 characters, and a tested SMTP channel. Insight uses the first eligible SMTP channel unless `INSIGHT_STATUS_SUBSCRIBER_SMTP_CHANNEL_ID` selects one explicitly. Confirmation and unsubscribe tokens are signed, requests are rate-limited, and delivery follows each status page's monitor scope. A custom page without assigned monitors receives no events; only the unconfigured `default` page represents all public monitors. Internal incident updates and maintenance windows with public notification disabled are never sent to subscribers.

## Liquid messages

The title and body of each event can be edited in the dashboard. The engine uses `python-liquid` with these safe variables:

| Variable | Content |
| --- | --- |
| `app_name` | Public instance name |
| `public_url` | Status page URL |
| `event` | Event key |
| `domain` | Domain grouping the targets |
| `sites` | List of affected targets |
| `site_url` | First target in the group |
| `count` | Number of affected targets |
| `status` | New state |
| `message` | Context supplied by the engine |
| `timestamp` | Delivery timestamp |
| `channel_name` | Destination channel name |

Example:

```liquid
[{{ app_name }}] {{ domain }} is offline

{{ count }} service{% if count > 1 %}s are{% else %} is{% endif %} unavailable: {{ sites }}.
```

The dashboard validates syntax before saving. A custom webhook body can also use `title`, `body`, and context variables; the rendered result must be a JSON document.

## Backup

`./scripts/backup.sh` backs up MariaDB and local identity data. Also keep `.env`, or at least `INSIGHT_NOTIFICATION_ENCRYPTION_KEY`, in a separate vault. The key is not stored in the database and Insight has no recovery mechanism. Replacing it without decrypting and re-encrypting channels makes existing configurations unusable.

The delivery log retains the channel, event, rendered title, and any error for 90 days. It never stores passwords, tokens, or the complete message body.
