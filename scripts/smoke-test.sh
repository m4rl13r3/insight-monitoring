#!/usr/bin/env bash

set -euo pipefail

env_file="${INSIGHT_COMPOSE_ENV_FILE:-}"
project_name="${INSIGHT_COMPOSE_PROJECT_NAME:-}"
compose=(docker compose)
if [ -n "$env_file" ]; then
    compose+=(--env-file "$env_file")
fi
if [ -n "$project_name" ]; then
    compose+=(-p "$project_name")
fi

port="${INSIGHT_HTTP_PORT:-}"
if [ -z "$port" ] && [ -n "$env_file" ] && [ -f "$env_file" ]; then
    port="$(awk 'index($0, "INSIGHT_HTTP_PORT=") == 1 { sub(/^[^=]*=/, ""); print; exit }' "$env_file")"
fi
port="${port:-8080}"
base_url="http://127.0.0.1:${port}"

curl --fail --silent --show-error --retry 30 --retry-all-errors --retry-delay 2 "${base_url}/" >/dev/null
INSIGHT_COMPOSE_ENV_FILE="$env_file" INSIGHT_COMPOSE_PROJECT_NAME="$project_name" ./scripts/migrate.sh >/tmp/insight-migrate-first.txt
INSIGHT_COMPOSE_ENV_FILE="$env_file" INSIGHT_COMPOSE_PROJECT_NAME="$project_name" ./scripts/migrate.sh >/tmp/insight-migrate-second.txt
grep -q '0 applied' /tmp/insight-migrate-second.txt
curl --fail --silent --show-error "${base_url}/hourly_stats_report.php?contract=v2" >/tmp/insight-hourly.json

access_json="$("${compose[@]}" exec -T php php -r '$_SERVER["SCRIPT_FILENAME"]="cli"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; require "public/admin/_access.php"; insight_access_set_feature("headless_api_enabled", true); echo json_encode(insight_access_create_token(["name"=>"Smoke test","scopes"=>["status:read"],"expires_in_days"=>1], insight_auth_dev_user()));')"
access_token="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["token"])' "$access_json")"
access_id="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["item"]["id"])' "$access_json")"
test "$(curl --silent --output /dev/null --write-out '%{http_code}' "${base_url}/api/v1/status.php")" = "401"
curl --fail --silent --show-error --header "Authorization: Bearer ${access_token}" "${base_url}/api/v1/status.php" >/tmp/insight-headless-status.json
"${compose[@]}" exec -T php php -r '$_SERVER["SCRIPT_FILENAME"]="cli"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; require "public/admin/_access.php"; insight_access_revoke_token((int)$argv[1], insight_auth_dev_user()); insight_access_set_feature("headless_api_enabled", false); insight_access_set_feature("oauth_provider_enabled", true);' "$access_id"
curl --fail --silent --show-error "${base_url}/.well-known/openid-configuration" >/tmp/insight-oidc-discovery.json
curl --fail --silent --show-error "${base_url}/api/oauth/jwks.php" >/tmp/insight-oidc-jwks.json
"${compose[@]}" exec -T php php -r '$_SERVER["SCRIPT_FILENAME"]="cli"; require "public/admin/_access.php"; insight_access_set_feature("oauth_provider_enabled", false);'

test "$(curl --silent --output /dev/null --write-out '%{http_code}' --request POST "${base_url}/hourly_stats_report.php?contract=v2")" = "405"
test "$(curl --silent --output /dev/null --write-out '%{http_code}' "${base_url}/api/hourly_report/helpers.php")" = "403"

"${compose[@]}" exec -T php php tests/mariadb_integration.php
"${compose[@]}" exec -T worker python3 tests/mariadb_aggregation.py
"${compose[@]}" exec -T db sh -lc 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "DELETE FROM sites WHERE url IN (\"http://web\", \"db\", \"db:3306\")"'
"${compose[@]}" exec -T worker python3 monitoring/python_monitoring/cli.py actions add --site-url http://web --probe-type http >/dev/null
"${compose[@]}" exec -T worker python3 monitoring/python_monitoring/cli.py actions add --site-url db --probe-type icmp >/dev/null
"${compose[@]}" exec -T worker python3 monitoring/python_monitoring/cli.py actions add --site-url db:3306 --probe-type tcp >/dev/null
"${compose[@]}" exec -T worker env MONITORING_SCHEDULER_FORCE_RUN=1 php monitoring/monitoring.php >/tmp/insight-monitor.json
"${compose[@]}" exec -T worker php monitoring/hourly.php >/tmp/insight-hourly-job.txt
"${compose[@]}" exec -T worker php monitoring/daily.php >/tmp/insight-daily-job.txt
"${compose[@]}" exec -T worker python3 monitoring/python_monitoring/cli.py actions list >/tmp/insight-sites.json
"${compose[@]}" exec -T db sh -lc 'mariadb --batch --skip-column-names -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT s.url, p.status FROM sites s JOIN probes p ON p.id = (SELECT MAX(latest.id) FROM probes latest WHERE latest.site_id = s.id AND latest.checked_by = \"pyt\") WHERE s.url IN (\"http://web\", \"db\", \"db:3306\") ORDER BY s.url"' >/tmp/insight-probes.tsv
curl --fail --silent --show-error "${base_url}/api/public_runtime_state.php" >/tmp/insight-runtime.json

python3 - <<'PY'
import json
from pathlib import Path

monitor = json.loads(Path('/tmp/insight-monitor.json').read_text())
sites = json.loads(Path('/tmp/insight-sites.json').read_text())
hourly = json.loads(Path('/tmp/insight-hourly.json').read_text())
runtime = json.loads(Path('/tmp/insight-runtime.json').read_text())
headless = json.loads(Path('/tmp/insight-headless-status.json').read_text())
discovery = json.loads(Path('/tmp/insight-oidc-discovery.json').read_text())
jwks = json.loads(Path('/tmp/insight-oidc-jwks.json').read_text())
probe_statuses = dict(
    line.split('\t', 1)
    for line in Path('/tmp/insight-probes.tsv').read_text().splitlines()
    if '\t' in line
)

assert monitor.get('ok') is True
assert int(monitor.get('sites_checked', 0)) >= 3
assert sites.get('ok') is True
targets = {site.get('url') for site in sites.get('sites', [])}
assert {'http://web', 'db', 'db:3306'} <= targets
assert probe_statuses == {'db': 'online', 'db:3306': 'online', 'http://web': 'online'}
assert hourly.get('contract') == 'v2'
assert runtime.get('ok') is True
runtime_data = runtime.get('data') or {}
assert runtime_data.get('active_engine') == 'python'
assert runtime_data.get('monitor_checked_by') == 'python'
assert int(runtime_data.get('monitor_last_ok', 0)) == 1
assert int(runtime_data.get('hourly_last_ok', 0)) == 1
assert int(runtime_data.get('daily_last_ok', 0)) == 1
assert runtime_data.get('last_monitor_at')
assert runtime_data.get('last_hourly_at')
assert runtime_data.get('last_daily_at')
assert int(runtime_data.get('is_degraded', 1)) == 0
assert headless.get('ok') is True
assert discovery.get('code_challenge_methods_supported') == ['S256']
assert len(jwks.get('keys', [])) == 1
PY

curl --fail --silent --show-error "${base_url}/hourly_stats_report.php?contract=v2&mode=incidents" >/dev/null
curl --fail --silent --show-error "${base_url}/hourly_stats_report.php?contract=v2&mode=incidents&format=rss" >/dev/null

echo "Insight smoke test passed."
