#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

strict=0
if [ "${1:-}" = "--strict" ]; then
    strict=1
elif [ -n "${1:-}" ]; then
    echo "Usage: $0 [--strict]" >&2
    exit 2
fi

env_file="${INSIGHT_COMPOSE_ENV_FILE:-.env}"
project_name="${INSIGHT_COMPOSE_PROJECT_NAME:-}"
errors=0
warnings=0

fail() {
    echo "ERROR: $1" >&2
    errors=$((errors + 1))
}

warn() {
    echo "WARNING: $1" >&2
    warnings=$((warnings + 1))
}

pass() {
    echo "OK: $1"
}

env_value() {
    local key="$1"
    local value
    value="$(awk -v target="$key" 'index($0, target "=") == 1 { sub(/^[^=]*=/, ""); print; exit }' "$env_file")"
    value="${value%$'\r'}"
    if [ "${value#\"}" != "$value" ] && [ "${value%\"}" != "$value" ]; then
        value="${value#\"}"
        value="${value%\"}"
    elif [ "${value#\'}" != "$value" ] && [ "${value%\'}" != "$value" ]; then
        value="${value#\'}"
        value="${value%\'}"
    fi
    printf '%s' "$value"
}

is_example_value() {
    printf '%s' "$1" | grep -Eqi '(^|[./:@_-])(example|localhost)([./:@_-]|$)|127\.0\.0\.1'
}

if [ ! -f "$env_file" ]; then
    echo "Environment file ${env_file} was not found." >&2
    exit 1
fi

permissions=""
if stat -f '%Lp' "$env_file" >/dev/null 2>&1; then
    permissions="$(stat -f '%Lp' "$env_file")"
elif stat -c '%a' "$env_file" >/dev/null 2>&1; then
    permissions="$(stat -c '%a' "$env_file")"
fi
if [ "$permissions" = "600" ]; then
    pass "the environment file is private"
else
    fail "protect ${env_file} with chmod 600"
fi

app_env="$(env_value INSIGHT_APP_ENV)"
dev_bypass="$(env_value INSIGHT_DEV_AUTH_BYPASS)"
public_url="$(env_value INSIGHT_PUBLIC_URL)"
contact_email="$(env_value INSIGHT_CONTACT_EMAIL)"
http_bind="$(env_value INSIGHT_HTTP_BIND)"
cookie_secure="$(env_value INSIGHT_AUTH_COOKIE_SECURE)"
db_password="$(env_value INSIGHT_DB_PASSWORD)"
db_root_password="$(env_value INSIGHT_DB_ROOT_PASSWORD)"
notification_key="$(env_value INSIGHT_NOTIFICATION_ENCRYPTION_KEY)"
allowed_origins="$(env_value INSIGHT_ALLOWED_ORIGINS)"
api_origins="$(env_value INSIGHT_API_ALLOWED_ORIGINS)"
distributed_mode="$(env_value INSIGHT_DISTRIBUTED_MODE)"
notifications_disabled="$(env_value INSIGHT_DISABLE_NOTIFICATIONS)"

[ "$app_env" = "production" ] || fail "INSIGHT_APP_ENV must be production"
[ "$dev_bypass" = "0" ] || fail "INSIGHT_DEV_AUTH_BYPASS must be 0"
printf '%s' "$public_url" | grep -Eq '^https://[^/ ]+' || fail "INSIGHT_PUBLIC_URL must use HTTPS"
is_example_value "$public_url" && fail "INSIGHT_PUBLIC_URL must point to the instance's real domain"
printf '%s' "$contact_email" | grep -Eq '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$' || fail "INSIGHT_CONTACT_EMAIL is invalid"
is_example_value "$contact_email" && fail "INSIGHT_CONTACT_EMAIL must be a real address"
[ "${#db_password}" -ge 24 ] || fail "INSIGHT_DB_PASSWORD must contain at least 24 characters"
[ "${#db_root_password}" -ge 24 ] || fail "INSIGHT_DB_ROOT_PASSWORD must contain at least 24 characters"
if ! printf '%s' "$notification_key" | grep -Eq '^[[:xdigit:]]{64}$' && [ "${#notification_key}" -lt 32 ]; then
    fail "INSIGHT_NOTIFICATION_ENCRYPTION_KEY must contain 64 hexadecimal characters or at least 32 characters"
fi
cookie_secure_normalized="$(printf '%s' "$cookie_secure" | tr '[:upper:]' '[:lower:]')"
case "$cookie_secure_normalized" in
    1|true|yes|on|auto) ;;
    *) fail "INSIGHT_AUTH_COOKIE_SECURE must not disable secure cookies" ;;
esac

for origin_group in "$allowed_origins" "$api_origins"; do
    [ -n "$origin_group" ] || fail "CORS origin lists cannot be empty"
    old_ifs="$IFS"
    IFS=','
    for origin in $origin_group; do
        IFS="$old_ifs"
        origin="$(printf '%s' "$origin" | xargs)"
        printf '%s' "$origin" | grep -Eq '^https://[^/ ]+' || fail "all CORS origins must use HTTPS"
        printf '%s' "$origin" | grep -q '\*' && fail "wildcard CORS origins are forbidden"
        is_example_value "$origin" && fail "CORS origins must point to real domains"
        IFS=','
    done
    IFS="$old_ifs"
done

if [ "$strict" -eq 1 ]; then
    [ "$http_bind" = "127.0.0.1" ] || fail "INSIGHT_HTTP_BIND must be 127.0.0.1 behind the HTTPS proxy"
    [ "$notifications_disabled" = "0" ] || fail "enable notifications after testing them with INSIGHT_DISABLE_NOTIFICATIONS=0"
fi

