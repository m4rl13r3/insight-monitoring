#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

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

if ! "${compose[@]}" ps --services --status running | grep -qx db; then
    echo "The MariaDB service must be running." >&2
    exit 1
fi

if ! "${compose[@]}" ps --services --status running | grep -qx php; then
    echo "The PHP service must be running." >&2
    exit 1
fi

output="${1:-${root_dir}/backups/insight-$(date -u +%Y%m%dT%H%M%SZ).tar.gz}"
mkdir -p "$(dirname "$output")"
output="$(cd "$(dirname "$output")" && pwd)/$(basename "$output")"

temporary_dir="$(mktemp -d)"
trap 'rm -rf "$temporary_dir"' EXIT
umask 077

"${compose[@]}" exec -T db sh -lc 'exec mariadb-dump --single-transaction --quick --routines --triggers --events --hex-blob -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' >"${temporary_dir}/database.sql"

"${compose[@]}" exec -T php sh -lc '
set -eu
source_path="${INSIGHT_AUTH_DB_PATH:-/var/lib/insight-auth/auth.sqlite}"
source_dir="$(dirname "$source_path")"
export_dir="$(mktemp -d)"
trap '\''rm -rf "$export_dir"'\'' EXIT
if [ -f "$source_path" ]; then
    php -r '\''
$source = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
$target = new SQLite3($argv[2], SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
if (!$source->backup($target)) {
    fwrite(STDERR, "Local identity backup failed.\n");
    exit(1);
}
if ($target->querySingle("PRAGMA integrity_check") !== "ok") {
    fwrite(STDERR, "The local identity copy is invalid.\n");
    exit(1);
}
'\'' "$source_path" "$export_dir/auth.sqlite"
fi
for source_file in "$source_dir"/*; do
    [ -f "$source_file" ] || continue
    [ "$source_file" = "$source_path" ] && continue
    cp "$source_file" "$export_dir/"
done
tar -C "$export_dir" -cf - .
' >"${temporary_dir}/auth.tar"

created_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
printf '{"format":1,"application":"Insight","created_at":"%s"}\n' "$created_at" >"${temporary_dir}/metadata.json"
printf '%s\n' 'This archive contains MariaDB and local identity data. Keep the .env file separately in a secure location.' >"${temporary_dir}/README.txt"

tar -C "$temporary_dir" -czf "$output" database.sql auth.tar metadata.json README.txt

if command -v shasum >/dev/null 2>&1; then
    checksum="$(shasum -a 256 "$output" | awk '{print $1}')"
else
    checksum="$(sha256sum "$output" | awk '{print $1}')"
fi
printf '%s  %s\n' "$checksum" "$(basename "$output")" >"${output}.sha256"

echo "Backup created: ${output}"
echo "Checksum created: ${output}.sha256"
