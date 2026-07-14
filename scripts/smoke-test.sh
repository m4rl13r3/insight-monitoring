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
access_id=""
audit_baseline=""
headless_original=""
oauth_original=""

cleanup_smoke() {
    local failed=0
    set +e
    "${compose[@]}" exec -T php php -r '
            $_SERVER["SCRIPT_FILENAME"] = "cli";
            $_SERVER["REMOTE_ADDR"] = "127.0.0.1";
            require "public/admin/_access.php";
            $auditBaseline = (int)$argv[2];
            $database = insight_auth_db();
            $statement = $database->prepare("DELETE FROM auth_api_tokens WHERE name = :name");
            $statement->execute(["name" => "Smoke test"]);
            $statement = $database->prepare("DELETE FROM auth_audit_log WHERE id > :baseline AND event = :event AND user_id IS NULL");
            $statement->execute(["baseline" => $auditBaseline, "event" => "api_token_created"]);
            if (in_array($argv[3], ["0", "1"], true) && in_array($argv[4], ["0", "1"], true)) {
                $features = [
                    "headless_api_enabled" => $argv[3] === "1",
                    "oauth_provider_enabled" => $argv[4] === "1",
                ];
                foreach ($features as $feature => $enabled) {
                    if (insight_access_feature_enabled($feature) !== $enabled) {
                        insight_access_set_feature($feature, $enabled);
                    }
                }
            }
        ' "${access_id:-0}" "${audit_baseline:-0}" "$headless_original" "$oauth_original" >/dev/null 2>&1 || failed=1
    "${compose[@]}" exec -T db sh -lc 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "DELETE FROM incidents WHERE source_mode = \"system\" AND site_label IN (\"http://web\", \"db\", \"db:3306\"); DELETE FROM sites WHERE url IN (\"http://web\", \"db\", \"db:3306\");"' >/dev/null 2>&1 || failed=1
    set -e
    return "$failed"
}

finish_smoke() {
    local status=$?
    trap - EXIT INT TERM
    cleanup_smoke || status=1
    exit "$status"
}

trap finish_smoke EXIT INT TERM

curl --fail --silent --show-error --retry 30 --retry-all-errors --retry-delay 2 "${base_url}/" >/dev/null
INSIGHT_COMPOSE_ENV_FILE="$env_file" INSIGHT_COMPOSE_PROJECT_NAME="$project_name" ./scripts/migrate.sh >/tmp/insight-migrate-first.txt
INSIGHT_COMPOSE_ENV_FILE="$env_file" INSIGHT_COMPOSE_PROJECT_NAME="$project_name" ./scripts/migrate.sh >/tmp/insight-migrate-second.txt
grep -q '0 applied' /tmp/insight-migrate-second.txt
curl --fail --silent --show-error "${base_url}/hourly_stats_report.php?contract=v2" >/tmp/insight-hourly.json

feature_json="$("${compose[@]}" exec -T php php -r '$_SERVER["SCRIPT_FILENAME"]="cli"; require "public/admin/_access.php"; echo json_encode(["headless"=>insight_access_feature_enabled("headless_api_enabled"),"oauth"=>insight_access_feature_enabled("oauth_provider_enabled"),"audit_baseline"=>(int)insight_auth_db()->query("SELECT COALESCE(MAX(id),0) FROM auth_audit_log")->fetchColumn()]);')"
headless_original="$(python3 -c 'import json,sys; print("1" if json.loads(sys.argv[1])["headless"] else "0")' "$feature_json")"
oauth_original="$(python3 -c 'import json,sys; print("1" if json.loads(sys.argv[1])["oauth"] else "0")' "$feature_json")"
audit_baseline="$(python3 -c 'import json,sys; print(int(json.loads(sys.argv[1])["audit_baseline"]))' "$feature_json")"
access_json="$("${compose[@]}" exec -T php php -r '$_SERVER["SCRIPT_FILENAME"]="cli"; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; require "public/admin/_access.php"; insight_access_set_feature("headless_api_enabled", true); insight_access_set_feature("oauth_provider_enabled", true); echo json_encode(insight_access_create_token(["name"=>"Smoke test","scopes"=>["status:read","incidents:read","maintenances:read","status-pages:read","notifications:read"],"expires_in_days"=>1], insight_auth_dev_user()));')"
access_token="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["token"])' "$access_json")"
access_id="$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["item"]["id"])' "$access_json")"
test "$(curl --silent --output /dev/null --write-out '%{http_code}' "${base_url}/api/v1/status.php")" = "401"
curl --fail --silent --show-error --header "Authorization: Bearer ${access_token}" "${base_url}/api/v1/status.php" >/tmp/insight-headless-status.json
curl --fail --silent --show-error --header "Authorization: Bearer ${access_token}" "${base_url}/api/v1/incidents.php" >/tmp/insight-headless-incidents.json
curl --fail --silent --show-error --header "Authorization: Bearer ${access_token}" "${base_url}/api/v1/maintenances.php" >/tmp/insight-headless-maintenances.json
curl --fail --silent --show-error --header "Authorization: Bearer ${access_token}" "${base_url}/api/v1/status-pages.php" >/tmp/insight-headless-status-pages.json
curl --fail --silent --show-error --header "Authorization: Bearer ${access_token}" "${base_url}/api/v1/notifications.php" >/tmp/insight-headless-notifications.json
curl --fail --silent --show-error --header "Authorization: Bearer ${access_token}" "${base_url}/api/v1/oncall.php" >/tmp/insight-headless-oncall.json
curl --fail --silent --show-error "${base_url}/api/v1/openapi.php" >/tmp/insight-headless-openapi.json
curl --fail --silent --show-error "${base_url}/.well-known/openid-configuration" >/tmp/insight-oidc-discovery.json
curl --fail --silent --show-error "${base_url}/api/oauth/jwks.php" >/tmp/insight-oidc-jwks.json

