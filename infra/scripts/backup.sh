#!/usr/bin/env bash
# =============================================================================
# BmsSiteOps — production backup (Sprint 9.3)
# =============================================================================
#
# Captures three artifacts every run:
#   1.  postgres dump   — full database via pg_dump --format=custom
#   2.  caddy_data.tar  — TLS certificates + ACME state + Caddy storage
#   3.  api_storage.tar — Laravel storage/ (uploaded files, logs, framework)
#
# Optional fourth step uploads to S3 if BACKUP_S3_BUCKET is set.
#
# Output: a single timestamped subdirectory under $BACKUP_DIR. The directory
# name is the only piece of state that matters — restore points to it.
#
# Required env (with sane defaults):
#   BACKUP_DIR              default /var/lib/bmssiteops/backups
#   POSTGRES_CONTAINER      default bmssiteops-postgres
#   CADDY_VOLUME            default bmssiteops_caddy_data
#   API_STORAGE_VOLUME      default bmssiteops_api_storage
#   DB_NAME                 default bmssiteops
#   DB_USER                 default bmssiteops
#
# Optional:
#   BACKUP_S3_BUCKET        if set, upload via `aws s3 cp` (aws-cli required)
#   BACKUP_S3_PREFIX        default "bmssiteops/$(hostname)"
#   BACKUP_RETENTION_DAYS   default 30 — local backups older than this go
#                                       (S3 retention is operator's job)
#   VERBOSE=1               more chatty output
#
# Exit codes:
#   0  full success (all artifacts captured, optional S3 upload OK)
#   1  required tool missing
#   2  postgres dump failed
#   3  caddy volume tarball failed
#   4  api_storage volume tarball failed
#   5  S3 upload failed (artifacts on disk are still good)
# =============================================================================

set -euo pipefail

# --- Config -----------------------------------------------------------------
BACKUP_DIR="${BACKUP_DIR:-/var/lib/bmssiteops/backups}"
POSTGRES_CONTAINER="${POSTGRES_CONTAINER:-bmssiteops-postgres}"
CADDY_VOLUME="${CADDY_VOLUME:-bmssiteops_caddy_data}"
API_STORAGE_VOLUME="${API_STORAGE_VOLUME:-bmssiteops_api_storage}"
DB_NAME="${DB_NAME:-bmssiteops}"
DB_USER="${DB_USER:-bmssiteops}"
BACKUP_S3_BUCKET="${BACKUP_S3_BUCKET:-}"
BACKUP_S3_PREFIX="${BACKUP_S3_PREFIX:-bmssiteops/$(hostname)}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
VERBOSE="${VERBOSE:-0}"

