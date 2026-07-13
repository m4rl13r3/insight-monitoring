#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

if ! git rev-parse --verify HEAD >/dev/null 2>&1; then
    echo "A Git commit is required before building the package." >&2
    exit 1
fi

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Tracked files must be committed before building the package." >&2
    exit 1
fi

./scripts/check-release.sh

version="$(node -p 'require("./package.json").version')"
output="${1:-${root_dir}/dist/insight-${version}.tar.gz}"
mkdir -p "$(dirname "$output")"
output="$(cd "$(dirname "$output")" && pwd)/$(basename "$output")"
umask 077

git archive --format=tar.gz --prefix="insight-${version}/" --output="$output" HEAD

temporary_dir="$(mktemp -d)"
trap 'rm -rf "$temporary_dir"' EXIT
tar -C "$temporary_dir" -xzf "$output"

if find "$temporary_dir" -type f \( -name '.env' -o -name '*.log' -o -name '*.sqlite' -o -name '*.sqlite-shm' -o -name '*.sqlite-wal' -o -name '*.pyc' -o -name '*.png' -o -name '*.jpg' \) -print | grep -q .; then
    echo "The package contains a forbidden runtime file." >&2
    exit 1
fi

legacy_brand="$(printf '%s%s' 'MAR' 'LIERE')"
legacy_domain="$(printf '%s%s' 'marlie' '\.re')"
legacy_tool="$(printf '%s%s' 'atr' '-x')"
legacy_path="$(printf '%s%s' '/opt/MAR' 'LIERE')"
if rg -n -i "${legacy_brand}|${legacy_domain}|${legacy_tool}|${legacy_path}|BEGIN [A-Z ]*PRIVATE KEY" "$temporary_dir"; then
    echo "The package contains a private brand marker or private key." >&2
    exit 1
fi

if command -v shasum >/dev/null 2>&1; then
    checksum="$(shasum -a 256 "$output" | awk '{print $1}')"
else
    checksum="$(sha256sum "$output" | awk '{print $1}')"
fi
printf '%s  %s\n' "$checksum" "$(basename "$output")" >"${output}.sha256"

echo "Public package created: ${output}"
echo "Checksum created: ${output}.sha256"
