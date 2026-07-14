# Contributing to Insight

Thank you for contributing to Insight.

## Prepare the environment

```bash
cp .env.example .env
docker compose up -d --build
```

Work on a dedicated branch and keep changes focused on a specific problem. Never add secrets, logs, debugging screenshots, or data from a real instance.

## Required checks

The release check requires `rg` (ripgrep).

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
find public -name '*.js' -not -path 'public/assets/*' -print0 | xargs -0 -n1 node --check
python3 -m py_compile monitoring/python_monitoring/*.py monitoring/agent/agent.py
php tests/admin_probes.php
php tests/admin_notifications.php
php tests/admin_auth.php
php tests/admin_access.php
php tests/admin_sso.php
php tests/public_api.php
python3 -m unittest discover -s tests -p 'test_*.py' -v
```

For behavioral changes, also check the public page, the v2 JSON contract, engine state, and RSS feed on a fresh installation.

Deployment changes must also pass `./scripts/smoke-test.sh` after `docker compose up -d --build` on empty volumes. This test includes `tests/mariadb_integration.php`.

Distributed changes must cover at least one agent, two disagreeing agents, a three-agent majority, missing responses, and replaying a batch after restart.

Every new visible string must use the i18n engine. Keep `public/locales/fr.json` and `public/locales/en.json` synchronized with the same keys.

## Proposing a change

Describe the problem, selected solution, visible effects, and completed checks. Add a compatible migration when a schema change is required, and keep `database/schema.sql` current for new installations.
