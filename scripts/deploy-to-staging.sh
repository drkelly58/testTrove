#!/usr/bin/env bash
# Build TestTrove and rsync a release to the staging app root.
# Configure scripts/deploy.env (see deploy.env.example).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy-common.sh
source "$SCRIPT_DIR/deploy-common.sh"

usage() {
    cat <<'EOF'
Usage: scripts/deploy-to-staging.sh [--dry-run]

Builds the SPA, runs composer install --no-dev (unless skipped), and rsyncs
to DEPLOY_STAGING_TARGET. Does not upload .env or storage/.

EOF
}

for arg in "$@"; do
    case "$arg" in
        -h | --help) usage; exit 0 ;;
        --dry-run) export DEPLOY_DRY_RUN=1 ;;
        *) echo "Unknown option: $arg" >&2; usage >&2; exit 1 ;;
    esac
done

deploy_load_config

if [[ -z "${DEPLOY_STAGING_TARGET:-}" ]]; then
    echo "Set DEPLOY_STAGING_TARGET in scripts/deploy.env" >&2
    exit 1
fi

ROOT="$(deploy_repo_root)"
cd "$ROOT"

if [[ "${DEPLOY_SKIP_BUILD:-}" != "1" ]]; then
    echo "→ Building frontend (npm run build)"
    (cd frontend && npm run build)
fi

if [[ "${DEPLOY_SKIP_COMPOSER:-}" != "1" ]]; then
    echo "→ Installing PHP dependencies (composer install --no-dev)"
    composer install --no-dev --optimize-autoloader
fi

if [[ ! -f public/app/index.html ]]; then
    echo "Missing public/app/index.html after build." >&2
    exit 1
fi

TARGET="${DEPLOY_STAGING_TARGET}"
[[ "$TARGET" == */ ]] || TARGET="${TARGET}/"

deploy_sync_release "$ROOT" "$TARGET"