test "$(curl --silent --output /dev/null --write-out '%{http_code}' --request POST "${base_url}/hourly_stats_report.php?contract=v2")" = "405"
test "$(curl --silent --output /dev/null --write-out '%{http_code}' "${base_url}/api/hourly_report/helpers.php")" = "403"

"${compose[@]}" exec -T php php tests/mariadb_integration.php
"${compose[@]}" exec -T php php tests/mariadb_workflows.php
"${compose[@]}" exec -T worker python3 tests/mariadb_aggregation.py
"${compose[@]}" exec -T db sh -lc 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "DELETE FROM sites WHERE url IN (\"http://web\", \"db\", \"db:3306\")"'
"${compose[@]}" exec -T worker python3 monitoring/python_monitoring/cli.py actions add --site-url http://web --probe-type http >/dev/null
"${compose[@]}" exec -T worker python3 monitoring/python_monitoring/cli.py actions add --site-url db --probe-type icmp >/dev/null
"${compose[@]}" exec -T worker python3 monitoring/python_monitoring/cli.py actions add --site-url db:3306 --probe-type tcp >/dev/null
"${compose[@]}" exec -T worker env INSIGHT_SCHEDULER_FORCE_RUN=1 python3 monitoring/python_monitoring/cli.py monitor >/tmp/insight-monitor.json
"${compose[@]}" exec -T worker python3 monitoring/python_monitoring/cli.py hourly >/tmp/insight-hourly-job.txt
"${compose[@]}" exec -T worker python3 monitoring/python_monitoring/cli.py daily >/tmp/insight-daily-job.txt
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
headless_incidents = json.loads(Path('/tmp/insight-headless-incidents.json').read_text())
headless_maintenances = json.loads(Path('/tmp/insight-headless-maintenances.json').read_text())
headless_status_pages = json.loads(Path('/tmp/insight-headless-status-pages.json').read_text())
headless_notifications = json.loads(Path('/tmp/insight-headless-notifications.json').read_text())
headless_oncall = json.loads(Path('/tmp/insight-headless-oncall.json').read_text())
headless_openapi = json.loads(Path('/tmp/insight-headless-openapi.json').read_text())
discovery = json.loads(Path('/tmp/insight-oidc-discovery.json').read_text())
jwks = json.loads(Path('/tmp/insight-oidc-jwks.json').read_text())
probe_statuses = dict(
    line.split('\t', 1)
    for line in Path('/tmp/insight-probes.tsv').read_text().splitlines()
    if '\t' in line
)

assert monitor.get('ok') is True
assert int(monitor.get('sites_checked', 0)) >= 3
assert set((monitor.get('oncall') or {}).keys()) == {'due', 'sent', 'failed', 'without_shift', 'disabled'}
assert sites.get('ok') is True
targets = {site.get('url') for site in sites.get('sites', [])}
assert {'http://web', 'db', 'db:3306'} <= targets
assert probe_statuses == {'db': 'online', 'db:3306': 'online', 'http://web': 'online'}
assert hourly.get('contract') == 'v2'
assert runtime.get('ok') is True
runtime_data = runtime.get('data') or {}
assert runtime_data.get('active_engine') in {'python', 'consensus'}
assert runtime_data.get('monitor_checked_by') in {'python', 'agents'}
assert int(runtime_data.get('monitor_last_ok', 0)) == 1
assert int(runtime_data.get('hourly_last_ok', 0)) == 1
assert int(runtime_data.get('daily_last_ok', 0)) == 1
assert runtime_data.get('last_monitor_at')
assert runtime_data.get('last_hourly_at')
assert runtime_data.get('last_daily_at')
assert int(runtime_data.get('is_degraded', 1)) == 0
assert headless.get('ok') is True
assert headless_incidents.get('ok') is True
assert headless_maintenances.get('ok') is True
assert headless_status_pages.get('ok') is True
assert headless_notifications.get('ok') is True
assert headless_oncall.get('ok') is True
assert headless_openapi.get('openapi') == '3.1.0'
assert '/status-pages.php' in headless_openapi.get('paths', {})
assert '/oncall.php' in headless_openapi.get('paths', {})
assert discovery.get('code_challenge_methods_supported') == ['S256']
assert len(jwks.get('keys', [])) == 1
PY

curl --fail --silent --show-error "${base_url}/hourly_stats_report.php?contract=v2&mode=incidents" >/dev/null
curl --fail --silent --show-error "${base_url}/hourly_stats_report.php?contract=v2&mode=incidents&format=rss" >/dev/null

if ! cleanup_smoke; then
    echo "Smoke-test cleanup failed." >&2
    exit 1
fi
trap - EXIT INT TERM
echo "Insight smoke test passed."
