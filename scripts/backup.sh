#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

env_file="${INSIGHT_COMPOSE_ENV_FILE:-.env}"
project_name="${INSIGHT_COMPOSE_PROJECT_NAME:-}"
compose=(docker compose)

if [ -n "$env_file" ]; then
    if [ ! -f "$env_file" ]; then
        echo "Le fichier d’environnement ${env_file} est introuvable." >&2
        exit 1
    fi
    compose+=(--env-file "$env_file")
fi

if [ -n "$project_name" ]; then
    compose+=(-p "$project_name")
fi

if ! "${compose[@]}" ps --services --status running | grep -qx db; then
    echo "Le service MariaDB doit être démarré." >&2
    exit 1
fi

if ! "${compose[@]}" ps --services --status running | grep -qx php; then
    echo "Le service PHP doit être démarré." >&2
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
    fwrite(STDERR, "La sauvegarde de l’identité locale a échoué.\n");
    exit(1);
}
if ($target->querySingle("PRAGMA integrity_check") !== "ok") {
    fwrite(STDERR, "La copie de l’identité locale est invalide.\n");
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
printf '%s\n' 'Cette archive contient MariaDB et l’identité locale. Conservez aussi le fichier .env séparément et en lieu sûr.' >"${temporary_dir}/LISEZ-MOI.txt"

tar -C "$temporary_dir" -czf "$output" database.sql auth.tar metadata.json LISEZ-MOI.txt

if command -v shasum >/dev/null 2>&1; then
    checksum="$(shasum -a 256 "$output" | awk '{print $1}')"
else
    checksum="$(sha256sum "$output" | awk '{print $1}')"
fi
printf '%s  %s\n' "$checksum" "$(basename "$output")" >"${output}.sha256"

echo "Sauvegarde créée : ${output}"
echo "Empreinte créée : ${output}.sha256"
