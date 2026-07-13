#!/usr/bin/env bash

set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root_dir"

mode="check"
target=""

usage() {
    echo "Usage: ./scripts/update.sh [--check|--apply|--auto|--rollback] [--target vX.Y.Z]"
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --check|--apply|--auto|--rollback)
            mode="${1#--}"
            ;;
        --target)
            shift
            target="${1:-}"
            if [ -z "$target" ]; then
                usage >&2
                exit 1
            fi
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        *)
            usage >&2
            exit 1
            ;;
    esac
    shift
done

env_file="${INSIGHT_COMPOSE_ENV_FILE:-.env}"
project_name="${INSIGHT_COMPOSE_PROJECT_NAME:-}"

if [ ! -f "$env_file" ]; then
    echo "Environment file ${env_file} was not found." >&2
    exit 1
fi

read_env() {
    key="$1"
    fallback="$2"
    value="$(awk -v wanted="$key" '
        $0 ~ "^[[:space:]]*" wanted "=" {
            line = $0
            sub("^[[:space:]]*" wanted "=", "", line)
            sub("\\r$", "", line)
            print line
            exit
        }
    ' "$env_file")"
    if [ -z "$value" ]; then
        printf '%s' "$fallback"
        return
    fi
    case "$value" in
        \"*\") value="${value#\"}"; value="${value%\"}" ;;
        \'*\') value="${value#\'}"; value="${value%\'}" ;;
    esac
    printf '%s' "$value"
}

remote="$(read_env INSIGHT_UPDATE_REMOTE origin)"
auto_update="$(read_env INSIGHT_AUTO_UPDATE 0)"
backup_enabled="$(read_env INSIGHT_UPDATE_BACKUP 1)"
require_signed_tags="$(read_env INSIGHT_UPDATE_REQUIRE_SIGNED_TAGS 0)"
health_timeout="$(read_env INSIGHT_UPDATE_HEALTH_TIMEOUT_SEC 180)"

printf '%s' "$remote" | grep -Eq '^[A-Za-z0-9._/-]+$' || {
    echo "INSIGHT_UPDATE_REMOTE contains an invalid value." >&2
    exit 1
}
printf '%s' "$health_timeout" | grep -Eq '^[0-9]+$' || {
    echo "INSIGHT_UPDATE_HEALTH_TIMEOUT_SEC must be an integer." >&2
    exit 1
}

if [ "$mode" = "auto" ] && [ "$auto_update" != "1" ]; then
    echo "Automatic updates are disabled in ${env_file}."
    exit 0
fi

for command_name in git docker awk grep; do
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "${command_name} is required to update Insight." >&2
        exit 1
    fi
done
if ! docker compose version >/dev/null 2>&1; then
    echo "The Docker Compose plugin is required to update Insight." >&2
    exit 1
fi

compose=(docker compose --env-file "$env_file")
if [ -n "$project_name" ]; then
    compose+=(-p "$project_name")
fi

mkdir -p data
lock_dir="data/update.lock"
if ! mkdir "$lock_dir" 2>/dev/null; then
    echo "An Insight update is already running." >&2
    exit 1
fi
trap 'rmdir "$lock_dir" 2>/dev/null || true' EXIT

check_health() {
    running_services="$("${compose[@]}" ps --services --status running)" || return 1
    for service in db php worker web; do
        printf '%s\n' "$running_services" | grep -qx "$service" || return 1
    done
    "${compose[@]}" exec -T db sh -lc 'mariadb --batch --skip-column-names -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT 1"' | grep -qx 1 || return 1
    "${compose[@]}" exec -T web wget -q -O /dev/null http://127.0.0.1/ || return 1
    "${compose[@]}" exec -T web wget -q -O /dev/null http://127.0.0.1/api/public_runtime_state.php || return 1
}

deploy_commit() {
    commit="$1"
    git checkout --detach "$commit" || return 1
    "${compose[@]}" config --quiet || return 1
    "${compose[@]}" build --pull php worker web || return 1
    "${compose[@]}" stop worker || return 1
    INSIGHT_COMPOSE_ENV_FILE="$env_file" INSIGHT_COMPOSE_PROJECT_NAME="$project_name" ./scripts/migrate.sh || return 1
    "${compose[@]}" up -d --wait --wait-timeout "$health_timeout" || return 1
    check_health || return 1
}

state_file="data/update-state.env"

if [ "$mode" = "rollback" ]; then
    if [ ! -f "$state_file" ]; then
        echo "No previous update state is available." >&2
        exit 1
    fi
    previous_commit="$(awk -F= '$1 == "previous_commit" {print $2; exit}' "$state_file")"
    printf '%s' "$previous_commit" | grep -Eq '^[0-9a-f]{40}$' || {
        echo "The rollback state is invalid." >&2
        exit 1
    }
    git cat-file -e "${previous_commit}^{commit}" 2>/dev/null || {
        echo "The previous commit is no longer available locally." >&2
        exit 1
    }
    current_commit="$(git rev-parse HEAD)"
    if [ "$current_commit" = "$previous_commit" ]; then
        echo "Insight is already using the previous commit ${previous_commit}."
        exit 0
    fi
    echo "Returning to ${previous_commit}..."
    if deploy_commit "$previous_commit"; then
        echo "Rollback complete. The database was not restored automatically."
        exit 0
    fi
    git checkout --detach "$current_commit" >/dev/null 2>&1 || true
    echo "Rollback failed. The latest update backup remains available in backups/." >&2
    exit 1
fi

if [ -n "$(git status --porcelain --untracked-files=normal)" ]; then
    echo "The repository contains changes or non-ignored local files. Clean them before updating." >&2
    exit 1
fi
if ! git remote get-url "$remote" >/dev/null 2>&1; then
    echo "Git remote ${remote} does not exist." >&2
    exit 1
fi

remote_tags="$(git ls-remote --tags --refs "$remote")"
if [ -z "$target" ]; then
    target="$(printf '%s\n' "$remote_tags" | awk '
        {
            tag = $2
            sub("refs/tags/", "", tag)
            if (tag !~ /^v[0-9]+\.[0-9]+\.[0-9]+$/) next
            version = substr(tag, 2)
            split(version, parts, ".")
            if (!found || parts[1] + 0 > major || (parts[1] + 0 == major && parts[2] + 0 > minor) || (parts[1] + 0 == major && parts[2] + 0 == minor && parts[3] + 0 > patch)) {
                found = 1
                major = parts[1] + 0
                minor = parts[2] + 0
                patch = parts[3] + 0
                latest = tag
            }
        }
        END { if (found) print latest }
    ')"
fi

printf '%s' "$target" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$' || {
    echo "No stable vX.Y.Z release was found on ${remote}." >&2
    exit 1
}
printf '%s\n' "$remote_tags" | awk '{print $2}' | grep -qx "refs/tags/${target}" || {
    echo "Release ${target} does not exist on ${remote}." >&2
    exit 1
}

