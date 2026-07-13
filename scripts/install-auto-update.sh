#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
env_file="${INSIGHT_COMPOSE_ENV_FILE:-${root_dir}/.env}"
action="${1:-install}"
unit_dir="${XDG_CONFIG_HOME:-${HOME}/.config}/systemd/user"
service_file="${unit_dir}/insight-update.service"
timer_file="${unit_dir}/insight-update.timer"

if [ ! -f "$env_file" ]; then
    echo "Environment file ${env_file} was not found." >&2
    exit 1
fi
if ! command -v systemctl >/dev/null 2>&1; then
    echo "systemd is required to install the update timer." >&2
    exit 1
fi

set_env_value() {
    key="$1"
    value="$2"
    temporary_file="$(mktemp)"
    awk -v wanted="$key" -v replacement="$value" '
        BEGIN { found = 0 }
        $0 ~ "^" wanted "=" {
            print wanted "=" replacement
            found = 1
            next
        }
        { print }
        END { if (!found) print wanted "=" replacement }
    ' "$env_file" >"$temporary_file"
    chmod 600 "$temporary_file"
    mv "$temporary_file" "$env_file"
}

if [ "$action" = "--remove" ] || [ "$action" = "remove" ]; then
    systemctl --user disable --now insight-update.timer >/dev/null 2>&1 || true
    rm -f "$service_file" "$timer_file"
    set_env_value INSIGHT_AUTO_UPDATE 0
    systemctl --user daemon-reload
    echo "Automatic Insight updates are disabled."
    exit 0
fi
if [ "$action" != "install" ]; then
    echo "Usage: ./scripts/install-auto-update.sh [--remove]" >&2
    exit 1
fi

escape_systemd() {
    printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g; s/%/%%/g'
}

escaped_root="$(escape_systemd "$root_dir")"
escaped_env="$(escape_systemd "$env_file")"
mkdir -p "$unit_dir"

set_env_value INSIGHT_AUTO_UPDATE 1

cat >"$service_file" <<EOF
[Unit]
Description=Stable Insight update
After=docker.service network-online.target
Wants=network-online.target

[Service]
Type=oneshot
WorkingDirectory="${escaped_root}"
Environment="INSIGHT_COMPOSE_ENV_FILE=${escaped_env}"
ExecStart="${escaped_root}/scripts/update.sh" --auto
TimeoutStartSec=45min
EOF

cat >"$timer_file" <<'EOF'
[Unit]
Description=Daily Insight update check

[Timer]
OnCalendar=*-*-* 04:17:00
RandomizedDelaySec=45min
Persistent=true
Unit=insight-update.service

[Install]
WantedBy=timers.target
EOF

systemctl --user daemon-reload
systemctl --user enable --now insight-update.timer

echo "Stable updates are enabled."
echo "Next run:"
systemctl --user list-timers insight-update.timer --no-pager
