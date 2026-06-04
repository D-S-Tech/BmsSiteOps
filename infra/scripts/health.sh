#!/usr/bin/env bash
# =============================================================================
# BmsSiteOps - composite health check (Sprint 11.2)
# =============================================================================
#
# One command that summarizes every operational health signal on a running
# BmsSiteOps deploy:
#
#   1. TLS cert status      via cert-status.sh
#   2. Q&A smoke test       via smoke-test.sh
#   3. MCP SSE handshake    via mcp-smoke.py
#   4. Postgres extensions  via pg-ext-check.sh
#   5. Last backup age      direct (mtime of newest dir under $BACKUP_DIR)
#
# Designed as the post-deploy acceptance test AND the daily cron summary.
# Each sub-check runs independently; the script captures their exit codes
# and reports per-check + aggregate.
#
# Environment:
#   API_BASE_URL, API_BEARER_TOKEN   required for smoke-test
#   MCP_BASE_URL                      required for mcp-smoke
#   BACKUP_DIR                        default /var/lib/bmssiteops/backups
#   BACKUP_MAX_AGE_HOURS              default 36 (warning over this)
#   QUIET=1                           cron mode: silent on full success
#   SKIP_SMOKE=1                      skip the Q&A smoke (avoid token cost)
#
# Output modes:
#   Default                           one-line-per-check, colored, summary
#   QUIET=1                           nothing on full pass, full report on
#                                    any warning/critical
#
# Exit codes:
#   0  all checks passed
#   1  at least one check returned 'warning'
#   2  at least one check returned 'critical' (=== alert)
#
# Cron recipe:
#   0 8 * * * QUIET=1 SKIP_SMOKE=1 \\
#               /opt/bmssiteops/infra/scripts/health.sh \\
#               || mail -s '[bmssiteops] health' ops@example.com
# =============================================================================

set -u  # NOT -e — we want to capture each sub-check's exit code

QUIET="${QUIET:-0}"
SKIP_SMOKE="${SKIP_SMOKE:-0}"
BACKUP_DIR="${BACKUP_DIR:-/var/lib/bmssiteops/backups}"
BACKUP_MAX_AGE_HOURS="${BACKUP_MAX_AGE_HOURS:-36}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Each check populates RESULTS[name]=exitcode and DETAIL[name]=one-line summary.
declare -A RESULTS DETAIL

# WORST holds the maximum severity seen so far (0=ok, 1=warn, 2=crit).
WORST=0
BUFFER=""

# --- output helpers ----------------------------------------------------------
# Use printf -v to preserve trailing newlines ($(...) command substitution
# strips them, which would collapse the report into a single line).
hdr()  {
    local tmp
    printf -v tmp '\n\033[1m%s\033[0m\n\n' "$*"
    BUFFER+="$tmp"
}
row()  {
    local status=$1 name=$2 msg=$3
    local icon tmp
    case "$status" in
        0) icon='\033[32m✓\033[0m' ;;
        1) icon='\033[33m⚠\033[0m' ;;
        *) icon='\033[31m✗\033[0m' ;;
    esac
    printf -v tmp '  %b  %-22s %s\n' "$icon" "$name" "$msg"
    BUFFER+="$tmp"
}
finish() {
    [ "$WORST" = 0 ] && [ "$QUIET" = "1" ] && return
    printf '%s' "$BUFFER"
    case "$WORST" in
        0) printf '\n\033[1;32m✓ all checks passed\033[0m\n\n' ;;
        1) printf '\n\033[1;33m⚠ at least one warning\033[0m\n\n' ;;
        *) printf '\n\033[1;31m✗ at least one critical\033[0m\n\n' ;;
    esac
}

run_check() {
    local name=$1; shift
    local cmd=("$@")
    if output="$("${cmd[@]}" 2>&1)"; then
        RESULTS[$name]=0
        DETAIL[$name]="passed"
    else
        RESULTS[$name]=$?
        DETAIL[$name]="exit ${RESULTS[$name]}"
    fi
    case ${RESULTS[$name]} in
        0) row 0 "$name" "${DETAIL[$name]}" ;;
        1) row 1 "$name" "${DETAIL[$name]} (warning)"; [ "$WORST" -lt 1 ] && WORST=1 ;;
        *) row 2 "$name" "${DETAIL[$name]} (critical)"; WORST=2 ;;
    esac
}

hdr "BmsSiteOps health"

# --- 1. TLS cert status -----------------------------------------------------
run_check "cert-status" env QUIET=1 "${SCRIPT_DIR}/cert-status.sh"

# --- 2. Postgres extensions -------------------------------------------------
run_check "pg-ext-check" env QUIET=1 "${SCRIPT_DIR}/pg-ext-check.sh"

# --- 3. MCP SSE handshake ---------------------------------------------------
if [ -n "${MCP_BASE_URL:-}" ]; then
    if command -v python3 >/dev/null 2>&1 && python3 -c "import mcp" 2>/dev/null; then
        run_check "mcp-smoke" python3 "${SCRIPT_DIR}/mcp-smoke.py"
    else
        row 1 "mcp-smoke" "mcp SDK not on PATH — skipped (run from worker venv)"
        [ "$WORST" -lt 1 ] && WORST=1
    fi
else
    row 1 "mcp-smoke" "MCP_BASE_URL unset — skipped"
    [ "$WORST" -lt 1 ] && WORST=1
fi

# --- 4. Q&A smoke (optional — costs LLM tokens) -----------------------------
if [ "$SKIP_SMOKE" = "1" ]; then
    row 0 "smoke-test" "skipped (SKIP_SMOKE=1)"
elif [ -z "${API_BASE_URL:-}" ] || [ -z "${API_BEARER_TOKEN:-}" ]; then
    row 1 "smoke-test" "API_BASE_URL or API_BEARER_TOKEN unset — skipped"
    [ "$WORST" -lt 1 ] && WORST=1
else
    run_check "smoke-test" "${SCRIPT_DIR}/smoke-test.sh"
fi

# --- 5. Backup freshness ----------------------------------------------------
if [ ! -d "$BACKUP_DIR" ]; then
    row 2 "backup-age" "BACKUP_DIR ($BACKUP_DIR) does not exist"
    WORST=2
else
    # Find newest direct subdir of BACKUP_DIR.
    LATEST="$(find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d \
                  -printf '%T@ %p\n' 2>/dev/null \
              | sort -nr | head -1 | cut -d' ' -f2-)"
    if [ -z "$LATEST" ]; then
        row 2 "backup-age" "no backup directories found in $BACKUP_DIR"
        WORST=2
    else
        AGE_SEC=$(( $(date +%s) - $(stat -c %Y "$LATEST") ))
        AGE_HRS=$(( AGE_SEC / 3600 ))
        if [ "$AGE_HRS" -le "$BACKUP_MAX_AGE_HOURS" ]; then
            row 0 "backup-age" "${AGE_HRS}h ago ($(basename "$LATEST"))"
        elif [ "$AGE_HRS" -le $(( BACKUP_MAX_AGE_HOURS * 2 )) ]; then
            row 1 "backup-age" "${AGE_HRS}h ago — exceeds threshold ${BACKUP_MAX_AGE_HOURS}h (warning)"
            [ "$WORST" -lt 1 ] && WORST=1
        else
            row 2 "backup-age" "${AGE_HRS}h ago — well over threshold (critical)"
            WORST=2
        fi
    fi
fi

finish
exit "$WORST"
