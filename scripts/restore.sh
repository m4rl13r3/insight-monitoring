#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

archive="${1:-}"
if [ -z "$archive" ] || [ ! -f "$archive" ]; then
    echo "Usage: INSIGHT_RESTORE_CONFIRM=1 ./scripts/restore.sh path/backup.tar.gz" >&2
    exit 1
fi

if [ "${INSIGHT_RESTORE_CONFIRM:-0}" != "1" ]; then
    echo "Restoration replaces current data. Run again with INSIGHT_RESTORE_CONFIRM=1." >&2
    exit 1
fi

archive="$(cd "$(dirname "$archive")" && pwd)/$(basename "$archive")"
env_file="${INSIGHT_COMPOSE_ENV_FILE:-.env}"
project_name="${INSIGHT_COMPOSE_PROJECT_NAME:-}"
compose=(docker compose)

if [ -n "$env_file" ]; then
    if [ ! -f "$env_file" ]; then
        echo "Environment file ${env_file} was not found." >&2
        exit 1
    fi
    compose+=(--env-file "$env_file")
fi

if [ -n "$project_name" ]; then
    compose+=(-p "$project_name")
fi

checksum_file="${archive}.sha256"
if [ -f "$checksum_file" ]; then
    expected="$(awk 'NR == 1 {print $1}' "$checksum_file")"
    if command -v shasum >/dev/null 2>&1; then
        actual="$(shasum -a 256 "$archive" | awk '{print $1}')"
    else
        actual="$(sha256sum "$archive" | awk '{print $1}')"
    fi
    if [ "$actual" != "$expected" ]; then
        echo "The backup SHA-256 checksum is invalid." >&2
        exit 1
    fi
fi

temporary_dir="$(mktemp -d)"
services_stopped=0
cleanup() {
    rm -rf "$temporary_dir"
    if [ "$services_stopped" = "1" ]; then
        "${compose[@]}" up -d >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

tar -C "$temporary_dir" -xzf "$archive"
for required_file in database.sql auth.tar metadata.json; do
    if [ ! -s "${temporary_dir}/${required_file}" ]; then
        echo "The backup does not contain ${required_file}." >&2
        exit 1
    fi
done

if [ "${INSIGHT_RESTORE_SKIP_SAFETY_BACKUP:-0}" != "1" ]; then
    safety_path="${root_dir}/backups/avant-restauration-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
    INSIGHT_COMPOSE_ENV_FILE="$env_file" INSIGHT_COMPOSE_PROJECT_NAME="$project_name" "${root_dir}/scripts/backup.sh" "$safety_path" >/dev/null
    echo "Safety backup created: ${safety_path}"
fi

"${compose[@]}" stop worker web >/dev/null
services_stopped=1

"${compose[@]}" exec -T db sh -lc 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' <"${temporary_dir}/database.sql"

"${compose[@]}" exec -T php sh -lc '
set -eu
target_path="${INSIGHT_AUTH_DB_PATH:-/var/lib/insight-auth/auth.sqlite}"
target_dir="$(dirname "$target_path")"
import_dir="$(mktemp -d)"
trap '\''rm -rf "$import_dir"'\'' EXIT
tar -C "$import_dir" -xf -
if [ -f "$import_dir/auth.sqlite" ]; then
    php -r '\''
$database = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
if ($database->querySingle("PRAGMA integrity_check") !== "ok") {
    fwrite(STDERR, "The restored identity database is invalid.\n");
    exit(1);
}
'\'' "$import_dir/auth.sqlite"
fi
mkdir -p "$target_dir"
find "$target_dir" -mindepth 1 -maxdepth 1 -not -name sessions -exec rm -rf {} +
rm -rf "$target_dir/sessions"
mkdir -p "$target_dir/sessions"
for source_file in "$import_dir"/*; do
    [ -f "$source_file" ] || continue
    mv "$source_file" "$target_dir/"
done
chmod 700 "$target_dir" "$target_dir/sessions"
find "$target_dir" -maxdepth 1 -type f -exec chmod 600 {} +
' <"${temporary_dir}/auth.tar"

"${compose[@]}" restart php >/dev/null
"${compose[@]}" up -d --wait --wait-timeout 120 >/dev/null
services_stopped=0

"${compose[@]}" exec -T db sh -lc 'mariadb --batch --skip-column-names -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT 1"' | grep -qx 1
"${compose[@]}" exec -T php php -r '$path = getenv("INSIGHT_AUTH_DB_PATH") ?: "/var/lib/insight-auth/auth.sqlite"; if (is_file($path)) { $database = new SQLite3($path, SQLITE3_OPEN_READONLY); exit($database->querySingle("PRAGMA integrity_check") === "ok" ? 0 : 1); }'

echo "Restore completed and validated. Previous sessions were invalidated."
