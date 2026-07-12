#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker est requis pour installer Insight." >&2
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "Le module Docker Compose est requis." >&2
    exit 1
fi

if ! command -v openssl >/dev/null 2>&1; then
    echo "OpenSSL est requis pour générer les secrets de l’instance." >&2
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
    echo "Configuration créée dans .env. Vérifiez l’URL publique, l’adresse de contact et le mode de monitoring."
fi

chmod 600 .env

docker compose up -d --build
docker compose ps

echo "Insight est démarré."
echo "Avant l’ouverture publique, suivez docs/production.md puis lancez ./scripts/production-check.sh --strict."
