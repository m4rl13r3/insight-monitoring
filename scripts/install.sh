#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is required to install Insight." >&2
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "The Docker Compose plugin is required." >&2
    exit 1
fi

if ! command -v openssl >/dev/null 2>&1; then
    echo "OpenSSL is required to generate instance secrets." >&2
    exit 1
fi

if [ ! -f .env ]; then
    cp .env.example .env
    db_password="$(openssl rand -hex 24)"
    root_password="$(openssl rand -hex 24)"
    agent_master_secret="$(openssl rand -hex 32)"
    notification_encryption_key="$(openssl rand -hex 32)"
    sed -i.bak "s/^INSIGHT_DB_PASSWORD=.*/INSIGHT_DB_PASSWORD=${db_password}/" .env
    sed -i.bak "s/^INSIGHT_DB_ROOT_PASSWORD=.*/INSIGHT_DB_ROOT_PASSWORD=${root_password}/" .env
    sed -i.bak "s/^INSIGHT_AGENT_MASTER_SECRET=.*/INSIGHT_AGENT_MASTER_SECRET=${agent_master_secret}/" .env
    sed -i.bak "s/^INSIGHT_NOTIFICATION_ENCRYPTION_KEY=.*/INSIGHT_NOTIFICATION_ENCRYPTION_KEY=${notification_encryption_key}/" .env
    rm -f .env.bak
    echo "Configuration created in .env. Review the public URL, contact address, and monitoring mode."
fi

chmod 600 .env

docker compose up -d --build --wait --wait-timeout 180
./scripts/migrate.sh

admin_username="${INSIGHT_ADMIN_USERNAME:-admin}"
admin_password="${INSIGHT_ADMIN_PASSWORD:-}"
generated_admin_password=0
if [ -z "$admin_password" ]; then
    admin_password="$(openssl rand -hex 24)"
    generated_admin_password=1
fi

set +e
docker compose exec -T \
    -e "INSIGHT_BOOTSTRAP_ADMIN_USERNAME=$admin_username" \
    -e "INSIGHT_BOOTSTRAP_ADMIN_PASSWORD=$admin_password" \
    php php scripts/create-admin.php
admin_status=$?
set -e

if [ "$admin_status" -eq 0 ]; then
    echo "Administrator account: $admin_username"
    echo "Administrator password: $admin_password"
    if [ "$generated_admin_password" -eq 1 ]; then
        echo "Store this generated password now. It will not be printed again."
    fi
elif [ "$admin_status" -ne 10 ]; then
    exit "$admin_status"
fi

docker compose ps

echo "Insight is running."
echo "Before making it public, follow docs/production.md and run ./scripts/production-check.sh --strict."
