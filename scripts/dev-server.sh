#!/usr/bin/env bash

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
host="${INSIGHT_DEV_HOST:-127.0.0.1}"
port="${INSIGHT_DEV_PORT:-8787}"

cd "$root"
export INSIGHT_APP_ENV=development
export INSIGHT_DEV_AUTH_BYPASS=1
export INSIGHT_PUBLIC_URL="${INSIGHT_PUBLIC_URL:-http://$host:$port}"
export INSIGHT_API_ALLOWED_ORIGINS="${INSIGHT_API_ALLOWED_ORIGINS:-$INSIGHT_PUBLIC_URL}"
if [ -d "$root/monitoring/.pydeps" ]; then
    export PYTHONPATH="${PYTHONPATH:-$root/monitoring/.pydeps}"
fi

exec php -S "$host:$port" -t public scripts/dev-router.php
