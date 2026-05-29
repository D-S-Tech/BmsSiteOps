#!/usr/bin/env bash
# =============================================================================
# BmsSiteOps — restore from a backup (Sprint 9.3)
# =============================================================================
#
# Companion to backup.sh. Restores from a backup directory produced by it:
#
#   ./infra/scripts/backup-restore.sh /var/lib/bmssiteops/backups/20260529T120000Z
#
# Three artifacts get restored:
#   1.  postgres database  via pg_restore --clean --if-exists
#   2.  caddy_data volume  contents replaced
#   3.  api_storage volume contents replaced
#
# DESTRUCTIVE: every restore drops + recreates the target tables/volumes.
# Designed for disaster recovery, NOT for selective re-import.
#
# Requires the stack to be DOWN for volume restores (otherwise running
# containers would lock the volumes). The script verifies and aborts if
# the relevant containers are running.
#
# Exit codes:
#   0  full success
#   1  bad invocation (missing arg / not a backup dir)
#   2  containers running — refused
#   3  postgres restore failed
#   4  caddy_data restore failed
#   5  api_storage restore failed
# =============================================================================

set -euo pipefail

if [ $# -lt 1 ]; then
    cat >&2 <<EOF
usage: $0 <backup-directory>

Example:
    $0 /var/lib/bmssiteops/backups/20260529T120000Z

The backup directory must contain manifest.json, postgres.dump,
caddy_data.tar.gz, and api_storage.tar.gz (the layout backup.sh writes).
EOF
    exit 1
fi

BACKUP_DIR="$1"

# --- Config (must match backup.sh defaults; override via env if your
# stack uses non-default container/volume names) -----------------------------
POSTGRES_CONTAINER="${POSTGRES_CONTAINER:-bmssiteops-postgres}"
CADDY_VOLUME="${CADDY_VOLUME:-bmssiteops_caddy_data}"
API_STORAGE_VOLUME="${API_STORAGE_VOLUME:-bmssiteops_api_storage}"
DB_NAME="${DB_NAME:-bmssiteops}"
DB_USER="${DB_USER:-bmssiteops}"

# --- Helpers ----------------------------------------------------------------
info() { printf '  \033[36m·\033[0m %s\n' "$*" >&2; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*" >&2; }
warn() { printf '  \033[33m⚠\033[0m %s\n' "$*" >&2; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$*" >&2; }

# --- Sanity -----------------------------------------------------------------
if [ ! -d "$BACKUP_DIR" ]; then
    fail "not a directory: $BACKUP_DIR"
    exit 1
fi
for required in manifest.json postgres.dump caddy_data.tar.gz api_storage.tar.gz; do
    if [ ! -f "${BACKUP_DIR}/${required}" ]; then
        fail "missing $required in $BACKUP_DIR"
        exit 1
    fi
done

printf '\n\033[1mBmsSiteOps restore\033[0m\n'
printf '   source: %s\n\n' "$BACKUP_DIR"

# Refuse to restore volumes while containers are running — they would lock
# files and the restore would silently leave stale state.
for c in bmssiteops-caddy bmssiteops-api bmssiteops-web bmssiteops-worker; do
    if docker inspect "$c" >/dev/null 2>&1 \
       && [ "$(docker inspect --format='{{.State.Running}}' "$c")" = "true" ]; then
        fail "container '$c' is running — stop the stack first: make prod-down"
        exit 2
    fi
done

# Postgres can stay up since we use pg_restore against it.
if ! docker inspect "$POSTGRES_CONTAINER" >/dev/null 2>&1; then
    fail "postgres container '$POSTGRES_CONTAINER' is not running"
    fail "  start it first: docker compose -f infra/compose/docker-compose.prod.yml up -d postgres"
    exit 2
fi

# --- Confirmation -----------------------------------------------------------
warn "this will overwrite:"
warn "  - database $DB_NAME in $POSTGRES_CONTAINER"
warn "  - volume $CADDY_VOLUME"
warn "  - volume $API_STORAGE_VOLUME"
echo ""
read -rp "type 'restore' to confirm: " confirm
if [ "$confirm" != "restore" ]; then
    info "aborted"
    exit 0
fi

# --- 1. Postgres ------------------------------------------------------------
info "restoring postgres database $DB_NAME..."
if ! docker exec -i "$POSTGRES_CONTAINER" \
    pg_restore --clean --if-exists --no-owner --no-acl \
    --username="$DB_USER" --dbname="$DB_NAME" \
    < "${BACKUP_DIR}/postgres.dump"; then
    # pg_restore warnings are non-fatal; exit code 1 from --clean on a fresh
    # DB is expected when targets don't exist yet. We only treat exit >= 2
    # as a hard failure via the inner shell.
    rc=$?
    if [ "$rc" -ge 2 ]; then
        fail "pg_restore failed (exit $rc)"
        exit 3
    fi
    warn "pg_restore exit $rc (non-fatal, continuing)"
fi
ok "postgres restored"

# --- 2. caddy_data volume ---------------------------------------------------
info "restoring caddy_data volume..."
if ! docker run --rm \
    -v "${CADDY_VOLUME}:/dest" \
    -v "${BACKUP_DIR}:/src:ro" \
    alpine:3.20 \
    sh -c 'rm -rf /dest/* /dest/.[!.]* 2>/dev/null; cd /dest && tar -xzf /src/caddy_data.tar.gz'; then
    fail "caddy_data restore failed"
    exit 4
fi
ok "caddy_data restored"

# --- 3. api_storage volume --------------------------------------------------
info "restoring api_storage volume..."
if ! docker run --rm \
    -v "${API_STORAGE_VOLUME}:/dest" \
    -v "${BACKUP_DIR}:/src:ro" \
    alpine:3.20 \
    sh -c 'rm -rf /dest/* /dest/.[!.]* 2>/dev/null; cd /dest && tar -xzf /src/api_storage.tar.gz'; then
    fail "api_storage restore failed"
    exit 5
fi
ok "api_storage restored"

printf '\n\033[1;32m✓ restore complete\033[0m\n'
printf '  next: bring the stack back up with: make prod-up\n\n'
exit 0