git fetch --prune "$remote"
git fetch "$remote" "refs/tags/${target}:refs/tags/${target}"

if [ "$(git cat-file -t "refs/tags/${target}")" != "tag" ]; then
    echo "Release ${target} must use an annotated Git tag." >&2
    exit 1
fi
if [ "$require_signed_tags" = "1" ] && ! git verify-tag "$target"; then
    echo "The Git signature for ${target} is invalid." >&2
    exit 1
fi

target_commit="$(git rev-list -n 1 "$target")"
target_version="$(git show "${target}:package.json" | sed -n 's/^[[:space:]]*"version": "\([^"]*\)",/\1/p' | head -1)"
if [ "v${target_version}" != "$target" ]; then
    echo "The package.json version does not match tag ${target}." >&2
    exit 1
fi

current_commit="$(git rev-parse HEAD)"
current_version="$(sed -n 's/^[[:space:]]*"version": "\([^"]*\)",/\1/p' package.json | head -1)"
if [ "$current_commit" = "$target_commit" ]; then
    echo "Insight ${target_version} is already up to date."
    exit 0
fi
if ! git merge-base --is-ancestor "$current_commit" "$target_commit"; then
    if git merge-base --is-ancestor "$target_commit" "$current_commit"; then
        echo "Local version ${current_version} is newer than the latest stable release ${target_version}."
        exit 0
    fi
    echo "The local version and ${target} have diverged. Automatic update was refused." >&2
    exit 1
fi

echo "Update available: ${current_version} to ${target_version}."
if [ "$mode" = "check" ]; then
    exit 0
fi

backup_path=""
if [ "$backup_enabled" = "1" ]; then
    backup_path="${root_dir}/backups/avant-mise-a-jour-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
    INSIGHT_COMPOSE_ENV_FILE="$env_file" INSIGHT_COMPOSE_PROJECT_NAME="$project_name" ./scripts/backup.sh "$backup_path"
fi

umask 077
{
    printf 'previous_commit=%s\n' "$current_commit"
    printf 'target_tag=%s\n' "$target"
    printf 'target_commit=%s\n' "$target_commit"
    printf 'backup_path=%s\n' "$backup_path"
    printf 'started_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
} >"$state_file"

echo "Deploying ${target}..."
if deploy_commit "$target_commit"; then
    printf 'completed_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$state_file"
    echo "Insight ${target_version} is up to date and healthy."
    exit 0
fi

echo "Deployment of ${target} failed. Automatically returning to the previous code..." >&2
if deploy_commit "$current_commit"; then
    printf 'rolled_back_at=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" >>"$state_file"
    echo "The previous code was restored and validated. The backup was not restored automatically." >&2
else
    echo "Automatic rollback also failed. Use the backup ${backup_path:-available in backups/}." >&2
fi
exit 1