# --- Helpers ----------------------------------------------------------------
log()  { [ "$VERBOSE" = "1" ] && printf '  \033[36m·\033[0m %s\n' "$*" >&2; true; }
info() { printf '  \033[36m·\033[0m %s\n' "$*" >&2; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*" >&2; }
warn() { printf '  \033[33m⚠\033[0m %s\n' "$*" >&2; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$*" >&2; }

# --- Sanity -----------------------------------------------------------------
for cmd in docker tar; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        fail "required command not found: $cmd"
        exit 1
    fi
done

if [ -n "$BACKUP_S3_BUCKET" ] && ! command -v aws >/dev/null 2>&1; then
    fail "BACKUP_S3_BUCKET is set but aws CLI not found"
    exit 1
fi

# Use UTC + ISO-ish for sortable directory names.
TS="$(date -u +%Y%m%dT%H%M%SZ)"
DEST="${BACKUP_DIR}/${TS}"

mkdir -p "$DEST"

printf '\n\033[1mBmsSiteOps backup\033[0m\n'
printf '   destination: %s\n\n' "$DEST"

# --- 1. Postgres dump -------------------------------------------------------
info "dumping postgres ($POSTGRES_CONTAINER -> $DB_NAME)..."
if ! docker exec -i "$POSTGRES_CONTAINER" \
    pg_dump --format=custom --no-owner --no-acl \
    --username="$DB_USER" "$DB_NAME" > "${DEST}/postgres.dump" 2>"${DEST}/postgres.err"; then
    fail "pg_dump failed; see ${DEST}/postgres.err"
    exit 2
fi
rm -f "${DEST}/postgres.err"
ok "postgres.dump ($(du -h "${DEST}/postgres.dump" | cut -f1))"

# --- 2. caddy_data tarball --------------------------------------------------
info "snapshotting caddy_data volume ($CADDY_VOLUME)..."
if ! docker run --rm \
    -v "${CADDY_VOLUME}:/source:ro" \
    -v "${DEST}:/dest" \
    alpine:3.20 \
    sh -c 'cd /source && tar -czf /dest/caddy_data.tar.gz .' 2>"${DEST}/caddy.err"; then
    fail "caddy_data tarball failed; see ${DEST}/caddy.err"
    exit 3
fi
rm -f "${DEST}/caddy.err"
ok "caddy_data.tar.gz ($(du -h "${DEST}/caddy_data.tar.gz" | cut -f1))"

# --- 3. api_storage tarball -------------------------------------------------
info "snapshotting api_storage volume ($API_STORAGE_VOLUME)..."
if ! docker run --rm \
    -v "${API_STORAGE_VOLUME}:/source:ro" \
    -v "${DEST}:/dest" \
    alpine:3.20 \
    sh -c 'cd /source && tar -czf /dest/api_storage.tar.gz .' 2>"${DEST}/storage.err"; then
    fail "api_storage tarball failed; see ${DEST}/storage.err"
    exit 4
fi
rm -f "${DEST}/storage.err"
ok "api_storage.tar.gz ($(du -h "${DEST}/api_storage.tar.gz" | cut -f1))"

# --- 4. Manifest -------------------------------------------------------------
# A tiny JSON marker so backup-restore.sh can verify it's pointing at a
# real backup directory (not some random folder) and so operators can grep
# for backups that include specific volume contents.
cat > "${DEST}/manifest.json" <<MANIFEST
{
  "timestamp": "${TS}",
  "hostname": "$(hostname)",
  "postgres": {
    "container": "${POSTGRES_CONTAINER}",
    "database": "${DB_NAME}",
    "file": "postgres.dump",
    "format": "pg_dump custom (--format=custom)"
  },
  "volumes": {
    "caddy_data": "caddy_data.tar.gz",
    "api_storage": "api_storage.tar.gz"
  },
  "schema_version": 1
}
MANIFEST
ok "manifest.json"

TOTAL_SIZE="$(du -sh "$DEST" | cut -f1)"
ok "local backup complete: $DEST ($TOTAL_SIZE)"

# --- 5. Optional S3 upload --------------------------------------------------
if [ -n "$BACKUP_S3_BUCKET" ]; then
    S3_URI="s3://${BACKUP_S3_BUCKET}/${BACKUP_S3_PREFIX}/${TS}/"
    info "uploading to ${S3_URI}..."
    if ! aws s3 cp --quiet --recursive "$DEST" "$S3_URI"; then
        fail "S3 upload failed — local backup at $DEST is still good"
        exit 5
    fi
    ok "uploaded to $S3_URI"
fi

# --- 6. Local retention ------------------------------------------------------
# Remove local backups older than BACKUP_RETENTION_DAYS. S3 retention is
# the operator's job (lifecycle rule on the bucket).
if [ "$BACKUP_RETENTION_DAYS" -gt 0 ]; then
    info "pruning local backups older than ${BACKUP_RETENTION_DAYS} days..."
    REMOVED=0
    while IFS= read -r old; do
        rm -rf "$old"
        REMOVED=$((REMOVED + 1))
    done < <(find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d \
                  -mtime "+${BACKUP_RETENTION_DAYS}" 2>/dev/null)
    if [ "$REMOVED" -gt 0 ]; then
        ok "pruned $REMOVED old backup directories"
    else
        log "nothing to prune"
    fi
fi

printf '\n\033[1;32m✓ backup complete\033[0m  (%s)\n\n' "$TOTAL_SIZE"
exit 0
