#!/usr/bin/env bash
# Copy a tested staging release into production on the same server.
# Does not touch .env or storage/ on production.
# Run on the staging host with scripts/deploy.env configured.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=deploy-common.sh
source "$SCRIPT_DIR/deploy-common.sh"

usage() {
    cat <<'EOF'
Usage: scripts/promote-to-production.sh [--dry-run]

Rsyncs from DEPLOY_STAGING_ROOT to DEPLOY_PRODUCTION_ROOT (local paths).
Environment-specific files (.env, storage/) on production are not modified.

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

STAGING="${DEPLOY_STAGING_ROOT:-}"
PRODUCTION="${DEPLOY_PRODUCTION_ROOT:-}"

if [[ -z "$STAGING" || -z "$PRODUCTION" ]]; then
    echo "Set DEPLOY_STAGING_ROOT and DEPLOY_PRODUCTION_ROOT in scripts/deploy.env" >&2
    exit 1
fi

if [[ ! -d "$STAGING/src" ]]; then
    echo "Staging app root not found or invalid: $STAGING" >&2
    exit 1
fi

if [[ ! -d "$PRODUCTION" ]]; then
    echo "Production path does not exist: $PRODUCTION" >&2
    exit 1
fi

WEB="${DEPLOY_WEB_DIR:-public}"
if [[ ! -d "$STAGING/$WEB" ]]; then
    echo "Staging web directory missing: $STAGING/$WEB" >&2
    exit 1
fi

if [[ "$STAGING" == "$PRODUCTION" ]]; then
    echo "DEPLOY_STAGING_ROOT and DEPLOY_PRODUCTION_ROOT must differ." >&2
    exit 1
fi

echo "Promoting: $STAGING → $PRODUCTION"
deploy_sync_release "$STAGING" "$PRODUCTION/"
