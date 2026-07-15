#!/usr/bin/env bash

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

if [ -d "$root/monitoring/.pydeps" ]; then
    export PYTHONPATH="$root/monitoring/.pydeps${PYTHONPATH:+:$PYTHONPATH}"
fi

if ! command -v rg >/dev/null 2>&1; then
    echo "ripgrep (rg) is required for the release check." >&2
    exit 1
fi

for required in LICENSE README.md SECURITY.md CONTRIBUTING.md CHANGELOG.md THIRD_PARTY_NOTICES.md .env.example docker-compose.yml docker-compose.docker-probes.yml.example database/schema.sql docs/production.md docs/updates.md docs/probes.md docs/availability-calculation.md docs/configuration-as-code.md licenses/python/paho-mqtt.txt licenses/python/psycopg.txt licenses/python/PyYAML.txt scripts/production-check.sh scripts/backup-scheduled.sh scripts/create-admin.php scripts/dev-compose.sh scripts/migrate.sh scripts/update.sh scripts/install-auto-update.sh; do
    test -f "$required"
done

if rg -n -i "[àâäçéèêëîïôöùûüœ]|\\b(avec|pour|dans|ajouté|modifié|supprimé|sécurité|utilisez|aucun|erreur|échec|mise à jour)\\b" README.md CHANGELOG.md CONTRIBUTING.md SECURITY.md THIRD_PARTY_NOTICES.md docs monitoring public scripts src tests database docker .github package.json docker-compose.yml docker-compose.agent.yml --hidden --glob '!public/assets/**' --glob '!public/locales/fr.json' --glob '!database/migrations/003-notifications.sql' --glob '!database/migrations/005-english-notification-defaults.sql' --glob '!scripts/check-release.sh' --glob '!monitoring/.pydeps/**' --glob '!monitoring/logs/**' --glob '!public/logs/**' | rg -v 'sëcret-token|shared-sëcret'; then
    echo "French source text remains outside approved localization and migration files." >&2
    exit 1
fi

removed_engine_file="$(printf '%s%s' 'monitoring/php_' 'fallback.php')"
removed_engine_symbol="$(printf '%s%s' 'php_' 'fallback')"
removed_engine_variable="$(printf '%s%s' 'PHP_' 'FALLBACK')"
removed_engine_column="$(printf '%s%s' 'monitor_' 'fallback_message')"
test ! -e "$removed_engine_file"
if rg -n "${removed_engine_symbol}|${removed_engine_variable}|${removed_engine_column}" . --glob '!node_modules/**' --glob '!public/assets/**'; then
    exit 1
fi

package_version="$(node -p 'require("./package.json").version')"
agent_version="$(sed -n 's/^VERSION = "\([^"]*\)"/\1/p' monitoring/agent/agent.py)"
test "$package_version" = "$agent_version"

legacy_brand="$(printf '%s%s' 'MAR' 'LIERE')"
legacy_domain="$(printf '%s%s' 'marlie' '\.re')"
legacy_tool="$(printf '%s%s' 'atr' '-x')"
legacy_path="$(printf '%s%s' '/opt/MAR' 'LIERE')"

if rg -n -i "${legacy_brand}|${legacy_domain}|${legacy_tool}|${legacy_path}" . --glob '!node_modules/**' --glob '!data/**' --glob '!monitoring/logs/**' --glob '!public/logs/**'; then
    exit 1
fi

if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    if git ls-files | rg '(^|/)(data|monitoring/logs|public/logs)/|\.(log|sqlite|pyc|png|jpg)$'; then
        exit 1
    fi
    if git ls-files | rg '(^|/)\.env($|\.)' | rg -v '^\.env\.example$|^\.env\.agent\.example$'; then
        exit 1
    fi
fi

find scripts -maxdepth 1 -name '*.sh' -print0 | xargs -0 -n1 bash -n
npm run build
npm run check
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l >/dev/null
python3 -m py_compile monitoring/python_monitoring/*.py monitoring/agent/agent.py
php tests/admin_probes.php
php tests/admin_incidents.php
php tests/admin_slo.php
php tests/admin_notifications.php
php tests/admin_auth.php
php tests/admin_access.php
php tests/admin_sso.php
php tests/public_api.php
php tests/public_status_pages.php
python3 -m unittest discover -s tests -p 'test_*.py' -v

echo "Insight ${package_version} release check passed."
