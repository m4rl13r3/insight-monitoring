#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

env_file="${INSIGHT_COMPOSE_ENV_FILE:-}"
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

if ! "${compose[@]}" ps --services --status running | grep -qx db; then
    echo "MariaDB must be running before migrations." >&2
    exit 1
fi

"${compose[@]}" exec -T db sh -lc 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "CREATE TABLE IF NOT EXISTS insight_schema_migrations (version VARCHAR(128) NOT NULL, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"'

applied=0
skipped=0
for migration in database/migrations/*.sql; do
    [ -f "$migration" ] || continue
    version="$(basename "$migration")"
    printf '%s' "$version" | grep -Eq '^[0-9A-Za-z._-]+$' || {
        echo "Invalid migration name: ${version}" >&2
        exit 1
    }
    if command -v shasum >/dev/null 2>&1; then
        checksum="$(shasum -a 256 "$migration" | awk '{print $1}')"
    else
        checksum="$(sha256sum "$migration" | awk '{print $1}')"
    fi
    current_checksum="$("${compose[@]}" exec -T db sh -lc 'exec mariadb --batch --skip-column-names -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "$1"' sh "SELECT checksum FROM insight_schema_migrations WHERE version = '${version}' LIMIT 1" | tr -d '\r')"
    if [ -n "$current_checksum" ]; then
        if [ "$current_checksum" != "$checksum" ]; then
            echo "Migration ${version} was modified after it was applied." >&2
            exit 1
        fi
        skipped=$((skipped + 1))
        continue
    fi

    echo "Applying ${version}..."
    "${compose[@]}" exec -T db sh -lc 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' <"$migration"
    "${compose[@]}" exec -T db sh -lc 'exec mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "$1"' sh "INSERT INTO insight_schema_migrations (version, checksum) VALUES ('${version}', '${checksum}')"
    applied=$((applied + 1))
done

echo "Migrations complete: ${applied} applied, ${skipped} already present."
