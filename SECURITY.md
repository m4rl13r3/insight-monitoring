# Security

## Supported versions

Security fixes target the latest published Insight release.

## Reporting a vulnerability

Do not open a public issue for an exploitable vulnerability. Use the repository's private **Security -> Advisories -> Report a vulnerability** flow. The maintainer must enable this feature before making the repository public.

Include the affected version, reproduction conditions, estimated impact, and a proposed fix when possible. Do not include secrets, personal information, or production data in the report.

## Deployment best practices

- Replace all example passwords before the first public deployment.
- Keep `.env` outside version control and restrict its permissions.
- Expose only the `web` service, never MariaDB or PHP-FPM directly.
- Use HTTPS in front of Nginx and restrict `INSIGHT_ALLOWED_ORIGINS` to your domains.
- Create the first account from a trusted network, use a unique password, and back up the private `insight_auth` volume.
- Never place `INSIGHT_AUTH_DB_PATH` under the public directory. Keep `INSIGHT_AUTH_COOKIE_SECURE=auto` or explicitly enable it behind HTTPS.
- Keep `INSIGHT_AUTH_COOKIE_SAMESITE=Lax` with external SSO because the OIDC callback is a cross-site navigation. Use `Strict` only when external SSO is disabled.
- Never set `INSIGHT_DEV_AUTH_BYPASS=1` outside an ephemeral local environment. This setting completely removes dashboard protection.
- Enable the headless API only when needed, restrict `INSIGHT_API_ALLOWED_ORIGINS`, grant minimum permissions, and revoke unused tokens.
- Keep API tokens and OAuth secrets on the server. Never place them in a URL, JavaScript bundle, screenshot, or log.
- For SSO, maintain a local policy based on verified emails or groups, and keep the local account as fallback access. `INSIGHT_SSO_ALLOW_ALL=1` assumes strict application assignment at the identity provider.
- Back up the `insight_auth` volume with its OIDC private key. Losing or unexpectedly rotating it invalidates outstanding ID Tokens.
- For distributed agents, generate a random `INSIGHT_AGENT_MASTER_SECRET`, enforce HTTPS, and provide each server only with its derived secret.
- Give each agent a unique key, immediately revoke removed nodes, and disable automatic registration after enrollment when your fleet is fixed.
- Protect `/metrics` with `INSIGHT_METRICS_TOKEN` or leave the endpoint disabled.
- Never put tokens, passwords, or sensitive data in monitored URLs: targets appear in the dashboard, metrics, and observations.
- Keep notifications and ingestion disabled until they are configured.
- Generate `INSIGHT_NOTIFICATION_ENCRYPTION_KEY` with `openssl rand -hex 32`, back it up with the database, and never store it in MariaDB. Channel secrets cannot be recovered without this key.
- Do not replace the encryption key without a migration procedure. A direct substitution makes every existing notification configuration unreadable.
- Treat Apprise URLs, webhook URLs, and authorization headers as secrets. Insight masks them in the API, but their destinations can still receive alert content.