if [ "$distributed_mode" = "hub" ]; then
    agent_secret="$(env_value INSIGHT_AGENT_MASTER_SECRET)"
    agent_https="$(env_value INSIGHT_AGENT_REQUIRE_HTTPS)"
    agent_auto_register="$(env_value INSIGHT_AGENT_AUTO_REGISTER)"
    [ "${#agent_secret}" -ge 32 ] || fail "INSIGHT_AGENT_MASTER_SECRET must contain at least 32 characters in hub mode"
    [ "$agent_https" = "1" ] || fail "INSIGHT_AGENT_REQUIRE_HTTPS must be 1 in hub mode"
    if [ "$strict" -eq 1 ]; then
        [ "$agent_auto_register" = "0" ] || fail "disable INSIGHT_AGENT_AUTO_REGISTER after agent enrollment"
    fi
fi

if [ "$errors" -eq 0 ]; then
    pass "the static configuration is consistent"
fi

if [ "$strict" -eq 1 ]; then
    command -v docker >/dev/null 2>&1 || fail "Docker was not found"
    compose=(docker compose --env-file "$env_file")
    if [ -n "$project_name" ]; then
        compose+=(-p "$project_name")
    fi

    if [ "$errors" -eq 0 ] || command -v docker >/dev/null 2>&1; then
        running_services="$("${compose[@]}" ps --services --status running 2>/dev/null || true)"
        for service in db php worker web; do
            printf '%s\n' "$running_services" | grep -qx "$service" || fail "Docker service ${service} is not running"
        done

        if printf '%s\n' "$running_services" | grep -qx php; then
            auth_users="$("${compose[@]}" exec -T php php -r '$path=getenv("INSIGHT_AUTH_DB_PATH") ?: "/var/lib/insight-auth/auth.sqlite"; if (!is_file($path)) { echo "0"; exit; } $db=new SQLite3($path, SQLITE3_OPEN_READONLY); echo (int)$db->querySingle("SELECT COUNT(*) FROM auth_users");' 2>/dev/null || echo 0)"
            [ "$auth_users" -gt 0 ] 2>/dev/null || fail "create the first administrator account"
        fi

        if printf '%s\n' "$running_services" | grep -qx db; then
            applied_migrations="$("${compose[@]}" exec -T db sh -lc 'mariadb --batch --skip-column-names -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT CONCAT(version, CHAR(9), checksum) FROM insight_schema_migrations ORDER BY version"' 2>/dev/null || true)"
            for migration in database/migrations/*.sql; do
                [ -f "$migration" ] || continue
                version="$(basename "$migration")"
                if command -v shasum >/dev/null 2>&1; then
                    expected_checksum="$(shasum -a 256 "$migration" | awk '{print $1}')"
                else
                    expected_checksum="$(sha256sum "$migration" | awk '{print $1}')"
                fi
                stored_checksum="$(printf '%s\n' "$applied_migrations" | awk -F '\t' -v wanted="$version" '$1 == wanted {print $2; exit}')"
                if [ -z "$stored_checksum" ]; then
                    fail "apply pending migration ${version}"
                elif [ "$stored_checksum" != "$expected_checksum" ]; then
                    fail "migration ${version} does not match its applied checksum"
                fi
            done
            db_report="$("${compose[@]}" exec -T db sh -lc 'mariadb --batch --skip-column-names -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT COUNT(*) FROM sites; SELECT COUNT(*) FROM sites WHERE LOWER(url) IN (\"http://web\", \"db\", \"db:3306\") OR LOWER(url) LIKE \"%example.com%\" OR LOWER(url) LIKE \"%example.net%\" OR LOWER(url) LIKE \"%example.org%\"; SELECT COUNT(*) FROM notification_channels WHERE enabled = 1; SELECT COUNT(*) FROM notification_channels WHERE enabled = 1 AND last_status = \"success\" AND last_test_at IS NOT NULL; SELECT COUNT(*) FROM monitoring_public_runtime_state WHERE singleton_id = 1 AND monitor_last_ok = 1 AND last_monitor_at IS NOT NULL;"' 2>/dev/null || true)"
            sites_count="$(printf '%s\n' "$db_report" | sed -n '1p')"
            smoke_count="$(printf '%s\n' "$db_report" | sed -n '2p')"
            enabled_channels="$(printf '%s\n' "$db_report" | sed -n '3p')"
            tested_channels="$(printf '%s\n' "$db_report" | sed -n '4p')"
            healthy_runtime="$(printf '%s\n' "$db_report" | sed -n '5p')"
            [ "${sites_count:-0}" -gt 0 ] 2>/dev/null || fail "create at least one real monitor"
            [ "${smoke_count:-0}" -eq 0 ] 2>/dev/null || fail "remove demo and smoke-test monitors"
            [ "${enabled_channels:-0}" -gt 0 ] 2>/dev/null || fail "enable at least one notification channel"
            [ "${tested_channels:-0}" -gt 0 ] 2>/dev/null || fail "complete a successful test delivery on an active channel"
            [ "${healthy_runtime:-0}" -eq 1 ] 2>/dev/null || fail "the worker has not published a healthy state yet"
        fi

        if printf '%s\n' "$running_services" | grep -qx web; then
            http_port="$(env_value INSIGHT_HTTP_PORT)"
            http_port="${http_port:-8080}"
            curl --fail --silent --show-error "http://127.0.0.1:${http_port}/api/public_runtime_state.php" >/dev/null 2>&1 || fail "the local state API is not responding"
        fi
    fi
fi

if [ "$errors" -gt 0 ]; then
    echo "Check failed: ${errors} error(s), ${warnings} warning(s)." >&2
    exit 1
fi

if [ "$strict" -eq 1 ]; then
    echo "Insight passes the strict production checks."
else
    echo "Insight passes the static production checks."
fi
