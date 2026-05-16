# Shared helpers for TestTrove deployment. Source from other scripts; do not execute directly.

deploy_load_config() {
    local script_dir
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    DEPLOY_CONFIG_FILE="${DEPLOY_CONFIG_FILE:-$script_dir/deploy.env}"
    if [[ ! -f "$DEPLOY_CONFIG_FILE" ]]; then
        echo "Missing $DEPLOY_CONFIG_FILE — copy scripts/deploy.env.example to scripts/deploy.env and edit." >&2
        return 1
    fi
    # shellcheck disable=SC1090
    set -a
    source "$DEPLOY_CONFIG_FILE"
    set +a
}

deploy_repo_root() {
    local script_dir
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    cd "$script_dir/.." && pwd
}

deploy_rsync_opts() {
    DEPLOY_RSYNC_OPTS=(-a -z --human-readable)
    if [[ "${DEPLOY_DRY_RUN:-}" == "1" ]]; then
        DEPLOY_RSYNC_OPTS+=(--dry-run -n -v)
    fi
    if [[ -n "${DEPLOY_RSYNC_EXTRA_OPTS:-}" ]]; then
        # shellcheck disable=SC2206
        DEPLOY_RSYNC_OPTS+=($DEPLOY_RSYNC_EXTRA_OPTS)
    fi
}

# Paths under the app root that must never be overwritten on the target.
deploy_rsync_excludes() {
    DEPLOY_RSYNC_EXCLUDES=(
        --exclude='.env'
        --exclude='deploy/'
        --exclude='dist/'
        --exclude='storage/'
        --exclude='.git/'
        --exclude='frontend/'
        --exclude='node_modules/'
        --exclude='.DS_Store'
        --exclude='scripts/deploy.env'
    )
    if [[ -n "${DEPLOY_EXTRA_EXCLUDES:-}" ]]; then
        local item
        for item in $DEPLOY_EXTRA_EXCLUDES; do
            DEPLOY_RSYNC_EXCLUDES+=(--exclude="$item")
        done
    fi
}

# Sync application code from SRC_ROOT to DEST (user@host:path or /local/path).
# Local repo uses public/; remote may use DEPLOY_WEB_DIR (e.g. public_html).
deploy_sync_release() {
    local src_root="$1"
    local dest_root="$2"
    local web_dir="${DEPLOY_WEB_DIR:-public}"
    local src_web="$src_root/public"
    local dest_web="$dest_root/$web_dir"

    if [[ ! -d "$src_root/src" ]]; then
        echo "Not an application root (missing src/): $src_root" >&2
        return 1
    fi
    if [[ ! -d "$src_web" ]]; then
        echo "Missing built web root at $src_web — run npm run build in frontend/ first." >&2
        return 1
    fi

    deploy_rsync_opts
    deploy_rsync_excludes

    local -a del=(--delete)
    if [[ "${DEPLOY_DRY_RUN:-}" == "1" ]]; then
        del=(--delete)
    fi

    echo "→ Syncing application code to ${dest_root}"
    rsync "${DEPLOY_RSYNC_OPTS[@]}" "${del[@]}" "${DEPLOY_RSYNC_EXCLUDES[@]}" \
        --exclude='public/' \
        "$src_root/" "$dest_root/"

    echo "→ Syncing web root to ${dest_web}"
    rsync "${DEPLOY_RSYNC_OPTS[@]}" "${del[@]}" \
        "$src_web/" "$dest_web/"

    echo "Done. Left unchanged on target: .env, storage/"
}
