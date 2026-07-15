#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
    echo "Docker and the Docker Compose plugin are required." >&2
    exit 1
fi

host="${INSIGHT_DEV_HOST:-127.0.0.1}"
port="${INSIGHT_DEV_PORT:-8080}"

case "$host" in
    127.0.0.1|::1|localhost)
        ;;
    *)
        echo "Development access is restricted to a local host." >&2
        exit 1
        ;;
esac

export COMPOSE_PROJECT_NAME="${INSIGHT_DEV_COMPOSE_PROJECT:-insight-development}"
export INSIGHT_APP_ENV=development
export INSIGHT_DEV_AUTH_BYPASS=1
export INSIGHT_HTTP_BIND="$host"
export INSIGHT_HTTP_PORT="$port"
export INSIGHT_PUBLIC_URL="${INSIGHT_DEV_PUBLIC_URL:-http://$host:$port}"
export INSIGHT_ALLOWED_ORIGINS="${INSIGHT_DEV_ALLOWED_ORIGINS:-$INSIGHT_PUBLIC_URL}"
export INSIGHT_API_ALLOWED_ORIGINS="${INSIGHT_DEV_API_ALLOWED_ORIGINS:-$INSIGHT_PUBLIC_URL}"
export INSIGHT_CONTACT_EMAIL="${INSIGHT_DEV_CONTACT_EMAIL:-developer@example.com}"
export INSIGHT_DB_NAME="${INSIGHT_DEV_DB_NAME:-insight}"
export INSIGHT_DB_USER="${INSIGHT_DEV_DB_USER:-insight}"
export INSIGHT_DB_PASSWORD="${INSIGHT_DEV_DB_PASSWORD:-insight_development_database}"
export INSIGHT_DB_ROOT_PASSWORD="${INSIGHT_DEV_DB_ROOT_PASSWORD:-insight_development_root}"
export INSIGHT_NOTIFICATION_ENCRYPTION_KEY="${INSIGHT_DEV_NOTIFICATION_ENCRYPTION_KEY:-0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef}"
export INSIGHT_AGENT_MASTER_SECRET="${INSIGHT_DEV_AGENT_MASTER_SECRET:-abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789}"
export INSIGHT_DISABLE_NOTIFICATIONS=1
export INSIGHT_AGENT_REQUIRE_HTTPS=0

docker compose up -d --build --wait --wait-timeout 180 "$@"
echo "Insight development is running at $INSIGHT_PUBLIC_URL"
