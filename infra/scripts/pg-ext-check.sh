#!/usr/bin/env bash
# =============================================================================
# BmsSiteOps - Postgres extension verifier (Sprint 10)
# =============================================================================
#
# Reports the status of every extension BmsSiteOps depends on:
#
#   - timescaledb  (Sprint 3 events hypertable)
#   - vector       (Sprint 8.2 RAG vector(768) + HNSW)
#
# For each extension prints name | version | schema, and on the timescaledb
# row prints which tables have been converted to hypertables.
#
# Use as a one-liner sanity check after every deploy, and as the integration
# gate that the operator-side ext install actually worked.
#
# Required env (with sane defaults):
#   POSTGRES_CONTAINER   default bmssiteops-postgres
#   DB_USER              default bmssiteops
#   DB_NAME              default bmssiteops
#
# Exit codes:
#   0  every required extension is installed and visible
#   1  required tool missing
#   2  postgres container not running
#   3  at least one required extension is MISSING
# =============================================================================

set -euo pipefail

POSTGRES_CONTAINER="${POSTGRES_CONTAINER:-bmssiteops-postgres}"
DB_USER="${DB_USER:-bmssiteops}"
DB_NAME="${DB_NAME:-bmssiteops}"

REQUIRED_EXTENSIONS=(timescaledb vector)

# --- Helpers ----------------------------------------------------------------
info() { printf '  \033[36m·\033[0m %s\n' "$*" >&2; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$*" >&2; }
warn() { printf '  \033[33m⚠\033[0m %s\n' "$*" >&2; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$*" >&2; }

# --- Sanity -----------------------------------------------------------------
if ! command -v docker >/dev/null 2>&1; then
    fail "docker not on PATH"
    exit 1
fi

if ! docker inspect "$POSTGRES_CONTAINER" >/dev/null 2>&1; then
    fail "container '$POSTGRES_CONTAINER' is not running"
    fail "  is the stack up? try: make prod-ps"
    exit 2
fi

# Run a psql query inside the container.
psql_query() {
    docker exec -e PGPASSWORD=ignored "$POSTGRES_CONTAINER" \
        psql -U "$DB_USER" -d "$DB_NAME" -At -F'|' -c "$1"
}

# --- Walk required extensions -----------------------------------------------
printf '\n\033[1mBmsSiteOps Postgres extensions\033[0m\n'
printf '   container: %s  db: %s\n\n' "$POSTGRES_CONTAINER" "$DB_NAME"

MISSING=0

for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    row="$(psql_query "
        SELECT extname, extversion, n.nspname
        FROM pg_extension e
        JOIN pg_namespace n ON n.oid = e.extnamespace
        WHERE extname = '$ext'
    " 2>/dev/null || true)"

    if [ -z "$row" ]; then
        fail "$ext - NOT INSTALLED"
        MISSING=$((MISSING + 1))
        continue
    fi

    # row format: name|version|schema
    name="$(echo "$row" | cut -d'|' -f1)"
    version="$(echo "$row" | cut -d'|' -f2)"
    schema="$(echo "$row" | cut -d'|' -f3)"
    ok "$(printf '%-14s v%-10s schema=%s' "$name" "$version" "$schema")"
done

# --- TimescaleDB extras: hypertable inventory -------------------------------
if [ "$MISSING" -eq 0 ]; then
    HYPER="$(psql_query "
        SELECT hypertable_name,
               (SELECT COUNT(*) FROM timescaledb_information.chunks
                WHERE hypertable_name = ht.hypertable_name)
        FROM timescaledb_information.hypertables ht
    " 2>/dev/null || true)"

    if [ -n "$HYPER" ]; then
        echo ""
        info "hypertables (chunks):"
        while IFS= read -r line; do
            [ -z "$line" ] && continue
            tbl="$(echo "$line" | cut -d'|' -f1)"
            chunks="$(echo "$line" | cut -d'|' -f2)"
            printf '    \033[36m·\033[0m %-30s %s chunks\n' "$tbl" "$chunks"
        done <<< "$HYPER"
    else
        info "no hypertables found yet (Sprint 3 migration not applied?)"
    fi
fi

# --- pgvector extras: column inventory --------------------------------------
if [ "$MISSING" -eq 0 ]; then
    VECCOLS="$(psql_query "
        SELECT table_schema || '.' || table_name || '.' || column_name
        FROM information_schema.columns
        WHERE udt_name = 'vector'
        ORDER BY table_schema, table_name
    " 2>/dev/null || true)"

    if [ -n "$VECCOLS" ]; then
        echo ""
        info "vector columns:"
        while IFS= read -r col; do
            [ -z "$col" ] && continue
            printf '    \033[36m·\033[0m %s\n' "$col"
        done <<< "$VECCOLS"
    else
        info "no vector(N) columns found yet (Sprint 8.2 migration not applied?)"
    fi
fi

# --- Exit -------------------------------------------------------------------
echo ""
if [ "$MISSING" -eq 0 ]; then
    printf '\033[1;32m✓ all required extensions present\033[0m\n\n'
    exit 0
else
    printf '\033[1;31m✗ %d required extension(s) missing\033[0m\n' "$MISSING"
    printf '  enable them with the init script in infra/postgres/init/, or by hand:\n'
    printf '    docker exec -it %s psql -U %s -d %s -c "CREATE EXTENSION IF NOT EXISTS timescaledb;"\n' \
        "$POSTGRES_CONTAINER" "$DB_USER" "$DB_NAME"
    printf '    docker exec -it %s psql -U %s -d %s -c "CREATE EXTENSION IF NOT EXISTS vector;"\n\n' \
        "$POSTGRES_CONTAINER" "$DB_USER" "$DB_NAME"
    exit 3
fi
