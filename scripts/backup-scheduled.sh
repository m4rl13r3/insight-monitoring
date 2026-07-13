#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
backup_dir="${INSIGHT_BACKUP_DIR:-${root_dir}/backups}"
retention_days="${INSIGHT_BACKUP_RETENTION_DAYS:-30}"
remote_destination="${INSIGHT_BACKUP_RCLONE_DEST:-}"

printf '%s' "$retention_days" | grep -Eq '^[1-9][0-9]*$' || {
    echo "INSIGHT_BACKUP_RETENTION_DAYS must be a positive integer." >&2
    exit 1
}

mkdir -p "$backup_dir"
archive="${backup_dir}/insight-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
"${root_dir}/scripts/backup.sh" "$archive"

if [ -n "$remote_destination" ]; then
    command -v rclone >/dev/null 2>&1 || {
        echo "rclone is required for remote copies." >&2
        exit 1
    }
    rclone copyto "$archive" "${remote_destination%/}/$(basename "$archive")"
    rclone copyto "${archive}.sha256" "${remote_destination%/}/$(basename "${archive}.sha256")"
fi

find "$backup_dir" -type f \( -name 'insight-*.tar.gz' -o -name 'insight-*.tar.gz.sha256' \) -mtime "+${retention_days}" -delete

echo "Scheduled backup complete: ${archive}"
