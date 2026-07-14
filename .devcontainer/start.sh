#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

build=()
if [ "${1:-}" = "--build" ]; then
    build=(--build)
fi

docker compose --env-file .devcontainer/demo.env up -d "${build[@]}" --wait --wait-timeout 180
